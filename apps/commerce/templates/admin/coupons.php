<?php

declare(strict_types=1);

/**
 * Экран купонов магазина (M4): список с кнопками и одна форма — создать или править
 * выбранный. Скидки: процент корзины или фиксированная сумма от корзины.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, code: string, kind: string, value: float, min_total: ?float, expires_at: ?string, max_uses: ?int, used_count: int, active: bool}> $coupons
 * @var string $mode
 * @var array{id: string, code: string, kind: string, value: float, min_total: ?float, expires_at: ?string, max_uses: ?int, used_count: int, active: bool}|null $coupon
 * @var array{code: string, kind: string, value: string, min_total: string, expires: string, max_uses: string, active: int} $form
 * @var array<string, string> $errors
 */
$isEdit = $mode === 'edit' && $coupon !== null;
$action = $isEdit ? '/commerce/admin/coupons/' . rawurlencode((string) $coupon['id']) : '/commerce/admin/coupons';
$op = $isEdit ? 'save' : 'create';

/**
 * Строковые значения формы: отказ печатает набранное, открытие — из строки купона.
 *
 * @param string $field
 */
$value = static function (string $field) use ($form, $isEdit, $coupon): string {
    if ((string) ($form[$field] ?? '') !== '') {
        return (string) $form[$field];
    }

    if ($isEdit && $coupon !== null) {
        $raw = $coupon[$field] ?? null;

        if ($raw === null) {
            return '';
        }

        if (in_array($field, ['value', 'min_total'], true)) {
            return number_format((float) $raw, 2, ',', ' ');
        }

        if ($field === 'expires') {
            return substr(moscowIso(fromDb((string) $raw)), 0, 10);
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

$kind = $value('kind');

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="' . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="' . e($op) . '">'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . '<label class="block" for="coupon_code">'
    . '<span class="text-sm text-ink/60">Код (латиница, цифры, дефис — без регистра)</span>'
    . '<input class="mt-1 min-h-[44px] w-full rounded-lg border' . $isError('code') . ' px-3 py-2 uppercase"'
    . ' id="coupon_code" name="code" maxlength="' . e((string) COMMERCE_COUPON_CODE_MAX) . '" value="' . e($value('code')) . '" placeholder="SUMMER">'
    . $fieldError('code')
    . '</label>'
    . '<label class="block" for="coupon_kind">'
    . '<span class="text-sm text-ink/60">Вид скидки</span>'
    . '<select class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2" id="coupon_kind" name="kind">'
    . $kindOptions
    . '</select>'
    . '</label>'
    . (($kind === 'PERCENT')
        ? $sumInput('value', 'coupon_value', 'Процент (например, 10 или 12,5)')
        : $sumInput('value', 'coupon_value', 'Сумма скидки'))
    . $sumInput('min_total', 'coupon_min_total', 'Минимум корзины (пусто — без порога)')
    . '<label class="block" for="coupon_expires">'
    . '<span class="text-sm text-ink/60">Действует до (включительно)</span>'
    . '<input class="mt-1 min-h-[44px] w-full rounded-lg border' . $isError('expires') . ' px-3 py-2"'
    . ' id="coupon_expires" name="expires" value="' . e($value('expires')) . '" placeholder="2026-12-31">'
    . $fieldError('expires')
    . '</label>'
    . '<label class="block" for="coupon_max_uses">'
    . '<span class="text-sm text-ink/60">Лимит визитов (пусто — без лимита)</span>'
    . '<input class="mt-1 min-h-[44px] w-full rounded-lg border' . $isError('max_uses') . ' px-3 py-2"'
    . ' id="coupon_max_uses" name="max_uses" type="number" min="0" value="' . e($value('max_uses')) . '">'
    . $fieldError('max_uses')
    . '</label>'
    . '<label class="mt-6 flex min-h-[44px] items-center gap-2" for="coupon_active">'
    . '<input id="coupon_active" name="active" type="checkbox" value="1" class="min-h-[44px] size-11 rounded border border-ink/20 bg-white"'
    . ($value('active') === '1' ? ' checked' : '')
    . ' class="h-4 w-4"> Купон активен (снят — покупатель получит «Купон снят с продаж»)</label>'
    . '</div>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . e($isEdit ? 'Сохранить купон' : 'Добавить купон') . '</button>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/commerce/admin/coupons">Отмена</a>'
        : '')
    . '</div></form>';

/**
 * Кнопка строки со своей формой: действие держит скрытое поле — имя кнопки не доходит
 * до сервера при отправке формы клавишей Enter.
 *
 * @param string $couponId
 */
$rowButton = static function (string $couponId): string {
    return '<form method="post" action="/commerce/admin/coupons/' . rawurlencode($couponId) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rows = '';
foreach ($coupons as $row) {
    $discount = (string) $row['kind'] === 'PERCENT'
        ? '−' . e(number_format((float) $row['value'], 2, ',', ' ')) . '%'
        : '−' . e(commerceFormatMoney(commerceCents((float) $row['value']), COMMERCE_CURRENCY_DEFAULT));
    $limit = $row['max_uses'] === null
        ? 'без лимита'
        : 'визитов: ' . max(0, (int) $row['max_uses'] - (int) $row['used_count']) . ' из ' . (int) $row['max_uses'];

    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium uppercase">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="' . e('/commerce/admin/coupons/' . rawurlencode((string) $row['id'])) . '">'
        . e((string) $row['code']) . '</a>'
        . ($isEdit && $coupon !== null && (string) $coupon['id'] === (string) $row['id']
            ? ' <span class="text-xs font-normal normal-case text-ink/50">в форме ниже</span>' : '')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">' . $discount . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . ($row['min_total'] !== null ? 'от ' . e(commerceFormatMoney(commerceCents((float) $row['min_total']), COMMERCE_CURRENCY_DEFAULT)) : 'без порога')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . ($row['expires_at'] !== null ? 'до ' . e(substr(moscowIso(fromDb((string) $row['expires_at'])), 0, 10)) : 'без срока')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . $limit
        . ($row['active'] ? '' : ' · <span class="text-red-700">снят</span>') . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $row['id']) . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Купонов пока нет.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Код</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Скидка</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Условие</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Срок</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Визиты</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Купоны</h1>'
    . '<p class="text-sm text-ink/70">Код называет покупатель в корзине: скидка процента или фиксированная сумма от подытога.</p>'
    . '</header>'
    . $formHtml
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Купоны', $content, $notice);
