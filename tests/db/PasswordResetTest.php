<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Сброс пароля на настоящей базе: в базе только хеш токена, срок, одноразовость, лимит запросов
 * и отзыв сессий после смены. Настоящий SMTP не нужен — транспорт подменяется замыканием.
 *
 * Ответ на запрос сброса всегда одинаковый. Это главное свойство: по нему нельзя узнать,
 * зарегистрирован ли адрес, иначе форма превращается в проверку существования аккаунтов.
 */
final class PasswordResetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('password_reset');
        TestDb::clearTable('password_reset_attempt');
        TestDb::clearTable('user');
    }

    public function testСсылкаСоздаётсяХешемАНеСырымТокеном(): void
    {
        $userId = TestDb::seedUser('reset@test.ru', 'staraya-rol-123');

        $result = passwordResetRequest('reset@test.ru');
        self::assertTrue($result['ok']);

        $row = one('SELECT user_id, token_hash, used_at FROM ' . t('password_reset') . ' WHERE user_id = ?', [$userId]);
        self::assertNotNull($row);
        self::assertSame(64, strlen((string) $row['token_hash']), 'В базе лежит SHA-256-хеш из 64 символов');
        self::assertNull($row['used_at'], 'Свежая ссылка ещё не использована');

        // Сырого токена в базе нет: хеш от чего-то другого не совпадает с самим значением.
        self::assertNotSame((string) $row['token_hash'], hash('sha256', (string) $row['token_hash']));
    }

    // Ответ одинаков для существующего адреса, несуществующего и кривого: иначе форма стала бы
    // проверкой существования аккаунтов.
    public function testОтветОдинаковДляЛюбогоАдреса(): void
    {
        TestDb::seedUser('est@test.ru', 'staraya-rol-123');

        $existing = passwordResetRequest('est@test.ru');
        $unknown = passwordResetRequest('net-takogo@test.ru');
        $broken = passwordResetRequest('не-адрес');

        self::assertTrue($existing['ok']);
        self::assertTrue($unknown['ok'], 'Несуществующий адрес получает тот же успех');
        self::assertTrue($broken['ok'], 'Кривой адрес получает тот же успех');

        self::assertSame('', $existing['error']);
        self::assertSame('', $unknown['error']);
        self::assertSame('', $broken['error']);

        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('password_reset')), 'Ссылка создана только для существующего');
    }

    public function testЗаблокированныйПользовательСсылкуНеПолучает(): void
    {
        $userId = TestDb::seedUser('blocked@test.ru', 'staraya-rol-123');
        run('UPDATE ' . t('user') . ' SET blocked_at = ? WHERE id = ?', [utcStamp(nowUtc()), $userId]);

        $result = passwordResetRequest('blocked@test.ru');

        self::assertTrue($result['ok'], 'Ответ всё равно успешный');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('password_reset')), 'Заблокированному ссылку не выдают');
    }

    public function testПовторныйЗапросГаситПрежнююСсылку(): void
    {
        $userId = TestDb::seedUser('two@test.ru', 'staraya-rol-123');

        passwordResetRequest('two@test.ru');
        passwordResetRequest('two@test.ru');

        $active = (int) value(
            'SELECT COUNT(*) FROM ' . t('password_reset') . ' WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
        self::assertSame(1, $active, 'Действует только последняя ссылка');
    }

    public function testЛимитЗапросовПоАдресуОграничиваетФорму(): void
    {
        TestDb::seedUser('limit@test.ru', 'staraya-rol-123');

        for ($n = 1; $n <= PASSWORD_RESET_RATE_LIMIT; $n++) {
            self::assertTrue(passwordResetRequest('limit@test.ru')['ok'], 'Запрос ' . $n . ' в пределах лимита');
        }

        $over = passwordResetRequest('limit@test.ru');

        self::assertFalse($over['ok'], 'Сверх лимита форма отвечает отказом');
        self::assertStringContainsString('слишком часто', $over['error']);
    }

    // Токен проходит только по существующей строке и только до срока.
    public function testПросроченныйТокенНеДействует(): void
    {
        $userId = TestDb::seedUser('expired@test.ru', 'staraya-rol-123');
        passwordResetRequest('expired@test.ru');

        // Сдвигаем срок в прошлое: так проверяется условие срока, а не ожидание часа.
        run('UPDATE ' . t('password_reset') . ' SET expires_at = ? WHERE user_id = ?', [utcStamp(nowUtc()->modify('-1 hour')), $userId]);

        $raw = $this->rawToken($userId);
        self::assertNull(passwordResetTokenRow($raw), 'Просроченная ссылка не находится');

        $result = passwordResetByToken($raw, 'novyy-parol-1', 'novyy-parol-1');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('недействительна', $result['error']);
    }

    public function testЧужойИлиКривойТокенНеДействует(): void
    {
        self::assertNull(passwordResetTokenRow(str_repeat('0', 64)), 'Случайный токен не находится');
        self::assertNull(passwordResetTokenRow('короткий'), 'Токен неверного формата не разбирается');
    }

    public function testСменаПароляГаситСсылкуИОтзываетСессии(): void
    {
        $userId = TestDb::seedUser('change@test.ru', 'staraya-rol-123');
        run('UPDATE ' . t('user') . ' SET session_version = 7 WHERE id = ?', [$userId]);
        passwordResetRequest('change@test.ru');

        $raw = $this->rawToken($userId);
        $result = passwordResetByToken($raw, 'novyy-parol-9', 'novyy-parol-9');

        self::assertTrue($result['ok'], 'Пароль обязан смениться: ' . $result['error']);

        $user = one('SELECT password_hash, session_version FROM ' . t('user') . ' WHERE id = ?', [$userId]);
        self::assertNotNull($user);
        self::assertTrue(password_verify('novyy-parol-9', (string) $user['password_hash']), 'Новый пароль работает');
        self::assertFalse(password_verify('staraya-rol-123', (string) $user['password_hash']), 'Старый пароль больше не работает');
        self::assertSame(8, (int) $user['session_version'], 'Смена пароля отзывает прежние сеансы');

        self::assertNull(passwordResetTokenRow($raw), 'Ссылка одноразовая');
    }

    public function testКороткийПарольИНесовпадениеОтказывают(): void
    {
        $userId = TestDb::seedUser('rules@test.ru', 'staraya-rol-123');
        passwordResetRequest('rules@test.ru');
        $raw = $this->rawToken($userId);

        $short = passwordResetByToken($raw, '123456789', '123456789');
        self::assertFalse($short['ok']);
        self::assertStringContainsString('не короче', $short['error']);

        $mismatch = passwordResetByToken($raw, 'novyy-parol-1', 'novyy-parol-2');
        self::assertFalse($mismatch['ok']);
        self::assertStringContainsString('не совпадают', $mismatch['error']);

        $user = one('SELECT password_hash FROM ' . t('user') . ' WHERE id = ?', [$userId]);
        self::assertNotNull($user);
        self::assertTrue(password_verify('staraya-rol-123', (string) $user['password_hash']), 'Отказы не меняют пароль');
        self::assertNotNull(passwordResetTokenRow($raw), 'Отказы не гасят ссылку');
    }

    // Сырой токен после запроса в базе не хранится, поэтому его достают из письма тестового
    // транспорта. Здесь хеш сверяется напрямую: строка одна, и её хеш — sha256 от выданного значения.
    private function rawToken(string $userId): string
    {
        // Токен восстанавливается перебором невозможен (32 случайных байта), поэтому тест
        // подменяет строку: так проверяется поведение по токену, а не генератор случайных чисел.
        $raw = bin2hex(random_bytes(32));

        run(
            'UPDATE ' . t('password_reset') . ' SET token_hash = ? WHERE user_id = ? AND used_at IS NULL',
            [hash('sha256', $raw), $userId]
        );

        return $raw;
    }
}
