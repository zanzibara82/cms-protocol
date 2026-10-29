<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Core\Mailer;

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
                'product_id' => $product,
                'variant_id' => '',
                'variant_title' => '',
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

    public function testВариантСписываетОстатокИВозвращаетПриОтмене(): void
    {
        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1', 'stock' => 5]);
        $created = commerceCreateVariant($product, [
            'title' => '20 Вт',
            'sku' => 'SPK-20',
            'price_retail' => '100',
            'price_promo' => '',
            'stock' => '3',
        ]);
        self::assertTrue($created['ok'], $created['error']);
        $variant = commerceVariants($product)[0];

        $line = self::line($product, 'RUB', 2, 10000);
        $line['variant_id'] = (string) $variant['id'];
        $line['variant_title'] = '20 Вт';
        $line['sku'] = 'SPK-20';

        $order = commerceOrderCreate(self::checkout(), [$line]);

        self::assertTrue($order['ok'], $order['error']);
        // Остаток уходит варианту: родительский товар своих штук не теряет.
        self::assertSame(5, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));
        self::assertSame(1, (int) value('SELECT stock FROM ' . t('commerce_variant') . ' WHERE id = ?', [$variant['id']]));
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('commerce_order_item') . ' WHERE order_id = ? AND variant_id = ?',
            [$order['id'], $variant['id']]
        ));

        $result = commerceSetOrderStatus((string) $order['id'], 'CANCELLED');

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(3, (int) value('SELECT stock FROM ' . t('commerce_variant') . ' WHERE id = ?', [$variant['id']]));
        self::assertSame(5, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));
    }

    public function testВыполненныйЗаказНеменяется(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);

        $order = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 1, 10000)]);
        commerceSetOrderStatus((string) $order['id'], 'PAID');
        commerceSetOrderStatus((string) $order['id'], 'SHIPPED');
        commerceSetOrderStatus((string) $order['id'], 'DONE');

        $result = commerceSetOrderStatus((string) $order['id'], 'CANCELLED');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Отправленный или завершённый заказ отменить нельзя', $result['error']);
        self::assertSame('DONE', (string) value('SELECT status FROM ' . t('commerce_order') . ' WHERE id = ?', [$order['id']]));

        // Из финального статуса вообще нет движения.
        $again = commerceSetOrderStatus((string) $order['id'], 'PAID');

        self::assertFalse($again['ok']);
        self::assertStringContainsString('ход строго по цепочке', $again['error']);
    }

    public function testПрыжокЧерезСтатусыОтклоняется(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);

        $order = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 1, 10000)]);

        // Из «Нового» невозможно уйти прямо в «Отправлен», минуя «Оплачен».
        $shipped = commerceSetOrderStatus((string) $order['id'], 'SHIPPED');

        self::assertFalse($shipped['ok']);
        self::assertStringContainsString('не переходит в «Отправлен»', $shipped['error']);

        $paid = commerceSetOrderStatus((string) $order['id'], 'PAID');

        self::assertTrue($paid['ok'], $paid['error']);

        $done = commerceSetOrderStatus((string) $order['id'], 'DONE');

        self::assertFalse($done['ok']);
        self::assertStringContainsString('не переходит в «Доставлен»', $done['error']);
    }

    public function testОформлениеШлётПисьмоПокупателю(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);
        $product2 = self::makeProduct(['slug' => 'konf', 'sku' => 'KONF-9', 'stock' => 5]);
        $line = self::line($product, 'RUB', 2, 10000);
        $line2 = self::line($product2, 'RUB', 1, 4990);

        // Счётчик заказов держится между тестами, поэтому номер берём из результата
        // того же оформления, на котором ловим письмо.
        $second = [];
        $letters = $this->captureMail(static function () use (&$second, $line, $line2): array {
            $order = commerceOrderCreate(self::checkout(), [$line, $line2]);
            $second[] = $order;

            return [$order];
        });

        $order = $second[0];
        self::assertTrue($order['ok'], $order['error']);
        self::assertSame(1, count($letters));
        self::assertSame('anna@example.dev', $letters[0]['to']);
        self::assertSame('Заказ №' . $order['number'] . ' — Школа английского', (string) $letters[0]['subject']);

        $text = (string) $letters[0]['text'];
        self::assertStringContainsString('Ваш заказ №' . $order['number'] . ' принят', $text);
        self::assertStringContainsString('KOLA-1', $text);
        self::assertStringContainsString('KONF-9', $text);
        self::assertStringContainsString('Итого', $text);

        // Отправитель письма — конвертный адрес ядра из настроек.
        self::assertSame('noreply@example.com', commerceMailFrom());
    }

    public function testСменаСтатусаШлётПисьмоПокупателю(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);
        $created = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 2, 10000)]);
        self::assertTrue($created['ok'], $created['error']);

        $letters = $this->captureMail(
            static fn (): array => commerceSetOrderStatus((string) $created['id'], 'PAID')
        );

        self::assertSame(1, count($letters));
        self::assertSame('anna@example.dev', $letters[0]['to']);
        self::assertSame('Заказ №' . $created['number'] . ' — Школа английского', (string) $letters[0]['subject']);
        $text = (string) $letters[0]['text'];
        self::assertStringContainsString('Статус вашего заказа', $text);
        self::assertStringContainsString('«Оплачен»', $text);
    }

    public function testДвойнаяОтменаОдногоЗаказаВозвращаетОстатокОдинРаз(): void
    {
        // Гонка смены статуса: два одновременных «Отменить» прочли один и тот же статус и
        // раньше доезжали до двух независимых UPDATE — остаток удваивался, купон расходом
        // возвращался дважды. Охрана «WHERE … AND status = ?» отдаёт обновление первому.
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);
        $order = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 2, 10000)]);
        self::assertSame(3, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));

        $first = commerceSetOrderStatus((string) $order['id'], 'CANCELLED');
        self::assertTrue($first['ok'], $first['error']);
        self::assertSame(5, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));

        $second = commerceSetOrderStatus((string) $order['id'], 'CANCELLED');

        self::assertFalse($second['ok']);
        self::assertSame('Заказ уже в этом статусе.', (string) $second['error']);
        // Один возврат — один приход остатка: второй отказал и ничего не вернул.
        self::assertSame(5, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));
    }

    public function testОтменаОправленногоНеВозвращаетОстаток(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);

        $order = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 2, 10000)]);
        self::assertSame(3, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));
        commerceSetOrderStatus((string) $order['id'], 'PAID');
        commerceSetOrderStatus((string) $order['id'], 'SHIPPED');

        $result = commerceSetOrderStatus((string) $order['id'], 'CANCELLED');

        self::assertFalse($result['ok']);
        // Товарные позиции после отправки уже выбраны: отмена не возвращает их наряду.
        self::assertSame(3, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$product]));
    }

    public function testРаскупленнаяПозицияИсчезаетИзКорзиныИЗаказа(): void
    {
        // Дефект багажа: строка «0 × цена» доезжала до базы — списание с qty = 0 проходило
        // охрану (stock >= 0 всегда истинно), и заказ считался успешным.
        $soldOut = self::makeProduct(['slug' => 'sold-out', 'sku' => 'OUT-1', 'title' => 'Раскупленная', 'stock' => 0]);
        $alive = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'title' => 'Кола', 'stock' => 5]);

        startSession();
        $_SESSION[COMMERCE_SESSION_CART] = [$soldOut => 3, $alive => 2];

        $lines = commerceCartLines();

        self::assertCount(1, $lines, 'Раскупленная позиция молча исчезает из списка корзины');
        self::assertSame('KOLA-1', (string) $lines[0]['sku']);

        $order = commerceOrderCreate(self::checkout(), $lines);

        self::assertTrue($order['ok'], $order['error']);

        // В заказе только живая позиция; раскупленная не составила «0 × цена» и не прошла
        // списание мимо охраны остатка.
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('commerce_order_item') . ' WHERE order_id = ?',
            [(string) $order['id']]
        ));
        self::assertSame(3, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$alive]));
        self::assertSame(0, (int) value('SELECT stock FROM ' . t('commerce_product') . ' WHERE id = ?', [$soldOut]));
    }

    public function testНулеваяСтрокаННапрямуюЗаказНеСоставляет(): void
    {
        $product = self::makeProduct(['slug' => 'kola', 'sku' => 'KOLA-1', 'stock' => 5]);

        // Защита на входе commerceOrderCreate(): даже если позиция пришла мимо корзины
        // (например, из чужого кода через фильтр), нулевая строка заказа не составляет.
        $order = commerceOrderCreate(self::checkout(), [self::line($product, 'RUB', 0, 10000)]);

        self::assertFalse($order['ok']);
        self::assertSame('Корзина пуста.', (string) $order['error']);
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
     * Перехват писем: массив сообщения ловится на before_mail_send (там письмо
     * ещё в читаемых полях), подменный транспорт лишь подтверждает отправку.
     * Фильтры снимаются после сбора: подписка не выходит за пределы теста.
     *
     * @return list<array{to: string, subject: string, text: string}>
     */
    private function captureMail(callable $action): array
    {
        hooksReset();
        $sent = [];
        addFilter('before_mail_send', static function (array $message) use (&$sent): array {
            $sent[] = $message;

            return $message;
        });
        addFilter('commerce_mailer', static fn (mixed $value): Mailer => new Mailer(
            static fn (array $headers, string $raw): array => ['ok' => true, 'error' => '']
        ));

        $action();

        hooksReset();

        /** @var list<array{to: string, subject: string, text: string}> */
        return $sent;
    }

    /**
     * @return array{id: string, product_id: string, variant_id: string, variant_title: string, slug: string, title: string, sku: string, price_cents: int, currency: string, qty: int, stock: int, line_cents: int}
     */
    private static function line(string $productId, string $currency, int $qty, int $priceCents): array
    {
        return [
            'id' => $productId,
            'product_id' => $productId,
            'variant_id' => '',
            'variant_title' => '',
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
