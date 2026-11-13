<?php

declare(strict_types=1);

// Ленты RSS 2.0 и Atom. Собираются из опубликованных страниц ядра и материалов «Новостей»,
// если раздел установлен. Даты форматируются только функциями core/date.php: прямой ->format()
// в остальном дереве запрещён стражем.

const FEED_LIMIT = 50;

/**
 * @return list<array{title: string, url: string, summary: string, date: DateTimeImmutable}>
 */
function feedItems(): array
{
    $items = [];

    foreach (all(
        'SELECT slug, title, description, updated_at FROM ' . t('page')
        . ' WHERE status = ? AND noindex = 0 ORDER BY updated_at DESC, slug LIMIT ' . FEED_LIMIT,
        [PAGES_STATUS_PUBLISHED]
    ) as $row) {
        $date = feedMoment((string) $row['updated_at']);
        $items[] = [
            'title' => (string) $row['title'],
            'url' => absoluteUrl('/' . (string) $row['slug']),
            'summary' => (string) $row['description'],
            'date' => $date,
        ];
    }

    if (tableExists('news_item')) {
        foreach (all(
            'SELECT slug, title, lead_text, published_at, updated_at FROM ' . t('news_item')
            . " WHERE status = 'PUBLISHED' AND published_at IS NOT NULL AND published_at <= ?"
            . ' ORDER BY published_at DESC, slug LIMIT ' . FEED_LIMIT,
            [utcStamp(nowUtc())]
        ) as $row) {
            $items[] = [
                'title' => (string) $row['title'],
                'url' => absoluteUrl('/news/' . (string) $row['slug']),
                'summary' => (string) ($row['lead_text'] ?? ''),
                'date' => feedMoment((string) ($row['published_at'] ?? $row['updated_at'])),
            ];
        }
    }

    usort($items, static function (array $left, array $right): int {
        $byDate = $right['date']->getTimestamp() <=> $left['date']->getTimestamp();

        return $byDate !== 0 ? $byDate : strcmp($left['url'], $right['url']);
    });

    return array_slice($items, 0, FEED_LIMIT);
}

function feedMoment(string $stamp): DateTimeImmutable
{
    // Разбор даты — через модуль дат: прямой createFromFormat() страж DateUsageTest запрещает
    // вне core/date.php.
    return fromDb($stamp) ?? nowUtc();
}

function feedSiteName(): string
{
    $name = trim((string) getOption('site_name', (string) configValue('SITE_NAME', '')));

    return $name !== '' ? $name : 'Сайт';
}

function rssFeedXml(): string
{
    return rssXmlOf(feedItems());
}

/**
 * Сборка RSS из готового списка: отделена от выборки, чтобы правила формата проверялись unit-тестом
 * без подмены базы, а сайт и тест собирали ленту одним и тем же кодом.
 *
 * @param list<array{title: string, url: string, summary: string, date: DateTimeImmutable}> $items
 */
function rssXmlOf(array $items): string
{
    $latest = $items === [] ? nowUtc() : $items[0]['date'];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    $xml .= "  <channel>\n";
    $xml .= '    <title>' . e(feedSiteName()) . "</title>\n";
    $xml .= '    <link>' . e(absoluteUrl('/')) . "</link>\n";
    $xml .= "    <description>Новости и страницы сайта</description>\n";
    $xml .= "    <language>ru</language>\n";
    $xml .= '    <lastBuildDate>' . e(rfc2822Stamp($latest)) . "</lastBuildDate>\n";
    $xml .= '    <atom:link href="' . e(absoluteUrl('/feed.xml')) . '" rel="self" type="application/rss+xml"/>' . "\n";

    foreach ($items as $item) {
        $xml .= "    <item>\n";
        $xml .= '      <title>' . e($item['title']) . "</title>\n";
        $xml .= '      <link>' . e($item['url']) . "</link>\n";
        $xml .= '      <guid isPermaLink="true">' . e($item['url']) . "</guid>\n";
        $xml .= '      <pubDate>' . e(rfc2822Stamp($item['date'])) . "</pubDate>\n";
        $xml .= '      <description>' . e($item['summary']) . "</description>\n";
        $xml .= "    </item>\n";
    }

    return $xml . "  </channel>\n</rss>\n";
}

function atomFeedXml(): string
{
    return atomXmlOf(feedItems());
}

/**
 * @param list<array{title: string, url: string, summary: string, date: DateTimeImmutable}> $items
 */
function atomXmlOf(array $items): string
{
    $latest = $items === [] ? nowUtc() : $items[0]['date'];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
    $xml .= '  <title>' . e(feedSiteName()) . "</title>\n";
    $xml .= '  <link href="' . e(absoluteUrl('/')) . '"/>' . "\n";
    $xml .= '  <link rel="self" href="' . e(absoluteUrl('/feed.atom')) . '"/>' . "\n";
    $xml .= '  <id>' . e(absoluteUrl('/')) . "</id>\n";
    $xml .= '  <updated>' . e(moscowIso($latest)) . "</updated>\n";

    foreach ($items as $item) {
        $xml .= "  <entry>\n";
        $xml .= '    <title>' . e($item['title']) . "</title>\n";
        $xml .= '    <link href="' . e($item['url']) . '"/>' . "\n";
        $xml .= '    <id>' . e($item['url']) . "</id>\n";
        $xml .= '    <updated>' . e(moscowIso($item['date'])) . "</updated>\n";
        $xml .= '    <summary>' . e($item['summary']) . "</summary>\n";
        $xml .= "  </entry>\n";
    }

    return $xml . "</feed>\n";
}
