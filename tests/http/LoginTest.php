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
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        // Каждый тест начинается сам: журнал попыток очищается, аккаунт сидируется заново.
        // Без этого тесты зависели бы друг от друга — например, «подставной заголовок» проходил
        // только потому, что до него пять неудач записал предыдущий тест.
        TestDb::clearTable('login_attempt');
        TestDb::clearTable('user');
        TestDb::seedUser('admin@school.dev', 'верный-пароль', 'admin');
    }

    private function token(): string
    {
        return $this->tokenFrom(Kernel::get('/login')['body']);
    }

    /** Пять неудач своими запросами: лимит по адресу исчерпан независимо от порядка тестов. */
    private function exhaustLoginLimit(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        }
    }

    public function testСтраницаВходаОткрытаАнониму(): void
    {
        $page = Kernel::get('/login');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вход для редактора', $page['body']);
        self::assertStringContainsString('method="post"', $page['body'], 'Форма с паролем обязана быть POST');
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        // Минимум проекта — зона нажатия 44 px: каждый контрол экрана обязан нести этот класс.
        self::assertStringContainsString('min-h-[44px] w-full rounded-lg border border-ink/20', $page['body'], 'Поля входа не меньше 44 px');
        self::assertStringContainsString('min-h-[44px] w-full rounded-lg bg-ink', $page['body'], 'Кнопка входа не меньше 44 px');
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
        $this->exhaustLoginLimit();

        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    public function testВраждебныйNextНеУводитНаЧужойДомен(): void
    {
        // Обратный слэш сразу после одиночного «/» браузер сводит к «//evil.com», то есть к чужому
        // хосту. После входа в Location обязан остаться внутренний путь, а не адрес из ссылки.
        $response = Kernel::post(
            '/login?next=/\\evil.com',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location'], 'Открытый редирект закрыт: пускаем только внутренний путь');
        self::assertStringNotContainsString('evil.com', $response['headers']['location']);
    }

    public function testВнутреннийNextПослеВходаСохраняется(): void
    {
        // Фильтр не должен выкидывать и законную глубинную ссылку на свой сайт.
        $response = Kernel::post(
            '/login?next=/news',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/news', $response['headers']['location']);
    }

    public function testПодставнойЗаголовокАдресаНеСнимаетЛимит(): void
    {
        $this->exhaustLoginLimit();

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
