<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $block */
/** @var array<string, mixed> $values */
/** @var int $version */
/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$button = 'inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90';
$isNew = $block === null;
$target = $isNew ? '/admin/content/blocks/new' : '/admin/content/blocks/' . rawurlencode((string) $block['id']);

$layoutOptions = '';
foreach (GLOBAL_BLOCK_LAYOUTS as $layout => $label) {
    $selected = (string) $values['layout'] === $layout ? ' selected' : '';
    $layoutOptions .= '<option value="' . e($layout) . '"' . $selected . '>' . e($label) . '</option>';
}

$form = '<form class="space-y-4" method="post" action="' . e($target) . '" enctype="multipart/form-data">' . csrfField()
    . '<input type="hidden" name="op" value="save-block">';

if (!$isNew) {
    $form .= '<input type="hidden" name="expected_version" value="' . e((string) $version) . '">'
        . '<input type="hidden" name="idempotency_key" value="' . e(newId()) . '">';
}

$form .= '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Содержимое блока</h2>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2">'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Заголовок</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" value="' . e((string) $values['title']) . '" required></label>'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Адрес блока</span>'
    . ($isNew
        ? '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="slug" value="' . e((string) $values['slug']) . '">'
        : '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" value="' . e((string) $values['slug']) . '" readonly>')
    . '<span class="mt-1.5 block text-ink/60">' . ($isNew
        ? 'Латиницей, от трёх символов. По этому адресу конструктор страниц вставляет блок.'
        : 'Адрес блока менять нельзя: по нему его вставляют в страницы.') . '</span></label>'
    . '</div>'
    . '<label class="mt-3 block text-sm"><span class="block font-medium">Подзаголовок</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="subtitle" value="' . e((string) $values['subtitle']) . '"></label>'
    . '<label class="mt-3 block text-sm"><span class="block font-medium">Текст</span>'
    . '<textarea class="min-h-[44px] ' . e($input) . '" rows="8" name="text">' . e((string) $values['text']) . '</textarea>'
    . '<span class="mt-1.5 block text-ink/60">Абзацы разделяйте пустой строкой, выделение — **звёздочками**.</span></label>'
    . '</section>'

    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Картинка и ссылка</h2>'
    . partial('partials/file-picker', [
        'name' => 'image',
        'fileKey' => 'image_file',
        'value' => (string) $values['image'],
        'label' => 'Картинка',
        'hint' => 'Необязательна: блок из текста и так соберётся.',
        'preview' => adminImagePreview((string) $values['image'], (string) $values['title']),
        'inputClass' => $input,
        'maxLength' => GLOBAL_BLOCK_IMAGE_MAX,
    ])
    . '<label class="mt-3 block text-sm"><span class="block font-medium">Описание картинки</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="image_alt" value="' . e((string) $values['image_alt']) . '"></label>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2">'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Ссылка</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="url" name="link" value="' . e((string) $values['link']) . '"></label>'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Подпись ссылки</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="link_label" value="' . e((string) $values['link_label']) . '"></label>'
    . '</div></section>'

    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Вид на странице</h2>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2">'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Вёрстка</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="layout">' . $layoutOptions . '</select></label>'
    . adminCheckbox('active', 'Блок включён', '1', (bool) $values['active'])
    . '</div>'
    . (!$isNew ? '<label class="mt-3 block text-sm"><span class="block font-medium">Комментарий к версии</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="comment" value=""></label>' : '')
    . '</section>'
    . '<p><button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">' . ($isNew ? 'Создать блок' : 'Сохранить блок') . '</button></p>'
    . '</form>';

$archiveForm = '';
if (!$isNew) {
    $archived = (int) $block['active'] !== 1;
    $op = $archived ? 'restore-block' : 'archive-block';
    $label = $archived ? 'Восстановить блок' : 'Убрать блок в архив';
    $archiveForm = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<form method="post" action="' . e($target) . '">' . csrfField()
        . '<input type="hidden" name="op" value="' . e($op) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">' . e($label) . '</button>'
        . '<p class="mt-1.5 text-sm text-ink/60">Архивный блок перестаёт печататься в страницах, но остаётся в базе вместе с историей.</p>'
        . '</form></section>';
}

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell($isNew ? 'Новый глобальный блок' : 'Глобальный блок', $alert . $form . $archiveForm, $notice);
