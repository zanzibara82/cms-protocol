<?php

declare(strict_types=1);

/**
 * Экран «Разметка Schema.org» раздела «SEO и статистика»: справочник наборов объектов по
 * адресу страницы и форма правки.
 *
 * Методика подписана прямо на экране: печатается ровно то, что заведено, — без догадок о
 * содержимом страницы. Разметка печатается в голове той страницы, адрес которой указан.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $rows
 * @var string $mode
 * @var array<string, mixed>|null $row
 * @var array<string, string> $types
 * @var array<string, list<string>> $fields
 * @var array{path: string, schema_type: string, name: string, description: string, image: string, author: string, date_published: string, date_modified: string} $form
 * @var array<string, string> $errors
 */
$isEdit = $mode === 'edit' && $row !== null;
$action = $isEdit ? '/seo/admin/schema/' . rawurlencode((string) $row['id']) : '/seo/admin/schema';
$op = $isEdit ? 'save' : 'create';
$type = (string) $form['schema_type'];
$typeFields = $fields[$type] ?? ['name', 'description'];

$typeOptions = '';

foreach ($types as $value => $label) {
    $selected = $value === $type;
    $typeOptions .= '<option value="' . e((string) $value) . '"' . ($selected ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

/** Класс поля: красная рамка на поле с причиной отказа. */
$isError = static function (string $field) use ($errors): string {
    return (string) ($errors[$field] ?? '') !== '' ? ' border-red-400' : ' border-ink/20';
};

/** Причина отказа под полем. */
$fieldError = static function (string $field) use ($errors): string {
    $message = (string) ($errors[$field] ?? '');

    return $message === '' ? '' : '<span class="mt-1 block text-sm text-red-700">' . e($message) . '</span>';
};

/** Подсказка о поле: у каждого типа свой набор. */
$fieldHint = static function (string $field) use ($typeFields, $type): string {
    return in_array($field, $typeFields, true)
        ? ''
        : '<span class="mt-1 block text-sm text-ink/60">У типа «' . e((string) $type) . '» это поле не печатается.</span>';
};

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="'
    . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="' . e($op) . '">'
    . '<label class="block" for="schema_path">'
    . '<span class="text-sm text-ink/60">Адрес страницы</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('path') . ' px-3 py-2"'
    . ' id="schema_path" name="path" maxlength="255" placeholder="/classifieds/c/velosipedy"'
    . ' value="' . e((string) $form['path']) . '">'
    . $fieldError('path')
    . '</label>'
    . '<label class="block" for="schema_type">'
    . '<span class="text-sm text-ink/60">Тип объекта</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('schema_type') . ' bg-white px-3 py-2"'
    . ' id="schema_type" name="schema_type">' . $typeOptions . '</select>'
    . $fieldError('schema_type')
    . '</label>'
    . '<label class="block" for="schema_name">'
    . '<span class="text-sm text-ink/60">Название</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('name') . ' px-3 py-2"'
    . ' id="schema_name" name="name" maxlength="190" value="' . e((string) $form['name']) . '">'
    . $fieldError('name')
    . '</label>'
    . '<label class="block" for="schema_description">'
    . '<span class="text-sm text-ink/60">Описание</span>'
    . '<textarea class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('description') . ' px-3 py-2"'
    . ' id="schema_description" name="description" rows="3" maxlength="500">'
    . e((string) $form['description']) . '</textarea>'
    . $fieldError('description')
    . '</label>'
    . '<label class="block" for="schema_image">'
    . '<span class="text-sm text-ink/60">Картинка — путь от корня нашего сайта</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('image') . ' px-3 py-2"'
    . ' id="schema_image" name="image" maxlength="255" placeholder="/assets/img/brand/og-default.png"'
    . ' value="' . e((string) $form['image']) . '">'
    . $fieldHint('image')
    . $fieldError('image')
    . '</label>'
    . '<label class="block" for="schema_author">'
    . '<span class="text-sm text-ink/60">Автор</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('author') . ' px-3 py-2"'
    . ' id="schema_author" name="author" maxlength="190" value="' . e((string) $form['author']) . '">'
    . $fieldHint('author')
    . $fieldError('author')
    . '</label>'
    . '<label class="block" for="schema_date_published">'
    . '<span class="text-sm text-ink/60">Дата публикации — ГГГГ-ММ-ДД</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('date_published') . ' px-3 py-2"'
    . ' id="schema_date_published" name="date_published" maxlength="10"'
    . ' value="' . e((string) $form['date_published']) . '">'
    . $fieldHint('date_published')
    . $fieldError('date_published')
    . '</label>'
    . '<label class="block" for="schema_date_modified">'
    . '<span class="text-sm text-ink/60">Дата изменения — ГГГГ-ММ-ДД</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('date_modified') . ' px-3 py-2"'
    . ' id="schema_date_modified" name="date_modified" maxlength="10"'
    . ' value="' . e((string) $form['date_modified']) . '">'
    . $fieldHint('date_modified')
    . $fieldError('date_modified')
    . '</label>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . e($isEdit ? 'Сохранить разметку' : 'Добавить разметку') . '</button>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/seo/admin/schema">Отмена</a>'
        : '')
    . '</div></form>';

// Предпросмотр: тот же JSON, который уйдёт в страницу.
$preview = seoSchemaPreview([
    'path' => (string) $form['path'],
    'schema_type' => $type,
    'name' => (string) $form['name'],
    'description' => (string) $form['description'],
    'image' => (string) $form['image'],
    'author' => (string) $form['author'],
    'date_published' => (string) $form['date_published'],
    'date_modified' => (string) $form['date_modified'],
]);

$previewBlock = '<pre class="overflow-x-auto rounded-lg border border-ink/10 bg-white p-4 font-mono text-sm">'
    . e($preview) . '</pre>';

/** Кнопка строки со своей формой: действие — скрытым полем, Enter не молчит. */
$rowButton = static function (string $id): string {
    return '<form method="post" action="/seo/admin/schema/' . rawurlencode($id) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rowsHtml = '';

foreach ($rows as $item) {
    $rowsHtml .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="/seo/admin/schema/' . rawurlencode((string) $item['id']) . '">'
        . e((string) $item['path']) . '</a></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) $item['schema_type']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) $item['name']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $item['id']) . '</td>'
        . '</tr>';
}

$table = $rowsHtml === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Разметки пока нет — '
        . 'страницы идут без неё.</p>'
    : '<div class="overflow-x-auto"><table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Адрес</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Тип</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Название</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rowsHtml . '</tbody></table></div>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Разметка Schema.org</h1>'
    . '<p class="text-sm text-ink/70">Набор объектов по адресу страницы: тип, название, описание, картинка, '
    . 'автор и даты. Печатается ровно то, что заведено, — без догадок о содержимом страницы. '
    . 'Разметка печатается в голове той страницы, адрес которой указан.</p>'
    . '</header>'
    . $formHtml
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Предпросмотр разметки</h2>'
    . $previewBlock
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Справочник</h2>'
    . $table
    . '</section>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Разметка Schema.org', $content, $notice);
