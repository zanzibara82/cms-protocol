<?php

declare(strict_types=1);

/**
 * Карточка закрытого урока: содержимое не показывается, пока не закрыт предыдущий.
 *
 * @var array{
 *     lesson: array<string, mixed>,
 *     meta: array{module: array{id: string, title: string, sort: int, lesson_count: int}}
 * } $payload
 */
$module = $payload['meta']['module'];
$href = '/learn/module/' . rawurlencode($module['id']);
$lessonTitle = (string) ($payload['lesson']['title'] ?? '');

$content = '<section class="space-y-4">'
    . partial('partials/breadcrumbs', ['items' => [
        ['label' => 'Главная', 'href' => '/'],
        ['label' => 'Курсы', 'href' => '/learn'],
        ['label' => 'Модуль ' . (string) $module['sort'] . ': ' . (string) $module['title'], 'href' => $href],
        ['label' => $lessonTitle !== '' ? 'Урок: ' . $lessonTitle : 'Урок закрыт', 'href' => ''],
    ]])
    . '<header class="space-y-2">'
    . '<p class="text-sm text-ink/60">Модуль ' . e((string) $module['sort']) . ': ' . e($module['title']) . '</p>'
    . '<h1 class="text-3xl font-bold">Урок закрыт</h1>'
    . '</header>'
    . '<div class="space-y-3 rounded-xl border border-ink/10 bg-cream p-6">'
    . ($lessonTitle !== '' ? '<p class="font-medium">Урок «' . e($lessonTitle) . '»</p>' : '')
    . '<p class="text-sm text-ink/70">Сначала завершите предыдущий урок модуля.</p>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' href="' . e($href) . '">К списку уроков</a>'
    . '</div>'
    . '</section>';
?>
<?= $content /* @safe html */ ?>
