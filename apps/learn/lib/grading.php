<?php

declare(strict_types=1);

// Проверка ответов ученика — порт grading.ts. Функции чистые: строка упражнения и ответ
// приходят параметрами, поэтому правила видны в unit-тестах и не зависят от базы.

/** @param mixed $raw */
function learnDecodeJson(mixed $raw): mixed
{
    if (!is_string($raw)) {
        return $raw;
    }

    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null && trim($raw) !== 'null') {
        return $raw;
    }

    return $decoded;
}

/** @param mixed $answer */
function learnAnswerText(mixed $answer): string
{
    return is_scalar($answer) ? (string) $answer : '';
}

// Схлопываем пробелы и обрезаем их до снятия конечных знаков: «London. » иначе не попадало
// под правило. Типографский апостроф приводим к обычному — с клавиатуры ученик ставит '.
function learnNormalizeAnswer(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['’', '‘'], "'", $text);
    $text = (string) preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    $text = (string) preg_replace('/[.,!?;:\'"«»\x{2013}\x{2014}-]+$/u', '', $text);

    return trim($text);
}

/**
 * Ответ на упражнение → верно/не верно и что показать как правильный ответ.
 *
 * @param array<string, mixed> $exercise
 * @param mixed $answer
 * @return array{correct: bool, revealed: mixed, matched?: list<string>, required?: int}
 */
function learnGrade(array $exercise, mixed $answer): array
{
    $type = (string) ($exercise['type'] ?? '');
    $rawCorrect = $exercise['correct_answer'] ?? '';
    $correct = learnDecodeJson($rawCorrect);

    switch ($type) {
        case 'MULTIPLE_CHOICE':
        case 'LISTENING_CHOICE':
        case 'TIMED_QUIZ':
            $correctIndex = (int) $correct;
            $index = -1;
            if ($answer !== null && $answer !== '' && !is_array($answer) && !is_object($answer) && is_numeric($answer)) {
                $index = (int) $answer;
            }

            return ['correct' => $index === $correctIndex, 'revealed' => $correctIndex];

        case 'FILL_IN_THE_BLANK':
        case 'TRANSLATION':
            $accepted = is_array($correct) ? array_values($correct) : [];
            $normalized = array_map(
                static fn (mixed $value): string => learnNormalizeAnswer(learnAnswerText($value)),
                $accepted
            );
            $given = learnNormalizeAnswer(learnAnswerText($answer));

            return ['correct' => in_array($given, $normalized, true), 'revealed' => $accepted];

        case 'SENTENCE_BUILDING':
            $canonical = learnNormalizeAnswer(learnAnswerText($correct));
            $given = learnNormalizeAnswer(learnAnswerText($answer));

            return ['correct' => $given === $canonical, 'revealed' => $canonical];

        case 'MATCHING':
            $expected = is_array($correct) ? $correct : [];
            $given = is_array($answer) ? $answer : [];
            $ok = count($expected) === count($given);
            foreach ($expected as $left => $right) {
                if (!array_key_exists($left, $given) || (string) $given[$left] !== (string) $right) {
                    $ok = false;
                    break;
                }
            }

            return ['correct' => $ok, 'revealed' => $expected];

        case 'BRAINSTORM':
            $options = learnDecodeJson($exercise['options'] ?? null);
            $minCount = is_array($options) ? (int) ($options['minCount'] ?? 0) : 0;
            if ($minCount < 1) {
                $minCount = 3;
            }

            $acceptedRaw = is_array($correct) ? array_values($correct) : [];
            $accepted = [];
            foreach ($acceptedRaw as $value) {
                $accepted[learnNormalizeAnswer(learnAnswerText($value))] = true;
            }

            $tokens = preg_split('/[\n,;]+/u', learnAnswerText($answer)) ?: [];
            $matched = [];
            $seen = [];
            foreach ($tokens as $token) {
                $normalized = learnNormalizeAnswer($token);
                if ($normalized === '') {
                    continue;
                }

                // Принимаем и единственное, и множественное число: apples ~ apple. Обе формы
                // одного слова считаем одним совпадением, иначе «apple, apples» давало бы два.
                $singular = str_ends_with($normalized, 's')
                    ? mb_substr($normalized, 0, mb_strlen($normalized, 'UTF-8') - 1, 'UTF-8')
                    : $normalized;
                if (isset($seen[$singular])) {
                    continue;
                }

                if (isset($accepted[$normalized]) || isset($accepted[$singular]) || isset($accepted[$normalized . 's'])) {
                    $seen[$singular] = true;
                    $matched[] = $normalized;
                }
            }

            return [
                'correct' => count($matched) >= $minCount,
                'revealed' => $acceptedRaw,
                'matched' => $matched,
                'required' => $minCount,
            ];

        default:
            return ['correct' => false, 'revealed' => ''];
    }
}

/**
 * Публичная форма упражнения: без правильного ответа, объяснения и сложности — их нельзя
 * отдавать в разметку до попытки.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function learnPublicExercise(array $row): array
{
    $rawOptions = $row['options'] ?? null;
    $options = is_string($rawOptions) && $rawOptions !== '' ? json_decode($rawOptions, true) : $rawOptions;

    return [
        'id' => (string) ($row['id'] ?? ''),
        'type' => (string) ($row['type'] ?? ''),
        'question' => (string) ($row['question'] ?? ''),
        'options' => $options,
        'audio_url' => $row['audio_url'] ?? null,
        'xp_reward' => (int) ($row['xp_reward'] ?? 0),
        'sort' => (int) ($row['sort'] ?? 0),
        'skill' => $row['skill'] ?? null,
    ];
}
