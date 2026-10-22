<?php

declare(strict_types=1);

/**
 * Экран контентной аналитики (S5).
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var int $period
 * @var list<int> $periods
 * @var string $from
 * @var string $to
 * @var list<array<string, mixed>> $rows
 * @var array{views: int, sessions: int, conversions: int, conversionRate: float} $totals
 */

$periodOptions = '';
foreach ($periods as $value) {
    $label = $value === 7 ? '7 дней' : ($value === 30 ? '30 дней' : '90 дней');
    $periodOptions .= '<option value="' . e((string) $value) . '"' . ($period === $value ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

$tableRows = '';
foreach ($rows as $row) {
    $isNews = str_starts_with((string) $row['path'], '/news/item/');
    $label = $isNews ? 'статья' : 'страница';

    $tableRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium truncate max-w-[260px]" title="' . e((string) $row['path']) . '">'
        . e((string) $row['path']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/60">' . e($label) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">' . e((string) $row['views']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">' . e((string) $row['sessions']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">' . e((string) $row['conversions']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">'
        . e(number_format((float) $row['conversionRate'], 1, ',', '')) . '%</td>'
        . '</tr>';
}

$contentTable = $tableRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">За этот период просмотров нет.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr class="border-b border-ink/10">'
        . '<th class="px-2 py-3 text-left font-semibold">Страница</th>'
        . '<th class="px-2 py-3 text-left font-semibold">Что</th>'
        . '<th class="px-2 py-3 text-right font-semibold">Просмотры</th>'
        . '<th class="px-2 py-3 text-right font-semibold">Сессии</th>'
        . '<th class="px-2 py-3 text-right font-semibold">Конверсии</th>'
        . '<th class="px-2 py-3 text-right font-semibold">Конверсионный курс</th>'
        . '</tr></thead>'
        . '<tbody>' . $tableRows . '</tbody>'
        . '</table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Контент</h1>'
    . '<p class="text-sm text-ink/70">По каждой странице: просмотры и сессии из агрегатов счётчика, конверсии — из '
    . 'зафиксированных целей, конверсионный курс — конверсии к просмотрам. Это свои посещения, данные поисковика здесь '
    . 'не участвуют.</p>'
    . '</header>'
    . '<form class="flex flex-wrap items-end gap-2 mb-4" method="get" action="/seo/admin/content">'
    . '<label class="text-sm text-ink/60" for="content_period">Период</label>'
    . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="content_period" name="period">'
    . $periodOptions . '</select>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Показать</button>'
    . '</form>'
    . '<div class="grid gap-3 sm:grid-cols-4">'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Просмотры</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e((string) $totals['views']) . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Сессии</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e((string) $totals['sessions']) . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Конверсии</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e((string) $totals['conversions']) . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Конверсионный курс</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e(number_format($totals['conversionRate'], 1, ',', '')) . '%</p></div>'
    . '</div>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Страницы за период: ' . e($from) . ' — ' . e($to) . '</h2>'
    . $contentTable
    . '</section>'
    . '<p class="text-xs text-ink/60">Конверсионный курс считается как конверсии к просмотрам страницы. '
    . 'Одна конверсия на сессию и цель — обновление страницы не считается повторно.</p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Контент', $content, $notice);
