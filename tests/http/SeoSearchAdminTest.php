<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экраны поисковой аналитики раздела «SEO и статистика» через настоящий HTTP: право на экран
 * подключения, импорт данных, ответ источника и то, что секрет не показывается обратно.
 */
final class SeoSearchAdminTest extends TestCase
{
    private const ADMIN_EMAIL = 'seo-adminsearch@school.dev';
    private const ADMIN_PASSWORD = 'длинный-тестовый-пароль';
    private const EDITOR_EMAIL = 'seo-editorsearch@school.dev';
    private const EDITOR_PASSWORD = 'длинный-тестовый-пароль';

    private const SEARCH_PATH = '/seo/admin/search';
    private const SOURCE_PATH = '/seo/admin/search/source';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'admin');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        foreach (['seo_search_row', 'seo_search_connection'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        run('DELETE FROM ' . t('seo_search_row'));
        run('DELETE FROM ' . t('seo_search_connection'));

        foreach ([
            SEO_SEARCH_OPTION_PROVIDER,
            SEO_SEARCH_OPTION_SITE,
            SEO_SEARCH_OPTION_CLIENT_ID,
            SEO_SEARCH_OPTION_REDIRECT,
            SEO_SEARCH_OPTION_LAST_IMPORT,
        ] as $option) {
            deleteOption($option);
        }
    }

    private function loginAdmin(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
    }

    private function loginEditor(): void
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
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(string $path, array $fields, string $tokenFrom = self::SEARCH_PATH): array
    {
        $page = Kernel::get($tokenFrom);

        return Kernel::post($path, array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    // ── Право и доступ ─────────────────────────────────────

    public function testАнонимНаЭкранеПоискаУходитНаВход(): void
    {
        $page = Kernel::get(self::SEARCH_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Fsearch', $page['headers']['location']);
    }

    public function testЭкранПоискаВиденРедактору(): void
    {
        $this->loginEditor();

        $page = Kernel::get(self::SEARCH_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Источник не подключён', $page['body'], 'Без источника экран объясняет, почему он пуст');
    }

    public function testЭкранПодключенияЗакрытОтРедактора(): void
    {
        $this->loginEditor();

        $page = Kernel::get(self::SOURCE_PATH);

        self::assertSame(403, $page['status'], 'Клиентский секрет — дело администратора, а не редактора');
    }

    public function testЭкранПодключенияВиденАдминистратору(): void
    {
        $this->loginAdmin();

        $page = Kernel::get(self::SOURCE_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Клиентский секрет', $page['body']);
        self::assertStringNotContainsString('value="секрет-раз"', $page['body'], 'Секрет не показывается обратно даже в поле формы');
    }

    public function testЭкраныЗакрытыОтИндексации(): void
    {
        $this->loginAdmin();

        foreach ([self::SEARCH_PATH, self::SOURCE_PATH] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status']);
            self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page['body']);
            self::assertSame('no-store', $page['headers']['cache-control'] ?? '');
        }
    }

    // ── Подключение ────────────────────────────────────────

    public function testПодключениеИсточникаСохраняется(): void
    {
        $this->loginAdmin();

        $response = $this->press(self::SOURCE_PATH, [
            'op' => 'save',
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => 'секрет-раз',
            'redirect' => '',
        ], self::SOURCE_PATH);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=search_saved', $response['headers']['location']);

        $connection = one(
            'SELECT * FROM ' . t('seo_search_connection') . ' WHERE provider = ?',
            [SEO_SEARCH_PROVIDER_TEST]
        );

        self::assertNotNull($connection, 'Подключение обязано лежать в базе');
        self::assertSame('секрет-раз', (string) $connection['client_secret']);
    }

    public function testНеверныйАдресСайтаОтвергается(): void
    {
        $this->loginAdmin();

        $response = $this->press(self::SOURCE_PATH, [
            'op' => 'save',
            'provider' => SEO_SEARCH_PROVIDER_GOOGLE,
            'site' => 'не адрес',
            'client_id' => 'клиент',
            'client_secret' => 'секрет',
            'redirect' => '',
        ], self::SOURCE_PATH);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_connection')));
    }

    public function testОтключениеУдаляетТокены(): void
    {
        $this->loginAdmin();

        $this->press(self::SOURCE_PATH, [
            'op' => 'save',
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => 'секрет-раз',
            'redirect' => '',
        ], self::SOURCE_PATH);

        $response = $this->press(self::SOURCE_PATH, ['op' => 'disconnect'], self::SOURCE_PATH);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=search_disconnected', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_connection')));
    }

    // ── Импорт ─────────────────────────────────────────────

    public function testИмпортПишетСтрокиИПоказываетУведомление(): void
    {
        $this->loginAdmin();

        $this->press(self::SOURCE_PATH, [
            'op' => 'save',
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => '',
            'redirect' => '',
        ], self::SOURCE_PATH);

        $response = $this->press(self::SEARCH_PATH, [
            'op' => 'search_import',
            'from' => '2026-10-01',
            'to' => '2026-10-03',
            'dimension' => 'query',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=search_imported', $response['headers']['location']);
        self::assertSame(12, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_row')));
    }

    public function testИмпортБезТокенаОтвергается(): void
    {
        $this->loginAdmin();

        $response = Kernel::post(self::SEARCH_PATH, [
            'op' => 'search_import',
            'from' => '2026-10-01',
            'to' => '2026-10-03',
            'dimension' => 'query',
        ]);

        self::assertSame(403, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_row')));
    }

    public function testОтветИсточникаБезПодписиОтвергается(): void
    {
        $this->loginAdmin();

        $response = Kernel::get(self::SEARCH_PATH . '/callback?code=alien-code&state=broken-state');

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_connection')));
    }

    public function testДанныеИсточникаОтличаютсяОтСвоейАналитики(): void
    {
        $this->loginAdmin();

        $page = Kernel::get(self::SEARCH_PATH);

        self::assertStringContainsString('Это не наша аналитика', $page['body'], 'Данные поисковика и своя статистика не должны смешиваться');
    }
}
