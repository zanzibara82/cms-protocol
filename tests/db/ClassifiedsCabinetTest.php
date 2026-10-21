<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * C3: профиль продавца, избранное, срок публикации, продление и клонирование.
 */
final class ClassifiedsCabinetTest extends ClassifiedsDbTestCase
{
    public function testПрофильСоздаётсяОбновляетсяИСкрываетТелефон(): void
    {
        $sellerId = self::makeSeller();
        $moment = new \DateTimeImmutable('2026-10-01 09:00:00', new \DateTimeZone('UTC'));

        $saved = classifiedsSaveSellerProfile($sellerId, [
            'name' => 'Иван Продавец',
            'phone' => '+7 900 000-00-00',
            'city' => 'Тверь',
            'about' => 'Продаю инструменты.',
            'show_phone' => 1,
        ], $moment);

        self::assertTrue($saved['ok']);
        self::assertSame('Иван Продавец', value('SELECT name FROM ' . t('user') . ' WHERE id = ?', [$sellerId]));

        $public = classifiedsSellerPublic($sellerId);
        self::assertNotNull($public);
        self::assertSame('Тверь', $public['city']);
        self::assertSame('+7 900 000-00-00', $public['phone']);
        self::assertSame('Продаю инструменты.', $public['about']);

        $saved = classifiedsSaveSellerProfile($sellerId, [
            'name' => 'Иван П.',
            'phone' => '+7 900 000-00-00',
            'city' => 'Тверь',
            'about' => '',
            'show_phone' => 0,
        ], $moment);

        self::assertTrue($saved['ok']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_seller_profile') . ' WHERE user_id = ?',
            [$sellerId]
        ));

        $public = classifiedsSellerPublic($sellerId);
        self::assertNotNull($public);
        self::assertSame('', $public['phone']);
        self::assertFalse($public['show_phone']);

        run('UPDATE ' . t('user') . ' SET blocked_at = ? WHERE id = ?', [utcStamp($moment), $sellerId]);
        self::assertNull(classifiedsSellerPublic($sellerId));
    }

    public function testИзбранноеИдемпотентноИПоказываетТолькоДоступное(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $visible = self::makeListing($sellerId, $categoryId, ['title' => 'Видимое', 'slug' => 'vidimoe']);
        $expired = self::makeListing($sellerId, $categoryId, [
            'title' => 'Истёкшее',
            'slug' => 'istekshee',
            'expires_at' => utcStamp(nowUtc()->sub(new \DateInterval('P1D'))),
        ]);
        $draft = self::makeListing($sellerId, $categoryId, ['title' => 'Черновик', 'slug' => 'chernovik', 'status' => 'DRAFT']);

        self::assertTrue(classifiedsFavoriteAdd($sellerId, $visible)['ok']);
        self::assertTrue(classifiedsFavoriteAdd($sellerId, $visible)['ok']);
        self::assertSame(1, classifiedsFavoriteCount($sellerId));
        self::assertTrue(classifiedsIsFavorite($sellerId, $visible));

        $rows = classifiedsFavoriteListings($sellerId);
        self::assertCount(1, $rows);
        self::assertSame('Видимое', $rows[0]['title']);

        self::assertFalse(classifiedsFavoriteAdd($sellerId, $expired)['ok']);
        self::assertFalse(classifiedsFavoriteAdd($sellerId, $draft)['ok']);

        self::assertTrue(classifiedsFavoriteRemove($sellerId, $visible)['ok']);
        self::assertSame(0, classifiedsFavoriteCount($sellerId));
    }

    public function testЛентыПрячутИстёкшееАКабинетПоказывает(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $visible = self::makeListing($sellerId, $categoryId, ['title' => 'Видимое', 'slug' => 'vidimoe']);
        self::makeListing($sellerId, $categoryId, [
            'title' => 'Истёкшее',
            'slug' => 'istekshee',
            'expires_at' => utcStamp(nowUtc()->sub(new \DateInterval('P1D'))),
        ]);

        self::assertSame(1, classifiedsListingFeed([], 1)['total']);
        self::assertCount(1, classifiedsHomeFeed());

        $category = classifiedsCategoryById($categoryId);
        self::assertNotNull($category);
        self::assertSame(1, classifiedsCategoryFeed($category, 1)['total']);

        $owned = classifiedsOwnerListings($sellerId);
        self::assertCount(2, $owned);
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $owned);
        self::assertContains($visible, $ids);
    }

    public function testПубликацияСтавитСрокАЧерновикЕгоСнимает(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $base = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $values = [
            'title' => 'Срок',
            'slug' => 'srok',
            'category' => $categoryId,
            'body' => 'Объявление со сроком.',
            'price_float' => 100.0,
            'negotiable' => 0,
            'location' => '',
            'status' => 'PUBLISHED',
            'seo_title' => '',
            'seo_description' => '',
            'noindex' => 0,
        ];

        $created = classifiedsCreateListing($sellerId, $values, $base);
        self::assertTrue($created['ok']);
        $listing = classifiedsListingById($created['id']);
        self::assertNotNull($listing);
        self::assertSame('2026-01-31 10:00:00', $listing['expires_at']);

        $edited = classifiedsUpdateListing($created['id'], $values, $base->modify('+10 days'));
        self::assertTrue($edited['ok']);
        $afterEdit = classifiedsListingById($created['id']);
        self::assertNotNull($afterEdit);
        self::assertSame('2026-01-31 10:00:00', $afterEdit['expires_at']);

        $renewed = classifiedsUpdateListing($created['id'], $values, $base->modify('+40 days'));
        self::assertTrue($renewed['ok']);
        $afterRenew = classifiedsListingById($created['id']);
        self::assertNotNull($afterRenew);
        self::assertSame('2026-03-12 10:00:00', $afterRenew['expires_at']);

        $draft = $values;
        $draft['status'] = 'DRAFT';
        self::assertTrue(classifiedsUpdateListing($created['id'], $draft, $base->modify('+41 days'))['ok']);
        $afterDraft = classifiedsListingById($created['id']);
        self::assertNotNull($afterDraft);
        self::assertNull($afterDraft['expires_at']);
    }

    public function testКлонированиеПроверяетВладельца(): void
    {
        $sellerId = self::makeSeller();
        $otherId = self::makeSeller('other@example.ru');
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, [
            'title' => 'Оригинал',
            'slug' => 'original',
            'expires_at' => utcStamp(nowUtc()->sub(new \DateInterval('P1D'))),
            'image' => '/uploads/classifieds/original.jpg',
        ]);
        $attributeId = self::makeAttribute($categoryId, 'condition', 'Состояние', 'select', ['Новое', 'Б/у']);
        classifiedsReplaceListingAttributes($listingId, [
            $attributeId => ['value_text' => 'Б/у', 'value_number' => null],
        ]);
        classifiedsReplaceListingImages($listingId, ['/uploads/classifieds/one.jpg']);

        $moment = new \DateTimeImmutable('2026-10-01 09:00:00', new \DateTimeZone('UTC'));
        self::assertFalse(classifiedsListingClone($listingId, $otherId, $moment)['ok']);

        $cloned = classifiedsListingClone($listingId, $sellerId, $moment);
        self::assertTrue($cloned['ok']);
        $copy = classifiedsListingById($cloned['id']);
        self::assertNotNull($copy);
        self::assertSame('Оригинал (копия)', $copy['title']);
        self::assertSame('DRAFT', $copy['status']);
        self::assertNull($copy['image']);
        self::assertNull($copy['expires_at']);
        self::assertNotSame($listingId, $copy['id']);
        self::assertSame('Б/у', value(
            'SELECT value_text FROM ' . t('classifieds_listing_attribute') . ' WHERE listing_id = ? AND attribute_id = ?',
            [$copy['id'], $attributeId]
        ));
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_listing_image') . ' WHERE listing_id = ?',
            [$copy['id']]
        ));
    }
}
