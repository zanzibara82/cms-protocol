<?php

declare(strict_types=1);

/**
 * Дерево текущего курса: уровень, модули и проценты.
 *
 * @var array{
 *     level: array{id: string, code: string, title: string, sort: int}|null,
 *     course: array{id: string, level_id: string, title: string, description: string, sort: int}|null,
 *     modules: list<array{id: string, title: string, description: string, sort: int, lessons: list<array{id: string, slug: string, title: string, sort: int, completed: bool, unlocked: bool}>, lesson_count: int, completed_count: int, percent: int}>
 * } $tree
 */
$course = $tree['course'];

if ($course === null) {
    $content = '<section class="space-y-6">'
        . partial('partials/breadcrumbs', ['items' => [
            ['label' => 'Главная', 'href' => '/'],
            ['label' => 'Курсы', 'href' => '/learn'],
        ]])
        . '<header class="space-y-2">'
        . '<h1 class="text-3xl font-bold">Курсы</h1>'
        . '</header>'
        . '<p class="rounded-xl border border-ink/10 bg-cream p-6 text-sm text-ink/70">'
        . 'Курс пока не назначен. Он появится, как только школа опубликует программу.'
        . '</p>'
        . '</section>';
} else {
    $levelCode = $tree['level'] === null ? '' : (string) $tree['level']['code'];
    $items = '';

    foreach ($tree['modules'] as $module) {
        $href = '/learn/module/' . rawurlencode($module['id']);
        $progress = (string) $module['completed_count'] . '/' . (string) $module['lesson_count'];

        $items .= '<li class="flex flex-wrap items-end justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">'
            . '<div class="min-w-0 space-y-1">'
            . '<p class="font-medium">Модуль ' . e((string) $module['sort']) . ': ' . e($module['title']) . '</p>'
            . '<p class="text-sm text-ink/60">' . e($module['description']) . '</p>'
            . '<p class="text-sm tabular-nums text-ink/70">' . e($progress) . ' · ' . e((string) $module['percent']) . '%</p>'
            . '</div>'
            . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
            . ' href="' . e($href) . '">Открыть модуль</a>'
            . '</li>';
    }

    $content = '<section class="space-y-6">'
        . partial('partials/breadcrumbs', ['items' => [
            ['label' => 'Главная', 'href' => '/'],
            ['label' => 'Курсы', 'href' => '/learn'],
        ]])
        . '<header class="space-y-2">'
        . '<h1 class="text-3xl font-bold">Курсы</h1>'
        . '<p class="text-ink/70">'
        . ($levelCode !== '' ? 'Уровень ' . e($levelCode) . ' · ' : '')
        . e($course['title'])
        . '</p>'
        . ($course['description'] !== ''
            ? '<p class="text-sm text-ink/60">' . e($course['description']) . '</p>'
            : '')
        . '</header>'
        . ($items === ''
            ? '<p class="rounded-xl border border-ink/10 bg-cream p-6 text-sm text-ink/70">'
                . 'В курсе пока нет опубликованных модулей.</p>'
            : '<ul class="space-y-3">' . $items . '</ul>')
        . '</section>';
}
?>
<?= $content /* @safe html */ ?>
