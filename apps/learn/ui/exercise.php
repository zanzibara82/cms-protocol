<?php

declare(strict_types=1);

// Отрисовка упражнения по типу: одна функция на все формы, чтобы шаг урока и проверка
// попытки говорили одинаковой разметкой. Правильный ответ сюда не попадает — в массиве
// упражнения его нет, его отдаёт только проверка после ответа.

/**
 * Варианты выбора из публичной формы: список или объект с ключом `choices`
 * (так хранят аудирование и тест на время).
 *
 * @return list<string>
 */
function learnExerciseChoiceValues(mixed $options): array
{
    if (!is_array($options)) {
        return [];
    }

    $raw = array_key_exists('choices', $options) ? $options['choices'] : $options;
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $value) {
        if (is_scalar($value)) {
            $out[] = (string) $value;
        }
    }

    return $out;
}

/**
 * Пары соединения: `left` — слово, `right` — его перевод.
 *
 * @return list<array{left: string, right: string}>
 */
function learnExercisePairs(mixed $options): array
{
    if (!is_array($options) || !isset($options['pairs']) || !is_array($options['pairs'])) {
        return [];
    }

    $out = [];
    foreach ($options['pairs'] as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $left = (string) ($pair['left'] ?? '');
        $right = (string) ($pair['right'] ?? '');
        if ($left !== '' && $right !== '') {
            $out[] = ['left' => $left, 'right' => $right];
        }
    }

    return $out;
}

/**
 * Подсказки для сборки предложения: слова через пробел, без JS-чипов.
 *
 * @return list<string>
 */
function learnExerciseWordHints(mixed $options): array
{
    if (!is_array($options)) {
        return [];
    }

    $out = [];
    foreach ($options as $value) {
        if (is_scalar($value)) {
            $out[] = (string) $value;
        }
    }

    return $out;
}

/**
 * Правильный ответ для плашки попытки: у выбора — текст варианта, у пар — «слева — справа»,
 * у списков — через запятую. Пустая строка означает, что показывать нечего.
 *
 * @param array<string, mixed> $exercise
 */
function learnExerciseRevealText(array $exercise, mixed $revealed): string
{
    $type = (string) ($exercise['type'] ?? '');
    if (in_array($type, ['MULTIPLE_CHOICE', 'LISTENING_CHOICE', 'TIMED_QUIZ'], true)) {
        $choices = learnExerciseChoiceValues($exercise['options'] ?? null);
        $index = is_numeric($revealed) ? (int) $revealed : -1;
        if (isset($choices[$index])) {
            return $choices[$index];
        }
    }

    if (is_array($revealed)) {
        $parts = [];
        foreach ($revealed as $left => $right) {
            if (is_string($left)) {
                $parts[] = $left . ' — ' . (is_scalar($right) ? (string) $right : '');
            } elseif (is_scalar($right)) {
                $parts[] = (string) $right;
            }
        }

        return implode(', ', $parts);
    }

    return is_scalar($revealed) ? (string) $revealed : '';
}

/**
 * Форма упражнения и плашка попытки. После верного ответа поле замыкается: второй отправки
 * нет, «Дальше» рисует шаблон урока — функция не знает адреса следующего упражнения.
 *
 * @param array<string, mixed> $exercise
 * @param array<string, mixed> $attempt
 */
function learnRenderExercise(array $exercise, array $attempt = []): string
{
    $id = (string) ($exercise['id'] ?? '');
    $type = (string) ($exercise['type'] ?? '');
    $question = (string) ($exercise['question'] ?? '');
    $options = $exercise['options'] ?? null;
    $input = 'min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2';

    $correct = $attempt === [] ? null : ($attempt['correct'] ?? false) === true;
    $xp = (int) ($attempt['xp'] ?? 0);
    $locked = ($exercise['done'] ?? false) === true || $correct === true;

    $panel = '';
    if ($attempt !== []) {
        $panel = '<div class="space-y-2 rounded-xl border p-4 '
            . ($correct ? 'border-ink/10 bg-cream' : 'border-red-300 bg-red-50') . '">'
            . '<p class="font-medium ' . ($correct ? 'text-emerald-700' : 'text-red-700') . '">'
            . ($correct === true ? 'Верно' : 'Не верно')
            . ($xp > 0 ? ' · +' . (string) $xp . ' XP' : '')
            . '</p>';

        if ($correct !== true) {
            $revealed = learnExerciseRevealText($exercise, $attempt['revealed'] ?? null);
            if ($revealed !== '') {
                $panel .= '<p class="text-sm">Правильный ответ: ' . e($revealed) . '</p>';
            }

            $matched = $attempt['matched'] ?? null;
            $required = (int) ($attempt['required'] ?? 0);
            if (is_array($matched) && $required > 0) {
                $panel .= '<p class="text-sm">Совпало: ' . e((string) count($matched)) . ' из '
                    . e((string) $required) . '.</p>';
            }

            $explanation = (string) ($attempt['explanation'] ?? '');
            if ($explanation !== '') {
                $panel .= '<p class="text-sm">' . e($explanation) . '</p>';
            }
        }

        $panel .= '</div>';
    }

    if ($locked) {
        return $panel . '<p class="rounded-lg border border-ink/10 bg-cream p-3 text-sm">Ответ засчитан.</p>';
    }

    $fields = '';

    switch ($type) {
        case 'MULTIPLE_CHOICE':
        case 'LISTENING_CHOICE':
        case 'TIMED_QUIZ':
            $choices = learnExerciseChoiceValues($options);
            foreach ($choices as $index => $choice) {
                $inputId = 'answer-' . $id . '-' . (string) $index;
                $fields .= '<label class="flex min-h-[44px] items-center gap-3 rounded-lg border border-ink/10 p-3"'
                    . ' for="' . e($inputId) . '">'
                    . '<input class="size-11 min-h-[44px]" type="radio" id="' . e($inputId) . '"'
                    . ' name="answer" value="' . e((string) $index) . '">'
                    . '<span>' . e($choice) . '</span>'
                    . '</label>';
            }

            if ($type === 'LISTENING_CHOICE' && is_array($options) && is_string($options['speak'] ?? null)) {
                $fields = '<p class="rounded-lg border border-ink/10 bg-cream p-3 text-sm">'
                    . 'Озвучка: ' . e((string) $options['speak']) . '</p>' . $fields;
            }
            break;

        case 'FILL_IN_THE_BLANK':
        case 'TRANSLATION':
            $fields = '<input class="' . $input . '" type="text" name="answer" autocomplete="off">';
            break;

        case 'SENTENCE_BUILDING':
            $hints = learnExerciseWordHints($options);
            if ($hints !== []) {
                $fields .= '<p class="text-sm text-ink/60">Слова через пробел: ' . e(implode(' · ', $hints)) . '</p>';
            }

            $fields .= '<input class="' . $input . '" type="text" name="answer" autocomplete="off">';
            break;

        case 'MATCHING':
            $pairs = learnExercisePairs($options);
            $rights = [];
            foreach ($pairs as $pair) {
                $rights[$pair['right']] = true;
            }

            $rows = '';
            foreach ($pairs as $pair) {
                $field = '<select class="' . $input . '" name="' . e('answer[' . $pair['left'] . ']') . '">'
                    . '<option value="">Выберите перевод</option>';

                foreach (array_keys($rights) as $right) {
                    $field .= '<option value="' . e((string) $right) . '">' . e((string) $right) . '</option>';
                }

                $field .= '</select>';
                $rows .= '<li class="flex flex-wrap items-center gap-3">'
                    . '<span class="font-medium">' . e($pair['left']) . '</span>'
                    . $field
                    . '</li>';
            }

            $fields = '<ul class="space-y-3">' . $rows . '</ul>';
            break;

        case 'BRAINSTORM':
            $fields = '<textarea class="' . $input . '" name="answer" rows="4"></textarea>';
            break;

        default:
            $fields = '<input class="' . $input . '" type="text" name="answer" autocomplete="off">';
            break;
    }

    return $panel . '<form class="space-y-4" method="post" action="' . e(requestPath()) . '">'
        . '<input type="hidden" name="op" value="answer">'
        . '<input type="hidden" name="exercise" value="' . e($id) . '">'
        . csrfField()
        . '<p class="font-medium">' . e($question) . '</p>'
        . $fields
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Проверить</button>'
        . '</form>';
}
