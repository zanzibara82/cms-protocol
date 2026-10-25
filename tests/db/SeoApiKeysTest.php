<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * API-ключи машинного чтения (S6): форма ключа, хранение хешем, аутентификация заголовком,
 * предел числа ключей и отзыв.
 *
 * Набор держит обещание экрана: полный ключ не живёт в базе, отозванный ключ не отвечает,
 * а «ещё раз с тем же ключом» после отзыва — отказ, а не доступ.
 */
final class SeoApiKeysTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        run('DROP TABLE IF EXISTS ' . t('seo_api_key'));
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        run('DELETE FROM ' . t('seo_api_key'));
    }

    // ── Форма ключа ────────────────────────────────────────

    public function testКлючСобираетсяВСвоейФорме(): void
    {
        $key = seoApiKeyGenerate();

        self::assertStringStartsWith(SEO_API_KEY_PREFIX, $key, 'Префикс в самом ключе — часть его формы: человек видит, чья это метка');
        self::assertSame(strlen(SEO_API_KEY_PREFIX) + SEO_API_KEY_HEX_LENGTH, strlen($key));
        self::assertNotSame($key, seoApiKeyGenerate(), 'Два ключа подряд обязаны различаться');
        self::assertSame($key, seoApiKeyShape($key), 'Свой же ключ обязан проходить разбор формы');
    }

    public function testЧужаяФормаКлючаОтвергаетсяЦеликом(): void
    {
        self::assertNull(seoApiKeyShape(''));
        self::assertNull(seoApiKeyShape('bearer-token'), 'Чужой префикс — не наш ключ');
        self::assertNull(seoApiKeyShape(SEO_API_KEY_PREFIX . 'коротко'), 'Скрытая часть короче предела в базу не смотрит');
        self::assertNull(seoApiKeyShape(SEO_API_KEY_PREFIX . str_repeat('Z', SEO_API_KEY_HEX_LENGTH)), 'Не шестнадцатеричные знаки — не ключ');
    }

    public function testХешНеПохожНаКлюч(): void
    {
        $key = seoApiKeyGenerate();
        $hash = seoApiKeyHash($key);

        self::assertSame(64, strlen($hash), 'Хеш sha256 — 64 знака');
        self::assertStringNotContainsString(substr($key, strlen(SEO_API_KEY_PREFIX)), $hash, 'Хеш не вправе нести сам ключ');
        self::assertSame($hash, seoApiKeyHash($key), 'Один ключ — один хеш, иначе сверка не сработает');
    }

    // ── Создание ───────────────────────────────────────────

    public function testКлючСоздаётсяИЛожитсяХешем(): void
    {
        $created = seoApiKeyCreate('Кабинет отчётов');

        self::assertTrue($created['ok'], $created['error']);
        self::assertNotSame('', $created['key']);
        self::assertSame($created['key'], seoApiKeyShape($created['key']), 'Созданный ключ обязан быть в своей форме');

        $row = one('SELECT label, key_prefix, key_hash, last_used_at, revoked_at FROM ' . t('seo_api_key'), []);

        self::assertIsArray($row);
        self::assertSame('Кабинет отчётов', (string) $row['label']);
        self::assertSame(seoApiKeyHash($created['key']), (string) $row['key_hash'], 'В базе лежит хеш, а не ключ');
        self::assertStringStartsWith(SEO_API_KEY_PREFIX, (string) $row['key_prefix'], 'Префикс строки экрана — начало ключа, по нему человек узнаёт свой ключ');
        self::assertNull($row['last_used_at'], 'Новый ключ ещё не использовался');
        self::assertNull($row['revoked_at']);

        self::assertStringNotContainsString(
            $created['key'],
            (string) value('SELECT GROUP_CONCAT(key_prefix, key_hash) FROM ' . t('seo_api_key'), []),
            'Полного ключа в базе нет ни в одной колонке'
        );
    }

    public function testКлючБезПодписиНеСоздаётся(): void
    {
        $created = seoApiKeyCreate('   ');

        self::assertFalse($created['ok'], 'Ключ без подписи нельзя отличить от другого — отказ с объяснением');
        self::assertNotSame('', $created['error']);
        self::assertSame('', $created['key']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key'), []));
    }

    public function testДлиннаяПодписьОтвергается(): void
    {
        $created = seoApiKeyCreate(str_repeat('ы', 300));

        self::assertFalse($created['ok']);
        self::assertStringContainsString('190', $created['error'], 'Отказ называет предел, а не «что-то не так»');
    }

    public function testЧислоКлючейОграничено(): void
    {
        for ($i = 0; $i < SEO_API_KEY_MAX; $i++) {
            $created = seoApiKeyCreate('Ключ ' . ($i + 1));
            self::assertTrue($created['ok'], $created['error']);
        }

        $extra = seoApiKeyCreate('Лишний ключ');

        self::assertFalse($extra['ok'], 'Предел числа ключей обязан держаться: чек-лист хостинга кладёт память прежде удобства');
        self::assertStringContainsString((string) SEO_API_KEY_MAX, $extra['error']);
        self::assertSame(SEO_API_KEY_MAX, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key'), []));
    }

    // ── Аутентификация ─────────────────────────────────────

    public function testЗаголовокBearerПускаетИОтмечаетИспользование(): void
    {
        $created = seoApiKeyCreate('Кабинет отчётов');
        self::assertTrue($created['ok'], $created['error']);

        $key = seoApiKeyAuthenticate('Bearer ' . $created['key']);

        self::assertIsArray($key, 'Действующий ключ обязан пускать');
        self::assertSame($created['id'], $key['id']);
        self::assertSame('Кабинет отчётов', $key['label']);

        self::assertNotNull(
            one('SELECT last_used_at FROM ' . t('seo_api_key') . ' WHERE id = ?', [$created['id']])['last_used_at'] ?? null,
            'Отметка использования нужна экрану: по ней видно, живёт ли интеграция'
        );
    }

    public function testЧужойИПустойЗаголовокНеПускают(): void
    {
        $created = seoApiKeyCreate('Кабинет отчётов');
        self::assertTrue($created['ok'], $created['error']);

        self::assertNull(seoApiKeyAuthenticate(''), 'Без заголовка доступа нет');
        self::assertNull(seoApiKeyAuthenticate($created['key']), 'Схема Bearer обязательна: ключ без неё не разбирается');
        self::assertNull(seoApiKeyAuthenticate('Basic ' . $created['key']), 'Чужая схема — отказ');
        self::assertNull(seoApiKeyAuthenticate('Bearer ' . SEO_API_KEY_PREFIX . str_repeat('0', SEO_API_KEY_HEX_LENGTH)), 'Ключ своей формы, но незаведённый — отказ');
    }

    public function testСхемаЧитаетсяБезОглядкиНаРегистр(): void
    {
        $created = seoApiKeyCreate('Кабинет отчётов');
        self::assertTrue($created['ok'], $created['error']);

        self::assertIsArray(seoApiKeyAuthenticate('bearer ' . $created['key']), 'Читатели пишут схему по-разному, а ключ один и тот же');
    }

    // ── Отзыв ──────────────────────────────────────────────

    public function testОтозванныйКлючБольшеНеПускает(): void
    {
        $created = seoApiKeyCreate('Кабинет отчётов');
        self::assertTrue($created['ok'], $created['error']);
        self::assertIsArray(seoApiKeyAuthenticate('Bearer ' . $created['key']));

        $revoked = seoApiKeyRevoke($created['id']);

        self::assertTrue($revoked['ok'], $revoked['error']);
        self::assertSame('api_key_revoked', $revoked['notice']);
        self::assertNull(seoApiKeyAuthenticate('Bearer ' . $created['key']), 'Отозванный ключ не отвечает и при известном секрете');
    }

    public function testОтозванныйКлючОстаётсяВСписке(): void
    {
        $created = seoApiKeyCreate('Кабинет отчётов');
        seoApiKeyRevoke($created['id']);

        $keys = seoApiKeysList();

        self::assertCount(1, $keys, 'Строка остаётся: «вчера ключом пользовались» не должно исчезать из экрана');
        self::assertNotSame('', $keys[0]['revoked_at']);
        self::assertSame('Кабинет отчётов', $keys[0]['label']);
    }

    public function testПовторныйОтзывОбъясняетОтказ(): void
    {
        $created = seoApiKeyCreate('Кабинет отчётов');
        seoApiKeyRevoke($created['id']);

        $again = seoApiKeyRevoke($created['id']);

        self::assertFalse($again['ok']);
        self::assertStringContainsString('отозван', $again['error']);
    }

    public function testОтзывНесуществующегоКлючаОбъясняетОтказ(): void
    {
        $result = seoApiKeyRevoke('нет-такого-ключа');

        self::assertFalse($result['ok']);
        self::assertNotSame('', $result['error']);
    }

    public function testСписокПустойПокаКлючейНет(): void
    {
        self::assertSame([], seoApiKeysList(), 'Пустой список — это пустой список, а не отказ');
    }
}
