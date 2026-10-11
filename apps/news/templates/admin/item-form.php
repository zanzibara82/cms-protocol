<?php

declare(strict_types=1);

/**
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<array{intent: string, label: string, tone: string}> $buttons
 * @var string $action
 * @var string $back
 * @var bool $isNew
 * @var string $statusLabel
 * @var string $publicHref
 * @var string $updated
 * @var int $oldSlugs
 * @var string $nav
 * @var string $notice
 * @var string $error
 * @var list<array{id: string, title: string, visible: bool}> $categories
 */
// Зона нажатия min-h-[44px] стоит литералом в каждом атрибуте class (страж TapTargetTest читает
// классы значения атрибута, а `e($INPUT)` даёт ему только имя переменной): в $INPUT остаются
// размеры и цвета поля, которые к зоне нажатия отношения не имеют.
$INPUT = 'mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$TITLE_MIN = fieldLimit(NEWS_FIELDS, 'title', 'min');
$textHint = 'Картинка горизонтальная, 1200×630.';

$reserved = '';
foreach (NEWS_RESERVED_SLUGS as $slug) {
    $reserved .= ($reserved === '' ? '' : ', ') . '«' . $slug . '»';
}

// Одна сборка строки поля: подпись, контрол и подсказка, которую заменяет отказ (§8.5 — человек
// видит, что править именно здесь, а не ищет причину в плашке вверху экрана). Отказ помечается
// role="alert": без этого экран изменения не объявил бы, а человек с программой чтения экрана
// остался бы в поле, которое сервер уже отверг.
$row = static function (array $field, array $values, array $errors) use ($INPUT): string {
    $name = (string) $field['name'];
    $error = (string) ($errors[$name] ?? '');
    $hint = $error !== '' ? $error : (string) ($field['hint'] ?? '');
    $value = (string) ($values[$name] ?? '');
    $invalid = $error === '' ? '' : ' aria-invalid="true"';
    $alert = $error === '' ? '' : ' role="alert"';
    $max = isset($field['max']) ? ' maxlength="' . e((string) $field['max']) . '"' : '';
    $mono = ($field['mono'] ?? false) === true ? ' font-mono' : '';
    $control = $field['type'] === 'textarea'
        ? '<textarea class="min-h-[44px] ' . e($INPUT . $mono) . '" id="f-' . e($name) . '" name="' . e($name) . '"'
            . $max . $invalid . ' rows="' . e((string) ($field['rows'] ?? 4)) . '">' . e($value) . '</textarea>'
        : '<input class="min-h-[44px] ' . e($INPUT . $mono) . '" id="f-' . e($name) . '" name="' . e($name) . '"'
            . ' type="' . e((string) $field['type']) . '" value="' . e($value) . '"' . $max . $invalid . '>';

    return '<div class="space-y-1"><label class="block text-sm" for="f-' . e($name) . '">'
        . '<span class="font-medium">' . e((string) $field['label']) . '</span>' . $control . '</label>'
        . '<p class="' . e($error === '' ? 'text-sm text-ink/60' : 'text-sm text-bad') . '"' . $alert . '>'
        . e($hint) . '</p></div>';
};

$two = static function (string $first, string $second): string {
    return '<div class="grid gap-4 sm:grid-cols-2">' . $first . $second . '</div>';
};

// Чекбокс сам несёт зону нажатие 44×44: `size-11` задаёт ровно те же 44 px, что и min-height,
// иначе клетка осталась бы 16 px, а требование проекта не имеет исключений для видимых контролов.
$check = static function (array $field, array $values, array $errors): string {
    $name = (string) $field['name'];
    $error = (string) ($errors[$name] ?? '');

    return '<label class="flex min-h-[44px] items-center gap-2 text-sm">'
        . '<input class="min-h-[44px] size-11 rounded border border-ink/20 bg-white" type="checkbox" id="f-' . e($name) . '"'
        . ' name="' . e($name) . '" value="on"' . ($values[$name] === 'on' ? ' checked' : '') . '>'
        . '<span><span class="font-medium">' . e((string) $field['label']) . '</span>'
        . '<span class="block ' . e($error === '' ? 'text-ink/60' : 'text-bad') . '"'
        . ($error === '' ? '' : ' role="alert"') . '>'
        . e($error !== '' ? $error : (string) $field['hint']) . '</span></span></label>';
};

$categoryOptionsHtml = '';
foreach ($categories as $category) {
    $categoryOptionsHtml .= '<option value="' . e((string) $category['id']) . '"'
        . ((string) $category['id'] === $values['categoryId'] ? ' selected' : '') . '>'
        . e((string) $category['title']) . ((bool) $category['visible'] === false ? ' (скрыта)' : '') . '</option>';
}

$fields = $row([
    'name' => 'title', 'label' => 'Заголовок', 'type' => 'text', 'max' => NEWS_FORM_MAX['title'],
    'hint' => 'От ' . $TITLE_MIN . ' до ' . NEWS_FORM_MAX['title'] . ' символов. По заголовку материал находят в ленте и в поиске.',
], $values, $errors);

$fields .= $two(
    $row([
        'name' => 'slug', 'label' => 'Адрес', 'type' => 'text', 'max' => NEWS_SLUG_MAX, 'mono' => true,
        'hint' => 'Пустое поле — адрес сложится из заголовка. Занятые адреса раздела: ' . $reserved . '.',
    ], $values, $errors),
    '<div class="space-y-1"><label class="block text-sm" for="f-categoryId">'
        . '<span class="font-medium">Рубрика</span>'
        . '<select class="min-h-[44px] ' . e($INPUT) . '" id="f-categoryId" name="categoryId"'
        . (isset($errors['categoryId']) ? ' aria-invalid="true"' : '') . '>'
        . '<option value="">Выберите рубрику</option>'
        . $categoryOptionsHtml
        . '</select></label>'
        . '<p class="' . e(isset($errors['categoryId']) ? 'text-sm text-bad' : 'text-sm text-ink/60') . '"'
        . (isset($errors['categoryId']) ? ' role="alert"' : '') . '>'
        . e((string) ($errors['categoryId'] ?? 'Рубрики заводят в справочнике раздела.')) . '</p></div>'
);

$fields .= $row([
    'name' => 'lead', 'label' => 'Лид', 'type' => 'textarea', 'rows' => 3, 'max' => NEWS_FORM_MAX['lead'],
    'hint' => 'До ' . NEWS_FORM_MAX['lead'] . ' символов. Можно оставить пустым: тогда карточка покажет только заголовок.',
], $values, $errors);

$fields .= $row([
    'name' => 'body', 'label' => 'Текст', 'type' => 'textarea', 'rows' => 16, 'mono' => true,
    'hint' => 'Абзац — блок строк до пустой строки; первая строка «## » задаёт его заголовок, выделение — **звёздочками**. '
        . fieldLimit(NEWS_FIELDS, 'paragraphs') . ' абзацев хватает на лонгрид.',
], $values, $errors);

// Обложка — поле пути и поле файла в одной форме. Файл перетирает путь: имя и расширение решает
// сервер, а текстовое поле остаётся для картинки, которая уже лежит на сайте.
$coverError = (string) ($errors['cover'] ?? '');
$fields .= $two(
    $row([
        'name' => 'tags', 'label' => 'Теги', 'type' => 'text', 'max' => NEWS_FORM_MAX['tags'],
        'hint' => 'Названия через запятую, до ' . fieldLimit(TAG_FIELDS, 'perItem') . ' штук. Новое название заведётся в справочнике само.',
    ], $values, $errors),
    '<div class="space-y-1"><label class="block text-sm" for="f-cover">'
        . '<span class="font-medium">Обложка</span>'
        . '<input class="min-h-[44px] ' . e($INPUT . ' font-mono') . '" id="f-cover" name="cover" type="text" maxlength="'
        . e((string) NEWS_FORM_MAX['cover']) . '" value="' . e($values['cover']) . '"'
        . ($coverError === '' ? '' : ' aria-invalid="true"') . ' placeholder="/uploads/news/…">'
        . '</label>'
        . '<p class="' . e($coverError === '' ? 'text-sm text-ink/60' : 'text-sm text-bad') . '"'
        . ($coverError === '' ? '' : ' role="alert"') . '>'
        . e($coverError !== '' ? $coverError : 'Путь картинки этого сайта. ' . $textHint) . '</p>'
        . '<input class="block min-h-[44px] w-full text-sm" id="f-cover_file" name="cover_file" type="file"'
        . ' accept="image/jpeg,image/png,image/webp">'
        . '<label class="block text-sm text-ink/60" for="f-cover_file">или загрузите файл — jpg, png или webp до '
        . NEWS_IMAGE_MAX_MB . ' МБ</label></div>'
);

$publish = $row([
    'name' => 'publishedAt', 'label' => 'Срок публикации', 'type' => 'datetime-local',
    'hint' => 'Пустое поле значит «сразу». Время считается по Москве: материал появится сам, когда наступит срок.',
], $values, $errors);

$publish .= $check([
    'name' => 'pinned', 'label' => 'Закрепить первым',
    'hint' => 'Материал идёт перед остальными в ленте, в рубрике и в каждом блоке.',
], $values, $errors);

$seo = '<p class="text-sm text-ink/60">Всё это можно оставить пустым: карточка соберёт заголовок и описание из лида.</p>'
    . $row([
        'name' => 'seoTitle', 'label' => 'Заголовок в поисковике', 'type' => 'text', 'max' => NEWS_FORM_MAX['seoTitle'],
        'hint' => 'До ' . NEWS_FORM_MAX['seoTitle'] . ' символов.',
    ], $values, $errors)
    . $row([
        'name' => 'seoDescription', 'label' => 'Описание в поисковике', 'type' => 'textarea', 'rows' => 2,
        'max' => NEWS_FORM_MAX['seoDescription'],
        'hint' => 'До ' . NEWS_FORM_MAX['seoDescription'] . ' символов.',
    ], $values, $errors);

$seo .= $two(
    $row([
        'name' => 'ogImage', 'label' => 'Картинка для ссылок', 'type' => 'text', 'max' => NEWS_FORM_MAX['ogImage'],
        'mono' => true,
        'hint' => 'Путь этого сайта. ' . $textHint . ' Пустая — возьмётся обложка.',
    ], $values, $errors),
    $check([
        'name' => 'noindex', 'label' => 'Не показывать поисковикам',
        'hint' => 'Для служебных материалов: адрес остаётся по ссылке, но в поиск не попадает.',
    ], $values, $errors)
);

$buttonsHtml = '';
foreach ($buttons as $button) {
    $tone = (string) $button['tone'];
    $class = match ($tone) {
        'primary' => 'rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90',
        'danger' => 'rounded-lg border border-bad/30 bg-white px-4 py-2 font-medium text-bad hover:bg-bad/10',
        default => 'rounded-lg border border-ink/20 px-4 py-2 font-medium hover:bg-ink/5',
    };
    $order = (string) $button['intent'] === 'publish' ? ' order-first' : '';

    $buttonsHtml .= '<button class="min-h-[44px] ' . e($class . $order) . '" type="submit" name="intent"'
        . ' value="' . e((string) $button['intent']) . '">' . e((string) $button['label']) . '</button>';
}

// Строка состояния над формой: человек, который правит опубликованный материал, обязан видеть, что
// он уже на сайте, до того как нажмёт кнопку.
$status = $isNew
    ? '<p class="text-sm text-ink/60">Новый материал. «Сохранить черновик» оставит его у вас в списке, '
        . '«На печать» покажет его в ленте сразу или по сроку.</p>'
    : '<p class="text-sm text-ink/60">Статус: <span class="font-medium text-ink">' . e($statusLabel) . '</span>'
        . ' · изменён: ' . e($updated)
        . ($oldSlugs > 0 ? ' · прежних адресов: ' . e((string) $oldSlugs) : '')
        . ' · <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-ink" href="'
        . e($publicHref) . '">карточка на сайте</a></p>';

$hidden = '<input type="hidden" name="status" value="' . e($values['status']) . '">'
    . '<input type="hidden" name="back" value="' . e($back) . '">';

$form = '<form class="space-y-6" method="post" action="' . e($action) . '" enctype="multipart/form-data">'
    . csrfField() . $hidden
    . '<section class="space-y-4"><h2 class="font-semibold">Материал</h2>' . $fields . '</section>'
    . '<section class="space-y-4"><h2 class="font-semibold">Выход на сайт</h2>' . $publish . '</section>'
    . '<section class="space-y-4"><h2 class="font-semibold">Для поисковика</h2>' . $seo . '</section>'
    . '<div class="flex flex-wrap items-center gap-2">' . $buttonsHtml . '</div>'
    . '<p class="text-sm"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-ink" href="'
    . e($back) . '">К списку материалов</a></p></form>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => '', 'reason' => $error]) . adminShell($isNew ? 'Новый материал' : 'Правка материала', $status . $nav . $form, $notice);
