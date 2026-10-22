<?php

declare(strict_types=1);

/**
 * Экран воронок (S5).
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var int $period
 * @var list<int> $periods
 * @var string $from
 * @var string $to
 * @var list<array<string, mixed>> $funnels
 * @var array<string, mixed>|null $funnel
 * @var array<string, mixed>|null $report
 */

$matchOptions = '';
foreach (seoGoalMatchTypes() as $match => $label) {
    $matchOptions .= '<option value="' . e($match) . '">' . e($label) . '</option>';
}

$funnelRows = '';
foreach ($funnels as $f) {
    $badge = $f['enabled']
        ? '<span class="inline-flex min-h-[44px] items-center rounded-lg px-2 py-1 text-xs font-medium text-good">вкл.</span>'
        : '<span class="inline-flex min-h-[44px] items-center rounded-lg px-2 py-1 text-xs font-medium text-bad">выкл.</span>';

    $funnelRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e($f['name']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-center">' . e((string) $f['steps']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">' . $badge . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-right">'
        . '<form class="inline" method="post" action="/seo/admin/funnels">'
        . csrfField()
        . '<input type="hidden" name="op" value="funnel_' . ($f['enabled'] ? 'disable' : 'enable') . '">'
        . '<input type="hidden" name="id" value="' . e($f['id']) . '">'
        . '<input type="hidden" name="funnel_id" value="' . e($f['id']) . '">'
        . '<button class="min-h-[44px] rounded-lg border border-ink/20 px-2 py-2 text-sm hover:bg-ink/5" type="submit">'
        . e($f['enabled'] ? 'Выключить' : 'Включить')
        . '</button>'
        . '</form>'
        . '<form class="inline" method="post" action="/seo/admin/funnels"'
        . ' onsubmit="return confirm(\'Удалить воронку «' . e(addslashes($f['name'])) . '»? Шаги воронки будут удалены.\')">'
        . csrfField()
        . '<input type="hidden" name="op" value="funnel_delete">'
        . '<input type="hidden" name="id" value="' . e($f['id']) . '">'
        . '<button class="min-h-[44px] rounded-lg border border-bad/30 px-2 py-2 text-sm text-bad hover:bg-bad/5" type="submit">Удалить</button>'
        . '</form>'
        . '</td>'
        . '</tr>';
}

$createFunnelForm = '<form class="space-y-3 rounded-xl border border-ink/10 bg-white p-4" method="post" action="/seo/admin/funnels">'
    . csrfField()
    . '<input type="hidden" name="op" value="funnel_create">'
    . '<label class="block" for="funnel_name">'
    . '<span class="text-sm text-ink/60">Название воронки</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="funnel_name" name="name" placeholder="Например: «Оформление заказа»">'
    . '</label>'
    . '<button class="min-h-[44px] inline-flex items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Создать воронку</button>'
    . '</form>';

$reportHtml = '';
if ($funnel !== null && $report !== null) {
    $stepRows = '';
    foreach ($report['steps'] as $step) {
        $stepRows .= '<tr>'
            . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) $step['position']) . '. ' . e((string) $step['name']) . '</td>'
            . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/60 truncate max-w-[200px]" title="' . e((string) $step['pattern']) . '">'
            . e((string) $step['pattern']) . '</td>'
            . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">' . e((string) $step['sessions']) . '</td>'
            . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-right">'
            . e(number_format((float) $step['rate'], 1, ',', '')) . '%</td>'
            . '</tr>';
    }

    $reportHtml = '<section class="mt-6 space-y-2">'
        . '<h2 class="text-lg font-semibold">Отчёт воронки: ' . e((string) $funnel['name']) . '</h2>'
        . '<p class="text-sm text-ink/60">Сессий во воронке: <span class="font-medium">' . e((string) $report['sessions']) . '</span>. '
        . 'Период: ' . e($from) . ' — ' . e($to) . '.</p>'
        . '<table class="w-full text-sm">'
        . '<thead><tr class="border-b border-ink/10">'
        . '<th class="px-2 py-3 text-left font-semibold">Шаг</th>'
        . '<th class="px-2 py-3 text-left font-semibold">Адрес</th>'
        . '<th class="px-2 py-3 text-right font-semibold">Сессий дошагало</th>'
        . '<th class="px-2 py-3 text-right font-semibold">Конверсия от предыдущего</th>'
        . '</tr></thead>'
        . '<tbody>' . $stepRows . '</tbody>'
        . '</table>'
        . '<p class="text-xs text-ink/60 mt-2">Сессия считаетшней дошагнувшей до шага, если среди её посещений за период '
        . 'нашлись адреса шагов в порядке воронки (по шаблонам: префикс или точное совпадение). Конверсия — доля сессий '
        . 'шага от предыдущего шага.</p>'
        . '</section>';
}

$stepForm = '<form class="space-y-3 rounded-xl border border-ink/10 bg-white p-4" method="post" action="/seo/admin/funnels">'
    . csrfField()
    . '<input type="hidden" name="op" value="funnel_step_create">'
    . '<input type="hidden" name="funnel_id" value="' . e((string) ($funnel['id'] ?? '')) . '">'
    . '<h2 class="text-lg font-semibold mb-3">Шаг воронки'
    . ($funnel !== null ? ' «' . e((string) $funnel['name']) . '»' : '')
    . '</h2>'
    . '<label class="block" for="step_name">'
    . '<span class="text-sm text-ink/60">Название шага</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="step_name" name="name" placeholder="Корзина">'
    . '</label>'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<label class="block" for="step_pattern">'
    . '<span class="text-sm text-ink/60">Шаблон адреса</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="step_pattern" name="pattern" placeholder="/checkout">'
    . '</label>'
    . '<label class="block" for="step_match">'
    . '<span class="text-sm text-ink/60">Сравнение</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="step_match" name="match_type">'
    . $matchOptions . '</select>'
    . '</label>'
    . '</div>'
    . '<button class="min-h-[44px] inline-flex items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" type="submit">Добавить шаг</button>'
    . '</form>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Воронки</h1>'
    . '<p class="text-sm text-ink/70">Воронка — цепочка шагов по адресам. Отчёт показывает, сколько сессий дошагало до '
    . 'каждого шага и конверсию от шага к шагу. Считается из своих посещений за период.</p>'
    . '</header>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Создать воронку</h2>'
    . $createFunnelForm
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Воронки</h2>'
    . ($funnelRows === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Воронок пока нет. Создайте первую.</p>'
        : '<table class="w-full text-sm">'
            . '<thead><tr class="border-b border-ink/10">'
            . '<th class="px-2 py-3 text-left font-semibold">Воронка</th>'
            . '<th class="px-2 py-3 text-left font-semibold">Шаги</th>'
            . '<th class="px-2 py-3 text-left font-semibold">Статус</th>'
            . '<th class="px-2 py-3 text-right font-semibold">Действие</th>'
            . '</tr></thead>'
            . '<tbody>' . $funnelRows . '</tbody>'
            . '</table>')
    . '</section>';

if ($funnel !== null) {
    $content .= '<section class="space-y-2">'
        . '<h2 class="text-lg font-semibold">Шаги воронки «' . e((string) $funnel['name']) . '»</h2>'
        . $stepForm
        . '</section>'
        . $reportHtml;
}

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Воронки', $content, $notice);
