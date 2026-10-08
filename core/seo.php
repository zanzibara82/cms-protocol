<?php

declare(strict_types=1);

// Адрес сайта берётся из настроек, а не из заголовка Host: Host подставляется любой,
// а абсолютные ссылки OG и карты сайта обязаны вести на настоящий домен.
function absoluteUrl(string $path): string
{
    $base = rtrim((string) configValue('SITE_URL', ''), '/');
    $clean = '/' . ltrim($path, '/');

    return $base . $clean;
}

/** @return list<array{loc: string}> */
function coreSitemapUrls(): array
{
    return [['loc' => '/']];
}

// Служебные пути ядра не индексируются: robots.txt и noindex в шаблоне обязаны совпадать,
// иначе поисковик увидит страницу входа в списке адресов сайта (критерий §12.5).
const ROBOTS_CORE_DISALLOW = [
    '/admin',
    '/login',
    '/logout',
    '/setup.php',
    '/install.lock',
    '/config.php',
    '/core/',
    '/apps/',
    '/sql/',
    '/tests/',
    '/scripts/',
    '/vendor/',
    '/composer.json',
    '/composer.lock',
    '/docs/',
];

/**
 * Адреса карты сайта. Фильтр sitemap_urls (спецификация §5.4) позволяет разделу добавить свои,
 * не правя ядро; ядро при этом отбраковывает то, что раздел добавить не вправе.
 *
 * @return list<array{loc: string, lastmod?: string}>
 */
function sitemapUrls(): array
{
    $filtered = applyFilters('sitemap_urls', coreSitemapUrls());
    if (!is_array($filtered)) {
        return coreSitemapUrls();
    }

    $clean = [];
    $seen = [];

    foreach ($filtered as $url) {
        if (!is_array($url)) {
            continue;
        }

        $loc = (string) ($url['loc'] ?? '');

        // В карту попадают только относительные пути нашего сайта: раздел мог бы вернуть чужой
        // домен, а «//host» браузер раскрыл бы как внешний адрес.
        if ($loc === '' || !str_starts_with($loc, '/') || str_starts_with($loc, '//') || in_array($loc, $seen, true)) {
            continue;
        }

        if (isRobotsDisallowed($loc)) {
            continue;
        }

        $seen[] = $loc;
        $entry = ['loc' => $loc];

        $lastmod = $url['lastmod'] ?? null;
        if (is_string($lastmod) && $lastmod !== '') {
            $entry['lastmod'] = $lastmod;
        }

        $clean[] = $entry;
    }

    return $clean;
}

function isRobotsDisallowed(string $path): bool
{
    foreach (robotsDisallowed() as $prefix) {
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function robotsDisallowed(): array
{
    $paths = ROBOTS_CORE_DISALLOW;

    foreach (appPrivatePaths() as $private) {
        if (!in_array($private, $paths, true)) {
            $paths[] = $private;
        }
    }

    $filtered = applyFilters('robots_lines', $paths);

    return is_array($filtered)
        ? array_values(array_filter(array_map('strval', $filtered), static fn (string $p): bool => $p !== ''))
        : $paths;
}

function sitemapXml(): string
{
    // Карта сайта считается заново на каждый вызов: рабочий процесс PHP (FPM, mod_php, php -S)
    // живёт между запросами, и статический кеш показал бы поисковику устаревший список
    // до перезапуска воркера.
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach (sitemapUrls() as $url) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . e(absoluteUrl((string) $url['loc'])) . '</loc>' . "\n";
        if (isset($url['lastmod'])) {
            $xml .= '    <lastmod>' . e((string) $url['lastmod']) . '</lastmod>' . "\n";
        }
        $xml .= "  </url>\n";
    }

    return $xml . '</urlset>' . "\n";
}

/** @return list<string> */
function robotsLines(): array
{
    $lines = ['User-agent: *', 'Crawl-delay: 1'];

    foreach (robotsDisallowed() as $path) {
        $lines[] = 'Disallow: ' . $path;
    }

    $lines[] = '';
    $lines[] = 'Sitemap: ' . absoluteUrl('/sitemap.xml');

    return $lines;
}

function robotsTxt(): string
{
    return implode("\n", robotsLines()) . "\n";
}
