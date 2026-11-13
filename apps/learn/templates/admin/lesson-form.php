<?php

declare(strict_types=1);

/**
 * @var array{module_id: string, slug: string, title: string, description: string, status: string} $values
 * @var array<string, string> $errors
 * @var string $error
 * @var string $action
 * @var bool $isNew
 * @var string $lessonId
 * @var list<array{id: string, course_id: string, title: string, description: string, status: string, sort: int}> $modules
 * @var string $notice
 */

const LEARN_FORM_INPUT_CLASS = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

$row = static function (array $field, array $values, array $errors): string {
    $name = (string) $field['name'];
    $error = (string) ($errors[$name] ?? '');
    $hint = $error !== '' ? $error : (string) ($field['hint'] ?? '');
    $value = (string) ($values[$name] ?? '');
    $invalid = $error === '' ? '' : ' aria-invalid="true"';
    $alert = $error === '' ? '' : ' role="alert"';
    $max = isset($field['max']) ? ' maxlength="' . e((string) $field['max']) . '"' : '';

    return '<div class="space-y-1.5"><label class="block text-sm" for="f-' . e($name) . '">'
        . '<span class="block font-medium">' . e((string) $field['label']) . '</span>'
        . '<input class="min-h-[44px] ' . LEARN_FORM_INPUT_CLASS . '" id="f-' . e($name) . '" name="' . e($name) . '"'
        . ' type="' . e((string) $field['type']) . '" value="' . e($value) . '"' . $max . $invalid . ' required>'
        . '</label>'
        . '<p class="' . e($error === '' ? 'text-sm text-ink/60' : 'text-sm text-bad') . '"' . $alert . '>'
        . e($hint) . '</p></div>';
};

$textarea = static function (array $field, array $values, array $errors): string {
    $name = (string) $field['name'];
    $error = (string) ($errors[$name] ?? '');
    $hint = $error !== '' ? $error : (string) ($field['hint'] ?? '');
    $value = (string) ($values[$name] ?? '');
    $invalid = $error === '' ? '' : ' aria-invalid="true"';
    $alert = $error === '' ? '' : ' role="alert"';

    return '<div class="space-y-1.5"><label class="block text-sm" for="f-' . e($name) . '">'
        . '<span class="block font-medium">' . e((string) $field['label']) . '</span>'
        . '<textarea class="min-h-[44px] ' . LEARN_FORM_INPUT_CLASS . '" id="f-' . e($name) . '" name="' . e($name) . '"'
        . ' rows="' . e((string) ($field['rows'] ?? 4)) . '"' . $invalid . ' required>'
        . e($value) . '</textarea></label>'
        . '<p class="' . e($error === '' ? 'text-sm text-ink/60' : 'text-sm text-bad') . '"' . $alert . '>'
        . e($hint) . '</p></div>';
};

$select = static function (array $field, array $values, array $errors, string $optionsHtml): string {
    $name = (string) $field['name'];
    $error = (string) ($errors[$name] ?? '');
    $hint = $error !== '' ? $error : (string) ($field['hint'] ?? '');
    $value = (string) ($values[$name] ?? '');
    $invalid = $error === '' ? '' : ' aria-invalid="true"';
    $alert = $error === '' ? '' : ' role="alert"';

    return '<div class="space-y-1.5"><label class="block text-sm" for="f-' . e($name) . '">'
        . '<span class="block font-medium">' . e((string) $field['label']) . '</span>'
        . '<select class="min-h-[44px] ' . LEARN_FORM_INPUT_CLASS . '" id="f-' . e($name) . '" name="' . e($name) . '"'
        . $invalid . ' required>'
        . $optionsHtml . '</select></label>'
        . '<p class="' . e($error === '' ? 'text-sm text-ink/60' : 'text-sm text-bad') . '"' . $alert . '>'
        . e($hint) . '</p></div>';
};

/** @var list<array{id: string, course_id: string, title: string, description: string, status: string, sort: int}> $modules */
/** @var array{module_id: string, slug: string, title: string, description: string, status: string} $values */
/** @var array<string, string> $errors */
/** @var bool $isNew */
/** @var string $lessonId */
/** @var string $action */
/** @var string $notice */
$moduleOptionsHtml = '';
foreach ($modules as $module) {
    $moduleOptionsHtml .= '<option value="' . e((string) $module['id']) . '"'
        . ((string) $module['id'] === $values['module_id'] ? ' selected' : '') . '>'
        . e((string) $module['title']) . '</option>';
}

$statusOptionsHtml = '';
foreach (['DRAFT' => 'Черновик', 'REVIEW' => 'На проверке', 'PUBLISHED' => 'Опубликовано'] as $code => $label) {
    $statusOptionsHtml .= '<option value="' . e($code) . '"'
        . ($values['status'] === $code ? ' selected' : '') . '>' . e($label) . '</option>';
}

$fields = $row([
    'name' => 'slug', 'label' => 'Адрес', 'type' => 'text', 'max' => 120,
    'hint' => 'Только строчные латинские буквы, цифры и дефис. По адресу урок открывается у ученика.',
], $values, $errors)
    . $row([
    'name' => 'title', 'label' => 'Название', 'type' => 'text', 'max' => 120,
    'hint' => 'От 2 до 120 символов. Видно в шапке урока и в списке.',
], $values, $errors)
    . $textarea([
    'name' => 'description', 'label' => 'Описание', 'rows' => 4, 'max' => 500,
    'hint' => 'До 500 символов. Можно оставить пустым.',
], $values, $errors)
    . $select([
    'name' => 'module_id', 'label' => 'Модуль', 'type' => 'select',
    'hint' => 'Урок живёт в модуле курса.',
], $values, $errors, $moduleOptionsHtml)
    . $select([
    'name' => 'status', 'label' => 'Статус', 'type' => 'select',
    'hint' => 'Ученик видит только опубликованное.',
], $values, $errors, $statusOptionsHtml);

$buttonsHtml = '<button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:opacity-90" type="submit">'
    . ($isNew ? 'Создать урок' : 'Сохранить урок')
    . '</button>';

$back = '<p class="text-sm"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-ink" href="/learn/admin/lessons">К списку уроков</a></p>';

$status = $isNew
    ? '<p class="text-sm text-ink/60">Новый урок. «Сохранить урок» оставит его в списке модуля; '
        . 'уровень и курс берутся от модуля.</p>'
    : '<p class="text-sm text-ink/60">Правка урока. Статус определяет, видит ли его ученик: '
        . 'опубликованное открыто, черновик и ревью — нет.</p>';

$hidden = '<input type="hidden" name="id" value="' . e($lessonId) . '">';

$form = '<form class="space-y-6" method="post" action="' . e($action) . '">' . csrfField() . $hidden
    . '<section class="space-y-4"><h2 class="font-semibold">Карточка урока</h2>' . $fields . '</section>'
    . '<div class="flex flex-wrap items-center gap-2">' . $buttonsHtml . '</div>'
    . $back . '</form>';

$content = $status . $form;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo adminShell(($isNew ? 'Новый урок' : 'Правка урока') . ' — админка', $content, $notice);
