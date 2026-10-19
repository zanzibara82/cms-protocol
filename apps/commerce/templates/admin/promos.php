<?php

declare(strict_types=1);

/**
 * Экран промо-правил магазина (M4): автоскидка корзины на период. Правила в деле —
 * покупателю автоматическая, без кода; в заказ едет название и скидка лучшего правила.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, title: string, kind: string, value: float, min_total: ?float, starts_at: ?string, ends_at: ?string, active: bool}> $promos
 * @var string $mode
 * @var array{id: string, title: string, kind: string, value: float, min_total: ?float, starts_at: ?string, ends_at: ?string, active: bool}|null $promo
 * @var array{title: string, kind: string, value: string, min_total: string, starts: string, ends: string, active: int} $form
 * @var array<string, string> $errors
 */
$isEdit = $mode === 'edit' && $promo !== null;
$action = $isEdit ? '/commerce/admin/promos/' . rawurlencode((string) $promo['id']) : '/commerce/admin/promos';
$op = $isEdit ? 'save' : 'create';

/**
 * Строковые значения формы: отказ печатает набранное, открытие — из строки правила.
 *
 * @param string $field
 */
$value = static function (string $field) use ($form, $isEdit, $promo): string {
    if ((string) ($form[$field] ?? '') !== '') {
        return (string) $form[$field];
    }

    if ($isEdit && $promo !== null) {
        $raw = $promo[$field] ?? null;

        if ($raw === null) {
            return '';
        }

        if (in_array($field, ['value', 'min_total'], true)) {
            return number_format((float) $raw, 2, ',', ' ');
        }

        if (in_array($field, ['starts', 'ends'], true)) {
            $column = $field === 'starts' ? 'starts_at' : 'ends_at';

            return substr(moscowIso(fromDb((string) $promo[$column])), 0, 10);
        }

        return (string) $raw;
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

/** Опции вида скидки. */
$kindOptions = '';
foreach (COMMERCE_DISCOUNT_KIND_LABELS as $kindValue => $kindLabel) {
    $kindOptions .= '<option value="' . e((string) $kindValue) . '"'
        . ($value('kind') === (string) $kindValue ? ' selected' : '') . '>'
        . e((string) $kindLabel) . '</option>';
}

/** Строка формы суммы. */
$sumInput = static function (string $field, string $id, string $label) use ($value, $isError, $fieldError): string {
    return '<label class="block" for="' . $id . '">'
        . '<span class="text-sm text-ink/60">' . $label . '</span>'
        . '<input class="mt-1 min-h-[44px] w-full rounded-lg border' . $isError($field) . ' px-3 py-2"'
        . ' id="' . $id . '" name="' . $field . '" inputmode="decimal" value="' . e($value($field)) . '">'
        . $fieldError($field)
        . '</label>';
};

/** Строка формы дня. */
$dayInput = static function (string $field, string $id, string $label) use ($value, $isError, $fieldError): string {
    return '<label class="block" for="' . $id . '">'
        . '<span class="text-sm text-ink/60">' . $label . '</span>'
        . '<input class="mt-1 min-h-[44px] w-full rounded-lg border' . $isError($field) . ' px-3 py-2"'
        . ' id="' . $id . '" name="' . $field . '" value="' . e($value($field)) . '" placeholder="2026-12-31">'
        . $fieldError($field)
        . '</label>';
};

$kind = $value('kind');

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="' . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="' . e($op) . '">'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . '<label class="block" for="promo_title">'
    . '<span class="text-sm text-ink/60">Название правила</span>'
    . '<input class="mt-1 min-h-[44px] w-full rounded-lg border' . $isError('title') . ' px-3 py-2"'
    . ' id="promo_title" name="title" maxlength="160" value="' . e($value('title')) . '" placeholder="Новогодние скидки">'
    . $fieldError('title')
    . '</label>'
    . '<label class="block" for="promo_kind">'
    . '<span class="text-sm text-ink/60">Вид скидки</span>'
    . '<select class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2" id="promo_kind" name="kind">'
    . $kindOptions
    . '</select>'
    . '</label>'
    . (($kind === 'PERCENT')
        ? $sumInput('value', 'promo_value', 'Процент (например, 15)')
        : $sumInput('value', 'promo_value', 'Сумма скидки'))
    . $sumInput('min_total', 'promo_min_total', 'Минимум корзины (пусто — без порога)')
    . $dayInput('starts', 'promo_starts', 'Начало (пусто — без ограничения)')
    . $dayInput('ends', 'promo_ends', 'Конец (пусто — без ограничения)')
    . '<label class="mt-6 flex min-h-[44px] items-center gap-2" for="promo_active">'
    . '<input id="promo_active" name="active" type="checkbox" value="1" class="min-h-[44px] size-11 rounded border border-ink/20 bg-white"'
    . ($value('active') === '1' ? ' checked' : '')
    . ' class="h-4 w-4"> Правило активно</label>'
    . '</div>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . e($isEdit ? 'Сохранить правило' : 'Добавить правило') . '</button>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/commerce/admin/promos">Отмена</a>'
        : '')
    . '</div></form>';

/**
 * Кнопка строки со своей формой: действие держит скрытое поле — имя кнопки не доходит
 * до сервера при отправке формы клавишей Enter.
 *
 * @param string $promoId
 */
$rowButton = static function (string $promoId): string {
    return '<form method="post" action="/commerce/admin/promos/' . rawurlencode($promoId) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rows = '';
foreach ($promos as $row) {
    $discount = (string) $row['kind'] === 'PERCENT'
        ? '−' . e(number_format((float) $row['value'], 2, ',', ' ')) . '%'
        : '−' . e(commerceFormatMoney(commerceCents((float) $row['value']), COMMERCE_CURRENCY_DEFAULT));
    $period = [
        $row['starts_at'] === null ? '…' : substr(moscowIso(fromDb((string) $row['starts_at'])), 0, 10),
        $row['ends_at'] === null ? '…' : substr(moscowIso(fromDb((string) $row['ends_at'])), 0, 10),
    ];

    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="' . e('/commerce/admin/promos/' . rawurlencode((string) $row['id'])) . '">'
        . e((string) $row['title']) . '</a>'
        . ($isEdit && $promo !== null && (string) $promo['id'] === (string) $row['id']
            ? ' <span class="text-xs font-normal text-ink/50">в форме ниже</span>' : '')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">' . $discount . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . ($row['min_total'] !== null ? 'от ' . e(commerceFormatMoney(commerceCents((float) $row['min_total']), COMMERCE_CURRENCY_DEFAULT)) : 'без порога')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70 tabular-nums">'
        . e($period[0] . ' — ' . $period[1])
        . ($row['active'] ? '' : ' · <span class="text-red-700">выключено</span>') . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $row['id']) . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Правил пока нет.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Правило</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Скидка</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Условие</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Период</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Промо-правила</h1>'
    . '<p class="text-sm text-ink/70">Работает лучшее правило корзины из активных: скидка добавляется сама, по периоду и порогу.</p>'
    . '</header>'
    . $formHtml
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Промо-правила', $content, $notice);
