<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array<string, mixed>> $rows */
/** @var int $unread */
/** @var string $kind */
/** @var bool $unreadOnly */
/** @var list<string> $kinds */

// Центр уведомлений редакции: упоминания, проверки, решения, задачи и ошибки планировщика.
// Запись не удаляется вместе с материалом — история остаётся у получателя.
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);
$kindOptions = '<option value="">Все уведомления</option>';

foreach ($kinds as $item) {
    $selected = $kind === $item ? ' selected' : '';
    $kindOptions .= '<option value="' . e($item) . '"' . $selected . '>' . e(editorialNotificationKindLabel($item)) . '</option>';
}

$filters = '<form class="flex flex-wrap items-end gap-2" method="get" action="/admin/notifications">'
    . '<label class="block text-sm"><span class="block font-medium">Тип</span><select class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" name="kind">' . $kindOptions . '</select></label>'
    . adminCheckbox('unread', 'Только непрочитанные', '1', $unreadOnly)
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Показать</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="/admin/notifications">Сбросить</a>'
    . '</form>';

$items = '';

foreach ($rows as $row) {
    $read = (string) ($row['read_at'] ?? '') !== '';
    $url = (string) ($row['url'] ?? '');
    $safeUrl = str_starts_with($url, '/') ? $url : '';
    $items .= '<li class="border-b border-ink/10 py-3' . ($read ? ' opacity-70' : '') . '">'
        . '<div class="flex flex-wrap items-start justify-between gap-2">'
        . '<div><p class="text-sm font-medium">';

    if ($safeUrl !== '') {
        $items .= '<a class="inline-flex min-h-[44px] items-center underline-offset-4 hover:underline" href="' . e($safeUrl) . '">' . e((string) $row['title']) . '</a>';
    } else {
        $items .= e((string) $row['title']);
    }

    $items .= ' <span class="rounded-full border border-ink/20 px-2 py-0.5 text-xs font-normal text-ink/60">'
        . e(editorialNotificationKindLabel((string) $row['kind'])) . '</span>'
        . ($read ? ' <span class="text-xs font-normal text-good">прочитано</span>' : '') . '</p>'
        . ((string) $row['body'] !== '' ? '<p class="mt-1 text-sm text-ink/70">' . e((string) $row['body']) . '</p>' : '')
        . '<p class="mt-1 text-xs text-ink/50">' . e(formatRuDateTime(fromDb((string) $row['created_at']))) . '</p></div>';

    if (!$read) {
        $items .= '<form class="inline" method="post" action="/admin/notifications">' . csrfField()
            . '<input type="hidden" name="op" value="read"><input type="hidden" name="notification" value="' . e((string) $row['id']) . '">'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 text-xs hover:bg-ink/5" type="submit">Прочитано</button>'
            . '</form>';
    }

    $items .= '</div></li>';
}

$markAll = $unread > 0
    ? '<form method="post" action="/admin/notifications">' . csrfField()
        . '<input type="hidden" name="op" value="read-all">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Отметить всё прочитанным</button>'
        . '</form>'
    : '';

$header = '<div class="flex flex-wrap items-center justify-between gap-3">'
    . '<p class="text-sm text-ink/70">' . ($unread > 0 ? 'Непрочитанных: ' . e((string) $unread) . '.' : 'Все уведомления прочитаны.') . '</p>'
    . $markAll . '</div>';

$body = $alert . $filters . $header
    . ($items === ''
        ? '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">Уведомлений нет. Здесь появятся упоминания, решения по согласованию, задачи и ошибки планировщика.</p>'
        : '<ul class="rounded-lg border border-ink/10 bg-white p-4">' . $items . '</ul>');

/* @safe html */ echo adminShell('Уведомления', $body, $notice);
