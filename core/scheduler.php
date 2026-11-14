<?php

declare(strict_types=1);

// RELEASE 2.2: единый планировщик отложенной публикации. Публикация никогда не выполняется при
// обычном просмотре страницы: её забирает CLI-команда bin/cron.php. Строка сначала переводится
// в processing отдельной транзакцией, затем публикуется, поэтому упавший процесс не теряет задачу:
// зависшие processing возвращаются в очередь по возрасту. Ошибки повторяются с задержкой и
// остаются в last_error.

const SCHEDULE_STATUSES = ['pending', 'processing', 'done', 'failed', 'cancelled'];
const SCHEDULED_ACTION_LABELS = ['publish' => 'Публикация', 'unpublish' => 'Снятие с публикации'];
const SCHEDULE_MAX_ATTEMPTS = 3;
const SCHEDULE_RETRY_SECONDS = 300;
const SCHEDULE_STUCK_SECONDS = 600;

/** @return list<string> */
function scheduleTimezones(): array
{
    return [
        'Europe/Moscow', 'Europe/Kaliningrad', 'Europe/Samara', 'Asia/Yekaterinburg',
        'Asia/Omsk', 'Asia/Novosibirsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk',
        'Asia/Yakutsk', 'Asia/Vladivostok', 'UTC',
    ];
}

function scheduleTimezone(string $timezone): string
{
    return in_array($timezone, scheduleTimezones(), true) ? $timezone : 'Europe/Moscow';
}

/**
 * Создаёт или переносит расписание. Старое ожидающее расписание того же действия отменяется,
 * чтобы у материала не было двух конкурирующих задач.
 *
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string, id: string, publish_at: string}
 */
function scheduledPublicationCreate(
    string $type,
    string $id,
    string $action,
    string $whenRaw,
    string $timezone,
    array $user,
    ?DateTimeImmutable $now = null
): array {
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'id' => '', 'publish_at' => ''];

    if (!in_array($action, ['publish', 'unpublish'], true)) {
        return $fail('Неизвестное действие расписания.');
    }

    if (!editorialContentExists($type, $id)) {
        return $fail('Материал не найден.');
    }

    $right = $action === 'publish' ? 'schedule' : 'publish';

    if (!editorialCan($right, $user)) {
        return $fail('Недостаточно прав для управления расписанием.');
    }

    $timezone = scheduleTimezone($timezone);
    $when = parseFormDateTimeInZone($whenRaw, $timezone);

    if ($when === null) {
        return $fail('Дата и время расписания заполнены неверно.');
    }

    $now ??= nowUtc();

    if ($when <= $now) {
        return $fail('Время публикации уже прошло — выберите более позднее.');
    }

    $key = contentRevisionKeyId($type, $id);
    $moment = utcStamp($now);
    $scheduleId = newId();

    tx(static function () use ($type, $key, $action, $when, $timezone, $user, $now, $moment, $scheduleId): void {
        // Прежнее ожидающее расписание того же действия снимается: одно действие — одна задача.
        run(
            'UPDATE ' . t('scheduled_publications') . ' SET status = ?, updated_at = ?'
            . ' WHERE content_type = ? AND content_id = ? AND action = ? AND status = ?',
            ['cancelled', $moment, $type, $key, $action, 'pending']
        );

        insert('scheduled_publications', [
            'id' => $scheduleId,
            'content_type' => $type,
            'content_id' => $key,
            'action' => $action,
            'publish_at' => utcStamp($when),
            'timezone' => $timezone,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => '',
            'payload' => null,
            'created_by' => (string) $user['id'],
            'created_at' => $moment,
            'updated_at' => $moment,
            'processed_at' => null,
        ]);

        if ($action === 'publish') {
            editorialStateWrite($type, $key, 'scheduled', (string) $user['id'], [
                'scheduled_for' => utcStamp($when),
            ], $now);
        }
    });

    auditLog(
        'schedule.create',
        $type,
        $key,
        ['scheduleId' => $scheduleId, 'action' => $action, 'publish_at' => utcStamp($when), 'timezone' => $timezone],
        'Назначено расписание: ' . SCHEDULED_ACTION_LABELS[$action]
    );

    return ['ok' => true, 'error' => '', 'id' => $scheduleId, 'publish_at' => utcStamp($when)];
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string}
 */
function scheduledPublicationCancel(string $scheduleId, array $user, ?DateTimeImmutable $now = null): array
{
    if (!contentRevisionTableExists('scheduled_publications')) {
        return ['ok' => false, 'error' => 'Планировщик ещё не установлен.'];
    }

    $row = one(
        'SELECT id, content_type, content_id, action, status FROM ' . t('scheduled_publications')
        . ' WHERE id = ? LIMIT 1',
        [$scheduleId]
    );

    if ($row === null) {
        return ['ok' => false, 'error' => 'Расписание не найдено.'];
    }

    if ((string) $row['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'Отменить можно только ожидающую публикацию.'];
    }

    if (!editorialCan((string) $row['action'] === 'publish' ? 'schedule' : 'publish', $user)) {
        return ['ok' => false, 'error' => 'Недостаточно прав для отмены расписания.'];
    }

    $now ??= nowUtc();
    $moment = utcStamp($now);
    $type = (string) $row['content_type'];
    $id = (string) $row['content_id'];

    tx(static function () use ($scheduleId, $type, $id, $row, $user, $moment, $now): void {
        run(
            'UPDATE ' . t('scheduled_publications') . ' SET status = ?, updated_at = ? WHERE id = ?',
            ['cancelled', $moment, $scheduleId]
        );

        if ((string) $row['action'] === 'publish') {
            $other = one(
                'SELECT id FROM ' . t('scheduled_publications')
                . ' WHERE content_type = ? AND content_id = ? AND action = ? AND status = ? LIMIT 1',
                [$type, $id, 'publish', 'pending']
            );

            if ($other === null) {
                editorialStateWrite($type, $id, 'approved', (string) $user['id'], ['scheduled_for' => null], $now);
            }
        }
    });

    auditLog('schedule.cancel', $type, $id, ['scheduleId' => $scheduleId], 'Расписание публикации отменено');

    return ['ok' => true, 'error' => ''];
}

/** @return list<array<string, mixed>> */
function scheduledPublicationList(string $status = '', int $limit = 100, int $offset = 0): array
{
    if (!contentRevisionTableExists('scheduled_publications')) {
        return [];
    }

    $where = [];
    $args = [];

    if (in_array($status, SCHEDULE_STATUSES, true)) {
        $where[] = 's.status = ?';
        $args[] = $status;
    }

    $sql = 'SELECT s.id, s.content_type, s.content_id, s.action, s.publish_at, s.timezone, s.status,'
        . ' s.attempts, s.next_attempt_at, s.last_error, s.created_by, s.created_at, s.updated_at, s.processed_at,'
        . ' u.name AS creator_name FROM ' . t('scheduled_publications') . ' s'
        . ' LEFT JOIN ' . t('user') . ' u ON u.id = s.created_by'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY s.publish_at, s.id LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);

    return all($sql, $args);
}

/** @return list<array<string, mixed>> расписания конкретного материала */
function scheduledPublicationFor(string $type, string $id): array
{
    if (!contentRevisionTableExists('scheduled_publications')) {
        return [];
    }

    return all(
        'SELECT id, action, publish_at, timezone, status, attempts, last_error FROM ' . t('scheduled_publications')
        . ' WHERE content_type = ? AND content_id = ? AND status IN (?, ?) ORDER BY publish_at LIMIT 20',
        [$type, contentRevisionKeyId($type, $id), 'pending', 'processing']
    );
}

/**
 * Один проход планировщика. Возвращает счётчики and список ошибок.
 *
 * @return array{processed: int, published: int, failed: int, skipped: int, errors: list<string>, retried: int}
 */
function scheduledPublicationRun(int $limit = 50, ?DateTimeImmutable $now = null, bool $dryRun = false): array
{
    $report = ['processed' => 0, 'published' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [], 'retried' => 0];

    if (!contentRevisionTableExists('scheduled_publications')) {
        $report['errors'][] = 'Таблица расписаний не найдена — примените миграции RELEASE 2.2.';

        return $report;
    }

    $now ??= nowUtc();
    $moment = utcStamp($now);

    // Зависшие processing возвращаются в очередь: процесс мог упасть между claim и фиксацией.
    $stuck = utcStamp(shiftSeconds($now, -SCHEDULE_STUCK_SECONDS));
    run(
        'UPDATE ' . t('scheduled_publications') . ' SET status = ?, updated_at = ?'
        . ' WHERE status = ? AND updated_at < ?',
        ['pending', $moment, 'processing', $stuck]
    );

    $due = all(
        'SELECT id FROM ' . t('scheduled_publications')
        . ' WHERE status = ? AND ((next_attempt_at IS NOT NULL AND next_attempt_at <= ?)'
        . ' OR (next_attempt_at IS NULL AND publish_at <= ?))'
        . ' ORDER BY publish_at, id LIMIT ' . max(1, min(200, $limit)),
        ['pending', $moment, $moment]
    );

    foreach ($due as $row) {
        $scheduleId = (string) $row['id'];

        if ($dryRun) {
            $report['processed']++;
            $report['published']++;
            continue;
        }

        $claimed = tx(static function () use ($scheduleId, $moment): ?array {
            $schedule = one(
                'SELECT id, content_type, content_id, action, publish_at, status, attempts, created_by, last_error'
                . ' FROM ' . t('scheduled_publications') . ' WHERE id = ? FOR UPDATE',
                [$scheduleId]
            );

            if ($schedule === null || (string) $schedule['status'] !== 'pending') {
                return null;
            }

            run(
                'UPDATE ' . t('scheduled_publications') . ' SET status = ?, updated_at = ? WHERE id = ?',
                ['processing', $moment, $scheduleId]
            );

            return $schedule;
        });

        if ($claimed === null) {
            $report['skipped']++;
            continue;
        }

        $report['processed']++;
        $type = (string) $claimed['content_type'];
        $contentId = (string) $claimed['content_id'];
        $action = (string) $claimed['action'];

        try {
            if (!editorialContentExists($type, $contentId)) {
                throw new RuntimeException('Материал не найден.');
            }

            $transition = $action === 'publish' ? 'publish' : 'unpublish';
            $result = editorialTransition($type, $contentId, $transition, [
                'system' => true,
                'comment' => 'Публикация по расписанию',
            ], $now);

            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['error'] ?? 'Переход состояния не выполнен.'));
            }

            run(
                'UPDATE ' . t('scheduled_publications') . ' SET status = ?, processed_at = ?, updated_at = ?,'
                . ' last_error = ?, next_attempt_at = NULL WHERE id = ?',
                ['done', $moment, $moment, '', $scheduleId]
            );

            if ($action === 'publish') {
                $report['published']++;
            }

            auditLog('schedule.done', $type, $contentId, ['scheduleId' => $scheduleId, 'action' => $action], 'Планировщик выполнил публикацию');
        } catch (Throwable $error) {
            $attempts = (int) $claimed['attempts'] + 1;
            $final = $attempts >= SCHEDULE_MAX_ATTEMPTS;
            $nextAttempt = $final ? null : utcStamp(shiftSeconds($now, SCHEDULE_RETRY_SECONDS * $attempts));
            $message = mb_substr($error->getMessage(), 0, 500);

            run(
                'UPDATE ' . t('scheduled_publications') . ' SET status = ?, attempts = ?, next_attempt_at = ?,'
                . ' last_error = ?, updated_at = ? WHERE id = ?',
                [$final ? 'failed' : 'pending', $attempts, $nextAttempt, $message, $moment, $scheduleId]
            );

            $report['failed']++;
            if (!$final) {
                $report['retried']++;
            }
            $report['errors'][] = 'Расписание ' . $scheduleId . ': ' . $message;
            logError('schedule.failed', [
                'scheduleId' => $scheduleId,
                'type' => $type,
                'contentId' => $contentId,
                'attempts' => $attempts,
                'final' => $final,
                'message' => $message,
            ]);

            $creator = (string) ($claimed['created_by'] ?? '');
            if ($creator !== '' && ($final || $attempts === 1)) {
                editorialNotify(
                    $creator,
                    'published',
                    $type,
                    $contentId,
                    editorialContentTitle($type, $contentId),
                    $final
                        ? 'Публикация по расписанию не удалась после ' . $attempts . ' попыток: ' . $message
                        : 'Публикация по расписанию отложена и будет повторена: ' . $message,
                    '/admin/editorial?view=schedule',
                    $now
                );
            }
        }
    }

    return $report;
}

/** Уведомления о приближающемся сроке задач: одно на задачу в сутки. */
function scheduledTaskDueNotifications(?DateTimeImmutable $now = null): int
{
    if (!contentRevisionTableExists('editorial_tasks') || !contentRevisionTableExists('editorial_notifications')) {
        return 0;
    }

    $now ??= nowUtc();
    $limitAt = utcStamp(shiftSeconds($now, 86400));
    $dayAgo = utcStamp(shiftDays($now, -1));
    $rows = all(
        'SELECT id, content_type, content_id, title, assignee_id, due_at FROM ' . t('editorial_tasks')
        . ' WHERE status = ? AND due_at IS NOT NULL AND due_at <= ? ORDER BY due_at LIMIT 100',
        ['open', $limitAt]
    );

    $sent = 0;
    foreach ($rows as $task) {
        $assignee = (string) ($task['assignee_id'] ?? '');

        if ($assignee === '') {
            continue;
        }

        $already = one(
            'SELECT id FROM ' . t('editorial_notifications')
            . ' WHERE user_id = ? AND kind = ? AND content_type = ? AND content_id = ? AND created_at >= ? LIMIT 1',
            [$assignee, 'task_due', (string) $task['content_type'], (string) $task['content_id'], $dayAgo]
        );

        if ($already !== null) {
            continue;
        }

        editorialNotify(
            $assignee,
            'task_due',
            (string) $task['content_type'],
            (string) $task['content_id'],
            editorialContentTitle((string) $task['content_type'], (string) $task['content_id']),
            'Приближается срок задачи: ' . (string) $task['title'],
            '/admin/editorial?type=' . rawurlencode((string) $task['content_type'])
                . '&id=' . rawurlencode((string) $task['content_id']) . '&view=tasks',
            $now
        );
        $sent++;
    }

    return $sent;
}

/**
 * Ежедневная уборка: старые уведомления, истёкшие блокировки, старые ревизии.
 *
 * @return array{locks: int, notifications: int, revisions: int, presence: int}
 */
function scheduledMaintenance(?DateTimeImmutable $now = null): array
{
    $now ??= nowUtc();
    $report = ['locks' => 0, 'notifications' => 0, 'revisions' => 0, 'presence' => 0];

    if (contentRevisionTableExists('editor_locks')) {
        $report['locks'] = run(
            'DELETE FROM ' . t('editor_locks') . ' WHERE expires_at < ?',
            [utcStamp($now)]
        );
    }

    if (contentRevisionTableExists('editor_presence')) {
        $report['presence'] = run(
            'DELETE FROM ' . t('editor_presence') . ' WHERE last_seen_at < ?',
            [utcStamp(shiftSeconds($now, -EDITOR_PRESENCE_CLEANUP_SECONDS))]
        );
    }

    if (contentRevisionTableExists('editorial_notifications')) {
        $readBefore = utcStamp(shiftDays($now, -90));
        $report['notifications'] = run(
            'DELETE FROM ' . t('editorial_notifications') . ' WHERE read_at IS NOT NULL AND read_at < ? LIMIT 1000',
            [$readBefore]
        );
    }

    $cleanup = contentRevisionCleanup(false, $now);
    $report['revisions'] = $cleanup['deleted'];

    return $report;
}
