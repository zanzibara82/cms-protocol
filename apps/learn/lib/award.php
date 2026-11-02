<?php

declare(strict_types=1);

// Награда за урок — порт lesson-award.ts: кубок по итогу, короткий рассказ об успехах
// и один навык, который стоит подтянуть. Считается по ответам урока, поэтому живёт
// отдельно от экрана и переиспользуется кабинетом.

const LEARN_SKILL_HINTS = [
    'VOCABULARY' => 'Повторите слова урока и составьте с тремя из них свои предложения.',
    'GRAMMAR' => 'Перечитайте правило урока и выполните задания на него ещё раз.',
    'READING' => 'Прочитайте текст задания вслух и отметьте слова, которые вас остановили.',
    'LISTENING' => 'Слушайте запись по предложениям и повторяйте каждое вслух.',
    'WRITING' => 'Пишите ответ полным предложением и проверяйте окончание глагола.',
    'SPEAKING' => 'Проговорите фразы урока вслух три раза подряд.',
    'PRONUNCIATION' => 'Сверяйтесь с транскрипцией и повторяйте слово за озвучкой.',
];

const LEARN_SKILL_HINT_FALLBACK = 'Вернитесь к заданиям этого типа и разберите ошибку, из-за которой сорвался ответ.';

// Совет по навыку — один и тот же в награде за урок и в кабинете: человеку незачем
// получать два разных текста про одну и ту же слабую сторону.
function learnSkillAdvice(string $skill): string
{
    return LEARN_SKILL_HINTS[$skill] ?? LEARN_SKILL_HINT_FALLBACK;
}

/**
 * Сложение ответов урока по навыкам. Ответ без навыка не учитывается: похвалить
 * или поругать за него нечем.
 *
 * @param list<array<string, mixed>> $answers
 * @return list<array{skill: string, label: string, right: int, total: int}>
 */
function learnTallySkills(array $answers): array
{
    $tally = [];
    foreach ($answers as $answer) {
        $skill = (string) ($answer['skill'] ?? '');
        if ($skill === '') {
            continue;
        }

        if (!isset($tally[$skill])) {
            $tally[$skill] = [
                'skill' => $skill,
                'label' => LEARN_SKILL_LABELS[$skill] ?? $skill,
                'right' => 0,
                'total' => 0,
            ];
        }

        $tally[$skill]['total']++;
        if (($answer['correct'] ?? false) === true) {
            $tally[$skill]['right']++;
        }
    }

    return array_values($tally);
}

function learnShare(int $part, int $whole): float
{
    return $whole > 0 ? $part / $whole : 1.0;
}

/**
 * Слабый навык — самый низкий процент. При равной доле берём тот, где больше попыток:
 * вывод надёжнее. Идеальный навык слабым не объявляется.
 *
 * @param list<array<string, mixed>> $skills
 * @return array{label: string, right: int, total: int, hint: string}|null
 */
function learnPickFocus(array $skills): ?array
{
    $best = null;
    $bestShare = 0.0;
    $bestTotal = 0;
    foreach ($skills as $skill) {
        $right = (int) ($skill['right'] ?? 0);
        $total = (int) ($skill['total'] ?? 0);
        if ($total < 1 || $right === $total) {
            continue;
        }

        $share = learnShare($right, $total);
        if ($best === null || $share < $bestShare || ($share === $bestShare && $total > $bestTotal)) {
            $best = $skill;
            $bestShare = $share;
            $bestTotal = $total;
        }
    }

    if ($best === null) {
        return null;
    }

    return [
        'label' => (string) ($best['label'] ?? ''),
        'right' => (int) ($best['right'] ?? 0),
        'total' => (int) ($best['total'] ?? 0),
        'hint' => learnSkillAdvice((string) ($best['skill'] ?? '')),
    ];
}

/**
 * Сильная сторона имеет смысл, только если по навыку есть что показать: две попытки
 * и все верные. Один верный ответ объявлять победой — хвастовство без данных.
 *
 * @param list<array<string, mixed>> $skills
 * @return array{label: string, right: int, total: int}|null
 */
function learnPickStrength(array $skills, ?string $focusLabel): ?array
{
    $best = null;
    foreach ($skills as $skill) {
        $right = (int) ($skill['right'] ?? 0);
        $total = (int) ($skill['total'] ?? 0);
        if ($total < 2 || $right !== $total || (string) ($skill['label'] ?? '') === $focusLabel) {
            continue;
        }

        if ($best === null || $total > (int) ($best['total'] ?? 0)) {
            $best = $skill;
        }
    }

    if ($best === null) {
        return null;
    }

    return [
        'label' => (string) ($best['label'] ?? ''),
        'right' => (int) ($best['right'] ?? 0),
        'total' => (int) ($best['total'] ?? 0),
    ];
}

/**
 * Итог урока → награда. Пороги портала: gold при идеальном уроке либо точности ≥ 0.8
 * и тесте ≥ 80, silver при ≥ 0.6 и тесте ≥ 60, иначе bronze.
 *
 * @param array<string, mixed> $outcome
 * @return array{trophy: string, title: string, xp: int, wins: list<string>, focus: array{label: string, right: int, total: int, hint: string}|null, strength: array{label: string, right: int, total: int}|null}
 */
function learnAward(array $outcome): array
{
    $xp = (int) ($outcome['xp'] ?? 0);
    $right = (int) ($outcome['exercise_right'] ?? 0);
    $total = (int) ($outcome['exercise_total'] ?? 0);
    $testScore = $outcome['test_score'] ?? null;
    $testScore = $testScore === null ? null : (int) $testScore;
    $moduleCompleted = ($outcome['module_completed'] ?? false) === true;
    $streakDays = (int) ($outcome['streak_days'] ?? 0);

    $accuracy = $total > 0 ? $right / $total : 1.0;
    $flawless = $right === $total && ($testScore === null || $testScore === 100);

    if ($flawless) {
        $trophy = 'gold';
    } elseif ($accuracy >= 0.8 && ($testScore === null || $testScore >= 80)) {
        $trophy = 'gold';
    } elseif ($accuracy >= 0.6 && ($testScore === null || $testScore >= 60)) {
        $trophy = 'silver';
    } else {
        $trophy = 'bronze';
    }

    if ($trophy === 'bronze') {
        $title = 'Начало положено';
    } elseif ($trophy === 'silver') {
        $title = 'Ещё чуть-чуть до идеала';
    } else {
        $title = $flawless ? 'Ни одной ошибки' : 'Уверенный результат';
    }

    $wins = [];
    if ($total > 0) {
        $wins[] = 'Заданий решено верно: ' . $right . ' из ' . $total;
    }

    if ($testScore !== null) {
        $wins[] = 'Мини-тест урока: ' . $testScore . '%';
    }

    if ($moduleCompleted) {
        $wins[] = 'Модуль закрыт целиком';
    }

    if ($streakDays > 0) {
        $wins[] = 'Серия занятий: ' . $streakDays . ' ' . plural($streakDays, ['день', 'дня', 'дней']);
    }

    $skills = is_array($outcome['skills'] ?? null) ? array_values($outcome['skills']) : [];
    $focus = learnPickFocus($skills);
    $strength = learnPickStrength($skills, $focus['label'] ?? null);

    return [
        'trophy' => $trophy,
        'title' => $title,
        'xp' => $xp,
        'wins' => $wins,
        'focus' => $focus,
        'strength' => $strength,
    ];
}
