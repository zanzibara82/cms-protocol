<?php

declare(strict_types=1);

// Метки состояний берутся из ядра (appStateLabels()): const в подключаемом шаблоне при
// повторном подключении падает как «уже объявленный», а словарь состояний принадлежит ядру.

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var string $coreVersion */
/** @var list<string> $corePending */
/** @var list<array{id: string, title: string, state: string, dbVersion: string, manifestVersion: string, reason: string}> $apps */

// Кнопки — отдельные формы, а намерение приходит полем op, а не именем кнопки: имя кнопки
// не попадает в POST, если человек отправил форму клавишей Enter. Класс min-h-[44px] на каждой
// кнопке — глобальное требование проекта к зоне нажатия.
/** @param array{id: string, title: string, state: string, dbVersion: string, manifestVersion: string, reason: string} $app */
$appButtons = static function (array $app): string {
    $form = static function (string $op, string $label, string $kind) use ($app): string {
        return '<form class="inline" method="post" action="/admin/apps">' . csrfField()
            . '<input type="hidden" name="op" value="' . e($op) . '">'
            . '<input type="hidden" name="app" value="' . e((string) $app['id']) . '">'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm ' . $kind . '" type="submit">' . e($label) . '</button></form>';
    };

    $out = [];
    if ($app['state'] === 'missing') {
        $out[] = $form('install', 'Установить', 'bg-ink text-cream hover:opacity-90');
    }
    if ($app['state'] === 'outdated') {
        $out[] = $form('update', 'Обновить', 'bg-ink text-cream hover:opacity-90');
    }
    if ($app['state'] === 'ready' || $app['state'] === 'outdated') {
        $out[] = $form('disable', 'Выключить', 'border border-ink/20 hover:bg-ink/5');
    }
    if ($app['state'] === 'disabled') {
        $out[] = $form('enable', 'Включить', 'bg-ink text-cream hover:opacity-90');
    }

    return $out === [] ? '<span class="text-sm text-ink/50">Действий нет</span>' : implode(' ', $out);
};

$labels = appStateLabels();
$rows = '';
foreach ($apps as $app) {
    $rows .= '<tr class="border-b border-ink/10">'
        . '<th class="py-3 pr-3 pl-4 text-left font-medium">' . e($app['title']) . '</th>'
        . '<td class="py-3 pr-3 text-sm text-ink/70">' . e($labels[$app['state']] ?? $app['state']) . '</td>'
        . '<td class="py-3 pr-3 text-sm text-ink/70">' . e($app['dbVersion'] === '' ? '—' : $app['dbVersion'])
        . ' / ' . e($app['manifestVersion']) . '</td>'
        . '<td class="py-3 pr-3 text-sm text-ink/60">' . e($app['reason']) . '</td>'
        . '<td class="py-3 pr-4 text-right"><div class="flex flex-wrap justify-end gap-2">' . $appButtons($app) . '</div></td>'
        . '</tr>';
}

$core = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Ядро</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Версия в сборке: ' . e($coreVersion) . '.</p>';

if ($corePending === []) {
    $core .= '<p class="mt-2 text-sm text-ink/60">Обновлений ядра нет.</p>';
} else {
    $core .= '<p class="mt-2 text-sm text-ink/70">Ждут применения: ' . e(implode(', ', $corePending)) . '.</p>'
        . '<form class="mt-3" method="post" action="/admin/apps">' . csrfField()
        . '<input type="hidden" name="op" value="update-core">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Обновить ядро</button></form>';
}

$core .= '</section>';

$table = '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
    . '<table class="w-full min-w-[40rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
    . '<th class="py-2 pr-3 pl-4 font-medium">Раздел</th><th class="py-2 pr-3 font-medium">Состояние</th>'
    . '<th class="py-2 pr-3 font-medium">Версия в базе / в сборке</th><th class="py-2 pr-3 font-medium">Пояснение</th>'
    . '<th class="py-2 pr-4 text-right font-medium">Действия</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

// Отказ: заголовок берётся из словаря уведомлений, подробная причина приезжает из сессионного
// flash. В адресную строку причина не уезжает — свободный текст из адреса не печатается.
// Если причины нет (второй показ), остаётся только заголовок.
$alert = ($alertTitle === '' && $alertReason === '')
    ? ''
    : '<div class="mb-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3" role="alert">'
        . ($alertTitle !== '' ? '<p class="text-sm font-medium text-bad">' . e($alertTitle) . '</p>' : '')
        . ($alertReason !== '' ? '<p class="mt-1 text-sm text-bad">' . e($alertReason) . '</p>' : '')
        . '</div>';

// Строка помечена как разметка: $alert, $core и $table собраны этим же файлом из литералов
// и e(), а наружу уходят единственным вызовом adminShell — голой конкатенации с данными нет.
/* @safe html */ echo adminShell('Приложения', $alert . $core . $table, $notice);
