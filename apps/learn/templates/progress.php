<?php

declare(strict_types=1);

/**
 * Прогресс ученика: XP, серия, навыки полосами, история тестов LIMIT 10.
 *
 * @var array{
 *     greeting: string,
 *     streak: array{days: int, today: bool, nudge: string},
 *     stats: array{xp: int, streak: int, lessons: int, tests: int, exercises: int},
 *     weak: array{label: string, right: int, percent: int, advice: string, total: int}|null,
 *     strong: array{skill: string, label: string, right: int, total: int}|null,
 *     currentModule: array<string, mixed>|null,
 *     modulePercent: int,
 *     nextLesson: array<string, mixed>|null
 * } $summary
 * @var list<array{skill: string, label: string, right: int, total: int}> $skillStats
 * @var list<array<string, mixed>> $testHistory
 */
$stats = $summary['stats'];
$streak = $summary['streak'];

$content = '<section class="space-y-6">'
    . partial('partials/breadcrumbs', ['items' => [
        ['label' => 'Главная', 'href' => '/'],
        ['label' => 'Кабинет', 'href' => '/dashboard'],
        ['label' => 'Прогресс', 'href' => ''],
    ]])
    . '<header class="space-y-2">'
    . '<p class="text-sm text-ink/60">' . e($summary['greeting']) . '</p>'
    . '<h1 class="text-3xl font-bold">Прогресс</h1>'
    . '</header>'
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
    . '<span data-stat="lessons">' . (string) $stats['lessons'] . '</span> / '
    . '<span data-stat="tests">' . (string) $stats['tests'] . '</span> / '
    . '<span data-stat="exercises">' . (string) $stats['exercises'] . '</span>'
    . '</p>'
    . '</div>'
    . '</div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4 space-y-4">'
    . '<p class="text-sm font-medium text-ink/80">Навыки</p>'
    . '<div class="space-y-3">';
foreach ($skillStats as $stat) {
    $label = (string) ($stat['label'] ?? '');
    $right = (int) ($stat['right'] ?? 0);
    $total = (int) ($stat['total'] ?? 0);
    $percent = $total > 0 ? (int) round($right / $total * 100) : 0;
    if ($total === 0) {
        $content .= '<div class="flex items-center justify-between gap-3 text-sm">'
            . '<span class="text-ink/70">' . e($label) . '</span>'
            . '<span class="text-ink/60">нет данных</span>'
            . '</div>';
    } else {
        $content .= '<div class="flex items-center justify-between gap-3 text-sm">'
            . '<span class="text-ink/70">' . e($label) . '</span>'
            . '<span class="text-ink">' . $right . ' из ' . $total . ' · ' . $percent . '%</span>'
            . '</div>';
    }
}
$content .= '</div>'
    . '</div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4 space-y-4">'
    . '<p class="text-sm font-medium text-ink/80">История попыток тестов</p>';
if ($testHistory === []) {
    $content .= '<p class="text-sm text-ink/60">Попыток пока нет.</p>';
} else {
    $content .= '<table class="w-full text-sm border-collapse">'
        . '<thead><tr class="text-left text-ink/60 border-b border-ink/10">'
        . '<th class="pb-2">Когда</th>'
        . '<th class="pb-2 text-right">Балл</th>'
        . '<th class="pb-2 text-right">Лексика</th>'
        . '<th class="pb-2 text-right">Грамматика</th>'
        . '<th class="pb-2 text-right">Чтение</th>'
        . '<th class="pb-2 text-right">Аудирование</th>'
        . '</tr></thead>'
        . '<tbody class="text-ink space-y-1">';
    foreach ($testHistory as $attempt) {
        $score = (int) ($attempt['score'] ?? 0);
        $voc = $attempt['vocabulary_score'] === null ? '—' : (string) $attempt['vocabulary_score'] . '%';
        $gram = $attempt['grammar_score'] === null ? '—' : (string) $attempt['grammar_score'] . '%';
        $read = $attempt['reading_score'] === null ? '—' : (string) $attempt['reading_score'] . '%';
        $list = $attempt['listening_score'] === null ? '—' : (string) $attempt['listening_score'] . '%';
        $content .= '<tr class="border-b border-ink/5" data-attempt="1">'
            . '<td class="py-2 text-ink/60">' . e(substr((string) ($attempt['created_at'] ?? ''), 0, 16)) . '</td>'
            . '<td class="py-2 text-right text-ink font-medium text-right">' . $score . '%</td>'
            . '<td class="py-2 text-right text-ink/70">' . e($voc) . '</td>'
            . '<td class="py-2 text-right text-ink/70">' . e($gram) . '</td>'
            . '<td class="py-2 text-right text-ink/70">' . e($read) . '</td>'
            . '<td class="py-2 text-right text-ink/70">' . e($list) . '</td>'
            . '</tr>';
    }
    $content .= '</tbody></table>';
}
$content .= '</div>'
    . '</section>';
?>
<?= $content /* @safe html */ ?>
