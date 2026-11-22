<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран «Дизайн → Темы» /admin/themes: аноним уходит на вход, редактор получает 403 (переключение
 * темы меняет оформление всего сайта — право администратора), администратор видит каталог и
 * переключает тему. Встроенная тема возвращается действием `deactivate`.
 *
 * Каталог `themes/` реальный: тест переключает установленную тему `school` и обязан вернуть
 * настройку в исходное состояние в finally — иначе следующие наборы увидят чужой каркас.
 */
final class ThemesAdminTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@school.dev';
    private const ADMIN_PASSWORD = 'админ-пароль';
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'admin');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        // Каждая проверка начинается со встроенной темы: иначе состояние одной проверки
        // переедет в следующую и порядок выполнения начнёт влиять на результат.
        deleteOption('active_theme');
    }

    protected function tearDown(): void
    {
        deleteOption('active_theme');
    }

    private function login(string $email, string $password): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function testГостюЭкранТемНедоступен(): void
    {
        $page = Kernel::get('/admin/themes', ['follow' => false]);

        self::assertSame(302, $page['status'], 'анонима ядро ведёт на вход');
        self::assertStringContainsString('/login?next=%2Fadmin%2Fthemes', $page['headers']['location']);

        $denied = Kernel::post('/admin/themes', ['op' => 'activate', 'slug' => 'school']);

        self::assertSame(403, $denied['status']);
    }

    public function testРедакторуЭкранТемЗакрыт(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);

        $page = Kernel::get('/admin/themes');

        self::assertSame(403, $page['status'], 'переключение темы — право администратора');
    }

    public function testАдминистраторВидитКаталогТемИВстроеннуюТему(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $screen = Kernel::get('/admin/themes');

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('<title>Темы — админка</title>', $screen['body']);
        self::assertStringContainsString('Встроенная тема', $screen['body'], 'встроенная тема всегда в каталоге');
        self::assertStringContainsString('Школа', $screen['body'], 'установленная тема из themes/school');
        self::assertStringContainsString('1.0.0', $screen['body'], 'версия из манифеста');
        self::assertStringContainsString('noindex, nofollow', $screen['body'], 'приватный экран закрыт от поиска');
        self::assertStringContainsString('href="/admin/settings"', $screen['body'], 'вкладка настроек в каркасе админки');
    }

    public function testАктивацияТемыМеняетКаркасСайта(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $token = Kernel::tokenFrom(Kernel::get('/admin/themes')['body']);

        $saved = Kernel::post('/admin/themes', [
            '_token' => $token,
            'op' => 'activate',
            'slug' => 'school',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertSame('/admin/themes?notice=theme_activated', $saved['headers']['location']);
        self::assertSame('school', getOption('active_theme'), 'настройка переключена на тему');

        // Тема «Школа» несёт акцентную полосу под шапкой и контейнер max-w-6xl — по ним видно,
        // что страница отдана именно её каркасом, а не встроенным.
        $home = Kernel::get('/');
        self::assertSame(200, $home['status']);
        self::assertStringContainsString('max-w-6xl', $home['body'], 'каркас темы отдаёт широкий контейнер');
        self::assertStringContainsString('bg-accent', $home['body'], 'акцентная полоса темы на месте');

        // Плашка живёт в адресе редиректа (?notice=), а не в сессии: без него экран её не покажет.
        $screen = Kernel::get($saved['headers']['location']);
        self::assertStringContainsString('Тема активирована.', $screen['body'], 'плашка успеха');
    }

    public function testВозвратКВстроеннойТеме(): void
    {
        setOption('active_theme', 'school');
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $token = Kernel::tokenFrom(Kernel::get('/admin/themes')['body']);

        $saved = Kernel::post('/admin/themes', [
            '_token' => $token,
            'op' => 'deactivate',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertSame('/admin/themes?notice=theme_deactivated', $saved['headers']['location']);
        self::assertSame('', getOption('active_theme'), 'встроенная тема — пустое значение настройки');

        $home = Kernel::get('/');
        self::assertStringContainsString('max-w-5xl', $home['body'], 'встроенный каркас вернулся');
        self::assertStringNotContainsString('bg-accent', $home['body'], 'полосы темы больше нет');
    }

    public function testНеизвестнаяТемаНеАктивируется(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $token = Kernel::tokenFrom(Kernel::get('/admin/themes')['body']);

        $saved = Kernel::post('/admin/themes', [
            '_token' => $token,
            'op' => 'activate',
            'slug' => 'net-takoy-temy',
        ]);

        self::assertSame(302, $saved['status'], 'отказ возвращает на экран, а не отдаёт 500');
        self::assertSame('', getOption('active_theme'), 'неизвестная тема не стала активной');
    }
}
