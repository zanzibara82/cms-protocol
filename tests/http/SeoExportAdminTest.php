<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экспорт отчётов раздела «SEO и статистика» через настоящий HTTP (S6, спецификация §7):
 * экран выбора, предпросмотр и два скачивания — CSV и XLSX.
 *
 * Набор держит обещание экрана: человек видит ровно то, что уедет в файл; файл открывается
 * двойным щелчком (BOM, «;», CRLF); неверные аргументы не отдают файл вслепую, а возвращают
 * на экран; ни экран, ни файл не попадают в поиск и в кэш.
 */
final class SeoExportAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'seo-export-redaktor@school.dev';
    private const EDITOR_PASSWORD = 'длинный-тестовый-пароль';

    private const SCREEN_PATH = '/seo/admin/export';
    private const DOWNLOAD_PATH = '/seo/admin/export/download';

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

        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour'] as $table) {
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
     * @param array<string, string> $query
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function download(array $query): array
    {
        return Kernel::get(self::DOWNLOAD_PATH . '?' . http_build_query($query));
    }

    // ── Право на экран и файл ──────────────────────────────

    public function testАнонимНаЭкранеЭкспортаУходитНаВход(): void
    {
        $page = Kernel::get(self::SCREEN_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Fexport', $page['headers']['location']);
    }

    public function testАнонимНеСкачиваетФайл(): void
    {
        $file = $this->download(['kind' => 'pages', 'days' => '30']);

        self::assertSame(302, $file['status'], 'Файл отдаётся только вошедшему');
        self::assertStringContainsString('/login', $file['headers']['location']);
    }

    // ── Экран ──────────────────────────────────────────────

    public function testРедакторВидитЭкранСДвумяФорматами(): void
    {
        $this->login();
        $page = Kernel::get(self::SCREEN_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Экспорт отчётов', $page['body']);
        self::assertStringContainsString('Скачать CSV', $page['body']);
        self::assertStringContainsString('Скачать XLSX', $page['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page['body']);
        self::assertSame('no-store', $page['headers']['cache-control'] ?? '', 'Экран экспорта запрещает хранение');
    }

    public function testПредпросмотрПоказываетТуЖеТаблицу(): void
    {
        seoStatsStoreEvents([['p' => '/news', 'r' => '', 'u' => [], 'd' => 'desktop']], 'visitor-export');

        $this->login();
        $page = Kernel::get(self::SCREEN_PATH . '?kind=pages&days=30');

        self::assertStringContainsString('Отчёт: Популярные страницы', $page['body']);
        self::assertStringContainsString('/news', $page['body'], 'Предпросмотр читает тот же отчёт, что и файл');
        self::assertStringContainsString('Адрес', $page['body'], 'Заголовки колонок видны до скачивания');
    }

    public function testВыбранныеФильтрыПомечаются(): void
    {
        $this->login();
        $page = Kernel::get(self::SCREEN_PATH . '?kind=campaigns&days=90&source=organic');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('value="campaigns" selected', $page['body']);
        self::assertStringContainsString('value="90" selected', $page['body']);
        self::assertStringContainsString('value="organic" selected', $page['body']);
    }

    public function testОтчётБезИсточникаПрячетВыбор(): void
    {
        $this->login();
        $page = Kernel::get(self::SCREEN_PATH . '?kind=goals&source=organic');

        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('name="source"', $page['body'], 'У целей нет источника: выбор, который ничего не фильтрует, не показывается');
        self::assertStringContainsString('не делится по источнику', $page['body']);
    }

    public function testНеизвестныйВидБерётПервыйПоСписку(): void
    {
        $this->login();
        $page = Kernel::get(self::SCREEN_PATH . '?kind=tajnyj');

        self::assertSame(200, $page['status'], 'Экран не сваливается на выдуманном виде отчёта');
        self::assertStringContainsString('value="daily" selected', $page['body'], 'Выдуманный вид снимается первым по списку');
    }

    public function testЧужойПериодБерётсяЗапасным(): void
    {
        $this->login();
        $page = Kernel::get(self::SCREEN_PATH . '?days=5');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('value="' . SEO_STATS_DEFAULT_PERIOD . '" selected', $page['body']);
    }

    // ── Скачивание ─────────────────────────────────────────

    public function testCsvОтдаётсяВложениемСТаблицей(): void
    {
        seoStatsStoreEvents([['p' => '/news', 'r' => '', 'u' => [], 'd' => 'desktop']], 'visitor-csv');

        $this->login();
        $file = $this->download(['kind' => 'pages', 'days' => '30', 'format' => 'csv']);

        self::assertSame(200, $file['status']);
        self::assertStringContainsString('text/csv; charset=utf-8', $file['headers']['content-type'] ?? '');
        self::assertStringContainsString('attachment; filename="seo-pages-', $file['headers']['content-disposition'] ?? '');
        self::assertStringContainsString('.csv"', $file['headers']['content-disposition'] ?? '');

        self::assertSame("\xEF\xBB\xBF", substr($file['body'], 0, 3), 'BOM держит кириллицу при двойном щелчке');
        self::assertStringContainsString('"Адрес";"Просмотры";"Входы";"Выходы"' . "\r\n", $file['body'], 'Заголовки идут первой строкой, разделитель — точка с запятой');
        self::assertStringContainsString('"/news"', $file['body'], 'Строка отчёта доехала до файла');
    }

    public function testXlsxОтдаётсяКнигой(): void
    {
        seoStatsStoreEvents([['p' => '/news', 'r' => '', 'u' => [], 'd' => 'desktop']], 'visitor-xlsx');

        $this->login();
        $file = $this->download(['kind' => 'pages', 'days' => '30', 'format' => 'xlsx']);

        self::assertSame(200, $file['status']);
        self::assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $file['headers']['content-type'] ?? ''
        );
        self::assertStringContainsString('attachment; filename="seo-pages-', $file['headers']['content-disposition'] ?? '');
        self::assertStringContainsString('.xlsx"', $file['headers']['content-disposition'] ?? '');

        self::assertSame("PK\x03\x04", substr($file['body'], 0, 4), 'Книга — ZIP-контейнер');
        self::assertStringContainsString('xl/worksheets/sheet1.xml', $file['body'], 'В контейнере есть лист книги');
    }

    public function testФайлВидитСегодняшниеСобытия(): void
    {
        seoStatsStoreEvents([['p' => '/news', 'r' => '', 'u' => [], 'd' => 'desktop']], 'visitor-today');

        $this->login();
        $file = $this->download(['kind' => 'daily', 'days' => '7']);

        self::assertStringContainsString(moscowDayKey(nowUtc()), $file['body'], 'Период отчёта смотрит назад и включает сегодня');
    }

    public function testСкачиваниеЗапрещаетИндексированиеИКэш(): void
    {
        $this->login();

        foreach (['csv', 'xlsx'] as $format) {
            $file = $this->download(['kind' => 'sources', 'days' => '30', 'format' => $format]);

            self::assertSame(200, $file['status']);
            self::assertStringContainsString('noindex', strtolower($file['headers']['x-robots-tag'] ?? ''));
            self::assertSame('no-store', $file['headers']['cache-control'] ?? '');
        }
    }

    public function testЧужиеАргументыВозвращаютНаЭкран(): void
    {
        $this->login();

        foreach ([['kind' => 'tajnyj', 'days' => '30'], ['kind' => 'pages', 'days' => '5'], ['kind' => 'pages', 'days' => 'abc']] as $query) {
            $file = $this->download($query);

            self::assertSame(302, $file['status'], 'Файл с чужим периодом или видом не отдаётся: ' . http_build_query($query));
            self::assertStringContainsString(self::SCREEN_PATH, $file['headers']['location']);
        }
    }

    public function testСкачиваниеЦелейСИсточникомВозвращаетНаЭкран(): void
    {
        $this->login();
        $file = $this->download(['kind' => 'goals', 'days' => '30', 'source' => SEO_SOURCE_SOCIAL]);

        self::assertSame(302, $file['status'], 'Общий список целей под подписью фильтра был бы ложью');
        self::assertStringContainsString(self::SCREEN_PATH, $file['headers']['location']);
    }
}
