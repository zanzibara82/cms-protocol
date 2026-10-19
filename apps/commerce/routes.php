<?php

declare(strict_types=1);

// Правила раздела нужны контроллерам на каждом запросе, а в боевом процессе их не подключает
// никто: require_once здесь — единственная точка, где корзина и заказы встречаются с маршрутами.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/public.php';
require_once __DIR__ . '/admin.php';

// Порядок важен: /shop/c/{slug} и служебные экраны стоят до /shop/{slug}. Сами адреса «c»,
// «cart» и «order» заняты в COMMERCE_RESERVED_SLUGS, поэтому товар не может увести у
// рубрики её страницу и у витрины — экран корзины.
addRoute('GET', '/shop', 'commerceShopPage', ['app' => 'commerce']);
addRoute('GET', '/shop/c/{slug}', 'commerceCategoryPage', ['app' => 'commerce']);
addRoute('GET', '/shop/cart', 'commerceCartPage', ['app' => 'commerce']);
addRoute('POST', '/shop/cart/add', 'commerceCartAddAction', ['app' => 'commerce']);
addRoute('POST', '/shop/cart/update', 'commerceCartUpdateAction', ['app' => 'commerce']);
addRoute('POST', '/shop/order', 'commerceOrderAction', ['app' => 'commerce']);
addRoute('GET', '/shop/order/{number}', 'commerceOrderConfirmPage', ['app' => 'commerce']);
addRoute('GET', '/shop/{slug}', 'commerceProductPage', ['app' => 'commerce']);

// Экраны админки — литералами: страж CsrfCoverageTest::postHandlers() ищет путь
// POST-маршрута регуляркой, константа в этом месте спрятала бы обработчик от проверки.
addRoute('GET', '/commerce/admin', 'commerceAdminSummary', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/commerce/admin/products', 'commerceAdminProducts', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/commerce/admin/products/new', 'commerceProductNew', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/products/new', 'commerceProductSaveAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/commerce/admin/products/{id}', 'commerceProductEdit', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/products/{id}', 'commerceProductSaveAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/products/{id}/delete', 'commerceProductAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);

// Варианты товара (M2): отдельный экран карточки — пять форм товара и списки
// вариантов не помещались на одной странице.
addRoute('GET', '/commerce/admin/products/{id}/variants', 'commerceVariantsScreen', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/products/{id}/variants', 'commerceVariantAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/products/{id}/variants/{variantId}', 'commerceVariantAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/products/{id}/variants/{variantId}/delete', 'commerceVariantAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);

// Характеристики рубрики (M2): отдельный экран справочника под каждой рубрикой.
addRoute('GET', '/commerce/admin/categories/{id}', 'commerceCategoryAttributes', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/categories/{id}/attribute', 'commerceAttributeAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/categories/{id}/attribute/{attributeId}', 'commerceAttributeAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/categories/{id}/attribute/{attributeId}/delete', 'commerceAttributeAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);

addRoute('GET', '/commerce/admin/categories', 'commerceAdminCategories', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/categories', 'commerceCategoriesAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/commerce/admin/orders', 'commerceAdminOrders', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/commerce/admin/orders', 'commerceOrdersAction', ['app' => 'commerce', 'private' => true, 'permission' => 'login']);

// Карта сайтов: только опубликованные товары без noindex. Страница /shop и рубрики в неё
// не включаются по отдельному решению этим этапом:核对 видимости рубрик отложено.
addFilter('sitemap_urls', static function (array $urls): array {
    if (!isAppActive('commerce')) {
        return $urls;
    }

    return array_merge($urls, commerceSitemapUrls());
}, 10);
