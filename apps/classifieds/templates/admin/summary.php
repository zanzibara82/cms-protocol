<?php

declare(strict_types=1);

/**
 * Обзор доски: счётчики и быстрые ссылки.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array<string, int> $counts
 */
$panel = static function (string $title, string $href, array $lines): string {
    $rows = '';
    foreach ($lines as $label => $value) {
        $rows .= '<div class="flex items-baseline justify-between gap-2 border-b border-ink/10 py-1">'
            . '<dt class="text-sm text-ink/60">' . e($label) . '</dt>'
            . '<dd class="font-medium tabular-nums">' . e((string) $value) . '</dd></div>';
    }

    return '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e($href) . '">' . e($title) . '</a></h2><dl class="mt-2">' . $rows . '</dl></section>';
};

$content = $nav
    . '<h1 class="text-2xl font-bold">Доска объявлений</h1>'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . $panel('Категории', '/classifieds/admin/categories', [
        'всего' => $counts['categories'],
    ])
    . $panel('Характеристики', '/classifieds/admin/attributes', [
        'полей' => $counts['attributes'],
    ])
    . $panel('Объявления', '/classifieds', [
        'всего' => $counts['listings'],
        'опубликовано' => $counts['published'],
    ])
    . '</div>'
    . '<p class="mt-4 text-sm text-ink/60">Продавцов на доске: ' . e((string) $counts['sellers']) . '.</p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Доска', $content, $notice);
