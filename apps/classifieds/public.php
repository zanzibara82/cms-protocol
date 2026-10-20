<?php

declare(strict_types=1);

// Публичное чтение доски: главная, страница категории и страница объявления.
// Карточки и списки отдают шаблонам поля договора из lib/listings.php; суммы и даты
// форматирует раздел, а не шаблон.

/** Адрес категории на доске. */
function classifiedsCategoryHref(string $slug): string
{
    return '/classifieds/c/' . rawurlencode($slug);
}

/** Адрес объявления на доске. */
function classifiedsListingHref(string $slug): string
{
    return '/classifieds/' . rawurlencode($slug);
}

/**
 * Цена для карточки: без цены — «Договорная», с пометкой торга — хвост «· торг».
 */
function classifiedsPriceLabel(?float $price, bool $negotiable): string
{
    if ($price === null) {
        return 'Договорная';
    }

    $formatted = number_format($price, 2, ',', ' ');

    // Копейки показываются только когда они есть: «1 500,00 ₽» на доске читается как ошибка.
    if (str_ends_with($formatted, ',00')) {
        $formatted = substr($formatted, 0, -3);
    }

    return $formatted . ' ' . CLASSIFIEDS_CURRENCY_LABEL . ($negotiable ? ' · торг' : '');
}

/**
 * Дата объявления по-русски: день, месяц, год.
 *
 * @return string пустая строка, если отметка в базе не разобралась
 */
function classifiedsDateLabel(?string $stamp): string
{
    return formatRuDate(fromDb($stamp));
}

/** @param array<string, string> $params */
function classifiedsCatalogPage(array $params = []): void
{
    $categories = classifiedsCategoryVisible();

    render('classifieds/catalog', [
        'categories' => $categories,
        'listings' => classifiedsHomeFeed(),
        'notice' => (string) (noticeOfRequest(CLASSIFIEDS_NOTICE_TEXT) ?? ''),
    ], [
        'title' => 'Доска объявлений',
        'description' => 'Частные объявления города: купить, продать, сдать и найти.',
    ]);
}

/** @param array<string, string> $params */
function classifiedsCategoryPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $category = classifiedsCategoryBySlug($slug);

    // Скрытая категория отвечает как несуществующая: адрес, снятый с публикации, не должен
    // открываться по прямой ссылке.
    if ($category === null || $category['visible'] === false) {
        notFound();
    }

    $page = pageOf((string) queryString('page', '1'));
    $feed = classifiedsCategoryFeed($category, $page);

    render('classifieds/category', [
        'category' => $category,
        'categories' => classifiedsCategoryVisible(),
        'list' => $feed,
        'total' => $feed['total'],
        'notice' => (string) (noticeOfRequest(CLASSIFIEDS_NOTICE_TEXT) ?? ''),
    ], [
        'title' => (string) $category['title'] . ' — доска объявлений',
        'description' => 'Объявления в категории «' . (string) $category['title'] . '».',
        'noindex' => $feed['current'] > 1,
    ]);
}

/** @param array<string, string> $params */
function classifiedsListingPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $row = classifiedsPublishedListingWithCategory($slug);
    $preview = false;

    if ($row === null) {
        // Владелец видит свой черновик по прямой ссылке — иначе опубликованное объявление
        // нельзя проверить до публикации. Чужаку тот же адрес отвечает 404.
        $draft = classifiedsListingAnyStatus($slug);
        $user = currentUser();

        if ($draft === null || $user === null || (string) $draft['owner_id'] !== (string) $user['id']) {
            notFound();
        }

        $row = classifiedsListingShape($draft);
        $categoryOfDraft = classifiedsCategoryById((string) $row['category_id']);
        $row['category_slug'] = (string) ($categoryOfDraft['slug'] ?? '');
        $row['category_title'] = (string) ($categoryOfDraft['title'] ?? '');
        $preview = true;
    }

    $listing = classifiedsListingShape($row);
    $categorySlug = (string) ($row['category_slug'] ?? '');
    $categoryTitle = (string) ($row['category_title'] ?? '');

    render('classifieds/listing', [
        'listing' => $listing,
        'priceLabel' => classifiedsPriceLabel($listing['price'], $listing['negotiable']),
        'dateLabel' => classifiedsDateLabel($listing['created_at']),
        'categoryHref' => $categorySlug !== '' ? classifiedsCategoryHref($categorySlug) : '',
        'categoryTitle' => $categoryTitle,
        'preview' => $preview,
        'categories' => classifiedsCategoryVisible(),
    ], [
        'title' => (string) ($listing['seo_title'] ?? $listing['title']),
        'description' => (string) ($listing['seo_description'] ?? excerpt($listing['body'], 160)),
        'noindex' => $preview || $listing['noindex'] || $listing['status'] !== 'PUBLISHED',
    ]);
}

/**
 * Адреса карты сайта: открытые категории и опубликованные объявления без noindex.
 *
 * @return list<array{loc: string, lastmod?: string}>
 */
function classifiedsSitemapUrls(): array
{
    $out = [['loc' => '/classifieds']];

    foreach (classifiedsCategoryVisible() as $category) {
        $out[] = ['loc' => classifiedsCategoryHref((string) $category['slug'])];
    }

    $rows = all(
        'SELECT slug, updated_at FROM ' . t('classifieds_listing')
        . " WHERE status = 'PUBLISHED' AND noindex = 0"
        . ' ORDER BY updated_at DESC LIMIT 5000'
    );

    foreach ($rows as $row) {
        /** @var array{loc: string, lastmod?: string} $entry */
        $entry = ['loc' => classifiedsListingHref((string) $row['slug'])];
        $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

        if ($updated !== null) {
            $entry['lastmod'] = substr(moscowIso($updated), 0, 10);
        }

        $out[] = $entry;
    }

    return $out;
}
