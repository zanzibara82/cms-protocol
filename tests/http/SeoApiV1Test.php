<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Машинное API раздела «SEO и статистика» (S6, спецификация §18) через настоящий HTTP: доступ
 * по ключу в заголовке `Authorization`, ответы в JSON без cookie ядра и отказы по границам
 * запроса.
 *
 * Набор держит обещание экрана «API-ключи»: полный ключ существует только у читателя, отозванный
 * ключ не вытаскивает отчёты, а машина без входа в админку получает ровно те же агрегаты, что и
 * панель, — и ничего личного, потому что личного в базе нет.
 */
final class SeoApiV1Test extends TestCase
{
    private const OWNER_EMAIL = 'seo-api-owner@school.dev';
    private const OWNER_PASSWORD = 'длинный-тестовый-пароль';

    /** @var array{ok: bool, error: string, key: string, id: string} */
    private array $key = ['ok' => false, 'error' => '', 'key' => '', 'id' => ''];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::OWNER_EMAIL, self::OWNER_PASSWORD, 'administrator');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        run('DELETE FROM ' . t('seo_api_key'));
        run('DROP TABLE IF EXISTS ' . t('seo_api_key'));

        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour', 'seo_goal', 'seo_conversion', 'seo_conversion_day'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour', 'seo_goal', 'seo_conversion', 'seo_conversion_day'] as $table) {
            run('DELETE FROM ' . t($table));
        }

        run('DELETE FROM ' . t('seo_api_key'));

        $created = seoApiKeyCreate('Дашборд наблюдателя');

        if ($created['ok'] !== true) {
            self::fail('Ключ API не создался: ' . $created['error']);
        }

        $this->key = $created;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function ask(string $path, array $headers = []): array
    {
        return Kernel::get($path, ['headers' => $headers]);
    }

    /**
     * Запрос со своим ключом: схема `Bearer` — единственная, которую раздел понимает.
     *
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function askWithKey(string $path): array
    {
        return $this->ask($path, ['Authorization' => 'Bearer ' . $this->key['key']]);
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     * @return array<string, mixed>
     */
    private function json(array $response): array
    {
        $decoded = json_decode($response['body'], true);

        self::assertIsArray($decoded, 'Машинный адрес обязан отвечать разберёмым JSON, а не страницей: ' . $response['body']);

        return $decoded;
    }

    private function seedVisit(string $path, string $visitor, string $referrer = ''): void
    {
        seoStatsStoreEvents([['p' => $path, 'r' => $referrer, 'u' => [], 'd' => 'desktop']], $visitor);
    }

    // ── Доступ по ключу ────────────────────────────────────

    public function testБезКлючаОтказ401ИJSON(): void
    {
        $response = $this->ask('/seo/api/v1/summary');

        self::assertSame(401, $response['status']);
        self::assertArrayHasKey('error', $this->json($response), 'Машина получает объяснение в JSON, а не перенаправление на вход');
        self::assertStringNotContainsString('<html', strtolower($response['body']), 'Машинный адрес не печатает страницу');
    }

    public function testЧужаяСхемаИлиЧужойКлючНеПускают(): void
    {
        $foreign = seoApiKeyGenerate();

        foreach (['Authorization' => 'Basic ' . $this->key['key'], 'Bearer ' . $foreign, 'bearer-token'] as $header) {
            self::assertSame(401, $this->ask('/seo/api/v1/summary', ['Authorization' => $header])['status'], 'Заголовок «' . $header . '» — не действующий ключ');
        }

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key') . ' WHERE last_used_at IS NOT NULL', []), 'Отказ не помечает ключ использованным');
    }

    public function testСДействующимКлючомОтдаётСводку(): void
    {
        $this->seedVisit('/news', 'visitor-api-1');
        $this->seedVisit('/about', 'visitor-api-2');

        $response = $this->askWithKey('/seo/api/v1/summary');

        self::assertSame(200, $response['status']);

        $payload = $this->json($response);

        self::assertSame('summary', $payload['report']);
        self::assertSame(7, $payload['days'], 'Без параметра период берётся машинным запасным');
        self::assertSame(2, (int) $payload['items']['views'], 'Сводка несёт те же просмотры, что и панель');
        self::assertSame(2, (int) $payload['items']['sessions']);
    }

    public function testВсеЧетыреРазрезаОтвечают(): void
    {
        $created = seoGoalCreate(['name' => 'Заявка', 'pattern' => '/contact', 'type' => SEO_GOAL_TYPE_PAGE_VIEW, 'match_type' => SEO_GOAL_MATCH_EXACT]);
        self::assertTrue($created['ok'], $created['error']);

        $this->seedVisit('/contact', 'visitor-api-3');
        $this->seedVisit('/news', 'visitor-api-4', 'https://vk.com/ok');

        foreach (['summary', 'pages', 'sources', 'goals'] as $report) {
            $response = $this->askWithKey('/seo/api/v1/' . $report);

            self::assertSame(200, $response['status'], 'Разрез «' . $report . '» обязан отвечать');

            $payload = $this->json($response);
            self::assertSame($report, $payload['report']);
            self::assertArrayHasKey('items', $payload, 'Разрез «' . $report . '» не принёс списка');
        }

        $pages = $this->json($this->askWithKey('/seo/api/v1/pages'))['items'];
        self::assertIsArray($pages);
        self::assertContains('/contact', array_column($pages, 'path'), 'Адрес из агрегата обязан доехать до машины');

        $goals = $this->json($this->askWithKey('/seo/api/v1/goals'))['items'];
        self::assertIsArray($goals);
        self::assertSame('Заявка', (string) $goals[0]['name']);
    }

    public function testНеизвестныйРазрезОтвечает404(): void
    {
        $response = $this->askWithKey('/seo/api/v1/tajnyj-razrez');

        self::assertSame(404, $response['status']);
        self::assertStringContainsString('Неизвестный отчёт', $response['body'], 'Белый список разрезов закрыт от выдуманных имён');
        self::assertSame(401, $this->ask('/seo/api/v1/tajnyj-razrez')['status'], 'Без ключа и выдуманное имя получает тот же отказ: перечень разрезов не утекает');
    }

    // ── Границы запроса ────────────────────────────────────

    public function testПериодВнеГраницОтвергается(): void
    {
        foreach (['days=0', 'days=366', 'days=abc', 'days=-7'] as $query) {
            $response = $this->askWithKey('/seo/api/v1/summary?' . $query);

            self::assertSame(400, $response['status'], 'Период «' . $query . '» вне своих границ — отказ');
            self::assertArrayHasKey('error', $this->json($response));
        }

        $ok = $this->askWithKey('/seo/api/v1/summary?days=1');
        self::assertSame(200, $ok['status']);
        self::assertSame(1, $this->json($ok)['days'], 'Граница периода включительно: один день — законный запрос');
    }

    public function testЧужойИсточникОтвергается(): void
    {
        $response = $this->askWithKey('/seo/api/v1/summary?source=' . rawurlencode('выдуманный'));

        self::assertSame(400, $response['status']);

        $known = $this->askWithKey('/seo/api/v1/summary?source=' . SEO_SOURCE_SOCIAL);
        self::assertSame(200, $known['status'], 'Известный источник — законный разрез');
    }

    // ── Отзыв ключа ────────────────────────────────────────

    public function testОтозванныйКлючБольшеНеОтвечает(): void
    {
        $this->seedVisit('/news', 'visitor-api-5');

        self::assertSame(200, $this->askWithKey('/seo/api/v1/summary')['status']);

        $revoked = seoApiKeyRevoke($this->key['id']);
        self::assertTrue($revoked['ok'], $revoked['error']);

        self::assertSame(401, $this->askWithKey('/seo/api/v1/summary')['status'], 'Отзыв обязан закрыть доступ немедленно');
    }

    public function testУспешныйЗапросОтмечаетИспользованиеКлюча(): void
    {
        self::assertNull(
            value('SELECT last_used_at FROM ' . t('seo_api_key') . ' WHERE id = ?', [$this->key['id']]),
            'Свежий ключ ещё не использовался'
        );

        self::assertSame(200, $this->askWithKey('/seo/api/v1/summary')['status']);

        self::assertNotNull(
            value('SELECT last_used_at FROM ' . t('seo_api_key') . ' WHERE id = ?', [$this->key['id']]),
            'Экран «API-ключи» показывает обращение по этому столбцу'
        );
    }

    // ── Ответ машины ───────────────────────────────────────

    public function testОтветНеСтавитCookieЯдра(): void
    {
        $response = $this->askWithKey('/seo/api/v1/summary');

        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('set-cookie', $response['headers'], 'Машинный адрес помечен sessionless: cookie ядра там не нужны');
    }

    public function testОтветЗапрещаетИндексированиеИХранение(): void
    {
        foreach ([[200, $this->askWithKey('/seo/api/v1/summary')], [401, $this->ask('/seo/api/v1/summary')], [404, $this->askWithKey('/seo/api/v1/net-takogo')]] as [$status, $response]) {
            self::assertSame($status, $response['status']);
            self::assertStringContainsString('noindex', strtolower($response['headers']['x-robots-tag'] ?? ''), 'Отчёты раздела не должны попадать в поиск — и на отказе тоже');
            self::assertSame('no-store', $response['headers']['cache-control'] ?? '', 'Числа отчёта не должны оставаться в кэше — и на отказе тоже');
        }
    }

    public function testТипОтветAPPLICATIONJson(): void
    {
        $response = $this->askWithKey('/seo/api/v1/pages');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('application/json', $response['headers']['content-type'] ?? '');
        self::assertStringContainsString('charset=utf-8', $response['headers']['content-type'] ?? '');
    }
}
