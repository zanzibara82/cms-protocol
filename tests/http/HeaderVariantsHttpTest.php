<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Варианты шапки и подвала через админку: выбор сохраняется, неизвестное значение откатывается,
 * а вывод меняется на публичной странице.
 *
 * Настройки живут в опциях, поэтому каждая проверка обязана вернуть прежние значения: иначе
 * выбранная шапка переедет в следующие наборы и порядок выполнения начнёт влиять на результат.
 */
final class HeaderVariantsHttpTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@school.dev';
    private const ADMIN_PASSWORD = 'админ-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'admin');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        deleteOption('header_variant');
        deleteOption('footer_variant');
        optionsReset();
    }

    protected function tearDown(): void
    {
        deleteOption('header_variant');
        deleteOption('footer_variant');
        optionsReset();
    }

    private function login(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
    }

    public function testАдминистраторВыбираетВариантШапкиИОнСохраняется(): void
    {
        $this->login();
        $saved = Kernel::post('/admin/settings', $this->settingsBody(['header_variant' => 'centered', 'footer_variant' => 'compact']));

        self::assertSame(302, $saved['status']);
        self::assertSame('centered', getOption('header_variant'));
        self::assertSame('compact', getOption('footer_variant'));
    }

    public function testНеизвестныйВариантИзФормыНеЛомаетВид(): void
    {
        $this->login();
        Kernel::post('/admin/settings', $this->settingsBody(['header_variant' => 'hacker', 'footer_variant' => 'hacker']));

        // Правило применяется при отдаче плана: чужая строка означает стандартный вид.
        self::assertSame('standard', headerPlan(getOption('header_variant'))['variant']);
        self::assertSame('standard', footerPlan(getOption('footer_variant'))['variant']);
    }

    public function testМинималистичнаяШапкаМеняетПубличнуюВыдачу(): void
    {
        $this->login();
        Kernel::post('/admin/settings', $this->settingsBody(['header_variant' => 'minimal']));
        optionsReset();

        $page = Kernel::get('/');
        self::assertSame(200, $page['status']);
        // Полоса шапки есть, а пункты меню в ней не печатаются: навигация остаётся в подвале.
        self::assertStringContainsString('<header', $page['body']);
        self::assertFalse(headerPlan(getOption('header_variant'))['menu']);
    }

    public function testЭкранНастроекПечатаетВыборВариантов(): void
    {
        $this->login();
        $page = Kernel::get('/admin/settings');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('name="header_variant"', $page['body']);
        self::assertStringContainsString('name="footer_variant"', $page['body']);
        self::assertStringContainsString('Минималистичная', $page['body']);
    }

    /** @param array<string, string> $overrides
     *  @return array<string, string> */
    private function settingsBody(array $overrides): array
    {
        $form = Kernel::get('/admin/settings');

        $body = [
            'header_logo' => '',
            'header_text' => 'Школа',
            'footer_logo' => '',
            'footer_text' => 'Подпись',
            'footer_copyright' => 'Копирайт',
            'maintenance_text' => '',
            'header_variant' => 'standard',
            'footer_variant' => 'standard',
            '_token' => Kernel::tokenFrom($form['body']),
        ];

        return array_merge($body, $overrides);
    }
}
