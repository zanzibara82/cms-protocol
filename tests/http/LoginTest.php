<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class LoginTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::clearTable('login_attempt');
        TestDb::seedUser('admin@school.dev', 'верный-пароль', 'admin');
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    private function token(): string
    {
        return $this->tokenFrom(Kernel::get('/login')['body']);
    }

    public function testСтраницаВходаОткрытаАнониму(): void
    {
        $page = Kernel::get('/login');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вход для редактора', $page['body']);
        self::assertStringContainsString('method="post"', $page['body'], 'Форма с паролем обязана быть POST');
        self::assertStringContainsString('noindex, nofollow', $page['body']);
    }

    public function testВерныйПарольПереноситВАдминку(): void
    {
        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location']);
    }

    public function testНеверныйПарольПоказываетТотЖеТекстЧтоИЧужойАдрес(): void
    {
        $wrong = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        $absent = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'net@school.dev', 'password' => 'мимо']);

        self::assertSame(200, $wrong['status']);
        self::assertStringContainsString('Почта или пароль не подошли', $wrong['body']);
        self::assertStringContainsString('Почта или пароль не подошли', $absent['body']);
    }

    public function testПослеПятиНеудачВходЗакрывается(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        }

        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    public function testПодставнойЗаголовокАдресаНеСнимаетЛимит(): void
    {
        // Даже с «другим» заголовком переадресации сервер считает адрес из серверного массива: лимит остаётся.
        $response = Kernel::post(
            '/login',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль'],
            ['headers' => ['X-Forwarded-For' => '198.51.100.4']]
        );

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    // Задачей 8 этот помощник пригодится тесту выхода: там токен берётся со страницы админки.
    private function tokenFrom(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $m);

        return $m[1] ?? '';
    }
}
