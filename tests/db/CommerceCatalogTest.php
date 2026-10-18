<?php

declare(strict_types=1);

namespace Cms\Tests\db;

final class CommerceCatalogTest extends CommerceDbTestCase
{
    // -----------------------------------------------------------------------
    // С reading
    // -----------------------------------------------------------------------

    public function testВитринаВидитТолькоОпубликованные(): void
    {
        self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA', 'title' => 'Кола', 'status' => 'PUBLISHED']);
        self::makeProduct(['slug' => 'limonad', 'sku' => 'LIMO', 'title' => 'Лимонад', 'status' => 'DRAFT']);

        $list = commerceProductList(['published_only' => true, 'page' => 1, 'per_page' => 10]);

        self::assertSame(1, $list['total']);
        self::assertSame('Кола', (string) $list['rows'][0]['title']);
    }

    public function testПоискPodNameAndSku(): void
    {
        self::makeProduct(['slug' => 'kola', 'sku' => 'DRINK-1', 'title' => 'Кола']);
        self::makeProduct(['slug' => 'hleb', 'sku' => 'FOOD-1', 'title' => 'Хлеб']);

        $found = commerceProductList(['q' => 'drink', 'page' => 1, 'per_page' => 10]);
        self::assertSame(1, $found['total']);
        self::assertSame('Кола', (string) $found['rows'][0]['title']);

        $byTitle = commerceProductList(['q' => 'хлеб', 'page' => 1, 'per_page' => 10]);
        self::assertSame(1, $byTitle['total']);
    }

    // Знак % в запросе не открывает веер: escapeLike() держит LIKE-узор честным.
    public function testЗнакПроцентовНеРасширяетПоиск(): void
    {
        self::makeProduct(['slug' => 'kola', 'sku' => 'AB-1', 'title' => 'Кола']);
        self::makeProduct(['slug' => 'hleb', 'sku' => 'XB-1', 'title' => 'Хлеб']);

        $found = commerceProductList(['q' => '%AB%', 'page' => 1, 'per_page' => 10]);

        self::assertSame(0, $found['total']);
    }

    // -----------------------------------------------------------------------
    // Рубрики
    // -----------------------------------------------------------------------

    public function testРубрикаСТоварамиНеУдаляется(): void
    {
        $category = self::makeCategory('tehnika', 'Техника');
        $product = self::makeProduct(['slug' => 'kofemashina', 'sku' => 'COFFEE-1']);
        self::attach($product, $category);

        $result = commerceDeleteCategory($category);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('используют товары', $result['error']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('commerce_category')));
    }

    public function testПустаяРубрикаУдаляется(): void
    {
        $category = self::makeCategory('svobodnaya', 'Свободная');

        $result = commerceDeleteCategory($category);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_category')));
    }

    public function testПерестановкаРубрикМеняетПорядок(): void
    {
        $a = self::makeCategory('a', 'А', 0);
        $b = self::makeCategory('b', 'Б', 1);

        $result = commerceSwapCategories($a, $b);

        self::assertTrue($result['ok'], $result['error']);
        $rows = commerceCategories();
        self::assertSame('b', (string) $rows[0]['slug']);
    }

    // -----------------------------------------------------------------------
    // Товары и связка
    // -----------------------------------------------------------------------

    public function testСозданиеТовараСРубриками(): void
    {
        $category = self::makeCategory('tehnika', 'Техника');
        $categories = commerceCategories();

        $values = [
            'fields' => [
                'title' => 'Кофемашина',
                'slug' => '', // план адреса собирается из названия
                'sku' => 'COFFEE-1',
                'lead_text' => 'Готовит эспрессо.',
                'brand' => 'HomeMade',
                'barcode' => '460000',
                'currency' => 'RUB',
                'status' => 'PUBLISHED',
                'noindex' => '0',
                'seo_title' => '',
                'seo_description' => '',
            ],
            'prices' => ['price_retail' => 2490.0, 'price_promo' => null, 'price_cost' => 1800.0],
            'stock' => 5,
            'body' => [['title' => 'О товаре', 'text' => 'Специальный аппарат.']],
            'categories' => [$category],
        ];

        $plan = commerceSlugPlan('', 'Кофемашина', static fn (string $s): bool => false, 'product');
        $values['slug'] = $plan['slug'];

        $result = commerceCreateProduct($values);
        self::assertTrue($result['ok'], $result['error']);

        $row = commerceProductById($result['id']);
        self::assertSame('kofemashina', (string) $row['slug']);
        self::assertSame('Кофемашина', (string) $row['title']);
        self::assertSame('PUBLISHED', (string) $row['status']);

        $joined = commerceProductCategories((string) $row['id']);
        self::assertCount(1, $joined);
        self::assertSame('Техника', (string) $joined[0]['title']);
    }

    public function testПравкаТовараМеняетСтрокуИСвязку(): void
    {
        $category = self::makeCategory('tehnika', 'Техника');
        $otherCategory = self::makeCategory('drobnost', 'Дробности', 1);
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPEAKER-1', 'title' => 'Колонка']);
        self::attach($product, $category);

        $values = [
            'fields' => [
                'title' => 'Колонка обновлённая',
                'slug' => 'kolonka',
                'sku' => 'SPEAKER-2',
                'lead_text' => '',
                'brand' => '',
                'barcode' => '',
                'currency' => 'RUB',
                'status' => 'DRAFT',
                'noindex' => '1',
                'seo_title' => '',
                'seo_description' => '',
            ],
            'prices' => ['price_retail' => 990.0, 'price_promo' => null, 'price_cost' => null],
            'stock' => 3,
            'body' => [],
            'slug' => 'kolonka',
            'categories' => [$otherCategory],
        ];

        $result = commerceUpdateProduct($product, $values, 'kolonka');

        self::assertTrue($result['ok'], $result['error']);
        $row = commerceProductById($product);
        self::assertSame('Колонка обновлённая', (string) $row['title']);
        self::assertSame('SPEAKER-2', (string) $row['sku']);
        self::assertSame('DRAFT', (string) $row['status']);

        $joined = commerceProductCategories($product);
        self::assertSame('Дробности', (string) $joined[0]['title']);
    }

    public function testТоварЗаЗаказомНеУдаляется(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1']);
        $order = self::makeOrder($product);

        $result = commerceDeleteProduct($product);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('участвует в заказах', $result['error']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('commerce_order_item') . ' WHERE order_id = ?', [$order]));
    }

    // -----------------------------------------------------------------------
    // Валидация
    // -----------------------------------------------------------------------

    public function testПромоЦенаНижеRetailОбязанноОтклоняться(): void
    {
        $check = commerceValidateProduct(
            [
                'title' => 'Товар',
                'slug' => '',
                'sku' => 'X-1',
                'price_retail' => '100,00',
                'price_promo' => '150',
                'currency' => 'RUB',
                'status' => 'DRAFT',
                'stock' => '3',
            ],
            '',
            [],
            static fn (string $s): bool => false
        );

        self::assertArrayHasKey('price_promo', $check['errors']);
        self::assertStringContainsString('ниже обычной', $check['errors']['price_promo']);
    }

    public function testАдресРезервнногоСегментаНеПринимается(): void
    {
        $check = commerceValidateProduct(
            [
                'title' => 'Товар',
                'slug' => 'cart',
                'sku' => 'X-1',
                'price_retail' => '100',
                'currency' => 'RUB',
                'status' => 'DRAFT',
                'stock' => '3',
            ],
            '',
            [],
            static fn (string $s): bool => false
        );

        self::assertStringContainsString('служебной страницей', $check['errors']['slug']);
    }

    public function test_checkoutТребуетИмяИПочту(): void
    {
        $check = commerceValidateCheckout([]);

        self::assertArrayHasKey('customer_name', $check['errors']);
        self::assertArrayHasKey('customer_email', $check['errors']);

        $good = commerceValidateCheckout(['customer_name' => 'Анна', 'customer_email' => 'anna@example.dev']);
        self::assertSame([], $good['errors']);
    }

    /**
     * Заказ с одной позицией, чтобы проверка удаления товара держалась на заказе.
     */
    private static function makeOrder(string $productId): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('commerce_order', [
            'id' => $id,
            'customer_name' => 'Тест',
            'customer_email' => 'test@example.dev',
            'customer_phone' => null,
            'address' => null,
            'comment' => null,
            'currency' => 'RUB',
            'total' => 100.00,
            'status' => 'NEW',
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
        insert('commerce_order_item', [
            'id' => newId(),
            'order_id' => $id,
            'product_id' => $productId,
            'sku' => 'KOLA-1',
            'title' => 'Кола',
            'currency' => 'RUB',
            'unit_price' => 100.00,
            'qty' => 1,
        ]);

        return $id;
    }
}
