<?php

declare(strict_types=1);

// Форматы черновиков упражнений: форма админки → JSON в колонках options/correct_answer
// и обратно. Форматы обязаны совпадать с теми, что читает learnGrade(): иначе сохранённое
// упражнение откроется, но ответ на него не проверится. Все отказы — по-русски и с причиной.

/** @return list<string> */
function learnLines(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/u', $text) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $out[] = $trimmed;
        }
    }

    return $out;
}

/** @param array<string, mixed> $draft */
function learnDraftText(array $draft, string $key): string
{
    $value = $draft[$key] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
}

/** @param array<string, mixed> $draft */
function learnDraftNumber(array $draft, string $key, int $default): int
{
    $value = $draft[$key] ?? null;
    if (!is_scalar($value) || !is_numeric($value)) {
        return $default;
    }

    return (int) $value;
}

/** @param mixed $value */
function learnJsonText(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Черновик формы → строка tbl_learn_exercise. Поля id/lesson_id добавляет операция записи:
 * здесь только содержимое, чтобы формат можно было проверить без базы.
 *
 * @param array<string, mixed> $draft
 * @return array{row: array<string, mixed>|null, error: string}
 */
function learnExerciseDraftToRow(array $draft, int $sort): array
{
    $type = learnDraftText($draft, 'type');
    $question = mb_substr(
        learnDraftText($draft, 'question'),
        0,
        fieldLimit(LEARN_FIELDS, 'question', 'max')
    );
    $explanation = mb_substr(
        learnDraftText($draft, 'explanation'),
        0,
        fieldLimit(LEARN_FIELDS, 'description', 'max')
    );
    $skill = learnDraftText($draft, 'skill');
    $difficulty = max(1, min(5, learnDraftNumber($draft, 'difficulty', 1)));
    $xpReward = max(0, min(100, learnDraftNumber($draft, 'xp_reward', 5)));
    $audioUrl = learnDraftText($draft, 'audio_url');
    $optionsText = learnDraftText($draft, 'options_text');
    $answerText = learnDraftText($draft, 'answer_text');
    $speakText = learnDraftText($draft, 'speak_text');

    if ($question === '') {
        return ['row' => null, 'error' => 'Вопрос упражнения пуст — напишите задание.'];
    }

    if ($skill !== '' && !in_array($skill, LEARN_SKILLS, true)) {
        return ['row' => null, 'error' => 'Навык «' . $skill . '» неизвестен — выберите из списка.'];
    }

    $options = null;
    $correct = '';

    switch ($type) {
        case 'MULTIPLE_CHOICE':
        case 'TIMED_QUIZ':
            $choices = learnLines($optionsText);
            if (count($choices) < 2) {
                return ['row' => null, 'error' => 'Нужно минимум два варианта ответа.'];
            }

            $index = learnDraftNumber($draft, 'answer_text', -1);
            if ($index < 0 || $index >= count($choices)) {
                return ['row' => null, 'error' => 'Верный вариант не выбран или выходит за список.'];
            }

            if ($type === 'TIMED_QUIZ') {
                $limit = learnDraftNumber($draft, 'time_limit_sec', 20);
                $limit = max(5, min(120, $limit));
                $options = learnJsonText(['choices' => $choices, 'timeLimitSec' => $limit]);
            } else {
                $options = learnJsonText($choices);
            }

            $correct = learnJsonText($index);
            break;

        case 'LISTENING_CHOICE':
            $choices = learnLines($optionsText);
            if ($speakText === '') {
                return ['row' => null, 'error' => 'Для аудирования нужен текст озвучки.'];
            }

            if (count($choices) < 2) {
                return ['row' => null, 'error' => 'Нужно минимум два варианта ответа.'];
            }

            $index = learnDraftNumber($draft, 'answer_text', -1);
            if ($index < 0 || $index >= count($choices)) {
                return ['row' => null, 'error' => 'Верный вариант не выбран или выходит за список.'];
            }

            $options = learnJsonText(['speak' => $speakText, 'choices' => $choices]);
            $correct = learnJsonText($index);
            break;

        case 'FILL_IN_THE_BLANK':
        case 'TRANSLATION':
            $accepted = learnLines($answerText);
            if ($accepted === []) {
                return ['row' => null, 'error' => 'Укажите хотя бы один принимаемый ответ.'];
            }

            $correct = learnJsonText($accepted);
            break;

        case 'SENTENCE_BUILDING':
            $words = preg_split('/\s+/u', $optionsText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($words) < 2) {
                return ['row' => null, 'error' => 'Для сборки предложения нужны минимум два слова.'];
            }

            $options = learnJsonText($words);
            $correct = learnJsonText(implode(' ', $words));
            break;

        case 'MATCHING':
            $pairs = [];
            $map = [];
            foreach (learnLines($optionsText) as $line) {
                $at = strpos($line, '=');
                if ($at === false) {
                    return ['row' => null, 'error' => 'Пары пишутся строками «слева = справа».'];
                }

                $left = trim(substr($line, 0, $at));
                $right = trim(substr($line, $at + 1));
                if ($left === '' || $right === '') {
                    return ['row' => null, 'error' => 'У пары пустая левая или правая часть.'];
                }

                if (array_key_exists($left, $map)) {
                    return ['row' => null, 'error' => 'Левая часть пары «' . $left . '» повторяется.'];
                }

                $pairs[] = ['left' => $left, 'right' => $right];
                $map[$left] = $right;
            }

            if (count($pairs) < 2) {
                return ['row' => null, 'error' => 'Для соединения нужны минимум две пары.'];
            }

            $options = learnJsonText(['pairs' => $pairs]);
            $correct = learnJsonText($map);
            break;

        case 'BRAINSTORM':
            $accepted = learnLines($optionsText);
            if (count($accepted) < 3) {
                return ['row' => null, 'error' => 'Для мозгового штурма нужно минимум три принимаемых слова.'];
            }

            $minCount = learnDraftNumber($draft, 'answer_text', 3);
            if ($minCount < 1) {
                return ['row' => null, 'error' => 'Сколько слов считать достаточным — число от 1.'];
            }

            if ($minCount > count($accepted)) {
                return ['row' => null, 'error' => 'Порог больше списка принимаемых слов.'];
            }

            $options = learnJsonText(['minCount' => $minCount]);
            $correct = learnJsonText($accepted);
            break;

        default:
            return ['row' => null, 'error' => 'Тип упражнения «' . $type . '» неизвестен.'];
    }

    return [
        'row' => [
            'type' => $type,
            'question' => $question,
            'options' => $options,
            'correct_answer' => $correct,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
            'skill' => $skill === '' ? null : $skill,
            'xp_reward' => $xpReward,
            'audio_url' => $audioUrl === '' ? null : $audioUrl,
            'sort' => $sort,
        ],
        'error' => '',
    ];
}

/**
 * Строка tbl_learn_exercise → черновик формы. Обратное чтение обязано быть без потерь:
 * сохранённое упражнение открывается в форме и сохраняется обратно тем же.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function learnExerciseRowToDraft(array $row): array
{
    $type = (string) ($row['type'] ?? '');
    $rawOptions = $row['options'] ?? null;
    $options = is_string($rawOptions) && $rawOptions !== '' ? json_decode($rawOptions, true) : null;
    $rawCorrect = $row['correct_answer'] ?? '';
    $correct = is_string($rawCorrect) && $rawCorrect !== '' ? json_decode($rawCorrect, true) : null;

    $draft = [
        'type' => $type,
        'question' => (string) ($row['question'] ?? ''),
        'explanation' => (string) ($row['explanation'] ?? ''),
        'skill' => (string) ($row['skill'] ?? ''),
        'difficulty' => (int) ($row['difficulty'] ?? 1),
        'xp_reward' => (int) ($row['xp_reward'] ?? 5),
        'options_text' => '',
        'answer_text' => '',
        'speak_text' => '',
        'time_limit_sec' => '',
        'audio_url' => (string) ($row['audio_url'] ?? ''),
    ];

    if ($type === 'MULTIPLE_CHOICE' || $type === 'LISTENING_CHOICE' || $type === 'TIMED_QUIZ') {
        $choices = [];
        if ($type === 'MULTIPLE_CHOICE' && is_array($options)) {
            $choices = $options;
        } elseif (is_array($options) && isset($options['choices']) && is_array($options['choices'])) {
            $choices = $options['choices'];
        }

        $draft['options_text'] = implode("\n", array_map(static fn (mixed $v): string => (string) $v, $choices));
        $draft['answer_text'] = is_int($correct) ? (string) $correct : (string) ($correct ?? '');
        if ($type === 'LISTENING_CHOICE') {
            $draft['speak_text'] = is_array($options) ? (string) ($options['speak'] ?? '') : '';
        }
        if ($type === 'TIMED_QUIZ') {
            $draft['time_limit_sec'] = is_array($options) ? (string) ($options['timeLimitSec'] ?? 20) : '20';
        }
    } elseif ($type === 'FILL_IN_THE_BLANK' || $type === 'TRANSLATION') {
        $accepted = is_array($correct) ? $correct : [];
        $draft['answer_text'] = implode("\n", array_map(static fn (mixed $v): string => (string) $v, $accepted));
    } elseif ($type === 'SENTENCE_BUILDING') {
        $words = is_array($options) ? $options : [];
        $draft['options_text'] = implode(' ', array_map(static fn (mixed $v): string => (string) $v, $words));
    } elseif ($type === 'MATCHING') {
        $pairs = [];
        if (is_array($options) && isset($options['pairs']) && is_array($options['pairs'])) {
            $pairs = $options['pairs'];
        } elseif (is_array($correct)) {
            foreach ($correct as $left => $right) {
                $pairs[] = ['left' => (string) $left, 'right' => (string) $right];
            }
        }

        $lines = [];
        foreach ($pairs as $pair) {
            if (is_array($pair)) {
                $lines[] = (string) ($pair['left'] ?? '') . ' = ' . (string) ($pair['right'] ?? '');
            }
        }

        $draft['options_text'] = implode("\n", $lines);
    } elseif ($type === 'BRAINSTORM') {
        $accepted = is_array($correct) ? $correct : [];
        $draft['options_text'] = implode("\n", array_map(static fn (mixed $v): string => (string) $v, $accepted));
        $draft['answer_text'] = is_array($options) ? (string) ($options['minCount'] ?? 3) : '3';
    }

    return $draft;
}

/**
 * Строки «английский :: русский» → примеры грамматики. Пустая правая часть не пишется:
 * форма различает пример без перевода и пример с пустым переводом.
 *
 * @return list<array{en: string, ru?: string}>
 */
function learnTextToGrammarExamples(string $text): array
{
    $out = [];
    foreach (learnLines($text) as $line) {
        $at = strpos($line, '::');
        if ($at === false) {
            $out[] = ['en' => $line];

            continue;
        }

        $en = trim(substr($line, 0, $at));
        $ru = trim(substr($line, $at + 2));
        if ($en === '') {
            continue;
        }

        $out[] = $ru === '' ? ['en' => $en] : ['en' => $en, 'ru' => $ru];
    }

    return $out;
}

/** @param list<array{en: string, ru?: string}> $examples */
function learnGrammarExamplesToText(array $examples): string
{
    $lines = [];
    foreach ($examples as $example) {
        $ru = (string) ($example['ru'] ?? '');
        $lines[] = $ru === '' ? $example['en'] : $example['en'] . ' :: ' . $ru;
    }

    return implode("\n", $lines);
}
