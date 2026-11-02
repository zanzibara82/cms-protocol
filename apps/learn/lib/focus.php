<?php

declare(strict_types=1);

// Персональные подсказки кабинета — порт dashboard-focus.ts: обращение по времени суток,
// реплика про серию, слабый и сильный навык. Все функции чистые: время и статистика
// приходят параметрами, поэтому вывод видно в тестах.

function learnDayGreeting(DateTimeImmutable $now): string
{
    $hour = utcHour($now);
    if ($hour >= 5 && $hour < 12) {
        return 'Доброе утро';
    }

    if ($hour >= 12 && $hour < 18) {
        return 'Добрый день';
    }

    if ($hour >= 18 && $hour < 23) {
        return 'Добрый вечер';
    }

    return 'Добрая ночь';
}

// today — занимался ли человек сегодня; дни серии берутся такими, как их посчитал
// движок, и пересчитывать их здесь незачем.
function learnStreakNudge(int $days, bool $today): string
{
    $word = plural($days, ['день', 'дня', 'дней']);
    if ($today) {
        return $days > 0
            ? 'Сегодняшнее занятие засчитано, серия — ' . $days . ' ' . $word . '.'
            : 'Сегодняшнее занятие засчитано.';
    }

    if ($days === 0) {
        return 'Серии пока нет — начнём её с одного урока сегодня.';
    }

    return 'Серия ' . $days . ' ' . $word . ' держится до полуночи: сегодня ещё ни одного занятия.';
}

// «Сегодня» — это UTC-день: дата из колонки DATE сравнивается с днём сервера.
function learnIsToday(?string $lastStudyAt, ?DateTimeImmutable $now = null): bool
{
    if ($lastStudyAt === null || $lastStudyAt === '') {
        return false;
    }

    $now ??= nowUtc();

    return substr($lastStudyAt, 0, 10) === utcDayKey($now);
}

/**
 * Слабый навык — самый низкий процент при достаточной выборке. При равном проценте
 * берём тот, где попыток больше: вывод надёжнее.
 *
 * @param list<array<string, mixed>> $stats
 * @return array<string, mixed>|null
 */
function learnWeakSkill(array $stats, int $minTotal = LEARN_SKILL_SAMPLE_MIN): ?array
{
    $best = null;
    $bestRate = 0.0;
    $bestTotal = 0;
    foreach ($stats as $stat) {
        $right = (int) ($stat['right'] ?? 0);
        $total = (int) ($stat['total'] ?? 0);
        if ($total < $minTotal || $right === $total) {
            continue;
        }

        $rate = $right / $total;
        if ($best === null || $rate < $bestRate || ($rate === $bestRate && $total > $bestTotal)) {
            $best = $stat;
            $bestRate = $rate;
            $bestTotal = $total;
        }
    }

    return $best;
}

/**
 * Сильная сторона: все ответы верны и их достаточно много. Пропуск принимается и кодом
 * навыка, и его подписью — кабинет называет слабый навык подписью.
 *
 * @param list<array<string, mixed>> $stats
 * @return array<string, mixed>|null
 */
function learnStrongSkill(array $stats, ?string $skip, int $minTotal = LEARN_SKILL_SAMPLE_MIN): ?array
{
    $best = null;
    foreach ($stats as $stat) {
        $right = (int) ($stat['right'] ?? 0);
        $total = (int) ($stat['total'] ?? 0);
        if ($total < $minTotal || $right !== $total) {
            continue;
        }

        if ($skip !== null
            && ((string) ($stat['skill'] ?? '') === $skip || (string) ($stat['label'] ?? '') === $skip)
        ) {
            continue;
        }

        if ($best === null || $total > (int) ($best['total'] ?? 0)) {
            $best = $stat;
        }
    }

    return $best;
}

/**
 * Что показать в кабинете про слабый навык: процент, совет и сколько данных за этим.
 *
 * @param array<string, mixed> $weak
 * @return array{label: string, right: int, percent: int, advice: string, total: int}
 */
function learnFocusOf(array $weak): array
{
    $right = (int) ($weak['right'] ?? 0);
    $total = (int) ($weak['total'] ?? 0);

    return [
        'label' => (string) ($weak['label'] ?? ''),
        'right' => $right,
        'percent' => $total > 0 ? (int) round($right / $total * 100) : 0,
        'advice' => learnSkillAdvice((string) ($weak['skill'] ?? '')),
        'total' => $total,
    ];
}
