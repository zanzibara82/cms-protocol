<?php

declare(strict_types=1);

/**
 * Экран модуля: уроки по порядку, галочки пройденного и замки следующих.
 *
 * @var array{
 *     module: array{id: string, title: string, description: string, sort: int, lessons: list<array{id: string, slug: string, title: string, sort: int, completed: bool, unlocked: bool}>, lesson_count: int, completed_count: int, percent: int},
 *     course: array{id: string, level_id: string, title: string, description: string, sort: int},
 *     level: array{id: string, code: string, title: string, sort: int}
 * } $payload
 */
$module = $payload['module'];
$items = '';

foreach ($module['lessons'] as $lesson) {
    $title = (string) $lesson['sort'] . '. ' . $lesson['title'];
    $badges = '';

    if ($lesson['completed']) {
        $badges .= '<span class="rounded bg-accent/15 px-2 py-0.5 text-xs font-medium">Пройден</span>';
    }

    if (!$lesson['unlocked']) {
        $badges .= '<span class="rounded bg-ink/10 px-2 py-0.5 text-xs font-medium">Урок закрыт</span>';
    }

    $link = $lesson['unlocked']
        ? '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
            . ' href="' . e('/lesson/' . rawurlencode($lesson['slug'])) . '">' . e($title) . '</a>'
        : '<span class="text-ink/50">' . e($title) . '</span>';

    $items .= '<li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">'
        . '<div class="flex flex-wrap items-center gap-2">' . $link . $badges . '</div>'
        . '</li>';
}

$progress = 'Прогресс: ' . (string) $module['completed_count'] . ' из ' . (string) $module['lesson_count'] . ' уроков';

$content = '<section class="space-y-6">'
    . '<header class="space-y-2">'
    . '<p class="text-sm text-ink/60">' . e($payload['level']['code']) . ' · ' . e($payload['course']['title']) . '</p>'
    . '<h1 class="text-3xl font-bold">Модуль ' . e((string) $module['sort']) . ': ' . e($module['title']) . '</h1>'
    . ($module['description'] !== '' ? '<p class="text-ink/70">' . e($module['description']) . '</p>' : '')
    . '<p class="text-sm tabular-nums text-ink/60">' . e($progress) . ' · ' . e((string) $module['percent']) . '%</p>'
    . '<div><a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
    . ' href="/learn">К курсу</a></div>'
    . '</header>'
    . ($items === ''
        ? '<p class="rounded-xl border border-ink/10 bg-cream p-6 text-sm text-ink/70">В модуле пока нет опубликованных уроков.</p>'
        : '<ul class="space-y-3">' . $items . '</ul>')
    . '</section>';
?>
<?= $content /* @safe html */ ?>
