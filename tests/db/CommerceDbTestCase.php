<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Общая подставка тестов магазина: установка раздела, чистка таблиц и фабрики записей.
 * Раздел проверяют два набора (каталог и заказы), поэтому подготовка живёт здесь.
 */
abstract class CommerceDbTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropCommerceTables();

        $result = installApp('commerce');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Магазин» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropCommerceTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'commerce'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'commerce'");
    }

    protected function setUp(): void
    {
        self::clearCommerce();
    }

    protected static function dropCommerceTables(): void
    {
        // Порядок важен: дочерние таблицы снимаются раньше родителей, иначе MySQL
        // не даст уронить таблицу, на которую кто-то ссылается.
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
    }

    protected static function clearCommerce(): void
    {
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
            run('DELETE FROM ' . t($table));
        }
    }

    /**
     * Рубрика вставляется строкой: тест чтения обязан класть ровно то состояние, которое
     * проверяет, а не то, что собрал бы createCategory().
     */
    protected static function makeCategory(string $slug, string $title, int $orderIndex = 0): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('commerce_category', [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'order_index' => $orderIndex,
            'visible' => 1,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }

    /**
     * Товар строки в базу с опциями: статус, остаток, цены.
     *
     * @param array<string, mixed> $overrides
     */
    protected static function makeProduct(array $overrides = []): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('commerce_product', array_merge([
            'id' => $id,
            'slug' => (string) $overrides['slug'],
            'sku' => (string) $overrides['sku'],
            'title' => (string) ($overrides['title'] ?? 'Товар'),
            'lead_text' => $overrides['lead_text'] ?? null,
            'cover' => null,
            'body' => $overrides['body'] ?? null,
            'brand' => $overrides['brand'] ?? null,
            'barcode' => null,
            'price_retail' => (float) ($overrides['price_retail'] ?? 100.00),
            'price_promo' => $overrides['price_promo'] ?? null,
            'price_cost' => null,
            'currency' => (string) ($overrides['currency'] ?? 'RUB'),
            'stock' => (int) ($overrides['stock'] ?? 10),
            'status' => (string) ($overrides['status'] ?? 'PUBLISHED'),
            'seo_title' => null,
            'seo_description' => null,
            'noindex' => 0,
            'created_at' => $moment,
            'updated_at' => $moment,
        ], $overrides));

        return $id;
    }

    /** Связка товар-рубрика: попадает в базу без транзакции — это тест. */
    protected static function attach(string $productId, string $categoryId): void
    {
        insert('commerce_product_category', ['product_id' => $productId, 'category_id' => $categoryId]);
    }
}
