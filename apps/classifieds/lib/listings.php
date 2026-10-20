<?php

declare(strict_types=1);

// Объявления доски: списки для витрины и кабинета, проверка формы и правка статусов.
// Транзакции tx() здесь не требуются: правятся одиночные строки, а составные части
// (изображения и характеристики) будут позже.

/** Колонки карточки: без описания и служебных колонок — список и витрина ими не сыт. */
const CLASSIFIEDS_CARD_COLUMNS = 'l.id, l.slug, l.title, l.category_id, l.price, l.currency, l.negotiable,'
    . ' l.location, l.image, l.status, l.created_at';

/** Полный набор: страница объявления и кабинет переживают описание и SEO. */
const CLASSIFIEDS_FULL_COLUMNS = CLASSIFIEDS_CARD_COLUMNS . ', l.owner_id, l.body, l.seo_title,'
    . ' l.seo_description, l.noindex, l.updated_at';

/** Страница каталога: строк на лист. */
const CLASSIFIEDS_PER_PAGE = 20;

/** На главной доске свежих объявлений в один экран. */
const CLASSIFIEDS_HOME_LIMIT = 12;

/**
 * Приводит строку базы к договору шаблонов: NULL там, где пусто, и строгие типы.
 *
 * @param array<string, mixed> $row
 * @return array{id: string, owner_id: string, category_id: string, slug: string, title: string, body: string, price: ?float, currency: string, negotiable: bool, location: ?string, image: ?string, status: string, seo_title: ?string, seo_description: ?string, noindex: bool, created_at: string, updated_at: string}
 */
function classifiedsListingShape(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'owner_id' => (string) $row['owner_id'],
        'category_id' => (string) $row['category_id'],
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'body' => (string) $row['body'],
        'price' => $row['price'] === null ? null : (float) $row['price'],
        'currency' => (string) $row['currency'],
        'negotiable' => (int) $row['negotiable'] === 1,
        'location' => $row['location'] !== null && (string) $row['location'] !== '' ? (string) $row['location'] : null,
        'image' => $row['image'] !== null && (string) $row['image'] !== '' ? (string) $row['image'] : null,
        'status' => (string) $row['status'],
        'seo_title' => $row['seo_title'] !== null && (string) $row['seo_title'] !== '' ? (string) $row['seo_title'] : null,
        'seo_description' => $row['seo_description'] !== null && (string) $row['seo_description'] !== '' ? (string) $row['seo_description'] : null,
        'noindex' => (int) $row['noindex'] === 1,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

/** Объявление по id целиком: кабинет и страница работают по одной форме договора. */
/** @return array{id: string, owner_id: string, category_id: string, slug: string, title: string, body: string, price: ?float, currency: string, negotiable: bool, location: ?string, image: ?string, status: string, seo_title: ?string, seo_description: ?string, noindex: bool, created_at: string, updated_at: string}|null */
function classifiedsListingById(string $id): ?array
{
    $row = one('SELECT ' . CLASSIFIEDS_FULL_COLUMNS . ' FROM ' . t('classifieds_listing') . ' l WHERE l.id = ?', [$id]);

    return $row === null ? null : classifiedsListingShape($row);
}

/**
 * Открытое объявление по адресу: черновики витрине не светятся. Обратная ссылка на
 * категорию нужна хлебной крошке страницы — она экономит вторую выборку.
 *
 * @return array<string, mixed>|null
 */
function classifiedsPublishedListingWithCategory(string $slug): ?array
{
    $row = one(
        'SELECT ' . CLASSIFIEDS_FULL_COLUMNS . ', c.slug AS category_slug, c.title AS category_title'
        . ' FROM ' . t('classifieds_listing') . ' l'
        . ' LEFT JOIN ' . t('classifieds_category') . ' c ON c.id = l.category_id'
        . " WHERE l.slug = ? AND l.status = 'PUBLISHED'",
        [$slug]
    );

    return $row === null ? null : $row;
}

/**
 * Объявление любого статуса по адресу — для предпросмотра и кабинета: владельцу
 * черновик виден по прямой ссылке, гостю адрес отвечает как несуществующий.
 *
 * @return array{id: string, owner_id: string, category_id: string, slug: string, title: string, body: string, price: ?float, currency: string, negotiable: bool, location: ?string, image: ?string, status: string, seo_title: ?string, seo_description: ?string, noindex: bool, created_at: string, updated_at: string}|null
 */
function classifiedsListingAnyStatus(string $slug): ?array
{
    $row = one('SELECT ' . CLASSIFIEDS_FULL_COLUMNS . ' FROM ' . t('classifieds_listing') . ' l WHERE l.slug = ?', [$slug]);

    return $row === null ? null : classifiedsListingShape($row);
}

/**
 * Список объявлений категории вместе с веткой потомков: корень «Авто» показывает
 * и «Авто / Запчасти», как читатель и ожидает от доски.
 *
 * @param array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool} $category
 * @return array{rows: list<array<string, mixed>>, total: int, pages: int, current: int}
 */
function classifiedsCategoryFeed(array $category, int $page): array
{
    $ids = array_merge([$category['id']], classifiedsCategoryDescendants($category['id']));
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $total = (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_listing') . ' l'
        . " WHERE l.category_id IN ({$marks}) AND l.status = 'PUBLISHED'",
        $ids
    );

    if ($total === 0) {
        return ['rows' => [], 'total' => 0, 'pages' => 0, 'current' => max(1, $page)];
    }

    $pages = (int) ceil($total / CLASSIFIEDS_PER_PAGE);
    $current = min(max(1, $page), $pages);
    $offset = ($current - 1) * CLASSIFIEDS_PER_PAGE;

    $rows = all(
        'SELECT ' . CLASSIFIEDS_CARD_COLUMNS . ' FROM ' . t('classifieds_listing') . ' l'
        . " WHERE l.category_id IN ({$marks}) AND l.status = 'PUBLISHED'"
        . ' ORDER BY l.created_at DESC, l.id DESC'
        . ' LIMIT ' . (string) CLASSIFIEDS_PER_PAGE . ' OFFSET ' . (string) $offset,
        $ids
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'current' => $current];
}

/**
 * Свежие публикации главной доски: одна выборка на экран, пагинации нет.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsHomeFeed(): array
{
    return all(
        'SELECT ' . CLASSIFIEDS_CARD_COLUMNS . ' FROM ' . t('classifieds_listing') . ' l'
        . " WHERE l.status = 'PUBLISHED'"
        . ' ORDER BY l.created_at DESC, l.id DESC'
        . ' LIMIT ' . (string) CLASSIFIEDS_HOME_LIMIT
    );
}

/**
 * Список объявлений продавца для кабинета.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsOwnerListings(string $ownerId): array
{
    return all(
        'SELECT ' . CLASSIFIEDS_CARD_COLUMNS . ' FROM ' . t('classifieds_listing') . ' l'
        . ' WHERE l.owner_id = ?'
        . ' ORDER BY l.created_at DESC, l.id DESC',
        [$ownerId]
    );
}

/** Занят ли адрес другого объявления; своё ($exceptId) не считается занятием. */
function classifiedsListingSlugTaken(string $slug, string $exceptId = ''): bool
{
    if ($exceptId === '') {
        return one(
            'SELECT id FROM ' . t('classifieds_listing') . ' WHERE slug = ?',
            [$slug]
        ) !== null;
    }

    return one(
        'SELECT id FROM ' . t('classifieds_listing') . ' WHERE slug = ? AND id <> ?',
        [$slug, $exceptId]
    ) !== null;
}

/**
 * Проверка формы объявления. Категория обязана существовать и быть видимой — публикация
 * в закрытой ветке засыпает адреса, которые никто не найдёт по навигации.
 *
 * @param array<string, string> $post
 * @param string $exceptId объявление, которое правим: своя ссылка не считается занятой
 * @return array{errors: array<string, string>, values: array{title: string, slug: string, category: string, body: string, price: string, price_float: ?float, negotiable: int, location: string, status: string, seo_title: string, seo_description: string, noindex: int}}
 */
function classifiedsValidateListing(array $post, string $exceptId = ''): array
{
    $errors = [];
    $title = trim((string) ($post['title'] ?? ''));
    $typedSlug = trim((string) ($post['slug'] ?? ''));
    $category = trim((string) ($post['category'] ?? ''));
    $body = (string) ($post['body'] ?? '');
    $priceRaw = str_replace([' ', ','], ['', '.'], trim((string) ($post['price'] ?? '')));
    $negotiable = ($post['negotiable'] ?? '') === '1' ? 1 : 0;
    $location = trim((string) ($post['location'] ?? ''));
    $statusRaw = strtoupper(trim((string) ($post['status'] ?? '')));
    $status = in_array($statusRaw, CLASSIFIEDS_STATUS, true) ? $statusRaw : 'DRAFT';
    $seoTitle = trim((string) ($post['seo_title'] ?? ''));
    $seoDescription = trim((string) ($post['seo_description'] ?? ''));
    $noindex = ($post['noindex'] ?? '') === '1' ? 1 : 0;

    if ($title === '') {
        $errors['title'] = 'Заголовок пустой — напишите, о чём объявление.';
    } elseif (mb_strlen($title) > CLASSIFIEDS_TITLE_MAX) {
        $errors['title'] = 'Заголовок длиннее ' . CLASSIFIEDS_TITLE_MAX . ' символов — сократите.';
    }

    $slugCheck = classifiedsSlugPlan($typedSlug, $title, static fn (string $candidate): bool => classifiedsListingSlugTaken($candidate, $exceptId), 'listing');

    if ($slugCheck['error'] !== '') {
        $errors['slug'] = $slugCheck['error'];
    }

    if ($category === '') {
        $errors['category'] = 'Выберите категорию — без неё объявление никто не найдёт.';
    } else {
        $row = classifiedsCategoryById($category);

        if ($row === null || $row['visible'] === false) {
            $errors['category'] = 'Такой категории на доске нет — выберите из списка.';
        }
    }

    $bodyClean = trim($body);

    if ($bodyClean === '') {
        $errors['body'] = 'Описание пустое — покупателю нужно знать о товаре всё.';
    } elseif (mb_strlen($bodyClean) > CLASSIFIEDS_BODY_MAX) {
        $errors['body'] = 'Описание длиннее ' . CLASSIFIEDS_BODY_MAX . ' символов — сократите.';
    }

    $price = null;

    if ($priceRaw !== '') {
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $priceRaw) !== 1) {
            $errors['price'] = 'Цена — только число и копейки, пример: 1500 или 1500.50.';
        } else {
            $price = (float) $priceRaw;
        }
    } elseif ($negotiable === 0) {
        // Без цены и без пометки «договорная» объявление читается как «сколько угодно».
        $errors['price'] = 'Укажите цену или поставьте пометку «договорная».';
    }

    if (mb_strlen($location) > CLASSIFIEDS_LOCATION_MAX) {
        $errors['location'] = 'Местоположение длиннее ' . CLASSIFIEDS_LOCATION_MAX . ' символов — сократите.';
    }

    if (mb_strlen($seoTitle) > 120) {
        $errors['seo_title'] = 'SEO-заголовок длиннее 120 символов — сократите.';
    }

    if (mb_strlen($seoDescription) > 300) {
        $errors['seo_description'] = 'SEO-описание длиннее 300 символов — сократите.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'title' => $title,
            'slug' => $slugCheck['slug'],
            'category' => $category,
            'body' => $bodyClean,
            'price' => $price === null ? '' : (string) $price,
            'price_float' => $price,
            'negotiable' => $negotiable,
            'location' => $location,
            'status' => $status,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'noindex' => $noindex,
        ],
    ];
}

/**
 * Новое объявление: сделано только в кабинете, владелец — тот, кто открывает форму.
 *
 * @param array<string, mixed> $values результат classifiedsValidateListing()
 * @return array{ok: bool, error: string, id: string, notice: string}
 */
function classifiedsCreateListing(string $ownerId, array $values, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'id' => '', 'notice' => ''];
    }

    $id = newId();
    // Цена и валюта пишутся только вместе: без рублёвой цены поле остаётся NULL,
    // и карточка показывает «договорная».
    $price = $values['price_float'];
    insert('classifieds_listing', [
        'id' => $id,
        'owner_id' => $ownerId,
        'category_id' => (string) $values['category'],
        'slug' => $slug,
        'title' => (string) $values['title'],
        'body' => (string) $values['body'],
        'price' => $price,
        'currency' => (string) ($values['currency'] ?? CLASSIFIEDS_CURRENCY),
        'negotiable' => (int) $values['negotiable'],
        'location' => (string) $values['location'] !== '' ? (string) $values['location'] : null,
        'image' => isset($values['image']) && (string) $values['image'] !== '' ? (string) $values['image'] : null,
        'status' => (string) $values['status'],
        'seo_title' => (string) $values['seo_title'] !== '' ? (string) $values['seo_title'] : null,
        'seo_description' => (string) $values['seo_description'] !== '' ? (string) $values['seo_description'] : null,
        'noindex' => (int) $values['noindex'],
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('classifieds.listing_created', ['listingId' => $id, 'slug' => $slug, 'status' => (string) $values['status']]);

    return ['ok' => true, 'error' => '', 'id' => $id, 'notice' => 'listing_saved'];
}

/**
 * Правка объявления. Владельца, адрес, категорию и статус правит вызывающий код —
 * здесь чистятся текстовые поля и цена.
 *
 * @param array<string, mixed> $values результат classifiedsValidateListing()
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsUpdateListing(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $listing = classifiedsListingById($id);

    if ($listing === null) {
        return ['ok' => false, 'error' => 'Объявления уже нет — обновите список.', 'notice' => ''];
    }

    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'notice' => ''];
    }

    run(
        'UPDATE ' . t('classifieds_listing')
        . ' SET category_id = ?, slug = ?, title = ?, body = ?, price = ?, negotiable = ?, location = ?,' .
        ' image = ?, status = ?, seo_title = ?, seo_description = ?, noindex = ?, updated_at = ? WHERE id = ?',
        [
            (string) $values['category'],
            $slug,
            (string) $values['title'],
            (string) $values['body'],
            $values['price_float'],
            (int) $values['negotiable'],
            (string) $values['location'] !== '' ? (string) $values['location'] : null,
            isset($values['image']) && (string) $values['image'] !== '' ? (string) $values['image'] : null,
            (string) $values['status'],
            (string) $values['seo_title'] !== '' ? (string) $values['seo_title'] : null,
            (string) $values['seo_description'] !== '' ? (string) $values['seo_description'] : null,
            (int) $values['noindex'],
            utcStamp($now ?? nowUtc()),
            $id,
        ]
    );

    return ['ok' => true, 'error' => '', 'notice' => 'listing_saved'];
}

/**
 * Смена статуса объявления владельцем: публикация или снятие. Полная проверка
 * права владения — на вызывающем (кабинет), здесь только допустимые статусы.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsListingSetStatus(string $id, string $ownerId, string $status): array
{
    if (!in_array($status, CLASSIFIEDS_STATUS, true)) {
        return ['ok' => false, 'error' => 'Неизвестный статус объявления.', 'notice' => ''];
    }

    $listing = classifiedsListingById($id);

    if ($listing === null || (string) $listing['owner_id'] !== $ownerId) {
        return ['ok' => false, 'error' => 'Объявления уже нет — обновите список.', 'notice' => ''];
    }

    run('UPDATE ' . t('classifieds_listing') . ' SET status = ?, updated_at = ? WHERE id = ?', [$status, utcStamp(nowUtc()), $id]);
    logInfo('classifieds.listing_status', ['listingId' => $id, 'status' => $status]);

    return ['ok' => true, 'error' => '', 'notice' => $status === 'PUBLISHED' ? 'listing_published' : 'listing_hidden'];
}

/**
 * Удаление объявления владельцем. Черновики удаляются молча; публикация сначала снимается —
 * правило в кабинете проверяет состояние, здесь честно стирает строку.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsListingDelete(string $id, string $ownerId): array
{
    $listing = classifiedsListingById($id);

    if ($listing === null || (string) $listing['owner_id'] !== $ownerId) {
        return ['ok' => false, 'error' => 'Объявления уже нет — обновите список.', 'notice' => ''];
    }

    run('DELETE FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$id]);
    logInfo('classifieds.listing_deleted', ['listingId' => $id]);

    return ['ok' => true, 'error' => '', 'notice' => 'listing_deleted'];
}
