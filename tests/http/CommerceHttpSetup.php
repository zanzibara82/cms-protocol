<?php

declare(strict_types=1);

namespace Cms\Tests\http;

/**
 * Данные витрины для HTTP-набора: один товар в продаже с промо-ценой, черновик и рубрика.
 * Подготовка идёт один раз, между тестами состояние восстанавливается — остаток, видимость
 * рубрики и строки заказов.
 */
final class CommerceHttpSetup
{
    public static string $productId = '';
    public static string $draftId = '';

    public static function install(): void
    {
        self::dropTables();

        $result = installApp('commerce');
        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Магазин» не установился: ' . (string) ($result['error'] ?? ''));
        }

        $moment = utcStamp(nowUtc());
        $categoryId = newId();
        insert('commerce_category', [
            'id' => $categoryId,
            'slug' => 'tehnika',
            'title' => 'Техника',
            'order_index' => 0,
            'visible' => 1,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
        insert('commerce_category', [
            'id' => newId(),
            'slug' => 'drobnost',
            'title' => 'Дробности',
            'order_index' => 1,
            'visible' => 1,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        self::$productId = newId();
        insert('commerce_product', self::productRow(self::$productId, [
            'slug' => 'kofemashina',
            'sku' => 'COFFEE-1',
            'title' => 'Кофемашина',
            'lead' => 'Готовит эспрессо и капучино.',
        ], $moment));
        self::$draftId = newId();
        insert('commerce_product', self::productRow(self::$draftId, [
            'slug' => 'draft-tovar',
            'sku' => 'DRAFT-1',
            'title' => 'Черновой товар',
            'lead' => 'Черновик — его не видит витрина.',
        ], $moment, ['status' => 'DRAFT']));

        insert('commerce_product_category', ['product_id' => self::$productId, 'category_id' => $categoryId]);
    }

    /**
     * Состояние между тестами: остаток, видимость рубрик, строки заказов.
     */
    public static function prepare(): void
    {
        foreach (['commerce_order_item', 'commerce_order'] as $table) {
            run('DELETE FROM ' . t($table));
        }
        run('UPDATE ' . t('commerce_product') . ' SET stock = 35 WHERE id = ?', [self::$productId]);
        run('UPDATE ' . t('commerce_category') . ' SET visible = 1');
    }

    public static function hideCategory(): void
    {
        run('UPDATE ' . t('commerce_category') . " SET visible = 0 WHERE slug = 'drobnost'");
    }

    public static function stockOf(): int
    {
        return (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [self::$productId]);
    }

    public static function cleanup(): void
    {
        self::dropTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'commerce'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'commerce'");
    }

    private static function dropTables(): void
    {
        foreach ([
            'commerce_order_item',
            'commerce_order',
            'commerce_product_category',
            'commerce_product',
            'commerce_category',
        ] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
    }

    /**
     * @param array<string, string> $identity
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function productRow(string $id, array $identity, string $moment, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'slug' => $identity['slug'],
            'sku' => $identity['sku'],
            'title' => $identity['title'],
            'lead_text' => $identity['lead'],
            'cover' => null,
            'body' => json_encode([['title' => '', 'text' => 'Аппарат для дома и офиса.']], JSON_UNESCAPED_UNICODE),
            'brand' => 'HomeMade',
            'barcode' => null,
            'price_retail' => 2800.00,
            'price_promo' => 2240.00,
            'price_cost' => 1500.00,
            'currency' => 'RUB',
            'stock' => 35,
            'status' => 'PUBLISHED',
            'seo_title' => null,
            'seo_description' => null,
            'noindex' => 0,
            'created_at' => $moment,
            'updated_at' => $moment,
        ], $overrides);
    }

    private static function fail(string $message): void
    {
        throw new \RuntimeException($message);
    }
}
