<?php

declare(strict_types=1);

// Заголовки и превью раздела: одно место, где решается, что увидит читатель в вкладке,
// а поисковик — в сниппете и в карточке ссылки. Страницы эти строки не собирают (§8.6).
const NEWS_SITE = [
    'name' => 'Новости школы',
    'tagline' => 'что происходит в школе',
    'title' => 'Новости школы',
    'description' => 'Открытые записи, события школы, результаты учеников и изменения в программах.',
];
const NEWS_OG_SITE_NAME = 'Новости школы — что происходит в школе';
const NEWS_TITLE_TEMPLATE = '%s · Новости школы';

// Фирменная картинка превью лежит в репозитории: у материала без обложки пустое превью
// показывать нельзя, а рисовать её на лету значило бы требовать от хостинга GD.
const NEWS_OG_IMAGE = '/assets/og-default.png';
const NEWS_OG_WIDTH = 1200;
const NEWS_OG_HEIGHT = 630;
const NEWS_SEO_DESCRIPTION_LIMIT = 200;

function newsTitle(string $title): string
{
    $clean = trim($title);

    return $clean === '' ? (string) NEWS_SITE['title'] : sprintf(NEWS_TITLE_TEMPLATE, $clean);
}

/**
 * @param array<string, mixed> $item
 *
 * @return array{title: string, description: string}
 */
function seoOf(array $item, string $fallback): array
{
    $title = trim((string) ($item['seoTitle'] ?? ''));
    if ($title === '') {
        $title = trim((string) ($item['title'] ?? ''));
    }

    $description = trim((string) ($item['seoDescription'] ?? ''));
    if ($description === '') {
        $description = trim((string) ($item['lead'] ?? ''));
    }
    if ($description === '') {
        $description = trim($fallback);
    }

    return [
        'title' => $title === '' ? (string) NEWS_SITE['title'] : $title,
        'description' => excerpt($description, NEWS_SEO_DESCRIPTION_LIMIT),
    ];
}

/**
 * @param array<string, mixed> $item
 */
function ogImageOf(array $item): string
{
    foreach (['ogImage', 'cover'] as $key) {
        $value = trim((string) ($item[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return NEWS_OG_IMAGE;
}

/**
 * @param array<string, mixed> $item
 *
 * @return list<string>
 */
function itemTagLabels(array $item): array
{
    $labels = [];

    foreach ((array) ($item['tags'] ?? []) as $tag) {
        $label = trim((string) (is_array($tag) ? ($tag['label'] ?? '') : ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return $labels;
}

// Значения превью приходят из материала, а материал пишет человек: экранируем каждый атрибут,
// иначе кавычка в заголовке закрывает тег и ломает разметку страницы.
function metaTag(string $name, string $content, bool $property = false): string
{
    return sprintf('<meta %s="%s" content="%s">', $property ? 'property' : 'name', e($name), e($content));
}

function canonicalTag(string $path): string
{
    return sprintf('<link rel="canonical" href="%s">', e(absoluteUrl($path)));
}

function listHeadTags(string $title, string $description, string $path): string
{
    $url = absoluteUrl($path);
    $image = absoluteUrl(NEWS_OG_IMAGE);

    return implode("\n", [
        canonicalTag($path),
        metaTag('og:site_name', NEWS_OG_SITE_NAME, true),
        metaTag('og:type', 'website', true),
        metaTag('og:title', $title, true),
        metaTag('og:description', $description, true),
        metaTag('og:url', $url, true),
        metaTag('og:image', $image, true),
        metaTag('og:image:width', (string) NEWS_OG_WIDTH, true),
        metaTag('og:image:height', (string) NEWS_OG_HEIGHT, true),
        metaTag('og:locale', 'ru_RU', true),
        metaTag('twitter:card', 'summary_large_image'),
        metaTag('twitter:title', $title),
        metaTag('twitter:description', $description),
        metaTag('twitter:image', $image),
    ]);
}

/**
 * @param array<string, mixed> $item
 */
function articleHeadTags(array $item, string $path, bool $open): string
{
    $seo = seoOf($item, (string) NEWS_SITE['description']);
    $image = absoluteUrl(ogImageOf($item));

    $tags = [
        canonicalTag($path),
        metaTag('og:site_name', NEWS_OG_SITE_NAME, true),
        metaTag('og:type', 'article', true),
        metaTag('og:title', $seo['title'], true),
        metaTag('og:description', $seo['description'], true),
        metaTag('og:url', absoluteUrl($path), true),
        metaTag('og:image', $image, true),
        metaTag('og:image:width', (string) NEWS_OG_WIDTH, true),
        metaTag('og:image:height', (string) NEWS_OG_HEIGHT, true),
        metaTag('og:locale', 'ru_RU', true),
        metaTag('twitter:card', 'summary_large_image'),
        metaTag('twitter:title', $seo['title']),
        metaTag('twitter:description', $seo['description']),
        metaTag('twitter:image', $image),
    ];

    $published = publishedTimeOf($item, $open);
    if ($published !== null) {
        $tags[] = metaTag('article:published_time', $published, true);
    }

    $modified = modifiedTimeOf($item);
    if ($modified !== null) {
        $tags[] = metaTag('article:modified_time', $modified, true);
    }

    $section = trim((string) ($item['categoryTitle'] ?? ''));
    if ($section !== '') {
        $tags[] = metaTag('article:section', $section, true);
    }

    foreach (itemTagLabels($item) as $label) {
        $tags[] = metaTag('article:tag', $label, true);
    }

    return implode("\n", $tags);
}

/**
 * @param array<string, mixed> $item
 *
 * @return array<string, mixed>
 */
function newsArticleLd(array $item, string $path, string $title, string $description, string $image): array
{
    // Автор — школа, а не почта редактора: адрес автора в открытой разметке собирать негде,
    // а публиковать его в выдаче нельзя (§7.4).
    $organization = [
        '@type' => 'Organization',
        '@id' => absoluteUrl('/') . '#organization',
        'name' => (string) NEWS_SITE['name'],
        'url' => absoluteUrl('/'),
    ];

    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        '@id' => absoluteUrl($path),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => absoluteUrl($path)],
        'headline' => $title,
        'description' => $description,
        'image' => [$image],
        'inLanguage' => 'ru-RU',
        'isAccessibleForFree' => true,
        'author' => $organization,
        'publisher' => $organization,
    ];

    $published = publishedTimeOf($item, (bool) ($item['open'] ?? false));
    if ($published !== null) {
        $ld['datePublished'] = $published;
    }

    $modified = modifiedTimeOf($item);
    if ($modified !== null) {
        $ld['dateModified'] = $modified;
    }

    $section = trim((string) ($item['categoryTitle'] ?? ''));
    if ($section !== '') {
        $ld['articleSection'] = $section;
    }

    $labels = itemTagLabels($item);
    if ($labels !== []) {
        $ld['keywords'] = implode(', ', $labels);
    }

    return $ld;
}

/**
 * @param array<string, mixed> $ld
 */
function jsonLdTag(array $ld): string
{
    try {
        // HEX_TAG обязателен: текст материала попадает внутрь <script>, и строка «</script>»
        // в заголовке закрыла бы блок посреди страницы.
        $json = json_encode(
            $ld,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        logWarn('news_ld_broken', ['reason' => $error->getMessage()]);

        return '';
    }

    return '<script type="application/ld+json">' . $json . '</script>';
}

// Хук page_head — единственный источник $head в render(): ядро знает про title, description
// и robots, а про каноник и превью раздела не знает ничего (§5.6). Разметку собирает функция,
// а печать идёт через переменную $head с пометкой: страж экранирования читает и этот файл.
function listHead(string $title, string $description, string $path): void
{
    addAction('page_head', static function () use ($title, $description, $path): void {
        $head = listHeadTags($title, $description, $path);

        echo $head; /* @safe html */
    });
}

/**
 * @param array<string, mixed> $item
 */
function articleHead(array $item, string $path, bool $open): void
{
    addAction('page_head', static function () use ($item, $path, $open): void {
        $head = articleHeadTags($item, $path, $open);

        if ($open) {
            $seo = seoOf($item, (string) NEWS_SITE['description']);

            $head .= "\n" . jsonLdTag(newsArticleLd(
                $item,
                $path,
                $seo['title'],
                $seo['description'],
                absoluteUrl(ogImageOf($item))
            ));
        }

        echo $head; /* @safe html */
    });
}

// Ниже — разбор моментов материала: его читают и метаданные, и страница материала.

function momentOf(mixed $value): ?DateTimeImmutable
{
    return $value instanceof DateTimeImmutable ? $value : null;
}

/**
 * У черновика и у материала с ненаступившим сроком публиковать нечего, поэтому превью
 * показывает время последней правки: пустой срок превратился бы в 1970 год.
 *
 * @param array<string, mixed> $item
 */
function publishedTimeOf(array $item, bool $open): ?string
{
    $published = momentOf($item['publishedAt'] ?? null);
    $updated = momentOf($item['updatedAt'] ?? null);
    $moment = $open ? ($published ?? $updated) : ($updated ?? $published);

    return $moment === null ? null : moscowIso($moment);
}

/**
 * @param array<string, mixed> $item
 */
function modifiedTimeOf(array $item): ?string
{
    $moment = momentOf($item['updatedAt'] ?? null) ?? momentOf($item['publishedAt'] ?? null);

    return $moment === null ? null : moscowIso($moment);
}

/**
 * Адреса карты сайта этого раздела (§8.6): лента, открытые материалы и страницы видимых рубрик.
 *
 * У ленты и у списков даты нет: они меняются на каждом материале, и `<lastmod>` у них показывал
 * бы дату правки последней строки вместо даты правки списка. Ядро при этом всё равно отбракует
 * дубликат и приватный путь (core/seo.php), поэтому раздел не повторяет эту проверку.
 *
 * Страниц тегов в карте нет, и это уточнение к §8.6: задача 17 закрыла их от индекса
 * (`noindex, nofollow` — подборки повторяют ленту и не самостоятельный контент), а обещать
 * адрес поиску и тут же говорить «не индексируй» — значит давать противоречивый сигнал. Рубрика
 * же индексируется (задача 17 снимает `noindex` с первой страницы), поэтому её адрес в карте
 * законен.
 *
 * @return list<array{loc: string, lastmod?: string}>
 */
function newsSitemapUrls(): array
{
    $urls = [['loc' => newsHref()]];

    foreach (publishedForSitemap() as $entry) {
        $urls[] = $entry;
    }

    foreach (visibleCategories() as $category) {
        $urls[] = ['loc' => newsHref(['category' => (string) $category['slug']])];
    }

    return $urls;
}
