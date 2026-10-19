<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * Варианты товара: уникальный SKU, цены и остаток; карточка и удаление с гвардом
 * по заказам.
 */
final class CommerceVariantTest extends CommerceDbTestCase
{
    /**
     * @param array<string, string> $overrides
     * @return array{title: string, sku: string, price_retail: string, price_promo: string, stock: string}
     */
    private static function values(array $overrides = []): array
    {
        return array_merge([
            'title' => '20 Вт, чёрная',
            'sku' => 'SPK-20',
            'price_retail' => '1290',
            'price_promo' => '',
            'stock' => '3',
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Валидация
    // -----------------------------------------------------------------------

    public function testПустыеПоляРежутся(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        $result = commerceValidateVariant($product, ['stock' => '2']);

        self::assertArrayHasKey('title', $result['errors']);
        self::assertArrayHasKey('sku', $result['errors']);
        self::assertArrayHasKey('price_retail', $result['errors']);
        self::assertArrayNotHasKey('stock', $result['errors']);
    }

    public function testАртикулВариантаCAParentSKUStrict(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        $result = commerceValidateVariant($product, ['title' => 'Тонкая', 'sku' => 'SPK-1', 'price_retail' => '100', 'stock' => '1']);

        self::assertSame('Артикул варианта совпадает с артикулом товара — они должны различаться.', $result['errors']['sku']);
    }

    public function testАртикулВариантаЗанятДругимТоваром(): void
    {
        self::makeProduct(['slug' => 'drayver', 'sku' => 'DRV-1', 'title' => 'Драйвер']);
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        $result = commerceValidateVariant($product, ['title' => 'Тонкая', 'sku' => 'DRV-1', 'price_retail' => '100', 'stock' => '1']);

        self::assertSame('Такой артикул уже занят товаром или другим вариантом.', $result['errors']['sku']);
    }

    public function testСвойАртикулНеМешаетПравкеСебе(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        $ok = commerceCreateVariant($product, self::values());
        self::assertTrue($ok['ok'], $ok['error']);

        $variants = commerceVariants($product);
        $fresh = commerceValidateVariant($product, self::values(['title' => '20 Вт, чёрная (правка)']));

        self::assertSame([], $fresh['errors']);
        self::assertSame('SPK-20', (string) $variants[0]['sku']);
    }

    public function testЦеныРежутсяФорматомИПромоНиже(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        $bad = commerceValidateVariant($product, ['title' => 'Т', 'sku' => 'V-1', 'price_retail' => '12,345', 'stock' => '0']);
        self::assertArrayHasKey('price_retail', $bad['errors']);

        $promoAbove = commerceValidateVariant($product, ['title' => 'Т', 'sku' => 'V-1', 'price_retail' => '100', 'price_promo' => '150', 'stock' => '0']);
        self::assertArrayHasKey('price_promo', $promoAbove['errors']);

        $spacesOk = commerceValidateVariant($product, ['title' => 'Т', 'sku' => 'V-1', 'price_retail' => '1 290,50', 'stock' => '0']);
        self::assertSame([], $spacesOk['errors']);
    }

    public function testОстатокТолькоЦелое(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        $result = commerceValidateVariant($product, ['title' => 'Т', 'sku' => 'V-1', 'price_retail' => '100', 'stock' => '2.5']);

        self::assertArrayHasKey('stock', $result['errors']);
    }

    // -----------------------------------------------------------------------
    // Жизненный цикл
    // -----------------------------------------------------------------------

    public function testСозданиеВариантовСтавитПозиции(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        commerceCreateVariant($product, self::values(['sku' => 'SPK-20']));
        commerceCreateVariant($product, self::values(['title' => '30 Вт, белая', 'sku' => 'SPK-30']));

        $variants = commerceVariants($product);
        self::assertCount(2, $variants);
        self::assertSame('SPK-20', (string) $variants[0]['sku']);
        self::assertSame('SPK-30', (string) $variants[1]['sku']);
    }

    public function testПравкаВариантаМеняетПоля(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        commerceCreateVariant($product, self::values());
        $id = (string) commerceVariants($product)[0]['id'];

        $result = commerceUpdateVariant($id, self::values(['title' => 'Чёрная 20 Вт', 'price_retail' => '1 500', 'price_promo' => '1 199', 'stock' => '7']));

        self::assertTrue($result['ok'], $result['error']);
        $row = commerceVariantById($id);
        self::assertSame('Чёрная 20 Вт', (string) $row['title']);
        self::assertSame(7, (int) $row['stock']);
        self::assertEqualsWithDelta(1199.0, (float) $row['price_promo'], 0.001);
    }

    public function testНесуществующийВариантОтказывается(): void
    {
        $result = commerceUpdateVariant('A' . str_repeat('0', 23), self::values());
        self::assertFalse($result['ok']);
        self::assertStringContainsString('уже нет', $result['error']);
    }

    public function testУдалениеВариантаСвободноРаботает(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        commerceCreateVariant($product, self::values());
        $id = (string) commerceVariants($product)[0]['id'];

        $result = commerceDeleteVariant($id);

        self::assertArrayHasKey('ok', $result);
        self::assertSame('variant_deleted', $result['notice']);
        self::assertNull(commerceVariantById($id));
    }

    public function testВариантВЗаказеНеУдаляется(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        commerceCreateVariant($product, self::values());
        $id = (string) commerceVariants($product)[0]['id'];

        $order = newId();
        insert('commerce_order', [
            'id' => $order,
            'customer_name' => 'Иван',
            'customer_email' => 'ivan@example.com',
            'currency' => 'RUB',
            'total' => 1290.0,
            'status' => 'NEW',
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);
        insert('commerce_order_item', [
            'id' => newId(),
            'order_id' => $order,
            'product_id' => $product,
            'variant_id' => $id,
            'sku' => 'SPK-20',
            'title' => 'Колонка — 20 Вт, чёрная',
            'currency' => 'RUB',
            'unit_price' => 1290.0,
            'qty' => 1,
        ]);

        $result = commerceDeleteVariant($id);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('заказах', $result['error']);
        self::assertNotNull(commerceVariantById($id));
    }

    // -----------------------------------------------------------------------
    // Карточка
    // -----------------------------------------------------------------------

    public function testКарточкаВидитПромоИОстатокВарианта(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        commerceCreateVariant($product, self::values(['price_retail' => '1000', 'price_promo' => '899', 'stock' => '0']));
        commerceCreateVariant($product, self::values(['title' => '30 Вт', 'sku' => 'SPK-30', 'price_retail' => '2000', 'stock' => '2']));

        $options = commerceVariantOptions($product);
        self::assertSame('RUB', (string) $options[0]['currency']);

        self::assertSame(89900, (int) $options[0]['price_cents']);
        self::assertSame(100000, (int) $options[0]['price_retail_cents']);
        self::assertFalse((bool) $options[0]['in_stock']);
        self::assertSame(200000, (int) $options[1]['price_cents']);
        self::assertSame(0, (int) $options[1]['price_retail_cents']);
        self::assertTrue((bool) $options[1]['in_stock']);
        self::assertTrue(commerceHasVariants($product));
        self::assertFalse(commerceHasVariants((string) self::makeProduct(['slug' => 'splk', 'sku' => 'PLK-1'])));
    }
}
