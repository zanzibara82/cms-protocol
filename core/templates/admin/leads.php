<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array<string, mixed>> $rows */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var string $status */
/** @var string $query */

// Заявки с публичных форм: фильтры Новые/Обработанные/Все, поиск по имени и контакту, список
// 25 на страницу. Действия — отдельные формы с полем op (имя кнопки не попадает в POST при
// отправке клавишей Enter). Ответ письмом раскрывается нативным <details> — экран работает без
// JavaScript, как всё ядро.

$statusHref = static function (string $filter) use ($query): string {
    $parts = [];
    if ($filter !== LEAD_STATUS_NEW) {
        $parts[] = 'status=' . rawurlencode($filter);
    }
    if ($query !== '') {
        $parts[] = 'q=' . rawurlencode($query);
    }

    return '/admin/leads' . ($parts === [] ? '' : '?' . implode('&', $parts));
};

$pageHref = static function (int $target) use ($status, $query): string {
    $parts = [];
    if ($status !== LEAD_STATUS_NEW) {
        $parts[] = 'status=' . rawurlencode($status);
    }
    if ($query !== '') {
        $parts[] = 'q=' . rawurlencode($query);
    }
    $parts[] = 'page=' . $target;

    return '/admin/leads?' . implode('&', $parts);
};

$filters = '<div class="flex flex-wrap gap-2">';

foreach ([[LEAD_STATUS_NEW, 'Новые'], [LEAD_STATUS_DONE, 'Обработанные'], ['ALL', 'Все']] as [$filter, $label]) {
    // Класс собирается переменной, а не помощником: страж зон нажатия читает литералы веток, и
    // каждая ветка обязана нести min-h-[44px] сама (TapTargetTest).
    $class = $filter === $status
        ? 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm font-medium text-cream'
        : 'inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium text-ink/70 hover:bg-ink/5';
    $filters .= '<a class="' . $class . '" href="' . e($statusHref($filter)) . '">' . e($label) . '</a>';
}

$filters .= '</div>';

$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="/admin/leads">'
    . '<input type="hidden" name="status" value="' . e($status) . '">'
    . '<label class="block text-sm"><span class="font-medium">Поиск</span>'
    . '<input class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" type="search" name="q"'
    . ' value="' . e($query) . '" placeholder="Имя или контакт"></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Найти</button>'
    . '</form>';

$rowHtml = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $name = (string) $row['name'];
    $contact = (string) $row['contact'];
    $message = $row['message'] === null ? '' : trim((string) $row['message']);
    $source = (string) $row['source'];
    $created = formatRuDateTime(fromDb((string) $row['created_at']));
    $done = $row['done_at'] === null ? null : formatRuDateTime(fromDb((string) $row['done_at']));
    $isNew = (string) $row['status'] === LEAD_STATUS_NEW;
    $contactHref = leadContactHref($contact);
    $canEmail = leadIsEmail($contact);
    $replyText = trim((string) ($row['reply_text'] ?? ''));
    $draft = $replyText !== '' ? $replyText : leadReplyDraft($name);

    $actions = '<div class="flex flex-wrap items-center gap-2">';

    if ($canEmail) {
        $actions .= '<details class="relative">'
            . '<summary class="inline-flex min-h-[44px] cursor-pointer list-none items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5">Ответить письмом</summary>'
            . '<div class="mt-2 rounded-lg border border-ink/10 bg-cream p-3">'
            . '<form class="space-y-2" method="post" action="/admin/leads">' . csrfField()
            . '<input type="hidden" name="op" value="reply">'
            . '<input type="hidden" name="lead" value="' . e($id) . '">'
            . '<input type="hidden" name="status" value="' . e($status) . '">'
            . '<input type="hidden" name="q" value="' . e($query) . '">'
            . '<p class="text-xs text-ink/60">Письмо уйдёт на ' . e($contact) . '.</p>'
            . '<textarea class="h-[160px] min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" name="text" rows="6" maxlength="' . e((string) LEAD_REPLY_MAX) . '">' . e($draft) . '</textarea>'
            . '<p class="text-xs text-ink/50">От ' . e((string) LEAD_REPLY_MIN) . ' до ' . e((string) LEAD_REPLY_MAX) . ' символов.</p>'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Отправить письмо</button>'
            . '</form></div></details>';
    }

    $target = $isNew ? LEAD_STATUS_DONE : LEAD_STATUS_NEW;
    $label = $isNew ? 'Отметить обработанной' : 'Вернуть в новые';
    $actions .= '<form class="inline" method="post" action="/admin/leads">' . csrfField()
        . '<input type="hidden" name="op" value="set-status">'
        . '<input type="hidden" name="lead" value="' . e($id) . '">'
        . '<input type="hidden" name="target" value="' . e($target) . '">'
        . '<input type="hidden" name="status" value="' . e($status) . '">'
        . '<input type="hidden" name="q" value="' . e($query) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">' . e($label) . '</button></form>';

    $actions .= '<form class="inline" method="post" action="/admin/leads">' . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<input type="hidden" name="lead" value="' . e($id) . '">'
        . '<input type="hidden" name="status" value="' . e($status) . '">'
        . '<input type="hidden" name="q" value="' . e($query) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Удалить</button></form>';

    $actions .= '</div>';

    $contactCell = '<p class="break-words font-medium">' . e($name) . '</p>';

    if ($contactHref !== null) {
        $contactCell .= '<a class="inline-flex min-h-[44px] items-center break-all text-sm text-accent underline-offset-4 hover:underline" href="' . e($contactHref) . '">' . e($contact) . '</a>';
    } else {
        $contactCell .= '<p class="break-all text-sm">' . e($contact) . '</p>';
    }

    $messageCell = $message !== ''
        ? '<p class="max-w-[22rem] break-words text-sm whitespace-pre-line">' . e($message) . '</p>'
        : '<p class="text-sm text-ink/40">Сообщения нет</p>';

    $whenCell = '<span class="whitespace-nowrap text-ink/70">' . e($created) . '</span>';

    if ($done !== null) {
        $whenCell .= '<p class="text-xs text-ink/50">ответили ' . e($done) . '</p>';
    }

    $rowHtml .= '<tr class="border-b border-ink/10 align-top">'
        . '<td class="py-3 pr-3 pl-4">' . $contactCell . '</td>'
        . '<td class="py-3 pr-3">' . $messageCell . '</td>'
        . '<td class="py-3 pr-3 whitespace-nowrap font-mono text-xs text-ink/50">' . e($source !== '' ? $source : '—') . '</td>'
        . '<td class="py-3 pr-3">' . $whenCell . '</td>'
        . '<td class="py-3 pr-4"><div class="space-y-2">'
        . '<span class="inline-flex items-center rounded-full border border-ink/10 px-2 py-0.5 text-xs">' . e(LEAD_STATUS_LABELS[(string) $row['status']] ?? (string) $row['status']) . '</span>'
        . $actions . '</div></td>'
        . '</tr>';
}

$empty = $query !== ''
    ? 'Ни одна заявка не подходит под запрос «' . e($query) . '».'
    : ($status === LEAD_STATUS_NEW
        ? 'Новых заявок нет. Обработанные — во вкладке «Обработанные».'
        : 'Заявок пока нет: форма появляется на странице из редактора блоков.');

$table = '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
    . '<table class="w-full min-w-[48rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
    . '<th class="py-2 pr-3 pl-4 font-medium">Человек</th><th class="py-2 pr-3 font-medium">Сообщение</th>'
    . '<th class="py-2 pr-3 font-medium">Откуда</th><th class="py-2 pr-3 font-medium">Когда</th>'
    . '<th class="py-2 pr-4 font-medium">Что с заявкой</th></tr></thead><tbody>' . $rowHtml . '</tbody></table></div>';

if ($rows === []) {
    $table = '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">' . $empty . '</p>';
}

$pager = '';

if ($pages > 1) {
    $pager = '<div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">'
        . '<span class="text-ink/60">Страница ' . e((string) $page) . ' из ' . e((string) $pages)
        . ' · всего ' . e((string) $total) . ' · на странице ' . e((string) LEADS_PAGE_SIZE) . '</span>'
        . '<div class="flex gap-2">';

    if ($page > 1) {
        $pager .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 hover:bg-ink/5" href="' . e($pageHref($page - 1)) . '">← Назад</a>';
    }

    if ($page < $pages) {
        $pager .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 hover:bg-ink/5" href="' . e($pageHref($page + 1)) . '">Вперёд →</a>';
    }

    $pager .= '</div></div>';
}

$header = '<p class="text-sm text-ink/70">Заявки приходят с форм на публичных страницах. Человек сразу получает подтверждение на почту, а ответ школа пишет из этого раздела — письмо уходит на тот же адрес.</p>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Заявки с публичных страниц (' . $total . ')', $alert . $header . $filters . $search . $table . $pager, $notice);
