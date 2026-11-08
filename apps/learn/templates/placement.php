<?php

declare(strict_types=1);

/**
 * Тест на уровень: форма вопросов или экран результата после прохождения.
 *
 * @var array{id: string, title: string, pass_score: int} $test
 * @var list<array{id: string, skill: string, text: string, options: list<string>}> $questions
 * @var string $notice
 * @var array{score: int, vocabulary_score: int|null, grammar_score: int|null, reading_score: int|null, listening_score: int|null, level_code: string, level_title: string, start_module_id: string, questions: list<array<string, mixed>>, created_at: string}|null $result
 */
$questionField = static function (string $name, int $index, array $options): string {
    $html = '';
    foreach ($options as $optionIndex => $optionText) {
        $inputId = $name . '-' . $optionIndex;
        $html .= '<label class="flex min-h-[44px] cursor-pointer items-center gap-2" for="' . e($inputId) . '">'
            . '<input class="size-11 min-h-[44px]" type="radio" name="' . e($name) . '"'
            . ' id="' . e($inputId) . '" value="' . (string) $optionIndex . '" required>'
            . '<span class="text-sm text-ink">' . e((string) $optionText) . '</span>'
            . '</label>';
    }

    return $html;
};

$hasResult = $result !== null;

if ($hasResult) {
    $content = '<section class="mx-auto max-w-md space-y-6">'
        . partial('partials/breadcrumbs', ['items' => [
            ['label' => 'Главная', 'href' => '/'],
            ['label' => 'Тест на уровень', 'href' => ''],
        ]])
        . '<header class="space-y-2">'
        . '<h1 class="text-3xl font-bold">Тест на уровень</h1>'
        . '<p class="text-ink/70">' . e($notice) . '</p>'
        . '</header>'
        . '<div class="rounded-xl border border-ink/10 bg-white p-5 space-y-4">'
        . '<div class="space-y-2">'
        . '<span class="text-sm text-ink/60">Балл: ' . (string) $result['score'] . '%</span>'
        . '</div>'
        . '<div class="space-y-2">'
        . '<span class="text-sm text-ink/60">Уровень:</span>'
        . '<span class="text-xl font-bold text-ink">' . e($result['level_code']) . ' — ' . e($result['level_title']) . '</span>'
        . '</div>'
        . (($result['course_title'] ?? '') !== ''
            ? '<div class="space-y-2">'
            . '<span class="text-sm text-ink/60">Курс:</span>'
            . '<span class="text-xl font-bold text-ink">' . e($result['course_title']) . '</span>'
            . '</div>'
            : '')
        . '<div class="space-y-2 border-t border-ink/10 pt-4">'
        . '<p class="text-sm font-medium text-ink/80">Разбор по навыкам</p>'
        . '<div class="space-y-1">'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['VOCABULARY']) . ' — ' . (string) ($result['vocabulary_score'] ?? 0) . '%</span></div>'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['GRAMMAR']) . ' — ' . (string) ($result['grammar_score'] ?? 0) . '%</span></div>'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['READING']) . ' — ' . (string) ($result['reading_score'] ?? 0) . '%</span></div>'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['LISTENING']) . ' — ' . (string) ($result['listening_score'] ?? 0) . '%</span></div>'
        . '</div>'
        . '</div>'
        . '<div class="space-y-3 border-t border-ink/10 pt-4">'
        . '<p class="text-sm font-medium text-ink/80">Разбор ответов</p>';
    foreach ($result['questions'] as $question) {
        $content .= '<div class="space-y-1 text-sm">'
            . '<div class="flex justify-between"><span class="text-ink">' . e((string) $question['text']) . '</span>'
            . '<span class="text-xs ' . (($question['correct'] ?? false) ? 'text-green-700' : 'text-red-700') . ' font-medium">'
            . (($question['correct'] ?? false) ? 'Верно' : 'Неверно') . '</span></div>';
        if (($question['correct'] ?? false) !== true) {
            $content .= '<div class="text-ink/60">Правильный ответ: ' . e((string) (($question['options'][$question['correct_index']] ?? ''))) . '</div>';
        }
        if ((string) ($question['explanation'] ?? '') !== '') {
            $content .= '<div class="text-ink/50 text-xs italic">' . e((string) $question['explanation']) . '</div>';
        }
        $content .= '</div>';
    }
    $content .= '</div>'
        . '</div>'
        . '<div class="space-y-2">'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/dashboard">'
        . 'В кабинет'
        . '</a>'
        . '</div>'
        . '</section>';
} else {
    $content = '<section class="mx-auto max-w-md space-y-6">'
        . partial('partials/breadcrumbs', ['items' => [
            ['label' => 'Главная', 'href' => '/'],
            ['label' => 'Тест на уровень', 'href' => ''],
        ]])
        . '<header class="space-y-2">'
        . '<h1 class="text-3xl font-bold">Тест на уровень</h1>'
        . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
        . '<p class="text-ink/70">' . e($test['title']) . '. Пройдёт около десяти минут — по десять вопросов на лексику и грамматику, по пять на чтение и аудирование.</p>'
        . '</header>'
        . '<form class="space-y-6 rounded-xl border border-ink/10 bg-white p-5" method="post" action="/placement-test">'
        . csrfField();
    $questionNumber = 0;
    foreach ($questions as $question) {
        $questionNumber++;
        $name = 'answer[' . e((string) $question['id']) . ']';
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        $content .= '<div class="space-y-2 border-b border-ink/5 pb-4 last:border-0">'
            . '<div class="flex justify-between items-start">'
            . '<span class="text-sm font-medium text-ink">' . $questionNumber . '. ' . e((string) $question['text']) . '</span>'
            . '</div>'
            . $questionField($name, $questionNumber, $options)
            . '</div>';
    }
    $content .= '<button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
        . 'Завершить тест'
        . '</button>'
        . '</form>'
        . '</section>';
}
?>
<?= $content /* @safe html */ ?>
