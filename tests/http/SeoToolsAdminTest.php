<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экраны мониторинга, аудита и разметки раздела «SEO и статистика» через настоящий HTTP:
 * вход, формы, действия и ответ несуществующего адреса.
 */
final class SeoToolsAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'seo-instrumenty@school.dev';
    private const EDITOR_PASSWORD = 'длинный-тестовый-пароль';

    private const ERRORS_PATH = '/seo/admin/errors';
    private const AUDIT_PATH = '/seo/admin/audit';
    private const SCHEMA_PATH = '/seo/admin/schema';

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

        foreach (['seo_404', 'seo_redirect', 'seo_audit_issue', 'seo_schema'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach (['seo_404', 'seo_redirect', 'seo_audit_issue', 'seo_schema'] as $table) {
            run('DELETE FROM ' . t($table));
        }
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

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(string $path, array $fields): array
    {
        $page = Kernel::get(self::ERRORS_PATH);

        return Kernel::post($path, array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    // ── Право на экраны ────────────────────────────────────

    public function testАнонимНаЭкранеУходитНаВход(): void
    {
        $page = Kernel::get(self::ERRORS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Ferrors', $page['headers']['location']);
    }

    public function testЭкраныЗакрытыОтИндексации(): void
    {
        $this->login();

        foreach ([self::ERRORS_PATH, self::AUDIT_PATH, self::SCHEMA_PATH] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status']);
            self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page['body']);
            self::assertSame('no-store', $page['headers']['cache-control'] ?? '', 'Отчёт раздела обязан запрещать хранение');
        }
    }

    // ── Мониторинг ────────────────────────────────────────

    public function testПравилоПереходаСохраняется(): void
    {
        $this->login();

        $response = $this->press(self::ERRORS_PATH, [
            'op' => 'redirect_create',
            'from' => '/staryy-adres',
            'to' => '/novyy-adres',
            'code' => '301',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=redirect_saved', $response['headers']['location']);

        $row = seoMonitorRedirectByPath('/staryy-adres');
        self::assertIsArray($row, 'Правило обязано сохраниться');
        self::assertSame('/novyy-adres', (string) $row['to_path']);
    }

    public function testНесуществующийАдресОтвечаетПереходом(): void
    {
        $this->login();
        $this->press(self::ERRORS_PATH, [
            'op' => 'redirect_create',
            'from' => '/staryy-adres',
            'to' => '/novyy-adres',
            'code' => '301',
        ]);

        $page = Kernel::get('/staryy-adres');

        self::assertSame(301, $page['status'], 'Адрес с правилом отвечает переходом, а не страницей «страницы нет»');
        self::assertSame('/novyy-adres', $page['headers']['location'] ?? '');
    }

    public function testОшибкаПроверкиВозвращаетПричину(): void
    {
        $this->login();

        $response = $this->press(self::ERRORS_PATH, [
            'op' => 'redirect_create',
            'from' => '/odin',
            'to' => '/odin',
            'code' => '301',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);

        $page = Kernel::get(self::ERRORS_PATH);
        self::assertStringContainsString('Редирект не может вести сам на себя', $page['body'], 'Причина отказа обязана быть видна человеку');
    }

    public function testЖурналОчищается(): void
    {
        $this->login();
        $this->press(self::ERRORS_PATH, [
            'op' => 'redirect_create',
            'from' => '/staryy-adres',
            'to' => '/novyy-adres',
            'code' => '301',
        ]);

        $response = $this->press(self::ERRORS_PATH, ['op' => 'errors_clear']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=errors_cleared', $response['headers']['location']);
    }

    public function testЧужойТокенОтвергается(): void
    {
        $this->login();

        $response = Kernel::post(self::ERRORS_PATH, [
            '_token' => str_repeat('a', 64),
            'op' => 'redirect_create',
            'from' => '/a',
            'to' => '/b',
            'code' => '301',
        ]);

        self::assertSame(403, $response['status']);
        self::assertNull(seoMonitorRedirectByPath('/a'), 'Без своего токена правило не заводится');
    }

    // ── Аудит ─────────────────────────────────────────────

    public function testАудитЗапускаетсяИСчитаетЗамечания(): void
    {
        $this->login();

        $page = Kernel::get(self::AUDIT_PATH);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Аудит', $page['body']);

        $response = $this->press(self::AUDIT_PATH, ['op' => 'audit_run']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=audit_done', $response['headers']['location']);
    }

    public function testАудитНеМеняетСтраницуСамуПоСебе(): void
    {
        $this->login();
        $this->press(self::AUDIT_PATH, ['op' => 'audit_run']);

        $page = Kernel::get('/');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('name="robots" content="noindex', $page['body'], 'Проверка не имеет права закрывать живую страницу');
    }

    // ── Разметка ───────────────────────────────────────────

    public function testРазметкаСохраняется(): void
    {
        $this->login();

        $response = $this->press(self::SCHEMA_PATH, [
            'op' => 'create',
            'path' => '/',
            'schema_type' => 'Organization',
            'name' => 'Школа английского языка',
            'description' => 'Школа, где учат английскому.',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=schema_saved', $response['headers']['location']);

        $row = seoSchemaByPath('/');
        self::assertIsArray($row);
        self::assertSame('Organization', (string) $row['schema_type']);
    }

    public function testРазметкаПечатаетсяВСвоейСтранице(): void
    {
        $this->login();
        $this->press(self::SCHEMA_PATH, [
            'op' => 'create',
            'path' => '/',
            'schema_type' => 'Organization',
            'name' => 'Школа английского языка',
            'description' => 'Школа, где учат английскому.',
        ]);

        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('application/ld+json', $page['body'], 'Разметка обязана попасть в страницу, для которой она заведена');
        self::assertStringContainsString('Школа английского языка', $page['body']);
    }

    public function testРазметкаНеПечатаетсяНаЧужойСтранице(): void
    {
        $this->login();
        $this->press(self::SCHEMA_PATH, [
            'op' => 'create',
            'path' => '/',
            'schema_type' => 'Organization',
            'name' => 'Школа английского языка',
        ]);

        $page = Kernel::get(self::ERRORS_PATH);

        self::assertStringNotContainsString('application/ld+json', $page['body'], 'Чужая страница не обязана печатать чужую разметку');
    }

    public function testОшибкаРазметкиВозвращаетПричину(): void
    {
        $this->login();

        $response = $this->press(self::SCHEMA_PATH, [
            'op' => 'create',
            'path' => '/',
            'schema_type' => 'Product',
            'name' => 'Велосипед',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);

        $page = Kernel::get(self::SCHEMA_PATH);
        self::assertStringContainsString('Выберите тип объекта из списка', $page['body']);
    }

    public function testНесуществующаяРазметкаВозвращаетКСправочнику(): void
    {
        $this->login();

        $page = Kernel::get(self::SCHEMA_PATH . '/net-takoy-stroki');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/seo/admin/schema', $page['headers']['location']);
    }
}
