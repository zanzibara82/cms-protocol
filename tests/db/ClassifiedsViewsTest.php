<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\Attributes\Test;

/**
 * Счётчик просмотров объявления (C6): кому засчитывается просмотр, как считается
 * подпись и кто видит число.
 */
final class ClassifiedsViewsTest extends ClassifiedsDbTestCase
{
    #[Test]
    public function guestViewIncrementsCounter(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category);
        $listing = classifiedsListingById($listingId);

        self::assertNotNull($listing);
        self::assertSame(1, classifiedsListingCountView($listing, null));

        $after = classifiedsListingById($listingId);
        self::assertNotNull($after);
        self::assertSame(1, $after['views_count']);
        self::assertNotNull($after['last_viewed_at']);
    }

    #[Test]
    public function ownerViewIsNotCounted(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category);
        $listing = classifiedsListingById($listingId);
        $owner = one('SELECT id, role, blocked_at FROM ' . t('user') . ' WHERE id = ?', [$seller]);

        self::assertNotNull($listing);
        self::assertNotNull($owner);
        self::assertNull(classifiedsListingCountView($listing, $owner));

        $after = classifiedsListingById($listingId);
        self::assertNotNull($after);
        self::assertSame(0, $after['views_count']);
        self::assertNull($after['last_viewed_at']);
    }

    #[Test]
    public function moderatorViewIsNotCounted(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category);
        $listing = classifiedsListingById($listingId);
        $editorId = TestDb::seedUser('editor@example.ru', 'secret-pass-1', 'editor');
        $editor = one('SELECT id, role, blocked_at FROM ' . t('user') . ' WHERE id = ?', [$editorId]);

        self::assertNotNull($listing);
        self::assertNotNull($editor);
        self::assertNull(classifiedsListingCountView($listing, $editor));

        $after = classifiedsListingById($listingId);
        self::assertNotNull($after);
        self::assertSame(0, $after['views_count']);
    }

    #[Test]
    public function draftIsNotCounted(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category, ['status' => 'DRAFT']);
        $listing = classifiedsListingById($listingId);

        self::assertNotNull($listing);
        self::assertNull(classifiedsListingCountView($listing, null));

        $after = classifiedsListingById($listingId);
        self::assertNotNull($after);
        self::assertSame(0, $after['views_count']);
    }

    #[Test]
    public function labelAgreesWithNumber(): void
    {
        self::assertSame('', classifiedsViewsLabel(0));
        self::assertSame('1 просмотр', classifiedsViewsLabel(1));
        self::assertSame('2 просмотра', classifiedsViewsLabel(2));
        self::assertSame('5 просмотров', classifiedsViewsLabel(5));
        self::assertSame('11 просмотров', classifiedsViewsLabel(11));
        self::assertSame('21 просмотр', classifiedsViewsLabel(21));
    }

    #[Test]
    public function counterIsHiddenWhenOwnerTurnedItOff(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category, ['views_public' => 0]);
        $listing = classifiedsListingById($listingId);
        $owner = one('SELECT id, role, blocked_at FROM ' . t('user') . ' WHERE id = ?', [$seller]);

        self::assertNotNull($listing);
        self::assertNotNull($owner);
        self::assertFalse(classifiedsViewsPublic($listing, 10, null));
        self::assertTrue(classifiedsViewsPublic($listing, 10, $owner));
    }

    #[Test]
    public function counterIsVisibleToGuestsByDefault(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category);
        $listing = classifiedsListingById($listingId);

        self::assertNotNull($listing);
        self::assertTrue(classifiedsViewsPublic($listing, 3, null));
        self::assertFalse(classifiedsViewsPublic($listing, 0, null));
    }

    #[Test]
    public function formKeepsViewsPublicChoice(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category, ['views_public' => 0]);
        $listing = classifiedsListingById($listingId);

        self::assertNotNull($listing);
        self::assertFalse($listing['views_public']);

        $check = classifiedsValidateListing([
            'title' => 'Велосипед',
            'slug' => '',
            'category' => $category,
            'body' => 'Описание.',
            'price' => '1000',
            'status' => 'PUBLISHED',
            'views_public' => '1',
        ], $listingId);

        self::assertSame([], $check['errors']);
        self::assertSame(1, $check['values']['views_public']);
    }
}
