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
     * Состояние между тестами: остаток, видимость рубрик, строки заказов, наборы M2.
     * Варианты и характеристики чистятся: каждая сделка ставит своё состояние.
     */
    public static function prepare(): void
    {
        foreach (['commerce_order_item', 'commerce_order', 'commerce_variant', 'commerce_product_attribute'] as $table) {
            run('DELETE FROM ' . t($table));
        }
        run('DELETE FROM ' . t('commerce_attribute_choice'));
        run('DELETE FROM ' . t('commerce_attribute'));

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

    /**
     * Заказ фикстурой: строка заказа с честным номером AUTO_INCREMENT, идентификатор
     * возвращается вместе с номером — POST-действия админки работают по id.
     *
     * @return array{id: string, number: int}
     */
    public static function addOrder(string $email = 'boris@example.dev', string $status = 'NEW'): array
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('commerce_order', [
            'id' => $id,
            'customer_name' => 'Борис Колосово',
            'customer_email' => $email,
            'customer_phone' => null,
            'address' => null,
            'comment' => null,
            'currency' => 'RUB',
            'total' => 22.40,
            'status' => $status,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        $number = (int) value('SELECT number FROM ' . t('commerce_order') . ' WHERE id = ?', [$id]);

        return ['id' => $id, 'number' => $number];
    }

    public static function cleanup(): void
    {
        self::dropTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'commerce'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'commerce'");
    }

    private static function dropTables(): void
    {
        // Дочерние таблицы снимаются раньше родителей: MySQL не даст уронить таблицу,
        // на которую ссылается Constraint другой таблицы.
        foreach ([
            'commerce_order_item',
            'commerce_order',
            'commerce_variant',
            'commerce_product_attribute',
            'commerce_attribute_choice',
            'commerce_attribute',
            'commerce_product_category',
            'commerce_product',
            'commerce_category',
        ] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
    }

    /** Строка варианта для фикстур карточки: своя цена, SKU и остаток. */
    public static function addVariant(string $productId, string $sku, string $title, float $priceRetail, int $stock, ?float $pricePromo = null, int $position = 0): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('commerce_variant', [
            'id' => $id,
            'product_id' => $productId,
            'sku' => $sku,
            'title' => $title,
            'price_retail' => $priceRetail,
            'price_promo' => $pricePromo,
            'stock' => $stock,
            'position' => $position,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }

    /** Характеристика рубрики: слаг — ключ фильтра витрины (?attr_{slug}=…). */
    public static function addAttribute(string $categoryId, string $slug, string $label, string $type, ?string $unit = null): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('commerce_attribute', [
            'id' => $id,
            'category_id' => $categoryId,
            'slug' => $slug,
            'label' => $label,
            'type' => $type,
            'unit' => $unit,
            'order_index' => 0,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }

    /** Значение CHOICE в справочнике характеристики. */
    public static function addChoice(string $attributeId, string $value): void
    {
        insert('commerce_attribute_choice', [
            'id' => newId(),
            'attribute_id' => $attributeId,
            'value' => $value,
            'order_index' => 0,
        ]);
    }

    /** Значение характеристики на товаре: строка идёт на экран, число — в фильтр. */
    public static function addProductValue(string $productId, string $attributeId, string $value, ?float $numeric = null): void
    {
        insert('commerce_product_attribute', [
            'product_id' => $productId,
            'attribute_id' => $attributeId,
            'value' => $value,
            'value_num' => $numeric,
        ]);
    }

    /** @return string id рубрики магазина по слагу */
    public static function categoryId(string $slug): string
    {
        /** @var array<string, mixed>|null */
        $row = one('SELECT id FROM ' . t('commerce_category') . ' WHERE slug = ?', [$slug]);

        return $row === null ? '' : (string) $row['id'];
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
