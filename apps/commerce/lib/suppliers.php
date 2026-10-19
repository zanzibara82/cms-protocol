<?php

declare(strict_types=1);

// Поставщики (M5): словарная единица, к которой магазин привязывает варианты. Поставщик
// не виден покупателю — это справочник торговца: откуда товар, кому звонить за прайсом.
// Вариант может не иметь поставщика, но если он есть — его прайс-лист правит только
// его строки.

/**
 * Список поставщиков: новые приходят в конец по времени создания, для форм выбора.
 *
 * @return list<array{id: string, title: string, note: ?string, created_at: string, updated_at: string}>
 */
function commerceSuppliers(): array
{
    $rows = all(
        'SELECT id, title, note, created_at, updated_at FROM ' . t('commerce_supplier')
        . ' ORDER BY created_at, id'
    );

    /** @var list<array{id: string, title: string, note: ?string, created_at: string, updated_at: string}> */
    return $rows;
}

/** @return array{id: string, title: string, note: ?string, created_at: string, updated_at: string}|null */
function commerceSupplierById(string $id): ?array
{
    $row = one(
        'SELECT id, title, note, created_at, updated_at FROM ' . t('commerce_supplier') . ' WHERE id = ?',
        [$id]
    );

    /** @var array{id: string, title: string, note: ?string, created_at: string, updated_at: string}|null */
    return $row;
}

/** Есть ли варианты, привязанные к поставщику: страж удаления справочной строки. */
function commerceSupplierHasVariants(string $id): bool
{
    return value(
        'SELECT COUNT(*) FROM ' . t('commerce_variant') . ' WHERE supplier_id = ?',
        [$id]
    ) > 0;
}

/**
 * Разбор формы поставщика. Вина русским текстом по человеческим полям.
 *
 * @param array<string, mixed> $post
 * @return array{errors: array<string, string>, values: array{title: string, note: string}}
 */
function commerceValidateSupplier(array $post): array
{
    $values = [
        'title' => trim((string) ($post['title'] ?? '')),
        'note' => trim((string) ($post['note'] ?? '')),
    ];
    $errors = [];

    if ($values['title'] === '') {
        $errors['title'] = 'Укажите название поставщика.';
    } elseif (mb_strlen($values['title']) > COMMERCE_TITLE_MAX) {
        $errors['title'] = 'Название поставщика не длиннее ' . COMMERCE_TITLE_MAX . ' символов.';
    }

    if (mb_strlen($values['note']) > COMMERCE_SUPPLIER_NOTE_MAX) {
        $errors['note'] = 'Описание не длиннее ' . COMMERCE_SUPPLIER_NOTE_MAX . ' символов.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Создать поставщика.
 *
 * @param array{title: string, note: string} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCreateSupplier(array $values): array
{
    $id = newId();
    $moment = nowUtc();

    insert('commerce_supplier', [
        'id' => $id,
        'title' => $values['title'],
        'note' => $values['note'] !== '' ? $values['note'] : null,
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('commerce.supplier_created', ['supplierId' => $id, 'title' => $values['title']]);

    return ['ok' => true, 'error' => '', 'notice' => 'supplier_saved'];
}

/**
 * Правка поставщика.
 *
 * @param array{title: string, note: string} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceUpdateSupplier(string $id, array $values): array
{
    if (commerceSupplierById($id) === null) {
        return ['ok' => false, 'error' => 'Поставщика уже нет — обновите страницу.', 'notice' => ''];
    }

    run(
        'UPDATE ' . t('commerce_supplier')
        . ' SET title = ?, note = ?, updated_at = ? WHERE id = ?',
        [
            $values['title'],
            $values['note'] !== '' ? $values['note'] : null,
            utcStamp(nowUtc()),
            $id,
        ]
    );

    logInfo('commerce.supplier_updated', ['supplierId' => $id, 'title' => $values['title']]);

    return ['ok' => true, 'error' => '', 'notice' => 'supplier_saved'];
}

/**
 * Удалить поставщика. Пока к нему привязаны варианты, удаление запрещено: прайс-лист
 * этого поставщика обязан остаться осмысленным — строка со скоупом не исчезает молча.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceDeleteSupplier(string $id): array
{
    $supplier = commerceSupplierById($id);

    if ($supplier === null) {
        return ['ok' => false, 'error' => 'Поставщика уже нет.', 'notice' => ''];
    }

    if (commerceSupplierHasVariants($id)) {
        return [
            'ok' => false,
            'error' => 'У поставщика есть варианты — спишите их другим поставщиком, потом снимайте его.',
            'notice' => '',
        ];
    }

    run('DELETE FROM ' . t('commerce_supplier') . ' WHERE id = ?', [$id]);
    logInfo('commerce.supplier_deleted', ['supplierId' => $id, 'title' => $supplier['title']]);

    return ['ok' => true, 'error' => '', 'notice' => 'supplier_deleted'];
}
