<?php

declare(strict_types=1);

/**
 * Экран метаданных раздела «SEO и статистика»: справочник адресов и форма правки.
 * Подсказки о длине — рекомендации, а не запрет: обрезанный поисковиком заголовок лучше
 * отказа принимать правку.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $rows
 * @var string $mode
 * @var array<string, mixed>|null $row
 * @var array<string, string> $robotsOptions
 * @var array{path: string, title: string, description: string, canonical: string, robots: string, og_title: string, og_description: string, og_image: string, noindex: int, in_sitemap: int} $form
 * @var array<string, string> $errors
 */
$isEdit = $mode === 'edit' && $row !== null;
$action = $isEdit ? '/seo/admin/' . rawurlencode((string) $row['id']) : '/seo/admin';
$op = $isEdit ? 'save' : 'create';

/** Класс поля: красная рамка на поле с причиной отказа. */
$isError = static function (string $field) use ($errors): string {
    return (string) ($errors[$field] ?? '') !== '' ? ' border-red-400' : ' border-ink/20';
};

/** Причина отказа под полем. */
$fieldError = static function (string $field) use ($errors): string {
    $message = (string) ($errors[$field] ?? '');

    return $message === '' ? '' : '<span class="mt-1 block text-sm text-red-700">' . e($message) . '</span>';
};

/** Подсказка о длине: короткий текст укладывается в сниппет, длинный — обрежется. */
$lengthHint = static function (string $value, int $recommended): string {
    $length = mb_strlen($value);

    if ($value === '') {
        return '';
    }

    if ($length <= $recommended) {
        return '<span class="mt-1 block text-sm text-ink/60">Сейчас ' . $length . ' знаков — укладывается в сниппет.</span>';
    }

    return '<span class="mt-1 block text-sm text-amber-700">Сейчас ' . $length . ' знаков: поисковик обрежет до '
        . $recommended . '. Правку принять можно — обрезается выдача, а не текст.</span>';
};

// Собранный HTML лежит в отдельной переменной: имя данных занимать нельзя — иначе цикл
// ниже обошёл бы сам себя.
$robotsHtml = '';

foreach ($robotsOptions as $value => $label) {
    $selected = (string) $form['robots'] === (string) $value;

    $robotsHtml .= '<option value="' . e((string) $value) . '"' . ($selected ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

$noindexChecked = (int) $form['noindex'] === 1;
$sitemapChecked = (int) $form['in_sitemap'] === 1;

$title = (string) $form['title'];
$description = (string) $form['description'];

// Предпросмотр сниппета: как страница будет выглядеть в выдаче. Это подсказка, а не
// обещание: поисковик решает по-своему, но порядок полей человек видит сразу.
$snippetTitle = $title === '' ? 'Заголовок страницы' : $title;
$snippetDescription = $description === '' ? 'Описание страницы' : $description;

$snippet = '<div class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<p class="text-sm text-emerald-700">' . e((string) $form['path'] === '' ? '/адрес/страницы' : (string) $form['path']) . '</p>'
    . '<p class="mt-1 text-lg text-blue-800">' . e($snippetTitle) . '</p>'
    . '<p class="mt-1 text-sm text-ink/70">' . e($snippetDescription) . '</p>'
    . '</div>';

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="'
    . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="' . e($op) . '">'
    . '<label class="block" for="meta_path">'
    . '<span class="text-sm text-ink/60">Адрес страницы</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('path') . ' px-3 py-2"'
    . ' id="meta_path" name="path" maxlength="' . e((string) SEO_META_PATH_MAX) . '"'
    . ' placeholder="/classifieds/c/velosipedy"'
    . ' value="' . e((string) $form['path']) . '">'
    . $fieldError('path')
    . '</label>'
    . '<label class="block" for="meta_title">'
    . '<span class="text-sm text-ink/60">Заголовок страницы (title)</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('title') . ' px-3 py-2"'
    . ' id="meta_title" name="title" maxlength="' . e((string) SEO_META_TITLE_MAX) . '"'
    . ' value="' . e($title) . '">'
    . $lengthHint($title, SEO_META_TITLE_RECOMMENDED)
    . $fieldError('title')
    . '</label>'
    . '<label class="block" for="meta_description">'
    . '<span class="text-sm text-ink/60">Описание страницы (description)</span>'
    . '<textarea class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('description') . ' px-3 py-2"'
    . ' id="meta_description" name="description" rows="3" maxlength="' . e((string) SEO_META_DESCRIPTION_MAX) . '">'
    . e($description) . '</textarea>'
    . $lengthHint($description, SEO_META_DESCRIPTION_RECOMMENDED)
    . $fieldError('description')
    . '</label>'
    . '<label class="block" for="meta_canonical">'
    . '<span class="text-sm text-ink/60">Канонический адрес (canonical) — путь от корня или полный адрес нашего домена</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('canonical') . ' px-3 py-2"'
    . ' id="meta_canonical" name="canonical" maxlength="' . e((string) SEO_META_PATH_MAX) . '"'
    . ' placeholder="/classifieds/c/velosipedy"'
    . ' value="' . e((string) $form['canonical']) . '">'
    . $fieldError('canonical')
    . '</label>'
    . '<label class="block" for="meta_robots">'
    . '<span class="text-sm text-ink/60">Директивы robots</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('robots') . ' bg-white px-3 py-2"'
    . ' id="meta_robots" name="robots">' . $robotsHtml . '</select>'
    . $fieldError('robots')
    . '</label>'
    . '<label class="block" for="meta_og_title">'
    . '<span class="text-sm text-ink/60">Заголовок карточки соцсети (og:title) — пусто значит «как заголовок страницы»</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('og_title') . ' px-3 py-2"'
    . ' id="meta_og_title" name="og_title" maxlength="' . e((string) SEO_META_TITLE_MAX) . '"'
    . ' value="' . e((string) $form['og_title']) . '">'
    . $fieldError('og_title')
    . '</label>'
    . '<label class="block" for="meta_og_description">'
    . '<span class="text-sm text-ink/60">Описание карточки соцсети (og:description) — пусто значит «как описание страницы»</span>'
    . '<textarea class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('og_description') . ' px-3 py-2"'
    . ' id="meta_og_description" name="og_description" rows="2" maxlength="' . e((string) SEO_META_DESCRIPTION_MAX) . '">'
    . e((string) $form['og_description']) . '</textarea>'
    . $fieldError('og_description')
    . '</label>'
    . '<label class="block" for="meta_og_image">'
    . '<span class="text-sm text-ink/60">Картинка карточки соцсети (og:image) — путь от корня</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('og_image') . ' px-3 py-2"'
    . ' id="meta_og_image" name="og_image" maxlength="' . e((string) SEO_META_PATH_MAX) . '"'
    . ' placeholder="/assets/img/brand/og-default.png"'
    . ' value="' . e((string) $form['og_image']) . '">'
    . $fieldError('og_image')
    . '</label>'
    . '<label class="flex items-start gap-3">'
    . '<input type="checkbox" name="noindex" value="1"' . ($noindexChecked ? ' checked' : '')
    . ' class="min-h-[44px] size-11 rounded border border-ink/20 bg-white">'
    . '<span class="text-sm">Закрыть адрес от индексации: страница получит метку noindex, nofollow и перестанет отдаваться из кэша.</span>'
    . '</label>'
    . '<label class="flex items-start gap-3">'
    . '<input type="checkbox" name="in_sitemap" value="1"' . ($sitemapChecked ? ' checked' : '')
    . ' class="min-h-[44px] size-11 rounded border border-ink/20 bg-white">'
    . '<span class="text-sm">Включить адрес в карту сайта: закрытые адреса в карту не попадают.</span>'
    . '</label>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . e($isEdit ? 'Сохранить метаданные' : 'Добавить метаданные') . '</button>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/seo/admin">Отмена</a>'
        : '')
    . '</div></form>';

/** Кнопка строки со своей формой: действие — скрытым полем, Enter не молчит. */
$rowButton = static function (string $id): string {
    return '<form method="post" action="/seo/admin/' . rawurlencode($id) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rowsHtml = '';

foreach ($rows as $row) {
    $rowTitle = (string) ($row['title'] ?? '');

    $marks = [];

    if ((int) ($row['noindex'] ?? 0) === 1) {
        $marks[] = 'noindex';
    }

    if ((int) ($row['in_sitemap'] ?? 0) === 1) {
        $marks[] = 'в карте';
    }

    $rowsHtml .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="/seo/admin/' . rawurlencode((string) $row['id']) . '">'
        . e((string) $row['path']) . '</a></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e($rowTitle === '' ? 'Как у страницы' : $rowTitle) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . e($marks === [] ? 'Открыт' : implode(', ', $marks)) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $row['id']) . '</td>'
        . '</tr>';
}

$table = $rowsHtml === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Метаданных пока нет — страницы идут со своими заголовками и описаниями.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Адрес</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Заголовок</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Пометки</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rowsHtml . '</tbody></table>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Метаданные</h1>'
    . '<p class="text-sm text-ink/70">Заголовок, описание, канонический адрес, директивы robots и карточка соцсети — '
    . 'по адресу страницы. Раздел может сделать страницу строже (закрыть от индексации, задать каноник), '
    . 'но никогда — мягче: закрытая ядром страница закрытой и останется.</p>'
    . '</header>'
    . $formHtml
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Предпросмотр сниппета</h2>'
    . $snippet
    . '</section>'
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Метаданные', $content, $notice);
