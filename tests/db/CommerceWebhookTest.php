<?php

declare(strict_types=1);

namespace Cms\Tests\db;

// Webhooks (M6): справочник приёмников событий заказов, подпись HMAC, отдача события
// через отделённый фильтром транспорт и честная запись последней доставки.
// Сеть в тесте не поднимается: транспорт подменяется подпиской commerce_webhook_transport
// — тот самый приём, каким раздел обещает расширяемость ТЗ.

final class CommerceWebhookTest extends CommerceDbTestCase
{
    /** @var list<array{id: string, url: string, secret: string, event: string, payload: string}> */
    private static array $delivered = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::registerTransport();
    }

    /**
     * Транспорт подменяется с подпиской: собранное остаётся до конца набора. Сеть не
     * поднимается — риск живого curl на тестовом стенде исчезает.
     */
    private static function registerTransport(): void
    {
        self::$delivered = [];

        addFilter('commerce_webhook_transport', static function (mixed $value, array $hook, string $event, string $payload): \Closure {
            return static function (array $hook, string $event, string $payload): bool {
                self::$delivered[] = [
                    'id' => (string) $hook['id'],
                    'url' => (string) $hook['url'],
                    'secret' => (string) $hook['secret'],
                    'event' => $event,
                    'payload' => $payload,
                ];

                return true;
            };
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$delivered = [];
    }

    // ------------------------------------------------------------------
    // Валидация и CRUD справочника
    // ------------------------------------------------------------------

    public function testВалидаторПроситАдресИСекрет(): void
    {
        $empty = commerceValidateWebhook([]);
        self::assertSame('Укажите адрес приёмника.', (string) $empty['errors']['url']);
        self::assertSame('Укажите секрет — он подписывает события.', (string) $empty['errors']['secret']);

        $notUrl = commerceValidateWebhook(['url' => 'ftp://пример.рф/orders', 'secret' => 'secret-20-symbols-x']);
        self::assertSame('Адрес начинается с http:// или https://.', (string) $notUrl['errors']['url']);

        $short = commerceValidateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'короткий']);
        self::assertSame('Секрет не короче ' . COMMERCE_WEBHOOK_SECRET_MIN . ' символов.', (string) $short['errors']['secret']);

        $long = commerceValidateWebhook([
            'url' => 'https://приёмник.рф/' . str_repeat('а', COMMERCE_WEBHOOK_URL_MAX),
            'secret' => 'secret-20-symbols-x',
        ]);
        self::assertSame('Адрес не длиннее ' . COMMERCE_WEBHOOK_URL_MAX . ' символов.', (string) $long['errors']['url']);

        $tooLongSecret = commerceValidateWebhook([
            'url' => 'https://приёмник.рф/orders',
            'secret' => str_repeat('с', COMMERCE_WEBHOOK_SECRET_MAX + 1),
        ]);
        self::assertSame('Секрет не длиннее ' . COMMERCE_WEBHOOK_SECRET_MAX . ' символов.', (string) $tooLongSecret['errors']['secret']);

        $good = commerceValidateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-xyz', 'active' => '1']);
        self::assertSame([], $good['errors'], 'Валидная пара адрес + секрет обязана пройти без причин');
        self::assertSame(1, $good['values']['active'], 'Флажок активности читается из набора формы');
    }

    public function testСозданиеИПравкаВебхука(): void
    {
        $check = commerceValidateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 'on']);
        $created = commerceCreateWebhook($check['values']);
        self::assertTrue($created['ok'], 'Создание валидного вебхука обязано пройти');
        self::assertSame('webhook_saved', $created['notice']);

        $row = commerceWebhookReceivers()[0] ?? null;
        self::assertIsArray($row, 'Созданный активный приёмник виден списку события');
        self::assertSame('https://приёмник.рф/orders', (string) $row['url']);

        $updated = commerceUpdateWebhook((string) $row['id'], [
            'url' => 'https://второй.рф/commerce',
            'secret' => 'second-secret-20-sym',
            'active' => 0,
        ]);
        self::assertTrue($updated['ok'], 'Правка существующего приёмника обязана пройти');

        $kept = one('SELECT url, active FROM ' . t('commerce_webhook') . ' WHERE id = ?', [$row['id']]);
        self::assertIsArray($kept);
        self::assertSame('https://второй.рф/commerce', (string) $kept['url']);
        self::assertSame(0, (int) $kept['active'], 'Снятие флажка выключает приёмник');

        $gone = commerceUpdateWebhook('ID_PUstroyki', [
            'url' => 'https://второй.рф/commerce',
            'secret' => 'second-secret-20-sym',
            'active' => 0,
        ]);
        self::assertFalse($gone['ok'], 'Правка отсутствующего приёмника красит причиной');
        self::assertSame('Вебхука уже нет — обновите список.', (string) $gone['error']);
    }

    public function testУдалениеВебхука(): void
    {
        commerceCreateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 0]);
        $row = one('SELECT id FROM ' . t('commerce_webhook'));
        self::assertIsArray($row);

        $deleted = commerceDeleteWebhook((string) $row['id']);
        self::assertTrue($deleted['ok'], 'Удаление существующего приёмника проходит');
        self::assertSame('webhook_deleted', $deleted['notice']);
        self::assertNull(one('SELECT id FROM ' . t('commerce_webhook')), 'Строка приёмника ушла из таблицы');

        $again = commerceDeleteWebhook((string) $row['id']);
        self::assertFalse($again['ok'], 'Второе удаление того же идентификатора красит причиной');
        self::assertSame('Вебхука уже нет — обновите список.', (string) $again['error']);
    }

    public function testПредложенныйСекретДостаточен(): void
    {
        foreach (range(1, 5) as $i) {
            $secret = commerceWebhookSecretSuggest();
            $check = commerceValidateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => $secret]);
            self::assertSame([], $check['errors'], 'Машинный секрет обязателен не хуже набранного (' . $i . ' прогон)');
        }
    }

    // ------------------------------------------------------------------
    // События и транспорт
    // ------------------------------------------------------------------

    /** @return array{id: string, number: int} */
    private static function makeOrder(int $number, string $status = 'NEW'): array
    {
        $id = newId();
        $moment = utcStamp(nowUtc());
        $productId = self::makeProduct([
            'slug' => 'obhod-tovar-' . $number,
            'sku' => 'COFFEE-' . $number,
            'title' => 'Кофемашина',
            'stock' => 10,
        ]);

        insert('commerce_order', [
            'id' => $id,
            'customer_name' => 'Борис Колосово',
            'customer_email' => 'boris@example.dev',
            'customer_phone' => null,
            'address' => null,
            'comment' => null,
            'coupon_code' => null,
            'coupon_discount' => 0.00,
            'promo_title' => null,
            'promo_discount' => 0.00,
            'currency' => 'RUB',
            'total' => 22.40,
            'status' => $status,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        run('UPDATE ' . t('commerce_order') . ' SET number = ? WHERE id = ?', [$number, $id]);

        insert('commerce_order_item', [
            'id' => newId(),
            'order_id' => $id,
            'product_id' => $productId,
            'variant_id' => null,
            'title' => 'Кофемашина',
            'sku' => 'COFFEE-' . $number,
            'currency' => 'RUB',
            'unit_price' => 22.40,
            'qty' => 1,
        ]);

        return ['id' => $id, 'number' => $number];
    }

    public function testСобытиеДоставляетсяСПодписьюHmac(): void
    {
        commerceCreateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 1]);
        $order = self::makeOrder(101);

        commerceWebhookEvent('order.created', $order['id']);

        self::assertCount(1, self::$delivered, 'Активный приёмник получает ровно одно письмо события');
        $letter = self::$delivered[0];
        self::assertSame('order.created', $letter['event']);
        self::assertSame('https://приёмник.рф/orders', $letter['url']);
        self::assertSame('secret-20-symbols-x', $letter['secret'], 'Транспорт получает секрет в своих руках');

        $payload = (array) json_decode($letter['payload'], true);
        self::assertSame('order.created', (string) ($payload['event'] ?? ''));
        self::assertSame(101, (int) ($payload['number'] ?? 0));
        self::assertSame('NEW', (string) ($payload['status'] ?? ''));
        self::assertSame('22.40', (string) ($payload['total'] ?? ''));
        self::assertSame('RUB', (string) ($payload['currency'] ?? ''));
        self::assertSame('boris@example.dev', (string) ($payload['customer_email'] ?? ''));
        self::assertSame(1, (int) ($payload['items'] ?? 0), 'Состав строк сводится счётом, без листа позиций');
        self::assertNotSame('', (string) ($payload['at'] ?? ''), 'Время события кладётся в тело');

        // Подпись печатается одной фабрикой: транспорт подменяемый, а прима факта остаётся.
        self::assertSame(
            commerceWebhookSignature($letter['payload'], 'secret-20-symbols-x'),
            'sha256=' . hash_hmac('sha256', $letter['payload'], 'secret-20-symbols-x'),
            'Фабрика подписи и известная формула HMAC сходятся'
        );
    }

    public function testСобытиеСостоянияУходитСФактомСтатуса(): void
    {
        commerceCreateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 1]);
        $order = self::makeOrder(102, 'PAID');

        commerceWebhookEvent('order.status_changed', $order['id']);

        self::assertCount(1, self::$delivered);
        $payload = (array) json_decode(self::$delivered[0]['payload'], true);
        self::assertSame('order.status_changed', (string) ($payload['event'] ?? ''));
        self::assertSame('PAID', (string) ($payload['status'] ?? ''));
    }

    public function testВыключенныйПриёмникМолчит(): void
    {
        commerceCreateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 0]);
        $order = self::makeOrder(103);

        commerceWebhookEvent('order.created', $order['id']);

        self::assertSame([], self::$delivered, 'Выключенный приёмник не получает событий и не роняет звонок');
        $row = one('SELECT last_state FROM ' . t('commerce_webhook'));
        self::assertIsArray($row);
        self::assertNull($row['last_state'], 'Выключенному не пишется «доставлено» — доставки не было');
    }

    public function testСписокПриёмниковДержитТолькоАктивных(): void
    {
        commerceCreateWebhook(['url' => 'https://живой.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 1]);
        commerceCreateWebhook(['url' => 'https://молчащий.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 0]);

        $urls = array_map(static fn (array $row): string => (string) $row['url'], commerceWebhookReceivers());
        self::assertSame(['https://живой.рф/orders'], $urls, 'Список события отбирает только флажок активности');
    }

    public function testОтсутствующийЗаказМолчит(): void
    {
        commerceCreateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 1]);

        commerceWebhookEvent('order.created', 'нет-такого-идентификатора');

        self::assertSame([], self::$delivered, 'Звонок по несуществующему заказу ничего не отправляет и не падает');
    }

    public function testПустойКаталогВебхуковМолчит(): void
    {
        $order = self::makeOrder(104);

        commerceWebhookEvent('order.created', $order['id']);

        self::assertSame([], self::$delivered, 'Без приёмников событие не отправляется вовсе');
    }

    public function testОтказДоставкиЗаписываетсяВПоследнееСостояние(): void
    {
        // Свежая подписка на транспорт: этот тест собирает неудачную доставку.
        hooksReset();
        self::$delivered = [];
        addFilter('commerce_webhook_transport', static function (mixed $value, array $receiver, string $event, string $payload): \Closure {
            return static fn (): bool => false;
        });

        commerceCreateWebhook(['url' => 'https://мёртвый.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 1]);
        $order = self::makeOrder(105);

        commerceWebhookEvent('order.created', $order['id']);

        $row = one('SELECT last_state, last_at FROM ' . t('commerce_webhook'));
        self::assertIsArray($row, 'Приёмник остался в таблице');
        self::assertSame('Не доставлено — проверьте адрес события.', (string) $row['last_state'], 'Отказ доставки печатается словами, а не кодом');
        self::assertNotNull($row['last_at'], 'Отметка времени доставки пишется и при отказе');

        // Подмену вернуть честным: hooksReset() снимает все подписки набора,
        // а последующие тесты ждут доставляющий транспорт.
        self::registerTransport();
    }

    public function testУспехДоставкиЗаписываетсяВПоследнееСостояние(): void
    {
        commerceCreateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 1]);
        $order = self::makeOrder(106);

        commerceWebhookEvent('order.created', $order['id']);

        $row = one('SELECT last_state, last_at FROM ' . t('commerce_webhook'));
        self::assertIsArray($row);
        self::assertSame('Доставлено', (string) $row['last_state']);
        self::assertNotNull($row['last_at']);
    }

    public function testСобытиеНеРоняетОформлениеПриИсключенииТранспорта(): void
    {
        // Огрызнувшийся транспорт не имеет права сломать checkout: исключение гасится
        // и уезжает в лог, а исполнение продолжается.
        hooksReset();
        self::$delivered = [];
        addFilter('commerce_webhook_transport', static function (mixed $value, array $receiver, string $event, string $payload): \Closure {
            return static function (): bool {
                throw new \RuntimeException('транспорт огрызнулся');
            };
        });

        commerceCreateWebhook(['url' => 'https://приёмник.рф/orders', 'secret' => 'secret-20-symbols-x', 'active' => 1]);
        $order = self::makeOrder(107);

        commerceWebhookEvent('order.created', $order['id']);

        $row = one('SELECT last_state FROM ' . t('commerce_webhook'));
        self::assertIsArray($row, 'Исключение транспорта гасится — приёмник и магазин остаются на месте');
        self::assertNull($row['last_state'], 'Состояние доставки не рисуется из-за поломки транспорта: доставки не было');

        // Подмену вернуть честным, чтобы держать набор без порядка-зависимости.
        self::registerTransport();
    }
}
