<?php

declare(strict_types=1);

/**
 * Экран «Экспорт»: выбор вида отчёта, периода и источника, предпросмотр таблицы и ссылки
 * на скачивание. Человек видит ровно то, что уедет в файл: предпросмотр собран той же
 * функцией, что и скачивание.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array{base: string, kind: string, days: int, source: string, split: bool, kinds: array<string, string>} $filter
 * @var array{title: string, columns: list<string>, rows: list<list<string>>}|null $report
 * @var array{csv: string, xlsx: string} $download
 */

$kindOptions = '';

foreach ($filter['kinds'] as $key => $title) {
    $kindOptions .= '<option value="' . e($key) . '"' . ($key === $filter['kind'] ? ' selected' : '')
        . '>' . e($title) . '</option>';
}

$sourceOptions = '';

foreach (SEO_SOURCES as $key => $title) {
    $sourceOptions .= '<option value="' . e($key) . '"' . ($key === $filter['source'] ? ' selected' : '')
        . '>' . e($title) . '</option>';
}

$periodOptions = '';

foreach (SEO_STATS_PERIODS as $days) {
    $periodOptions .= '<option value="' . e((string) $days) . '"' . ($days === $filter['days'] ? ' selected' : '')
        . '>' . e((string) ($days === SEO_STATS_DEFAULT_PERIOD ? (string) $days . ' (по умолчанию)' : (string) $days)) . '</option>';
}

$sourceField = $filter['split'] === true
    ? '<label class="block" for="export_source">'
        . '<span class="text-sm text-ink/60">Источник</span>'
        . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="export_source" name="source">'
        . '<option value=""' . ($filter['source'] === '' ? ' selected' : '') . '>Все источники</option>'
        . $sourceOptions
        . '</select></label>'
    : '<p class="text-sm text-ink/60">Этот отчёт не делится по источнику.</p>';

$filterForm = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="get" action="' . e($filter['base']) . '">'
    . '<label class="block" for="export_kind">'
    . '<span class="text-sm text-ink/60">Отчёт</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="export_kind" name="kind">'
    . $kindOptions
    . '</select></label>'
    . '<label class="block" for="export_days">'
    . '<span class="text-sm text-ink/60">Период, дней</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="export_days" name="days">'
    . $periodOptions
    . '</select></label>'
    . $sourceField
    . '<div class="mt-1">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' type="submit">Обновить предпросмотр</button>'
    . '</div></form>';

$table = '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">За этот период данных нет.</p>';

if ($report !== null) {
    $head = '<tr>';
    foreach ($report['columns'] as $column) {
        $head .= '<th class="px-2 py-2 text-left font-semibold">' . e($column) . '</th>';
    }
    $head .= '</tr>';

    $body = '';

    foreach ($report['rows'] as $row) {
        $body .= '<tr>';
        foreach ($row as $cell) {
            $body .= '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e($cell) . '</td>';
        }
        $body .= '</tr>';
    }

    $table = '<p class="text-sm text-ink/60">Отчёт: ' . e($report['title'])
        . ' — ' . e((string) count($report['rows'])) . ' строк.</p>'
        . '<table class="w-full text-sm"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table>';
}

$downloads = '<div class="flex flex-wrap gap-3">'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' href="' . e($download['csv']) . '">Скачать CSV</a>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-5 py-2 text-sm hover:bg-ink/5"'
    . ' href="' . e($download['xlsx']) . '">Скачать XLSX</a>'
    . '</div>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Экспорт отчётов</h1>'
    . '<p class="text-sm text-ink/70">Отчёты выгружаются в CSV и XLSX: разделитель CSV — точка с запятой, '
    . 'концы строк — Windows-стиль, файл открывается двойным щелчком. Заголовки колонок идут первой строкой, '
    . 'числа — целые счётчики за период.</p>'
    . '</header>'
    . $filterForm
    . '<section class="space-y-3">'
    . '<h2 class="text-lg font-semibold">Предпросмотр</h2>'
    . $downloads
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . $table
    . '</div>'
    . '</section>'
    . '<p class="text-xs text-ink/60">В файл уходит то же, что на экране: агрегаты и цели, без каталога адресов '
    . 'посетителей и прочего личного — его и в базе не хранится.</p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Экспорт', $content, $notice);
