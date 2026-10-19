<?php

declare(strict_types=1);

/**
 * Экран поставщиков магазина (M5): справочник с кнопками правки и одна форма.
 * Поставщик не виден покупателю — это то, откуда товар и кому звонить за прайсом.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, title: string, note: ?string, created_at: string, updated_at: string}> $suppliers
 * @var string $mode
 * @var array{id: string, title: string, note: ?string, created_at: string, updated_at: string}|null $supplier
 * @var array{title: string, note: string} $form
 * @var array<string, string> $errors
 */
$isEdit = $mode === 'edit' && $supplier !== null;
$action = $isEdit ? '/commerce/admin/suppliers/' . rawurlencode((string) $supplier['id']) : '/commerce/admin/suppliers';
$op = $isEdit ? 'save' : 'create';

/**
 * Строковые значения формы: отказ печатает набранное, открытие — из строки поставщика.
 *
 * @param string $field
 */
$value = static function (string $field) use ($form, $isEdit, $supplier): string {
    if ((string) ($form[$field] ?? '') !== '') {
        return (string) $form[$field];
    }

    if ($isEdit && $supplier !== null) {
        return (string) ($supplier[$field] ?? '');
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

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="'
    . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="' . e($op) . '">'
    . '<label class="block" for="supplier_title">'
    . '<span class="text-sm text-ink/60">Название поставщика</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('title') . ' px-3 py-2"'
    . ' id="supplier_title" name="title" maxlength="' . e((string) COMMERCE_TITLE_MAX) . '"'
    . ' value="' . e($value('title')) . '">'
    . $fieldError('title')
    . '</label>'
    . '<label class="block" for="supplier_note">'
    . '<span class="text-sm text-ink/60">Контакт или примечание</span>'
    . '<textarea class="min-h-[44px] rounded-lg border' . $isError('note') . ' w-full px-3 py-2"'
    . ' id="supplier_note" name="note" rows="2" maxlength="' . e((string) COMMERCE_SUPPLIER_NOTE_MAX) . '">'
    . e($value('note')) . '</textarea>'
    . $fieldError('note')
    . '</label>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . e($isEdit ? 'Сохранить поставщика' : 'Добавить поставщика') . '</button>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/commerce/admin/suppliers">Отмена</a>'
        : '')
    . '</div></form>';

/** Кнопка строки со своей формой: действие — скрытым полем, Enter не молчит. */
$rowButton = static function (string $id): string {
    return '<form method="post" action="/commerce/admin/suppliers/' . rawurlencode($id) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rows = '';
foreach ($suppliers as $row) {
    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="/commerce/admin/suppliers/' . rawurlencode((string) $row['id']) . '">'
        . e((string) $row['title']) . '</a></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) ($row['note'] ?? '')) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $row['id']) . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Поставщиков пока нет — варианты можно без поставщика, а прайс-лист работает по всем.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Поставщик</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Примечание</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Поставщики</h1>'
    . '<p class="text-sm text-ink/70">Справочник для форм вариантов и прайс-листов: у поставщика нет витрины, только учёт.</p>'
    . '</header>'
    . $formHtml
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Поставщики', $content, $notice);
