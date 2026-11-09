<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use CURLFile;
use PHPUnit\Framework\TestCase;

/**
 * Экран «Настройки» /admin/settings: гость уходит на вход или получает 403, редактор видит поля
 * шапки и подвала и сохраняет их одной формой. Тексты после сохранения печатаются темой на
 * каждой странице; ключ уведомления — settings_saved.
 */
final class SettingsAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
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
    }

    private function login(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    public function testГостюЭкранНастроекНедоступен(): void
    {
        $page = Kernel::get('/admin/settings', ['follow' => false]);

        self::assertSame(302, $page['status'], 'анонима ядро ведёт на вход');
        self::assertStringContainsString('/login?next=%2Fadmin%2Fsettings', $page['headers']['location']);

        $denied = Kernel::post('/admin/settings', [
            'header_text' => 'Взлом',
            'footer_text' => 'Взлом',
        ]);

        self::assertSame(403, $denied['status']);
        self::assertStringContainsString('Раздел открыт только редактору и администратору.', $denied['body']);
    }

    public function testЭкранОткрытРедакторуИПоказываетПоляШапкиИПодвала(): void
    {
        $this->login();

        $screen = Kernel::get('/admin/settings');

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('<title>Настройки — админка</title>', $screen['body']);
        self::assertStringContainsString('Шапка сайта', $screen['body']);
        self::assertStringContainsString('Подвал сайта', $screen['body']);
        self::assertStringContainsString('name="header_logo"', $screen['body'], 'поле пути логотипа шапки');
        self::assertStringContainsString('name="header_logo_file"', $screen['body'], 'выбор файла логотипа шапки');
        self::assertStringContainsString('name="header_text"', $screen['body'], 'текст шапки');
        self::assertStringContainsString('name="footer_logo"', $screen['body'], 'поле пути логотипа подвала');
        self::assertStringContainsString('name="footer_logo_file"', $screen['body'], 'выбор файла логотипа подвала');
        self::assertStringContainsString('name="footer_text"', $screen['body'], 'текст подвала');
        self::assertStringContainsString('noindex, nofollow', $screen['body'], 'приватный экран поисковой машине закрыт');
        self::assertStringContainsString('href="/admin/menu"', $screen['body'], 'вкладка меню в каркасе админки');
    }

    public function testСохранениеПишетТекстыИВозвращаетПлашку(): void
    {
        $this->login();
        $token = Kernel::tokenFrom(Kernel::get('/admin/settings')['body']);

        $saved = Kernel::post('/admin/settings', [
            '_token' => $token,
            'header_logo' => '',
            'header_text' => 'Школа у реки',
            'footer_logo' => '',
            'footer_text' => 'Подпись подвала из настроек.',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertSame('/admin/settings?notice=settings_saved', $saved['headers']['location']);

        $screen = Kernel::get($saved['headers']['location']);

        self::assertStringContainsString('Настройки шапки и подвала сохранены.', $screen['body'], 'плашка успеха');
        self::assertStringContainsString('value="Школа у реки"', $screen['body'], 'текст шапки вернулся в форму');
        self::assertStringContainsString('Подпись подвала из настроек.', $screen['body'], 'текст подвала вернулся в форму');

        $home = Kernel::get('/');

        self::assertStringContainsString('Школа у реки', $home['body'], 'текст шапки печатается темой');
        self::assertStringContainsString('Подпись подвала из настроек.', $home['body'], 'текст подвала печатается темой');
    }

    public function testЛоготипШапкиМожноЗагрузитьСКомпьютера(): void
    {
        $source = dirname(__DIR__, 2) . '/public/assets/img/brand/og-default.png';

        self::assertFileExists($source);

        $this->login();
        $token = Kernel::tokenFrom(Kernel::get('/admin/settings')['body']);

        try {
            $saved = Kernel::post('/admin/settings', [
                '_token' => $token,
                'header_logo' => '',
                'header_text' => 'Школа у реки',
                'footer_logo' => '',
                'footer_text' => 'Подпись подвала из настроек.',
                'header_logo_file' => new CURLFile($source, 'image/png', 'shapka.png'),
            ]);

            self::assertSame(302, $saved['status']);
            self::assertSame('/admin/settings?notice=settings_saved', $saved['headers']['location']);

            $logo = getOption('header_logo');
            self::assertMatchesRegularExpression(
                '#^/uploads/images/[0-9a-hjkmnp-tv-z]{26}\.png$#',
                $logo,
                'сервер сам назвал файл логотипа'
            );
            self::assertFileExists(dirname(__DIR__, 2) . '/public' . $logo, 'логотип лёг в каталог загрузок');

            $screen = Kernel::get($saved['headers']['location']);
            self::assertStringContainsString('Настройки шапки и подвала сохранены.', $screen['body'], 'плашка успеха');
            self::assertStringContainsString('value="' . $logo . '"', $screen['body'], 'путь логотипа вернулся в форму');

            $home = Kernel::get('/');
            self::assertStringContainsString('src="' . $logo . '"', $home['body'], 'логотип печатается темой');
        } finally {
            $logo = getOption('header_logo');

            if ($logo !== '') {
                pageImageDelete($logo);
            }

            deleteOption('header_logo');
        }
    }
}
