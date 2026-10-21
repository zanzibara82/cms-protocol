<?php

declare(strict_types=1);

// Правила раздела нужны контроллерам на каждом запросе, а в боевом процессе их не подключает
// никто: require_once здесь — единственная точка, где база доски встречается с маршрутами.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/public.php';
require_once __DIR__ . '/cabinet.php';
require_once __DIR__ . '/admin.php';

// Порядок важен: /classifieds/c/{slug} (категория) и служебные экраны стоят до
// /classifieds/{slug} (объявление). Сами адреса «c», «admin», «cabinet» и «register» заняты
// в CLASSIFIEDS_RESERVED_SLUGS, поэтому объявление не может увести у категории её страницу.
addRoute('GET', '/classifieds', 'classifiedsCatalogPage', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/c/{slug}', 'classifiedsCategoryPage', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/search', 'classifiedsSearchPage', ['app' => 'classifieds']);

// JSON-выдача доски (C6): список объявлений и карточка объявления. Адреса стоят до
// /classifieds/{slug}, иначе объявление съело бы вложенные адреса.
addRoute('GET', '/classifieds/api/listings', 'classifiedsApiListingsPage', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/api/listings/{slug}', 'classifiedsApiListingPage', ['app' => 'classifieds']);

// Кабинет продавца. Гейта маршрута нет намеренно: ядро пускает в приватные пути только
// роли admin/editor, а продавец — своя роль seller. Поэтому вход проверяет обработчик:
// аноним получает редирект на штатный /login с глубинной ссылкой.
addRoute('GET', '/classifieds/register', 'classifiedsRegisterPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/register', 'classifiedsRegisterAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet', 'classifiedsCabinetPage', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/profile', 'classifiedsProfilePage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/cabinet/profile', 'classifiedsProfileSaveAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/favorites', 'classifiedsFavoritesPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/cabinet/favorites', 'classifiedsFavoriteRemoveAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/messages', 'classifiedsMessagesPage', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/messages/{id}', 'classifiedsMessageThreadPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/cabinet/messages/{id}', 'classifiedsMessageReplyAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/notifications', 'classifiedsNotificationsPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/cabinet/notifications', 'classifiedsNotificationsReadAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/promotions', 'classifiedsPromotionsPage', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/new', 'classifiedsListingNewPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/cabinet/new', 'classifiedsListingSaveAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/cabinet/{id}', 'classifiedsListingEditPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/cabinet/{id}', 'classifiedsListingSaveAction', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/cabinet/{id}/action', 'classifiedsListingAction', ['app' => 'classifieds']);

addRoute('GET', '/classifieds/seller/{id}', 'classifiedsSellerPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/{slug}/favorite', 'classifiedsFavoriteAction', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/{slug}/message', 'classifiedsMessageSendAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/{slug}/report', 'classifiedsReportPage', ['app' => 'classifieds']);
addRoute('POST', '/classifieds/{slug}/report', 'classifiedsReportAction', ['app' => 'classifieds']);
addRoute('GET', '/classifieds/{slug}', 'classifiedsListingPage', ['app' => 'classifieds']);

// Экраны админки — литералами: страж CsrfCoverageTest::postHandlers() ищет путь
// POST-маршрута регуляркой, константа в этом месте спрятала бы обработчик от проверки.
addRoute('GET', '/classifieds/admin', 'classifiedsAdminSummary', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/classifieds/admin/categories', 'classifiedsAdminCategories', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/classifieds/admin/categories', 'classifiedsCategoryAction', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/classifieds/admin/attributes', 'classifiedsAdminAttributes', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/classifieds/admin/attributes', 'classifiedsAttributeAction', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/classifieds/admin/moderation', 'classifiedsAdminModeration', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/classifieds/admin/moderation', 'classifiedsModerationAction', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/classifieds/admin/reports', 'classifiedsAdminReports', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/classifieds/admin/reports', 'classifiedsAdminReportAction', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/classifieds/admin/promotions', 'classifiedsAdminPromotionsPage', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);

// Вебхуки доски (C6): список приёмников и правка выбранного по идентификатору.
addRoute('GET', '/classifieds/admin/webhooks', 'classifiedsAdminWebhooks', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/classifieds/admin/webhooks/{id}', 'classifiedsAdminWebhooks', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/classifieds/admin/webhooks', 'classifiedsWebhookAction', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/classifieds/admin/webhooks/{id}', 'classifiedsWebhookAction', ['app' => 'classifieds', 'private' => true, 'permission' => 'login']);

// Карта сайтов: только открытые категории и опубликованные объявления (C1 — базовый набор,
// счётчики и приоритеты приедут вместе с SEO-этапом C6).
addFilter('sitemap_urls', static function (array $urls): array {
    if (!isAppActive('classifieds')) {
        return $urls;
    }

    return array_merge($urls, classifiedsSitemapUrls());
}, 10);
