<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Sitemap Index: индекс и части собираются из одного списка адресов. Проверяется то, что видит
// поисковик: индекс ссылается на существующие части, число частей совпадает с числом ссылок,
// часть за пределом отдаёт пустую строку (маршрут отвечает 404), а не пустой urlset.
//
// Списки адресов передаются готовыми: sitemapUrls() читает базу, и подменять её значило бы
// проверять заглушку. Сборка — тот же код, что работает на сайте.
final class SitemapIndexTest extends TestCase
{
    /** @return list<array{loc: string, lastmod?: string, priority?: float}> */
    private function urls(int $count): array
    {
        $urls = [];

        for ($n = 1; $n <= $count; $n++) {
            $urls[] = ['loc' => '/stranica-' . $n, 'lastmod' => '2026-11-0' . (($n % 9) + 1), 'priority' => 0.5];
        }

        return $urls;
    }

    public function testИндексСсылаетсяНаКаждуюЧасть(): void
    {
        $xml = sitemapIndexOf($this->urls(3));

        self::assertStringContainsString('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        self::assertSame(1, substr_count($xml, '<sitemap>'), 'Три адреса — одна часть: SITEMAP_PART_SIZE больше трёх');
        self::assertStringContainsString('/sitemap/1', $xml);
    }

    // Граница части — свойство читателя карты: часть не длиннее SITEMAP_PART_SIZE, а последняя
    // может быть короче. Ошибка на единицу здесь означала бы потерянный адрес.
    public function testЧастиНеДлиннееПределаИПоследняяКороче(): void
    {
        $total = SITEMAP_PART_SIZE * 2 + 7;
        $parts = sitemapPartsOf($this->urls($total));

        self::assertCount(3, $parts, 'Две полные части и хвост — это три части');
        self::assertCount(SITEMAP_PART_SIZE, $parts[0]);
        self::assertCount(SITEMAP_PART_SIZE, $parts[1]);
        self::assertCount(7, $parts[2]);

        $sum = 0;
        foreach ($parts as $part) {
            $sum += count($part);
        }
        self::assertSame($total, $sum, 'Части обязаны покрывать все адреса без потерь и повторов');
    }

    // Ровно на пределе — одна часть: array_chunk() отдаёт один блок, а не пустой хвост. Лишняя
    // пустая часть в индексе — это адрес, который поисковик обойдёт и получит пустой urlset.
    public function testРовноНаПределеДаётОднуЧасть(): void
    {
        self::assertCount(1, sitemapPartsOf($this->urls(SITEMAP_PART_SIZE)));
        self::assertCount(2, sitemapPartsOf($this->urls(SITEMAP_PART_SIZE + 1)));
    }

    public function testПустойСайтДаётОднуПустуюЧасть(): void
    {
        $parts = sitemapPartsOf([]);

        self::assertCount(1, $parts, 'Пустой сайт обязан отдать одну часть: индекс без частей — сломанная карта');
        self::assertSame([], $parts[0]);
    }

    // Часть за пределом — пустая строка, а не пустой urlset: обработчик отвечает 404. Пустой urlset
    // поисковик принял бы за «на этой части адресов нет» и вычеркнул бы её из обхода молча.
    public function testЧастьЗаПределомОтдаётПустуюСтроку(): void
    {
        $parts = sitemapPartsOf($this->urls(3));

        self::assertSame('', sitemapPartOf($parts, 0), 'Нулевая часть не существует');
        self::assertSame('', sitemapPartOf($parts, 2), 'Второй части у трёх адресов нет');
        self::assertNotSame('', sitemapPartOf($parts, 1), 'Первая часть обязана печататься');
        self::assertStringContainsString('<urlset', sitemapPartOf($parts, 1));
    }

    // Адреса в части абсолютные: относительный адрес в карте сайта поисковик не примет.
    public function testАдресаЧастиАбсолютные(): void
    {
        $xml = sitemapPartOf(sitemapPartsOf($this->urls(2)), 1);

        self::assertMatchesRegularExpression('~<loc>https?://[^<]+/stranica-1</loc>~', $xml);
        self::assertSame(2, substr_count($xml, '<loc>'));
    }
}
