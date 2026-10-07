<?php

declare(strict_types=1);

// Точки расширения ядра. Приложение не правит core/: оно подписывается на хук. Порядок — по
// приоритету, внутри приоритета — по порядку регистрации (PHP не гарантирует устойчивость
// sort(), поэтому сортируем парой «приоритет + счётчик»).
/** @return array<string, list<array{fn: callable, priority: int, kind: string, order: int}>> */
function &hookRegistry(): array
{
    static $hooks = [];

    return $hooks;
}

function hookCounter(): int
{
    static $counter = 0;

    return ++$counter;
}

function addHook(string $hook, callable $fn, int $priority, string $kind): void
{
    $registry = &hookRegistry();
    $registry[$hook][] = ['fn' => $fn, 'priority' => $priority, 'kind' => $kind, 'order' => hookCounter()];
    usort($registry[$hook], static fn (array $a, array $b): int => [$a['priority'], $a['order']] <=> [$b['priority'], $b['order']]);
}

function addAction(string $hook, callable $fn, int $priority = 10): void
{
    addHook($hook, $fn, $priority, 'action');
}

function addFilter(string $hook, callable $fn, int $priority = 10): void
{
    addHook($hook, $fn, $priority, 'filter');
}

function doAction(string $hook, mixed ...$args): void
{
    $registry = &hookRegistry();
    foreach ($registry[$hook] ?? [] as $entry) {
        if ($entry['kind'] !== 'action') {
            continue;
        }
        ($entry['fn'])(...$args);
    }
}

function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
{
    $registry = &hookRegistry();
    foreach ($registry[$hook] ?? [] as $entry) {
        if ($entry['kind'] !== 'filter') {
            continue;
        }
        $value = ($entry['fn'])($value, ...$args);
    }

    return $value;
}

function hookCount(string $hook): int
{
    $registry = &hookRegistry();

    return count($registry[$hook] ?? []);
}

// Тесты подписываются на один и тот же хук десятки раз: без сброса они вызывали бы друг друга.
function hooksReset(): void
{
    $registry = &hookRegistry();
    $registry = [];
}
