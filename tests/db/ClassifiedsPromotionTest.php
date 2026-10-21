<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * C5: платное продвижение. Услуга покупается заказом магазина и включается после оплаты:
 * до оплаты объявление не трогают, после — услуга применяется один раз, а отменённый
 * заказ закрывает покупку, ничего не меняя.
 */
final class ClassifiedsPromotionTest extends ClassifiedsDbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $result = installApp('commerce');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Магазин» не установился: ' . (string) ($result['error'] ?? ''));
        }

        // Подписка на смену статуса заказа живёт в boot.php доски, а hooksReset() в других
        // наборах снимает все подписки процесса. Набор подписывается заново — иначе оплата
        // заказа молча осталась бы без услуги.
        addAction('commerce_order_status', 'classifiedsPromotionOrderStatus');
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        // Магазин уходит вместе с доской: таблицы и отметка установки, как у его собственных
        // наборов, — иначе следующий набор магазина сочтёт раздел установленным без таблиц.
        foreach ([
            'commerce_order_item',
            'commerce_order',
            'commerce_variant',
            'commerce_product_attribute',
            'commerce_attribute_choice',
            'commerce_attribute',
            'commerce_product_category',
            'commerce_coupon',
            'commerce_promo',
            'commerce_supplier',
            'commerce_webhook',
            'commerce_product',
            'commerce_category',
        ] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'commerce'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'commerce'");
    }

    public function testПокупкаСоздаётЗаказИЖдётОплаты(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, ['title' => 'Кофемашина']);

        $result = classifiedsPromotionCreate('BUMP', $listingId, self::user($sellerId));

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame('promotion_ordered', $result['notice']);
        self::assertGreaterThan(0, $result['order_number']);

        $order = one(
            'SELECT status, total, currency FROM ' . t('commerce_order') . ' WHERE id = ?',
            [$result['order_id']]
        );
        self::assertNotNull($order);
        self::assertSame('NEW', (string) $order['status']);
        self::assertSame(300.00, (float) $order['total']);
        self::assertSame('RUB', (string) $order['currency']);

        $promo = one(
            'SELECT kind, status, order_number, amount FROM ' . t('classifieds_promotion')
            . ' WHERE listing_id = ?',
            [$listingId]
        );
        self::assertNotNull($promo);
        self::assertSame('BUMP', (string) $promo['kind']);
        self::assertSame('NEW', (string) $promo['status']);
        self::assertSame($result['order_number'], (int) $promo['order_number']);
        self::assertSame(300.00, (float) $promo['amount']);

        // До оплаты объявление не тронуто: поднятие ждёт денег.
        $listing = classifiedsListingById($listingId);
        self::assertNotNull($listing);
        self::assertNull($listing['boosted_at']);
    }

    public function testОплатаВключаетПоднятиеОдинРаз(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, ['title' => 'Кофемашина']);

        $result = classifiedsPromotionCreate('BUMP', $listingId, self::user($sellerId));
        self::assertTrue($result['ok'], $result['error']);

        $paid = commerceSetOrderStatus($result['order_id'], 'PAID');
        self::assertTrue($paid['ok'], $paid['error']);

        $listing = classifiedsListingById($listingId);
        self::assertNotNull($listing);
        self::assertNotNull($listing['boosted_at']);

        $applied = (string) $listing['boosted_at'];
        $promo = one(
            'SELECT status, applied_at FROM ' . t('classifieds_promotion') . ' WHERE listing_id = ?',
            [$listingId]
        );
        self::assertNotNull($promo);
        self::assertSame('ACTIVE', (string) $promo['status']);
        self::assertNotNull($promo['applied_at']);

        // Повторный прогон по тому же заказу услугу второй раз не применяет.
        classifiedsPromotionActivateByOrder($result['order_id']);
        $again = classifiedsListingById($listingId);
        self::assertNotNull($again);
        self::assertSame($applied, (string) $again['boosted_at']);
    }

    public function testОплатаВыделяетОбъявлениеНаНеделю(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, ['title' => 'Кофемашина']);

        $result = classifiedsPromotionCreate('FEATURED', $listingId, self::user($sellerId));
        self::assertTrue($result['ok'], $result['error']);

        $until = fromDb((string) value(
            'SELECT featured_until FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [$listingId]
        ));
        self::assertNull($until, 'До оплаты выделение не включается');

        commerceSetOrderStatus($result['order_id'], 'PAID');

        $until = fromDb((string) value(
            'SELECT featured_until FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [$listingId]
        ));
        self::assertNotNull($until);
        self::assertSame(
            CLASSIFIEDS_FEATURED_DAYS,
            (int) round(($until->getTimestamp() - nowUtc()->getTimestamp()) / 86400)
        );
    }

    public function testОплатаПродлеваетСрокОтТекущего(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, [
            'title' => 'Кофемашина',
            'expires_at' => utcStamp(nowUtc()->add(new \DateInterval('P10D'))),
        ]);

        $result = classifiedsPromotionCreate('EXTEND', $listingId, self::user($sellerId));
        self::assertTrue($result['ok'], $result['error']);

        commerceSetOrderStatus($result['order_id'], 'PAID');

        $expires = fromDb((string) value(
            'SELECT expires_at FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [$listingId]
        ));
        self::assertNotNull($expires);
        // Срок сдвигается от текущего конца публикации, а не от момента оплаты.
        self::assertSame(
            CLASSIFIEDS_LISTING_DAYS,
            (int) round(($expires->getTimestamp() - (nowUtc()->add(new \DateInterval('P10D')))->getTimestamp()) / 86400)
        );
    }

    public function testОтменённыйЗаказЗакрываетПродвижение(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, ['title' => 'Кофемашина']);

        $result = classifiedsPromotionCreate('BUMP', $listingId, self::user($sellerId));
        self::assertTrue($result['ok'], $result['error']);

        $cancelled = commerceSetOrderStatus($result['order_id'], 'CANCELLED');
        self::assertTrue($cancelled['ok'], $cancelled['error']);

        $promo = one(
            'SELECT status, applied_at FROM ' . t('classifieds_promotion') . ' WHERE listing_id = ?',
            [$listingId]
        );
        self::assertNotNull($promo);
        self::assertSame('CANCELLED', (string) $promo['status']);
        self::assertNull($promo['applied_at']);

        $listing = classifiedsListingById($listingId);
        self::assertNotNull($listing);
        self::assertNull($listing['boosted_at']);
    }

    public function testЧужоеЧерновикИИстёкшееОтказ(): void
    {
        $sellerId = self::makeSeller();
        $otherId = self::makeSeller('other@example.ru');
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, ['title' => 'Кофемашина']);
        $draftId = self::makeListing($sellerId, $categoryId, [
            'title' => 'Черновик',
            'status' => 'DRAFT',
        ]);
        $expiredId = self::makeListing($sellerId, $categoryId, [
            'title' => 'Истёкшее',
            'expires_at' => utcStamp(nowUtc()->sub(new \DateInterval('P1D'))),
        ]);

        self::assertFalse(classifiedsPromotionCreate('BUMP', $listingId, self::user($otherId))['ok']);
        self::assertFalse(classifiedsPromotionCreate('BUMP', $draftId, self::user($sellerId))['ok']);
        self::assertFalse(classifiedsPromotionCreate('BUMP', $expiredId, self::user($sellerId))['ok']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_promotion')));

        // Продление истёкшего объявления разрешено: срок возвращает объявление в ленту.
        self::assertTrue(classifiedsPromotionCreate('EXTEND', $expiredId, self::user($sellerId))['ok']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_promotion')));

        // До оплаты срок остаётся прежним: услуга ждёт денег.
        $expired = classifiedsListingById($expiredId);
        self::assertNotNull($expired);
        self::assertTrue(classifiedsListingIsExpired($expired));
    }

    public function testПовторноеВыделениеОтказ(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, ['title' => 'Кофемашина']);

        $first = classifiedsPromotionCreate('FEATURED', $listingId, self::user($sellerId));
        self::assertTrue($first['ok'], $first['error']);
        commerceSetOrderStatus($first['order_id'], 'PAID');

        $second = classifiedsPromotionCreate('FEATURED', $listingId, self::user($sellerId));
        self::assertFalse($second['ok']);
        self::assertStringContainsString('уже выделено', $second['error']);
    }

    public function testБезМагазинаУслугиНедоступны(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($sellerId, $categoryId, ['title' => 'Кофемашина']);

        setAppEnabled('commerce', false);

        try {
            $result = classifiedsPromotionCreate('BUMP', $listingId, self::user($sellerId));
            self::assertFalse($result['ok']);
            self::assertSame([], classifiedsPromotionServices());
        } finally {
            setAppEnabled('commerce', true);
        }
    }

    public function testПоднятоеОбъявлениеИдётВышеСвежего(): void
    {
        $sellerId = self::makeSeller();
        $categoryId = self::makeCategory('tehnika', 'Техника');
        $older = self::makeListing($sellerId, $categoryId, [
            'title' => 'Старое',
            'slug' => 'staroe',
            'created_at' => utcStamp(nowUtc()->sub(new \DateInterval('P5D'))),
        ]);
        $newer = self::makeListing($sellerId, $categoryId, [
            'title' => 'Свежее',
            'slug' => 'svezhee',
            'created_at' => utcStamp(nowUtc()->sub(new \DateInterval('P1D'))),
        ]);

        run(
            'UPDATE ' . t('classifieds_listing') . ' SET boosted_at = ? WHERE id = ?',
            [utcStamp(nowUtc()), $older]
        );

        $feed = classifiedsListingFeed([], 1);
        self::assertSame(2, $feed['total']);
        self::assertSame($older, (string) $feed['rows'][0]['id']);
        self::assertSame($newer, (string) $feed['rows'][1]['id']);
    }

    /**
     * Продавец строкой: те же поля, что отдаёт кабинет (id, e-mail, имя).
     *
     * @return array<string, string>
     */
    private static function user(string $userId): array
    {
        $row = one('SELECT id, email, name FROM ' . t('user') . ' WHERE id = ?', [$userId]);
        self::assertNotNull($row, 'Продавец не найден: ' . $userId);

        return [
            'id' => (string) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
        ];
    }
}
