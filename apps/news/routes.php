<?php

declare(strict_types=1);

// Правила раздела нужны контроллерам на каждом запросе, а в боевом процессе их не подключает никто:
// require_once здесь — единственная точка, где `findFeed()` и `parseQuery()` встречаются с маршрутами.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/actions/items.php';
require_once __DIR__ . '/actions/item.php';
require_once __DIR__ . '/actions/catalog.php';
require_once __DIR__ . '/actions/blocks.php';

// Порядок важен: /news/c/{slug} и /news/t/{slug} стоят до /news/{slug}. Сами адреса «c» и «t»
// заняты в NEWS_RESERVED_SLUGS, поэтому материал не может увести у рубрики её страницу.
addRoute('GET', '/news', 'newsFeedPage', ['app' => 'news']);
addRoute('GET', '/news/c/{slug}', 'newsCategoryPage', ['app' => 'news']);
addRoute('GET', '/news/t/{slug}', 'newsTagPage', ['app' => 'news']);
addRoute('GET', '/news/{slug}', 'newsSinglePage', ['app' => 'news']);

// Экраны админки раздела. Статические пути идут раньше параметрических по правилу маршрутизатора
// (core/router.php сортирует таблицу по признаку `static`), поэтому /news/admin не перехватывается
// адресом материала — даже если кто-то опубликует новость с адресом «admin».
addRoute('GET', '/news/admin', 'newsAdminSummary', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/news/admin/items', 'newsAdminItems', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/items', 'newsItemsAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);

// Форма материала. Пути пишутся строковыми литералами, а не константами: страж
// `CsrfCoverageTest::postHandlers()` ищет POST-обработчик регуляркой
// addRoute('POST', 'путь', 'имя') — путь в виде константы в этот шаблон не попадает, и обработчик
// исчез бы из-под проверки CSRF незаметно.
addRoute('GET', '/news/admin/items/new', 'newsItemNew', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/items/new', 'newsItemSaveAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/news/admin/items/{id}', 'newsItemEdit', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/items/{id}', 'newsItemSaveAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);

// Экраны справочников: GET открывает экран, POST выполняет действие. Пути писаны литералами —
// адрес рубрик и тегов читают вкладки lib/admin.php, а эти строки держатся на том же значении.
addRoute('GET', '/news/admin/categories', 'newsAdminCategories', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/categories', 'newsCategoriesAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/news/admin/tags', 'newsAdminTags', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/tags', 'newsTagsAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);

// Экран блоков вывода: GET печатает три таблицы зон, POST выполняет девять действий. Пути —
// строковые литералы по той же причине, что у справочников: регулярка CsrfCoverageTest читает
// путь маршрута, и константа в этом месте спрятала бы обработчик от проверки токена.
addRoute('GET', '/news/admin/blocks', 'newsAdminBlocks', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/blocks', 'newsBlocksAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);

// Карта сайтов: раздел добавляет свои адреса фильтром, а не правкой ядра (§5.4). Порядок
// фиксирован — ядро кладёт главную первым, раздел дописывает свои следом.
//
// Проверка установки обязательна: таблицы раздела есть не в каждой сборке, и запрос к
// несуществующей таблице — это ошибка, а не пустой список.
addFilter('sitemap_urls', static function (array $urls): array {
    if (!isAppActive('news')) {
        return $urls;
    }

    return array_merge($urls, newsSitemapUrls());
}, 10);

// Блоки вывода: раздел подписывает каждую свою зону одной функцией, имя зоны приходит
// аргументом — на второе имя не нужно второе замыкание (решение 2 спецификации).
// Подписка стоит ниже фильтра карты сайтов: для стража `NewsVisibilityTest` строка того
// замыкания — единственная наводка на место в файле, и сдвигать её здесь нельзя (вердикт Р3).
// Имя переменной с префиксом области: файл исполняется внутри `registerAppRoutes()`
// (`core/apps.php:266`), и короткое `$zone` жило бы в её локальных переменных.
foreach (NEWS_BLOCK_ZONES as $regionZone) {
    addFilter('region_' . $regionZone, 'newsRegionHtml', 10);
}

// Вклад «Новости — лента» в конструкторе страниц (§5 дизайна слоя страниц): перечень видов для
// селекта и рендер секции. Файлы разделов исполняются для всей сборки, включая неустановленные
// (core/apps.php), поэтому сверку isAppActive ведут сами функции; замыканий здесь нет — страж
// NewsVisibilityTest допускает в файле ровно одно, хук карты сайтов.
addFilter('pages_block_kinds', 'newsPagesBlockKindsFilter', 10);
addFilter('pages_block_' . NEWS_PAGES_BLOCK_KIND, 'newsPagesFeedHtml', 10);

/** @param array<string, string> $params */
function newsFeedPage(array $params = []): void
{
    $requested = trim((string) queryString('cat', ''));
    $query = parseQuery((string) queryString('q', ''));
    $page = pageOf((string) queryString('page', '1'));

    $heading = (string) NEWS_SITE['title'];
    $intro = (string) NEWS_SITE['description'];

    if ($requested !== '') {
        // ?cat= достался от прежних ссылок: лента открывается, а каноник ведёт на адрес рубрики,
        // иначе один и тот же список лежит в индексе по двум адресам.
        $row = findCategory($requested);
        if ($row === null) {
            notFound();
        }

        $heading = newsTitle((string) $row['title']);
        $intro = categoryIntro($row);
    } elseif ($query !== '') {
        $heading = newsTitle('Поиск: ' . $query);
        $intro = sprintf('Материалы раздела по запросу «%s».', $query);
    }

    $feed = findFeed(['category' => $requested, 'q' => $query], $page);
    $path = $requested !== '' ? newsHref(['category' => $requested]) : newsHref();

    listHead($heading, $intro, $path);

    render('news/feed', [
        'feed' => $feed,
        'heading' => $heading,
        'intro' => $intro,
        'categories' => visibleCategories(),
        'current' => $requested,
        'empty' => $query !== '' ? 'По такому запросу материалов нет.' : 'В разделе пока нет материалов.',
    ], [
        'title' => $heading,
        'description' => $intro,
        'noindex' => $query !== '' || $feed['current'] > 1,
    ]);
}

/** @param array<string, string> $params */
function newsCategoryPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $row = findCategory($slug);

    // Скрытая рубрика отвечает как несуществующая: иначе адрес, который редактор снял с публикации,
    // продолжает открываться по прямой ссылке (§8.2).
    if ($row === null) {
        notFound();
    }

    $page = pageOf((string) queryString('page', '1'));
    $feed = findFeed(['category' => $slug], $page);
    $heading = newsTitle((string) $row['title']);
    $intro = categoryIntro($row);

    listHead($heading, $intro, newsHref(['category' => $slug]));

    render('news/category', [
        'feed' => $feed,
        'heading' => (string) $row['title'],
        'intro' => $intro,
        'categories' => visibleCategories(),
        'current' => $slug,
        'empty' => 'В этой рубрике пока нет материалов.',
    ], [
        'title' => $heading,
        'description' => $intro,
        'noindex' => $feed['current'] > 1,
    ]);
}

/** @param array<string, string> $params */
function newsTagPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $tag = findTag($slug);

    if ($tag === null) {
        notFound();
    }

    $page = pageOf((string) queryString('page', '1'));
    $feed = findFeed(['tag' => $slug], $page);
    $heading = sprintf('Материалы по тегу «%s»', (string) $tag['label']);
    $title = newsTitle($heading);
    $intro = sprintf('Подборка материалов раздела по тегу «%s».', (string) $tag['label']);

    listHead($title, $intro, newsHref(['tag' => $slug]));

    // Подборка по тегу не индексируется целиком: это бесконечный набор дублей ленты (§8.5).
    render('news/tag', [
        'feed' => $feed,
        'heading' => $heading,
        'intro' => $intro,
        'categories' => visibleCategories(),
        'current' => '',
        'empty' => 'По этому тегу материалов пока нет.',
    ], [
        'title' => $title,
        'description' => $intro,
        'noindex' => true,
    ]);
}

/** @param array<string, string> $params */
function newsSinglePage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $now = nowUtc();

    // Предпросмотр открывает только редактор и только по явной ссылке ?draft=1: без этого адрес
    // будущей публикации становится известным по ответу сервера (§8.2).
    $access = getEditorAccess();
    $editor = $access['kind'] === 'granted';
    $preview = $editor && queryString('draft') === '1';

    $item = $preview ? findAny($slug) : findPublished($slug, $now);

    if ($item === null) {
        $alias = findByOldSlug($slug);

        // Прежний адрес открытого материала переносит на новый навсегда; закрытого — не переносит
        // никому, кроме редактора. Постоянный редирект со снятого материала выдал бы наружу три
        // вещи сразу: что материал существует, что он снят, и его новый адрес — а §8.2 обещает
        // анониму ответ как у несуществующего адреса.
        if ($alias === null || ($alias['open'] !== true && !$editor)) {
            notFound();
        }

        // Редактор, державший ссылку на черновик, не должен увидеть 404 после смены адреса:
        // у закрытого материала прежний адрес сохраняет ?draft=1.
        $target = newsHref(['slug' => (string) $alias['slug']]);

        redirect($alias['open'] === true ? $target : $target . '?draft=1', 301);
    }

    $path = newsHref(['slug' => (string) $item['slug']]);
    $open = $item['open'] === true;
    $seo = seoOf($item, (string) NEWS_SITE['description']);
    $updated = momentOf($item['updatedAt']);

    articleHead($item, $path, $open);

    render('news/single', [
        'item' => $item,
        'open' => $open,
        'preview' => $preview && !$open,
        'scheduled' => $open === false && $item['status'] === NEWS_STATUS['PUBLISHED'],
        'showUpdated' => $updated !== null && formatRuDate($updated) !== formatRuDate(momentOf($item['publishedAt'])),
    ], [
        'title' => $seo['title'],
        'description' => $seo['description'],
        'noindex' => !$open || $item['noindex'] === true,
    ]);
}

/**
 * Короткое описание рубрики: своё, если редактор его заполнил, и типовое, если нет.
 *
 * @param array{id: string, slug: string, title: string, description: ?string} $row
 */
function categoryIntro(array $row): string
{
    $text = trim((string) $row['description']);

    return $text !== '' ? $text : sprintf('%s: материалы рубрики «%s».', (string) NEWS_SITE['name'], (string) $row['title']);
}
