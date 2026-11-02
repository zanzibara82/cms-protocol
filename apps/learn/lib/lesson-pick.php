<?php

declare(strict_types=1);

// Подбор упражнений на один заход — порт lesson-pick.ts. Начатые упражнения сохраняются,
// недостающие добираются seeded-перемешиванием; редкие типы резервируются по одному.
// Seed — строка (ученик + урок), поэтому набор одинаков при повторном открытии урока.

// 32-битное умножение как Math.imul: в PHP 64-битные числа не должны переполняться.
function learnImul32(int $a, int $b): int
{
    $a &= 0xFFFFFFFF;
    $b &= 0xFFFFFFFF;
    $al = $a & 0xFFFF;
    $ah = ($a >> 16) & 0xFFFF;
    $bl = $b & 0xFFFF;
    $bh = ($b >> 16) & 0xFFFF;

    $low = $al * $bl;
    $mid = (($ah * $bl) + ($al * $bh)) & 0xFFFF;

    return ($low + ($mid << 16)) & 0xFFFFFFFF;
}

/** @return callable(): float */
function learnMulberry32(int $seed): callable
{
    $a = $seed & 0xFFFFFFFF;

    return static function () use (&$a): float {
        $a = ($a + 0x6d2b79f5) & 0xFFFFFFFF;
        $t = $a;
        $t = learnImul32($t ^ ($t >> 15), $t | 1);
        $t = ($t ^ (($t + learnImul32($t >> 7, $t | 61)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $result = ($t ^ ($t >> 14)) & 0xFFFFFFFF;

        return $result / 4294967296;
    };
}

/**
 * @param list<array<string, mixed>> $all
 * @param list<string> $attemptedIds
 * @return list<array<string, mixed>>
 */
function learnPickExercises(array $all, array $attemptedIds, int $target, string $seed): array
{
    if ($target < 1) {
        return [];
    }

    $bySort = static function (array $a, array $b): int {
        $order = ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        if ($order !== 0) {
            return $order;
        }

        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    };

    if (count($all) <= $target) {
        $ordered = array_values($all);
        usort($ordered, $bySort);

        return $ordered;
    }

    $hash = 2166136261;
    $length = strlen($seed);
    for ($i = 0; $i < $length; $i++) {
        $hash = learnImul32($hash ^ ord($seed[$i]), 16777619);
    }

    $rnd = learnMulberry32($hash);
    $shuffle = static function (array $items) use ($rnd): array {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = (int) floor($rnd() * ($i + 1));
            $swap = $items[$i];
            $items[$i] = $items[$j];
            $items[$j] = $swap;
        }

        return $items;
    };

    $attempted = [];
    foreach ($attemptedIds as $id) {
        $attempted[(string) $id] = true;
    }

    $started = [];
    $fresh = [];
    foreach ($all as $exercise) {
        $id = (string) ($exercise['id'] ?? '');
        if (isset($attempted[$id])) {
            $started[] = $exercise;
        } else {
            $fresh[] = $exercise;
        }
    }

    usort($started, $bySort);
    $fresh = $shuffle($fresh);

    $taken = [];
    foreach ($started as $exercise) {
        $taken[(string) ($exercise['id'] ?? '')] = true;
    }

    $rest = [];
    foreach (LEARN_ONCE_PER_LESSON as $type) {
        if (count($started) + count($rest) >= $target) {
            break;
        }

        $already = false;
        foreach ($started as $exercise) {
            if ((string) ($exercise['type'] ?? '') === $type) {
                $already = true;
                break;
            }
        }

        if (!$already) {
            foreach ($rest as $exercise) {
                if ((string) ($exercise['type'] ?? '') === $type) {
                    $already = true;
                    break;
                }
            }
        }

        if ($already) {
            continue;
        }

        foreach ($fresh as $exercise) {
            $id = (string) ($exercise['id'] ?? '');
            if (isset($taken[$id]) || (string) ($exercise['type'] ?? '') !== $type) {
                continue;
            }

            $rest[] = $exercise;
            $taken[$id] = true;
            break;
        }
    }

    foreach ($fresh as $exercise) {
        if (count($started) + count($rest) >= $target) {
            break;
        }

        $id = (string) ($exercise['id'] ?? '');
        if (isset($taken[$id])) {
            continue;
        }

        $rest[] = $exercise;
        $taken[$id] = true;
    }

    usort($rest, static function (array $a, array $b): int {
        $difficulty = ((int) ($a['difficulty'] ?? 0)) <=> ((int) ($b['difficulty'] ?? 0));
        if ($difficulty !== 0) {
            return $difficulty;
        }

        $order = ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
        if ($order !== 0) {
            return $order;
        }

        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });

    return array_merge($started, $rest);
}
