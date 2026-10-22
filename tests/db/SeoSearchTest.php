<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Поисковая аналитика раздела «SEO и статистика» (S4): подключение источника, подпись
 * состояния входа, обмен кода на токен, чтение строк, импорт с его лимитами и отчёт.
 *
 * Набор проверяет обещания раздела: данные поисковика не смешиваются со своей аналитикой,
 * выборка неполна и не достраивается, лимиты стоят до обращения к источнику, а секреты не
 * показываются обратно.
 */
final class SeoSearchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['seo_search_row', 'seo_search_connection'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
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

        hooksReset();
    }

    protected function tearDown(): void
    {
        hooksReset();
    }

    /**
     * Подключение тестового источника: он ничего не выдаёт и никуда не ходит.
     */
    private static function connectTestSource(string $site = 'https://example.com/'): void
    {
        seoSearchSaveSettings([
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => $site,
            'client_id' => '',
            'client_secret' => '',
            'redirect' => '',
        ]);

        seoSearchSaveConnection([
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => $site,
            'client_id' => '',
            'client_secret' => '',
        ]);
    }

    /**
     * Подключение Google: настройки, строка подключения и выданный токен со сроком впереди.
     */
    private static function connectGoogleSource(): void
    {
        seoSearchSaveSettings([
            'provider' => SEO_SEARCH_PROVIDER_GOOGLE,
            'site' => 'https://example.com/',
            'client_id' => 'клиент',
            'client_secret' => 'секрет',
            'redirect' => 'https://example.com/seo/admin/search/callback',
        ]);

        seoSearchSaveConnection([
            'provider' => SEO_SEARCH_PROVIDER_GOOGLE,
            'site' => 'https://example.com/',
            'client_id' => 'клиент',
            'client_secret' => 'секрет',
        ]);

        run(
            'UPDATE ' . t('seo_search_connection')
            . ' SET access_token = ?, refresh_token = ?, expires_at = ?',
            ['токен', 'обновляющий-токен', '2099-01-01 00:00:00']
        );
    }

    // ── Состояние ──────────────────────────────────────────

    public function testБезНастроекИсточникаНет(): void
    {
        self::assertSame('none', seoSearchState(), 'Без настроек источника данных нет, и выдумывать их нельзя');
        self::assertNull(seoSearchConnection());
    }

    public function testНастроенныйНоНеПодключённыйИсточникВиден(): void
    {
        seoSearchSaveSettings([
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => '',
            'redirect' => '',
        ]);

        self::assertSame('configured', seoSearchState());
    }

    public function testСекретНеПоказываетсяОбратно(): void
    {
        self::connectTestSource();
        seoSearchSaveConnection([
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => 'секрет-раз',
        ]);

        $connection = seoSearchConnection();

        self::assertNotNull($connection);
        self::assertSame('секрет-раз', (string) $connection['client_secret']);
    }

    public function testПустойСекретОставляетПрежний(): void
    {
        seoSearchSaveSettings([
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => '',
            'redirect' => '',
        ]);
        seoSearchSaveConnection([
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => 'первый-секрет',
        ]);
        seoSearchSaveConnection([
            'provider' => SEO_SEARCH_PROVIDER_TEST,
            'site' => 'https://example.com/',
            'client_id' => 'другой-клиент',
            'client_secret' => '',
        ]);

        $connection = seoSearchConnection();

        self::assertNotNull($connection);
        self::assertSame('первый-секрет', (string) $connection['client_secret'], 'Пустой секрет означает «оставить прежний»');
        self::assertSame('другой-клиент', (string) $connection['client_id']);
    }

    // ── Подпись состояния ──────────────────────────────────

    public function testПодписьСостоянияПроверяется(): void
    {
        self::connectTestSource();

        $state = seoSearchStateToken();

        self::assertTrue(seoSearchStateValid($state), 'Свежая подпись обязана проходить');
    }

    public function testПодделаннаяПодписьНеПроходит(): void
    {
        self::connectTestSource();

        $state = seoSearchStateToken();
        $parts = explode('.', $state);
        $parts[1] = strrev((string) $parts[1]);

        self::assertFalse(seoSearchStateValid(implode('.', $parts)), 'Подпись без ключа раздела не должна проходить');
        self::assertFalse(seoSearchStateValid('мусор'), 'Мусор в подписи — тоже отказ');
        self::assertFalse(seoSearchStateValid(''));
    }

    public function testПросроченнаяПодписьНеПроходит(): void
    {
        self::connectTestSource();

        $payload = implode('|', [SEO_SEARCH_PROVIDER_TEST, 'https://example.com/', (string) (time() - 4000), 'abcdef']);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $state = $encoded . '.' . hash_hmac('sha256', $encoded, seoSearchSecret());

        self::assertFalse(seoSearchStateValid($state), 'Подпись старше четверти часа — уже не подпись');
    }

    public function testЧужаяПодписьОтДругогоИсточникаНеПроходит(): void
    {
        self::connectTestSource();

        $payload = implode('|', [SEO_SEARCH_PROVIDER_GOOGLE, 'https://example.com/', (string) time(), 'abcdef']);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $state = $encoded . '.' . hash_hmac('sha256', $encoded, seoSearchSecret());

        self::assertFalse(seoSearchStateValid($state), 'Подпись от другого источника — отказ');
    }

    // ── Импорт ─────────────────────────────────────────────

    public function testТестовыйИсточникИмпортируетДанные(): void
    {
        self::connectTestSource();

        $result = seoSearchImport('2026-10-01', '2026-10-03', 'query');

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertSame(12, $result['rows'], 'За три дня и четыре запроса получается двенадцать строк');
        self::assertSame(12, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_row')));
    }

    public function testОтчётСчитаетCtrКликамиКПоказам(): void
    {
        self::connectTestSource();
        seoSearchImport('2026-10-01', '2026-10-03', 'query');

        $summary = seoSearchSummary('2026-10-01', '2026-10-03', 'query');

        self::assertNotEmpty($summary, 'Импортированные запросы обязаны попасть в отчёт');

        foreach ($summary as $row) {
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $expected = $impressions > 0 ? $clicks / $impressions : 0.0;

            self::assertEqualsWithDelta(
                $expected,
                (float) $row['ctr'],
                0.0001,
                'CTR строки — клики к показам этой же строки, а не среднее по CTR-ам'
            );
        }
    }

    public function testПериодДлиннееПотолкаОтвергается(): void
    {
        self::connectTestSource();

        $result = seoSearchImport('2026-01-01', '2026-12-31', 'query');

        self::assertFalse($result['ok']);
        self::assertStringContainsString((string) SEO_SEARCH_RANGE_MAX_DAYS, (string) $result['error']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_row')), 'Отвергнутый импорт не пишет строк');
    }

    public function testМеждуИмпортамиНужнаПауза(): void
    {
        self::connectTestSource();

        $first = seoSearchImport('2026-10-01', '2026-10-03', 'query');
        self::assertTrue($first['ok'], (string) $first['error']);

        $second = seoSearchImport('2026-10-01', '2026-10-03', 'query');

        self::assertFalse($second['ok'], 'Второй импорт сразу после первого — расход квоты, а не «ещё немного данных»');
        self::assertStringContainsString((string) SEO_SEARCH_IMPORT_INTERVAL, (string) $second['error']);
    }

    public function testПовторныйИмпортОбновляетСтроки(): void
    {
        self::connectTestSource();
        seoSearchImport('2026-10-01', '2026-10-03', 'query');

        deleteOption(SEO_SEARCH_OPTION_LAST_IMPORT);

        $again = seoSearchImport('2026-10-01', '2026-10-03', 'query');

        self::assertTrue($again['ok'], (string) $again['error']);
        self::assertSame(12, (int) value('SELECT COUNT(*) FROM ' . t('seo_search_row')), 'Повторный импорт обновляет строки, а не плодит их');
    }

    public function testДанныеДругогоИсточникаНеСмешиваются(): void
    {
        self::connectTestSource();
        seoSearchImport('2026-10-01', '2026-10-03', 'query');

        seoSearchSaveSettings([
            'provider' => SEO_SEARCH_PROVIDER_GOOGLE,
            'site' => 'https://example.com/',
            'client_id' => 'клиент',
            'client_secret' => 'секрет',
            'redirect' => '',
        ]);

        self::assertSame([], seoSearchSummary('2026-10-01', '2026-10-03', 'query'), 'Свои строки не должны попадать в чужой отчёт');
    }

    public function testИзмерениеМеняетНаборКолонок(): void
    {
        self::connectTestSource();

        seoSearchImport('2026-10-01', '2026-10-01', 'country');

        $row = one('SELECT * FROM ' . t('seo_search_row') . ' LIMIT 1');

        self::assertNotNull($row);
        self::assertSame('', (string) $row['query']);
        self::assertNotSame('', (string) $row['country']);
    }

    // ── Чтение из источника ─────────────────────────────────

    public function testGoogleАдаптерЧитаетСтрокиЧерезФильтр(): void
    {
        self::connectGoogleSource();

        $seen = [];

        addFilter('seo_search_http', static function (mixed $value, string $method, string $url, array $options) use (&$seen): array {
            $seen[] = [$method, $url, $options];

            return [
                'status' => 200,
                'body' => (string) json_encode([
                    'rows' => [
                        ['keys' => ['кухня', 'https://example.com/kitchen'], 'clicks' => 7, 'impressions' => 100, 'ctr' => 0.07, 'position' => 12.5],
                    ],
                ]),
                'error' => '',
            ];
        });

        $connection = seoSearchConnection();
        self::assertNotNull($connection);

        $fetched = seoSearchFetchRows($connection, '2026-10-01', '2026-10-02', 'query');

        self::assertTrue($fetched['ok'], (string) $fetched['error']);
        self::assertCount(1, $fetched['rows']);
        self::assertSame('кухня', (string) $fetched['rows'][0]['query']);
        self::assertSame(7, (int) $fetched['rows'][0]['clicks']);
        self::assertNotEmpty($seen, 'Запрос к источнику обязан уйти через транспорт');
        self::assertStringContainsString('searchconsole.googleapis.com', (string) $seen[0][1]);
    }

    public function testОтказИсточникаНеВыдаётсяЗаДанные(): void
    {
        self::connectGoogleSource();

        addFilter('seo_search_http', static fn (mixed $value, string $method, string $url, array $options): array => [
            'status' => 403,
            'body' => '',
            'error' => '',
        ]);

        $connection = seoSearchConnection();
        self::assertNotNull($connection);

        $fetched = seoSearchFetchRows($connection, '2026-10-01', '2026-10-02', 'query');

        self::assertFalse($fetched['ok'], 'Отказ источника — это отказ, а не пустой отчёт');
        self::assertSame([], $fetched['rows']);
    }

    public function testПросроченныйТокенОбновляетсяИЗапросПовторяется(): void
    {
        self::connectGoogleSource();

        run(
            'UPDATE ' . t('seo_search_connection')
            . ' SET access_token = ?, refresh_token = ?, expires_at = ?',
            ['старый-токен', 'обновляющий-токен', '2026-01-01 00:00:00']
        );

        $calls = 0;

        addFilter('seo_search_http', static function (mixed $value, string $method, string $url, array $options) use (&$calls): array {
            $calls++;

            if (str_contains($url, 'oauth2.googleapis.com')) {
                return [
                    'status' => 200,
                    'body' => (string) json_encode(['access_token' => 'новый-токен', 'expires_in' => 3600]),
                    'error' => '',
                ];
            }

            return [
                'status' => 200,
                'body' => (string) json_encode(['rows' => []]),
                'error' => '',
            ];
        });

        $connection = seoSearchConnection();
        self::assertNotNull($connection);

        $fetched = seoSearchFetchRows($connection, '2026-10-01', '2026-10-02', 'query');

        self::assertTrue($fetched['ok'], (string) $fetched['error']);
        self::assertSame(2, $calls, 'Сначала обновление токена, затем сам запрос');

        $fresh = seoSearchConnection();
        self::assertNotNull($fresh);
        self::assertSame('новый-токен', (string) $fresh['access_token']);
    }

    public function testАдресВходаСобранПоПравиламИсточника(): void
    {
        self::connectGoogleSource();

        $url = seoSearchAuthorizeUrl();

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        self::assertStringContainsString('client_id=' . rawurlencode('клиент'), $url);
        self::assertStringContainsString('scope=' . rawurlencode(SEO_SEARCH_SCOPE), $url);
        self::assertStringContainsString('access_type=offline', $url);
        self::assertStringContainsString('response_type=code', $url);

        // Подпись состояния едет вместе со ссылкой и должна проходить проверку: иначе возврат
        // из источника отвергли бы как чужой.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');

        self::assertNotSame('', $state, 'Подпись состояния обязана ехать вместе со ссылкой');
        self::assertTrue(seoSearchStateValid($state));
    }

    public function testБезКлиентаВходаНет(): void
    {
        seoSearchSaveSettings([
            'provider' => SEO_SEARCH_PROVIDER_GOOGLE,
            'site' => 'https://example.com/',
            'client_id' => '',
            'client_secret' => 'секрет',
            'redirect' => '',
        ]);

        self::assertSame('', seoSearchAuthorizeUrl(), 'Без идентификатора клиента ссылка для входа бессмысленна');
    }

    public function testКодМеняетсяНаТокен(): void
    {
        self::connectGoogleSource();

        addFilter('seo_search_http', static fn (mixed $value, string $method, string $url, array $options): array => [
            'status' => 200,
            'body' => (string) json_encode([
                'access_token' => 'выданный-токен',
                'refresh_token' => 'выданный-refresh',
                'expires_in' => 3600,
                'scope' => SEO_SEARCH_SCOPE,
            ]),
            'error' => '',
        ]);

        $exchanged = seoSearchExchange('код-источника');

        self::assertTrue($exchanged['ok'], (string) $exchanged['error']);
        self::assertSame('выданный-токен', (string) $exchanged['tokens']['access_token']);

        seoSearchStoreTokens($exchanged['tokens']);

        $connection = seoSearchConnection();

        self::assertNotNull($connection);
        self::assertSame('выданный-токен', (string) $connection['access_token']);
        self::assertSame('выданный-refresh', (string) $connection['refresh_token']);
        self::assertSame(SEO_SEARCH_SCOPE, (string) $connection['scope']);
        self::assertNotNull(fromDb((string) $connection['expires_at']), 'Срок действия токена обязан быть датой, а не пустотой');
    }

    public function testОтказИсточникаПриОбменеНеВыдаётсяЗаТокен(): void
    {
        self::connectGoogleSource();

        addFilter('seo_search_http', static fn (mixed $value, string $method, string $url, array $options): array => [
            'status' => 400,
            'body' => '',
            'error' => '',
        ]);

        $exchanged = seoSearchExchange('неверный-код');

        self::assertFalse($exchanged['ok']);
        self::assertSame([], $exchanged['tokens']);

        $connection = seoSearchConnection();

        self::assertNotNull($connection);
        self::assertSame('токен', (string) $connection['access_token'], 'Отказ источника не должен трогать уже выданный токен');
    }

    public function testТранспортПускаетТолькоHttps(): void
    {
        $response = seoSearchHttp('POST', 'http://example.com/', ['body' => 'x']);

        self::assertSame(0, $response['status']);
        self::assertStringContainsString('https', (string) $response['error']);
    }
}
