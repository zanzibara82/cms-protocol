<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use PHPUnit\Framework\Attributes\Test;

/**
 * Объявления доски: проверка формы, статусы, выдача витрины и правила владения.
 */
final class ClassifiedsListingTest extends ClassifiedsDbTestCase
{
    /** @var list<array{id: string, url: string, secret: string, event: string, payload: string}> */
    private static array $delivered = [];

    /** @var array<string, list<array{fn: callable, priority: int, kind: string, order: int}>> */
    private static array $savedHooks = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::registerTransport();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$delivered = [];
    }

    protected function tearDown(): void
    {
        // Реестр подписок восстанавливается целиком и транспорт ставится заново: порядок
        // тестов не должен зависеть от того, что набор натворил с подписками.
        $registry = &hookRegistry();
        $registry = self::$savedHooks;
        self::registerTransport();
    }

    /**
     * Транспорт подменяется подпиской: собранное остаётся до конца набора. Живой curl
     * на тестовом стенде не поднимается — риск ухода наружу исчезает.
     */
    private static function registerTransport(): void
    {
        $registry = &hookRegistry();

        if (!isset($registry['classifieds_webhook_transport'])) {
            self::$savedHooks = $registry;
        }

        addFilter('classifieds_webhook_transport', static function (mixed $value, array $receiver, string $event, string $payload): \Closure {
            return static function (array $receiver, string $event, string $payload): bool {
                self::$delivered[] = [
                    'id' => (string) $receiver['id'],
                    'url' => (string) $receiver['url'],
                    'secret' => (string) $receiver['secret'],
                    'event' => $event,
                    'payload' => $payload,
                ];

                return true;
            };
        });
    }

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

    #[Test]
    public function plainEditDoesNotRenewExpiredListing(): void
    {
        // Дефект «бесплатное продление»: правка истёкшего объявления ставила свежий срок —
        // владелец продлевал размещение бесплатно, хотя продление в разделе платное
        // (EXTEND) или идёт явным действием «Опубликовать». Правка обязана сохранить
        // прежний (прошедший) срок: объявление остаётся истёкшим.
        $seller = self::makeSeller();
        $category = self::makeCategory('velo', 'Велосипеды');
        $past = utcStamp(nowUtc()->modify('-1 day'));
        $id = self::makeListing($seller, $category, [
            'slug' => 'istekshee',
            'status' => 'PUBLISHED',
            'expires_at' => $past,
        ]);

        $check = classifiedsValidateListing(self::post([
            'title' => 'Правка истёкшего',
            'slug' => 'istekshee',
            'category' => $category,
            'status' => 'PUBLISHED',
        ]), $id);
        self::assertSame([], $check['errors']);

        $updated = classifiedsUpdateListing($id, $check['values']);
        self::assertTrue($updated['ok'], (string) $updated['error']);

        $row = classifiedsListingById($id);
        self::assertNotNull($row);
        self::assertSame($past, (string) $row['expires_at'], 'Правка не продлевает истёкшее бесплатно');
        self::assertSame('PUBLISHED', $row['status']);

        // Явное действие владельца продлевает: новый срок ставится только кнопкой.
        $republished = classifiedsListingSetStatus($id, $seller, 'PUBLISHED');
        self::assertTrue($republished['ok'], (string) $republished['error']);

        $renewed = classifiedsListingById($id);
        self::assertNotNull($renewed);
        self::assertNotNull($renewed['expires_at'], 'Явная публикация ставит новый срок');
        self::assertNotSame($past, (string) $renewed['expires_at']);
    }

    #[Test]
    public function updateWritesRowAttributesAndGalleryTogether(): void
    {
        // Транзакция правки: строка, характеристики и галерея сохраняются одним вызовом.
        // Атомарность гонки в тесте не поднять — проверяется, что все три части записи
        // доезжают при одном сохранении и читаются обратно.
        $seller = self::makeSeller();
        $category = self::makeCategory('velo', 'Велосипеды');
        $attributeId = self::makeAttribute($category, 'obhod-ves', 'Размер рамы', 'text');
        $id = self::makeListing($seller, $category, ['slug' => 'velosiped']);

        $check = classifiedsValidateListing(self::post([
            'title' => 'Велосипед с характеристикой',
            'slug' => 'velosiped',
            'category' => $category,
            'status' => 'PUBLISHED',
        ]), $id);
        self::assertSame([], $check['errors']);

        $values = $check['values'];
        $values['attributes'] = [$attributeId => ['value_text' => '17 дюймов', 'value_number' => null]];
        $values['gallery'] = ['/uploads/images/velosiped-1.jpg'];

        $updated = classifiedsUpdateListing($id, $values);
        self::assertTrue($updated['ok'], (string) $updated['error']);

        $row = classifiedsListingById($id);
        self::assertNotNull($row);
        self::assertSame('Велосипед с характеристикой', (string) $row['title']);

        $attribute = one(
            'SELECT value_text FROM ' . t('classifieds_listing_attribute') . ' WHERE listing_id = ? AND attribute_id = ?',
            [$id, $attributeId]
        );
        self::assertIsArray($attribute, 'Характеристика записана той же правкой');
        self::assertSame('17 дюймов', (string) $attribute['value_text']);

        $gallery = classifiedsListingImages($id);
        self::assertCount(1, $gallery, 'Галерея записана той же правкой');
        self::assertSame('/uploads/images/velosiped-1.jpg', (string) $gallery[0]['href']);
    }

    #[Test]
    public function deletingPublishedListingSendsHiddenEventFirst(): void
    {
        // Дефект «молчаливое удаление»: строка стиралась без события, и подписчик замечал
        // исчезновение только молчанием витрины. Удаление опубликованного обязано сначала
        // послать «listing.hidden»; черновик удаляется без событий.
        $seller = self::makeSeller('prodavec-hidden@example.ru');
        $category = self::makeCategory('velo', 'Велосипеды');
        $check = classifiedsValidateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-xyz',
            'event' => 'listing.hidden',
            'active' => 'on',
        ]);
        self::assertSame([], $check['errors']);
        classifiedsCreateWebhook($check['values']);

        $published = self::makeListing($seller, $category, ['slug' => 'udalyaemyj-publikum', 'status' => 'PUBLISHED']);
        self::assertNotNull(classifiedsListingById($published));

        $deleted = classifiedsListingDelete($published, $seller);
        self::assertTrue($deleted['ok'], (string) $deleted['error']);
        self::assertNull(classifiedsListingById($published), 'Строка удалена после события');

        $draft = self::makeListing($seller, $category, ['slug' => 'udalyaemyj-chernovik', 'status' => 'DRAFT']);
        self::assertTrue(classifiedsListingDelete($draft, $seller)['ok']);

        // Событие собирается в руки транспорта: одно снятие уходит активному приёмнику
        // именно на опубликованную строку, черновик удаляется молча. Тело события несёт
        // снимок строки — статус PUBLISHED доказывает, что данные взяты ДО стирания.
        self::assertCount(1, self::$delivered, 'Снятие с публикации ровно одно — на опубликованное удаление');
        $letter = self::$delivered[0];
        self::assertSame('listing.hidden', $letter['event']);

        $payload = (array) json_decode($letter['payload'], true);
        self::assertSame($published, (string) ($payload['listing']['id'] ?? ''), 'Событие несёт снимок удаляемой строки');
        self::assertSame('PUBLISHED', (string) ($payload['listing']['status'] ?? ''), 'Статус в событии — на момент удаления');
        self::assertSame('udalyaemyj-publikum', (string) ($payload['listing']['slug'] ?? ''));
    }
}
