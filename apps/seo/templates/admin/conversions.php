<?php

declare(strict_types=1);

/**
 * Экран целей и конверсий (S5).
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var int $period
 * @var list<int> $periods
 * @var string $from
 * @var string $to
 * @var list<array<string, mixed>> $goals
 * @var array<string, int> $conversions
 * @var int $total
 */

$periodOptions = '';
foreach ($periods as $value) {
    $label = $value === 7 ? '7 дней' : ($value === 30 ? '30 дней' : '90 дней');
    $periodOptions .= '<option value="' . e((string) $value) . '"' . ($period === $value ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

$typeOptions = '';
foreach (seoGoalTypes() as $type => $label) {
    $typeOptions .= '<option value="' . e($type) . '">' . e($label) . '</option>';
}

$matchOptions = '';
foreach (seoGoalMatchTypes() as $match => $label) {
    $matchOptions .= '<option value="' . e($match) . '">' . e($label) . '</option>';
}

$goalRows = '';
foreach ($goals as $goal) {
    $badge = $goal['enabled']
        ? '<span class="inline-flex min-h-[44px] items-center rounded-lg px-2 py-1 text-xs font-medium text-good">вкл.</span>'
        : '<span class="inline-flex min-h-[44px] items-center rounded-lg px-2 py-1 text-xs font-medium text-bad">выкл.</span>';

    $goalRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e($goal['name']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/60">' . e($goal['type']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/60 truncate max-w-[200px]" title="' . e($goal['pattern']) . '">'
        . e($goal['pattern']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/60">' . e($goal['matchType']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/60">' . e($goal['source'] === '' ? '—' : $goal['source']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/60">' . e($goal['device'] === '' ? '—' : $goal['device']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">' . $badge . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm font-medium text-right">' . e((string) ($conversions[$goal['id']] ?? 0)) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-right">'
        . '<form class="inline" method="post" action="/seo/admin/conversions">'
        . csrfField()
        . '<input type="hidden" name="op" value="goal_' . ($goal['enabled'] ? 'disable' : 'enable') . '">'
        . '<input type="hidden" name="id" value="' . e($goal['id']) . '">'
        . '<button class="min-h-[44px] rounded-lg border border-ink/20 px-2 py-2 text-sm hover:bg-ink/5" type="submit">'
        . e($goal['enabled'] ? 'Выключить' : 'Включить')
        . '</button>'
        . '</form>'
        . '<form class="inline" method="post" action="/seo/admin/conversions"'
        . ' onsubmit="return confirm(\'Удалить цель «' . e(addslashes($goal['name'])) . '»? Агрегат по цели будет удалён, конверсии в сырьё останутся.\')">'
        . csrfField()
        . '<input type="hidden" name="op" value="goal_delete">'
        . '<input type="hidden" name="id" value="' . e($goal['id']) . '">'
        . '<button class="min-h-[44px] rounded-lg border border-bad/30 px-2 py-2 text-sm text-bad hover:bg-bad/5" type="submit">Удалить</button>'
        . '</form>'
        . '</td>'
        . '</tr>';
}

$createForm = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/conversions">'
    . csrfField()
    . '<input type="hidden" name="op" value="goal_create">'
    . '<h2 class="text-lg font-semibold">Новая цель</h2>'
    . '<label class="block" for="goal_name">'
    . '<span class="text-sm text-ink/60">Название</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="goal_name" name="name" placeholder="Например: «Оформлен заказ»">'
    . '</label>'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<label class="block" for="goal_type">'
    . '<span class="text-sm text-ink/60">Тип</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="goal_type" name="type">'
    . $typeOptions . '</select>'
    . '</label>'
    . '<label class="block" for="goal_match">'
    . '<span class="text-sm text-ink/60">Сравнение</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="goal_match" name="match_type">'
    . $matchOptions . '</select>'
    . '</label>'
    . '</div>'
    . '<label class="block" for="goal_pattern">'
    . '<span class="text-sm text-ink/60">Шаблон адреса</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="goal_pattern" name="pattern" placeholder="/order/success">'
    . '<span class="mt-1 block text-sm text-ink/60">Префикс адреса или точное совпадение. Только путь, без хоста и строки запроса.</span>'
    . '</label>'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<label class="block" for="goal_source">'
    . '<span class="text-sm text-ink/60">Источник (опционально)</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="goal_source" name="source" placeholder="organic">'
    . '<span class="mt-1 block text-sm text-ink/60">Источник посещения, как определил счётчик. Пусто — любой.</span>'
    . '</label>'
    . '<label class="block" for="goal_device">'
    . '<span class="text-sm text-ink/60">Устройство (опционально)</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="goal_device" name="device" placeholder="desktop">'
    . '<span class="mt-1 block text-sm text-ink/60">desktop / mobile / tablet. Пусто — любое.</span>'
    . '</label>'
    . '</div>'
    . '<div class="flex items-end gap-2">'
    . '<button class="min-h-[44px] inline-flex items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Создать цель</button>'
    . '</div>'
    . '</form>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Конверсии</h1>'
    . '<p class="text-sm text-ink/70">Цель — правило: посещение адреса по шаблону считается конверсией. Конверсия считается '
    . 'один раз на сессию и цель, поэтому обновление страницы не плодит повторов. Данные — свои посещения, без данных '
    . 'поисковика.</p>'
    . '</header>'
    . '<div class="grid gap-3 sm:grid-cols-3">'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Целей</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e((string) count($goals)) . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Конверсий за период</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e((string) $total) . '</p></div>'
    . '<div class="rounded-xl border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-ink/60">Период</p>'
    . '<p class="mt-1 text-2xl font-bold">' . e($from) . ' — ' . e($to) . '</p></div>'
    . '</div>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Новая цель</h2>'
    . $createForm
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Цели и конверсии за период</h2>'
    . '<div class="rounded-xl border border-ink/10 bg-white overflow-hidden">'
    . '<table class="w-full text-sm">'
    . '<thead>'
    . '<tr class="border-b border-ink/10">'
    . '<th class="px-2 py-3 text-left font-semibold">Цель</th>'
    . '<th class="px-2 py-3 text-left font-semibold">Тип</th>'
    . '<th class="px-2 py-3 text-left font-semibold">Адрес</th>'
    . '<th class="px-2 py-3 text-left font-semibold">Сравнение</th>'
    . '<th class="px-2 py-3 text-left font-semibold">Источник</th>'
    . '<th class="px-2 py-3 text-left font-semibold">Устройство</th>'
    . '<th class="px-2 py-3 text-left font-semibold">Статус</th>'
    . '<th class="px-2 py-3 text-left font-semibold">Конверсии</th>'
    . '<th class="px-2 py-3 text-right font-semibold">Действие</th>'
    . '</tr>'
    . '</thead>'
    . '<tbody>' . $goalRows . '</tbody>'
    . '</table>'
    . '</div>'
    . '</section>'
    . '<p class="text-xs text-ink/60">Конверсия зафиксируется при первом посещении адреса по шаблону цели в сессии. '
    . 'Источник и устройство фильтруют посещения: пустое значение подходит любому.</p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Конверсии', $content, $notice);
