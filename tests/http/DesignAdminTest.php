<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран «Дизайн → Оформление» /admin/design: аноним уходит на вход, редактор получает 403
 * (палитра меняет читаемость всего сайта — право администратора), администратор правит цвета
 * и типографику, и сохранённые значения реально доезжают до публичной страницы.
 *
 * Проверяется не только сохранение настройки, но и то, что она печатается: настройка без
 * применения — это «сохранено» при неизменной странице, и заметить это можно лишь глазами.
 */
final class DesignAdminTest extends TestCase
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
        // Каждая проверка начинается с чистой палитры: иначе цвета одной проверки переехали бы
        // в следующую, и порядок выполнения начал бы влиять на результат.
        foreach (['paper', 'cream', 'ink', 'accent', 'good', 'bad'] as $key) {
            deleteOption('design_color_' . $key);
        }

        foreach (['h1', 'h2', 'h3', 'body'] as $key) {
            deleteOption('design_font_' . $key);
        }

        optionsReset();
    }

    protected function tearDown(): void
    {
        foreach (['paper', 'cream', 'ink', 'accent', 'good', 'bad'] as $key) {
            deleteOption('design_color_' . $key);
        }

        foreach (['h1', 'h2', 'h3', 'body'] as $key) {
            deleteOption('design_font_' . $key);
        }

        optionsReset();
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

    public function testГостюЭкранОформленияНедоступен(): void
    {
        $page = Kernel::get('/admin/design', ['follow' => false]);

        self::assertSame(302, $page['status'], 'анонима ядро ведёт на вход');
        self::assertStringContainsString('/login?next=%2Fadmin%2Fdesign', $page['headers']['location']);

        $denied = Kernel::post('/admin/design', ['color_paper' => '#ffffff']);

        self::assertSame(403, $denied['status'], 'без CSRF и сессии форма не проходит');
    }

    public function testРедакторуЭкранОформленияЗакрыт(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);

        $page = Kernel::get('/admin/design');

        self::assertSame(403, $page['status'], 'палитра сайта — право администратора');
    }

    public function testАдминистраторВидитПалитруИКонтраст(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $screen = Kernel::get('/admin/design');

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('<title>Оформление — админка</title>', $screen['body']);
        // Палитра показывает текущие значения: экран открывается на свежем сайте с умолчаниями темы.
        self::assertStringContainsString('name="color_paper"', $screen['body'], 'поле цвета на месте');
        self::assertStringContainsString('value="#fdf6ec"', $screen['body'], 'значение по умолчанию темы');
        self::assertStringContainsString('name="font_h1"', $screen['body'], 'поле размера заголовка');
        // Контраст считается, а не спрашивается: человек видит число и порог.
        self::assertStringContainsString(':1', $screen['body'], 'коэффициент контраста напечатан');
        self::assertStringContainsString('noindex, nofollow', $screen['body'], 'приватный экран закрыт от поиска');
    }

    public function testСохранениеПалитрыМеняетПубличнуюСтраницу(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $token = Kernel::tokenFrom(Kernel::get('/admin/design')['body']);

        $saved = Kernel::post('/admin/design', [
            '_token' => $token,
            'color_paper' => '#101010',
            'color_ink' => '#f5f5f5',
            'color_cream' => '#fff9ef',
            'color_accent' => '#d97706',
            'color_good' => '#16a34a',
            'color_bad' => '#dc2626',
            'font_h1' => '44',
            'font_body' => '18',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertSame('/admin/design?notice=design_saved', $saved['headers']['location']);
        self::assertSame('#101010', getOption('design_color_paper'), 'цвет сохранён в опции');

        // Главное: значение доехало до страницы. Настройка без печати — «сохранено» при
        // неизменной странице.
        $home = Kernel::get('/');
        self::assertSame(200, $home['status']);
        self::assertStringContainsString('--color-paper: #101010', $home['body'], 'свой цвет в странице');
        self::assertStringContainsString('--color-ink: #f5f5f5', $home['body']);
        self::assertStringContainsString('--design-h1: 44px', $home['body'], 'размер заголовка в странице');
        self::assertStringContainsString('font-size: 18px', $home['body'], 'размер основного текста');
        // Неизменённые цвета в стиль не попадают: иначе правка CSS темы не доезжала бы до сайта.
        self::assertStringNotContainsString('--color-accent', $home['body']);

        $screen = Kernel::get($saved['headers']['location']);
        self::assertStringContainsString('Оформление сохранено.', $screen['body'], 'плашка успеха');
    }

    public function testЧужоеЗначениеЦветаНеПопадаетВСтраницу(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $token = Kernel::tokenFrom(Kernel::get('/admin/design')['body']);

        // Подделанный POST: строка с правилом CSS вместо цвета. Значение отбрасывается — иначе
        // через поле цвета можно было бы внедрить в стиль чужое объявление.
        Kernel::post('/admin/design', [
            '_token' => $token,
            'color_paper' => 'red; background: url(http://evil.test/x.png)',
            'color_ink' => '#2b2118',
            'color_cream' => '#fff9ef',
            'color_accent' => '#d97706',
            'color_good' => '#16a34a',
            'color_bad' => '#dc2626',
            'font_h1' => '',
            'font_body' => '',
        ]);

        self::assertSame('#fdf6ec', getOption('design_color_paper'), 'чужое значение заменено умолчанием');

        $home = Kernel::get('/');
        self::assertStringNotContainsString('evil.test', $home['body'], 'правило не попало в страницу');
        self::assertStringNotContainsString('--color-paper', $home['body'], 'стиль остался прежним');
    }

    public function testПустыеРазмерыОставляютЗначенияТемы(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $token = Kernel::tokenFrom(Kernel::get('/admin/design')['body']);

        // Пустое поле означает «как в теме»: переменная не печатается, и работает CSS темы.
        Kernel::post('/admin/design', [
            '_token' => $token,
            'color_paper' => '#fdf6ec',
            'color_ink' => '#2b2118',
            'color_cream' => '#fff9ef',
            'color_accent' => '#d97706',
            'color_good' => '#16a34a',
            'color_bad' => '#dc2626',
            'font_h1' => '',
            'font_body' => '',
        ]);

        $home = Kernel::get('/');
        self::assertStringNotContainsString('--design-h1', $home['body']);
        self::assertStringNotContainsString('<style>', $home['body'], 'без своих значений стиль не печатается');
    }
}
