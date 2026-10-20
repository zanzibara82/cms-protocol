<?php

declare(strict_types=1);

// Витрина C2: поиск по словам, фильтры характеристик, сортировки и страницы. Один построитель
// запроса кормит и категорию, и общий поиск — иначе два экрана разошлись бы в том, что
// считается опубликованным и как снимается спецсимвол LIKE.

function classifiedsSearchQuery(mixed $raw): string
{
    $value = is_string($raw) ? $raw : '';
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));

    return mb_substr($value, 0, CLASSIFIEDS_SEARCH_MAX);
}

/**
 * Состояние витрины из адресной строки: запрос, сортировка, вид. Релевантность без запроса
 * не имеет смысла — она честно превращается в сортировку по дате.
 *
 * @param array<string, mixed> $query
 * @return array{q: string, sort: string, view: string}
 */
function classifiedsFeedState(array $query): array
{
    $q = classifiedsSearchQuery($query['q'] ?? null);
    $sort = is_string($query['sort'] ?? null) ? (string) $query['sort'] : CLASSIFIEDS_SORT_DEFAULT;

    if (!array_key_exists($sort, CLASSIFIEDS_SORTS) || ($sort === 'relevance' && $q === '')) {
        $sort = CLASSIFIEDS_SORT_DEFAULT;
    }

    $view = is_string($query['view'] ?? null) ? (string) $query['view'] : CLASSIFIEDS_VIEW_DEFAULT;

    if (!in_array($view, CLASSIFIEDS_VIEWS, true)) {
        $view = CLASSIFIEDS_VIEW_DEFAULT;
    }

    return ['q' => $q, 'sort' => $sort, 'view' => $view];
}

/**
 * Лента опубликованных объявлений: категория с веткой или весь каталог, слова, фильтры,
 * сортировка и страница. LIMIT/OFFSET подставляются числами, значения — только плейсхолдерами.
 *
 * @param array{
 *     category_ids?: list<string>,
 *     owner_id?: string,
 *     q?: string,
 *     filters?: array<string, array{kind: string, value?: string, min?: string, max?: string}>,
 *     sort?: string,
 *     view?: string
 * } $options
 * @return array{
 *     rows: list<array<string, mixed>>, total: int, pages: int, current: int, perPage: int,
 *     q: string, sort: string, view: string,
 *     filters: array<string, array{kind: string, value?: string, min?: string, max?: string}>
 * }
 */
function classifiedsListingFeed(array $options, int $page): array
{
    $categoryIds = $options['category_ids'] ?? [];
    $q = classifiedsSearchQuery($options['q'] ?? '');
    $filters = $options['filters'] ?? [];
    $ownerId = trim((string) ($options['owner_id'] ?? ''));
    $sort = (string) ($options['sort'] ?? CLASSIFIEDS_SORT_DEFAULT);
    $view = (string) ($options['view'] ?? CLASSIFIEDS_VIEW_DEFAULT);

    if (!array_key_exists($sort, CLASSIFIEDS_SORTS) || ($sort === 'relevance' && $q === '')) {
        $sort = CLASSIFIEDS_SORT_DEFAULT;
    }

    if (!in_array($view, CLASSIFIEDS_VIEWS, true)) {
        $view = CLASSIFIEDS_VIEW_DEFAULT;
    }

    $parts = ["l.status = 'PUBLISHED'", CLASSIFIEDS_ACTIVE_SQL];
    $args = [];

    if ($ownerId !== '') {
        $parts[] = 'l.owner_id = ?';
        $args[] = $ownerId;
    }

    if ($categoryIds !== []) {
        $parts[] = 'l.category_id IN (' . inPlaceholders(count($categoryIds)) . ')';
        $args = array_merge($args, $categoryIds);
    }

    $like = '';

    if ($q !== '') {
        $like = '%' . escapeLike($q) . '%';
        $parts[] = '(l.title LIKE ? ESCAPE ? OR l.body LIKE ? ESCAPE ?)';
        $args[] = $like;
        $args[] = '\\';
        $args[] = $like;
        $args[] = '\\';
    }

    foreach ($filters as $attributeId => $filter) {
        $condition = '';
        $conditionArgs = [];
        $kind = (string) ($filter['kind'] ?? '');
        $value = (string) ($filter['value'] ?? '');

        if ($kind === 'number') {
            $min = (string) ($filter['min'] ?? '');
            $max = (string) ($filter['max'] ?? '');

            if ($min === '' && $max === '') {
                continue;
            }

            $condition = 'EXISTS (SELECT 1 FROM ' . t('classifieds_listing_attribute') . ' v'
                . ' WHERE v.listing_id = l.id AND v.attribute_id = ?';
            $conditionArgs[] = (string) $attributeId;

            if ($min !== '') {
                $condition .= ' AND v.value_number >= ?';
                $conditionArgs[] = $min;
            }

            if ($max !== '') {
                $condition .= ' AND v.value_number <= ?';
                $conditionArgs[] = $max;
            }

            $condition .= ')';
        } elseif ($kind === 'text') {
            $condition = 'EXISTS (SELECT 1 FROM ' . t('classifieds_listing_attribute') . ' v'
                . ' WHERE v.listing_id = l.id AND v.attribute_id = ? AND v.value_text LIKE ? ESCAPE ?)';
            $conditionArgs = [(string) $attributeId, '%' . escapeLike($value) . '%', '\\'];
        } elseif ($kind === 'select') {
            $condition = 'EXISTS (SELECT 1 FROM ' . t('classifieds_listing_attribute') . ' v'
                . ' WHERE v.listing_id = l.id AND v.attribute_id = ? AND v.value_text = ?)';
            $conditionArgs = [(string) $attributeId, $value];
        } elseif ($kind === 'bool' && $value === '1') {
            $condition = 'EXISTS (SELECT 1 FROM ' . t('classifieds_listing_attribute') . ' v'
                . ' WHERE v.listing_id = l.id AND v.attribute_id = ? AND v.value_number = 1)';
            $conditionArgs = [(string) $attributeId];
        } elseif ($kind === 'bool' && $value === '0') {
            $condition = 'NOT EXISTS (SELECT 1 FROM ' . t('classifieds_listing_attribute') . ' v'
                . ' WHERE v.listing_id = l.id AND v.attribute_id = ? AND v.value_number = 1)';
            $conditionArgs = [(string) $attributeId];
        }

        if ($condition !== '') {
            $parts[] = $condition;
            $args = array_merge($args, $conditionArgs);
        }
    }

    $where = implode(' AND ', $parts);
    $order = 'l.created_at DESC, l.id DESC';
    $orderArgs = [];

    if ($sort === 'price_asc') {
        $order = 'CASE WHEN l.price IS NULL THEN 1 ELSE 0 END, l.price ASC, l.id DESC';
    } elseif ($sort === 'price_desc') {
        $order = 'CASE WHEN l.price IS NULL THEN 1 ELSE 0 END, l.price DESC, l.id DESC';
    } elseif ($sort === 'relevance' && $like !== '') {
        $order = '(l.title LIKE ? ESCAPE ?) DESC, (l.body LIKE ? ESCAPE ?) DESC, l.created_at DESC, l.id DESC';
        $orderArgs = [$like, '\\', $like, '\\'];
    }

    $total = (int) value(
        sprintf('SELECT COUNT(*) FROM %s l WHERE %s', t('classifieds_listing'), $where),
        $args
    );

    $pages = $total === 0 ? 0 : (int) ceil($total / CLASSIFIEDS_PER_PAGE);
    $current = $pages === 0 ? max(1, $page) : min(max(1, $page), $pages);
    $offset = ($current - 1) * CLASSIFIEDS_PER_PAGE;

    $rows = $total === 0 ? [] : all(
        sprintf(
            'SELECT %s FROM %s l WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
            CLASSIFIEDS_CARD_COLUMNS,
            t('classifieds_listing'),
            $where,
            $order,
            CLASSIFIEDS_PER_PAGE,
            $offset
        ),
        array_merge($args, $orderArgs)
    );

    return [
        'rows' => $rows,
        'total' => $total,
        'pages' => $pages,
        'current' => $current,
        'perPage' => CLASSIFIEDS_PER_PAGE,
        'q' => $q,
        'sort' => $sort,
        'view' => $view,
        'filters' => $filters,
    ];
}

/**
 * Адрес страницы ленты с сохранением запроса, фильтров, сортировки и вида. Пустые значения
 * по умолчанию не печатаются: канонический адрес категории остаётся коротким.
 *
 * @param array{q: string, sort: string, view: string, filters: array<string, array{kind: string, value?: string, min?: string, max?: string}>} $state
 */
function classifiedsFeedUrl(string $base, array $state, int $page = 1): string
{
    $params = [];

    if ($state['q'] !== '') {
        $params['q'] = $state['q'];
    }

    if ($state['sort'] !== CLASSIFIEDS_SORT_DEFAULT) {
        $params['sort'] = $state['sort'];
    }

    if ($state['view'] !== CLASSIFIEDS_VIEW_DEFAULT) {
        $params['view'] = $state['view'];
    }

    foreach ($state['filters'] as $id => $filter) {
        $kind = (string) ($filter['kind'] ?? '');

        if ($kind === 'number') {
            if (($filter['min'] ?? '') !== '') {
                $params['f'][$id]['min'] = (string) $filter['min'];
            }

            if (($filter['max'] ?? '') !== '') {
                $params['f'][$id]['max'] = (string) $filter['max'];
            }

            continue;
        }

        if (($filter['value'] ?? '') !== '') {
            $params['f'][$id]['value'] = (string) $filter['value'];
        }
    }

    if ($page > 1) {
        $params['page'] = $page;
    }

    return $base . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}
