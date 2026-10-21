<?php

declare(strict_types=1);

// Аналитика и SEO доски (C6): счётчик просмотров объявления, разметка Schema.org для
// страницы объявления и данные карты сайта. Модуль лежит рядом с объявлениями, потому
// что считает и размечает именно их: чужие разделы счётчиками объявлений не занимаются.

/**
 * Засчитать просмотр объявления. Считается только опубликованное объявление: черновик и
 * снятое с публикации страницу публично не показывает, а предпросмотр владельца — не трафик.
 * Владельцу и редактору просмотр не засчитывается: иначе счётчик врал бы с первого дня.
 *
 * @param array<string, mixed> $listing строка объявления (shape)
 * @param array<string, mixed>|null $viewer кто смотрит (строка tbl_user), null — гость
 * @return int|null новое число просмотров, null — просмотр не засчитан
 */
function classifiedsListingCountView(array $listing, ?array $viewer = null): ?int
{
    if ((string) $listing['status'] !== 'PUBLISHED') {
        return null;
    }

    if ($viewer !== null) {
        if ((string) $listing['owner_id'] === (string) ($viewer['id'] ?? '')) {
            return null;
        }

        if (roleGrantsManage($viewer)) {
            return null;
        }
    }

    $count = (int) $listing['views_count'] + 1;

    run(
        'UPDATE ' . t('classifieds_listing') . ' SET views_count = views_count + 1, last_viewed_at = ? WHERE id = ?',
        [utcStamp(nowUtc()), (string) $listing['id']]
    );

    return $count;
}

/**
 * Число просмотров словами. Ноль не показываем: «0 просмотров» на странице объявления
 * выглядит как ошибка, а не как факт.
 */
function classifiedsViewsLabel(int $count): string
{
    if ($count <= 0) {
        return '';
    }

    return $count . ' ' . plural($count, ['просмотр', 'просмотра', 'просмотров']);
}

/**
 * Показывать ли счётчик публично. Решает владелец объявления: галочка в форме правки.
 * Владельцу число видно всегда — свой счётчик знать полезно, даже если показывать его
 * гостям нельзя.
 *
 * @param array<string, mixed> $listing
 * @param array<string, mixed>|null $viewer
 */
function classifiedsViewsPublic(array $listing, int $count, ?array $viewer = null): bool
{
    if ($count <= 0) {
        return false;
    }

    if ($viewer !== null && (string) $listing['owner_id'] === (string) ($viewer['id'] ?? '')) {
        return true;
    }

    return (int) ($listing['views_public'] ?? 0) === 1;
}

/**
 * Разметка объявления для поисковиков: товар с предложением и хлебные крошки.
 * Цена в разметку идёт числом и в рублях — так её читают агрегаторы товаров.
 *
 * @param array<string, mixed> $listing
 * @param array{categoryTitle?: string, categoryHref?: string, sellerName?: string} $context
 * @return list<array<string, mixed>>
 */
function classifiedsListingJsonLd(array $listing, array $context = []): array
{
    $href = absoluteUrl(classifiedsListingHref((string) $listing['slug']));
    $categoryTitle = (string) ($context['categoryTitle'] ?? '');
    $categoryHref = (string) ($context['categoryHref'] ?? '');
    $product = [
        '@type' => 'Product',
        'name' => (string) $listing['title'],
        'description' => excerpt((string) $listing['body'], 300),
        'url' => $href,
        'sku' => (string) $listing['id'],
    ];

    if ($categoryTitle !== '') {
        $product['category'] = $categoryTitle;
    }

    $image = (string) ($listing['image'] ?? '');

    if ($image !== '') {
        $product['image'] = absoluteUrl($image);
    }

    // Предложение без цены не выкладываем: цена «договорная» в разметке вводит
    // агрегаторы в заблуждение сильнее, чем отсутствие предложения.
    $price = $listing['price'] === null ? null : (float) $listing['price'];

    if ($price !== null) {
        $offer = [
            '@type' => 'Offer',
            'price' => $price,
            'priceCurrency' => (string) ($listing['currency'] ?? CLASSIFIEDS_CURRENCY),
            'availability' => 'https://schema.org/InStock',
            'itemCondition' => 'https://schema.org/UsedCondition',
            'url' => $href,
        ];
        $sellerName = (string) ($context['sellerName'] ?? '');

        if ($sellerName !== '') {
            $offer['seller'] = ['@type' => 'Person', 'name' => $sellerName];
        }

        $product['offers'] = $offer;
    }

    $crumbs = [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Доска объявлений',
            'item' => absoluteUrl('/classifieds'),
        ],
    ];

    if ($categoryTitle !== '' && $categoryHref !== '') {
        $crumbs[] = [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $categoryTitle,
            'item' => absoluteUrl($categoryHref),
        ];
    }

    $crumbs[] = [
        '@type' => 'ListItem',
        'position' => count($crumbs) + 1,
        'name' => (string) $listing['title'],
        'item' => $href,
    ];

    return [
        $product,
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $crumbs,
        ],
    ];
}

/**
 * Разметка главной страницы доски: сайт, организация и список свежих объявлений.
 *
 * @param list<array<string, mixed>> $listings
 * @return list<array<string, mixed>>
 */
function classifiedsBoardJsonLd(array $listings = []): array
{
    $siteName = (string) configValue('SITE_NAME', 'Сайт');
    $items = [
        [
            '@type' => 'WebSite',
            'name' => $siteName,
            'url' => absoluteUrl('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => absoluteUrl('/classifieds/search?q={search_term_string}'),
                'query-input' => 'required name=search_term_string',
            ],
        ],
        [
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => absoluteUrl('/'),
        ],
    ];

    if ($listings !== []) {
        $elements = [];

        foreach (array_values($listings) as $index => $listing) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => absoluteUrl(classifiedsListingHref((string) $listing['slug'])),
                'name' => (string) $listing['title'],
            ];
        }

        $items[] = [
            '@type' => 'ItemList',
            'name' => 'Свежие объявления',
            'itemListElement' => $elements,
        ];
    }

    return $items;
}
