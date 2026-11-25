<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран «Дизайн → Библиотека паттернов» /admin/patterns: каталог коротких наборов блоков
 * и вставка в существующую страницу. Право конструктора (login) — паттерн меняет содержимое,
 * а не оформление сайта.
 */
final class PatternLibraryAdminTest extends TestCase
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

        foreach (['trash', 'page_old_slug', 'page_section', 'page'] as $table) {
            run('DELETE FROM ' . t($table));
        }
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

    public function testГостюБиблиотекаНедоступна(): void
    {
        $page = Kernel::get('/admin/patterns', ['follow' => false]);

        self::assertSame(302, $page['status'], 'анонима ядро ведёт на вход');
        self::assertStringContainsString('/login?next=%2Fadmin%2Fpatterns', $page['headers']['location']);

        $denied = Kernel::post('/admin/patterns', ['op' => 'insert', 'page' => '', 'pattern' => 'intro']);

        self::assertSame(403, $denied['status'], 'POST без CSRF-токена отклоняется');
    }

    public function testРедакторВидитКаталогПаттернов(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);

        $screen = Kernel::get('/admin/patterns');

        self::assertSame(200, $screen['status'], 'паттерн ставит содержимое — право конструктора');
        self::assertStringContainsString('<title>Библиотека паттернов — админка</title>', $screen['body']);
        self::assertStringContainsString('Три преимущества', $screen['body'], 'готовый паттерн в каталоге');
        self::assertStringContainsString('noindex, nofollow', $screen['body'], 'приватный экран закрыт от поиска');
        self::assertStringContainsString('href="/admin/patterns?category=cards"', $screen['body'], 'фильтр категорий');
        self::assertStringContainsString('Главная страница', $screen['body'], 'главная есть в списке страниц');
    }

    public function testФильтрКатегорииПоказываетТолькоСвои(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);

        $filtered = Kernel::get('/admin/patterns?category=cards');

        self::assertSame(200, $filtered['status']);
        self::assertStringContainsString('Три преимущества', $filtered['body']);
        self::assertStringNotContainsString('Короткие контакты', $filtered['body'], 'чужая категория не печатается');

        $unknown = Kernel::get('/admin/patterns?category=nesushchestvuyushchaya');

        self::assertSame(200, $unknown['status']);
        self::assertStringContainsString('В этой категории паттернов нет', $unknown['body']);
        self::assertStringNotContainsString('Три преимущества', $unknown['body']);
    }

    public function testВставкаСоздаётБлокиИВедётВРедактор(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $created = createPage([
            'title' => 'Страница с паттерном',
            'slug' => 'stranitsa-s-patternom',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ]);
        self::assertTrue($created['ok'], $created['error']);

        $token = Kernel::tokenFrom(Kernel::get('/admin/patterns')['body']);

        $inserted = Kernel::post('/admin/patterns', [
            '_token' => $token,
            'op' => 'insert',
            'page' => (string) $created['id'],
            'pattern' => 'three-steps',
        ]);

        self::assertSame(302, $inserted['status']);
        self::assertSame(
            '/admin/pages/' . (string) $created['id'] . '?notice=pattern_applied',
            $inserted['headers']['location'],
            'после вставки человек попадает в редактор страницы'
        );

        $sections = pageSections((string) $created['id']);
        self::assertCount(1, $sections, 'паттерн «Три шага» — один блок');
        self::assertSame('steps', (string) $sections[0]['kind']);
    }

    public function testЧужойКлючОтклоняется(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);

        $created = createPage([
            'title' => 'Страница для отказа',
            'slug' => 'stranitsa-dlya-otkaza',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ]);
        self::assertTrue($created['ok'], $created['error']);

        $token = Kernel::tokenFrom(Kernel::get('/admin/patterns')['body']);

        $denied = Kernel::post('/admin/patterns', [
            '_token' => $token,
            'op' => 'insert',
            'page' => (string) $created['id'],
            'pattern' => 'net-takogo-patterna',
        ]);

        self::assertSame(302, $denied['status']);
        self::assertStringContainsString('/admin/patterns?notice=migration-failed', $denied['headers']['location']);
        self::assertCount(0, pageSections((string) $created['id']));
    }
}
