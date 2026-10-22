<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $siteName */
/** @var string $adminEmail */
/** @var int $users */
/** @var list<array{title: string, href: string, state: string}> $apps */
/** @var string $phpVersion */
/** @var string $phpState */
/** @var string $phpHint */

$rows = '';
// min-h-[44px] на ссылках разделов — глобальное требование проекта к зоне нажатия.
foreach ($apps as $app) {
    $rows .= '<li class="flex flex-wrap items-center justify-between gap-2 border-b border-ink/10 py-2">'
        . '<a class="inline-flex min-h-[44px] items-center font-medium hover:underline" href="' . e($app['href']) . '">' . e($app['title']) . '</a>'
        . '<span class="text-sm text-ink/60">' . e($app['state']) . '</span></li>';
}

if ($rows === '') {
    $rows = '<li class="py-2 text-sm text-ink/60">Разделов пока нет — установите их на странице «Приложения».</li>';
}

$cards = '';
foreach ([
    ['label' => 'Сайт', 'value' => $siteName],
    ['label' => 'Администратор', 'value' => $adminEmail],
    ['label' => 'Пользователей', 'value' => (string) (int) $users],
    ['label' => 'PHP', 'value' => $phpVersion],
] as $card) {
    $cards .= '<div class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<dt class="text-sm text-ink/60">' . e($card['label']) . '</dt>'
        . '<dd class="mt-1 font-medium">' . e($card['value']) . '</dd></div>';
}

// Предупреждение о версии PHP на хостинге: 21 октября 2026 года вышло обновление
// безопасности (CVE-2026-109618, CVE-2026-109619), а старый движок на сервере остаётся
// открытой дырой. Плашка появляется только когда состояние не 'ok', и говорит ровно то,
// что нужно сделать: обновить движок. Подробности — в подсказке ядра.
$phpWarning = '';
if ($phpState !== 'ok') {
    $phpWarning = '<div class="rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert">'
        . '<p class="font-medium">PHP ' . e($phpVersion) . ' — версия движка без обновления безопасности</p>'
        . '<p class="mt-1">' . e($phpHint) . '</p></div>';
}

$content = '<dl class="grid gap-3 sm:grid-cols-3">' . $cards . '</dl>'
    . ($phpWarning === '' ? '' : '<div class="mt-3">' . $phpWarning . '</div>')
    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Установленные разделы</h2><ul class="mt-2">' . $rows . '</ul></section>';

/* @safe html */ echo adminShell('Сводка', $content, $notice);
