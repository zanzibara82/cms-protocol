<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var array<string, string> $values */

// Настройки шапки и подвала: текст и логотип каждого места. Одна форма на оба блока — экран
// показывает их как одно целое, и сохраняются они одной транзакцией. Класс min-h-[44px] стоит
// литералом в каждом атрибуте class (страж TapTargetTest читает классы значения атрибута).
$input = 'mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

/** Поле логотипа: путь в опции, выбор файла и превью того, что стоит сейчас. */
$logoField = static function (string $key, string $fileKey, string $label) use ($input, $values): string {
    $fieldId = 'settings-' . $key . '-file';
    $value = (string) ($values[$key] ?? '');
    $preview = adminImagePreview($value, $label);

    return '<div class="space-y-2">' . $preview
        . '<label class="block text-sm"><span class="font-medium">' . e($label) . '</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="' . e($key) . '" value="' . e($value) . '">'
        . '<span class="mt-1 block text-ink/60">Путь на сайте или файл: jpg, png, webp до 5 МБ. Пусто — логотипа нет.</span></label>'
        . '<div class="flex items-start gap-2">'
        . '<input class="sr-only" id="' . e($fieldId) . '" type="file" name="' . e($fileKey) . '" accept="image/jpeg,image/png,image/webp">'
        . '<label class="inline-flex min-h-[44px] min-w-[44px] cursor-pointer items-center justify-center rounded-lg border border-ink/20 bg-white hover:bg-ink/5" for="' . e($fieldId) . '" title="Выбрать логотип с компьютера">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>'
        . '<span class="sr-only">Выбрать логотип с компьютера</span></label>'
        . '<span class="text-sm text-ink/60">Присланный файл заменит путь после сохранения.</span>'
        . '</div></div>';
};

/** Текстовое поле: обычная строка для шапки, многострочное — для подписи подвала. */
$textField = static function (string $key, string $label, string $hint, bool $multiline = false) use ($input, $values): string {
    $value = (string) ($values[$key] ?? '');
    $max = (string) SITE_SETTINGS_FIELDS[$key]['max'];
    $control = $multiline
        ? '<textarea class="min-h-[44px] ' . e($input) . '" name="' . e($key) . '" maxlength="' . e($max) . '" rows="3">' . e($value) . '</textarea>'
        : '<input class="min-h-[44px] ' . e($input) . '" type="text" name="' . e($key) . '" maxlength="' . e($max) . '" value="' . e($value) . '">';

    return '<label class="block text-sm"><span class="font-medium">' . e($label) . '</span>'
        . $control
        . '<span class="mt-1 block text-ink/60">' . e($hint) . '</span></label>';
};

$header = '<section class="space-y-4 rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Шапка сайта</h2>'
    . '<p class="text-sm text-ink/70">Логотип и название печатаются в верхней полосе каждой страницы.</p>'
    . $logoField('header_logo', 'header_logo_file', 'Логотип шапки')
    . $textField('header_text', 'Текст шапки', 'Печатается рядом с логотипом. Пусто — только знак сайта.')
    . '</section>';

$footer = '<section class="space-y-4 rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Подвал сайта</h2>'
    . '<p class="text-sm text-ink/70">Логотип и подпись печатаются в нижней полосе каждой страницы.</p>'
    . $logoField('footer_logo', 'footer_logo_file', 'Логотип подвала')
    . $textField('footer_text', 'Текст подвала', 'Подпись сайта под колонками меню.', true)
    . $textField('footer_copyright', 'Копирайт подвала', 'Строка в самом низу подвала. Пусто — строки нет.')
    . '</section>';

$form = '<form class="space-y-4" method="post" action="/admin/settings" enctype="multipart/form-data">' . csrfField()
    . $header
    . $footer
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:opacity-90" type="submit">Сохранить настройки</button>'
    . '</form>';

// Отказ: заголовок из словаря уведомлений, причина — из сессионного flash (admin/alert).
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Настройки', $alert . $form, $notice);
