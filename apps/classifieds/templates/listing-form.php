<?php

declare(strict_types=1);

/**
 * Форма объявления: создание и правка. Категория выбирается отдельным шагом, чтобы
 * сервер показал характеристики ветки без JavaScript. Поля с ошибками помечены.
 *
 * @var string $mode новый или правка
 * @var array<string, mixed>|null $listing
 * @var array{title: string, slug: string, category: string, body: string, price: string, negotiable: int, location: string, status: string, seo_title: string, seo_description: string, noindex: int, image: string, attrs: array<string, string>} $form
 * @var array<string, string> $errors
 * @var list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool}> $categories
 * @var list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}> $attributes
 * @var list<array{id: string, href: string}> $gallery
 * @var string $action
 */
$error = static function (string $name) use ($errors): string {
    $text = (string) ($errors[$name] ?? '');

    return $text === '' ? '' : '<span class="mt-1 block text-sm text-red-700">' . e($text) . '</span>';
};
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true"' : '';

// Категории печатаются деревом: выбор родителя виден по отступу, служебные ветки не прячутся.
$options = '';
$byParent = [];
foreach ($categories as $category) {
    $byParent[(string) ($category['parent_id'] ?? '')][] = $category;
}
$walk = static function (string $parent, int $depth) use (&$walk, &$options, $byParent, $form): void {
    foreach ($byParent[$parent] ?? [] as $category) {
        $options .= '<option value="' . e((string) $category['id']) . '"'
            . ((string) $category['id'] === (string) $form['category'] ? ' selected' : '') . '>'
            . e(str_repeat('— ', $depth) . (string) $category['title'])
            . ($category['visible'] === false ? ' (скрыта)' : '')
            . '</option>';
        $walk((string) $category['id'], $depth + 1);
    }
};
$walk('', 0);

$categoryTitle = '';
foreach ($categories as $category) {
    if ((string) $category['id'] === (string) $form['category']) {
        $categoryTitle = (string) $category['title'];
    }
}

$title = $mode === 'new' ? 'Новое объявление' : 'Правка объявления';
$intro = 'Заполните поля. Черновик можно сохранить и опубликовать позже.';

// Без выбранной категории показываем только шаг выбора: характеристики зависят от ветки.
if ((string) $form['category'] === '') {
    $content = '<section class="mx-auto max-w-3xl space-y-6">'
        . '<header class="space-y-2">'
        . '<h1 class="text-3xl font-bold">' . e($title) . '</h1>'
        . '<p class="text-ink/70">Сначала выберите категорию — от неё зависят дополнительные поля.</p>'
        . '</header>'
        . '<form class="space-y-5 rounded-xl border border-ink/10 bg-white p-5" method="get" action="' . e($action) . '">'
        . csrfField()
        . '<label class="block" for="category">'
        . '<span class="mb-1 block text-sm text-ink/70">Категория</span>'
        . '<select class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="category" name="category" required' . $invalid('category') . '>'
        . '<option value="">— выберите —</option>'
        . $options
        . '</select>'
        . $error('category')
        . '</label>'
        . '<div class="flex flex-wrap gap-2">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Продолжить</button>'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" href="/classifieds/cabinet">Отменить</a>'
        . '</div>'
        . '</form>'
        . '</section>';

    /* @safe html */ echo $content;

    return;
}

$attributeFields = static function (array $attribute) use ($form, $error, $invalid): string {
    $id = (string) $attribute['id'];
    $name = 'attrs[' . $id . ']';
    $errorKey = 'attrs_' . $id;
    $fieldId = 'attr-' . $id;
    $value = (string) ($form['attrs'][$id] ?? '');
    $required = $attribute['required'] ? ' required' : '';
    $control = '';

    if ($attribute['kind'] === 'select') {
        $choices = '<option value="">— не выбрано —</option>';

        foreach ($attribute['options'] as $choice) {
            $choices .= '<option value="' . e($choice) . '"' . ($choice === $value ? ' selected' : '') . '>'
                . e($choice) . '</option>';
        }

        $control = '<select class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="' . e($fieldId) . '"'
            . ' name="' . e($name) . '"' . $required . $invalid($errorKey) . '>' . $choices . '</select>';
    } elseif ($attribute['kind'] === 'bool') {
        $control = '<label class="flex min-h-[44px] items-center gap-3 text-sm">'
            . '<input class="size-11 min-h-[44px] rounded border border-ink/20" type="checkbox" id="' . e($fieldId) . '"'
            . ' name="' . e($name) . '" value="1"' . ($value === '1' ? ' checked' : '') . $invalid($errorKey) . '>'
            . '<span>' . e((string) $attribute['title']) . '</span></label>';
    } else {
        $inputmode = $attribute['kind'] === 'number' ? ' inputmode="decimal"' : '';
        $control = '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="' . e($fieldId) . '"'
            . ' name="' . e($name) . '" maxlength="200" value="' . e($value) . '"' . $inputmode . $required . $invalid($errorKey) . '>';
    }

    return '<div class="block">'
        . ($attribute['kind'] === 'bool'
            ? $control
            : '<label class="block" for="' . e($fieldId) . '"><span class="mb-1 block text-sm text-ink/70">'
                . e((string) $attribute['title'])
                . ($attribute['unit'] !== '' ? ', ' . e((string) $attribute['unit']) : '')
                . ($attribute['required'] ? ' *' : '')
                . '</span>' . $control . '</label>')
        . $error($errorKey)
        . '</div>';
};

$attributeHtml = '';

foreach ($attributes as $attribute) {
    $attributeHtml .= $attributeFields($attribute);
}

$galleryHtml = '';

if ($gallery !== []) {
    $items = '';

    foreach ($gallery as $image) {
        $items .= '<li class="space-y-2 rounded-lg border border-ink/10 p-2">'
            . '<img class="h-28 w-full rounded object-cover" src="' . e($image['href']) . '" alt="Дополнительное изображение">'
            . '<label class="flex min-h-[44px] items-center gap-2 text-sm">'
            . '<input class="size-11 min-h-[44px] rounded border border-ink/20" type="checkbox" name="remove_gallery[]" value="' . e($image['id']) . '">'
            . '<span>Убрать</span></label>'
            . '</li>';
    }

    $galleryHtml = '<ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">' . $items . '</ul>';
}

$content = '<section class="mx-auto max-w-3xl space-y-6">'
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">' . e($title) . '</h1>'
    . '<p class="text-ink/70">' . e($intro) . '</p>'
    . '<p class="text-sm text-ink/60">Категория: ' . e($categoryTitle)
    . ' · <a class="inline-flex min-h-[44px] items-center underline" href="' . e($action . ($mode === 'edit' ? '?category=' : '')) . '">сменить</a></p>'
    . '</header>'
    . '<form class="space-y-5 rounded-xl border border-ink/10 bg-white p-5" method="post" action="' . e($action) . '" enctype="multipart/form-data">'
    . csrfField()
    . '<input type="hidden" name="category" value="' . e($form['category']) . '">'
    . '<label class="block" for="title">'
    . '<span class="mb-1 block text-sm text-ink/70">Заголовок</span>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="title" name="title"'
    . ' maxlength="140" value="' . e($form['title']) . '" required' . $invalid('title') . '>'
    . $error('title')
    . '</label>'
    . '<label class="block" for="slug">'
    . '<span class="mb-1 block text-sm text-ink/70">Адрес (пусто — соберётся из заголовка)</span>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="slug" name="slug"'
    . ' maxlength="60" value="' . e($form['slug']) . '"' . $invalid('slug') . '>'
    . $error('slug')
    . '</label>';

if ($attributeHtml !== '') {
    $content .= '<div class="space-y-4 rounded-lg border border-ink/10 bg-cream/40 p-4">'
        . '<p class="text-sm font-medium">Характеристики</p>'
        . $attributeHtml
        . '</div>';
}

$content .= '<label class="block" for="body">'
    . '<span class="mb-1 block text-sm text-ink/70">Описание</span>'
    . '<textarea class="h-[180px] min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="body" name="body"'
    . ' maxlength="10000" required' . $invalid('body') . '>' . e($form['body']) . '</textarea>'
    . $error('body')
    . '</label>'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . '<label class="block" for="price">'
    . '<span class="mb-1 block text-sm text-ink/70">Цена, ₽</span>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 tabular-nums" id="price" name="price"'
    . ' inputmode="decimal" value="' . e($form['price']) . '"' . $invalid('price') . '>'
    . $error('price')
    . '</label>'
    . '<label class="block" for="location">'
    . '<span class="mb-1 block text-sm text-ink/70">Местоположение</span>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="location" name="location"'
    . ' maxlength="120" value="' . e($form['location']) . '"' . $invalid('location') . '>'
    . $error('location')
    . '</label>'
    . '</div>'
    . '<div class="space-y-3">'
    . '<label class="flex min-h-[44px] items-center gap-3 text-sm">'
    . '<input class="size-11 min-h-[44px] rounded border border-ink/20" type="checkbox" name="negotiable" value="1"'
    . ($form['negotiable'] === 1 ? ' checked' : '') . '>'
    . '<span>Договорная цена</span>'
    . '</label>'
    . '<label class="flex min-h-[44px] items-center gap-3 text-sm">'
    . '<input class="size-11 min-h-[44px] rounded border border-ink/20" type="checkbox" name="noindex" value="1"'
    . ($form['noindex'] === 1 ? ' checked' : '') . '>'
    . '<span>Не индексировать в поисковиках</span>'
    . '</label>'
    . '</div>'
    . '<label class="block" for="image">'
    . '<span class="mb-1 block text-sm text-ink/70">Главное изображение (jpg, png или webp до 5 МБ)</span>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="image" name="image" type="file"'
    . ' accept="image/jpeg,image/png,image/webp"' . $invalid('image') . '>'
    . $error('image')
    . '</label>';

if ((string) $form['image'] !== '') {
    $content .= '<div class="space-y-2">'
        . '<img class="h-40 rounded-lg object-cover" src="' . e($form['image']) . '" alt="Текущее изображение">'
        . '<label class="flex min-h-[44px] items-center gap-3 text-sm">'
        . '<input class="size-11 min-h-[44px] rounded border border-ink/20" type="checkbox" name="remove_image" value="1">'
        . '<span>Убрать текущее изображение</span>'
        . '</label>'
        . '</div>';
}

$content .= '<div class="space-y-3">'
    . '<p class="text-sm text-ink/70">Дополнительные изображения — до ' . e((string) CLASSIFIEDS_GALLERY_MAX) . ' штук</p>'
    . $galleryHtml
    . '<label class="block" for="images">'
    . '<span class="mb-1 block text-sm text-ink/70">Добавить изображения (jpg, png или webp)</span>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="images" name="images[]" type="file"'
    . ' accept="image/jpeg,image/png,image/webp" multiple' . $invalid('gallery') . '>'
    . $error('gallery')
    . '</label>'
    . '</div>'
    . '<label class="block" for="status">'
    . '<span class="mb-1 block text-sm text-ink/70">Статус</span>'
    . '<select class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="status" name="status">'
    . '<option value="DRAFT"' . ($form['status'] === 'DRAFT' ? ' selected' : '') . '>Черновик</option>'
    . '<option value="PUBLISHED"' . ($form['status'] === 'PUBLISHED' ? ' selected' : '') . '>Опубликовать</option>'
    . '</select>'
    . '</label>'
    . '<details class="rounded-lg border border-ink/10 p-4">'
    . '<summary class="cursor-pointer text-sm font-medium">SEO-поля</summary>'
    . '<div class="mt-3 space-y-4">'
    . '<label class="block" for="seo_title">'
    . '<span class="mb-1 block text-sm text-ink/70">SEO-заголовок</span>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="seo_title" name="seo_title"'
    . ' maxlength="120" value="' . e($form['seo_title']) . '"' . $invalid('seo_title') . '>'
    . $error('seo_title')
    . '</label>'
    . '<label class="block" for="seo_description">'
    . '<span class="mb-1 block text-sm text-ink/70">SEO-описание</span>'
    . '<textarea class="h-[80px] min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="seo_description"'
    . ' name="seo_description" maxlength="300"' . $invalid('seo_description') . '>' . e($form['seo_description']) . '</textarea>'
    . $error('seo_description')
    . '</label>'
    . '</div>'
    . '</details>'
    . '<div class="flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . 'Сохранить'
    . '</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
    . ' href="/classifieds/cabinet">Отменить</a>'
    . '</div>'
    . '</form>'
    . '</section>';
?>
<?= $content /* @safe html */ ?>
