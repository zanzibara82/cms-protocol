<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * Поставщики (M5): справочник со связью вариантов. Удаление с привязанными
 * вариантами запрещено — прайс-лист со скоупом не должен терять свой смысл.
 */
final class CommerceSupplierTest extends CommerceDbTestCase
{
    /**
     * @param array<string, string> $overrides
     * @return array{title: string, note: string}
     */
    private static function values(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Техноприбор',
            'note' => 'Пн–Пт с 9 до 18.',
        ], $overrides);
    }

    public function testПустоеНазваниеРежется(): void
    {
        $check = commerceValidateSupplier(['note' => 'Есть примечание']);

        self::assertArrayHasKey('title', $check['errors']);
        self::assertArrayNotHasKey('note', $check['errors']);
    }

    public function testДлинноеПримечаниеРежется(): void
    {
        $check = commerceValidateSupplier(['title' => 'Поставщик', 'note' => str_repeat('ы', COMMERCE_SUPPLIER_NOTE_MAX + 1)]);

        self::assertArrayHasKey('note', $check['errors']);
    }

    public function testПоставщикСоздаётсяИПравится(): void
    {
        $saved = commerceCreateSupplier(self::values());
        self::assertTrue($saved['ok'], $saved['error']);
        self::assertSame('supplier_saved', $saved['notice']);

        $rows = commerceSuppliers();
        self::assertCount(1, $rows);
        $id = (string) $rows[0]['id'];
        self::assertSame('Техноприбор', (string) $rows[0]['title']);
        self::assertSame('Пн–Пт с 9 до 18.', (string) $rows[0]['note']);

        $update = commerceUpdateSupplier($id, self::values(['title' => 'Техноприбор-Юг', 'note' => '']));
        self::assertTrue($update['ok'], $update['error']);

        $after = commerceSupplierById($id);
        self::assertIsArray($after);
        self::assertSame('Техноприбор-Юг', (string) $after['title']);
        self::assertNull($after['note']);
    }

    public function testПравкаНесуществующегоОтказывается(): void
    {
        $update = commerceUpdateSupplier('ZZZZZZZZ', self::values());

        self::assertTrue(!$update['ok']);
        self::assertStringContainsString('уже нет', $update['error']);
    }

    public function testУдалениеСВариантамиЗапрещено(): void
    {
        $saved = commerceCreateSupplier(self::values());
        self::assertTrue($saved['ok']);
        $id = (string) commerceSuppliers()[0]['id'];

        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        $values = ['title' => '20 Вт', 'sku' => 'SPK-20', 'price_retail' => '1290', 'price_promo' => '', 'stock' => '3', 'supplier' => $id];
        $variant = commerceCreateVariant($product, $values);
        self::assertTrue($variant['ok'], $variant['error']);

        $deleted = commerceDeleteSupplier($id);
        self::assertTrue(!$deleted['ok']);
        self::assertStringContainsString('есть варианты', $deleted['error']);
    }

    public function testУдалениеПустойСтрокиПроходит(): void
    {
        $saved = commerceCreateSupplier(self::values());
        self::assertTrue($saved['ok']);
        $id = (string) commerceSuppliers()[0]['id'];

        $deleted = commerceDeleteSupplier($id);

        self::assertSame('supplier_deleted', $deleted['notice']);
        self::assertSame([], commerceSuppliers());
    }

    public function testВариантНоситПоставщика(): void
    {
        $saved = commerceCreateSupplier(self::values());
        self::assertTrue($saved['ok']);
        $supplierId = (string) commerceSuppliers()[0]['id'];

        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        $withSupplier = commerceValidateVariant($product, [
            'title' => 'Тонкая',
            'sku' => 'SPK-20',
            'price_retail' => '1290',
            'stock' => '2',
            'supplier' => $supplierId,
        ]);
        self::assertSame([], $withSupplier['errors']);

        $saved = commerceCreateVariant($product, $withSupplier['values']);
        self::assertTrue($saved['ok'], $saved['error']);

        $rows = commerceVariants($product);
        self::assertNotSame([], $rows);
        self::assertSame($supplierId, (string) $rows[0]['supplier_id']);

        $done = commerceUpdateVariant((string) $rows[0]['id'], [
            'title' => 'Тонкая',
            'sku' => 'SPK-20',
            'price_retail' => '1290',
            'price_promo' => '',
            'stock' => '2',
            'supplier' => '',
        ]);
        self::assertTrue($done['ok'], $done['error']);
        self::assertNull(commerceVariantById((string) $rows[0]['id'])['supplier_id']);
    }

    public function testНеизвестныйПоставщикРежется(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        $check = commerceValidateVariant($product, ['title' => 'Т', 'sku' => 'V-1', 'price_retail' => '100', 'stock' => '0', 'supplier' => 'ZZZZ']);

        self::assertArrayHasKey('supplier', $check['errors']);
    }
}
