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
 *         module_all_done: bool, module_completed: bool, completed_test_score: int|null,
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
 * @var array<string, mixed>|null $attempt
 * @var array<string, mixed>|null $testResult
 * @var array<string, mixed>|null $award
 * @var string $nextExerciseUrl
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
        $locked = ($exercise['done'] ?? false) === true || (($attempt['correct'] ?? false) === true);
        $body = '<div class="space-y-4">'
            . '<p class="text-sm tabular-nums text-ink/60">' . e($position) . '</p>'
            . learnRenderExercise($exercise, $attempt ?? [])
            . '<div class="flex flex-wrap gap-2">'
            . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
            . ' href="' . e('/lesson/' . rawurlencode($lesson['slug']) . '?step=grammar') . '">К грамматике</a>'
            . ($locked && $nextExerciseUrl !== ''
                ? '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
                    . ' href="' . e($nextExerciseUrl) . '">Дальше</a>'
                : '')
            . '</div>'
            . '</div>';
    }
} elseif ($step === 'test') {
    $test = $lesson['test'];

    if ($test === null) {
        $body = '<div class="space-y-3">'
            . '<h2 class="text-xl font-semibold">Мини-тест урока</h2>'
            . '<p class="text-sm text-ink/60">У этого урока нет мини-теста.</p>'
            . '</div>';
    } elseif ($testResult !== null) {
        $score = (int) ($testResult['score'] ?? 0);
        $passed = ($testResult['passed'] ?? false) === true;
        $passScore = (int) ($testResult['pass_score'] ?? 0);
        $xp = (int) ($testResult['xp'] ?? 0);
        $moduleXp = (int) ($testResult['module_xp'] ?? 0);
        $alreadyCompleted = ($testResult['already_completed'] ?? false) === true;

        $detailRows = '';
        $details = $testResult['details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }

                $options = [];
                $rawOptions = $detail['options'] ?? null;
                if (is_array($rawOptions)) {
                    foreach ($rawOptions as $option) {
                        if (is_scalar($option)) {
                            $options[] = (string) $option;
                        }
                    }
                }

                $selected = (int) ($detail['selected'] ?? -1);
                $correctIndex = (int) ($detail['correct_index'] ?? -1);
                $isCorrect = ($detail['correct'] ?? false) === true;
                $selectedText = isset($options[$selected]) ? $options[$selected] : 'Нет ответа';
                $correctText = isset($options[$correctIndex]) ? $options[$correctIndex] : '';
                $explanation = (string) ($detail['explanation'] ?? '');

                $detailRows .= '<article class="space-y-1 rounded-xl border border-ink/10 bg-white p-4">'
                    . '<p class="font-medium">' . e((string) ($detail['question'] ?? '')) . '</p>'
                    . '<p class="text-sm font-medium ' . ($isCorrect ? 'text-emerald-700' : 'text-red-700') . '">'
                    . ($isCorrect ? 'Верно' : 'Не верно') . '</p>'
                    . '<p class="text-sm">Ваш ответ: ' . e($selectedText) . '</p>'
                    . (!$isCorrect && $correctText !== ''
                        ? '<p class="text-sm">Правильный ответ: ' . e($correctText) . '</p>'
                        : '')
                    . ($explanation !== '' ? '<p class="text-sm text-ink/60">' . e($explanation) . '</p>' : '')
                    . '</article>';
            }
        }

        $body = '<div class="space-y-4">'
            . '<div class="space-y-2 rounded-xl border border-ink/10 bg-cream p-6">'
            . '<h2 class="text-xl font-semibold">' . e($test['title']) . '</h2>'
            . '<p class="text-3xl font-bold tabular-nums">' . e((string) $score) . '%</p>'
            . '<p class="font-medium ' . ($passed ? 'text-emerald-700' : 'text-red-700') . '">'
            . ($passed ? 'Тест сдан' : 'Тест не сдан') . '</p>'
            . '<p class="text-sm text-ink/60">Порог: ' . e((string) $passScore) . '%.</p>'
            . ($xp > 0 ? '<p>Начислено: +' . e((string) $xp) . ' XP</p>' : '')
            . ($moduleXp > 0 ? '<p>Бонус модуля: +' . e((string) $moduleXp) . ' XP</p>' : '')
            . ($alreadyCompleted
                ? '<p class="text-sm text-ink/60">Повторная сдача урока опыт не начисляет.</p>'
                : '')
            . '<div class="flex flex-wrap gap-2">'
            . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
            . ' href="?step=result">К результату урока</a>'
            . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
            . ' href="?step=test">Пройти заново</a>'
            . '</div>'
            . '</div>'
            . ($detailRows !== '' ? '<div class="space-y-3">' . $detailRows . '</div>' : '')
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
    $awardHtml = '';

    if ($award !== null) {
        $trophy = (string) ($award['trophy'] ?? 'bronze');
        $trophyLabel = match ($trophy) {
            'gold' => 'Золотой кубок',
            'silver' => 'Серебряный кубок',
            default => 'Бронзовый кубок',
        };
        $trophyTone = match ($trophy) {
            'gold' => 'text-amber-700',
            'silver' => 'text-ink/70',
            default => 'text-ink/60',
        };

        $wins = '';
        $winsRaw = $award['wins'] ?? null;
        if (is_array($winsRaw)) {
            foreach ($winsRaw as $win) {
                if (is_scalar($win)) {
                    $wins .= '<li class="flex min-h-[44px] items-center gap-2"><span aria-hidden="true">✓</span>'
                        . '<span>' . e((string) $win) . '</span></li>';
                }
            }
        }

        $focusHtml = '<p class="text-sm text-ink/60">Слабого места не видно: все навыки урока закрыты верно.</p>';
        $focus = $award['focus'] ?? null;
        if (is_array($focus)) {
            $focusHtml = '<div class="space-y-1 rounded-lg border border-ink/10 bg-cream p-3">'
                . '<p class="font-medium">На что обратить внимание: ' . e((string) ($focus['label'] ?? '')) . '</p>'
                . '<p class="text-sm">Верно ' . e((string) (int) ($focus['right'] ?? 0)) . ' из '
                . e((string) (int) ($focus['total'] ?? 0)) . '.</p>'
                . '<p class="text-sm text-ink/60">' . e((string) ($focus['hint'] ?? '')) . '</p>'
                . '</div>';
        }

        $strengthHtml = '';
        $strength = $award['strength'] ?? null;
        if (is_array($strength)) {
            $strengthHtml = '<p class="text-sm text-emerald-700">Сильная сторона: '
                . e((string) ($strength['label'] ?? '')) . ' — '
                . e((string) (int) ($strength['right'] ?? 0)) . ' из '
                . e((string) (int) ($strength['total'] ?? 0)) . '.</p>';
        }

        $awardHtml = '<div class="space-y-3 rounded-xl border border-ink/10 bg-white p-4">'
            . '<p class="font-medium ' . $trophyTone . '">' . e($trophyLabel) . ' · '
            . e((string) ($award['title'] ?? '')) . '</p>'
            . '<p>Начислено за урок: +' . e((string) (int) ($award['xp'] ?? 0)) . ' XP</p>'
            . ($wins !== '' ? '<ul class="space-y-1 text-sm">' . $wins . '</ul>' : '')
            . $focusHtml
            . $strengthHtml
            . '</div>';
    }

    $body = '<div class="space-y-3 rounded-xl border border-ink/10 bg-cream p-6">'
        . '<h2 class="text-xl font-semibold">Результат урока</h2>'
        . '<p>Пройдено упражнений: ' . e((string) $meta['done_count']) . ' из ' . e((string) $meta['total_exercises']) . '.</p>'
        . ($meta['completed']
            ? '<p class="font-medium">Урок засчитан.</p>'
            : '<p class="text-sm text-ink/60">Засчитайте урок, завершив упражнения и мини-тест.</p>')
        . ($meta['module_completed'] ? '<p class="font-medium">Модуль закрыт целиком.</p>' : '')
        . $awardHtml
        . ($next !== null
            ? '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
                . ' href="' . e('/lesson/' . rawurlencode($next['slug'])) . '">Следующий урок</a>'
            : '')
        . '</div>';
}

$content = '<section class="space-y-6">'
    . partial('partials/breadcrumbs', ['items' => [
        ['label' => 'Главная', 'href' => '/'],
        ['label' => 'Курсы', 'href' => '/learn'],
        ['label' => 'Модуль ' . (string) $module['sort'] . ': ' . (string) $module['title'], 'href' => $moduleUrl],
        ['label' => 'Урок ' . (string) $lesson['sort'] . '. ' . (string) $lesson['title'], 'href' => ''],
    ]])
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
