<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * Лента отдаёт только то, что читатель уже может открыть: материал со сроком публикации в
 * будущем лежит в базе как PUBLISHED, но на сайте его ещё нет. Без фильтра по дате RSS и Atom
 * раскрывали бы запланированную новость раньше времени.
 */
final class FeedItemsTest extends NewsDbTestCase
{
    public function testЗапланированныйМатериалНеПопадаетВЛенту(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $past = utcStamp(shiftSeconds(nowUtc(), -3600));
        $future = utcStamp(shiftSeconds(nowUtc(), 7200));

        self::makeItem($category, 'uzhe-na-sajte', [
            'title' => 'Уже на сайте',
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => $past,
            'updated_at' => $past,
        ]);
        self::makeItem($category, 'eshche-ne-vyshel', [
            'title' => 'Ещё не вышел',
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => $future,
            'updated_at' => $future,
        ]);

        $urls = array_column(feedItems(), 'url');

        self::assertContains(absoluteUrl('/news/uzhe-na-sajte'), $urls);
        self::assertNotContains(absoluteUrl('/news/eshche-ne-vyshel'), $urls);
        self::assertStringNotContainsString('Ещё не вышел', rssFeedXml());
        self::assertStringNotContainsString('Ещё не вышел', atomFeedXml());
    }
}
