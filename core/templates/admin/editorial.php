<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var string $type */
/** @var string $id */
/** @var string $view */
/** @var list<string> $rights */
/** @var list<array<string, mixed>> $team */
/** @var bool $overview */
/** @var list<array<string, mixed>> $queue */
/** @var list<array<string, mixed>> $history */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array<string, mixed>|null $state */
/** @var int $version */
/** @var array<string, mixed>|null $latest */
/** @var array<string, mixed>|null $lock */
/** @var list<array<string, mixed>> $presence */
/** @var list<array<string, mixed>> $comments */
/** @var list<array<string, mixed>> $tasks */
/** @var list<array<string, mixed>> $schedules */
/** @var array<string, mixed>|null $diff */
/** @var array<string, mixed>|null $revision */
/** @var array<string, mixed>|null $compare */
/** @var string $title */
/** @var string $contentUrl */
/** @var array{operation: string, author: string, from: string, to: string} $filters */
/** @var int $unread */
/** @var list<array<string, mixed>> $locks */
/** @var list<array<string, mixed>> $upcoming */

// RELEASE 2.2: редакционный центр. Экраны серверные, права проверены обработчиком; скрипт
// admin-editorial.js только ускоряет работу — без него все формы сохраняются обычным POST.
$can = static fn (string $right): bool => in_array($right, $rights, true);
$base = '/admin/editorial';
$contentQuery = ['type' => $type, 'id' => $id];
$viewUrl = static function (string $target) use ($base, $contentQuery): string {
    return $base . '?' . http_build_query($contentQuery + ['view' => $target]);
};
$person = static function (array $row): string {
    $name = trim((string) ($row['author_name'] ?? ''));

    if ($name === '') {
        $name = trim((string) ($row['author_email'] ?? ''));
    }

    return $name !== '' ? $name : 'система';
};
$when = static fn ($value): string => trim((string) $value) === ''
    ? '—'
    : formatRuDateTime(fromDb((string) $value));
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);
$settings = '';

if ((string) (currentUser()['role'] ?? '') === ROLES['admin']) {
    $settings = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold">Хранение истории</h2>'
        . '<p class="mt-1.5 text-sm text-ink/70">Опубликованные версии, последняя опубликованная и история материалов на согласовании не удаляются никогда. Настройка ограничивает только черновые снимки.</p>'
        . '<form class="mt-3 flex flex-wrap items-end gap-3" method="post" action="' . e($base) . '">' . csrfField()
        . '<input type="hidden" name="op" value="settings-save">'
        . '<label class="block text-sm"><span class="block font-medium">Ревизий на материал</span>'
        . '<input class="mt-1.5 min-h-[44px] w-36 rounded-lg border border-ink/20 bg-white px-3 py-2" type="number" name="revision_max" min="10" max="5000" value="' . e(getOption('REVISION_MAX_PER_CONTENT', (string) CONTENT_REVISION_DEFAULT_LIMIT)) . '"></label>'
        . '<label class="block text-sm"><span class="block font-medium">Срок хранения, дней</span>'
        . '<input class="mt-1.5 min-h-[44px] w-36 rounded-lg border border-ink/20 bg-white px-3 py-2" type="number" name="revision_days" min="7" max="3650" value="' . e(getOption('REVISION_RETENTION_DAYS', (string) CONTENT_REVISION_DEFAULT_DAYS)) . '"></label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Сохранить настройки</button>'
        . '</form></section>';
}

if ($overview) {
    $queueRows = '';

    foreach ($queue as $row) {
        $queueRows .= '<tr class="border-b border-ink/10 align-top">'
            . '<td class="py-3 pr-3 pl-4"><a class="inline-flex min-h-[44px] items-center font-medium underline-offset-4 hover:underline" href="' . e((string) $row['editor_url']) . '">' . e((string) $row['title']) . '</a>'
            . '<p class="text-xs text-ink/50">' . e((string) $row['type_label']) . ' · версия ' . e((string) $row['version']) . '</p></td>'
            . '<td class="py-3 pr-3"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs ' . e((string) $row['state_class']) . '">' . e((string) $row['state_label']) . '</span></td>'
            . '<td class="py-3 pr-3 text-sm text-ink/70">' . e((string) ($row['user_name'] ?? $row['user_email'] ?? 'система')) . '</td>'
            . '<td class="py-3 pr-4 text-sm whitespace-nowrap text-ink/60">' . e($when($row['updated_at'])) . '</td>'
            . '</tr>';
    }

    $historyRows = '';

    foreach ($history as $row) {
        $historyRows .= '<tr class="border-b border-ink/10 align-top">'
            . '<td class="py-3 pr-3 pl-4"><a class="inline-flex min-h-[44px] items-center font-medium underline-offset-4 hover:underline" href="' . e((string) $row['editor_url']) . '">' . e((string) $row['title']) . '</a>'
            . '<p class="text-xs text-ink/50">' . e((string) $row['type_label']) . '</p></td>'
            . '<td class="py-3 pr-3 text-sm">' . e((string) $row['operation_label']) . ' · v' . e((string) $row['version']) . '</td>'
            . '<td class="py-3 pr-3 text-sm text-ink/70">' . e($person($row)) . '</td>'
            . '<td class="py-3 pr-4 text-sm whitespace-nowrap text-ink/60">' . e($when($row['created_at'])) . '</td>'
            . '</tr>';
    }

    $lockRows = '';

    foreach ($locks as $row) {
        $lockTitle = editorialContentTitle((string) $row['content_type'], (string) $row['content_id']);
        $lockRows .= '<li class="border-b border-ink/10 py-2 text-sm">'
            . '<span class="font-medium">' . e($lockTitle) . '</span> · '
            . e((string) ($row['user_name'] ?? $row['user_email'] ?? 'редактор'))
            . ' <span class="text-ink/50">до ' . e($when($row['expires_at'])) . '</span></li>';
    }

    $upcomingRows = '';

    foreach ($upcoming as $row) {
        $upcomingRows .= '<li class="border-b border-ink/10 py-2 text-sm">'
            . '<span class="font-medium">' . e(editorialContentTitle((string) $row['content_type'], (string) $row['content_id'])) . '</span> · '
            . e((string) $row['action'] === 'publish' ? 'публикация' : 'снятие')
            . ' <span class="text-ink/50">' . e($when($row['publish_at'])) . ' (' . e((string) $row['timezone']) . ')</span></li>';
    }

    $overviewHtml = '<div class="grid gap-4 lg:grid-cols-2">'
        . '<section class="rounded-lg border border-ink/10 bg-white p-4"><h2 class="font-semibold">Очередь редакции</h2>'
        . ($queueRows === ''
            ? '<p class="mt-2 text-sm text-ink/60">Очередь пуста: откройте материал в конструкторе и отправьте его на проверку.</p>'
            : '<div class="mt-2 overflow-x-auto"><table class="w-full min-w-[34rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60"><th class="py-2 pr-3 pl-4 font-medium">Материал</th><th class="py-2 pr-3 font-medium">Стадия</th><th class="py-2 pr-3 font-medium">Кто</th><th class="py-2 pr-4 font-medium">Когда</th></tr></thead><tbody>' . $queueRows . '</tbody></table></div>')
        . '</section>'
        . '<section class="rounded-lg border border-ink/10 bg-white p-4"><h2 class="font-semibold">Последние ревизии</h2>'
        . ($historyRows === ''
            ? '<p class="mt-2 text-sm text-ink/60">История пока пуста. Первая ревизия появится при сохранении материала.</p>'
            : '<div class="mt-2 overflow-x-auto"><table class="w-full min-w-[34rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60"><th class="py-2 pr-3 pl-4 font-medium">Материал</th><th class="py-2 pr-3 font-medium">Версия</th><th class="py-2 pr-3 font-medium">Автор</th><th class="py-2 pr-4 font-medium">Когда</th></tr></thead><tbody>' . $historyRows . '</tbody></table></div>')
        . '</section>'
        . '<section class="rounded-lg border border-ink/10 bg-white p-4"><h2 class="font-semibold">Кто сейчас редактирует</h2>'
        . ($lockRows === '' ? '<p class="mt-2 text-sm text-ink/60">Активных блокировок нет.</p>' : '<ul class="mt-2">' . $lockRows . '</ul>')
        . '</section>'
        . '<section class="rounded-lg border border-ink/10 bg-white p-4"><h2 class="font-semibold">Ближайшие публикации</h2>'
        . ($upcomingRows === '' ? '<p class="mt-2 text-sm text-ink/60">Расписание пусто.</p>' : '<ul class="mt-2">' . $upcomingRows . '</ul>')
        . '</section></div>';

    /* @safe html */ echo adminShell('Редакция', $alert . $overviewHtml . $settings, $notice);

    return;
}

$stateStatus = (string) ($state['status'] ?? 'draft');
$stateBadge = '<span class="inline-flex rounded-full border px-2.5 py-1 text-sm ' . e(editorialStateClass($stateStatus)) . '">'
    . e(editorialStateLabel($stateStatus)) . '</span>';
$lockHtml = $lock === null
    ? '<span class="text-sm text-good">Свободен для правки</span>'
    : '<span class="text-sm text-bad">Редактирует ' . e((string) ($lock['user_name'] ?? $lock['user_email'] ?? 'другой редактор'))
        . ' до ' . e($when($lock['expires_at'])) . '</span>';
$presenceHtml = '';

foreach ($presence as $row) {
    $presenceHtml .= '<li class="text-sm text-ink/70">' . e((string) ($row['user_name'] ?? $row['user_email'] ?? 'редактор'))
        . ' · с ' . e($when($row['entered_at'])) . '</li>';
}

$header = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<div class="flex flex-wrap items-start justify-between gap-3">'
    . '<div><p class="text-sm text-ink/50">' . e(editorialTypeLabel($type)) . '</p>'
    . '<h2 class="text-xl font-semibold">' . e($title) . '</h2>'
    . '<p class="mt-1 text-sm text-ink/60">Версия ' . e((string) $version) . ' · ' . $lockHtml . '</p></div>'
    . '<div class="flex flex-wrap items-center gap-2">' . $stateBadge
    . ($contentUrl !== '' ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="' . e($contentUrl) . '" target="_blank" rel="noopener noreferrer">Открыть на сайте</a>' : '')
    . '</div></div>'
    . ($presenceHtml !== '' ? '<div class="mt-3"><p class="text-xs font-medium text-ink/50">Сейчас смотрят</p><ul>' . $presenceHtml . '</ul></div>' : '')
    . '</section>';

$tabs = '<nav class="flex flex-wrap gap-2" aria-label="Разделы редакции">';
$tabLabels = ['history' => 'История', 'diff' => 'Сравнение', 'comments' => 'Комментарии', 'tasks' => 'Задачи', 'schedule' => 'Расписание'];

foreach ($tabLabels as $tabView => $tabLabel) {
    $active = $view === $tabView;
    $tabs .= '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm ' . ($active ? 'bg-ink text-cream' : 'border border-ink/20 hover:bg-ink/5') . '" href="' . e($viewUrl($tabView)) . '">' . e($tabLabel) . '</a>';
}

$tabs .= '</nav>';

// Переходы состояния: кнопка показывается только если право есть и текущая стадия допускает
// переход. Сервер проверяет то же самое ещё раз — интерфейс не является защитой.
$transitions = '';
$transitionLabels = [
    'submit' => 'Отправить на проверку',
    'return' => 'Вернуть на доработку',
    'approve' => 'Одобрить',
    'publish' => 'Опубликовать',
    'unpublish' => 'Снять с публикации',
    'draft' => 'Вернуть в черновик',
];

foreach ($transitionLabels as $action => $label) {
    $plan = editorialTransitionPlan($action);

    if ($plan === null || !$can((string) $plan['right']) || !in_array($stateStatus, $plan['from'], true)) {
        continue;
    }

    $transitions .= '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit" name="op" value="' . e($action) . '">' . e($label) . '</button>';
}

$workflow = '';

if ($transitions !== '' || $can('publish')) {
    $workflow = '<section class="rounded-lg border border-ink/10 bg-white p-4"><h2 class="font-semibold">Согласование</h2>'
        . '<form class="mt-3 space-y-3" method="post" action="' . e($base) . '">' . csrfField()
        . '<input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<input type="hidden" name="view" value="history"><input type="hidden" name="expected_version" value="' . e((string) $version) . '">'
        . '<label class="block text-sm"><span class="block font-medium">Комментарий к переходу</span>'
        . '<textarea class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" name="comment" rows="2" maxlength="500"></textarea></label>'
        . '<div class="flex flex-wrap gap-2">' . $transitions . '</div></form></section>';
}

$historyHtml = '';

if ($view === 'history') {
    $operationOptions = '<option value="">Все операции</option>';

    foreach (CONTENT_REVISION_OPERATIONS as $operation) {
        $selected = $filters['operation'] === $operation ? ' selected' : '';
        $operationOptions .= '<option value="' . e($operation) . '"' . $selected . '>' . e(contentRevisionOperationLabel($operation)) . '</option>';
    }

    $authorOptions = '<option value="">Все авторы</option>';

    foreach ($team as $member) {
        $selected = $filters['author'] === (string) $member['id'] ? ' selected' : '';
        $authorOptions .= '<option value="' . e((string) $member['id']) . '"' . $selected . '>'
            . e((string) ($member['name'] !== '' ? $member['name'] : $member['email'])) . '</option>';
    }

    $filterForm = '<form class="flex flex-wrap items-end gap-2" method="get" action="' . e($base) . '">'
        . '<input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="view" value="history">'
        . '<label class="block text-sm"><span class="block font-medium">Операция</span><select class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" name="operation">' . $operationOptions . '</select></label>'
        . '<label class="block text-sm"><span class="block font-medium">Автор</span><select class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" name="author">' . $authorOptions . '</select></label>'
        . '<label class="block text-sm"><span class="block font-medium">С даты</span><input class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" type="date" name="from" value="' . e($filters['from']) . '"></label>'
        . '<label class="block text-sm"><span class="block font-medium">По дату</span><input class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" type="date" name="to" value="' . e($filters['to']) . '"></label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Показать</button>'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="' . e($viewUrl('history')) . '">Сбросить</a>'
        . '</form>';

    $rowsHtml = '';

    foreach ($history as $row) {
        $revisionId = (string) $row['id'];
        $isLatest = is_array($latest) && (string) $latest['id'] === $revisionId;
        $actions = '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 text-xs hover:bg-ink/5" href="' . e($viewUrl('diff') . '&revision=' . rawurlencode($revisionId)) . '">Сравнить</a>';

        if ($can('restore') && !$isLatest) {
            $actions .= '<form class="inline" method="post" action="' . e($base) . '">' . csrfField()
                . '<input type="hidden" name="op" value="restore"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
                . '<input type="hidden" name="revision" value="' . e($revisionId) . '"><input type="hidden" name="expected_version" value="' . e((string) $version) . '">'
                . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-2.5 py-1 text-xs text-accent hover:bg-accent/10" type="submit">Восстановить</button></form>';
        }

        if ($can('delete_revision') && !$isLatest && (string) $row['operation'] !== 'publish') {
            $actions .= '<form class="inline" method="post" action="' . e($base) . '">' . csrfField()
                . '<input type="hidden" name="op" value="revision-delete"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
                . '<input type="hidden" name="revision" value="' . e($revisionId) . '">'
                . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-2.5 py-1 text-xs text-bad hover:bg-bad/10" type="submit">Удалить</button></form>';
        }

        $rowsHtml .= '<tr class="border-b border-ink/10 align-top">'
            . '<td class="py-3 pr-3 pl-4 whitespace-nowrap font-mono text-sm">v' . e((string) $row['version']) . ($isLatest ? ' <span class="rounded bg-good/10 px-1.5 py-0.5 text-xs text-good">актуальная</span>' : '') . '</td>'
            . '<td class="py-3 pr-3 text-sm">' . e(contentRevisionOperationLabel((string) $row['operation'])) . '<p class="text-xs text-ink/50">' . e((string) $row['source']) . '</p></td>'
            . '<td class="py-3 pr-3 text-sm text-ink/70">' . e($person($row)) . '</td>'
            . '<td class="py-3 pr-3 text-sm text-ink/70">' . e($when($row['created_at'])) . '</td>'
            . '<td class="py-3 pr-3 text-sm text-ink/70">' . e((string) $row['comment']) . '</td>'
            . '<td class="py-3 pr-4"><div class="flex flex-wrap items-center gap-1">' . $actions . '</div></td>'
            . '</tr>';
    }

    $table = $rowsHtml === ''
        ? '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">Версий пока нет.</p>'
        : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white"><table class="w-full min-w-[64rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60"><th class="py-2 pr-3 pl-4 font-medium">Версия</th><th class="py-2 pr-3 font-medium">Операция</th><th class="py-2 pr-3 font-medium">Автор</th><th class="py-2 pr-3 font-medium">Когда</th><th class="py-2 pr-3 font-medium">Комментарий</th><th class="py-2 pr-4 font-medium">Действия</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';

    $pager = '';

    if ($pages > 1) {
        $pager = '<div class="flex flex-wrap items-center justify-between gap-3 text-sm"><span class="text-ink/60">Страница ' . e((string) $page) . ' из ' . e((string) $pages) . ' · всего ' . e((string) $total) . '</span><div class="flex gap-2">';

        if ($page > 1) {
            $pager .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 hover:bg-ink/5" href="' . e($viewUrl('history') . '&page=' . ($page - 1)) . '">← Назад</a>';
        }

        if ($page < $pages) {
            $pager .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 hover:bg-ink/5" href="' . e($viewUrl('history') . '&page=' . ($page + 1)) . '">Вперёд →</a>';
        }

        $pager .= '</div></div>';
    }

    $historyHtml = '<section class="space-y-3"><h2 class="font-semibold">История версий</h2>' . $filterForm . $table . $pager . '</section>';
}

$diffHtml = '';

if ($view === 'diff') {
    if (!is_array($revision)) {
        $diffHtml = '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">Выберите версию в истории, чтобы увидеть сравнение.</p>';
    } else {
        $summary = is_array($diff['summary'] ?? null) ? $diff['summary'] : [];
        $summaryLine = 'Добавлено блоков: ' . (int) ($summary['added'] ?? 0)
            . ' · удалено: ' . (int) ($summary['removed'] ?? 0)
            . ' · изменено: ' . (int) ($summary['changed'] ?? 0)
            . ' · перемещено: ' . (int) ($summary['moved'] ?? 0);
        $fieldRows = '';

        foreach ((array) ($diff['fields'] ?? []) as $field) {
            $fieldRows .= '<tr class="border-b border-ink/10 align-top"><td class="py-2 pr-3 font-medium">' . e((string) $field['label']) . '</td>'
                . '<td class="py-2 pr-3 text-bad"><pre class="max-w-[28rem] overflow-x-auto whitespace-pre-wrap">' . e((string) $field['old']) . '</pre></td>'
                . '<td class="py-2 pr-3 text-good"><pre class="max-w-[28rem] overflow-x-auto whitespace-pre-wrap">' . e((string) $field['new']) . '</pre></td></tr>';
        }

        foreach ((array) ($diff['seo'] ?? []) as $field) {
            $fieldRows .= '<tr class="border-b border-ink/10 align-top"><td class="py-2 pr-3 font-medium">' . e((string) $field['label']) . '</td>'
                . '<td class="py-2 pr-3 text-bad"><pre class="max-w-[28rem] overflow-x-auto whitespace-pre-wrap">' . e((string) $field['old']) . '</pre></td>'
                . '<td class="py-2 pr-3 text-good"><pre class="max-w-[28rem] overflow-x-auto whitespace-pre-wrap">' . e((string) $field['new']) . '</pre></td></tr>';
        }

        $fieldsTable = $fieldRows === ''
            ? '<p class="text-sm text-ink/60">Поля и SEO не изменились.</p>'
            : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white"><table class="w-full min-w-[44rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60"><th class="py-2 pr-3 pl-4 font-medium">Поле</th><th class="py-2 pr-3 font-medium">Было</th><th class="py-2 pr-3 font-medium">Стало</th></tr></thead><tbody>' . $fieldRows . '</tbody></table></div>';

        $sectionHtml = '';

        foreach (['removed' => 'Удалены', 'added' => 'Добавлены', 'changed' => 'Изменены', 'moved' => 'Перемещены'] as $kind => $label) {
            foreach ((array) ($diff['sections'][$kind] ?? []) as $section) {
                $sectionHtml .= '<li class="border-b border-ink/10 py-2 text-sm"><span class="font-medium">' . e($label) . ':</span> '
                    . e((string) ($section['name'] ?? $section['sec_key'] ?? 'блок'));
                if (isset($section['old_position'], $section['new_position'])) {
                    $sectionHtml .= ' <span class="text-ink/50">(' . e((string) $section['old_position']) . ' → ' . e((string) $section['new_position']) . ')</span>';
                }
                $sectionHtml .= '</li>';
            }
        }

        $textLines = '';

        foreach ((array) ($diff['text'] ?? []) as $line) {
            $kind = (string) ($line['type'] ?? 'same');
            $class = $kind === 'add' ? 'bg-good/10 text-good' : ($kind === 'remove' ? 'bg-bad/10 text-bad' : 'text-ink/70');
            $prefix = $kind === 'add' ? '+ ' : ($kind === 'remove' ? '− ' : '  ');
            $textLines .= '<div class="px-2 py-0.5 font-mono text-xs whitespace-pre-wrap ' . $class . '">' . e($prefix . (string) ($line['text'] ?? '')) . '</div>';
        }

        $diffHtml = '<section class="space-y-3">'
            . '<div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="font-semibold">Сравнение версий</h2>'
            . '<p class="text-sm text-ink/60">v' . e((string) $revision['version']) . ' от ' . e($when($revision['created_at']))
            . (is_array($compare) ? ' против v' . e((string) $compare['version']) : ' против пустой истории') . '</p></div>'
            . '<p class="text-sm text-ink/70">' . e($summaryLine) . '</p></div>'
            . $fieldsTable
            . ($sectionHtml !== '' ? '<div class="rounded-lg border border-ink/10 bg-white p-4"><h3 class="text-sm font-medium">Блоки</h3><ul class="mt-1">' . $sectionHtml . '</ul></div>' : '')
            . ($textLines !== '' ? '<div class="rounded-lg border border-ink/10 bg-white py-2"><h3 class="px-4 py-1 text-sm font-medium">Текст</h3>' . $textLines . '</div>' : '')
            . '</section>';
    }
}

$commentsHtml = '';

if ($view === 'comments') {
    $commentItems = '';

    foreach ($comments as $comment) {
        $resolved = (string) ($comment['resolved_at'] ?? '') !== '';
        $commentItems .= '<li class="border-b border-ink/10 py-3">'
            . '<div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-medium">'
            . e((string) ($comment['author_name'] ?? $comment['author_email'] ?? 'редактор'))
            . ' <span class="font-normal text-ink/50">' . e($when($comment['created_at'])) . '</span>'
            . ($resolved ? ' <span class="rounded bg-good/10 px-1.5 py-0.5 text-xs text-good">решён</span>' : '') . '</p>';

        if ($can('comment')) {
            $commentItems .= '<form class="inline" method="post" action="' . e($base) . '">' . csrfField()
                . '<input type="hidden" name="op" value="' . ($resolved ? 'comment-reopen' : 'comment-resolve') . '">'
                . '<input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
                . '<input type="hidden" name="comment" value="' . e((string) $comment['id']) . '">'
                . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-2.5 py-1 text-xs hover:bg-ink/5" type="submit">' . ($resolved ? 'Открыть заново' : 'Отметить решённым') . '</button></form>';
        }

        $commentItems .= '</div><div class="mt-1 whitespace-pre-wrap text-sm">' . e((string) $comment['body']) . '</div></li>';
    }

    $commentForm = $can('comment')
        ? '<form class="space-y-3" method="post" action="' . e($base) . '">' . csrfField()
            . '<input type="hidden" name="op" value="comment"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
            . '<label class="block text-sm"><span class="block font-medium">Новый комментарий</span>'
            . '<textarea class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" name="body" rows="3" maxlength="' . e((string) EDITORIAL_COMMENT_MAX) . '" placeholder="Текст и @почта для упоминания"></textarea></label>'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Добавить комментарий</button></form>'
        : '<p class="text-sm text-ink/60">Комментирование недоступно для вашей роли.</p>';

    $commentsHtml = '<section class="space-y-3"><h2 class="font-semibold">Внутренние комментарии</h2>'
        . '<p class="text-sm text-ink/60">Комментарии видны только редакции и никогда не попадают на публичные страницы.</p>'
        . '<div class="rounded-lg border border-ink/10 bg-white p-4">' . $commentForm . '</div>'
        . ($commentItems === '' ? '<p class="text-sm text-ink/60">Комментариев пока нет.</p>' : '<ul class="rounded-lg border border-ink/10 bg-white p-4">' . $commentItems . '</ul>')
        . '</section>';
}

$tasksHtml = '';

if ($view === 'tasks') {
    $taskItems = '';

    foreach ($tasks as $task) {
        $taskItems .= '<li class="border-b border-ink/10 py-3">'
            . '<div class="flex flex-wrap items-center justify-between gap-2"><div><p class="text-sm font-medium">' . e((string) $task['title']) . '</p>'
            . '<p class="text-xs text-ink/50">' . e((string) ($task['assignee_name'] ?? $task['assignee_email'] ?? 'без исполнителя'))
            . ' · срок ' . e($when($task['due_at'])) . ' · ' . e((string) $task['status']) . '</p>'
            . ((string) $task['comment'] !== '' ? '<p class="mt-1 text-sm text-ink/70">' . e((string) $task['comment']) . '</p>' : '')
            . '</div>';

        if ($can('manage_tasks')) {
            $taskItems .= '<form class="flex gap-1" method="post" action="' . e($base) . '">' . csrfField()
                . '<input type="hidden" name="op" value="task-status"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
                . '<input type="hidden" name="task" value="' . e((string) $task['id']) . '">'
                . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 text-xs hover:bg-ink/5" type="submit" name="status" value="done">Готово</button>'
                . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 text-xs hover:bg-ink/5" type="submit" name="status" value="cancelled">Отменить</button>'
                . '</form>';
        }

        $taskItems .= '</div></li>';
    }

    $assigneeOptions = '';

    foreach ($team as $member) {
        $assigneeOptions .= '<option value="' . e((string) $member['id']) . '">' . e((string) ($member['name'] !== '' ? $member['name'] : $member['email'])) . '</option>';
    }

    $taskForm = $can('manage_tasks')
        ? '<form class="grid gap-3 sm:grid-cols-2" method="post" action="' . e($base) . '">' . csrfField()
            . '<input type="hidden" name="op" value="task-create"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
            . '<label class="block text-sm sm:col-span-2"><span class="block font-medium">Задача</span><input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" type="text" name="title" maxlength="190" required></label>'
            . '<label class="block text-sm"><span class="block font-medium">Исполнитель</span><select class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" name="assignee" required>' . $assigneeOptions . '</select></label>'
            . '<label class="block text-sm"><span class="block font-medium">Срок</span><input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" type="datetime-local" name="due_at"></label>'
            . '<label class="block text-sm sm:col-span-2"><span class="block font-medium">Описание</span><textarea class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" name="comment" rows="2" maxlength="1000"></textarea></label>'
            . '<button class="inline-flex min-h-[44px] w-fit items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Назначить задачу</button></form>'
        : '';

    $tasksHtml = '<section class="space-y-3"><h2 class="font-semibold">Задачи редакции</h2>'
        . ($taskForm !== '' ? '<div class="rounded-lg border border-ink/10 bg-white p-4">' . $taskForm . '</div>' : '')
        . ($taskItems === '' ? '<p class="text-sm text-ink/60">Задач пока нет.</p>' : '<ul class="rounded-lg border border-ink/10 bg-white p-4">' . $taskItems . '</ul>')
        . '</section>';
}

$scheduleHtml = '';

if ($view === 'schedule') {
    $scheduleItems = '';

    foreach ($schedules as $schedule) {
        $scheduleItems .= '<li class="flex flex-wrap items-center justify-between gap-2 border-b border-ink/10 py-3 text-sm">'
            . '<div><p class="font-medium">' . e((string) $schedule['action'] === 'publish' ? 'Публикация' : 'Снятие с публикации') . '</p>'
            . '<p class="text-xs text-ink/50">' . e($when($schedule['publish_at'])) . ' (' . e((string) $schedule['timezone']) . ') · ' . e((string) $schedule['status'])
            . ' · попыток ' . e((string) $schedule['attempts']) . '</p>'
            . ((string) $schedule['last_error'] !== '' ? '<p class="text-xs text-bad">' . e((string) $schedule['last_error']) . '</p>' : '') . '</div>';

        if ($can('schedule')) {
            $scheduleItems .= '<form class="inline" method="post" action="' . e($base) . '">' . csrfField()
                . '<input type="hidden" name="op" value="schedule-cancel"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
                . '<input type="hidden" name="schedule" value="' . e((string) $schedule['id']) . '">'
                . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-2.5 py-1 text-xs text-bad hover:bg-bad/10" type="submit">Отменить</button></form>';
        }

        $scheduleItems .= '</li>';
    }

    $timezoneOptions = '';

    foreach (scheduleTimezones() as $timezone) {
        $selected = $timezone === 'Europe/Moscow' ? ' selected' : '';
        $timezoneOptions .= '<option value="' . e($timezone) . '"' . $selected . '>' . e($timezone) . '</option>';
    }

    $scheduleForm = $can('schedule')
        ? '<form class="grid gap-3 sm:grid-cols-2" method="post" action="' . e($base) . '">' . csrfField()
            . '<input type="hidden" name="op" value="schedule-create"><input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . e($id) . '">'
            . '<label class="block text-sm"><span class="block font-medium">Действие</span><select class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" name="action"><option value="publish">Опубликовать</option><option value="unpublish">Снять с публикации</option></select></label>'
            . '<label class="block text-sm"><span class="block font-medium">Когда</span><input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" type="datetime-local" name="publish_at" required></label>'
            . '<label class="block text-sm"><span class="block font-medium">Часовой пояс</span><select class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" name="timezone">' . $timezoneOptions . '</select></label>'
            . '<button class="inline-flex min-h-[44px] w-fit items-center self-end rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Запланировать</button></form>'
        : '<p class="text-sm text-ink/60">Планирование доступно администратору.</p>';

    $scheduleHtml = '<section class="space-y-3"><h2 class="font-semibold">Отложенная публикация</h2>'
        . '<div class="rounded-lg border border-ink/10 bg-white p-4">' . $scheduleForm . '</div>'
        . ($scheduleItems === '' ? '<p class="text-sm text-ink/60">Расписание пусто.</p>' : '<ul class="rounded-lg border border-ink/10 bg-white p-4">' . $scheduleItems . '</ul>')
        . '<p class="text-sm text-ink/60">Планировщик запускается командой <code class="rounded bg-ink/5 px-1">php bin/cron.php</code> по расписанию хостинга. Ошибки повторяются автоматически и остаются в журнале.</p>'
        . '</section>';
}

$body = $alert . $header . $tabs . $workflow . $historyHtml . $diffHtml . $commentsHtml . $tasksHtml . $scheduleHtml;

/* @safe html */ echo adminShell('Редакция — ' . $title, $body, $notice);
