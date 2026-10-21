<?php

declare(strict_types=1);

/**
 * Экран «Страницы»: популярные адреса за период с входами и выходами, рядом — кампании по
 * меткам UTM. Метки читаются из адреса первого захода и помогают увидеть, какая реклама
 * привела на сайт.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array{base: string, days: int, source: string} $filter
 * @var list<array{path: string, views: int, entrances: int, exits: int}> $pages
 * @var list<array{source: string, medium: string, campaign: string, views: int}> $campaigns
 * @var array{enabled: bool, timeout: int, retention: int, respectDnt: bool} $settings
 */

$pageRows = '';

foreach ($pages as $page) {
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
    . '<h1 class="text-2xl font-bold">Страницы</h1>'
    . '<p class="text-sm text-ink/70">Какие адреса открывают и откуда в них заходят. В адресе без служебной '
    . 'части — её не видно: в счёт идут пути страниц, а не полные ссылки с метками.</p>'
    . '</header>'
    . partial('seo/partials/filters', $filter)
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Популярные страницы</h2>'
    . $pagesTable
    . '<p class="text-xs text-ink/60">Вход — первая страница сессии, выход — последняя. Сессия живёт '
    . e((string) $settings['timeout']) . ' минут без движения.</p>'
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Кампании</h2>'
    . $campaignsTable
    . '<p class="text-xs text-ink/60">Кампания читается из метки utm_campaign в адресе первого захода. '
    . 'Метки приходят только при прямом заходе по размеченной ссылке.</p>'
    . '</section>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Страницы', $content, $notice);
