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

    public function testЗаблокированныйАккаунтНеВпускаетДажеСВернымПаролем(): void
    {
        $id = TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');
        run('UPDATE ' . t('user') . ' SET blocked_at = ? WHERE id = ?', [utcStamp(nowUtc()), $id]);

        $result = attemptLogin('redaktor@school.dev', 'верный-пароль');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('заблокирован', $result['error']);
    }
}
