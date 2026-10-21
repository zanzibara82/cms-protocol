<?php

declare(strict_types=1);

/**
 * Обзор статистики раздела «SEO и статистика»: сводка за период, график по дням, популярные
 * страницы и источники. Методика подписана под таблицами: «посетитель» здесь — подпись
 * номера из localStorage, а не человек; число такое-то посетителей значит столько-то разных
 * номеров, а не столько-то людей.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array{base: string, days: int, source: string} $filter
 * @var array{views: int, sessions: int, visitors: int} $summary
 * @var array{views: int, sessions: int, visitors: int} $previous
 * @var list<array{day: string, views: int, sessions: int}> $daily
 * @var list<array{path: string, views: int, entrances: int, exits: int}> $pages
 * @var list<array{source: string, label: string, views: int, sessions: int, visitors: int}> $sources
 * @var array{enabled: bool, timeout: int, retention: int, respectDnt: bool} $settings
 */

/** Карточка с числом и переменой к прошлому периоду. */
$card = static function (string $label, int $value, int $was): string {
    $change = $was > 0 ? (int) round((($value - $was) / $was) * 100) : 0;
    $sign = $change > 0 ? '+' : '';
    $tone = $change > 0 ? 'text-good' : ($change < 0 ? 'text-bad' : 'text-ink/60');

    return '<div class="rounded-xl border border-ink/10 bg-white p-4">'
        . '<p class="text-sm text-ink/60">' . e($label) . '</p>'
        . '<p class="mt-1 text-2xl font-bold">' . e((string) $value) . '</p>'
        . '<p class="mt-1 text-xs ' . $tone . '">за прошлый период ' . e((string) $was)
        . ' (' . e($sign . (string) $change . '%') . ')</p>'
        . '</div>';
};

$cards = '<div class="grid gap-3 sm:grid-cols-3">'
    . $card('Просмотры', $summary['views'], $previous['views'])
    . $card('Сессии', $summary['sessions'], $previous['sessions'])
    . $card('Посетители', $summary['visitors'], $previous['visitors'])
    . '</div>';

$pageRows = '';

foreach (array_slice($pages, 0, 10) as $page) {
    $pageRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) $page['path']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) $page['views']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) $page['entrances']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) $page['exits']) . '</td>'
        . '</tr>';
}

$pagesTable = $pageRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">За этот период страниц ещё не открывали.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Адрес</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Просмотры</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Входы</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Выходы</th>'
        . '</tr></thead>'
        . '<tbody>' . $pageRows . '</tbody></table>';

$sourceRows = '';

foreach (array_slice($sources, 0, 10) as $source) {
    $sourceRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) $source['label']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) $source['views']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) $source['sessions']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) $source['visitors']) . '</td>'
        . '</tr>';
}

$sourcesTable = $sourceRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">За этот период трафика не было.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Источник</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Просмотры</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Сессии</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Посетители</th>'
        . '</tr></thead>'
        . '<tbody>' . $sourceRows . '</tbody></table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Обзор</h1>'
    . '<p class="text-sm text-ink/70">Сколько страниц открыли, откуда пришли и сколько было гостей. '
    . 'Счётчик считает собственные посещения: cookie не используются, адрес посетителя не хранится, '
    . 'содержимое форм не собирается.</p>'
    . '</header>'
    . partial('seo/partials/filters', $filter)
    . $cards
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Просмотры по дням</h2>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . partial('seo/partials/chart', ['daily' => $daily])
    . '</div>'
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Популярные страницы</h2>'
    . $pagesTable
    . '<p class="text-xs text-ink/60">Входы и выходы — по сессиям: входом считается первая страница сессии, '
    . 'выходом — последняя. Сессия — это окно бездействия в ' . e((string) $settings['timeout']) . ' минут.</p>'
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Источники</h2>'
    . $sourcesTable
    . '</section>'
    . '<p class="text-xs text-ink/60">Методика: просмотры и сессии считаются по агрегатам, посетители — '
    . 'по подписи номера из localStorage. Один человек с двух браузеров посчитается двумя посетителями, '
    . 'а один посетитель с длинной сессией — одним. Числа ориентировочные, а не точная аудитория.</p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Обзор', $content, $notice);
