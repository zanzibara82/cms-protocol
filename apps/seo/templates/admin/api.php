<?php

declare(strict_types=1);

/**
 * Экран «API-ключи»: ключи машинного чтения отчётов. Полного ключа нет и не будет: база
 * держит хеш, а экран — только префикс. Секрет печатается ровно один раз, на экране ответа
 * формы создания; потом ключ разве что отозвать.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, label: string, prefix: string, created_at: string, last_used_at: string, revoked_at: string}> $keys
 */

$rows = '';

foreach ($keys as $key) {
    $revoked = $key['revoked_at'] !== '';
    $form = $revoked ? '' : '<form class="inline" method="post" action="/seo/admin/api">'
        . csrfField()
        . '<input type="hidden" name="op" value="key_revoke">'
        . '<input type="hidden" name="id" value="' . e($key['id']) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-xs hover:bg-ink/5"'
        . ' type="submit">Отозвать</button></form>';

    $rows .= '<tr class="' . ($revoked ? 'text-ink/50' : '') . '">'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e($key['label']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-mono text-sm">' . e($key['prefix']) . '…</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e($key['created_at']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . ($key['last_used_at'] !== '' ? e($key['last_used_at']) : 'не использовался') . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">'
        . ($revoked ? 'отозван ' . e($key['revoked_at']) : 'действует') . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-right">' . $form . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Ключей ещё нет.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Подпись</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Префикс</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Создан</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Использован</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Статус</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$createForm = '<form class="space-y-3 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/api">'
    . csrfField()
    . '<input type="hidden" name="op" value="key_create">'
    . '<label class="block" for="api_key_label">'
    . '<span class="text-sm text-ink/60">Подпись ключа</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text"'
    . ' id="api_key_label" name="label" maxlength="190" required>'
    . '<span class="mt-1 block text-sm text-ink/60">Например «Кабинет отчётов» — подпись видна только вам.</span>'
    . '</label>'
    . '<div class="mt-1">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' type="submit">Создать ключ</button>'
    . '</div></form>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">API-ключи</h1>'
    . '<p class="text-sm text-ink/70">Ключ открывает машинные адреса /seo/api/v1/*: агрегаты обзора, '
    . 'страниц, источников и целей. Ключ передаётся заголовком «Authorization: Bearer …», хранится'
    . ' только хешем и показывается ровно один раз — после создания. Не работает — отзовите и заведите '
    . 'новый.</p>'
    . '</header>'
    . $createForm
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Список ключей</h2>'
    . $table
    . '<p class="text-xs text-ink/60">Полный ключ нигде не хранится открытым текстом: в базе лежит хеш. '
    . 'Потерянный ключ не восстанавливается — только отзыв и новый.</p>'
    . '</section>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('API-ключи', $content, $notice);
