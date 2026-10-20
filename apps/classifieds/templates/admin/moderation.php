<?php

declare(strict_types=1);

/**
 * Очередь проверки: объявления, отправленные продавцами.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $rows
 */
$items = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $slug = (string) $row['slug'];
    $price = classifiedsPriceLabel(
        $row['price'] === null ? null : (float) $row['price'],
        (int) $row['negotiable'] === 1
    );
    $submitted = formatRuDateTime(fromDb((string) ($row['submitted_at'] ?? $row['created_at'])));

    $approve = '<form class="inline-flex" method="post" action="/classifieds/admin/moderation">'
        . csrfField()
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" name="op" value="approve">Опубликовать</button>'
        . '</form>';

    $reject = '<form class="flex flex-wrap items-end gap-2" method="post" action="/classifieds/admin/moderation">'
        . csrfField()
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<label class="block" for="note-' . e($id) . '">'
        . '<span class="mb-1 block text-sm text-ink/60">Причина отказа</span>'
        . '<textarea class="min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2" id="note-' . e($id) . '" name="note"'
        . ' maxlength="' . e((string) CLASSIFIEDS_MODERATION_NOTE_MAX) . '" required></textarea>'
        . '</label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" name="op" value="reject">Отклонить</button>'
        . '</form>';

    $items .= '<li class="space-y-3 rounded-xl border border-ink/10 bg-white p-4">'
        . '<div class="flex flex-wrap items-start justify-between gap-3">'
        . '<div class="min-w-0 space-y-1">'
        . '<p class="font-medium"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e('/classifieds/' . rawurlencode($slug)) . '">' . e((string) $row['title']) . '</a></p>'
        . '<p class="text-sm text-ink/60">' . e((string) ($row['category_title'] ?? 'Без категории'))
        . ' · ' . e($price) . ($submitted !== '' ? ' · ' . e($submitted) : '') . '</p>'
        . '<p class="text-sm text-ink/60">Продавец: ' . e((string) $row['owner_name']) . ' · ' . e((string) $row['owner_email']) . '</p>'
        . '<p class="text-sm text-ink/80">' . e(excerpt((string) $row['body'], 240)) . '</p>'
        . '</div>'
        . $approve
        . '</div>'
        . $reject
        . '</li>';
}

$content = $nav
    . '<h1 class="text-2xl font-bold">Проверка объявлений</h1>'
    . ($items === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Очередь пуста — все объявления проверены.</p>'
        : '<ul class="space-y-3">' . $items . '</ul>');

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Проверка', $content, $notice);
