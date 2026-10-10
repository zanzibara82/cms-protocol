<?php

declare(strict_types=1);

// Видимость материала — одно правило на весь код раздела. Черновик публикации не открывается
// строкой в базе: материал выпускает действие контент-менеджера, а срок публикации просто ещё
// не наступил. Публичная выборка обязана идти через visibleWhere() + visibleArgs().

/** @param array<string, mixed> $row */
function isOpen(array $row, DateTimeImmutable $now): bool
{
    if ((string) ($row['status'] ?? '') !== NEWS_STATUS['PUBLISHED']) {
        return false;
    }

    $raw = $row['published_at'] ?? null;
    $published = fromDb(is_string($raw) ? $raw : null);

    return $published !== null && $published <= $now;
}

// Строка условия собирается из словаря статусов, а не пишется текстом: единственная кавычка
// здесь — вокруг значения NEWS_STATUS, и оно приходит из константы, а не из запроса.
function visibleWhere(): string
{
    return "status = '" . NEWS_STATUS['PUBLISHED'] . "' AND published_at IS NOT NULL AND published_at <= ?";
}

/** Момент передаётся отдельным аргументом, чтобы вызывающий код не подставлял дату в SQL.
 *  @return list<string> */
function visibleArgs(DateTimeImmutable $now): array
{
    return [utcStamp($now)];
}

/**
 * Порядок везде один: закреплённые вперёд, затем по сроку. Страница в него не входит — при
 * порядке «закреплённые только на первой» OFFSET читается по одной последовательности, а строки
 * по другой: на стыке страниц материал теряется, а закреплённый показывается дважды. Закреплённые
 * идут первыми и так, поэтому со второй страницы они не поднимаются, пока их не больше
 * PINNED_WARN_LIMIT — вытеснение ленты считает tooManyPinned().
 */
function orderNews(): string
{
    return 'pinned DESC, published_at DESC';
}

/** Пора ли предупредить администратора, что закреплённые вытеснили ленту. */
function tooManyPinned(int $count): bool
{
    return $count > PINNED_WARN_LIMIT;
}

// «Ждут срока» — опубликованная строка, у которой момент публикации ещё не наступил:
// фильтр админки показывает её отдельно от черновиков (ключ `scheduled` из NEWS_FILTERS).
/** @param array<string, mixed> $row */
function isScheduled(array $row, DateTimeImmutable $now): bool
{
    return (string) ($row['status'] ?? '') === NEWS_STATUS['PUBLISHED'] && !isOpen($row, $now);
}
