<?php

declare(strict_types=1);

// Характеристики доски: поля, которые контент-менеджер заводит у категории, и значения этих
// полей у объявления. Набор категории наследуется потомками — поле «Год» у «Авто» работает
// и в «Авто / Запчасти». Значения хранятся отдельными строками: фильтр и сортировка идут
// по индексированным колонкам, а не по разбору JSON.

/**
 * @param array<string, mixed> $row
 * @return array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}
 */
function classifiedsAttributeShape(array $row): array
{
    $options = [];

    foreach (preg_split('/\r\n|\r|\n/u', (string) ($row['option_list'] ?? '')) ?: [] as $option) {
        $option = trim($option);

        if ($option !== '') {
            $options[] = $option;
        }
    }

    return [
        'id' => (string) $row['id'],
        'category_id' => (string) $row['category_id'],
        'key_slug' => (string) $row['key_slug'],
        'title' => (string) $row['title'],
        'kind' => (string) $row['kind'],
        'unit' => (string) ($row['unit'] ?? ''),
        'options' => $options,
        'required' => (int) $row['required'] === 1,
        'filterable' => (int) $row['filterable'] === 1,
        'order_index' => (int) $row['order_index'],
    ];
}

/** @return array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}|null */
function classifiedsAttributeById(string $id): ?array
{
    $row = one(
        'SELECT id, category_id, key_slug, title, kind, unit, option_list, required, filterable, order_index'
        . ' FROM ' . t('classifieds_attribute') . ' WHERE id = ?',
        [$id]
    );

    return $row === null ? null : classifiedsAttributeShape($row);
}

/**
 * Цепочка категорий от корня к выбранной: характеристики родителя идут раньше своих.
 *
 * @return list<string>
 */
function classifiedsCategoryChain(string $categoryId): array
{
    if ($categoryId === '') {
        return [];
    }

    $byId = [];
    foreach (classifiedsCategoryAll() as $row) {
        $byId[(string) $row['id']] = $row;
    }

    $chain = [];
    $cursor = $categoryId;

    for ($guard = 0; $guard < 12 && isset($byId[$cursor]); $guard++) {
        array_unshift($chain, $cursor);
        $cursor = (string) ($byId[$cursor]['parent_id'] ?? '');
    }

    return $chain;
}

/**
 * Набор характеристик категории вместе с унаследованными: порядок — от корня к листу,
 * внутри категории — order_index. Так динамическая форма читается сверху вниз.
 *
 * @return list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}>
 */
function classifiedsAttributesForCategory(string $categoryId): array
{
    $chain = classifiedsCategoryChain($categoryId);

    if ($chain === []) {
        return [];
    }

    $rows = all(
        'SELECT id, category_id, key_slug, title, kind, unit, option_list, required, filterable, order_index'
        . ' FROM ' . t('classifieds_attribute')
        . ' WHERE category_id IN (' . inPlaceholders(count($chain)) . ')'
        . ' ORDER BY order_index, title',
        $chain
    );

    $out = array_map('classifiedsAttributeShape', $rows);
    $position = array_flip($chain);

    usort($out, static function (array $left, array $right) use ($position): int {
        $leftCategory = $position[$left['category_id']] ?? 0;
        $rightCategory = $position[$right['category_id']] ?? 0;

        return [$leftCategory, $left['order_index'], $left['title']]
            <=> [$rightCategory, $right['order_index'], $right['title']];
    });

    return $out;
}

/**
 * Собственные характеристики категории для админки: унаследованные не редактируются здесь.
 *
 * @return list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}>
 */
function classifiedsAttributesOwn(string $categoryId): array
{
    $rows = all(
        'SELECT id, category_id, key_slug, title, kind, unit, option_list, required, filterable, order_index'
        . ' FROM ' . t('classifieds_attribute') . ' WHERE category_id = ? ORDER BY order_index, title',
        [$categoryId]
    );

    return array_map('classifiedsAttributeShape', $rows);
}

function classifiedsAttributeKeyTaken(string $categoryId, string $key, string $exceptId = ''): bool
{
    if ($exceptId === '') {
        return one(
            'SELECT id FROM ' . t('classifieds_attribute') . ' WHERE category_id = ? AND key_slug = ?',
            [$categoryId, $key]
        ) !== null;
    }

    return one(
        'SELECT id FROM ' . t('classifieds_attribute') . ' WHERE category_id = ? AND key_slug = ? AND id <> ?',
        [$categoryId, $key, $exceptId]
    ) !== null;
}

/**
 * @param array<string, string> $post
 * @return array{errors: array<string, string>, values: array{category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: int, filterable: int}}
 */
function classifiedsValidateAttribute(array $post, string $categoryId, string $exceptId = ''): array
{
    $errors = [];
    $title = trim((string) ($post['title'] ?? ''));
    $typedKey = trim((string) ($post['key'] ?? ''));
    $kind = trim((string) ($post['kind'] ?? 'text'));
    $unit = trim((string) ($post['unit'] ?? ''));
    $optionsRaw = (string) ($post['options'] ?? '');
    $required = ($post['required'] ?? '') === '1' ? 1 : 0;
    $filterable = ($post['filterable'] ?? '') === '1' ? 1 : 0;

    if (classifiedsCategoryById($categoryId) === null) {
        $errors['category'] = 'Категории уже нет — обновите список.';
    }

    if ($title === '') {
        $errors['title'] = 'Название пустое — покупатель должен понять, что это за поле.';
    } elseif (mb_strlen($title) > CLASSIFIEDS_ATTRIBUTE_TITLE_MAX) {
        $errors['title'] = 'Название длиннее ' . CLASSIFIEDS_ATTRIBUTE_TITLE_MAX . ' символов — сократите.';
    }

    if (!array_key_exists($kind, CLASSIFIEDS_ATTRIBUTE_KINDS)) {
        $errors['kind'] = 'Неизвестный тип поля — выберите из списка.';
        $kind = 'text';
    }

    $base = $typedKey !== '' ? $typedKey : $title;
    $key = rtrim(mb_substr(classifiedsSlugify($base), 0, CLASSIFIEDS_ATTRIBUTE_KEY_MAX), '-');

    if ($key === '') {
        $errors['key'] = 'Код поля не собрался — впишите латиницей, например year.';
    } elseif (preg_match('/^[a-z][a-z0-9-]*$/', $key) !== 1) {
        $errors['key'] = 'Код начинается с латинской буквы и состоит из букв, цифр и дефиса.';
    } elseif (classifiedsAttributeKeyTaken($categoryId, $key, $exceptId)) {
        $errors['key'] = 'Такой код у этой категории уже есть — придумайте другой.';
    }

    $options = [];
    if ($kind === 'select') {
        foreach (preg_split('/\r\n|\r|\n/u', $optionsRaw) ?: [] as $option) {
            $option = trim($option);

            if ($option === '') {
                continue;
            }

            if (mb_strlen($option) > CLASSIFIEDS_ATTRIBUTE_OPTION_MAX) {
                $errors['options'] = 'Вариант списка длиннее ' . CLASSIFIEDS_ATTRIBUTE_OPTION_MAX . ' символов — сократите.';
                break;
            }

            if (!in_array($option, $options, true)) {
                $options[] = $option;
            }
        }

        if ($options === []) {
            $errors['options'] = 'Для списка нужен хотя бы один вариант — по одному в строке.';
        } elseif (count($options) > CLASSIFIEDS_ATTRIBUTE_OPTIONS_MAX) {
            $errors['options'] = 'Вариантов больше ' . CLASSIFIEDS_ATTRIBUTE_OPTIONS_MAX . ' — сократите список.';
        }
    }

    if ($kind !== 'number') {
        $unit = '';
    } elseif (mb_strlen($unit) > CLASSIFIEDS_ATTRIBUTE_UNIT_MAX) {
        $errors['unit'] = 'Единица измерения длиннее ' . CLASSIFIEDS_ATTRIBUTE_UNIT_MAX . ' символов — сократите.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'category_id' => $categoryId,
            'key_slug' => $key,
            'title' => $title,
            'kind' => $kind,
            'unit' => $unit,
            'options' => $options,
            'required' => $required,
            'filterable' => $filterable,
        ],
    ];
}

/**
 * @param array{category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: int, filterable: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsCreateAttribute(array $values, ?DateTimeImmutable $now = null): array
{
    $moment = utcStamp($now ?? nowUtc());
    $next = (int) value(
        'SELECT COALESCE(MAX(order_index), 0) + 1 FROM ' . t('classifieds_attribute') . ' WHERE category_id = ?',
        [$values['category_id']]
    );

    insert('classifieds_attribute', [
        'id' => newId(),
        'category_id' => $values['category_id'],
        'key_slug' => $values['key_slug'],
        'title' => $values['title'],
        'kind' => $values['kind'],
        'unit' => $values['unit'] !== '' ? $values['unit'] : null,
        'option_list' => $values['options'] !== [] ? implode("\n", $values['options']) : null,
        'required' => $values['required'],
        'filterable' => $values['filterable'],
        'order_index' => $next,
        'created_at' => $moment,
        'updated_at' => $moment,
    ]);

    return ['ok' => true, 'error' => '', 'notice' => 'attribute_saved'];
}

/**
 * @param array{category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: int, filterable: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsUpdateAttribute(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $attribute = classifiedsAttributeById($id);

    if ($attribute === null) {
        return ['ok' => false, 'error' => 'Характеристики уже нет — обновите список.', 'notice' => ''];
    }

    run(
        'UPDATE ' . t('classifieds_attribute')
        . ' SET key_slug = ?, title = ?, kind = ?, unit = ?, option_list = ?, required = ?, filterable = ?, updated_at = ?'
        . ' WHERE id = ?',
        [
            $values['key_slug'],
            $values['title'],
            $values['kind'],
            $values['unit'] !== '' ? $values['unit'] : null,
            $values['options'] !== [] ? implode("\n", $values['options']) : null,
            $values['required'],
            $values['filterable'],
            utcStamp($now ?? nowUtc()),
            $id,
        ]
    );

    return ['ok' => true, 'error' => '', 'notice' => 'attribute_saved'];
}

/**
 * Удаление характеристики: занятая значениями не стирается — иначе объявления молча
 * потеряли бы заполненное поле.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsDeleteAttribute(string $id): array
{
    $attribute = classifiedsAttributeById($id);

    if ($attribute === null) {
        return ['ok' => false, 'error' => 'Характеристики уже нет — обновите список.', 'notice' => ''];
    }

    $used = (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_listing_attribute') . ' WHERE attribute_id = ?',
        [$id]
    );

    if ($used > 0) {
        return [
            'ok' => false,
            'error' => 'Характеристика занята: значений ' . $used . ' — сначала очистите их у объявлений.',
            'notice' => '',
        ];
    }

    run('DELETE FROM ' . t('classifieds_attribute') . ' WHERE id = ?', [$id]);

    return ['ok' => true, 'error' => '', 'notice' => 'attribute_deleted'];
}

/**
 * Проверка характеристик формы. Значения приходят полем `attrs[<id>]`; сохраняются только
 * те, что относятся к набору выбранной категории, — лишний id из запроса не пройдёт.
 *
 * @param array<string, mixed> $post
 * @return array{errors: array<string, string>, values: array<string, array{value_text: ?string, value_number: ?float}>}
 */
function classifiedsValidateListingAttributes(array $post, string $categoryId): array
{
    $errors = [];
    $values = [];
    $raw = $post['attrs'] ?? null;
    $attrs = is_array($raw) ? $raw : [];

    foreach (classifiedsAttributesForCategory($categoryId) as $attribute) {
        $id = (string) $attribute['id'];
        $kind = (string) $attribute['kind'];
        $label = (string) $attribute['title'];
        $value = $attrs[$id] ?? '';
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($kind === 'bool') {
            $checked = $value === '1';

            if ($attribute['required'] && !$checked) {
                $errors['attrs_' . $id] = 'Поле «' . $label . '» нужно отметить.';
            }

            if ($checked || $attribute['required']) {
                $values[$id] = ['value_text' => null, 'value_number' => $checked ? 1.0 : 0.0];
            }

            continue;
        }

        if ($value === '') {
            if ($attribute['required']) {
                $errors['attrs_' . $id] = 'Поле «' . $label . '» обязательное.';
            }

            continue;
        }

        if ($kind === 'number') {
            $numberRaw = str_replace([' ', ','], ['', '.'], $value);

            if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $numberRaw) !== 1) {
                $errors['attrs_' . $id] = 'Поле «' . $label . '» — только число, пример: 2019.';
                continue;
            }

            $values[$id] = ['value_text' => null, 'value_number' => (float) $numberRaw];
            continue;
        }

        if ($kind === 'select' && !in_array($value, $attribute['options'], true)) {
            $errors['attrs_' . $id] = 'В поле «' . $label . '» выберите вариант из списка.';
            continue;
        }

        if (mb_strlen($value) > CLASSIFIEDS_ATTRIBUTE_VALUE_MAX) {
            $errors['attrs_' . $id] = 'Поле «' . $label . '» длиннее ' . CLASSIFIEDS_ATTRIBUTE_VALUE_MAX . ' символов — сократите.';
            continue;
        }

        $values[$id] = ['value_text' => $value, 'value_number' => null];
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Полная замена значений объявления: правка категории не оставляет хвостов старого набора.
 *
 * @param array<string, array{value_text: ?string, value_number: ?float}> $values
 */
function classifiedsReplaceListingAttributes(string $listingId, array $values): void
{
    run('DELETE FROM ' . t('classifieds_listing_attribute') . ' WHERE listing_id = ?', [$listingId]);

    foreach ($values as $attributeId => $value) {
        insert('classifieds_listing_attribute', [
            'listing_id' => $listingId,
            'attribute_id' => (string) $attributeId,
            'value_text' => $value['value_text'],
            'value_number' => $value['value_number'],
        ]);
    }
}

/**
 * Значения характеристик для страницы объявления: порядок набора, человеческая подпись.
 *
 * @return list<array{id: string, title: string, display: string}>
 */
function classifiedsListingAttributeValues(string $listingId): array
{
    $rows = all(
        'SELECT a.id, a.title, a.kind, a.unit, v.value_text, v.value_number'
        . ' FROM ' . t('classifieds_listing_attribute') . ' v'
        . ' JOIN ' . t('classifieds_attribute') . ' a ON a.id = v.attribute_id'
        . ' WHERE v.listing_id = ? ORDER BY a.order_index, a.title',
        [$listingId]
    );

    $out = [];

    foreach ($rows as $row) {
        $kind = (string) $row['kind'];
        $text = trim((string) ($row['value_text'] ?? ''));
        $number = $row['value_number'] === null ? null : (float) $row['value_number'];
        $display = '';

        if ($kind === 'bool') {
            $display = $number !== null && $number > 0 ? 'Да' : 'Нет';
        } elseif ($kind === 'number' && $number !== null) {
            $display = number_format($number, 2, ',', ' ');

            if (str_ends_with($display, ',00')) {
                $display = substr($display, 0, -3);
            }

            $unit = (string) ($row['unit'] ?? '');

            if ($unit !== '') {
                $display .= ' ' . $unit;
            }
        } elseif ($text !== '') {
            $display = $text;
        }

        if ($display !== '') {
            $out[] = [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'display' => $display,
            ];
        }
    }

    return $out;
}

/**
 * Значения характеристик для полей формы: строками, как их набирал человек.
 *
 * @return array<string, string>
 */
function classifiedsListingAttributeFormValues(string $listingId): array
{
    $rows = all(
        'SELECT attribute_id, value_text, value_number FROM ' . t('classifieds_listing_attribute')
        . ' WHERE listing_id = ?',
        [$listingId]
    );

    $out = [];

    foreach ($rows as $row) {
        $id = (string) $row['attribute_id'];
        $text = trim((string) ($row['value_text'] ?? ''));
        $number = $row['value_number'] === null ? null : (float) $row['value_number'];

        if ($text !== '') {
            $out[$id] = $text;
        } elseif ($number !== null) {
            $formatted = number_format($number, 2, '.', '');

            if (str_ends_with($formatted, '.00')) {
                $formatted = substr($formatted, 0, -3);
            }

            $out[$id] = $formatted;
        }
    }

    return $out;
}

/**
 * Значения фильтров из адресной строки: только характеристики текущего набора, только
 * допустимые варианты. Мусор в ссылке не превращается в условие запроса.
 *
 * @param array<string, mixed> $query
 * @param list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}> $attributes
 * @return array{values: array<string, array{kind: string, value?: string, min?: string, max?: string}>, active: bool}
 */
function classifiedsFiltersFromQuery(array $query, array $attributes): array
{
    $raw = $query['f'] ?? null;
    $input = is_array($raw) ? $raw : [];
    $values = [];

    foreach ($attributes as $attribute) {
        if (!$attribute['filterable']) {
            continue;
        }

        $id = (string) $attribute['id'];
        $entry = $input[$id] ?? null;

        if (!is_array($entry)) {
            continue;
        }

        if ($attribute['kind'] === 'number') {
            $min = is_scalar($entry['min'] ?? null) ? trim((string) $entry['min']) : '';
            $max = is_scalar($entry['max'] ?? null) ? trim((string) $entry['max']) : '';
            $clean = [];

            foreach (['min' => $min, 'max' => $max] as $key => $number) {
                if ($number !== '' && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $number) === 1) {
                    $clean[$key] = $number;
                }
            }

            if ($clean !== []) {
                $values[$id] = ['kind' => 'number'] + $clean;
            }

            continue;
        }

        $value = is_scalar($entry['value'] ?? null) ? trim((string) $entry['value']) : '';

        if ($value === '') {
            continue;
        }

        if ($attribute['kind'] === 'select' && !in_array($value, $attribute['options'], true)) {
            continue;
        }

        if ($attribute['kind'] === 'bool' && !in_array($value, ['0', '1'], true)) {
            continue;
        }

        if ($attribute['kind'] === 'text') {
            $value = mb_substr($value, 0, 80);
        }

        $values[$id] = ['kind' => $attribute['kind'], 'value' => $value];
    }

    return ['values' => $values, 'active' => $values !== []];
}
