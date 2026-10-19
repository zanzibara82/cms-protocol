<?php

declare(strict_types=1);

// Характеристики рубрик (M2): зарегистрированный набор «что покупателю интересно
// знать о товарах этой рубрики» + значения на товарах. Атрибут — свойство рубрики,
// значение принадлежит паре товар-атрибут; списки выбора дополняются при сохранении
// товара, поэтому справочник не надо пополнять руками перед первым товаром.

/** @var list<string> */
const COMMERCE_ATTRIBUTE_TYPES = ['CHOICE', 'NUMBER'];

/** @var array<string, string> */
const COMMERCE_ATTRIBUTE_TYPE_LABELS = [
    'CHOICE' => 'Список значений',
    'NUMBER' => 'Число с диапазоном',
];

/**
 * Атрибуты с параметрами — выбор и число с границами.

 * @return list<array{id: string, category_id: string, slug: string, label: string, type: string, unit: ?string, order_index: int}>
 */
function commerceAttributes(string $categoryId): array
{
    $rows = all(
        'SELECT id, category_id, slug, label, type, unit, order_index'
        . ' FROM ' . t('commerce_attribute')
        . ' WHERE category_id = ? ORDER BY order_index, slug',
        [$categoryId]
    );

    /** @var list<array{id: string, category_id: string, slug: string, label: string, type: string, unit: ?string, order_index: int}> */
    return $rows;
}

/**
 * Атрибуты всех рубрик сразу: форма товара показывает набор по выбранным рубрикам.
 *
 * @param list<string> $categoryIds
 * @return list<array{id: string, category_id: string, slug: string, label: string, type: string, unit: ?string, order_index: int}>
 */
function commerceAttributesForCategories(array $categoryIds): array
{
    if ($categoryIds === []) {
        return [];
    }

    $placeholders = inPlaceholders(count($categoryIds));
    $rows = all(
        sprintf(
            'SELECT id, category_id, slug, label, type, unit, order_index'
            . ' FROM %s WHERE category_id IN (%s) ORDER BY order_index, slug',
            t('commerce_attribute'),
            $placeholders
        ),
        $categoryIds
    );

    /** @var list<array{id: string, category_id: string, slug: string, label: string, type: string, unit: ?string, order_index: int}> */
    return $rows;
}

/**
 * Значения атрибутов конкретного товара с подписями — карточка товара печатает
 * таблицу характеристик из этого списка.
 *
 * @return list<array{id: string, slug: string, label: string, type: string, unit: ?string, value: string, value_num: ?float}>
 */
function commerceProductAttributes(string $productId): array
{
    $rows = all(
        'SELECT a.id, a.slug, a.label, a.type, a.unit, pa.value, pa.value_num'
        . ' FROM ' . t('commerce_product_attribute') . ' pa'
        . ' JOIN ' . t('commerce_attribute') . ' a ON a.id = pa.attribute_id'
        . ' WHERE pa.product_id = ? ORDER BY a.order_index, a.slug',
        [$productId]
    );

    /** @var list<array{id: string, slug: string, label: string, type: string, unit: ?string, value: string, value_num: ?float}> */
    return $rows;
}

/** @return array{id: string, category_id: string, slug: string, label: string, type: string, unit: ?string, order_index: int}|null */
function commerceAttributeById(string $id): ?array
{
    $row = one(
        'SELECT id, category_id, slug, label, type, unit, order_index'
        . ' FROM ' . t('commerce_attribute')
        . ' WHERE id = ?',
        [$id]
    );

    /** @var array{id: string, category_id: string, slug: string, label: string, type: string, unit: ?string, order_index: int}|null */
    return $row;
}

/**
 * Справочник значений CHOICE-атрибута.
 *
 * @return list<array{id: string, attribute_id: string, value: string}>
 */
function commerceAttributeChoices(string $attributeId): array
{
    $rows = all(
        'SELECT id, attribute_id, value FROM ' . t('commerce_attribute_choice')
        . ' WHERE attribute_id = ? ORDER BY order_index, value',
        [$attributeId]
    );

    /** @var list<array{id: string, attribute_id: string, value: string}> */
    return $rows;
}

/**
 * Разбор POST формы атрибута. Слаг собирается из названия, но человек может вписать
 * свой — он же ключ фильтра в адресе витрины.
 *
 * @param array<string, string> $post
 * @return array{errors: array<string, string>, values: array{label: string, slug: string, type: string, unit: string}}
 */
function commerceValidateAttribute(array $post, string $currentSlug): array
{
    $errors = [];
    $label = trim((string) ($post['label'] ?? ''));
    $typoSlug = trim((string) ($post['slug'] ?? ''));
    $type = strtoupper(trim((string) ($post['type'] ?? 'CHOICE')));
    $unit = trim((string) ($post['unit'] ?? ''));

    if ($label === '') {
        $errors['label'] = 'Укажите название характеристики — например «Мощность».';
    } elseif (mb_strlen($label) > 120) {
        $errors['label'] = 'Название не длиннее 120 символов.';
    }

    if (!in_array($type, COMMERCE_ATTRIBUTE_TYPES, true)) {
        $errors['type'] = 'Тип выбирается из списка.';
    }

    if ($unit !== '' && mb_strlen($unit) > 32) {
        $errors['unit'] = 'Единица измерения не длиннее 32 символов.';
    }

    $plan = commerceSlugPlan(
        $typoSlug,
        $label,
        static function (string $candidate) use ($currentSlug): bool {
            if ($candidate === $currentSlug) {
                return false;
            }

            // Ключ фильтра в адресе не может совпасть с служебными параметрами
            // витрины и адресами раздела: «?q=…» ищет, «?c=…» зовёт рубрику.
            return in_array($candidate, ['q', 'page', 'c', 'cart', 'order', 'admin'], true)
                || value('SELECT COUNT(*) FROM ' . t('commerce_attribute') . ' WHERE slug = ?', [$candidate]) > 0;
        },
        'attribute'
    );
    if (!isset($errors['label']) && $plan['error'] !== '') {
        $errors['slug'] = $plan['error'];
    }

    return [
        'errors' => $errors,
        'values' => [
            'label' => $label,
            'slug' => $plan['slug'],
            'type' => $type,
            'unit' => $unit,
        ],
    ];
}

/**
 * Создать атрибут рубрики; позиция — в конец набора.
 *
 * @param array{label: string, slug: string, type: string, unit: string} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCreateAttribute(string $categoryId, array $values, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $id = newId();
    $position = (int) value('SELECT COALESCE(MAX(order_index), 0) FROM ' . t('commerce_attribute') . ' WHERE category_id = ?', [$categoryId]) + 1;

    insert('commerce_attribute', [
        'id' => $id,
        'category_id' => $categoryId,
        'slug' => $values['slug'],
        'label' => $values['label'],
        'type' => $values['type'],
        'unit' => $values['unit'] !== '' ? $values['unit'] : null,
        'order_index' => $position,
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('commerce.attribute_created', ['attributeId' => $id, 'categoryId' => $categoryId, 'slug' => $values['slug']]);

    return ['ok' => true, 'error' => '', 'notice' => 'attribute_saved'];
}

/**
 * Удалить атрибут. Значения на товарах уходят каскадом — товар остаётся, набор
 * фильтров рубрики сокращается сам.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceDeleteAttribute(string $id): array
{
    $attribute = commerceAttributeById($id);

    if ($attribute === null) {
        return ['ok' => false, 'error' => 'Характеристики уже нет.', 'notice' => ''];
    }

    run('DELETE FROM ' . t('commerce_attribute') . ' WHERE id = ?', [$id]);
    logInfo('commerce.attribute_deleted', ['attributeId' => $id, 'slug' => $attribute['slug']]);

    return ['ok' => true, 'error' => '', 'notice' => 'attribute_deleted'];
}

/**
 * Записать значения характеристик товара. Приходят пары «id атрибута → значение»;
 * атрибут обязан принадлежать одной из рубрик товара — чужой id игнорируется молча:
 * человек не может вписать значение для не выбранных им рубрик.
 *
 * @param string $productId
 * @param list<string> $categoryIds рубрики товара
 * @param array<string, string> $post POST формы товара: ключ attr_{attributeId}
 */
function commerceSaveProductAttributes(string $productId, array $categoryIds, array $post): void
{
    $attributes = commerceAttributesForCategories($categoryIds);
    $allowed = [];
    foreach ($attributes as $attribute) {
        $allowed[(string) $attribute['id']] = $attribute;
    }

    tx(static function () use ($productId, $allowed, $post): void {
        // Перед записью честно уничтожаем прошлые значения: несуществующие
        // характеристики не переживают правку полей товара.
        run('DELETE FROM ' . t('commerce_product_attribute') . ' WHERE product_id = ?', [$productId]);

        foreach ($allowed as $attributeId => $attribute) {
            $raw = trim((string) ($post['attr_' . $attributeId] ?? ''));

            if ($raw === '') {
                continue;
            }

            $numeric = null;

            if ($attribute['type'] === 'NUMBER') {
                $cleaned = str_replace(' ', '', str_replace(',', '.', $raw));
                if (!is_numeric($cleaned)) {
                    // Люди часто вписывают «до 100 Вт» — такого числа не будет;
                    // значение молча отбрасывается, причина в журнале.
                    logWarn('commerce_attribute_number', ['attributeId' => $attributeId, 'value' => $raw]);

                    continue;
                }
                $numeric = (float) $cleaned;
            }

            insert('commerce_product_attribute', [
                'product_id' => $productId,
                'attribute_id' => $attributeId,
                'value' => mb_substr($raw, 0, 300),
                'value_num' => $numeric,
            ]);

            if ($attribute['type'] === 'CHOICE') {
                commerceUpsertAttributeChoice($attributeId, $raw);
            }
        }
    });
}

/**
 * Значение CHOICE добавляется в справочник, если его там нет: рубрика постепенно
 * собирает живой список вариантов.
 */
function commerceUpsertAttributeChoice(string $attributeId, string $value): void
{
    $value = mb_substr(trim($value), 0, 300);

    if ($value === '') {
        return;
    }

    $seen = value(
        'SELECT COUNT(*) FROM ' . t('commerce_attribute_choice') . ' WHERE attribute_id = ? AND value = ?',
        [$attributeId, $value]
    );

    if ($seen > 0) {
        return;
    }

    $position = (int) value(
        'SELECT COALESCE(MAX(order_index), 0) FROM ' . t('commerce_attribute_choice') . ' WHERE attribute_id = ?',
        [$attributeId]
    ) + 1;

    insert('commerce_attribute_choice', [
        'id' => newId(),
        'attribute_id' => $attributeId,
        'value' => $value,
        'order_index' => $position,
    ]);
}
