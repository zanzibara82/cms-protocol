<?php

declare(strict_types=1);

/**
 * Экран «Источники»: откуда пришёл трафик, когда он приходит и какие кампании привели.
 * Источник определяется по адресу, с которого пришли, и по меткам UTM: переход из поисковика
 * узнаётся по хосту, переход по размеченной ссылке — по метке.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array{base: string, days: int, source: string} $filter
 * @var list<array{source: string, label: string, views: int, sessions: int, visitors: int}> $sources
 * @var list<array{hour: int, views: int, sessions: int}> $hourly
 * @var list<array{source: string, medium: string, campaign: string, views: int}> $campaigns
 * @var array{enabled: bool, timeout: int, retention: int, respectDnt: bool} $settings
 */

$sourceRows = '';

foreach ($sources as $source) {
    $sourceRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) $source['label']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) $source['source']) . '</td>'
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
        . '<th class="px-2 py-2 text-left font-semibold">Ключ</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Просмотры</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Сессии</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Посетители</th>'
        . '</tr></thead>'
        . '<tbody>' . $sourceRows . '</tbody></table>';

$hourRows = '';

foreach ($hourly as $hour) {
    if ((int) $hour['views'] === 0) {
        continue;
    }

    $hourRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-2 font-medium">' . e(sprintf('%02d:00', (int) $hour['hour'])) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-2 text-sm">' . e((string) $hour['views']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-2 text-sm text-ink/70">' . e((string) $hour['sessions']) . '</td>'
        . '</tr>';
}

$hourTable = $hourRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Часов с трафиком не было.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Час</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Просмотры</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Сессии</th>'
        . '</tr></thead>'
        . '<tbody>' . $hourRows . '</tbody></table>';

$campaignRows = '';

foreach ($campaigns as $campaign) {
    $campaignRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) $campaign['campaign']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) $campaign['source']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) $campaign['medium']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) $campaign['views']) . '</td>'
        . '</tr>';
}

$campaignsTable = $campaignRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Кампаний с метками UTM не было.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Кампания</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Источник</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Носитель</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Просмотры</th>'
        . '</tr></thead>'
        . '<tbody>' . $campaignRows . '</tbody></table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Источники</h1>'
    . '<p class="text-sm text-ink/70">Откуда пришли гости. Поисковик, соцсеть или почта узнаются по хосту, '
    . 'с которого пришли; размеченная ссылка — по меткам UTM в адресе; всё остальное — «не разобрано», '
    . 'и это честнее, чем угадывать.</p>'
    . '</header>'
    . partial('seo/partials/filters', $filter)
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Откуда трафик</h2>'
    . $sourcesTable
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Когда приходит трафик</h2>'
    . $hourTable
    . '<p class="text-xs text-ink/60">Часы — по московскому времени, сутки начинаются в полночь.</p>'
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Кампании</h2>'
    . $campaignsTable
    . '</section>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Источники', $content, $notice);
