<?php

declare(strict_types=1);

// RELEASE 2.2: редакционные статусы, права, согласование, комментарии, задачи, уведомления,
// присутствие и блокировки. Все проверки прав — серверные; интерфейс лишь показывает доступное.

const EDITORIAL_STATUSES = ['draft', 'review', 'changes', 'approved', 'scheduled', 'published', 'unpublished'];

const EDITORIAL_STATUS_LABELS = [
    'draft' => 'Черновик',
    'review' => 'На проверке',
    'changes' => 'Возвращено на доработку',
    'approved' => 'Одобрено',
    'scheduled' => 'Запланировано',
    'published' => 'Опубликовано',
    'unpublished' => 'Снято с публикации',
];

const EDITORIAL_STATUS_CLASSES = [
    'draft' => 'border-ink/20 bg-ink/5 text-ink/70',
    'review' => 'border-accent/30 bg-accent/10 text-accent',
    'changes' => 'border-bad/30 bg-bad/10 text-bad',
    'approved' => 'border-good/30 bg-good/10 text-good',
    'scheduled' => 'border-accent/30 bg-accent/10 text-accent',
    'published' => 'border-good/30 bg-good/10 text-good',
    'unpublished' => 'border-ink/20 bg-ink/5 text-ink/70',
];

const EDITORIAL_RIGHT_LABELS = [
    'view_history' => 'Просмотр истории',
    'view_diff' => 'Просмотр сравнения',
    'restore' => 'Восстановление ревизий',
    'delete_revision' => 'Удаление ревизий',
    'autosave' => 'Автосохранение',
    'edit' => 'Редактирование материала',
    'lock' => 'Блокировка материала',
    'unlock' => 'Снятие блокировки',
    'submit' => 'Отправка на проверку',
    'approve' => 'Согласование',
    'publish' => 'Публикация',
    'schedule' => 'Управление расписанием',
    'view_comments' => 'Просмотр комментариев',
    'comment' => 'Комментирование',
    'manage_tasks' => 'Управление задачами',
];

const EDITOR_LOCK_TTL_SECONDS = 90;
const EDITOR_PRESENCE_TTL_SECONDS = 120;
const EDITOR_PRESENCE_CLEANUP_SECONDS = 900;
const EDITORIAL_COMMENT_MAX = 5000;
const EDITORIAL_RATE_LIMIT = 60;
const EDITORIAL_RATE_WINDOW_SECONDS = 300;

/** @param array<string, mixed>|null $user
 *  @return list<string>
 */
function editorialRights(?array $user = null): array
{
    $user ??= currentUser();

    if (!is_array($user) || ($user['blocked_at'] ?? null) !== null) {
        return [];
    }

    $role = (string) ($user['role'] ?? '');

    if ($role === ROLES['admin']) {
        return array_keys(EDITORIAL_RIGHT_LABELS);
    }

    if ($role === ROLES['editor']) {
        return [
            'view_history', 'view_diff', 'restore', 'autosave', 'edit', 'lock', 'unlock',
            'submit', 'view_comments', 'comment', 'manage_tasks',
        ];
    }

    return [];
}

/** @param array<string, mixed>|null $user */
function editorialCan(string $right, ?array $user = null): bool
{
    return in_array($right, editorialRights($user), true);
}

/** @param array<string, mixed>|null $user
 *  @return array<string, mixed>|null
 */
function editorialUser(?array $user = null): ?array
{
    $user ??= currentUser();

    if (!is_array($user)) {
        return null;
    }

    return [
        'id' => (string) ($user['id'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ];
}

/** @return list<array{id: string, email: string, name: string, role: string}> */
function editorialTeam(): array
{
    if (!contentRevisionTableExists('user')) {
        return [];
    }

    return all(
        'SELECT id, email, name, role FROM ' . t('user')
        . ' WHERE blocked_at IS NULL AND role IN (?, ?) ORDER BY name, email LIMIT 500',
        [ROLES['admin'], ROLES['editor']]
    );
}

/** @return array<string, mixed>|null */
function editorialUserById(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    return one('SELECT id, email, name, role FROM ' . t('user') . ' WHERE id = ? LIMIT 1', [$id]);
}

function editorialContentExists(string $type, string $id): bool
{
    if (!contentRevisionTypeValid($type)) {
        return false;
    }

    $key = contentRevisionKeyId($type, trim($id));

    if ($key === '') {
        return false;
    }

    if ($type === 'page') {
        // Главная не имеет строки tbl_page: её ключ в истории — home.
        return $key === 'home' || pageById($key) !== null;
    }

    if ($type === 'news') {
        return contentRevisionTableExists('news_item')
            && one('SELECT id FROM ' . t('news_item') . ' WHERE id = ? LIMIT 1', [$key]) !== null;
    }

    if ($type === 'product') {
        return contentRevisionTableExists('commerce_product')
            && one('SELECT id FROM ' . t('commerce_product') . ' WHERE id = ? LIMIT 1', [$key]) !== null;
    }

    return false;
}

function editorialContentTitle(string $type, string $id): string
{
    $key = contentRevisionKeyId($type, $id);

    if ($type === 'page') {
        if ($key === 'home') {
            return 'Главная страница';
        }

        $page = pageById($key);

        return $page === null ? 'Страница' : (string) $page['title'];
    }

    if ($type === 'news') {
        $row = contentRevisionTableExists('news_item')
            ? one('SELECT title FROM ' . t('news_item') . ' WHERE id = ? LIMIT 1', [$key])
            : null;

        return $row === null ? 'Новость' : (string) $row['title'];
    }

    if ($type === 'product') {
        $row = contentRevisionTableExists('commerce_product')
            ? one('SELECT title FROM ' . t('commerce_product') . ' WHERE id = ? LIMIT 1', [$key])
            : null;

        return $row === null ? 'Товар' : (string) $row['title'];
    }

    return 'Материал';
}

function editorialContentUrl(string $type, string $id): string
{
    $key = contentRevisionKeyId($type, $id);

    return match ($type) {
        'page' => $key === 'home' ? '/admin/pages/home' : '/admin/pages/' . rawurlencode($key),
        'news' => '/news/admin/items/' . rawurlencode($key),
        'product' => '/commerce/admin/products/' . rawurlencode($key),
        default => '/admin/editorial?type=' . rawurlencode($type) . '&id=' . rawurlencode($key),
    };
}

function editorialTypeLabel(string $type): string
{
    return match ($type) {
        'page' => 'Страница',
        'news' => 'Новость',
        'product' => 'Товар',
        default => 'Материал',
    };
}

/** @return array<string, mixed> */
function editorialState(string $type, string $id): array
{
    $key = contentRevisionKeyId($type, $id);
    $empty = [
        'content_type' => $type,
        'content_id' => $key,
        'status' => 'draft',
        'previous_status' => '',
        'submitted_by' => null,
        'submitted_at' => null,
        'approved_by' => null,
        'approved_at' => null,
        'published_at' => null,
        'scheduled_for' => null,
        'updated_by' => null,
        'updated_at' => '',
    ];

    if (!contentRevisionTableExists('editorial_states')) {
        return $empty;
    }

    $row = one(
        'SELECT content_type, content_id, status, previous_status, submitted_by, submitted_at, approved_by,'
        . ' approved_at, published_at, scheduled_for, updated_by, updated_at FROM ' . t('editorial_states')
        . ' WHERE content_type = ? AND content_id = ? LIMIT 1',
        [$type, $key]
    );

    return $row === null ? $empty : $row;
}

function editorialStateLabel(string $status): string
{
    return EDITORIAL_STATUS_LABELS[$status] ?? $status;
}

function editorialStateClass(string $status): string
{
    return EDITORIAL_STATUS_CLASSES[$status] ?? EDITORIAL_STATUS_CLASSES['draft'];
}

/**
 * Запись состояния. Вызывается внутри транзакции перехода или планировщиком.
 *
 * @param array<string, mixed> $extra
 */
function editorialStateWrite(string $type, string $id, string $status, ?string $userId, array $extra = [], ?DateTimeImmutable $now = null): void
{
    if (!contentRevisionTableExists('editorial_states')) {
        return;
    }

    $key = contentRevisionKeyId($type, $id);
    $current = editorialState($type, $id);
    $moment = utcStamp($now ?? nowUtc());
    $previous = (string) $current['status'];

    run(
        'INSERT INTO ' . t('editorial_states')
        . ' (content_type, content_id, status, previous_status, submitted_by, submitted_at, approved_by, approved_at,'
        . ' published_at, scheduled_for, updated_by, updated_at)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE previous_status = status, status = ?, submitted_by = ?, submitted_at = ?,'
        . ' approved_by = ?, approved_at = ?, published_at = ?, scheduled_for = ?, updated_by = ?, updated_at = ?',
        [
            $type, $key, $status, $previous,
            $extra['submitted_by'] ?? $current['submitted_by'],
            $extra['submitted_at'] ?? $current['submitted_at'],
            $extra['approved_by'] ?? $current['approved_by'],
            $extra['approved_at'] ?? $current['approved_at'],
            $extra['published_at'] ?? $current['published_at'],
            $extra['scheduled_for'] ?? $current['scheduled_for'],
            $userId,
            $moment,
            $status,
            array_key_exists('submitted_by', $extra) ? $extra['submitted_by'] : $current['submitted_by'],
            array_key_exists('submitted_at', $extra) ? $extra['submitted_at'] : $current['submitted_at'],
            array_key_exists('approved_by', $extra) ? $extra['approved_by'] : $current['approved_by'],
            array_key_exists('approved_at', $extra) ? $extra['approved_at'] : $current['approved_at'],
            array_key_exists('published_at', $extra) ? $extra['published_at'] : $current['published_at'],
            array_key_exists('scheduled_for', $extra) ? $extra['scheduled_for'] : $current['scheduled_for'],
            $userId,
            $moment,
        ]
    );
}

/**
 * Разрешённый переход: откуда, куда, какое право нужно.
 *
 * @return array{from: list<string>, to: string, right: string}|null
 */
function editorialTransitionPlan(string $action): ?array
{
    return match ($action) {
        'submit' => ['from' => ['draft', 'changes', 'unpublished'], 'to' => 'review', 'right' => 'submit'],
        'return' => ['from' => ['review', 'approved'], 'to' => 'changes', 'right' => 'approve'],
        'approve' => ['from' => ['review'], 'to' => 'approved', 'right' => 'approve'],
        'publish' => ['from' => ['draft', 'review', 'approved', 'scheduled', 'unpublished'], 'to' => 'published', 'right' => 'publish'],
        'unpublish' => ['from' => ['published'], 'to' => 'unpublished', 'right' => 'publish'],
        'draft' => ['from' => ['changes', 'approved', 'review'], 'to' => 'draft', 'right' => 'edit'],
        default => null,
    };
}

/**
 * Переход редакционного статуса. Публикация и снятие меняют и публичный статус материала.
 *
 * @param array<string, mixed> $meta user, expected_version, comment, system, operation
 * @return array{ok: bool, error: string, status: string, version: int, conflict?: array<string, mixed>}
 */
function editorialTransition(string $type, string $id, string $action, array $meta = [], ?DateTimeImmutable $now = null): array
{
    $plan = editorialTransitionPlan($action);
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'status' => '', 'version' => 0];

    if ($plan === null) {
        return $fail('Неизвестное действие редакционного процесса.');
    }

    if (!editorialContentExists($type, $id)) {
        return $fail('Материал не найден — обновите страницу.');
    }

    $user = editorialUser(is_array($meta['user'] ?? null) ? $meta['user'] : null);
    $system = ($meta['system'] ?? false) === true;

    if (!$system && ($user === null || !editorialCan($plan['right'], $user))) {
        return $fail('Недостаточно прав для этого действия.');
    }

    $state = editorialState($type, $id);
    $current = (string) $state['status'];

    if (!in_array($current, $plan['from'], true)) {
        return $fail('Из состояния «' . editorialStateLabel($current) . '» действие недоступно.');
    }

    $key = contentRevisionKeyId($type, $id);
    $now ??= nowUtc();
    $userId = $user['id'] ?? null;
    $versionMeta = [
        'author' => $user,
        'expected_version' => $meta['expected_version'] ?? null,
        'comment' => (string) ($meta['comment'] ?? ''),
        'operation' => 'publish',
        'source' => $system ? 'scheduler' : 'editor',
    ];

    try {
        $result = tx(static function () use ($type, $key, $action, $plan, $user, $userId, $state, $now, $versionMeta): array {
            $version = 0;

            if ($plan['to'] === 'published') {
                $version = editorialApplyPublication($type, $key, true, $versionMeta, $now);
            } elseif ($plan['to'] === 'unpublished') {
                $version = editorialApplyPublication($type, $key, false, $versionMeta, $now);
            }

            $extra = [];

            if ($action === 'submit') {
                $extra['submitted_by'] = $userId;
                $extra['submitted_at'] = utcStamp($now);
            }

            if ($action === 'approve') {
                $extra['approved_by'] = $userId;
                $extra['approved_at'] = utcStamp($now);
            }

            if ($plan['to'] === 'published') {
                $extra['published_at'] = utcStamp($now);
                $extra['scheduled_for'] = null;
            }

            editorialStateWrite($type, $key, $plan['to'], $userId, $extra, $now);
            editorialTransitionNotify($type, $key, $action, $state, $user, $now);

            return ['status' => $plan['to'], 'version' => $version];
        });
    } catch (ContentVersionConflict $conflict) {
        return [
            'ok' => false,
            'error' => $conflict->getMessage(),
            'status' => (string) $state['status'],
            'version' => $conflict->actualVersion,
            'conflict' => [
                'expected' => $conflict->expectedVersion,
                'actual' => $conflict->actualVersion,
                'revision_id' => $conflict->currentRevisionId,
            ],
        ];
    } catch (ContentVersionReplay) {
        // Повтор уже выполнен: состояние уже нужное.
    }

    $title = editorialContentTitle($type, $key);
    auditLog(
        'editorial.' . $action,
        $type,
        $key,
        ['from' => $current, 'to' => $plan['to'], 'comment' => (string) ($meta['comment'] ?? '')],
        editorialTypeLabel($type) . ' «' . $title . '»: ' . editorialStateLabel($plan['to'])
    );

    return ['ok' => true, 'error' => '', 'status' => $plan['to'], 'version' => 0];
}

/** Меняет публичный статус материала и фиксирует ревизию публикации.
 *  @param array<string, mixed> $meta
 */
function editorialApplyPublication(string $type, string $key, bool $publish, array $meta = [], ?DateTimeImmutable $now = null): int
{
    $moment = utcStamp($now ?? nowUtc());
    $meta['operation'] = 'publish';
    $meta['comment'] = $meta['comment'] ?? ($publish ? 'Материал опубликован' : 'Материал снят с публикации');
    $meta = contentRevisionMeta($meta);
    $version = 0;

    if ($type === 'page') {
        if ($key !== 'home') {
            contentRevisionGuard('page', $key, $meta, $now);
            run(
                'UPDATE ' . t('page') . ' SET status = ?, updated_at = ? WHERE id = ?',
                [$publish ? PAGES_STATUS_PUBLISHED : PAGES_STATUS_DRAFT, $moment, $key]
            );
            $version = contentRevisionCommit('page', $key, $meta, $now)['version'];
        }

        return $version;
    }

    if ($type === 'news') {
        contentRevisionGuard('news', $key, $meta, $now);
        $publishedAt = null;

        if ($publish) {
            $row = one('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ? LIMIT 1', [$key]);
            $publishedAt = $row !== null && $row['published_at'] !== null ? (string) $row['published_at'] : $moment;
        }

        run(
            'UPDATE ' . t('news_item') . ' SET status = ?, published_at = ?, updated_at = ? WHERE id = ?',
            [$publish ? NEWS_STATUS['PUBLISHED'] : NEWS_STATUS['DRAFT'], $publishedAt, $moment, $key]
        );

        return contentRevisionCommit('news', $key, $meta, $now)['version'];
    }

    if ($type === 'product') {
        contentRevisionGuard('product', $key, $meta, $now);
        run(
            'UPDATE ' . t('commerce_product') . ' SET status = ?, updated_at = ? WHERE id = ?',
            [$publish ? 'PUBLISHED' : 'DRAFT', $moment, $key]
        );

        return contentRevisionCommit('product', $key, $meta, $now)['version'];
    }

    return 0;
}

/** @param array<string, mixed> $state
 *  @param array<string, mixed>|null $user
 */
function editorialTransitionNotify(string $type, string $id, string $action, array $state, ?array $user, ?DateTimeImmutable $now = null): void
{
    $title = editorialContentTitle($type, $id);
    $url = '/admin/editorial?type=' . rawurlencode($type) . '&id=' . rawurlencode(contentRevisionKeyId($type, $id));

    if ($action === 'submit') {
        $recipients = editorialTeam();
        foreach ($recipients as $recipient) {
            if ($user !== null && (string) $recipient['id'] === $user['id']) {
                continue;
            }

            if (in_array((string) $recipient['role'], [ROLES['admin']], true)) {
                editorialNotify((string) $recipient['id'], 'review_requested', $type, $id, $title, 'Материал отправлен на проверку.', $url, $now);
            }
        }

        return;
    }

    $authorId = (string) ($state['submitted_by'] ?? '');

    if ($action === 'return' && $authorId !== '') {
        editorialNotify($authorId, 'changes_requested', $type, $id, $title, 'Материал возвращён на доработку.', $url, $now);

        return;
    }

    if ($action === 'approve' && $authorId !== '') {
        editorialNotify($authorId, 'approved', $type, $id, $title, 'Материал одобрен.', $url, $now);

        return;
    }

    if ($action === 'publish') {
        foreach (editorialTeam() as $recipient) {
            if ($user !== null && (string) $recipient['id'] === $user['id']) {
                continue;
            }

            editorialNotify((string) $recipient['id'], 'published', $type, $id, $title, 'Материал опубликован.', $url, $now);
        }
    }
}

/** @return array{ok: bool, error: string, id: string} */
function editorialNotify(
    string $userId,
    string $kind,
    string $type,
    string $id,
    string $title,
    string $body,
    string $url,
    ?DateTimeImmutable $now = null
): array {
    if ($userId === '' || !contentRevisionTableExists('editorial_notifications')) {
        return ['ok' => false, 'error' => 'Получатель уведомления не найден.', 'id' => ''];
    }

    // Внутренние уведомления ведут только внутрь админки: внешний адрес в поле url не попадает.
    if ($url !== '' && (!str_starts_with($url, '/') || str_starts_with($url, '//'))) {
        $url = '';
    }

    $notificationId = newId();
    insert('editorial_notifications', [
        'id' => $notificationId,
        'user_id' => $userId,
        'kind' => mb_substr($kind, 0, 40),
        'content_type' => mb_substr($type, 0, 32),
        'content_id' => mb_substr(contentRevisionKeyId($type, $id), 0, 64),
        'title' => mb_substr($title, 0, 190),
        'body' => mb_substr($body, 0, 500),
        'url' => mb_substr($url, 0, 300),
        'read_at' => null,
        'created_at' => utcStamp($now ?? nowUtc()),
    ]);

    return ['ok' => true, 'error' => '', 'id' => $notificationId];
}

/** @return list<array<string, mixed>> */
function editorialNotifications(string $userId, string $kind = '', bool $unreadOnly = false, int $limit = 50, int $offset = 0): array
{
    if ($userId === '' || !contentRevisionTableExists('editorial_notifications')) {
        return [];
    }

    $where = ['user_id = ?'];
    $args = [$userId];

    if (in_array($kind, editorialNotificationKinds(), true)) {
        $where[] = 'kind = ?';
        $args[] = $kind;
    }

    if ($unreadOnly) {
        $where[] = 'read_at IS NULL';
    }

    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $sql = sprintf(
        'SELECT id, user_id, kind, content_type, content_id, title, body, url, read_at, created_at'
        . ' FROM ' . t('editorial_notifications') . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
        $limit,
        $offset
    );

    return all($sql, $args);
}

/** @return list<string> */
function editorialNotificationKinds(): array
{
    return [
        'review_requested', 'changes_requested', 'approved', 'published', 'mention',
        'comment', 'task', 'task_due', 'conflict', 'autosave_failed', 'lock_expired',
    ];
}

function editorialNotificationKindLabel(string $kind): string
{
    return match ($kind) {
        'review_requested' => 'Назначено на проверку',
        'changes_requested' => 'Возвращено на доработку',
        'approved' => 'Одобрено',
        'published' => 'Опубликовано',
        'mention' => 'Упоминание',
        'comment' => 'Комментарий',
        'task' => 'Задача',
        'task_due' => 'Срок задачи',
        'conflict' => 'Конфликт редактирования',
        'autosave_failed' => 'Ошибка автосохранения',
        'lock_expired' => 'Блокировка истекла',
        default => $kind,
    };
}

function editorialNotificationUnread(string $userId): int
{
    if ($userId === '' || !contentRevisionTableExists('editorial_notifications')) {
        return 0;
    }

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('editorial_notifications') . ' WHERE user_id = ? AND read_at IS NULL',
        [$userId]
    );
}

function editorialNotificationRead(string $userId, string $id, ?DateTimeImmutable $now = null): bool
{
    if ($userId === '' || $id === '' || !contentRevisionTableExists('editorial_notifications')) {
        return false;
    }

    $changed = run(
        'UPDATE ' . t('editorial_notifications') . ' SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL',
        [utcStamp($now ?? nowUtc()), $id, $userId]
    );

    return $changed > 0;
}

function editorialNotificationReadAll(string $userId, ?DateTimeImmutable $now = null): int
{
    if ($userId === '' || !contentRevisionTableExists('editorial_notifications')) {
        return 0;
    }

    return run(
        'UPDATE ' . t('editorial_notifications') . ' SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
        [utcStamp($now ?? nowUtc()), $userId]
    );
}

// ---------------------------------------------------------------------------
// Присутствие и блокировки
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string, token: string, expires_at: string, lock: array<string, mixed>|null}
 */
function editorialLockAcquire(string $type, string $id, string $sessionId, array $user, bool $force = false, ?DateTimeImmutable $now = null): array
{
    $fail = static fn (string $error, ?array $lock, string $token = ''): array => [
        'ok' => false,
        'error' => $error,
        'token' => $token,
        'expires_at' => '',
        'lock' => $lock,
    ];

    if (!contentRevisionTableExists('editor_locks')) {
        return $fail('Блокировки ещё не установлены — примените миграции RELEASE 2.2.', null);
    }

    if (!editorialContentExists($type, $id)) {
        return $fail('Материал не найден.', null);
    }

    $key = contentRevisionKeyId($type, $id);
    $now ??= nowUtc();
    $moment = utcStamp($now);
    $expires = utcStamp(shiftSeconds($now, EDITOR_LOCK_TTL_SECONDS));
    $token = bin2hex(random_bytes(32));

    $result = tx(static function () use ($type, $key, $sessionId, $user, $force, $moment, $expires, $token, $now): array {
        $existing = one(
            'SELECT id, user_id, session_id, token, created_at, heartbeat_at, expires_at FROM ' . t('editor_locks')
            . ' WHERE content_type = ? AND content_id = ? FOR UPDATE',
            [$type, $key]
        );

        if ($existing !== null && (string) $existing['expires_at'] <= $moment) {
            run('DELETE FROM ' . t('editor_locks') . ' WHERE id = ?', [(string) $existing['id']]);
            $existing = null;
        }

        if ($existing !== null && (string) $existing['user_id'] !== $user['id'] && !$force) {
            $owner = editorialUserById((string) $existing['user_id']);

            return [
                'ok' => false,
                'error' => 'Материал сейчас редактирует ' . (($owner['name'] ?? '') !== '' ? (string) $owner['name'] : (string) $existing['user_id']),
                'token' => '',
                'expires_at' => (string) $existing['expires_at'],
                'lock' => [
                    'user_id' => (string) $existing['user_id'],
                    'user_name' => (string) ($owner['name'] ?? ''),
                    'user_email' => (string) ($owner['email'] ?? ''),
                    'expires_at' => (string) $existing['expires_at'],
                ],
            ];
        }

        if ($existing !== null) {
            run('DELETE FROM ' . t('editor_locks') . ' WHERE id = ?', [(string) $existing['id']]);
        }

        insert('editor_locks', [
            'id' => newId(),
            'content_type' => $type,
            'content_id' => $key,
            'user_id' => (string) $user['id'],
            'session_id' => mb_substr($sessionId, 0, 128),
            'token' => hash('sha256', $token),
            'created_at' => $moment,
            'heartbeat_at' => $moment,
            'expires_at' => $expires,
        ]);

        editorialPresenceTouch($type, $key, $user, $sessionId, $now, true);

        return ['ok' => true, 'error' => '', 'token' => $token, 'expires_at' => $expires, 'lock' => null];
    });

    return $result;
}

/** @return array{ok: bool, error: string, expires_at: string} */
function editorialLockHeartbeat(string $type, string $id, string $token, string $sessionId, ?DateTimeImmutable $now = null): array
{
    if (!contentRevisionTableExists('editor_locks')) {
        return ['ok' => false, 'error' => 'Блокировки ещё не установлены.', 'expires_at' => ''];
    }

    $key = contentRevisionKeyId($type, $id);
    $now ??= nowUtc();
    $moment = utcStamp($now);
    $expires = utcStamp(shiftSeconds($now, EDITOR_LOCK_TTL_SECONDS));
    $row = one(
        'SELECT id, token, session_id, expires_at FROM ' . t('editor_locks')
        . ' WHERE content_type = ? AND content_id = ? LIMIT 1',
        [$type, $key]
    );

    if ($row === null || !hash_equals((string) $row['token'], hash('sha256', $token))) {
        return ['ok' => false, 'error' => 'Блокировка потеряна — обновите страницу.', 'expires_at' => ''];
    }

    if ((string) $row['expires_at'] <= $moment) {
        run('DELETE FROM ' . t('editor_locks') . ' WHERE id = ?', [(string) $row['id']]);

        return ['ok' => false, 'error' => 'Блокировка истекла — обновите страницу.', 'expires_at' => ''];
    }

    if ((string) $row['session_id'] !== $sessionId) {
        return ['ok' => false, 'error' => 'Блокировка принадлежит другой вкладке.', 'expires_at' => ''];
    }

    run(
        'UPDATE ' . t('editor_locks') . ' SET heartbeat_at = ?, expires_at = ? WHERE id = ?',
        [$moment, $expires, (string) $row['id']]
    );

    return ['ok' => true, 'error' => '', 'expires_at' => $expires];
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string}
 */
function editorialLockRelease(string $type, string $id, string $token, array $user, bool $force = false, ?DateTimeImmutable $now = null): array
{
    if (!contentRevisionTableExists('editor_locks')) {
        return ['ok' => true, 'error' => ''];
    }

    $key = contentRevisionKeyId($type, $id);
    $row = one(
        'SELECT id, user_id, token FROM ' . t('editor_locks') . ' WHERE content_type = ? AND content_id = ? LIMIT 1',
        [$type, $key]
    );

    if ($row === null) {
        return ['ok' => true, 'error' => ''];
    }

    $owner = (string) $row['user_id'] === (string) $user['id'];
    $tokenOk = $token !== '' && hash_equals((string) $row['token'], hash('sha256', $token));

    if (!$owner && !$force) {
        return ['ok' => false, 'error' => 'Снять блокировку может её владелец или администратор.'];
    }

    if ($owner && !$tokenOk && !$force) {
        return ['ok' => false, 'error' => 'Блокировка принадлежит другой вкладке.'];
    }

    run('DELETE FROM ' . t('editor_locks') . ' WHERE id = ?', [(string) $row['id']]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed>|null */
function editorialLockState(string $type, string $id, ?DateTimeImmutable $now = null): ?array
{
    if (!contentRevisionTableExists('editor_locks')) {
        return null;
    }

    $key = contentRevisionKeyId($type, $id);
    $moment = utcStamp($now ?? nowUtc());
    $row = one(
        'SELECT l.id, l.user_id, l.session_id, l.created_at, l.heartbeat_at, l.expires_at,'
        . ' u.name AS user_name, u.email AS user_email FROM ' . t('editor_locks') . ' l'
        . ' LEFT JOIN ' . t('user') . ' u ON u.id = l.user_id'
        . ' WHERE l.content_type = ? AND l.content_id = ? LIMIT 1',
        [$type, $key]
    );

    if ($row === null) {
        return null;
    }

    if ((string) $row['expires_at'] <= $moment) {
        run('DELETE FROM ' . t('editor_locks') . ' WHERE id = ?', [(string) $row['id']]);

        return null;
    }

    return $row;
}

/**
 * Отметка присутствия. Строка на сессию; истёкшие записи чистятся пачкой.
 *
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string}
 */
function editorialPresenceTouch(string $type, string $id, array $user, string $sessionId, ?DateTimeImmutable $now = null, bool $keepLock = false): array
{
    if (!contentRevisionTableExists('editor_presence')) {
        return ['ok' => false, 'error' => 'Присутствие ещё не установлено.'];
    }

    $key = contentRevisionKeyId($type, $id);
    $now ??= nowUtc();
    $moment = utcStamp($now);
    $entered = $moment;

    $existing = one(
        'SELECT id, entered_at FROM ' . t('editor_presence')
        . ' WHERE content_type = ? AND content_id = ? AND session_id = ? LIMIT 1',
        [$type, $key, mb_substr($sessionId, 0, 128)]
    );

    if ($existing !== null) {
        $entered = (string) $existing['entered_at'];
        run(
            'UPDATE ' . t('editor_presence') . ' SET last_seen_at = ?, user_id = ? WHERE id = ?',
            [$moment, (string) $user['id'], (string) $existing['id']]
        );
    } else {
        insert('editor_presence', [
            'id' => newId(),
            'content_type' => $type,
            'content_id' => $key,
            'user_id' => (string) $user['id'],
            'session_id' => mb_substr($sessionId, 0, 128),
            'entered_at' => $entered,
            'last_seen_at' => $moment,
        ]);
    }

    $cutoff = utcStamp(shiftSeconds($now, -EDITOR_PRESENCE_CLEANUP_SECONDS));
    run('DELETE FROM ' . t('editor_presence') . ' WHERE last_seen_at < ?', [$cutoff]);

    return ['ok' => true, 'error' => ''];
}

/**
 * Активные редакторы материала: одна строка на пользователя, даже если открыто несколько вкладок.
 *
 * @return list<array<string, mixed>>
 */
function editorialPresenceList(string $type, string $id, ?DateTimeImmutable $now = null): array
{
    if (!contentRevisionTableExists('editor_presence')) {
        return [];
    }

    $key = contentRevisionKeyId($type, $id);
    $now ??= nowUtc();
    $cutoff = utcStamp(shiftSeconds($now, -EDITOR_PRESENCE_TTL_SECONDS));
    $rows = all(
        'SELECT p.user_id, MIN(p.entered_at) AS entered_at, MAX(p.last_seen_at) AS last_seen_at,'
        . ' u.name AS user_name, u.email AS user_email, u.role AS user_role'
        . ' FROM ' . t('editor_presence') . ' p LEFT JOIN ' . t('user') . ' u ON u.id = p.user_id'
        . ' WHERE p.content_type = ? AND p.content_id = ? AND p.last_seen_at >= ?'
        . ' GROUP BY p.user_id, u.name, u.email, u.role ORDER BY MIN(p.entered_at) LIMIT 50',
        [$type, $key, $cutoff]
    );

    return $rows;
}

/** @return list<array<string, mixed>> все действующие блокировки для сводки админки */
function editorialActiveLocks(int $limit = 50, ?DateTimeImmutable $now = null): array
{
    if (!contentRevisionTableExists('editor_locks')) {
        return [];
    }

    $moment = utcStamp($now ?? nowUtc());

    return all(
        'SELECT l.content_type, l.content_id, l.user_id, l.expires_at, u.name AS user_name, u.email AS user_email'
        . ' FROM ' . t('editor_locks') . ' l LEFT JOIN ' . t('user') . ' u ON u.id = l.user_id'
        . ' WHERE l.expires_at > ? ORDER BY l.expires_at LIMIT ' . max(1, min(100, $limit)),
        [$moment]
    );
}

/**
 * Материал занят другой вкладкой или другим редактором? Пустая строка — можно сохранять.
 * Свою блокировку не блокируем: иначе автосохранение не прошло бы в той же вкладке.
 *
 * @param array<string, mixed> $user
 */
function editorialLockBlocks(string $type, string $id, array $user, ?DateTimeImmutable $now = null, ?string $sessionId = null): string
{
    $lock = editorialLockState($type, $id, $now);

    if ($lock === null) {
        return '';
    }

    $sessionId ??= editorialSessionId();

    if ((string) $lock['user_id'] === (string) ($user['id'] ?? '')
        && (string) $lock['session_id'] === $sessionId) {
        return '';
    }

    $name = trim((string) ($lock['user_name'] ?? ''));

    if ($name === '') {
        $name = trim((string) ($lock['user_email'] ?? ''));
    }

    if ($name === '') {
        $name = 'другой редактор';
    }

    return 'Материал редактирует ' . $name . '. Дождитесь освобождения блокировки или попросите администратора снять её.';
}

/**
 * Актуальная очередь редакции: материалы, у которых есть редакционное состояние.
 * Свежие сверху; публикации тоже показываются — это история движения, а не архив.
 *
 * @return list<array<string, mixed>>
 */
function editorialQueue(int $limit = 100): array
{
    if (!contentRevisionTableExists('editorial_states')) {
        return [];
    }

    $rows = all(
        'SELECT s.content_type, s.content_id, s.status, s.previous_status, s.submitted_at,'
        . ' s.approved_at, s.published_at, s.scheduled_for, s.updated_at, s.updated_by,'
        . ' u.name AS user_name, u.email AS user_email FROM ' . t('editorial_states') . ' s'
        . ' LEFT JOIN ' . t('user') . ' u ON u.id = s.updated_by'
        . ' ORDER BY s.updated_at DESC LIMIT ' . max(1, min(200, $limit))
    );

    foreach ($rows as $index => $row) {
        $type = (string) $row['content_type'];
        $id = (string) $row['content_id'];
        $status = (string) $row['status'];
        $rows[$index] = $row + [
            'title' => editorialContentTitle($type, $id),
            'type_label' => editorialTypeLabel($type),
            'state_label' => editorialStateLabel($status),
            'state_class' => editorialStateClass($status),
            'version' => contentRevisionVersion($type, $id),
            'url' => editorialContentUrl($type, $id),
            'editor_url' => '/admin/editorial?' . http_build_query(['type' => $type, 'id' => $id]),
        ];
    }

    return $rows;
}

/**
 * Последние ревизии по всем материалам — лента работы редакции.
 *
 * @return list<array<string, mixed>>
 */
function editorialRecentRevisions(int $limit = 50): array
{
    if (!contentRevisionTableExists('content_revisions')) {
        return [];
    }

    $rows = all(
        'SELECT r.id, r.content_type, r.content_id, r.version, r.operation, r.source, r.comment,'
        . ' r.author_email, r.created_at, r.author_id, u.name AS author_name FROM ' . t('content_revisions') . ' r'
        . ' LEFT JOIN ' . t('user') . ' u ON u.id = r.author_id'
        . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . max(1, min(200, $limit))
    );

    foreach ($rows as $index => $row) {
        $type = (string) $row['content_type'];
        $id = (string) $row['content_id'];
        $rows[$index] = $row + [
            'title' => editorialContentTitle($type, $id),
            'type_label' => editorialTypeLabel($type),
            'operation_label' => contentRevisionOperationLabel((string) $row['operation']),
            'editor_url' => '/admin/editorial?' . http_build_query(['type' => $type, 'id' => $id, 'view' => 'diff', 'revision' => (string) $row['id']]),
        ];
    }

    return $rows;
}

/** Идентификатор сессии для блокировок и присутствия. Один на вкладку браузера. */
function editorialSessionId(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        startSession();
    }

    return session_id();
}

// ---------------------------------------------------------------------------
// Комментарии и задачи
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string, id: string, mentions: list<string>}
 */
function editorialCommentCreate(
    string $type,
    string $id,
    string $body,
    array $user,
    string $parentId = '',
    string $sectionId = '',
    ?DateTimeImmutable $now = null
): array {
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'id' => '', 'mentions' => []];

    if (!editorialContentExists($type, $id)) {
        return $fail('Материал не найден.');
    }

    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));

    if ($body === '') {
        return $fail('Комментарий пустой.');
    }

    if (mb_strlen($body) > EDITORIAL_COMMENT_MAX) {
        return $fail('Комментарий длиннее ' . EDITORIAL_COMMENT_MAX . ' символов.');
    }

    $key = contentRevisionKeyId($type, $id);
    $parentId = trim($parentId);

    if ($parentId !== '') {
        $parent = one(
            'SELECT id, content_type, content_id FROM ' . t('editorial_comments') . ' WHERE id = ? LIMIT 1',
            [$parentId]
        );

        if ($parent === null || (string) $parent['content_type'] !== $type || (string) $parent['content_id'] !== $key) {
            return $fail('Ответ адресован комментарию другого материала.');
        }
    }

    $sectionId = trim($sectionId);
    if ($type === 'page' && $sectionId !== '' && sectionById($sectionId) === null) {
        return $fail('Секция, к которой относится комментарий, уже удалена.');
    }

    $mentions = editorialMentions($body);
    $now ??= nowUtc();
    $commentId = newId();

    try {
        tx(static function () use ($commentId, $type, $key, $sectionId, $parentId, $user, $body, $mentions, $now): void {
            insert('editorial_comments', [
                'id' => $commentId,
                'content_type' => $type,
                'content_id' => $key,
                'section_id' => $sectionId,
                'parent_id' => $parentId !== '' ? $parentId : null,
                'author_id' => (string) $user['id'],
                'author_email' => mb_substr((string) $user['email'], 0, 190),
                'body' => $body,
                'mentions' => $mentions === [] ? null : (string) json_encode($mentions, JSON_UNESCAPED_UNICODE),
                'resolved_by' => null,
                'resolved_at' => null,
                'created_at' => utcStamp($now),
                'updated_at' => utcStamp($now),
            ]);

            $url = '/admin/editorial?type=' . rawurlencode($type) . '&id=' . rawurlencode($key) . '&view=comments';
            $title = editorialContentTitle($type, $key);

            foreach ($mentions as $mentionId) {
                if ($mentionId !== (string) $user['id']) {
                    editorialNotify($mentionId, 'mention', $type, $key, $title, 'Вас упомянули в комментарии.', $url, $now);
                }
            }

            if ($parentId !== '') {
                $parent = one('SELECT author_id FROM ' . t('editorial_comments') . ' WHERE id = ? LIMIT 1', [$parentId]);
                $parentAuthor = (string) ($parent['author_id'] ?? '');

                if ($parentAuthor !== '' && $parentAuthor !== (string) $user['id'] && !in_array($parentAuthor, $mentions, true)) {
                    editorialNotify($parentAuthor, 'comment', $type, $key, $title, 'Ответ на ваш комментарий.', $url, $now);
                }
            }
        });
    } catch (Throwable $error) {
        logError('editorial.comment_failed', ['message' => $error->getMessage()]);

        return $fail('Комментарий не сохранился — повторите попытку.');
    }

    auditLog('editorial.comment', $type, $key, ['commentId' => $commentId, 'mentions' => $mentions], 'Добавлен редакционный комментарий');

    return ['ok' => true, 'error' => '', 'id' => $commentId, 'mentions' => $mentions];
}

/** @return list<string> идентификаторы упомянутых пользователей */
function editorialMentions(string $body): array
{
    if (preg_match_all('/@([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/u', $body, $matches) < 1) {
        return [];
    }

    $emails = array_values(array_unique(array_map('mb_strtolower', $matches[1] ?? [])));
    $ids = [];

    foreach (array_slice($emails, 0, 10) as $email) {
        $user = one('SELECT id FROM ' . t('user') . ' WHERE email = ? LIMIT 1', [$email]);

        if ($user !== null) {
            $ids[] = (string) $user['id'];
        }
    }

    return $ids;
}

/** @return list<array<string, mixed>> */
function editorialCommentList(string $type, string $id, bool $includeResolved = true, int $limit = 100): array
{
    if (!contentRevisionTableExists('editorial_comments')) {
        return [];
    }

    $key = contentRevisionKeyId($type, $id);
    $where = ['c.content_type = ?', 'c.content_id = ?'];
    $args = [$type, $key];

    if (!$includeResolved) {
        $where[] = 'c.resolved_at IS NULL';
    }

    $rows = all(
        'SELECT c.id, c.content_type, c.content_id, c.section_id, c.parent_id, c.author_id, c.author_email,'
        . ' c.body, c.mentions, c.resolved_by, c.resolved_at, c.created_at, c.updated_at,'
        . ' u.name AS author_name FROM ' . t('editorial_comments') . ' c'
        . ' LEFT JOIN ' . t('user') . ' u ON u.id = c.author_id'
        . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.created_at, c.id LIMIT ' . max(1, min(200, $limit)),
        $args
    );

    foreach ($rows as &$row) {
        $decoded = json_decode((string) ($row['mentions'] ?? '[]'), true);
        $row['mentions'] = is_array($decoded) ? $decoded : [];
    }
    unset($row);

    return $rows;
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string}
 */
function editorialCommentResolve(string $commentId, bool $resolved, array $user, ?DateTimeImmutable $now = null): array
{
    if (!contentRevisionTableExists('editorial_comments')) {
        return ['ok' => false, 'error' => 'Комментарии ещё не установлены.'];
    }

    $row = one('SELECT id, content_type, content_id, resolved_at FROM ' . t('editorial_comments') . ' WHERE id = ? LIMIT 1', [$commentId]);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Комментарий не найден.'];
    }

    $moment = utcStamp($now ?? nowUtc());
    run(
        'UPDATE ' . t('editorial_comments') . ' SET resolved_by = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
        [$resolved ? (string) $user['id'] : null, $resolved ? $moment : null, $moment, $commentId]
    );

    auditLog(
        'editorial.comment_' . ($resolved ? 'resolve' : 'reopen'),
        (string) $row['content_type'],
        (string) $row['content_id'],
        ['commentId' => $commentId],
        $resolved ? 'Обсуждение отмечено решённым' : 'Обсуждение открыто заново'
    );

    return ['ok' => true, 'error' => ''];
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string, id: string}
 */
function editorialTaskCreate(string $type, string $id, string $title, string $assigneeId, array $user, string $comment = '', ?DateTimeImmutable $dueAt = null, ?DateTimeImmutable $now = null): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'id' => ''];

    if (!editorialContentExists($type, $id)) {
        return $fail('Материал не найден.');
    }

    $title = trim($title);
    if ($title === '') {
        return $fail('Название задачи пустое.');
    }

    $assignee = editorialUserById($assigneeId);
    if ($assignee === null) {
        return $fail('Исполнитель не найден.');
    }

    $taskId = newId();
    $moment = $now ?? nowUtc();

    insert('editorial_tasks', [
        'id' => $taskId,
        'content_type' => $type,
        'content_id' => contentRevisionKeyId($type, $id),
        'title' => mb_substr($title, 0, 190),
        'comment' => mb_substr(trim($comment), 0, 1000),
        'assignee_id' => (string) $assignee['id'],
        'assignee_email' => mb_substr((string) $assignee['email'], 0, 190),
        'due_at' => $dueAt === null ? null : utcStamp($dueAt),
        'status' => 'open',
        'created_by' => (string) $user['id'],
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
        'completed_at' => null,
    ]);

    editorialNotify(
        (string) $assignee['id'],
        'task',
        $type,
        $id,
        editorialContentTitle($type, $id),
        'Вам назначена задача: ' . $title,
        '/admin/editorial?type=' . rawurlencode($type) . '&id=' . rawurlencode(contentRevisionKeyId($type, $id)) . '&view=tasks',
        $moment
    );

    auditLog('editorial.task_create', $type, contentRevisionKeyId($type, $id), ['taskId' => $taskId, 'assignee' => (string) $assignee['id']], 'Назначена редакционная задача');

    return ['ok' => true, 'error' => '', 'id' => $taskId];
}

/** @return list<array<string, mixed>> */
function editorialTasks(string $type, string $id, bool $onlyOpen = false, int $limit = 100): array
{
    if (!contentRevisionTableExists('editorial_tasks')) {
        return [];
    }

    $key = contentRevisionKeyId($type, $id);
    $sql = 'SELECT t.id, t.content_type, t.content_id, t.title, t.comment, t.assignee_id, t.assignee_email,'
        . ' t.due_at, t.status, t.created_by, t.created_at, t.updated_at, t.completed_at,'
        . ' u.name AS assignee_name FROM ' . t('editorial_tasks') . ' t'
        . ' LEFT JOIN ' . t('user') . ' u ON u.id = t.assignee_id'
        . ' WHERE t.content_type = ? AND t.content_id = ?' . ($onlyOpen ? ' AND t.status = ?' : '')
        . ' ORDER BY t.status, t.due_at, t.created_at LIMIT ' . max(1, min(200, $limit));
    $args = [$type, $key];

    if ($onlyOpen) {
        $args[] = 'open';
    }

    return all($sql, $args);
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, error: string}
 */
function editorialTaskStatus(string $taskId, string $status, array $user, ?DateTimeImmutable $now = null): array
{
    if (!in_array($status, ['open', 'done', 'cancelled'], true)) {
        return ['ok' => false, 'error' => 'Неизвестное состояние задачи.'];
    }

    if (!contentRevisionTableExists('editorial_tasks')) {
        return ['ok' => false, 'error' => 'Задачи ещё не установлены.'];
    }

    $row = one('SELECT id, content_type, content_id, created_by, assignee_id FROM ' . t('editorial_tasks') . ' WHERE id = ? LIMIT 1', [$taskId]);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Задача не найдена.'];
    }

    $moment = utcStamp($now ?? nowUtc());
    run(
        'UPDATE ' . t('editorial_tasks') . ' SET status = ?, updated_at = ?, completed_at = ? WHERE id = ?',
        [$status, $moment, $status === 'done' ? $moment : null, $taskId]
    );

    auditLog('editorial.task_status', (string) $row['content_type'], (string) $row['content_id'], ['taskId' => $taskId, 'status' => $status], 'Состояние редакционной задачи изменено');

    return ['ok' => true, 'error' => ''];
}

// ---------------------------------------------------------------------------
// Ограничение частоты
// ---------------------------------------------------------------------------

/** @param array<string, mixed>|null $user */
function editorialRateAllow(string $bucket, int $limit = EDITORIAL_RATE_LIMIT, int $window = EDITORIAL_RATE_WINDOW_SECONDS, ?array $user = null, ?DateTimeImmutable $now = null): bool
{
    if (!contentRevisionTableExists('editorial_rates')) {
        return true;
    }

    $now ??= nowUtc();
    $moment = utcStamp($now);
    $cutoff = utcStamp(shiftSeconds($now, -$window));
    $key = mb_substr($bucket . ':' . (string) ($user['id'] ?? 'guest'), 0, 120);

    run('DELETE FROM ' . t('editorial_rates') . ' WHERE created_at < ? LIMIT 500', [$cutoff]);

    $count = (int) value(
        'SELECT COUNT(*) FROM ' . t('editorial_rates') . ' WHERE bucket = ? AND created_at >= ?',
        [$key, $cutoff]
    );

    if ($count >= $limit) {
        return false;
    }

    insert('editorial_rates', [
        'bucket' => $key,
        'user_id' => $user['id'] ?? null,
        'ip' => clientIp(),
        'created_at' => $moment,
    ]);

    return true;
}
