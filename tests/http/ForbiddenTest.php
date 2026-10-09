<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Отказ — страница, а не обрывок: человек обязан увидеть тот же каркас темы, что и на 404
// и на входе, иначе он получает текст без шапки, футера и подключённых стилей.
final class ForbiddenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser('admin@school.dev', 'длинный-тестовый-пароль', 'admin');
        TestDb::seedUser('editor@school.dev', 'длинный-тестовый-пароль', 'editor');

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

    public function testОтказАдминуВЧужомРазделеПриходитСтраницейТемы(): void
    {
        $this->enter('editor@school.dev');

        $response = Kernel::get('/admin/apps');

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('<!doctype html>', $response['body']);
        self::assertStringContainsString('<body class="min-h-screen', $response['body']);
        self::assertStringContainsString('<header', $response['body']);
        self::assertStringContainsString('<footer', $response['body']);
        self::assertStringContainsString('href="/assets/app.css"', $response['body']);
        self::assertStringContainsString('<title>Сюда нельзя</title>', $response['body']);
        self::assertStringContainsString('Раздел доступен только администратору.', $response['body']);
        // Отказ не должен попадать в индекс: и заголовком, и метой внутри каркаса.
        self::assertStringContainsString('noindex', $response['headers']['x-robots-tag']);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response['body']);
        self::assertSame('text/html; charset=utf-8', $response['headers']['content-type']);
        // Пакет V, находка 3 аудита просмотра: отказ собран через `renderToString()` → `render()`, поэтому
        // запрет кэширования приходит из того же правила, что и у админки, а не из значения
        // `session.cache_limiter` по умолчанию. Текст отказа зависит от прав текущего человека, и
        // сохранённую страницу нельзя отдавать тому, кому вход открыт.
        self::assertSame('no-store', $response['headers']['cache-control'], 'Отказ 403 не отдал собственный запрет кэширования');
    }

    public function testАнонимныйПостПолучаетОтказТакжеСтраницейТемы(): void
    {
        // POST анонима не редиректится на вход: редирект потерял бы тело запроса, поэтому
        // здесь уместен ровно 403 — и он обязан быть собранной страницей, а не фрагментом.
        $response = Kernel::post('/admin/apps', ['_token' => str_repeat('0', 64), 'op' => 'install']);

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('<!doctype html>', $response['body']);
        self::assertStringContainsString('<body class="min-h-screen', $response['body']);
        self::assertStringContainsString('Раздел открыт только редактору и администратору.', $response['body']);
    }

    public function testАнонимныйГетНеПадаетВОтказАУходитНаВход(): void
    {
        // Этот тест держит границу: каркас отказа обязаны получать только те отказы, которые
        // правда отказ. Анонима на GET приватного раздела ядро перенаправляет на форму входа,
        // и подмена этой ветки вызовом forbidden() краснит проверку, а не делает её зелёной.
        $response = Kernel::get('/admin/apps', ['follow' => false]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('/login?next=%2Fadmin%2Fapps', $response['headers']['location']);
    }

    private function enter(string $email): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => 'длинный-тестовый-пароль',
        ]);
    }
}
