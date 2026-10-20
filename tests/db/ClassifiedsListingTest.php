<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use PHPUnit\Framework\Attributes\Test;

/**
 * Объявления доски: проверка формы, статусы, выдача витрины и правила владения.
 */
final class ClassifiedsListingTest extends ClassifiedsDbTestCase
{
    /** @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    private static function post(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Велосипед городской',
            'slug' => '',
            'category' => '',
            'body' => "Продаю велосипед.\nПробег небольшой.",
            'price' => '1500',
            'negotiable' => '',
            'location' => 'Москва',
            'status' => 'DRAFT',
            'seo_title' => '',
            'seo_description' => '',
            'noindex' => '',
        ], $overrides);
    }

    #[Test]
    public function validationNamesEveryMissingField(): void
    {
        $check = classifiedsValidateListing(self::post(['title' => '', 'body' => '', 'price' => '']));

        self::assertArrayHasKey('title', $check['errors']);
        self::assertArrayHasKey('body', $check['errors']);
        self::assertArrayHasKey('price', $check['errors']);
        self::assertArrayHasKey('category', $check['errors']);
    }

    #[Test]
    public function negotiableReplacesPriceAndBadPriceIsRejected(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');

        $free = classifiedsValidateListing(self::post(['category' => $category, 'price' => '', 'negotiable' => '1']));
        self::assertSame([], $free['errors']);
        self::assertNull($free['values']['price_float']);
        self::assertSame(1, $free['values']['negotiable']);

        $broken = classifiedsValidateListing(self::post(['category' => $category, 'price' => '1 500 руб']));
        self::assertArrayHasKey('price', $broken['errors']);

        $kopecks = classifiedsValidateListing(self::post(['category' => $category, 'price' => '1500,50']));
        self::assertSame([], $kopecks['errors']);
        self::assertSame(1500.5, $kopecks['values']['price_float']);
    }

    #[Test]
    public function hiddenCategoryCannotBeChosen(): void
    {
        $hidden = self::makeCategory('skrytaya', 'Скрытая', null, 0, 0);

        $check = classifiedsValidateListing(self::post(['category' => $hidden]));
        self::assertArrayHasKey('category', $check['errors']);
    }

    #[Test]
    public function slugIsBuiltAndNumberedAcrossListings(): void
    {
        $seller = self::makeSeller();
        $category = self::makeCategory('velo', 'Велосипеды');
        $first = classifiedsValidateListing(self::post(['category' => $category]));
        self::assertSame('velosiped-gorodskoy', $first['values']['slug']);

        classifiedsCreateListing($seller, $first['values']);
        $second = classifiedsValidateListing(self::post(['category' => $category]));
        self::assertSame('velosiped-gorodskoy-2', $second['values']['slug']);
    }

    #[Test]
    public function createKeepsDraftHiddenUntilPublished(): void
    {
        $seller = self::makeSeller();
        $category = self::makeCategory('velo', 'Велосипеды');
        $check = classifiedsValidateListing(self::post(['category' => $category, 'status' => 'DRAFT']));

        $created = classifiedsCreateListing($seller, $check['values']);
        self::assertTrue($created['ok']);

        self::assertNull(classifiedsPublishedListingWithCategory('velosiped-gorodskoy'));
        self::assertSame([], classifiedsCategoryFeed(self::category('velo'), 1)['rows']);

        $published = classifiedsListingSetStatus($created['id'], $seller, 'PUBLISHED');
        self::assertTrue($published['ok']);
        self::assertNotNull(classifiedsPublishedListingWithCategory('velosiped-gorodskoy'));
        self::assertCount(1, classifiedsCategoryFeed(self::category('velo'), 1)['rows']);
    }

    /** @return array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool} */
    private static function category(string $slug): array
    {
        $row = classifiedsCategoryBySlug($slug);
        self::assertNotNull($row);

        return $row;
    }

    #[Test]
    public function categoryFeedIncludesDescendantListings(): void
    {
        $seller = self::makeSeller();
        $root = self::makeCategory('transport', 'Транспорт');
        $child = self::makeCategory('velo', 'Велосипеды', $root);
        $other = self::makeCategory('dom', 'Дом');

        self::makeListing($seller, $child, ['slug' => 'velosiped']);
        self::makeListing($seller, $child, ['slug' => 'samokat', 'status' => 'DRAFT']);
        self::makeListing($seller, $other, ['slug' => 'divan']);

        $feed = classifiedsCategoryFeed(self::category('transport'), 1);
        self::assertSame(1, $feed['total']);
        self::assertSame(['velosiped'], array_column($feed['rows'], 'slug'));
    }

    #[Test]
    public function feedPagesAndHomeLimitWork(): void
    {
        $seller = self::makeSeller();
        $category = self::makeCategory('velo', 'Велосипеды');

        for ($i = 1; $i <= 25; $i++) {
            self::makeListing($seller, $category, [
                'slug' => 'tovar-' . $i,
                'created_at' => utcStamp(nowUtc()->modify('-' . $i . ' minutes')),
            ]);
        }

        $first = classifiedsCategoryFeed(self::category('velo'), 1);
        self::assertSame(25, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertCount(20, $first['rows']);
        self::assertSame('tovar-1', (string) $first['rows'][0]['slug']);

        $second = classifiedsCategoryFeed(self::category('velo'), 2);
        self::assertCount(5, $second['rows']);

        self::assertCount(12, classifiedsHomeFeed());
    }

    #[Test]
    public function statusAndDeleteRespectOwnership(): void
    {
        $owner = self::makeSeller('owner@example.ru');
        $stranger = self::makeSeller('stranger@example.ru');
        $category = self::makeCategory('velo', 'Велосипеды');
        $id = self::makeListing($owner, $category, ['slug' => 'velosiped', 'status' => 'DRAFT']);

        $foreign = classifiedsListingSetStatus($id, $stranger, 'PUBLISHED');
        self::assertFalse($foreign['ok']);

        $deleteForeign = classifiedsListingDelete($id, $stranger);
        self::assertFalse($deleteForeign['ok']);
        self::assertNotNull(classifiedsListingById($id));

        $better = classifiedsListingSetStatus($id, $owner, 'PUBLISHED');
        self::assertTrue($better['ok']);
        self::assertSame('listing_published', $better['notice']);

        $deleted = classifiedsListingDelete($id, $owner);
        self::assertTrue($deleted['ok']);
        self::assertNull(classifiedsListingById($id));
    }

    #[Test]
    public function updateRewritesFieldsAndCanClearImage(): void
    {
        $seller = self::makeSeller();
        $category = self::makeCategory('velo', 'Велосипеды');
        $id = self::makeListing($seller, $category, ['slug' => 'velosiped', 'image' => '/uploads/images/staryy.jpg']);

        $check = classifiedsValidateListing(self::post([
            'title' => 'Велосипед складной',
            'slug' => 'velosiped',
            'category' => $category,
            'price' => '2000',
            'status' => 'PUBLISHED',
        ]), $id);
        self::assertSame([], $check['errors']);

        $check['values']['image'] = '';
        $updated = classifiedsUpdateListing($id, $check['values']);
        self::assertTrue($updated['ok']);

        $row = classifiedsListingById($id);
        self::assertNotNull($row);
        self::assertSame('Велосипед складной', $row['title']);
        self::assertSame('PUBLISHED', $row['status']);
        self::assertSame(2000.0, $row['price']);
        self::assertNull($row['image']);
    }

    #[Test]
    public function draftIsVisibleBySlugOnlyThroughAnyStatusRead(): void
    {
        $seller = self::makeSeller();
        $category = self::makeCategory('velo', 'Велосипеды');
        self::makeListing($seller, $category, ['slug' => 'chernovik', 'status' => 'DRAFT']);

        self::assertNull(classifiedsPublishedListingWithCategory('chernovik'));
        $draft = classifiedsListingAnyStatus('chernovik');
        self::assertNotNull($draft);
        self::assertSame('DRAFT', $draft['status']);
    }
}
