<?php

declare(strict_types=1);

// Журнал действий редакции. Пишется из обработчиков и сервисов, читается экраном /admin/audit.
// Журнал не должен ломать само действие: если таблицы ещё нет (миграция не применена) или запись
// сорвалась, причина уходит в журнал ошибок, а человек продолжает работу.

const AUDIT_PAGE_SIZE = 50;

/**
 * @param array<string, mixed> $details
 */
function auditLog(string $action, string $entity = '', string $entityId = '', array $details = [], string $summary = ''): void
{
    $user = currentUser();
    $userId = is_array($user) ? (string) $user['id'] : null;
    $email = is_array($user) ? (string) $user['email'] : '';

    $encoded = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        insert('audit_log', [
            'user_id' => $userId,
            'user_email' => mb_substr($email, 0, 190),
            'action' => mb_substr($action, 0, 64),
            'entity' => mb_substr($entity, 0, 32),
            'entity_id' => mb_substr($entityId, 0, 64),
            'summary' => mb_substr($summary, 0, 255),
            'details' => is_string($encoded) ? $encoded : null,
            'ip' => mb_substr(clientIp(), 0, 45),
            'created_at' => utcStamp(nowUtc()),
        ]);
    } catch (Throwable $error) {
        logWarn('audit.write_failed', ['action' => $action, 'message' => $error->getMessage()]);
    }
}

/**
 * Страница журнала: новые сверху, необязательный поиск по действию, сущности, сводке и почте.
 *
 * @return array{rows: list<array<string, mixed>>, total: int, pages: int}
 */
function auditPage(int $page, string $query = ''): array
{
    $where = '';
    $args = [];
    $q = trim($query);

    if ($q !== '') {
        $like = '%' . escapeLike($q) . '%';
        $where = ' WHERE action LIKE ? ESCAPE ? OR entity LIKE ? ESCAPE ? OR summary LIKE ? ESCAPE ? OR user_email LIKE ? ESCAPE ?';
        $args = [$like, '\\', $like, '\\', $like, '\\', $like, '\\'];
    }

    $total = (int) value('SELECT COUNT(*) FROM ' . t('audit_log') . $where, $args);
    $pages = max(1, (int) ceil($total / AUDIT_PAGE_SIZE));
    $current = min(max(1, $page), $pages);

    $rows = all(
        'SELECT id, user_id, user_email, action, entity, entity_id, summary, details, ip, created_at'
        . ' FROM ' . t('audit_log') . $where
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . AUDIT_PAGE_SIZE . ' OFFSET ' . (($current - 1) * AUDIT_PAGE_SIZE),
        $args
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
}

/** @return array<string, mixed> */
function auditDetailsOf(mixed $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}
