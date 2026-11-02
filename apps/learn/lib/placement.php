<?php

declare(strict_types=1);

// Оценка теста на уровень — порт placement.ts: балл по всем отвеченным вопросам,
// разбивка по четырём навыкам и выбор самого верхнего достигнутого уровня.

/**
 * @param list<array<string, mixed>> $questions
 * @param list<array<string, mixed>> $answers
 * @param list<string> $questionIds
 * @return array{score: int, correct_count: int, vocabulary_score: int|null, grammar_score: int|null, reading_score: int|null, listening_score: int|null}
 */
function learnPlacementScores(array $questions, array $answers, array $questionIds): array
{
    $byId = [];
    foreach ($questions as $index => $question) {
        $id = (string) ($questionIds[$index] ?? '');
        if ($id !== '') {
            $byId[$id] = $question;
        }
    }

    $stats = [];
    $correctCount = 0;

    foreach ($answers as $answer) {
        $questionId = (string) ($answer['question_id'] ?? $answer['questionId'] ?? '');
        if (!isset($byId[$questionId])) {
            continue;
        }

        $question = $byId[$questionId];
        $skill = (string) ($question['skill'] ?? '');
        if (!isset($stats[$skill])) {
            $stats[$skill] = ['right' => 0, 'total' => 0];
        }

        $stats[$skill]['total']++;
        $optionIndex = (int) ($answer['option_index'] ?? $answer['optionIndex'] ?? -1);
        $correctIndex = (int) ($question['correct'] ?? $question['correct_option_index'] ?? -1);
        if ($optionIndex === $correctIndex) {
            $stats[$skill]['right']++;
            $correctCount++;
        }
    }

    $total = 0;
    foreach ($stats as $stat) {
        $total += $stat['total'];
    }

    $percent = static function (array $stat): int {
        return (int) round($stat['right'] / $stat['total'] * 100);
    };

    return [
        'score' => $total > 0 ? (int) round($correctCount / $total * 100) : 0,
        'correct_count' => $correctCount,
        'vocabulary_score' => isset($stats['VOCABULARY']) ? $percent($stats['VOCABULARY']) : null,
        'grammar_score' => isset($stats['GRAMMAR']) ? $percent($stats['GRAMMAR']) : null,
        'reading_score' => isset($stats['READING']) ? $percent($stats['READING']) : null,
        'listening_score' => isset($stats['LISTENING']) ? $percent($stats['LISTENING']) : null,
    ];
}

// Порог берёт самый верхний уровень, который ученик достиг. C2 шкалой не ставится.
function learnDecideLevel(int $percent): string
{
    $recommended = LEARN_PLACEMENT_LEVELS[0]['code'];
    foreach (LEARN_PLACEMENT_LEVELS as $level) {
        if ($percent >= $level['min']) {
            $recommended = $level['code'];
        }
    }

    return $recommended;
}
