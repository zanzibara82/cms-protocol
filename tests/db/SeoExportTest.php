<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экспорт отчётов (S6): состав отчёта, формат CSV и состав книги XLSX.
 *
 * Отчёт собирается той же функцией, что и предпросмотр экрана, поэтому набор проверяет
 * обещание «в файле ровно то, что на экране»: числа берутся из агрегатов, период и источник
 * вне своих границ дают отказ, а не молчаливую подмену.
 *
 * Книга XLSX собирается своим писателем без расширения zip (чек-лист хостинга его не
 * обещает), поэтому контейнер разбирается здесь вручную: подпись локальной записи, метод
 * хранения и конец центрального каталога. Так проверка ловит и «файл, который Excel не
 * откроет», а не только «строка не пустая».
 */
final class SeoExportTest extends TestCase
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
        foreach ([
            'seo_visit',
            'seo_session',
            'seo_stat_day',
            'seo_stat_hour',
            'seo_conversion',
            'seo_conversion_day',
            'seo_goal',
        ] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour', 'seo_conversion', 'seo_conversion_day', 'seo_goal'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    /**
     * Событие в виде, в котором его присылает счётчик.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function event(array $overrides = []): array
    {
        return array_merge([
            'p' => '/news',
            'r' => '',
            'u' => [],
            'd' => 'desktop',
        ], $overrides);
    }

    // ── Состав отчёта ──────────────────────────────────────

    public function testВидыОтчётовНазваныИСобираются(): void
    {
        $kinds = seoReportKinds('export');

        self::assertNotSame([], $kinds, 'Без видов отчёта экран экспорта нечего предлагать');

        seoStatsStoreEvents([self::event()], 'visitor-0001');

        foreach ($kinds as $kind => $title) {
            $report = seoExportReport((string) $kind, SEO_STATS_DEFAULT_PERIOD, '');

            self::assertIsArray($report, 'Вид отчёта ' . $kind . ' не собрался');
            self::assertNotSame([], $report['columns'], 'Отчёт ' . $kind . ' без колонок — пустая таблица в Excel');
            self::assertSame($title, $report['title']);

            foreach ($report['numeric'] as $index) {
                self::assertArrayHasKey($index, $report['columns'], 'Числовой номер колонки отчёта ' . $kind . ' обязан указывать на существующую колонку');
            }
        }
    }

    public function testРазрезТолькоМашинногоАпиНеСкачивается(): void
    {
        $apiOnly = array_diff_key(seoReportKinds('api'), seoReportKinds('export'));

        self::assertNotSame([], $apiOnly, 'Реестр обязан различать выходы, иначе проверка ничего не ловит');

        foreach (array_keys($apiOnly) as $kind) {
            self::assertNull(
                seoExportReport((string) $kind, SEO_STATS_DEFAULT_PERIOD, ''),
                'Разрез «' . $kind . '» живёт только в машинном ответе и в файл не уезжает'
            );
        }
    }

    public function testНеизвестныйВидИПериодВнеГраницДаютОтказ(): void
    {
        self::assertNull(seoExportReport('нет-такого-отчёта', SEO_STATS_DEFAULT_PERIOD, ''));
        self::assertNull(seoExportReport('daily', 5000, ''), 'Период вне списка — отказ, а не молчаливая подмена');
        self::assertNull(seoExportReport('daily', SEO_STATS_DEFAULT_PERIOD, 'нет-такого-источника'));
    }

    public function testОтчётПоДнямСходитсяСАгрегатами(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news']), self::event(['p' => '/about'])], 'visitor-0001');

        $report = seoExportReport('daily', SEO_STATS_DEFAULT_PERIOD, '');

        self::assertIsArray($report);
        self::assertSame(['День', 'Просмотры', 'Сессии'], $report['columns']);

        $today = seoStatsDayKey(nowUtc());
        $views = 0;

        foreach ($report['rows'] as $row) {
            if ($row[0] === $today) {
                $views = (int) $row[1];
            }
        }

        self::assertSame(2, $views, 'В файл идут те же просмотры, что показывает экран');
    }

    public function testОтчётПоСтраницамНесётАдресаИЧисла(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news'])], 'visitor-0001');

        $report = seoExportReport('pages', SEO_STATS_DEFAULT_PERIOD, '');

        self::assertIsArray($report);
        self::assertSame(['Адрес', 'Просмотры', 'Входы', 'Выходы'], $report['columns']);
        self::assertSame('/news', $report['rows'][0][0] ?? '');
    }

    public function testФильтрПоИсточникуУрезаетОтчёт(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news'])], 'visitor-0001');
        seoStatsStoreEvents([self::event(['p' => '/about', 'r' => 'https://vk.com/'])], 'visitor-0002');

        $all = seoExportReport('pages', SEO_STATS_DEFAULT_PERIOD, '');
        $social = seoExportReport('pages', SEO_STATS_DEFAULT_PERIOD, SEO_SOURCE_SOCIAL);

        self::assertIsArray($all);
        self::assertIsArray($social);
        self::assertCount(2, $all['rows']);
        self::assertCount(1, $social['rows'], 'Разрез по источнику обязан урезать и файл, а не только экран');
        self::assertSame('/about', $social['rows'][0][0]);
    }

    public function testОтчётИсточниковПринимаетИсточник(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news'])], 'visitor-0001');
        seoStatsStoreEvents([self::event(['p' => '/about', 'r' => 'https://vk.com/'])], 'visitor-0002');

        $all = seoExportReport('sources', SEO_STATS_DEFAULT_PERIOD, '');
        $social = seoExportReport('sources', SEO_STATS_DEFAULT_PERIOD, SEO_SOURCE_SOCIAL);

        self::assertIsArray($all);
        self::assertIsArray($social);
        self::assertCount(2, $all['rows']);
        self::assertCount(1, $social['rows'], 'Фильтр по источнику урезает и сам отчёт источников');
        self::assertSame('Соцсети', $social['rows'][0][0]);
    }

    public function testОтчётКампанийПринимаетИсточник(): void
    {
        seoStatsStoreEvents(
            [self::event(['p' => '/news', 'u' => ['s' => 'vk', 'm' => 'social', 'c' => 'promo']])],
            'visitor-0001'
        );
        seoStatsStoreEvents(
            [self::event(['p' => '/news', 'u' => ['s' => 'yandex', 'm' => 'cpc', 'c' => 'search']])],
            'visitor-0002'
        );

        $all = seoExportReport('campaigns', SEO_STATS_DEFAULT_PERIOD, '');
        $social = seoExportReport('campaigns', SEO_STATS_DEFAULT_PERIOD, SEO_SOURCE_SOCIAL);

        self::assertIsArray($all);
        self::assertIsArray($social);
        self::assertCount(2, $all['rows']);
        self::assertCount(1, $social['rows'], 'Кампании режутся по классифицированному источнику визита');
        self::assertSame('promo', $social['rows'][0][2]);
    }

    public function testЦелиНеПринимаютИсточник(): void
    {
        self::assertNull(
            seoExportReport('goals', SEO_STATS_DEFAULT_PERIOD, SEO_SOURCE_SOCIAL),
            'У конверсии нет источника: отказ, а не общий список под чужой подписью'
        );
    }

    public function testОтчётЦелейЧитаетКонверсии(): void
    {
        $created = seoGoalCreate(['name' => 'Заявка', 'pattern' => '/contact', 'type' => SEO_GOAL_TYPE_PAGE_VIEW, 'match_type' => SEO_GOAL_MATCH_PREFIX]);
        self::assertTrue($created['ok'], (string) $created['error']);

        seoStatsStoreEvents([self::event(['p' => '/contact'])], 'visitor-0001');

        $report = seoExportReport('goals', SEO_STATS_DEFAULT_PERIOD, '');

        self::assertIsArray($report);
        self::assertSame(['Цель', 'Конверсии'], $report['columns']);
        self::assertSame('Заявка', $report['rows'][0][0] ?? '');
        self::assertSame('1', $report['rows'][0][1] ?? '', 'Конверсия цели обязана доехать до файла');
    }

    // ── CSV ────────────────────────────────────────────────

    public function testCsvНесётBomРазделительИКонцыСтрок(): void
    {
        $csv = seoExportCsv([
            'title' => 'Проверка',
            'columns' => ['День', 'Просмотры'],
            'rows' => [['2026-10-24', '7']],
        ]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Без BOM Excel открывает кириллицу кракозябрами');
        self::assertStringContainsString('"День";"Просмотры"', $csv, 'Разделитель русского Excel — точка с запятой');
        self::assertStringContainsString("\r\n", $csv, 'Концы строк — пара CR LF: иначе Excel видит одну строку');
        self::assertStringEndsWith("\r\n", $csv);
    }

    public function testCsvУдваиваетКавычкиВнутриПоля(): void
    {
        $csv = seoExportCsv([
            'title' => 'Проверка',
            'columns' => ['Адрес'],
            'rows' => [['/news?q="кавычка"']],
        ]);

        self::assertStringContainsString('"/news?q=""кавычка"""', $csv, 'Кавычка внутри поля удваивается, иначе таблица распадается');
    }

    public function testCsvОбезвреживаетФормулыВЯчейках(): void
    {
        $dangerous = ['=1+1', '+1', '-1', '@SUM(1)', "\t=1+1", "\r=1+1"];

        $csv = seoExportCsv([
            'title' => 'Проверка',
            'columns' => ['Значение'],
            'rows' => array_map(static fn (string $value): array => [$value], $dangerous),
        ]);

        $lines = explode("\r\n", trim($csv, "\xEF\xBB\xBF\r\n"));
        array_shift($lines);

        self::assertCount(count($dangerous), $lines);

        foreach ($lines as $index => $line) {
            $fields = str_getcsv($line, ';', '"', '\\');

            self::assertSame(
                "'" . $dangerous[$index],
                $fields[0] ?? '',
                'Значение, начинающееся с формулы, уходит текстом: Excel иначе исполнит его'
            );
        }
    }

    public function testCsvНеОтдаётФормулуИзМеткиПосетителя(): void
    {
        seoStatsStoreEvents([
            self::event(['p' => '/news', 'u' => ['s' => '=1+1', 'm' => 'social', 'c' => '=HYPERLINK("http://evil")']]),
        ], 'visitor-0001');

        $report = seoExportReport('campaigns', SEO_STATS_DEFAULT_PERIOD, '');
        self::assertIsArray($report);

        $lines = explode("\r\n", trim(seoExportCsv($report), "\xEF\xBB\xBF\r\n"));
        $fields = str_getcsv($lines[1] ?? '', ';', '"', '\\');

        self::assertSame("'=1+1", $fields[0] ?? '', 'Метка UTM приходит от посетителя и не может стать формулой в файле администратора');
        self::assertSame("'=HYPERLINK(\"http://evil\")", $fields[2] ?? '');
    }

    public function testИмяФайлаНесётВидИДатуПоМоскве(): void
    {
        $moment = new \DateTimeImmutable('2026-10-24T21:30:00+00:00');

        self::assertSame('seo-daily-20261025.csv', seoExportFilename('daily', 'csv', $moment), 'Дата в имени — московская: вечер UTC уже следующий день');
        self::assertSame('seo-pages-20261025.xlsx', seoExportFilename('pages', 'xlsx', $moment));
    }

    // ── XLSX ───────────────────────────────────────────────

    public function testКнигаXlsxСобранаКонтейнеромZip(): void
    {
        $xlsx = seoExportXlsx([
            'title' => 'Просмотры по дням',
            'columns' => ['День', 'Просмотры'],
            'rows' => [['2026-10-24', '7']],
        ]);

        self::assertStringStartsWith('PK' . "\x03\x04", $xlsx, 'Книга обязана начинаться подписью локальной записи ZIP');
        self::assertStringContainsString('PK' . "\x01\x02", $xlsx, 'Без центрального каталога Excel книгу не откроет');
        self::assertStringContainsString('PK' . "\x05\x06", $xlsx, 'Конец центрального каталога — обязательная часть контейнера');

        $files = self::unzipStored($xlsx);

        self::assertSame([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
        ], array_keys($files), 'Состав книги — минимальный набор частей, который читают Excel и LibreOffice');
    }

    public function testЛистКнигиНесётЗаголовкиИЧисла(): void
    {
        $xlsx = seoExportXlsx([
            'title' => 'Просмотры по дням',
            'columns' => ['День', 'Просмотры'],
            'rows' => [['2026-10-24', '7']],
            'numeric' => [1],
        ]);

        $files = self::unzipStored($xlsx);
        $sheet = $files['xl/worksheets/sheet1.xml'] ?? '';

        self::assertStringContainsString('<t>День</t>', $sheet, 'Заголовок колонки идёт первой строкой листа');
        self::assertStringContainsString('<t>2026-10-24</t>', $sheet, 'Дата — текст: иначе Excel пересчитает её в число');
        self::assertStringContainsString('<v>7</v>', $sheet, 'Счётчик — число, а не текст: иначе в Excel не сложить сумму');

        $workbook = $files['xl/workbook.xml'] ?? '';
        self::assertStringContainsString('name="Просмотры по дням"', $workbook, 'Лист подписан отчётом — иначе из вложения не понять, что в книге');
    }

    public function testДлинноеИмяОтчётаОбрезаетсяПодПределExcel(): void
    {
        $xlsx = seoExportXlsx([
            'title' => str_repeat('Длинное название отчёта ', 5),
            'columns' => ['Колонка'],
            'rows' => [],
        ]);

        $workbook = self::unzipStored($xlsx)['xl/workbook.xml'] ?? '';

        self::assertSame(1, preg_match('/name="([^"]*)"/u', $workbook, $matches));
        self::assertLessThanOrEqual(31, mb_strlen($matches[1]), 'Excel не принимает имя листа длиннее 31 знака');
    }

    public function testОпасныеЗнакиВОтчётеУезжаютЭкранированными(): void
    {
        $xlsx = seoExportXlsx([
            'title' => 'Проверка',
            'columns' => ['Адрес'],
            'rows' => [['/news?a=1&b=<тег>']],
        ]);

        $sheet = self::unzipStored($xlsx)['xl/worksheets/sheet1.xml'] ?? '';

        self::assertStringContainsString('&amp;', $sheet, 'Амперсанд без экранирования ломает XML книги');
        self::assertStringContainsString('&lt;', $sheet);
        self::assertStringNotContainsString('<тег>', $sheet);
    }

    public function testXlsxНеПишетТекстЧислом(): void
    {
        $xlsx = seoExportXlsx([
            'title' => 'Проверка',
            'columns' => ['Метка', 'Просмотры'],
            'rows' => [['00123', '7']],
            'numeric' => [1],
        ]);

        $sheet = self::unzipStored($xlsx)['xl/worksheets/sheet1.xml'] ?? '';

        self::assertStringContainsString('<t>00123</t>', $sheet, 'Текстовая метка остаётся текстом и не теряет ведущие нули');
        self::assertStringNotContainsString('<v>123</v>', $sheet);
        self::assertStringContainsString('<v>7</v>', $sheet, 'Счётчик по-прежнему число: иначе в Excel не сложить сумму');
    }

    public function testXlsxВычищаетНедопустимыеСимволы(): void
    {
        $xlsx = seoExportXlsx([
            'title' => "Проверка\x01",
            'columns' => ['Адрес'],
            'rows' => [["/news\x01\x0B"]],
            'numeric' => [],
        ]);

        $sheet = self::unzipStored($xlsx)['xl/worksheets/sheet1.xml'] ?? '';

        self::assertStringNotContainsString("\x01", $sheet, 'Управляющий символ не попадает в XML книги');
        self::assertNotFalse(simplexml_load_string($sheet), 'Книга остаётся разбираемым XML');
    }

    /**
     * Мини-разбор ZIP без сжатия: имя и содержимое каждой локальной записи. Метод хранения
     * проверяется заодно — писатель раздела не сжимает, и расширение zip ему не нужно.
     *
     * @return array<string, string> имя => содержимое
     */
    private static function unzipStored(string $archive): array
    {
        $out = [];
        $offset = 0;
        $signature = 'PK' . "\x03\x04";

        while (substr($archive, $offset, 4) === $signature) {
            /** @var array{method: int, size: int, nameLength: int, extraLength: int} $header */
            $header = (array) unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', substr($archive, $offset + 4, 26));

            self::assertSame(0, (int) $header['method'], 'Записи контейнера обязаны лежать без сжатия: расширение zip хостинг не обещает');

            $nameAt = $offset + 30;
            $name = (string) substr($archive, $nameAt, (int) $header['nameLength']);
            $dataAt = $nameAt + (int) $header['nameLength'] + (int) $header['extraLength'];
            $out[$name] = (string) substr($archive, $dataAt, (int) $header['size']);

            $offset = $dataAt + (int) $header['size'];
        }

        self::assertNotSame([], $out, 'Контейнер не разобрался — писатель собрал не ZIP');

        return $out;
    }
}
