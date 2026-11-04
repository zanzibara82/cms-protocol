<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var int $levels
 * @var int $courses
 * @var int $modules
 * @var int $lessons
 */

$forms = ['уровень', 'уровня', 'уровней'];

$lines = '';
foreach ([
    ['уровней', $levels, $forms],
    ['курсов', $courses, ['курс', 'курса', 'курсов']],
    ['модулей', $modules, ['модуль', 'модуля', 'модулей']],
    ['уроков', $lessons, ['урок', 'урока', 'уроков']],
] as [$label, $value, $itemForms]) {
    $lines .= '<div class="flex items-baseline justify-between gap-2 border-b border-ink/10 py-1 last:border-0">'
        . '<dt class="text-sm text-ink/60">' . e($label) . '</dt>'
        . '<dd class="font-medium tabular-nums">' . e((string) $value) . '</dd></div>';
}

$panel = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<dl class="mt-2">' . $lines . '</dl></section>';

$content = '<p class="text-sm text-ink/60">Раздел «Курсы»: уровни, курсы, модули и уроки. '
    . 'Серый адрес не открыт ученику — статус решает, что показывается.</p>'
    . '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-2">' . $panel . '</div>'
    . '<p class="flex flex-wrap items-center gap-3">'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:opacity-90" href="/learn/admin/courses">Уровни и курсы</a>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:opacity-90" href="/learn/admin/lessons">Уроки</a>'
    . '<a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-ink" href="/learn">Посмотреть как ученик</a>'
    . '</p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo adminShell('Курсы — админка', $content, $notice);
