<?php

declare(strict_types=1);

// Экспорт отчётов статистики (S6, спецификация §7 «Экспорт CSV/XLSX»). Отчёт — тот же разрез,
// что и на экранах панели: только агрегаты и цели, а не сырьё; строка запроса и личные данные
// в файл не попадают, потому что их нет и в базе.
//
// Форматы держатся российскими привычками Excel: разделитель «;», десятичная запятая не нужна
// (числа здесь целые), BOM и концы строк CRLF в CSV, а XLSX собирается архивом без сжатия —
// чек-лист хостинга не требует расширения zip, и экспорт не вправе от него зависеть. Свой
// писатель собирает XML-файлы книги в ZIP-контейнер самих себя: файл открывается Excel и
// LibreOffice, а состав контролируется тестом db.
//
// Скачивание получает те же запреты, что и приёма прайс-листа магазина: заголовок вложения,
// запрет индексирования и запрет кэша — цифры отчёта не стоят ни в поиске, ни в кэше.

const SEO_EXPORT_KINDS = [
    'daily' => 'Просмотры по дням',
    'pages' => 'Популярные страницы',
    'sources' => 'Источники',
    'campaigns' => 'Кампании по меткам',
    'goals' => 'Цели и конверсии',
];

// Виды отчёта, которые принимают фильтр по источнику. У конверсии источника нет, поэтому
// «цели» этот фильтр не принимают: экран для них прячет выбор, а скачивание с источником
// возвращает к экрану, вместо того чтобы молча отдать общий список под чужой подписью.
const SEO_EXPORT_SOURCE_KINDS = ['daily', 'pages', 'sources', 'campaigns'];

/**
 * Отчёт для скачивания: те же данные, что и на экранах, но одной таблицей. Период и источник
 * проверяются тем же правилом, что и фильтры панели: значение вне границ — отказ, а не
 * молчаливая подмена («человек хотел другое» словами не названо).
 *
 * @param string $kind ключ из SEO_EXPORT_KINDS
 * @param int $days период из SEO_STATS_PERIODS
 * @param string $source ключ из SEO_SOURCES или пустая строка
 * @return array{title: string, columns: list<string>, rows: list<list<string>>, numeric: list<int>}|null
 */
function seoExportReport(string $kind, int $days, string $source): ?array
{
    $title = SEO_EXPORT_KINDS[$kind] ?? '';

    if ($title === '' || !in_array($days, SEO_STATS_PERIODS, true)) {
        return null;
    }

    if ($source !== '' && !isset(SEO_SOURCES[$source])) {
        return null;
    }

    if ($source !== '' && !in_array($kind, SEO_EXPORT_SOURCE_KINDS, true)) {
        return null;
    }

    $period = seoStatsPeriod($days);
    $from = $period['from'];
    $to = $period['to'];

    return match ($kind) {
        'daily' => [
            'title' => $title,
            'columns' => ['День', 'Просмотры', 'Сессии'],
            'numeric' => [1, 2],
            'rows' => array_map(
                static fn (array $row): array => [(string) $row['day'], (string) $row['views'], (string) $row['sessions']],
                seoStatsDaily($from, $to, $source)
            ),
        ],
        'pages' => [
            'title' => $title,
            'columns' => ['Адрес', 'Просмотры', 'Входы', 'Выходы'],
            'numeric' => [1, 2, 3],
            'rows' => array_map(
                static fn (array $row): array => [(string) $row['path'], (string) $row['views'], (string) $row['entrances'], (string) $row['exits']],
                seoStatsPages($from, $to, $source)
            ),
        ],
        'sources' => [
            'title' => $title,
            'columns' => ['Источник', 'Просмотры', 'Сессии', 'Посетители'],
            'numeric' => [1, 2, 3],
            'rows' => array_map(
                static fn (array $row): array => [(string) $row['label'], (string) $row['views'], (string) $row['sessions'], (string) $row['visitors']],
                seoStatsSources($from, $to, $source)
            ),
        ],
        'campaigns' => [
            'title' => $title,
            'columns' => ['Метка источника', 'Метка типа', 'Метка кампании', 'Просмотры'],
            'numeric' => [3],
            'rows' => array_map(
                static fn (array $row): array => [(string) $row['source'], (string) $row['medium'], (string) $row['campaign'], (string) $row['views']],
                seoStatsCampaigns($from, $to, $source)
            ),
        ],
        'goals' => [
            'title' => $title,
            'columns' => ['Цель', 'Конверсии'],
            'numeric' => [1],
            'rows' => array_map(
                static fn (array $row): array => [(string) $row['name'], (string) $row['conversions']],
                seoGoalConversions($from, $to)
            ),
        ],
        default => null,
    };
}

/**
 * CSV: скобки кавычек двойным повтором, концы строк CRLF, BOM в голове — «двойной щелчок
 * по файлу» открывает его без визарда. Заголовки колонок — первая строка.
 *
 * Ячейка, начинающаяся с `=`, `+`, `-`, `@`, табуляции или возврата каретки, получает
 * ведущий апостроф: Excel и LibreOffice исполняют такую ячейку как формулу, а метки UTM
 * приходят от посетителя. Данные в базе не меняются — защита стоит только на выводе.
 *
 * @param array{title: string, columns: list<string>, rows: list<list<string>>} $report
 */
function seoExportCsv(array $report): string
{
    $field = static function (string $value): string {
        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    };

    $lines = [implode(';', array_map($field, $report['columns']))];

    foreach ($report['rows'] as $row) {
        $lines[] = implode(';', array_map($field, $row));
    }

    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

/**
 * XLSX: книга из одного листа. Ячейки — два вида: текст (inline-строка, совместная строка
 * не нужна) и число (целые счётчики). Числовыми считаются только колонки, перечисленные в
 * `numeric`: текстовая метка «00123» иначе потеряла бы ведущие нули, а длинный идентификатор —
 * точность. Имя листа подписано отчётом, потому что человек выбирает книгу из почтового
 * вложения и обязан видеть, что в ней.
 *
 * @param array{title: string, columns: list<string>, rows: list<list<string>>, numeric?: list<int>} $report
 */
function seoExportXlsx(array $report): string
{
    $xml = static function (string $value): string {
        // XML 1.0 не принимает управляющие символы (кроме табуляции, LF и CR): метка UTM или
        // имя цели с таким знаком сделали бы книгу нечитаемой. Чистим только вывод.
        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $clean = (string) preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $clean
        );

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };

    $name = $xml(mb_substr($report['title'], 0, 31));
    $numeric = $report['numeric'] ?? [];

    $rowsXml = '<row>';
    foreach ($report['columns'] as $column) {
        $rowsXml .= '<c t="inlineStr"><is><t>' . $xml($column) . '</t></is></c>';
    }
    $rowsXml .= '</row>';

    foreach ($report['rows'] as $row) {
        $rowsXml .= '<row>';
        foreach ($row as $index => $value) {
            $rowsXml .= in_array($index, $numeric, true)
                ? '<c><v>' . $xml($value) . '</v></c>'
                : '<c t="inlineStr"><is><t>' . $xml($value) . '</t></is></c>';
        }
        $rowsXml .= '</row>';
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    return seoZipStored([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheet,
    ]);
}

/**
 * ZIP-контейнер без сжатия: локальные заголовки, центральный каталог и его конец.
 * Метод хранения — ноль: Excel обязывает читать только контейнер по правилам ZIP,
 * а не «первый файл подряд», и правила соблюдены. Время в заголовках нулевое: экспорт
 * не должен делать вид, будто файлы отличаются содержимым времени сборки.
 *
 * @param array<string, string> $files имя => содержимое
 */
function seoZipStored(array $files): string
{
    $out = '';
    $central = '';
    $offset = 0;

    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $size = strlen($data);
        $header = 'PK' . "\x03\x04"
            . pack('v', 20)          // версия для чтения
            . pack('v', 0)           // флаги
            . pack('v', 0)           // метод: хранение
            . pack('v', 0) . pack('v', 0) // время и дата: нулевые
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', strlen($name))
            . pack('v', 0)           // имя файла: длина
            . $name;

        $local = $header . $data;

        $central .= 'PK' . "\x01\x02"
            . pack('v', 20) . pack('v', 20) // версии для записи и для чтения
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', strlen($name))
            . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('V', 0)
            . pack('V', $offset)
            . $name;

        $out .= $local;
        $offset += strlen($local);
    }

    $out .= $central;
    $out .= 'PK' . "\x05\x06"
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', count($files))
        . pack('v', count($files))
        . pack('V', strlen($central))
        . pack('V', $offset)
        . pack('v', 0);

    return $out;
}

/**
 * Имя файла: отчёт, день по Москве и расширение. Печатный порядок: сначала отчёт,
 * потом дата и формат — сортировка по имени в почтовом вложении почти лишена смысла.
 */
function seoExportFilename(string $kind, string $format, DateTimeImmutable $moment): string
{
    $day = str_replace('-', '', (string) substr(moscowIso($moment), 0, 10));

    return 'seo-' . $kind . '-' . $day . '.' . ($format === 'xlsx' ? 'xlsx' : 'csv');
}
