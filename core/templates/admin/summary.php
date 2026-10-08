<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $siteName */
/** @var string $adminEmail */
/** @var int $users */
/** @var list<array{title: string, href: string, state: string}> $apps */

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
] as $card) {
    $cards .= '<div class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<dt class="text-sm text-ink/60">' . e($card['label']) . '</dt>'
        . '<dd class="mt-1 font-medium">' . e($card['value']) . '</dd></div>';
}

$content = '<dl class="grid gap-3 sm:grid-cols-3">' . $cards . '</dl>'
    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Установленные разделы</h2><ul class="mt-2">' . $rows . '</ul></section>';

/* @safe html */ echo adminShell('Сводка', $content, $notice);
