<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var array<string, string> $colors */
/** @var array<string, int> $sizes */
/** @var array{text: float, card: float} $contrast */
/** @var array<string, string> $colorNames */
/** @var array<string, string> $defaults */
/** @var array<string, string> $fontNames */

// Оформление темы: палитра и размеры шрифтов. Значения печатаются инлайновым <style> поверх
// артефакта CSS, поэтому здесь только данные — ни одного правила CSS в шаблоне.
//
// Контраст показывается числами: своя палитра легко даёт светлый текст на светлом фоне, и это
// видно только на живой странице. 4.5:1 — порог WCAG AA для обычного текста.
//
// Поле цвета — нативное `type="color"`, рядом текстовая строка с тем же значением: пипетка удобна
// мышью, а вписать `#d97706` руками быстрее, когда он уже известен. Оба поля несут одно имя —
// сервер читает последнее, и это ровно то, что человек видел последним.

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

$colorRows = '';
foreach ($colorNames as $key => $label) {
    $value = (string) ($colors[$key] ?? '');
    $default = (string) ($defaults[$key] ?? '');
    // Значение по умолчанию подписано у каждого поля: человек должен видеть, от чего он уходит,
    // иначе «сбросить» некуда — кнопки сброса нет, палитра правится только вручную.
    $colorRows .= '<div class="rounded-lg border border-ink/10 bg-white p-3">'
        . '<label class="block text-sm"><span class="block font-medium">' . e($label) . '</span>'
        . '<div class="mt-1.5 flex items-center gap-2">'
        . '<input class="min-h-[44px] h-11 w-14 shrink-0 cursor-pointer rounded-lg border border-ink/20 bg-white p-1" type="color"'
        . ' name="color_' . e($key) . '" value="' . e($value) . '" aria-label="' . e($label) . '">'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="color_' . e($key) . '" value="' . e($value) . '"'
        . ' pattern="#[0-9a-fA-F]{3,6}" maxlength="7">'
        . '</div>'
        . '<span class="mt-1.5 block text-xs text-ink/50">По умолчанию ' . e($default) . '</span></label>'
        . '</div>';
}

$sizeRows = '';
foreach ($fontNames as $key => $label) {
    $value = (string) ($sizes[$key] ?? '');
    $sizeRows .= '<label class="block text-sm"><span class="block font-medium">' . e($label) . '</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="number"'
        . ' name="font_' . e($key) . '" value="' . e($value) . '"'
        . ' min="' . DESIGN_FONT_MIN . '" max="' . DESIGN_FONT_MAX . '"'
        . ' placeholder="как в теме">'
        . '<span class="mt-1.5 block text-xs text-ink/50">Пусто — размер из темы. Пределы '
        . DESIGN_FONT_MIN . '–' . DESIGN_FONT_MAX . ' px.</span></label>';
}

/** Плашка контраста: число и порог, без субъективных оценок. */
$contrastBadge = static function (float $ratio): string {
    $ok = $ratio >= 4.5;

    return '<span class="rounded-full px-2 py-1 text-xs font-medium '
        . ($ok ? 'bg-good/10 text-good' : 'bg-bad/10 text-bad') . '">'
        . e((string) $ratio) . ':1 — ' . ($ok ? 'читается' : 'ниже порога 4.5:1') . '</span>';
};

$contrastBlock = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Контраст</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Проверяется пара «текст и фон». Ниже 4.5:1 текст читается плохо, '
    . 'и это заметно только на живой странице — сохраняйте палитру, видя числа.</p>'
    . '<dl class="mt-3 grid gap-2 sm:grid-cols-2">'
    . '<div class="flex items-center justify-between gap-3 rounded-lg border border-ink/10 px-3 py-2">'
    . '<dt class="text-sm">Текст на фоне страницы</dt><dd>' . $contrastBadge($contrast['text']) . '</dd></div>'
    . '<div class="flex items-center justify-between gap-3 rounded-lg border border-ink/10 px-3 py-2">'
    . '<dt class="text-sm">Текст на карточках</dt><dd>' . $contrastBadge($contrast['card']) . '</dd></div>'
    . '</dl></section>';

// Предпросмотр палитры: цвета уже применены на странице, поэтому витрина показывает их вместе —
// по отдельной плашке не видно, как «акцент» читается на «фоне карточки».
//
// Цвет плашки берётся значением, а не классом bg-<key>: имя класса собиралось бы из ключа, и
// сборка стилей не увидела бы его в разметке (страж StyleSourceTest ловит такой просвет).
// Инлайновый style разрешён CSP (style-src 'unsafe-inline'), а значение прошло проверку формата.
$swatches = '';
foreach ($colorNames as $key => $label) {
    $swatches .= '<div class="rounded-lg border border-ink/10 p-3">'
        . '<div class="h-12 w-full rounded-lg border border-ink/10" style="background-color: ' . e((string) ($colors[$key] ?? '')) . '"></div>'
        . '<p class="mt-2 text-xs text-ink/60">' . e($label) . '</p>'
        . '<p class="text-xs text-ink/40">' . e((string) ($colors[$key] ?? '')) . '</p>'
        . '</div>';
}

$form = '<form class="space-y-4" method="post" action="/admin/design">' . csrfField()
    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Цвета</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Палитра печатается в страницу поверх артефакта стилей: '
    . 'классы <code>bg-paper</code>, <code>text-ink</code> и остальные подхватывают значения сами, '
    . 'править шаблоны не нужно.</p>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">' . $colorRows . '</div>'
    . '</section>'
    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Типографика</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Размеры в пикселях. Пустое поле означает «как в теме»: '
    . 'тогда переменная не печатается и работает исходное значение CSS.</p>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">' . $sizeRows . '</div>'
    . '</section>'
    . '<div class="flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 text-sm font-medium text-cream hover:opacity-90" type="submit">Сохранить оформление</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 text-sm hover:bg-ink/5" href="/admin/themes">Темы</a>'
    . '</div>'
    . '</form>';

$previewBlock = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Палитра на странице</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Плашки показывают текущие значения.</p>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">' . $swatches . '</div>'
    . '</section>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Оформление', $alert . $form . $contrastBlock . $previewBlock, $notice);
