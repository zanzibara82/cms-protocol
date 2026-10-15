<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LoginLimitTest extends TestCase
{
    private const NOW = '2026-10-06 06:00:00';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('login_attempt');
        TestDb::clearTable('user');
    }

    private function now(): DateTimeImmutable
    {
        return fromDb(self::NOW) ?? nowUtc();
    }

    public function testПятьНеудачНаАдресЗакрываютВход(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            recordLoginAttempt('redaktor@school.dev', '203.0.113.7', $this->now());
        }

        self::assertTrue(loginBlocked('redaktor@school.dev', '203.0.113.99', $this->now()), 'Лимит по адресу действует с любого IP');
    }

    public function testЧетыреНеудачиЕщёНеБлокируют(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL - 1; $i++) {
            recordLoginAttempt('redaktor@school.dev', '203.0.113.7', $this->now());
        }

        self::assertFalse(loginBlocked('redaktor@school.dev', '203.0.113.7', $this->now()));
    }

    public function testПопыткиСтаршеПятнадцатиМинутНеСчитаются(): void
    {
        $old = $this->now()->sub(new DateInterval('PT16M'));
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            recordLoginAttempt('redaktor@school.dev', '203.0.113.7', $old);
        }

        self::assertFalse(loginBlocked('redaktor@school.dev', '203.0.113.7', $this->now()));
    }

    public function testДвадцатьПопытокСОдногоАйпиЗакрываютВходДляВсех(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_IP; $i++) {
            recordLoginAttempt('kto-to-' . $i . '@school.dev', '203.0.113.7', $this->now());
        }

        self::assertTrue(loginBlocked('drugoy@school.dev', '203.0.113.7', $this->now()));
        self::assertFalse(loginBlocked('drugoy@school.dev', '203.0.113.8', $this->now()));
    }

    public function testНеверныйПарольПишетПопыткуИОтдаётОдинИТотЖеТекст(): void
    {
        TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');

        $wrong = attemptLogin('redaktor@school.dev', 'неверный');
        $absent = attemptLogin('net-takogo@school.dev', 'неверный');

        self::assertFalse($wrong['ok']);
        self::assertSame($wrong['error'], $absent['error'], 'Текст не должен выдавать, есть ли такой адрес');
        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('login_attempt'), []));
    }

    // Отказ по несуществующему адресу обязан стоить столько же, сколько настоящая проверка
    // пароля: пока ветка «строки нет» возвращалась сразу, по времени ответа перечисляли адреса,
    // хотя тексты отказов уже были одинаковые. Замер сделан относительно эталона, полученного
    // на этой же машине в этом же процессе, — поэтому он не зависит от скорости диска и не
    // сравнивает между собой две ветки входа (такой тест дрейфует).
    public function testОтказПоНеизвестномуАдресуСтоитКакПроверкаПароля(): void
    {
        $probeHash = hashPassword('эталон-для-замера');

        $start = hrtime(true);
        verifyPassword('не тот пароль', $probeHash);
        $verifyMs = (hrtime(true) - $start) / 1000000;

        $start = hrtime(true);
        $result = attemptLogin('net-takogo@school.dev', 'неверный-пароль');
        $loginMs = (hrtime(true) - $start) / 1000000;

        self::assertFalse($result['ok']);
        self::assertSame(LOGIN_ERROR_GENERIC, $result['error']);
        self::assertGreaterThanOrEqual($verifyMs * 0.8, $loginMs, 'Отказ по неизвестному адресу быстрее проверки пароля — адрес выдаётся временем ответа');
    }

    // Пакет #289, находка первой тяжести: адрес длиннее ширины колонки tbl_login_attempt.email (190)
    // не должен доходить до записи попытки — иначе переполнение колонки превращает отказ по заведомо
    // неизвестному адресу в 500-й ответ. Ветка отказа стоит до обращения к базе и до проверки пароля,
    // поэтому в журнале не прибавляется ни строки, а текст остаётся общим, как при неизвестном адресе.
    public function testАдресДлиннееКолонкиОтказываетсяБезЗаписиПопытки(): void
    {
        // Ровно порог (190) ещё помещается в колонку, поэтому берём 191 символ локальной части.
        $email = str_repeat('a', LOGIN_EMAIL_MAX + 1) . '@school.dev';
        self::assertGreaterThan(LOGIN_EMAIL_MAX, mb_strlen($email), 'Замер собран: адрес именно длиннее порога');

        $before = (int) value('SELECT COUNT(*) FROM ' . t('login_attempt'), []);
        $result = attemptLogin($email, 'любой-пароль');
        $after = (int) value('SELECT COUNT(*) FROM ' . t('login_attempt'), []);

        self::assertFalse($result['ok'], 'Слишком длинный адрес не входит');
        self::assertSame(LOGIN_ERROR_GENERIC, $result['error'], 'Текст отказа не выдаёт, что адрес слишком длинный');
        self::assertSame($before, $after, 'Попытка по заведомо неизвестному адресу не пишется в журнал');
    }

    public function testВерныйПарольВпускаетРедактора(): void
    {
        TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');

        // Адрес отличается от сидового только регистром и пробелами по краям: перед сверкой с
        // базой он нормализуется, и вход обязан выпустить того же редактора.
        $result = attemptLogin('  Redaktor@School.Dev  ', 'верный-пароль');
        $clean = attemptLogin('redaktor@school.dev', 'верный-пароль');

        self::assertTrue($result['ok'], 'Регистр и краевые пробелы не мешают входу');
        self::assertSame('redaktor@school.dev', $result['user']['email']);
        self::assertFalse($clean['ok'] === false);
        self::assertSame('redaktor@school.dev', $clean['user']['email']);
    }

    // Пакет #289, А-3: наружу уходит узкий контракт возврата — четыре поля без хеша пароля. Полная
    // строка остаётся внутри ядра (её читает loginUser()), а не уезжает в ответ вызывающей стороне.
    public function testУспешныйВходОтдаётУзкийКонтрактБезХешаПароля(): void
    {
        TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');

        $result = attemptLogin('redaktor@school.dev', 'верный-пароль');

        self::assertTrue($result['ok'], 'Верный пароль выпускает редактора');
        self::assertArrayNotHasKey('password_hash', $result['user'], 'Хеш пароля не должен уходить наружу');
        self::assertSame(
            ['id', 'email', 'name', 'role'],
            array_keys($result['user']),
            'Наружу — ровно четыре поля в согласованном порядке'
        );
        self::assertSame('redaktor@school.dev', $result['user']['email'], 'Сужение контракта не теряет адрес');
    }

    public function testЗаблокированныйАккаунтНеВпускаетДажеСВернымПаролем(): void
    {
        $id = TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');
        run('UPDATE ' . t('user') . ' SET blocked_at = ? WHERE id = ?', [utcStamp(nowUtc()), $id]);

        $result = attemptLogin('redaktor@school.dev', 'верный-пароль');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('заблокирован', $result['error']);
    }
}
