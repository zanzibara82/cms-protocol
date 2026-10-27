<?php

declare(strict_types=1);

// Экспорт отчётов (S6): экран выбора и скачивание файла. Экран читает тот же отчёт, что
// пойдёт в файл, поэтому человек видит ровно то, что уедет в Excel, — иначе он выгрузил бы
// неизвестность. Скачивание — на отдельном маршруте: экран живёт в навигации, файл живёт
// в почтовом вложении, и у них разные ответы (страница и вложение, а не одна страница).
//
// Неверные аргументы экрана снимаются запасным периодом, а на скачивании дают перенаправление
// обратно на экран: файл с чужим периодом — это не «как получится», а «мы отказались».

/**
 * Период из адреса: только целое число из разрешённого списка. Приведение `(int)` пропускало
 * «30abc» как 30 — чужая строка молча становилась отчётом за 30 дней. Ноль здесь значит
 * «значение не наше»: экран берёт запасной период, скачивание отказывает.
 */
function seoExportDays(mixed $raw): int
{
    $raw = is_string($raw) ? $raw : '';

    if (preg_match('/^\d+$/', $raw) !== 1) {
        return 0;
    }

    $days = (int) $raw;

    return in_array($days, SEO_STATS_PERIODS, true) ? $days : 0;
}

/**
 * Фильтры экрана экспорта: вид отчёта, период, источник. Неизвестный вид отчёта — экран
 * показывает первый по списку, потому что экран не имеет права свалиться: человеку нужен
 * выбор, а не стена отказа.
 *
 * @return array{kind: string, days: int, source: string}
 */
function seoExportFilters(): array
{
    $kind = (string) queryString('kind', '');
    $kinds = seoReportKinds('export');

    if (!isset($kinds[$kind])) {
        $kind = (string) array_key_first($kinds);
    }

    $days = seoExportDays(queryString('days', ''));

    if ($days === 0) {
        $days = SEO_STATS_DEFAULT_PERIOD;
    }

    $rawSource = queryString('source', '');
    $source = is_string($rawSource) ? trim($rawSource) : '';

    if ($source !== '' && !isset(SEO_SOURCES[$source])) {
        $source = '';
    }

    // У отчёта, который не делится по источнику, выбор снимается: подпись «источник»
    // на общем списке была бы ложью, а не подсказкой.
    if (!seoReportSupportsSource($kind)) {
        $source = '';
    }

    return ['kind' => $kind, 'days' => $days, 'source' => $source];
}

/**
 * Адрес скачивания выбранного отчёта: один адрес держит все фильтры, чтобы несколько звеньев
 * на экране (для каждого формата) не расползлись по аргументам.
 */
function seoExportDownloadHref(string $kind, int $days, string $source, string $format = 'csv'): string
{
    return '/seo/admin/export/download?' . http_build_query([
        'kind' => $kind,
        'days' => (string) $days,
        'source' => $source,
        'format' => $format,
    ]);
}

/**
 * Экран экспорта: выбор вида отчёта, периода и источника, предпросмотр таблицы и ссылки
 * на скачивание двух форматов.
 *
 * @param array<string, string> $params
 */
function seoExportPage(array $params = []): void
{
    $filters = seoExportFilters();
    $report = seoExportReport($filters['kind'], $filters['days'], $filters['source']);

    render('seo/admin/export', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'filter' => [
            'base' => '/seo/admin/export',
            'kind' => $filters['kind'],
            'days' => $filters['days'],
            'source' => $filters['source'],
            'split' => seoReportSupportsSource($filters['kind']),
            'kinds' => seoReportKinds('export'),
        ],
        'report' => $report,
        'download' => [
            'csv' => seoExportDownloadHref($filters['kind'], $filters['days'], $filters['source']),
            'xlsx' => seoExportDownloadHref($filters['kind'], $filters['days'], $filters['source'], 'xlsx'),
        ],
    ], seoAdminAlert()), ['title' => 'Экспорт — SEO', 'noindex' => true]);
}

/**
 * Скачивание отчёта: CSV по умолчанию, XLSX при выборе формата. Неверные аргументы не
 * отдают файл вслепую: человек возвращается на экран, где снова видит выбор. Формат вне
 * двух известных снимается запасным, потому что формат — не отчёт: выбор вида файл не меняет.
 *
 * @param array<string, string> $params
 * @return never
 */
function seoExportDownloadPage(array $params = []): never
{
    $kind = (string) queryString('kind', '');
    $days = seoExportDays(queryString('days', ''));
    $source = trim((string) queryString('source', ''));
    $format = (string) queryString('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';

    if (!isset(seoReportKinds('export')[$kind]) || !in_array($days, SEO_STATS_PERIODS, true)) {
        redirect('/seo/admin/export');
    }

    $report = seoExportReport($kind, $days, $source);

    if ($report === null) {
        redirect('/seo/admin/export');
    }

    $body = $format === 'xlsx' ? seoExportXlsx($report) : seoExportCsv($report);
    $moment = nowUtc();

    respond(200, $body, [
        'Content-Type' => $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . seoExportFilename($kind, $format, $moment) . '"',
        'X-Robots-Tag' => 'noindex',
        'Cache-Control' => 'no-store',
    ]);
}
