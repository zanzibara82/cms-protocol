<?php

declare(strict_types=1);

namespace Cms\Tests\db;

final class CommerceOrderTest extends CommerceDbTestCase
{
    public function testЗаказСписываетОстатокИОчищаетКорзину(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 7]);

        startSession();
        $_SESSION[COMMERCE_SESSION_CART] = [$product => 3];

        $order = commerceOrderCreate(
            [
                'customer_name' => 'Анна',
                'customer_email' => 'anna@example.dev',
                'customer_phone' => '',
                'address' => '',
                'comment' => '',
            ],
            commerceCartLines()
        );

        self::assertTrue($order['ok'], $order['error']);
        self::assertGreaterThan(0, $order['number']);

        $stock = (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]);
        self::assertSame(4, $stock);

        // Корзина опустошается заказом: это часть контракта сборки.
        self::assertSame([], commerceCartItemsMap());
    }

    public function testЦенаЗаказаИдеетОПромоЦенеИТочныйОстаток(): void
    {
        $product = self::makeProduct([
            'slug' => 'kola',
            'sku' => 'KOLA-1',
            'price_retail' => 100.00,
            'price_promo' => 89.90,
            'stock' => 2,
        ]);

        startSession();
        $_SESSION[COMMERCE_SESSION_CART] = [$product => 2];

        $order = commerceOrderCreate(self::checkout(), commerceCartLines());

        self::assertTrue($order['ok'], $order['error']);
        self::assertSame(17980, $order['total_cents']);

        $stock = (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]);
        self::assertSame(0, $stock);

        $rows = commerceOrderItems((string) $order['id']);
        self::assertSame('KOLA-1', (string) $rows[0]['sku']);
        self::assertSame(89.90, (float) $rows[0]['unit_price']);
    }

    public function testВялыйОстатокЛовитсяИСуммаОткатывается(): void
    {
        $product = self::makeProduct(['slug' => 'limonad', 'sku' => 'LIMO-1', 'stock' => 1]);

        $lines = [
            [
                'id' => $product,
                'slug' => 'limonad',
                'title' => 'Лимонад',
                'sku' => 'LIMO-1',
                'price_cents' => 10000,
                'currency' => 'RUB',
                'qty' => 2, // больше остатка
                'stock' => 1,
                'line_cents' => 20000,
            ],
        ];

        $order = commerceOrderCreate(self::checkout(), $lines);

        self::assertFalse($order['ok']);
        self::assertStringContainsString('распродано — уменьшите количество', $order['error']);

        // Ничего не осталось ни в заказах, ни в строках: транзакция откатилась.
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_order')));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_order_item')));
        self::assertSame(1, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));
    }

    public function testСмешанныеВалютыНеСобираются(): void
    {
        $rub = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'currency' => 'RUB']);
        $usd = self::makeProduct(['slug' => 'dollars', 'sku' => 'USD-1', 'currency' => 'USD']);

        $lines = [
            self::line($rub, 'RUB', 1, 10000),
            self::line($usd, 'USD', 1, 500),
        ];

        $order = commerceOrderCreate(self::checkout(), $lines);

        self::assertFalse($order['ok']);
        self::assertStringContainsString('разных валют', $order['error']);
    }

    public function testОтменаЗаказаВозвращаетОстаток(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);

        $order = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 2, 10000)]);

        self::assertTrue($order['ok'], $order['error']);
        self::assertSame(3, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));

        $result = commerceSetOrderStatus((string) $order['id'], 'CANCELLED');

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(5, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));
        self::assertSame('CANCELLED', (string) value('SELECT status FROM ' . t('commerce_order') . ' WHERE id = ?', [$order['id']]));
    }

    public function testВыполненныйЗаказНеменяется(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);

        $order = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 1, 10000)]);
        commerceSetOrderStatus((string) $order['id'], 'SHIPPED');

        $result = commerceSetOrderStatus((string) $order['id'], 'CANCELLED');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Выполненный заказ не меняется', $result['error']);
        self::assertNotSame('CANCELLED', (string) value('SELECT status FROM ' . t('commerce_order') . ' WHERE id = ?', [$order['id']]));
    }

    // -----------------------------------------------------------------------
    // Помощники
    // -----------------------------------------------------------------------

    /** @return array<string, string> */
    private static function checkout(): array
    {
        return [
            'customer_name' => 'Анна',
            'customer_email' => 'anna@example.dev',
            'customer_phone' => '+7 999 000 00 00',
            'address' => '',
            'comment' => '',
        ];
    }

    /**
     * @return array{id: string, slug: string, title: string, sku: string, price_cents: int, currency: string, qty: int, stock: int, line_cents: int}
     */
    private static function line(string $productId, string $currency, int $qty, int $priceCents): array
    {
        return [
            'id' => $productId,
            'slug' => (string) value('SELECT slug FROM ' . t('commerce_product') . ' WHERE id = ?', [$productId]),
            'title' => (string) value('SELECT title FROM ' . t('commerce_product') . ' WHERE id = ?', [$productId]),
            'sku' => (string) value('SELECT sku FROM ' . t('commerce_product') . ' WHERE id = ?', [$productId]),
            'price_cents' => $priceCents,
            'currency' => $currency,
            'qty' => $qty,
            'stock' => $qty,
            'line_cents' => $priceCents * $qty,
        ];
    }
}
