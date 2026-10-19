<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * Прайс-листы (M5): экспорт CSV с BOM и защитой колонок, разбор формата с запятой
 * и точкой с запятой, правила промо и скоуп поставщика.
 */
final class CommercePricebookTest extends CommerceDbTestCase
{
    private const BOM = "\xEF\xBB\xBF";

    // -----------------------------------------------------------------------
    // Экспорт
    // -----------------------------------------------------------------------

    public function testЭкспортЕдетBOMЗаголовкомИКавычками(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1', 'title' => 'Колонка "Круглая"']);
        commerceCreateVariant($product, [
            'title' => '20 Вт, чёрная',
            'sku' => 'SPK-20',
            'price_retail' => '1290,50',
            'price_promo' => '990',
            'stock' => '3',
            'supplier' => '',
        ]);

        $csv = commercePricebookCsv(null);

        self::assertStringStartsWith(self::BOM, $csv);
        self::assertStringContainsString('sku;товар;вариант;цена;промо;остаток', $csv);
        self::assertStringContainsString('"Колонка ""Круглая"""', $csv);
        self::assertStringContainsString('"1 290,50"', $csv);
        self::assertStringContainsString('"990,00"', $csv);
        self::assertTrue(str_ends_with($csv, "\r\n"));
    }

    public function testЭкспортСкоупаПоПоставщику(): void
    {
        commerceCreateSupplier(['title' => 'Восток', 'note' => '']);
        $supplierId = (string) commerceSuppliers()[0]['id'];
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        commerceCreateVariant($product, ['title' => 'Тёплая', 'sku' => 'SPK-21', 'price_retail' => '1500', 'price_promo' => '', 'stock' => '1', 'supplier' => $supplierId]);
        commerceCreateVariant($product, ['title' => 'Голая', 'sku' => 'SPK-22', 'price_retail' => '1600', 'price_promo' => '', 'stock' => '0', 'supplier' => '']);

        $own = commercePricebookRows($supplierId);
        self::assertCount(1, $own);
        self::assertSame('SPK-21', $own[0]['sku']);

        $all = commercePricebookRows(null);
        self::assertCount(2, $all);
    }

    // -----------------------------------------------------------------------
    // Разбор ячеек и правила строк
    // -----------------------------------------------------------------------

    public function testЯчейкиЧитаютЗапятуюИПробелы(): void
    {
        self::assertSame(1290.5, commercePricebookCell('1 290,50')['num']);
        self::assertSame(1290.5, commercePricebookCell("1\xC2\xA0290,50")['num']);
        self::assertSame(0.0, commercePricebookCell('0')['num']);
        self::assertNull(commercePricebookCell('  ')['num']);
        self::assertTrue(commercePricebookCell('  ')['ok']);
        self::assertFalse(commercePricebookCell('1e3')['ok']);
        self::assertFalse(commercePricebookCell('12,345.50')['ok']);
        self::assertFalse(commercePricebookCell('-5')['ok']);
    }

    public function testОстатокДробьИЗнакРежутся(): void
    {
        self::assertFalse(commercePricebookStockOk(2.5));
        self::assertFalse(commercePricebookStockOk(-1.0));
        self::assertFalse(commercePricebookStockOk((float) (COMMERCE_QTY_MAX + 1)));
        self::assertTrue(commercePricebookStockOk(null));
    }

    public function testСтрокаБезЗаполненныхЯчеекРежется(): void
    {
        $plan = commercePricebookPlanRow('SPK-20', '', '', '');

        self::assertFalse($plan['ok']);
        self::assertStringContainsString('заполнена', $plan['error']);
    }

    public function testПромоВышеЦеныСтрокиРежется(): void
    {
        $plan = commercePricebookPlanRow('SPK-20', '1290', '1500', '');

        self::assertFalse($plan['ok']);
        self::assertStringContainsString('ниже', $plan['error']);
    }

    public function testНольПромоВВидеСнятия(): void
    {
        $plan = commercePricebookPlanRow('SPK-20', '', '0', '');

        self::assertTrue($plan['ok']);
        self::assertTrue($plan['promo_set']);
        self::assertSame(0.0, $plan['promo']);
    }

    // -----------------------------------------------------------------------
    // Импорт
    // -----------------------------------------------------------------------

    public function testИмпортЗаголовокИСтроки(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        $saved = commerceCreateVariant($product, ['title' => '20 Вт', 'sku' => 'SPK-20', 'price_retail' => '1290', 'price_promo' => '', 'stock' => '5', 'supplier' => '']);
        self::assertTrue($saved['ok'], $saved['error']);
        $variantId = self::variantId('SPK-20');

        $csv = self::BOM
            . "sku;товар;вариант;цена;промо;остаток\r\n"
            . "SPK-20;Колонка;20 Вт;1 350,50;1 000;7\r\n"
            . "SPK-40;Чужой;SKU;100;0;0\r\n";

        $result = self::applyCsv($csv);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(1, $result['report']['applied']);
        self::assertSame(1, $result['report']['skipped']);
        self::assertCount(1, $result['report']['skips']);
        self::assertSame('SPK-40', $result['report']['skips'][0]['sku']);
        self::assertStringContainsString('Такого артикула нет', $result['report']['skips'][0]['reason']);

        $row = commerceVariantById($variantId);
        self::assertIsArray($row);
        self::assertSame(1350.5, (float) $row['price_retail']);
        self::assertSame(1000.0, (float) $row['price_promo']);
        self::assertSame(7, (int) $row['stock']);
    }

    public function testИмпортПромоСнимаетсяНулёмИЦеной(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        $saved = commerceCreateVariant($product, ['title' => '20 Вт', 'sku' => 'SPK-20', 'price_retail' => '1290', 'price_promo' => '990', 'stock' => '5', 'supplier' => '']);
        self::assertTrue($saved['ok'], $saved['error']);
        $variantId = self::variantId('SPK-20');

        // Первая строка (минимальный формат sku;цена;промо;остаток) меняет цену, вторая
        // (полный формат) снимает промо нулём, пустая строка в конце — артефакт файла.
        $result = self::applyCsv("SPK-20;1000,10;;5\r\nSPK-20;;;;0;\r\n\r\n");

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(2, $result['report']['applied']);
        self::assertSame(1, $result['report']['quiet']);
        self::assertSame(0, $result['report']['skipped']);

        $row = commerceVariantById($variantId);
        self::assertIsArray($row);
        self::assertSame(1000.1, (float) $row['price_retail']);
        self::assertNull($row['price_promo']);
        self::assertSame(5, (int) $row['stock']);
    }

    public function testИмпортСкоупПоставщика(): void
    {
        commerceCreateSupplier(['title' => 'Восток', 'note' => '']);
        $supplierId = (string) commerceSuppliers()[0]['id'];
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        $own = commerceCreateVariant($product, ['title' => 'Авторы', 'sku' => 'POS-41', 'price_retail' => '1000', 'price_promo' => '', 'stock' => '1', 'supplier' => $supplierId]);
        self::assertTrue($own['ok'], $own['error']);
        $other = commerceCreateVariant($product, ['title' => 'Голая', 'sku' => 'SPK-22', 'price_retail' => '1600', 'price_promo' => '', 'stock' => '4', 'supplier' => '']);
        self::assertTrue($other['ok'], $other['error']);

        // Чужой артикул под скоупом (минимальный формат) — отказ строки, свой правится
        // полным форматом.
        $result = self::applyCsv("SPK-22;1550,55;;0\r\nPOS-41;;;;;2\r\n", $supplierId);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(1, $result['report']['applied']);
        self::assertSame(1, $result['report']['skipped']);
        self::assertSame('Артикул не числится за поставщиком.', $result['report']['skips'][0]['reason']);

        $plain = commerceVariantById(self::variantId('POS-41'));
        $authored = commerceVariantById(self::variantId('SPK-22'));
        self::assertIsArray($plain);
        self::assertIsArray($authored);
        self::assertSame(2, (int) $plain['stock']);
        self::assertSame(1000.0, (float) $plain['price_retail']);
        // Скоуп не дал тронуть чужой вариант.
        self::assertSame(1600.0, (float) $authored['price_retail']);
        self::assertSame(4, (int) $authored['stock']);
    }

    public function testИмпортПромоБезЦеныНижефакта(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        commerceCreateVariant($product, ['title' => '20 Вт', 'sku' => 'SPK-20', 'price_retail' => '1290', 'price_promo' => '', 'stock' => '5', 'supplier' => '']);

        // Промо 1500 выше факта 1290 при пустой цене в строке — отказ строки.
        $result = self::applyCsv("SPK-20;;;;1500;5\r\n");

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(0, $result['report']['applied']);
        self::assertSame(1, $result['report']['skipped']);
        self::assertStringContainsString('ниже', $result['report']['skips'][0]['reason']);
    }

    public function testИмпортПустойФайл(): void
    {
        $result = self::applyCsv(self::BOM);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('пуст', $result['error']);
    }

    public function testИмпортЗапятаяРазделитель(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        $saved = commerceCreateVariant($product, ['title' => '20 Вт', 'sku' => 'SPK-20', 'price_retail' => '1290', 'price_promo' => '', 'stock' => '5', 'supplier' => '']);
        self::assertTrue($saved['ok'], $saved['error']);
        $variantId = self::variantId('SPK-20');

        $result = self::applyCsv("sku,товар,вариант,цена,промо,остаток\nSPK-20,,,980,,2\n");

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(980.0, (float) commerceVariantById($variantId)['price_retail']);
        self::assertSame(2, (int) commerceVariantById($variantId)['stock']);
    }

    // -----------------------------------------------------------------------
    // Помощники
    // -----------------------------------------------------------------------

    private static function variantId(string $sku): string
    {
        $row = one('SELECT id FROM ' . t('commerce_variant') . ' WHERE sku = ?', [$sku]);
        self::assertIsArray($row);

        return (string) $row['id'];
    }

    /**
     * Разбор CSV во временный файл и правка базы так, как это пойдёт со стороны формы.
     *
     * @return array{ok: bool, error: string, report: array{lines: int, applied: int, skipped: int, quiet: int, skips: list<array{sku: string, reason: string}>}}
     */
    private static function applyCsv(string $csv, ?string $supplierId = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pricebook-') ?: '';
        self::assertNotSame('', $path);
        file_put_contents($path, $csv);

        try {
            return commercePricebookApply($path, $supplierId);
        } finally {
            @unlink($path);
        }
    }
}
