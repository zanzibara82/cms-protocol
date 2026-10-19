<?php

declare(strict_types=1);

/**
 * Экран вебхуков магазина (M6): приёмники событий заказов с одной формой.
 * Событие уходит POST-ом с подписью HMAC; сбой доставки записывается в last_state.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $webhooks
 * @var string $mode
 * @var array{id: string, url: string, secret: string, active: int}|null $webhook
 * @var array{url: string, secret: string, active: int} $form
 * @var array<string, string> $errors
 */
$isEdit = $mode === 'edit' && $webhook !== null;
$action = $isEdit ? '/commerce/admin/webhooks/' . rawurlencode((string) $webhook['id']) : '/commerce/admin/webhooks';
$op = $isEdit ? 'save' : 'create';

/**
 * Строковые значения формы: отказ печатает набранное, открытие — из строки вебхука.
 *
 * @param string $field
 */
$value = static function (string $field) use ($form, $isEdit, $webhook): string {
    if ((string) ($form[$field] ?? '') !== '' || $isEdit) {
        return (string) ($form[$field] ?? ($webhook[$field] ?? ''));
    }

    return '';
};

/** Класс поля: красная рамка на поле с причиной отказа. */
$isError = static function (string $field) use ($errors): string {
    return (string) ($errors[$field] ?? '') !== '' ? ' border-red-400' : ' border-ink/20';
};

/** Причина отказа под полем. */
$fieldError = static function (string $field) use ($errors): string {
    $message = (string) ($errors[$field] ?? '');

    return $message === '' ? '' : '<span class="mt-1 block text-sm text-red-700">' . e($message) . '</span>';
};

$checked = (int) ($form['active'] ?? ($webhook['active'] ?? 0)) === 1;

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="'
    . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="' . e($op) . '">'
    . '<label class="block" for="webhook_url">'
    . '<span class="text-sm text-ink/60">Адрес приёмника (http:// или https://)</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('url') . ' px-3 py-2"'
    . ' id="webhook_url" name="url" maxlength="' . e((string) COMMERCE_WEBHOOK_URL_MAX) . '"'
    . ' placeholder="https://пример.рф/nova-commerce"'
    . ' value="' . e($value('url')) . '">'
    . $fieldError('url')
    . '</label>'
    . '<label class="block" for="webhook_secret">'
    . '<span class="text-sm text-ink/60">Секрет подписи (не короче ' . e((string) COMMERCE_WEBHOOK_SECRET_MIN) . ')</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('secret') . ' px-3 py-2"'
    . ' id="webhook_secret" name="secret" maxlength="' . e((string) COMMERCE_WEBHOOK_SECRET_MAX) . '"'
    . ' value="' . e($value('secret')) . '">'
    . $fieldError('secret')
    . '</label>'
    . '<label class="flex items-start gap-3">'
    . '<input type="checkbox" name="active" value="1"' . ($checked ? ' checked' : '')
    . ' class="min-h-[44px] size-11 rounded border border-ink/20 bg-white">'
    . '<span class="text-sm">Приёмник активен: пока флажок снят, событие не уходит.</span>'
    . '</label>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . e($isEdit ? 'Сохранить вебхук' : 'Добавить вебхук') . '</button>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/commerce/admin/webhooks">Отмена</a>'
        : '')
    . '</div></form>';

/** Кнопка строки со своей формой: действие — скрытым полем, Enter не молчит. */
$rowButton = static function (string $id): string {
    return '<form method="post" action="/commerce/admin/webhooks/' . rawurlencode($id) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rows = '';
foreach ($webhooks as $row) {
    $state = (string) ($row['last_state'] ?? '');

    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="/commerce/admin/webhooks/' . rawurlencode((string) $row['id']) . '">'
        . e((string) $row['url']) . '</a></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . ((int) $row['active'] === 1
            ? 'Активен' : 'Выключен') . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . ($state === '' ? e('Доставок ещё не было') : e($state)) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $row['id']) . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Приёмников пока нет — метод «создание заказа и смена статуса» ни о чём не сообщит.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Адрес</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Состояние</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Последняя доставка</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Webhooks</h1>'
    . '<p class="text-sm text-ink/70">События заказов: приёмнику уходит POST с JSON-телом и подписью X-Commerce-Signature '
    . '(HMAC-SHA256 по телу, ключ — секрет вебхука). Транспорт можно подменить подпиской на фильтр commerce_webhook_transport.</p>'
    . '</header>'
    . $formHtml
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Webhooks', $content, $notice);
