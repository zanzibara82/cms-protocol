<?php

declare(strict_types=1);

/**
 * Экран «Поиск»: то, что отдал поисковик по нашему сайту. Это не наша аналитика — своя
 * статистика живёт на экране «Обзор», и смешивать их нельзя: выборка поисковика неполна по
 * определению, и на экране сказано об этом прямо.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $state
 * @var string $provider
 * @var string $providerName
 * @var string $site
 * @var bool $isTest
 * @var string $connectedAt
 * @var int $period
 * @var list<int> $periods
 * @var string $dimension
 * @var array<string, string> $dimensions
 * @var string $from
 * @var string $to
 * @var string $importFrom
 * @var string $importTo
 * @var list<array<string, mixed>> $rows
 * @var array{clicks: int, impressions: int, ctr: float, position: float} $totals
 * @var int $lastImport
 */

$dimensionLabels = ['query' => 'Запрос', 'page' => 'Страница', 'country' => 'Страна', 'device' => 'Устройство'];
$dimensionLabel = $dimensionLabels[$dimension] ?? 'Запрос';

$periodOptions = '';
foreach ($periods as $value) {
    $label = $value === 7 ? '7 дней' : ($value === 30 ? '30 дней' : '90 дней');
    $periodOptions .= '<option value="' . e((string) $value) . '"' . ($period === $value ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

$dimensionOptions = '';
foreach ($dimensions as $value => $label) {
    $dimensionOptions .= '<option value="' . e((string) $value) . '"' . ($dimension === $value ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

// Состояние источника: пока он не подключён, таблица всё равно показывается — пустой, но с
// объяснением, почему он пуст, и ссылкой на подключение.
$sourceHtml = '';

if ($state === 'none') {
    $sourceHtml = '<section class="rounded-xl border border-ink/10 bg-cream p-6">'
        . '<h2 class="text-lg font-semibold">Источник не подключён</h2>'
        . '<p class="mt-1 text-sm text-ink/70">Здесь появятся запросы, показы, клики, CTR и средняя позиция — '
        . 'но только после подключения официального источника. Без него данных нет, и выдумывать их мы не будем.</p>'
        . '<a class="mt-3 inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
        . ' href="/seo/admin/search/source">Подключить источник</a>'
        . '</section>';
} elseif ($state === 'configured') {
    $sourceHtml = '<section class="rounded-xl border border-ink/10 bg-cream p-6">'
        . '<h2 class="text-lg font-semibold">Источник настроен, но входа ещё не было</h2>'
        . '<p class="mt-1 text-sm text-ink/70">Настройки заведены: ' . e($providerName) . ', сайт ' . e($site)
        . '. Осталось войти в источник и получить токен — после этого импорт заработает.</p>'
        . '<a class="mt-3 inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
        . ' href="/seo/admin/search/source">Зайти в источник</a>'
        . '</section>';
} else {
    $sourceHtml = '<section class="rounded-xl border border-ink/10 bg-white p-4">'
        . '<p class="text-sm text-ink/60">Источник</p>'
        . '<p class="mt-1 font-medium">' . e($providerName) . ' — ' . e($site) . '</p>'
        . ($isTest
            ? '<p class="mt-2 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad">'
                . 'Это тестовый источник: данные выдуманы по правилу, чтобы экраны и отчёты можно было проверить '
                . 'без доступа к Google. Настоящими показателями поисковика они не являются.</p>'
            : '<p class="mt-1 text-sm text-ink/60">Токен получен'
                . ($connectedAt !== '' ? ' ' . e($connectedAt) : '') . '.</p>')
        . '</section>';
}

// Форма импорта: период и измерение. Потолки (длина периода, пауза между запусками) написаны
// рядом с формой — человек должен знать границы до того, как упрётся в них.
$importHtml = '<form class="space-y-3 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/search">'
    . csrfField()
    . '<input type="hidden" name="op" value="search_import">'
    . '<h2 class="text-lg font-semibold">Импорт данных</h2>'
    . '<div class="flex flex-wrap items-end gap-3">'
    . '<label class="block text-sm text-ink/60" for="search_from">С'
    . '<input class="mt-1 block min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="date" id="search_from" name="from"'
    . ' value="' . e($importFrom) . '"></label>'
    . '<label class="block text-sm text-ink/60" for="search_to">По'
    . '<input class="mt-1 block min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="date" id="search_to" name="to"'
    . ' value="' . e($importTo) . '"></label>'
    . '<label class="block text-sm text-ink/60" for="search_dimension">Измерение'
    . '<select class="mt-1 block min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="search_dimension" name="dimension">'
    . $dimensionOptions . '</select></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' type="submit">Импортировать</button>'
    . '</div>'
    . '<p class="text-xs text-ink/60">Период за один раз — не длиннее '
    . e((string) SEO_SEARCH_RANGE_MAX_DAYS) . ' дней, между запусками проходит не меньше '
    . e((string) SEO_SEARCH_IMPORT_INTERVAL) . ' секунд, строк за раз — не больше '
    . e((string) SEO_SEARCH_ROWS_MAX) . '. Это расход квоты источника, а не украшение отчёта.</p>'
    . '</form>';

$filterHtml = '<form class="flex flex-wrap items-end gap-2" method="get" action="/seo/admin/search">'
    . '<label class="text-sm text-ink/60" for="search_period">Период</label>'
    . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="search_period" name="period">'
    . $periodOptions . '</select>'
    . '<label class="text-sm text-ink/60" for="search_view">Показать</label>'
    . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="search_view" name="dimension">'
    . $dimensionOptions . '</select>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Показать</button>'
    . '</form>';

$tableRows = '';
foreach ($rows as $row) {
    $impressions = (int) ($row['impressions'] ?? 0);
    $clicks = (int) ($row['clicks'] ?? 0);
    $ctr = $impressions > 0 ? round($clicks / $impressions * 100, 1) : 0.0;

    $tableRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) ($row['name'] ?? '')) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) $clicks) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) $impressions) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e(rtrim(rtrim(number_format($ctr, 1, ',', ''), '0'), ',') . '%') . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e(number_format((float) ($row['position'] ?? 0), 1, ',', '')) . '</td>'
        . '</tr>';
}

$table = $tableRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">За этот период данных от источника нет.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">' . e($dimensionLabel) . '</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Клики</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Показы</th>'
        . '<th class="px-2 py-2 text-left font-semibold">CTR</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Позиция</th>'
        . '</tr></thead>'
        . '<tbody>' . $tableRows . '</tbody></table>';

$totalsHtml = '<div class="grid gap-3 sm:grid-cols-4">'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4"><p class="text-sm text-ink/60">Клики</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e((string) $totals['clicks']) . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4"><p class="text-sm text-ink/60">Показы</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e((string) $totals['impressions']) . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4"><p class="text-sm text-ink/60">CTR</p>'
    . '<p class="mt-1 text-2xl font-bold">'
    . e(rtrim(rtrim(number_format($totals['ctr'] * 100, 1, ',', ''), '0'), ',') . '%') . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4"><p class="text-sm text-ink/60">Средняя позиция</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e(number_format($totals['position'], 1, ',', '')) . '</p></div>'
    . '</div>';

$lastImportText = $lastImport > 0
    ? ' Последний импорт: ' . e(utcStampFromTimestamp($lastImport)) . '.'
    : ' Импортов ещё не было.';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Поиск</h1>'
    . '<p class="text-sm text-ink/70">Данные, которые отдал поисковик по нашему сайту: запросы, показы, клики, CTR '
    . 'и средняя позиция. Это не наша аналитика — свои посещения смотрите на экране «Обзор». Выборка поисковика '
    . 'неполна: часть запросов он не отдаёт, а позиция — средняя по странице, а не лучшая.</p>'
    . '</header>'
    . $sourceHtml
    . $importHtml
    . $filterHtml
    . $totalsHtml
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">' . e($dimensionLabel) . 'ы за период</h2>'
    . $table
    . '<p class="text-xs text-ink/60">Период: ' . e($from) . ' — ' . e($to) . '.'
    . $lastImportText
    . ' CTR посчитан как «клики к показам», а не как среднее по строкам: у строк разный вес, и среднее по CTR-ам '
    . 'искажает картину. Позиция — средняя, как её отдаёт источник.</p>'
    . '</section>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Поиск', $content, $notice);
