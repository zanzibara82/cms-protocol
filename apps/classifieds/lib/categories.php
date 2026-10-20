<?php

declare(strict_types=1);

// Категории доски: дерево произвольной глубины. Порядок держится внутри ветки одного
// родителя (order_index), а корни шагают по возрастанию той же колонки.

/**
 * Строки всех категорий, отсортированные по родителям и порядку: экран справочника
 * строит дерево из плоского списка сам.
 *
 * @return list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool}>
 */
function classifiedsCategoryAll(): array
{
    $rows = all(
        'SELECT id, parent_id, slug, title, order_index, visible FROM ' . t('classifieds_category')
        . ' ORDER BY order_index, slug'
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'parent_id' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'order_index' => (int) $row['order_index'],
            'visible' => (int) $row['visible'] === 1,
        ];
    }

    return $out;
}

/**
 * Полное дерево для админки: все узлы, включая скрытые, в порядке обхода с глубиной.
 * На доске тот же обход отсекает невидимые ветки (classifiedsCategoryVisible).
 *
 * @return list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool, depth: int}>
 */
function classifiedsCategoryFlatTree(): array
{
    $children = [];
    foreach (classifiedsCategoryAll() as $row) {
        $children[(string) ($row['parent_id'] ?? '')][] = $row;
    }

    $out = [];
    $walk = static function (string $parent, int $depth) use (&$walk, &$out, $children): void {
        foreach ($children[$parent] ?? [] as $row) {
            $row['depth'] = $depth;
            $out[] = $row;
            $walk((string) $row['id'], $depth + 1);
        }
    };
    $walk('', 0);

    return $out;
}

/**
 * Категории, открытые на доске: корни и их видимые потомки идут списком с отступом.
 * Скрытый родитель прячет всю ветку — на доске не бывает сироты, чьё место в навигации
 * держал невидимый предок.
 *
 * @return list<array{id: string, slug: string, title: string, depth: int}>
 */
function classifiedsCategoryVisible(): array
{
    $rows = classifiedsCategoryAll();

    // Скрытые узлы отбрасываем на два прохода: сначала собираем карту потомков,
    // потом отсекаем ветки, у которых предок невидим. Родителя нет — значит корень открыт.
    $children = [];
    $states = [];
    foreach ($rows as $row) {
        $parent = (string) ($row['parent_id'] ?? '');
        $children[$parent][] = $row;
        $states[(string) $row['id']] = $row;
    }

    $out = [];

    $walk = static function (string $parentId, int $depth) use (&$walk, $children, $states, &$out): void {
        foreach ($children[$parentId] ?? [] as $row) {
            $id = (string) $row['id'];
            $shown = (bool) $row['visible'] && ($parentId === '' || !empty($states[$parentId]['visible']));

            if ($shown) {
                $out[] = [
                    'id' => $id,
                    'slug' => (string) $row['slug'],
                    'title' => (string) $row['title'],
                    'depth' => $depth,
                ];
            }

            $walk($id, $shown ? $depth + 1 : $depth);
        }
    };

    $walk('', 0);

    return $out;
}

/** @return array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool}|null */
function classifiedsCategoryBySlug(string $slug): ?array
{
    $row = one(
        'SELECT id, parent_id, slug, title, order_index, visible FROM ' . t('classifieds_category') . ' WHERE slug = ?',
        [$slug]
    );

    if ($row === null) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'parent_id' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'order_index' => (int) $row['order_index'],
        'visible' => (int) $row['visible'] === 1,
    ];
}

/** @return array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool}|null */
function classifiedsCategoryById(string $id): ?array
{
    $row = one(
        'SELECT id, parent_id, slug, title, order_index, visible FROM ' . t('classifieds_category') . ' WHERE id = ?',
        [$id]
    );

    if ($row === null) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'parent_id' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'order_index' => (int) $row['order_index'],
        'visible' => (int) $row['visible'] === 1,
    ];
}

/** @return array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool}|null */
function classifiedsCategoryRow(string $id): ?array
{
    return classifiedsCategoryById($id);
}

/**
 * Дети узла в порядке следования списком. Родитель может отсутствовать: корни живут под ''.
 *
 * @return list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool}>
 */
function classifiedsCategoryChildrenOf(string $parentId): array
{
    $out = [];
    foreach (classifiedsCategoryAll() as $row) {
        if ((string) ($row['parent_id'] ?? '') === $parentId) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * Ветка: узел и все его потомки. Цикл в данных невозможен по построению (проверка смены родителя),
 * но на входе тестов границу берёт счётчик глубины — так чтение не прыгает по кругу вечность.
 *
 * @return list<string>
 */
function classifiedsCategoryDescendants(string $id, int $depth = 5): array
{
    if ($depth <= 0) {
        return [];
    }

    $out = [];

    foreach (classifiedsCategoryChildrenOf($id) as $child) {
        $childId = (string) $child['id'];
        $out[] = $childId;
        $out = array_merge($out, classifiedsCategoryDescendants($childId, $depth - 1));
    }

    return $out;
}

/**
 * Число объявлений в категории и во всей её ветке: стирание родителя не разрешается,
 * пока его потомки или сам узел оплачены объявлениями.
 */
function classifiedsCategoryListingCount(string $id): int
{
    $ids = array_merge([$id], classifiedsCategoryDescendants($id));

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_listing') . ' WHERE category_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids
    );
}

/** @return array{ok: bool, error: string, notice?: string} */
function classifiedsCategoryDelete(string $id): array
{
    $category = classifiedsCategoryById($id);

    if ($category === null) {
        return ['ok' => false, 'error' => 'Категории уже нет — обновите список.'];
    }

    $count = classifiedsCategoryListingCount($id);

    if ($count > 0) {
        return ['ok' => false, 'error' => 'Категория занята: в ней и её ветке ' . $count . ' ' . plural($count, ['объявление', 'объявления', 'объявлений']) . '.'];
    }

    // Потомки без родителя превращаются в корни: дерево сохраняется, ничего не исчезает.
    run('UPDATE ' . t('classifieds_category') . ' SET parent_id = NULL, updated_at = ? WHERE parent_id = ?', [utcStamp(nowUtc()), $id]);
    run('DELETE FROM ' . t('classifieds_category') . ' WHERE id = ?', [$id]);
    logInfo('classifieds.category_deleted', ['id' => $id]);

    return ['ok' => true, 'error' => '', 'notice' => 'category_deleted'];
}

/** @return array{ok: bool, error: string, notice?: string} */
function classifiedsCategoryToggle(string $id): array
{
    $category = classifiedsCategoryById($id);

    if ($category === null) {
        return ['ok' => false, 'error' => 'Категории уже нет — обновите список.'];
    }

    $next = $category['visible'] ? 0 : 1;
    run('UPDATE ' . t('classifieds_category') . ' SET visible = ?, updated_at = ? WHERE id = ?', [$next, utcStamp(nowUtc()), $id]);
    logInfo('classifieds.category_visible', ['id' => $id, 'visible' => $next]);

    return ['ok' => true, 'error' => '', 'notice' => 'category_saved'];
}

/** Закрытая ли категория корню дерева или другая — сравнение держится на id, а не на slug. */
function classifiedsCategorySlugTaken(string $slug, string $exceptId = ''): bool
{
    if ($exceptId === '') {
        return one(
            'SELECT id FROM ' . t('classifieds_category') . ' WHERE slug = ?',
            [$slug]
        ) !== null;
    }

    return one(
        'SELECT id FROM ' . t('classifieds_category') . ' WHERE slug = ? AND id <> ?',
        [$slug, $exceptId]
    ) !== null;
}

/**
 * Проверка формы категории: заголовок, адрес и родитель. Родитель со своей веткой
 * отклоняется — иначе дерево построено бы по кругу и никогда не кончается.
 *
 * @param string $exceptId пусто для новой категории
 * @param array<string, string> $post
 * @return array{errors: array<string, string>, values: array{title: string, slug: string, parent: string, visible: int}}
 */
function classifiedsValidateCategory(array $post, string $exceptId = ''): array
{
    $errors = [];
    $title = trim((string) ($post['title'] ?? ''));
    $typedSlug = trim((string) ($post['slug'] ?? ''));
    $parent = trim((string) ($post['parent'] ?? ''));
    $visible = ($post['visible'] ?? '') !== '0' ? 1 : 0;

    if ($title === '') {
        $errors['title'] = 'Название пустое — напишите, о чём категория.';
    } elseif (mb_strlen($title) > 120) {
        $errors['title'] = 'Название длиннее 120 символов — сократите.';
    }

    $slugCheck = classifiedsSlugPlan($typedSlug, $title, static fn (string $candidate): bool => classifiedsCategorySlugTaken($candidate, $exceptId), 'category');

    if ($slugCheck['error'] !== '') {
        $errors['slug'] = $slugCheck['error'];
    }

    if ($parent !== '' && classifiedsCategoryById($parent) === null) {
        $errors['parent'] = 'Родителя с таким адресом нет — выберите из списка.';
    }

    if ($parent !== '' && $parent === $exceptId) {
        $errors['parent'] = 'Родителем нельзя выбрать саму категорию.';
    }

    if ($exceptId !== '' && $parent !== '' && $parent !== $exceptId && in_array($parent, classifiedsCategoryDescendants($exceptId), true)) {
        // Проверка на каждый родитель заходит разворачиванием: потомок, у кого предок — сам
        // проверяемый узел, делает смену родителя петлёй в дереве.
        $errors['parent'] = 'Родителем нельзя выбрать собственную ветку — построится круг.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'title' => $title,
            'slug' => $slugCheck['slug'],
            'parent' => $parent,
            'visible' => $visible,
        ],
    ];
}

/**
 * Новая категория. Значения уже проверены classifiedsValidateCategory(); порядок вычисляет
 * вызывающий код — внутри ветки родителя.
 *
 * @param array{title: string, slug: string, parent: string, visible: int} $values
 * @return array{ok: bool, error: string, id: string, notice: string}
 */
function classifiedsCreateCategory(array $values, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'id' => '', 'notice' => ''];
    }

    // order_index живёт внутри ветки родителя: корень считает по своим братьям.
    $next = (int) value(
        'SELECT COALESCE(MAX(order_index), 0) + 1 FROM ' . t('classifieds_category') . ' WHERE ' . ($values['parent'] === '' ? 'parent_id IS NULL' : 'parent_id = ?'),
        $values['parent'] === '' ? [] : [$values['parent']]
    );
    $id = newId();

    insert('classifieds_category', [
        'id' => $id,
        'parent_id' => $values['parent'] === '' ? null : $values['parent'],
        'slug' => $slug,
        'title' => $values['title'],
        'order_index' => $next,
        'visible' => (int) $values['visible'],
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('classifieds.category_created', ['categoryId' => $id, 'slug' => $slug]);

    return ['ok' => true, 'error' => '', 'id' => $id, 'notice' => 'category_saved'];
}

/**
 * Правка категории: заголовок, адрес и родитель. Смена родителя перекладывает узел
 * вместе с пустым слотом порядка — порядок контролируют кнопками дальше.
 *
 * @param array{title: string, slug: string, parent: string, visible: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsUpdateCategory(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $category = classifiedsCategoryById($id);

    if ($category === null) {
        return ['ok' => false, 'error' => 'Категории уже нет — обновите список.', 'notice' => ''];
    }

    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'notice' => ''];
    }

    // Порядок сохраняется, но свежий родитель требует свежего места: конец списка своей ветки.
    $orderIndex = $category['order_index'];

    if ($category['parent_id'] !== null && (string) $category['parent_id'] !== ($values['parent'] === '' ? '' : $values['parent'])) {
        $orderIndex = (int) value(
            'SELECT COALESCE(MAX(order_index), 0) + 1 FROM ' . t('classifieds_category') . ' WHERE ' . ($values['parent'] === '' ? 'parent_id IS NULL' : 'parent_id = ?'),
            $values['parent'] === '' ? [] : [$values['parent']]
        );
    }

    run(
        'UPDATE ' . t('classifieds_category') . ' SET parent_id = ?, slug = ?, title = ?, order_index = ?, visible = ?, updated_at = ? WHERE id = ?',
        [
            $values['parent'] === '' ? null : $values['parent'],
            $slug,
            $values['title'],
            $orderIndex,
            (int) $values['visible'],
            utcStamp($now ?? nowUtc()),
            $id,
        ]
    );

    return ['ok' => true, 'error' => '', 'notice' => 'category_saved'];
}

/**
 * Обмен местами с соседом по одной ветке родителя. Соседа выбирает экран, здесь
 * только обмен значениями в транзакции.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsSwapCategories(string $firstId, string $secondId): array
{
    $first = classifiedsCategoryById($firstId);
    $second = classifiedsCategoryById($secondId);

    if ($first === null || $second === null) {
        return ['ok' => false, 'error' => 'Категория исчезла — обновите список.', 'notice' => ''];
    }

    tx(static function () use ($first, $second): void {
        // У order_index нет уникального ключа, поэтому меняем местами значения как есть:
        // прибавление единицы сдвигало обе строки в конец ветки.
        run('UPDATE ' . t('classifieds_category') . ' SET order_index = ? WHERE id = ?', [
            (int) $second['order_index'],
            (string) $first['id'],
        ]);
        run('UPDATE ' . t('classifieds_category') . ' SET order_index = ? WHERE id = ?', [
            (int) $first['order_index'],
            (string) $second['id'],
        ]);
    });

    return ['ok' => true, 'error' => '', 'notice' => 'category_saved'];
}
