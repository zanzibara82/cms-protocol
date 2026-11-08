<?php

declare(strict_types=1);

/**
 * Кабинет ученика: приветствие, серия, навыки, статистика, текущий модуль.
 *
 * @var array{
 *     greeting: string,
 *     streak: array{days: int, today: bool, nudge: string},
 *     stats: array{xp: int, streak: int, lessons: int, tests: int, exercises: int},
 *     weak: array{label: string, right: int, percent: int, advice: string, total: int}|null,
 *     strong: array{skill: string, label: string, right: int, total: int}|null,
 *     currentModule: array<string, mixed>|null,
 *     modulePercent: int,
 *     nextLesson: array<string, mixed>|null,
 *     courseTitle: string
 * } $summary
 */
$stats = $summary['stats'];
$streak = $summary['streak'];
$weak = $summary['weak'];
$strong = $summary['strong'];
$currentModule = $summary['currentModule'];
$nextLesson = $summary['nextLesson'];

$streakLine = $streak['days'] > 0 && $streak['today']
    ? $streak['nudge']
    : 'Серии пока нет. Начните с первого урока, и серия пойдёт.';

$weakCard = '';
if ($weak !== null) {
    $weakCard = '<div class="rounded-xl border border-ink/10 bg-white p-4 space-y-2">'
        . '<p class="text-sm font-medium text-ink/80">На что обратить внимание</p>'
        . '<div class="flex items-baseline gap-2"><span class="text-xl font-bold text-ink">' . (string) $weak['percent'] . '%</span><span class="text-sm text-ink/60">верных в ' . (string) $weak['total'] . ' попыток</span></div>'
        . '<p class="text-sm text-ink/70">' . e((string) $weak['label']) . ': ' . e((string) $weak['advice']) . '</p>'
        . '</div>';
} elseif ($strong !== null) {
    $weakCard = '<div class="rounded-xl border border-ink/10 bg-accent/10 p-4 space-y-2">'
        . '<p class="text-sm font-medium text-ink/80">Слабого места не видно — держите марку!</p>'
        . '<p class="text-sm text-ink/70">Сильный навык: ' . e((string) ($strong['label'] ?? '')) . ' — '
        . (string) $strong['right'] . ' из ' . (string) $strong['total'] . ' верно.</p>'
        . '</div>';
} else {
    $weakCard = '<div class="rounded-xl border border-ink/10 bg-accent/10 p-4 space-y-2">'
        . '<p class="text-sm font-medium text-ink/80">Слабого места не видно — ещё недостаточно данных.</p>'
        . '<p class="text-sm text-ink/70">Пройдите несколько уроков, и кабинет покажет, на что обратить внимание.</p>'
        . '</div>';
}

$moduleBlock = '';
if ($currentModule !== null && $nextLesson !== null) {
    $moduleBlock = '<div class="rounded-xl border border-ink/10 bg-white p-4 space-y-2">'
        . '<p class="text-sm font-medium text-ink/80">Текущий модуль</p>'
        . '<p class="text-sm text-ink">Модуль ' . (string) $currentModule['sort'] . ': ' . e((string) $currentModule['title']) . '</p>'
        . '<p class="text-sm tabular-nums text-ink/60">' . (string) $summary['modulePercent'] . '% модуля пройдено</p>'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
        . ' href="/lesson/' . e(rawurlencode((string) ($nextLesson['slug'] ?? ''))) . '">'
        . 'Продолжить урок'
        . '</a>'
        . '</div>';
} elseif ($currentModule !== null) {
    $moduleBlock = '<div class="rounded-xl border border-ink/10 bg-white p-4 space-y-2">'
        . '<p class="text-sm font-medium text-ink/80">Текущий модуль</p>'
        . '<p class="text-sm text-ink">Модуль ' . (string) $currentModule['sort'] . ': ' . e((string) $currentModule['title']) . '</p>'
        . '<p class="text-sm tabular-nums text-ink/60">' . (string) $summary['modulePercent'] . '% модуля пройдено</p>'
        . '<p class="text-sm text-ink/60">Все уроки модуля пройдены — переходите к следующему.</p>'
        . '</div>';
}

$content = '<section class="space-y-6">'
    . partial('partials/breadcrumbs', ['items' => [
        ['label' => 'Главная', 'href' => '/'],
        ['label' => 'Кабинет', 'href' => '/dashboard'],
    ]])
    . '<header class="space-y-2">'
    . '<p class="text-sm text-ink/60">' . e($summary['greeting']) . '</p>'
    . ($summary['courseTitle'] !== '' ? '<p class="text-sm text-ink/70">Курс: ' . e($summary['courseTitle']) . '</p>' : '')
    . '<h1 class="text-3xl font-bold">Кабинет</h1>'
    . '</header>'
    . $weakCard
    . '<div class="grid grid-cols-1 md:grid-cols-3 gap-4">'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Опыт</p>'
    . '<p class="text-2xl font-bold text-ink">' . (string) $stats['xp'] . ' XP</p>'
    . '</div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Серия</p>'
    . '<p class="text-2xl font-bold text-ink">' . (string) $stats['streak'] . ' ' . plural((int) $stats['streak'], ['день', 'дня', 'дней']) . '</p>'
    . '</div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Уроков / тестов / упражнений</p>'
    . '<p class="text-2xl font-bold text-ink tabular-nums">'
    . (string) $stats['lessons'] . ' / ' . (string) $stats['tests'] . ' / ' . (string) $stats['exercises']
    . '</p>'
    . '</div>'
    . '</div>'
    . '<p class="text-sm text-ink/60">' . e($streakLine) . '</p>'
    . $moduleBlock
    . '</section>';
?>
<?= $content /* @safe html */ ?>
