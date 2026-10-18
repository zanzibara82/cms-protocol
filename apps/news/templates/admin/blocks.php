<?php

declare(strict_types=1);

// Экран блоков вывода: по одной таблице на объявленную зону и своя форма добавления у каждой
// таблицы. Порядок внутри зоны задают стрелки, цель и число правятся полями самой строки.
//
// Формы не общие: каждая кнопка и каждое поле — отдельный POST со скрытыми `op` и `id`. Имя
// кнопки в POST не попадает при отправке формы клавишей Enter, поэтому намерение приходит полем —
// тот же договор, что у списка материалов и справочников.

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var array<string, list<array{id: string, zone: string, kind: string, title: string, take: int,
 *     visible: bool, order: int, target: null|array{kind: string, id: string, label: string, alive: bool}}>> $rows
 * @var list<array{kind: string, label: string, items: list<array{value: string, title: string, visible: bool}>}> $targetOptions
 * @var int $titleMax
 * @var int $takeMin
 * @var int $takeMax
 */

// Классы контролов выписаны литералами, которые уже лежат в артефакте стилей: экран не
// добавляет правил в src-css/app.css, и шаг 10 это сверяет.
$field = 'w-full rounded-lg border border-ink/20 px-3 py-2 text-sm';
$button = 'rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream hover:opacity-90';
$danger = 'rounded-lg border border-bad/30 px-3 py-1.5 text-sm text-bad hover:bg-bad/10';

/**
 * Значение цели строки в том же виде, в каком его отдаёт поле выбора: `category:<id>` или `tag:<id>`.
 *
 * @param null|array{kind: string, id: string, label: string, alive: bool} $target
 */
$targetValue = static function (?array $target): string {
    return $target === null ? '' : $target['kind'] . ':' . $target['id'];
};

/**
 * Поле выбора цели одной строки: «всю ленту», группы справочников и, если цель умерла, её сама.
 *
 * Мёртвая цель печатается отдельной выбранной опцией (правило 7): в списках справочников её уже
 * нет, и без этой строки сохранение любой другой правки молча вернуло бы блоку общую ленту —
 * тихая порча настройки, которую человек не заметил бы.
 *
 * @param null|array{kind: string, id: string, label: string, alive: bool} $target
 */
$targetField = static function (?array $target) use ($targetOptions, $targetValue, $field): string {
    $current = $targetValue($target);
    $out = '<select class="min-h-[44px] ' . $field . '" name="target">'
        . '<option value="">вся лента раздела</option>';

    foreach ($targetOptions as $group) {
        $out .= '<optgroup label="' . e($group['label']) . '">';

        foreach ($group['items'] as $item) {
            $out .= '<option value="' . e($item['value']) . '"'
                . ($item['value'] === $current ? ' selected' : '') . '>'
                . e($item['title'] . ($item['visible'] === true ? '' : ' (скрыта)'))
                . '</option>';
        }

        $out .= '</optgroup>';
    }

    if ($target !== null && $target['alive'] === false) {
        $dead = $targetValue($target);
        $out .= '<option value="' . e($dead) . '" selected>' . e($dead . ' — цель недоступна') . '</option>';
    }

    return $out . '</select>';
};

$tables = '';

foreach (NEWS_BLOCK_ZONES as $zone) {
    // Зона словаря, а не базы: пустая зона обязана остаться на экране со своей формой добавления,
    // иначе человек, который ничего ещё не настроил, не нашёл бы, где начинать.
    $rowsOfZone = $rows[$zone];

    // Форма добавления поле цели не печатает (правило 6): экран работает без JS, а выбор,
    // относящийся к одному из двух видов, без скрипта не скрыть.
    $create = '<form class="flex flex-wrap items-end gap-2 rounded-lg border border-ink/10 bg-white p-4" method="post" action="'
        . e(NEWS_ADMIN_BLOCKS_PATH) . '">' . csrfField()
        . '<input type="hidden" name="op" value="create">'
        . '<input type="hidden" name="zone" value="' . e($zone) . '">'
        . '<label class="grow text-sm">Название блока'
        . '<input class="min-h-[44px] ' . $field . '" type="text" name="title" required maxlength="'
        . e((string) $titleMax) . '"></label>'
        . '<label class="text-sm">Что показывать'
        . '<select class="min-h-[44px] ' . $field . '" name="kind">'
        // Вид «Похожие материалы» берёт рубрику со страницы самого материала, поэтому вне страницы
        // материала он молчит; пометка в подписи отводит от заведомо пустого выбора (замечание Р3).
        . '<option value="list">' . e(NEWS_BLOCK_KIND_LABEL['list']) . '</option>'
        . '<option value="related">' . e(NEWS_BLOCK_KIND_LABEL['related']) . ' (печатается только на странице материала)</option>'
        . '</select></label>'
        . '<label class="text-sm">Сколько материалов'
        . '<input class="min-h-[44px] ' . $field . '" type="number" name="take" min="' . e((string) $takeMin)
        . '" max="' . e((string) $takeMax) . '" value="' . e((string) $takeMin) . '" required></label>'
        . '<button class="min-h-[44px] ' . $primary . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['create']) . '</button>'
        . '</form>';

    $body = '';

    foreach ($rowsOfZone as $row) {
        $id = (string) $row['id'];
        $kind = (string) $row['kind'];
        $target = $row['target'];

        // Цель бывает только у вида «список» (правило спецификации §2): у «похожих материалов»
        // рубрика берётся из материала, на котором они лежат, и второе поле обещало бы настройку,
        // которую сервер всё равно отверг бы.
        if ($kind === 'related') {
            $targetCell = '<p class="text-sm text-ink/70">рубрика самого материала</p>';
        } else {
            $cellText = $target === null
                ? 'вся лента раздела'
                : ($target['alive'] === true
                    ? (string) $target['label']
                    : 'цель недоступна: ' . $target['kind'] . ':' . $target['id']);

            $targetCell = '<p class="text-sm text-ink/70">' . e($cellText) . '</p>'
                . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
                . csrfField() . '<input type="hidden" name="op" value="retarget">'
                . '<input type="hidden" name="id" value="' . e($id) . '">'
                . '<label class="grow text-sm">Цель' . $targetField($target) . '</label>'
                . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['retarget']) . '</button>'
                . '</form>';
        }

        $limitCell = '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
            . csrfField() . '<input type="hidden" name="op" value="limit">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<label class="text-sm">Материалов'
            . '<input class="min-h-[44px] ' . $field . '" type="number" name="take" min="' . e((string) $takeMin)
            . '" max="' . e((string) $takeMax) . '" value="' . e((string) $row['take']) . '" required></label>'
            . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['limit']) . '</button>'
            . '</form>';

        $renameCell = '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
            . csrfField() . '<input type="hidden" name="op" value="rename">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<label class="grow text-sm">Заголовок'
            . '<input class="min-h-[44px] ' . $field . '" type="text" name="title" value="' . e((string) $row['title'])
            . '" required maxlength="' . e((string) $titleMax) . '"></label>'
            . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['rename']) . '</button>'
            . '</form>';

        $state = $row['visible'] === true
            ? '<span class="text-sm text-good">виден</span>'
            : '<span class="text-sm text-bad">скрыт — на страницах его нет</span>';

        $buttons = '';

        foreach (newsBlockRowActions($row) as $action) {
            $class = $action['op'] === 'delete' ? $danger : $button;
            $buttons .= '<form class="contents" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
                . csrfField() . '<input type="hidden" name="op" value="' . e($action['op']) . '">'
                . '<input type="hidden" name="id" value="' . e($id) . '">'
                . '<button class="min-h-[44px] ' . $class . '" type="submit">' . e($action['label']) . '</button>'
                . '</form>';
        }

        $body .= '<tr class="border-b border-ink/10">'
            . '<th class="py-3 pr-3 pl-4 text-left font-medium">' . e((string) $row['title'])
            . '<p class="text-sm text-ink/50">' . e(NEWS_BLOCK_KIND_LABEL[$kind] ?? $kind)
            . ' · позиция ' . e((string) $row['order']) . '</p></th>'
            . '<td class="py-3 pr-3">' . $targetCell . '</td>'
            . '<td class="py-3 pr-3">' . $limitCell . '</td>'
            . '<td class="py-3 pr-3">' . $state . '</td>'
            . '<td class="py-3 pr-4 text-right">' . $renameCell
            . '<div class="flex flex-wrap justify-end gap-2">' . $buttons . '</div></td>'
            . '</tr>';
    }

    $emptyRow = $rowsOfZone === []
        ? '<tr class="border-b border-ink/10"><td class="py-3 pr-3 pl-4 text-sm text-ink/60" colspan="5">'
            . 'В этой зоне пока ни одного блока — добавьте его формой выше.</td></tr>'
        : '';

    $tables .= '<section class="space-y-3">'
        . '<h2 class="font-semibold">' . e(NEWS_BLOCK_ZONE_LABEL[$zone])
        . ' · не больше ' . e((string) NEWS_BLOCK_PER_ZONE) . '</h2>'
        . $create
        . '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[40rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-3 pl-4 font-medium">Блок</th><th class="py-2 pr-3 font-medium">Что берёт</th>'
        . '<th class="py-2 pr-3 font-medium">Сколько</th><th class="py-2 pr-3 font-medium">Состояние</th>'
        . '<th class="py-2 pr-4 text-right font-medium">Правка и кнопки</th></tr></thead><tbody>'
        . $body . $emptyRow . '</tbody></table></div></section>';
}

// Вступление не называет зоны их заголовочными словами: проверка порядка зон ищет «На главной»,
// «В рубрике» и «В материале» первым вхождением, и любое упоминание выше заголовка переставило бы
// их местами в глазах теста.
$content = $nav
    . '<p class="text-sm text-ink/70">Блок — это подборка материалов, которую раздел выводит в отмеченном месте '
    . 'страницы. Порядок задают кнопки «Вверх» и «Вниз» внутри своей зоны, а скрытый блок остаётся в '
    . 'таблице и возвращается кнопкой «Показать».</p>'
    . $tables;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Блоки', $content, $notice);
