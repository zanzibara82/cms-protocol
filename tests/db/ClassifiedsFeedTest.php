<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use PHPUnit\Framework\Attributes\Test;

/**
 * Лента доски: слова, категория с веткой, фильтры по характеристикам, сортировки и адреса.
 */
final class ClassifiedsFeedTest extends ClassifiedsDbTestCase
{
    /** @var array{category: string, child: string, material: string, length: string, keys: string} */
    private static array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $seller = self::makeSeller();
        $category = self::makeCategory('mebel', 'Мебель');
        $child = self::makeCategory('stoly', 'Столы', $category);
        $material = self::makeAttribute($category, 'material', 'Материал', 'select', ['Дуб', 'Сосна'], 0, 1);
        $length = self::makeAttribute($category, 'length', 'Длина', 'number', [], 0, 1);
        $keys = self::makeAttribute($category, 'keys', 'Ключи', 'bool', [], 0, 1);

        $table = self::makeListing($seller, $child, [
            'slug' => 'stol-dubovyy',
            'title' => 'Стол дубовый',
            'body' => 'Крепкий обеденный стол.',
            'price' => 5000,
            'created_at' => '2026-10-18 10:00:00',
        ]);
        $chair = self::makeListing($seller, $category, [
            'slug' => 'stul',
            'title' => 'Стул',
            'body' => 'Стол не входит, только стул.',
            'price' => 1500,
            'created_at' => '2026-10-19 10:00:00',
        ]);
        $folding = self::makeListing($seller, $category, [
            'slug' => 'stol-skladnoy',
            'title' => 'Стол складной',
            'body' => 'Легко убрать.',
            'price' => null,
            'negotiable' => 1,
            'created_at' => '2026-10-20 10:00:00',
        ]);
        self::makeListing($seller, $category, [
            'slug' => 'stol-chernovik',
            'title' => 'Стол черновик',
            'status' => 'DRAFT',
        ]);

        classifiedsReplaceListingAttributes($table, [
            $material => ['value_text' => 'Дуб', 'value_number' => null],
            $length => ['value_text' => null, 'value_number' => 120.0],
            $keys => ['value_text' => null, 'value_number' => 1.0],
        ]);
        classifiedsReplaceListingAttributes($chair, [
            $material => ['value_text' => 'Сосна', 'value_number' => null],
            $length => ['value_text' => null, 'value_number' => 45.0],
        ]);
        classifiedsReplaceListingAttributes($folding, [
            $material => ['value_text' => 'Дуб', 'value_number' => null],
            $length => ['value_text' => null, 'value_number' => 80.0],
            $keys => ['value_text' => null, 'value_number' => 0.0],
        ]);

        self::$fixture = [
            'category' => $category,
            'child' => $child,
            'material' => $material,
            'length' => $length,
            'keys' => $keys,
        ];
    }

    /**
     * @param array{rows: list<array<string, mixed>>} $feed
     * @return list<string>
     */
    private static function slugs(array $feed): array
    {
        return array_map(static fn (array $row): string => (string) $row['slug'], $feed['rows']);
    }

    #[Test]
    public function searchFindsWordsInTitleAndBodyOfPublishedListingsOnly(): void
    {
        $feed = classifiedsListingFeed(['q' => 'стол'], 1);
        self::assertSame(3, $feed['total']);
        self::assertContains('stul', self::slugs($feed));
        self::assertNotContains('stol-chernovik', self::slugs($feed));

        $titleOnly = classifiedsListingFeed(['q' => 'складной'], 1);
        self::assertSame(['stol-skladnoy'], self::slugs($titleOnly));
    }

    #[Test]
    public function categoryFeedIncludesDescendants(): void
    {
        $feed = classifiedsListingFeed(['category_ids' => [self::$fixture['category'], self::$fixture['child']]], 1);
        self::assertSame(3, $feed['total']);

        $onlyChild = classifiedsListingFeed(['category_ids' => [self::$fixture['child']]], 1);
        self::assertSame(['stol-dubovyy'], self::slugs($onlyChild));
    }

    #[Test]
    public function priceSortingKeepsNegotiableListingsLast(): void
    {
        $asc = classifiedsListingFeed(['sort' => 'price_asc'], 1);
        self::assertSame(['stul', 'stol-dubovyy', 'stol-skladnoy'], self::slugs($asc));

        $desc = classifiedsListingFeed(['sort' => 'price_desc'], 1);
        self::assertSame(['stol-dubovyy', 'stul', 'stol-skladnoy'], self::slugs($desc));
    }

    #[Test]
    public function filtersNarrowTheFeedByAttributeValues(): void
    {
        $material = self::$fixture['material'];
        $length = self::$fixture['length'];
        $keys = self::$fixture['keys'];

        $oak = classifiedsListingFeed(['filters' => [
            $material => ['kind' => 'select', 'value' => 'Дуб'],
        ]], 1);
        self::assertSame(['stol-skladnoy', 'stol-dubovyy'], self::slugs($oak));

        $long = classifiedsListingFeed(['filters' => [
            $length => ['kind' => 'number', 'min' => '100', 'max' => '150'],
        ]], 1);
        self::assertSame(['stol-dubovyy'], self::slugs($long));

        $withKeys = classifiedsListingFeed(['filters' => [
            $keys => ['kind' => 'bool', 'value' => '1'],
        ]], 1);
        self::assertSame(['stol-dubovyy'], self::slugs($withKeys));

        $withoutKeys = classifiedsListingFeed(['filters' => [
            $keys => ['kind' => 'bool', 'value' => '0'],
        ]], 1);
        self::assertSame(['stol-skladnoy', 'stul'], self::slugs($withoutKeys));
    }

    #[Test]
    public function relevanceRanksTitleMatchesFirstAndNeedsQuery(): void
    {
        $relevance = classifiedsListingFeed(['q' => 'стол', 'sort' => 'relevance'], 1);
        self::assertSame(['stol-dubovyy', 'stol-skladnoy', 'stul'], self::slugs($relevance));

        $noQuery = classifiedsListingFeed(['sort' => 'relevance'], 1);
        self::assertSame('date', $noQuery['sort']);
    }

    #[Test]
    public function invalidSortAndViewFallBackToDefaults(): void
    {
        $feed = classifiedsListingFeed(['sort' => 'magic', 'view' => 'cards'], 1);
        self::assertSame('date', $feed['sort']);
        self::assertSame('grid', $feed['view']);
        self::assertSame(['stol-skladnoy', 'stul', 'stol-dubovyy'], self::slugs($feed));
    }

    #[Test]
    public function feedUrlKeepsQueryFiltersSortViewAndPage(): void
    {
        $material = self::$fixture['material'];
        $state = [
            'q' => 'стол',
            'sort' => 'price_asc',
            'view' => 'list',
            'filters' => [$material => ['kind' => 'select', 'value' => 'Дуб']],
        ];

        $url = classifiedsFeedUrl('/classifieds/c/mebel', $state, 2);
        self::assertStringStartsWith('/classifieds/c/mebel?', $url);
        self::assertStringContainsString('q=', $url);
        self::assertStringContainsString('sort=price_asc', $url);
        self::assertStringContainsString('view=list', $url);
        self::assertStringContainsString('f%5B', $url);
        self::assertStringContainsString('page=2', $url);

        $default = classifiedsFeedUrl('/classifieds', [
            'q' => '',
            'sort' => 'date',
            'view' => 'grid',
            'filters' => [],
        ]);
        self::assertSame('/classifieds', $default);
    }
}
