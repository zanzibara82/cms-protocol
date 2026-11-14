<?php

declare(strict_types=1);

// Массовые операции над страницами из списка конструктора. Каждая страница проходит обычную
// проверку существования, поэтому подделанный номер в POST не создаёт новую строку и не ломает
// остальные: операция применяется к тем, что ещё на месте. Удаление идёт через корзину.

/**
 * @param list<string> $ids
 * @return array{ok: bool, error: string, count: int}
 */
function pagesBulkStatus(array $ids, string $status): array
{
    if (!in_array($status, PAGES_STATUSES, true)) {
        return ['ok' => false, 'error' => 'Неизвестный статус.', 'count' => 0];
    }

    if ($ids === []) {
        return ['ok' => false, 'error' => 'Отметьте хотя бы одну страницу.', 'count' => 0];
    }

    $done = 0;
    $now = nowUtc();
    $moment = utcStamp($now);

    foreach ($ids as $id) {
        if (pageById($id) === null) {
            continue;
        }

        contentRevisionEnsureBaseline('page', $id, $now);

        $changed = tx(static function () use ($id, $status, $moment, $now): int {
            $meta = contentRevisionMeta(['operation' => 'bulk', 'source' => 'editor'], currentUser());
            contentRevisionGuard('page', $id, $meta, $now);
            $count = run(
                'UPDATE ' . t('page') . ' SET status = ?, updated_at = ? WHERE id = ?',
                [$status, $moment, $id]
            );
            contentRevisionCommit('page', $id, $meta, $now);

            return $count;
        });

        if ($changed === 0) {
            continue;
        }

        $done++;
        auditLog('page.bulk_status', 'page', $id, ['status' => $status], 'Статус страницы изменён массово');
    }

    if ($done === 0) {
        return ['ok' => false, 'error' => 'Ни одна отмеченная страница не найдена — обновите список.', 'count' => 0];
    }

    logInfo('pages.bulk_status', ['status' => $status, 'count' => $done]);

    return ['ok' => true, 'error' => '', 'count' => $done];
}

/**
 * @param list<string> $ids
 * @return array{ok: bool, error: string, count: int}
 */
function pagesBulkTrash(array $ids): array
{
    if ($ids === []) {
        return ['ok' => false, 'error' => 'Отметьте хотя бы одну страницу.', 'count' => 0];
    }

    $done = 0;

    foreach ($ids as $id) {
        $result = trashPage($id);

        if (($result['ok'] ?? false) === true) {
            $done++;
        }
    }

    if ($done === 0) {
        return ['ok' => false, 'error' => 'Ни одна отмеченная страница не найдена — обновите список.', 'count' => 0];
    }

    auditLog('page.bulk_trash', 'page', '', ['count' => $done], 'Убрано в корзину страниц: ' . $done);
    logInfo('pages.bulk_trash', ['count' => $done]);

    return ['ok' => true, 'error' => '', 'count' => $done];
}
