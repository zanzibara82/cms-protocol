<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран метаданных раздела «SEO и статистика» через настоящий HTTP: вход, форма,
 * сохранение, правка, удаление и влияние правок на собранную страницу.
 */
final class SeoAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'seo-redaktor@school.dev';
    private const EDITOR_PASSWORD = 'длинный-тестовый-пароль';

    private const ADMIN_PATH = '/seo/admin';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        run('DROP TABLE IF EXISTS ' . t('seo_meta'));
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        run('DELETE FROM ' . t('seo_meta'));
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(string $path, array $fields): array
    {
        $page = Kernel::get(self::ADMIN_PATH);

        return Kernel::post($path, array_merge(['_token' => $this->token($page['body'])], $fields));
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаЭкранеУходитНаВход(): void
    {
        $page = Kernel::get(self::ADMIN_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапрет(): void
    {
        // Редирект на форму потерял бы тело запроса, поэтому приватный POST отвечает сразу.
        $response = Kernel::post(self::ADMIN_PATH, ['op' => 'create', 'path' => '/']);

        self::assertSame(403, $response['status']);
    }

    public function testЭкранРазделаЗакрытВRobots(): void
    {
        $page = Kernel::get('/robots.txt');

        self::assertStringContainsString('Disallow: /seo/admin', $page['body']);
    }

    // ── Экран ─────────────────────────────────────────────

    public function testРедакторВидитСправочникМетаданных(): void
    {
        $this->login();
        $page = Kernel::get(self::ADMIN_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Метаданные', $page['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page['body']);
        self::assertSame('no-store', $page['headers']['cache-control'] ?? '', 'Помеченный экран обязан запрещать хранение');
    }

    public function testПустойСправочникОбещаетПервыйАдрес(): void
    {
        $this->login();
        $page = Kernel::get(self::ADMIN_PATH);

        self::assertStringContainsString('Метаданных пока нет', $page['body']);
    }

    // ── Создание ──────────────────────────────────────────

    public function testНовыйАдресСохраняетсяИМеняетСтраницу(): void
    {
        $this->login();

        $response = $this->press(self::ADMIN_PATH, [
            'op' => 'create',
            'path' => '/',
            'title' => 'Главная школы',
            'description' => 'Описание главной из раздела SEO.',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('/seo/admin?notice=meta_saved', $response['headers']['location']);

        $row = seoMetaRow('/');
        self::assertIsArray($row, 'Строка метаданных обязана сохраниться');
        self::assertSame('Главная школы', (string) $row['title']);

        $page = Kernel::get('/');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Главная школы</title>', $page['body'], 'Правка обязана дойти до собранной страницы');
        self::assertStringContainsString('Описание главной из раздела SEO.', $page['body']);
    }

    public function testСписокПоказываетСохранённыйАдрес(): void
    {
        $this->login();
        $this->press(self::ADMIN_PATH, ['op' => 'create', 'path' => '/news', 'title' => 'Лента новостей']);

        $page = Kernel::get(self::ADMIN_PATH);

        self::assertStringContainsString('/news', $page['body']);
        self::assertStringContainsString('Лента новостей', $page['body']);
    }

    public function testОшибкаПроверкиВозвращаетПричину(): void
    {
        $this->login();

        $response = $this->press(self::ADMIN_PATH, ['op' => 'create', 'path' => '']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);

        $page = Kernel::get(self::ADMIN_PATH);
        self::assertStringContainsString('Укажите адрес страницы', $page['body'], 'Причина отказа обязана быть видна человеку');
        self::assertNull(seoMetaRow('/'), 'Отклонённая правка не обязана попасть в базу');
    }

    public function testЧужойТокенОтвергается(): void
    {
        $this->login();

        $response = Kernel::post(self::ADMIN_PATH, [
            '_token' => str_repeat('a', 64),
            'op' => 'create',
            'path' => '/news',
        ]);

        self::assertSame(403, $response['status']);
        self::assertNull(seoMetaRow('/news'), 'Без своего токена правка не проходит');
    }

    // ── Правка и удаление ─────────────────────────────────

    public function testПравкаОткрываетФормуСтроки(): void
    {
        $this->login();
        $this->press(self::ADMIN_PATH, ['op' => 'create', 'path' => '/news', 'title' => 'Лента новостей']);

        $row = seoMetaRow('/news');
        self::assertIsArray($row);

        $page = Kernel::get(self::ADMIN_PATH . '/' . (string) $row['id']);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('value="Лента новостей"', $page['body'], 'Форма правки обязана показывать сохранённое значение');
    }

    public function testПравкаМеняетЗаголовокСтраницы(): void
    {
        $this->login();
        $this->press(self::ADMIN_PATH, ['op' => 'create', 'path' => '/news', 'title' => 'Лента новостей']);

        $row = seoMetaRow('/news');
        self::assertIsArray($row);

        $response = $this->press(self::ADMIN_PATH . '/' . (string) $row['id'], [
            'op' => 'save',
            'path' => '/news',
            'title' => 'Лента новостей — обновлено',
        ]);

        self::assertSame(302, $response['status']);
        self::assertSame('Лента новостей — обновлено', (string) (seoMetaRow('/news')['title'] ?? ''));
    }

    public function testУдалениеВозвращаетСтраницуКСвоемуЗаголовку(): void
    {
        $this->login();
        $this->press(self::ADMIN_PATH, ['op' => 'create', 'path' => '/', 'title' => 'Главная школы']);

        $row = seoMetaRow('/');
        self::assertIsArray($row);

        $response = $this->press(self::ADMIN_PATH . '/' . (string) $row['id'], ['op' => 'delete']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=meta_deleted', $response['headers']['location']);
        self::assertNull(seoMetaRow('/'), 'Удалённая строка не обязана оставаться в базе');

        $page = Kernel::get('/');
        self::assertStringNotContainsString('<title>Главная школы</title>', $page['body'], 'После удаления правок страница возвращается к своему заголовку');
    }

    public function testНесуществующаяЗаписьВозвращаетКСправочнику(): void
    {
        $this->login();

        $page = Kernel::get(self::ADMIN_PATH . '/net-takoy-stroki');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/seo/admin', $page['headers']['location']);
    }
}
