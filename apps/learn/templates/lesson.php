<?php

declare(strict_types=1);

/**
 * Урок шагами: введение, слова, грамматика, упражнения, мини-тест и результат.
 * Правильные ответы в разметку не попадают — их отдаёт проверка после ответа.
 *
 * @var array{
 *     lesson: array{
 *         id: string, slug: string, title: string, description: string, sort: int,
 *         objectives: list<array{id: string, text: string, sort: int}>,
 *         vocabulary: list<array{id: string, word: string, translation: string, ipa: string, example_en: string, example_ru: string, sort: int}>,
 *         grammar: list<array{id: string, title: string, summary: string, details: string, examples: list<array{en: string, ru: string}>, sort: int}>,
 *         exercises: list<array<string, mixed>>,
 *         exercise_bank_size: int,
 *         test: array{id: string, title: string, pass_score: int, question_count: int}|null,
 *         test_questions: list<array{id: string, skill: string, text: string, options: list<string>, audio_url: string|null, sort: int}>
 *     },
 *     meta: array{
 *         unlocked: bool, completed: bool,
 *         module: array{id: string, title: string, sort: int, lesson_count: int},
 *         level_code: string, course_title: string,
 *         next_lesson: array{id: string, slug: string, title: string, sort: int}|null,
 *         done_count: int, total_exercises: int
 *     }
 * } $payload
 * @var string $step
 * @var list<string> $steps
 * @var array<string, mixed>|null $exercise
 * @var int $exerciseIndex
 * @var int $exerciseTotal
 * @var string $moduleUrl
 */
$lesson = $payload['lesson'];
$meta = $payload['meta'];
$module = $meta['module'];

$stepLabels = [
    'intro' => 'Введение',
    'vocab' => 'Слова',
    'grammar' => 'Грамматика',
    'exercises' => 'Упражнения',
    'test' => 'Тест',
    'result' => 'Результат',
];

$stepper = '';
foreach ($steps as $name) {
    $class = $name === $step
        ? 'rounded bg-ink px-2 py-1 text-xs font-medium text-cream'
        : 'rounded bg-ink/10 px-2 py-1 text-xs';
    $stepper .= '<span class="' . $class . '">' . e($stepLabels[$name] ?? $name) . '</span>';
}

$body = '';

if ($step === 'intro') {
    $objectives = '';
    foreach ($lesson['objectives'] as $objective) {
        $objectives .= '<li class="flex min-h-[44px] items-center gap-2">'
            . '<span aria-hidden="true">✓</span>'
            . '<span>' . e($objective['text']) . '</span>'
            . '</li>';
    }

    $body = '<div class="space-y-3">'
        . '<h2 class="text-xl font-semibold">Цели урока</h2>'
        . ($objectives === ''
            ? '<p class="text-sm text-ink/60">Цели урока пока не заполнены.</p>'
            : '<ul class="space-y-2">' . $objectives . '</ul>')
        . '</div>';
} elseif ($step === 'vocab') {
    $rows = '';
    foreach ($lesson['vocabulary'] as $word) {
        $rows .= '<tr class="border-b border-ink/10 align-top">'
            . '<td class="px-2 py-3">'
            . '<span class="font-medium">' . e($word['word']) . '</span>'
            . '<br><span class="text-sm text-ink/60">' . e($word['ipa']) . '</span>'
            . '</td>'
            . '<td class="px-2 py-3">' . e($word['translation']) . '</td>'
            . '<td class="px-2 py-3 text-sm">'
            . '<span>' . e($word['example_en']) . '</span>'
            . '<br><span class="text-ink/60">' . e($word['example_ru']) . '</span>'
            . '</td>'
            . '</tr>';
    }

    $body = '<div class="space-y-3">'
        . '<h2 class="text-xl font-semibold">Слова урока</h2>'
        . ($rows === ''
            ? '<p class="text-sm text-ink/60">Слова к этому уроку пока не добавлены.</p>'
            : '<div class="overflow-x-auto rounded-xl border border-ink/10 bg-white">'
                . '<table class="w-full text-left text-sm">'
                . '<thead class="border-b border-ink/10 text-xs uppercase tracking-wide text-ink/50">'
                . '<tr><th class="px-2 py-2">Слово</th><th class="px-2 py-2">Перевод</th><th class="px-2 py-2">Пример</th></tr>'
                . '</thead><tbody>' . $rows . '</tbody></table></div>')
        . '</div>';
} elseif ($step === 'grammar') {
    $cards = '';
    foreach ($lesson['grammar'] as $rule) {
        $examples = '';
        foreach ($rule['examples'] as $example) {
            $examples .= '<li class="min-h-[44px]">' . e($example['en'])
                . ($example['ru'] !== '' ? ' — ' . e($example['ru']) : '')
                . '</li>';
        }

        $cards .= '<article class="space-y-2 rounded-xl border border-ink/10 bg-white p-4">'
            . '<h2 class="text-xl font-semibold">' . e($rule['title']) . '</h2>'
            . '<p class="font-medium">' . e($rule['summary']) . '</p>'
            . ($rule['details'] !== '' ? '<p class="text-sm text-ink/70">' . e($rule['details']) . '</p>' : '')
            . ($examples !== '' ? '<ul class="space-y-1 text-sm">' . $examples . '</ul>' : '')
            . '</article>';
    }

    $body = '<div class="space-y-3">'
        . '<h2 class="text-xl font-semibold">Грамматика</h2>'
        . ($cards === ''
            ? '<p class="text-sm text-ink/60">Правил к этому уроку пока нет.</p>'
            : '<div class="space-y-3">' . $cards . '</div>')
        . '</div>';
} elseif ($step === 'exercises') {
    if ($exercise === null) {
        $body = '<div class="space-y-3">'
            . '<h2 class="text-xl font-semibold">Упражнения</h2>'
            . '<p class="text-sm text-ink/60">К этому уроку пока нет упражнений.</p>'
            . '</div>';
    } else {
        $position = 'Упражнение ' . (string) $exerciseIndex . ' из ' . (string) $exerciseTotal;
        $body = '<div class="space-y-4">'
            . '<p class="text-sm tabular-nums text-ink/60">' . e($position) . '</p>'
            . learnRenderExercise($exercise)
            . '<div><a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
            . ' href="' . e('/lesson/' . rawurlencode($lesson['slug']) . '?step=grammar') . '">К грамматике</a></div>'
            . '</div>';
    }
} elseif ($step === 'test') {
    $test = $lesson['test'];

    if ($test === null) {
        $body = '<div class="space-y-3">'
            . '<h2 class="text-xl font-semibold">Мини-тест урока</h2>'
            . '<p class="text-sm text-ink/60">У этого урока нет мини-теста.</p>'
            . '</div>';
    } else {
        $questions = '';
        foreach ($lesson['test_questions'] as $question) {
            $options = '';
            foreach ($question['options'] as $index => $option) {
                $inputId = 'test-' . $question['id'] . '-' . (string) $index;
                $options .= '<label class="flex min-h-[44px] items-center gap-3 rounded-lg border border-ink/10 p-3"'
                    . ' for="' . e($inputId) . '">'
                    . '<input class="size-11 min-h-[44px]" type="radio" id="' . e($inputId) . '"'
                    . ' name="' . e('answer[' . $question['id'] . ']') . '" value="' . e((string) $index) . '">'
                    . '<span>' . e($option) . '</span>'
                    . '</label>';
            }

            $questions .= '<fieldset class="space-y-2">'
                . '<legend class="font-medium">' . e($question['text']) . '</legend>'
                . $options
                . '</fieldset>';
        }

        $body = '<div class="space-y-4">'
            . '<h2 class="text-xl font-semibold">' . e($test['title']) . '</h2>'
            . '<form class="space-y-4" method="post" action="' . e('/lesson/' . rawurlencode($lesson['slug'])) . '">'
            . '<input type="hidden" name="op" value="test">'
            . csrfField()
            . $questions
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Проверить</button>'
            . '</form>'
            . '</div>';
    }
} else {
    $next = $meta['next_lesson'];
    $body = '<div class="space-y-3 rounded-xl border border-ink/10 bg-cream p-6">'
        . '<h2 class="text-xl font-semibold">Результат урока</h2>'
        . '<p>Пройдено упражнений: ' . e((string) $meta['done_count']) . ' из ' . e((string) $meta['total_exercises']) . '.</p>'
        . ($meta['completed']
            ? '<p class="font-medium">Урок засчитан.</p>'
            : '<p class="text-sm text-ink/60">Засчитайте урок, завершив упражнения и мини-тест.</p>')
        . ($next !== null
            ? '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
                . ' href="' . e('/lesson/' . rawurlencode($next['slug'])) . '">Следующий урок</a>'
            : '')
        . '</div>';
}

$content = '<section class="space-y-6">'
    . '<header class="space-y-2">'
    . '<p class="text-sm text-ink/60">' . e($meta['level_code']) . ' · Модуль ' . e((string) $module['sort']) . ': ' . e($module['title']) . '</p>'
    . '<h1 class="text-3xl font-bold">Урок ' . e((string) $lesson['sort']) . '. ' . e($lesson['title']) . '</h1>'
    . ($lesson['description'] !== '' ? '<p class="text-ink/70">' . e($lesson['description']) . '</p>' : '')
    . '<div class="flex flex-wrap gap-1">' . $stepper . '</div>'
    . '<div><a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
    . ' href="' . e($moduleUrl) . '">К модулю</a></div>'
    . '</header>'
    . $body
    . '</section>';
?>
<?= $content /* @safe html */ ?>
