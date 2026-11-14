<?php

declare(strict_types=1);

// RELEASE 2.2: экраны редакционного центра. GET /admin/editorial — обзор очереди или панель
// материала (история, сравнение, комментарии, задачи, расписание). POST — один обработчик с op:
// он же служит JSON API для автосохранения, блокировок и версий. GET/POST /admin/notifications —
// центр уведомлений. Публичных маршрутов у редакции нет: комментарии и история не видны посетителю.

/** @return array<string, mixed> */
function editorialRequestContext(): array
{
    $type = postString('type');

    if (!contentRevisionTypeValid($type)) {
        $type = (string) queryString('type', 'page');
    }

    if (!contentRevisionTypeValid($type)) {
        $type = 'page';
    }

    $id = postString('id');

    if ($id === '') {
        $id = (string) queryString('id', '');
    }

    return ['type' => $type, 'id' => trim($id)];
}

function editorialWantsJson(): bool
{
    return (string) ($_SERVER['HTTP_X_CMS_EDITORIAL'] ?? '') === '1' || postString('format') === 'json';
}

/** @param array<string, mixed> $payload */
function editorialJson(array $payload, int $status = 200): never
{
    respond(
        $status,
        (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]
    );
}

function editorialRedirectUrl(string $type, string $id, string $view = 'history'): string
{
    $url = '/admin/editorial?' . http_build_query(['type' => $type, 'id' => $id]);

    return $view !== '' ? $url . '&view=' . rawurlencode($view) : $url;
}

/** @param array<string, string> $params */
function coreAdminEditorial(array $params = []): void
{
    $user = currentUser();

    if (!is_array($user) || !editorialCan('view_history', $user)) {
        forbidden('История версий доступна только редакции.');
    }

    $typeRaw = queryString('type', 'page');
    $type = is_string($typeRaw) && contentRevisionTypeValid($typeRaw) ? $typeRaw : 'page';
    $idRaw = queryString('id', '');
    $id = is_string($idRaw) ? trim($idRaw) : '';
    $viewRaw = queryString('view', 'history');
    $view = is_string($viewRaw) && in_array($viewRaw, ['history', 'diff', 'comments', 'tasks', 'schedule'], true)
        ? $viewRaw
        : 'history';

    $data = [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle(takeAdminFlashReason()),
        'alertReason' => (string) (noticeOfRequest(CORE_NOTICE_TEXT) === null ? '' : ''),
        'type' => $type,
        'id' => $id,
        'view' => $view,
        'rights' => editorialRights($user),
        'team' => editorialTeam(),
        'overview' => $id === '',
        'queue' => [],
        'history' => [],
        'total' => 0,
        'state' => null,
        'version' => 0,
        'latest' => null,
        'lock' => null,
        'presence' => [],
        'comments' => [],
        'tasks' => [],
        'schedules' => [],
        'diff' => null,
        'revision' => null,
        'compare' => null,
        'title' => 'Редакция',
        'contentUrl' => '',
        'filters' => [
            'operation' => (string) queryString('operation', ''),
            'author' => (string) queryString('author', ''),
            'from' => (string) queryString('from', ''),
            'to' => (string) queryString('to', ''),
        ],
        'unread' => editorialNotificationUnread((string) $user['id']),
        'locks' => editorialActiveLocks(),
        'upcoming' => scheduledPublicationList('pending', 50),
        'page' => 1,
        'pages' => 1,
    ];

    if ($id === '') {
        $data['queue'] = editorialQueue();
        $data['history'] = editorialRecentRevisions();

        render('admin/editorial', $data, [
            'title' => 'Редакция — админка',
            'noindex' => true,
            'head' => ADMIN_EDITORIAL_HEAD,
        ]);

        return;
    }

    if (!editorialContentExists($type, $id)) {
        notFound('Материал не найден — возможно, его удалили.');
    }

    contentRevisionEnsureBaseline($type, $id);

    $filters = $data['filters'];
    $page = max(1, (int) queryString('page', '1'));
    $perPage = CONTENT_REVISION_PAGE_SIZE;
    $total = contentRevisionCount($type, $id, $filters);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);

    $data['history'] = contentRevisionList($type, $id, $filters, $perPage, ($page - 1) * $perPage);
    $data['total'] = $total;
    $data['page'] = $page;
    $data['pages'] = $pages;
    $data['state'] = editorialState($type, $id);
    $data['version'] = contentRevisionVersion($type, $id);
    $data['latest'] = contentRevisionLatest($type, $id);
    $data['lock'] = editorialLockState($type, $id);
    $data['presence'] = editorialPresenceList($type, $id);
    $data['comments'] = editorialCommentList($type, $id, true);
    $data['tasks'] = editorialTasks($type, $id);
    $data['schedules'] = scheduledPublicationFor($type, $id);
    $data['title'] = editorialContentTitle($type, $id);
    $data['contentUrl'] = editorialContentUrl($type, $id);

    if ($view === 'diff') {
        $revisionRaw = queryString('revision', '');
        $compareRaw = queryString('compare', '');
        $revisionId = is_string($revisionRaw) ? $revisionRaw : '';
        $compareId = is_string($compareRaw) ? $compareRaw : '';
        $latest = $data['latest'];

        if ($revisionId === '' && is_array($latest)) {
            $revisionId = (string) $latest['id'];
        }

        $revision = $revisionId !== '' ? contentRevisionGet($revisionId) : null;

        if ($revision === null || (string) $revision['content_type'] !== $type
            || (string) $revision['content_id'] !== contentRevisionKeyId($type, $id)) {
            $revision = null;
        }

        if ($compareId === '' && is_array($revision)) {
            $parentId = (string) ($revision['parent_id'] ?? '');
            $compareId = $parentId;
        }

        $compare = $compareId !== '' ? contentRevisionGet($compareId) : null;

        if ($compare === null || (string) $compare['content_type'] !== $type
            || (string) $compare['content_id'] !== contentRevisionKeyId($type, $id)) {
            $compare = null;
        }

        $data['revision'] = $revision;
        $data['compare'] = $compare;

        if (is_array($revision)) {
            $data['diff'] = contentDiffPayloads(
                is_array($compare['payload'] ?? null) ? $compare['payload'] : [],
                is_array($revision['payload'] ?? null) ? $revision['payload'] : []
            );
        }
    }

    render('admin/editorial', $data, [
        'title' => 'Редакция — ' . $data['title'],
        'noindex' => true,
        'head' => ADMIN_EDITORIAL_HEAD,
    ]);
}

/** @param array<string, string> $params */
function coreAdminEditorialAction(array $params = []): void
{
    csrfCheck();

    $user = currentUser();

    if (!is_array($user)) {
        forbidden('Нужен вход в редакцию.');
    }

    $op = postString('op');
    $context = editorialRequestContext();
    $type = (string) $context['type'];
    $id = (string) $context['id'];
    $view = postString('view');
    $view = in_array($view, ['history', 'diff', 'comments', 'tasks', 'schedule'], true) ? $view : 'history';
    $wantsJson = editorialWantsJson();

    if ($id === '' && $op !== 'settings-save') {
        $result = ['ok' => false, 'error' => 'Материал не указан.'];

        if ($wantsJson) {
            editorialJson($result, 422);
        }

        finishAppAction($result, '/admin/editorial', '');
    }

    if (!editorialRateAllow('editorial:' . $op, EDITORIAL_RATE_LIMIT * 4, EDITORIAL_RATE_WINDOW_SECONDS, $user)) {
        $result = ['ok' => false, 'error' => 'Слишком много запросов — подождите минуту и повторите.'];

        if ($wantsJson) {
            editorialJson($result, 429);
        }

        finishAppAction($result, editorialRedirectUrl($type, $id, $view), '');
    }

    $result = ['ok' => false, 'error' => 'Неизвестное действие редакции.'];
    $notice = '';
    $status = 200;
    $redirectView = $view;

    if ($op === 'version' || $op === 'check-version') {
        if (!editorialCan('view_history', $user)) {
            $result = ['ok' => false, 'error' => 'Недостаточно прав.'];
        } else {
            $version = contentRevisionVersion($type, $id);
            $expected = postInt('expected_version');
            $lock = editorialLockState($type, $id);
            $result = [
                'ok' => $expected === null || $expected === $version,
                'error' => $expected !== null && $expected !== $version
                    ? 'Материал был изменён другим пользователем. Необходимо сравнить версии перед сохранением.'
                    : '',
                'version' => $version,
                'state' => editorialState($type, $id),
                'lock' => $lock,
                'presence' => editorialPresenceList($type, $id),
                'latest' => contentRevisionLatest($type, $id),
            ];
            $status = $result['ok'] ? 200 : 409;
        }
    } elseif ($op === 'presence') {
        $result = editorialPresenceTouch($type, $id, $user, editorialSessionId())
            + ['version' => contentRevisionVersion($type, $id), 'lock' => editorialLockState($type, $id), 'presence' => editorialPresenceList($type, $id)];
        $result['ok'] = true;
    } elseif ($op === 'lock-acquire') {
        $result = editorialLockAcquire(
            $type,
            $id,
            editorialSessionId(),
            $user,
            editorialCan('unlock', $user) && postString('force') === '1'
        );
        $status = $result['ok'] ? 200 : 409;
    } elseif ($op === 'lock-heartbeat') {
        $result = editorialLockHeartbeat($type, $id, postString('token'), editorialSessionId());
        $status = $result['ok'] ? 200 : 409;
    } elseif ($op === 'lock-release') {
        $result = editorialLockRelease($type, $id, postString('token'), $user, editorialCan('unlock', $user) && postString('force') === '1');
        $status = $result['ok'] ? 200 : 403;
    } elseif ($op === 'autosave-page') {
        $result = editorialAutosavePage($type, $id, $user);
        $status = ($result['ok'] ?? false) === true ? 200 : (isset($result['conflict']) ? 409 : 422);
        $notice = 'editorial_saved';
    } elseif ($op === 'restore') {
        $result = editorialRestore($type, $id, $user);
        $status = ($result['ok'] ?? false) === true ? 200 : (isset($result['conflict']) ? 409 : 422);
        $notice = 'editorial_restored';
        $redirectView = 'history';
    } elseif ($op === 'revision-delete') {
        $result = editorialDeleteRevision($type, $id, $user);
        $notice = 'editorial_revision_deleted';
    } elseif (in_array($op, ['submit', 'return', 'approve', 'publish', 'unpublish', 'draft'], true)) {
        $result = editorialTransition($type, $id, $op, [
            'user' => $user,
            'expected_version' => postInt('expected_version'),
            'comment' => postString('comment'),
        ]);
        $status = ($result['ok'] ?? false) === true ? 200 : (isset($result['conflict']) ? 409 : 422);
        $notice = match ($op) {
            'submit' => 'editorial_submit',
            'return' => 'editorial_return',
            'approve' => 'editorial_approve',
            'publish' => 'editorial_publish',
            'unpublish' => 'editorial_unpublish',
            'draft' => 'editorial_draft',
        };
        $redirectView = 'history';
    } elseif ($op === 'schedule-create') {
        $result = scheduledPublicationCreate(
            $type,
            $id,
            postString('action') !== '' ? postString('action') : 'publish',
            postString('publish_at'),
            postString('timezone') !== '' ? postString('timezone') : 'Europe/Moscow',
            $user
        );
        $notice = 'editorial_schedule_saved';
        $redirectView = 'schedule';
    } elseif ($op === 'schedule-cancel') {
        $result = scheduledPublicationCancel(postString('schedule'), $user);
        $notice = 'editorial_schedule_cancelled';
        $redirectView = 'schedule';
    } elseif ($op === 'comment') {
        $result = editorialCommentCreate(
            $type,
            $id,
            postString('body'),
            $user,
            postString('parent'),
            postString('section')
        );
        $notice = 'editorial_comment_saved';
        $redirectView = 'comments';
    } elseif ($op === 'comment-resolve' || $op === 'comment-reopen') {
        if (!editorialCan('comment', $user)) {
            $result = ['ok' => false, 'error' => 'Недостаточно прав.'];
        } else {
            $result = editorialCommentResolve(postString('comment'), $op === 'comment-resolve', $user);
        }
        $notice = 'editorial_comment_saved';
        $redirectView = 'comments';
    } elseif ($op === 'task-create') {
        $result = editorialTaskCreate(
            $type,
            $id,
            postString('title'),
            postString('assignee'),
            $user,
            postString('comment'),
            parseFormDateTime(postString('due_at'))
        );
        $notice = 'editorial_task_saved';
        $redirectView = 'tasks';
    } elseif ($op === 'task-status') {
        $result = editorialTaskStatus(postString('task'), postString('status'), $user);
        $notice = 'editorial_task_saved';
        $redirectView = 'tasks';
    } elseif ($op === 'notification-read' || $op === 'notification-read-all') {
        $read = editorialNotificationRead($user['id'], postString('notification'))
            || $op === 'notification-read-all';
        $result = ['ok' => true, 'error' => '', 'read' => $read];
        $notice = 'editorial_notifications_read';
        $redirectView = '';
    } elseif ($op === 'settings-save') {
        $result = editorialSettingsSave($user);
        $notice = 'editorial_settings_saved';
        $redirectView = '';
    }

    if ($wantsJson) {
        editorialJson($result, $status);
    }

    $to = $op === 'settings-save'
        ? '/admin/editorial'
        : ($redirectView === '' ? '/admin/notifications' : editorialRedirectUrl($type, $id, $redirectView));
    finishAppAction($result, $to, $notice);
}

function postInt(string $key): ?int
{
    $raw = postString($key);

    return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
}

/**
 * Автосохранение полей страницы. Идемпотентность считается по содержимому и ожидаемой версии:
 * повторная отправка того же черновика возвращает прежнюю ревизию, а не создаёт вторую.
 *
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function editorialAutosavePage(string $type, string $id, array $user): array
{
    if ($type !== 'page') {
        return ['ok' => false, 'error' => 'Автосохранение полей поддерживается для страниц.'];
    }

    if (!editorialCan('autosave', $user)) {
        return ['ok' => false, 'error' => 'Недостаточно прав для автосохранения.'];
    }

    if (!editorialContentExists($type, $id)) {
        return ['ok' => false, 'error' => 'Материал не найден.'];
    }

    $blocked = editorialLockBlocks($type, $id, $user);

    if ($blocked !== '') {
        return ['ok' => false, 'error' => $blocked];
    }

    $title = postString('title');
    $slug = postString('slug');

    if ($slug === '') {
        $slug = slugify($title, 80);
    }

    $expected = postInt('expected_version');
    $key = postString('idempotency_key');

    if ($key === '') {
        $key = 'auto-' . substr(hash('sha256', $type . '|' . $id . '|' . (string) $expected . '|' . $slug . '|' . $title . '|' . postString('description') . '|' . postString('status') . '|' . postString('noindex')), 0, 32);
    }

    $values = [
        'title' => $title,
        'slug' => $slug,
        'description' => postString('description'),
        'status' => in_array(postString('status'), PAGES_STATUSES, true) ? postString('status') : PAGES_STATUS_DRAFT,
        'noindex' => postString('noindex'),
    ];

    $result = updatePage($id, $values, null, [
        'operation' => 'autosave',
        'source' => 'autosave',
        'expected_version' => $expected,
        'idempotency_key' => $key,
        'author' => $user,
        'comment' => 'Автосохранение',
    ]);

    $result['version'] = contentRevisionVersion($type, $id);
    $result['saved_at'] = utcStamp(nowUtc());
    $result['idempotency_key'] = $key;

    return $result;
}

/** @param array<string, mixed> $user
 *  @return array<string, mixed>
 */
function editorialRestore(string $type, string $id, array $user): array
{
    if (!editorialCan('restore', $user)) {
        return ['ok' => false, 'error' => 'Недостаточно прав для восстановления.'];
    }

    $result = contentRevisionRestore($type, $id, postString('revision'), [
        'expected_version' => postInt('expected_version'),
        'author' => $user,
        'comment' => postString('comment'),
    ]);

    if (($result['ok'] ?? false) === true) {
        auditLog('editorial.restore', $type, contentRevisionKeyId($type, $id), ['revision' => postString('revision'), 'version' => $result['version']], 'Восстановлена версия материала');
    }

    return $result;
}

/** @param array<string, mixed> $user
 *  @return array<string, mixed>
 */
function editorialDeleteRevision(string $type, string $id, array $user): array
{
    if (!editorialCan('delete_revision', $user)) {
        return ['ok' => false, 'error' => 'Удалять ревизии может только администратор.'];
    }

    $result = contentRevisionDelete($type, $id, postString('revision'), $user);

    if (($result['ok'] ?? false) === true) {
        auditLog('editorial.revision_delete', $type, contentRevisionKeyId($type, $id), ['revision' => postString('revision')], 'Ревизия удалена');
    }

    return $result;
}

/** @param array<string, mixed> $user
 *  @return array{ok: bool, error: string}
 */
function editorialSettingsSave(array $user): array
{
    if ((string) ($user['role'] ?? '') !== ROLES['admin']) {
        return ['ok' => false, 'error' => 'Настройки хранения меняет администратор.'];
    }

    $max = postInt('revision_max');
    $days = postInt('revision_days');

    if ($max === null || $max < 10 || $max > 5000) {
        return ['ok' => false, 'error' => 'Хранить ревизий: число от 10 до 5000.'];
    }

    if ($days === null || $days < 7 || $days > 3650) {
        return ['ok' => false, 'error' => 'Срок хранения: от 7 до 3650 дней.'];
    }

    setOption('REVISION_MAX_PER_CONTENT', (string) $max);
    setOption('REVISION_RETENTION_DAYS', (string) $days);

    return ['ok' => true, 'error' => ''];
}

/** @param array<string, string> $params */
function coreAdminNotifications(array $params = []): void
{
    $user = currentUser();

    if (!is_array($user)) {
        forbidden('Нужен вход в редакцию.');
    }

    $kindRaw = queryString('kind', '');
    $kind = is_string($kindRaw) && in_array($kindRaw, editorialNotificationKinds(), true) ? $kindRaw : '';
    $unreadRaw = queryString('unread', '');

    render('admin/notifications', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle(takeAdminFlashReason()),
        'alertReason' => '',
        'rows' => editorialNotifications((string) $user['id'], $kind, $unreadRaw === '1', 100),
        'unread' => editorialNotificationUnread((string) $user['id']),
        'kind' => $kind,
        'unreadOnly' => $unreadRaw === '1',
        'kinds' => editorialNotificationKinds(),
    ], [
        'title' => 'Уведомления — админка',
        'noindex' => true,
    ]);
}

/** @param array<string, string> $params */
function coreAdminNotificationsAction(array $params = []): void
{
    csrfCheck();

    $user = currentUser();

    if (!is_array($user)) {
        forbidden('Нужен вход в редакцию.');
    }

    $op = postString('op');
    $result = ['ok' => false, 'error' => 'Неизвестное действие.'];

    if ($op === 'read') {
        $result = ['ok' => true, 'error' => '', 'read' => editorialNotificationRead((string) $user['id'], postString('notification'))];
    } elseif ($op === 'read-all') {
        $count = editorialNotificationReadAll((string) $user['id']);
        $result = ['ok' => true, 'error' => '', 'count' => $count];
    }

    finishAppAction($result, '/admin/notifications', 'editorial_notifications_read');
}
