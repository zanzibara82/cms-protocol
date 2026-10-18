<?php

declare(strict_types=1);

// Оформление и учёт заказов. Сборка заказа атомарна: сумма, строки и списание остатка
// идут в одной транзакции, и «продано последней штуки двоим» невозможно — списание через
// UPDATE ... WHERE stock >= qty проверяет остаток моментом обновления строки.

/**
 * Заказ из корзины. Строки приходят из commerceCartLines() — свежие цена и остаток.
 *
 * @param array<string, string> $checkout данные commerceValidateCheckout()
 * @param list<array{id: string, slug: string, title: string, sku: string, price_cents: int, currency: string, qty: int, stock: int, line_cents: int}> $lines
 * @return array{ok: bool, error: string, id: string, number: int, total_cents: int}
 */
function commerceOrderCreate(array $checkout, array $lines): array
{
    if ($lines === []) {
        return ['ok' => false, 'error' => 'Корзина пуста.', 'id' => '', 'number' => 0, 'total_cents' => 0];
    }

    $currencies = [];
    foreach ($lines as $line) {
        $currencies[$line['currency']] = true;
    }

    if (count($currencies) > 1) {
        return [
            'ok' => false,
            'error' => 'В корзине товары разных валют — оформите их отдельными заказами.',
            'id' => '',
            'number' => 0,
            'total_cents' => 0,
        ];
    }

    $currency = (string) array_key_first($currencies);
    $total = 0;
    foreach ($lines as $line) {
        $total += $line['line_cents'];
    }

    $id = newId();
    $moment = nowUtc();

    try {
        $number = tx(static function () use ($id, $checkout, $lines, $total, $currency, $moment) {
            foreach ($lines as $line) {
                $inserted = run(
                    'UPDATE ' . t('commerce_product') . ' SET stock = stock - ?'
                . ' WHERE id = ? AND status = ? AND stock >= ?',
                    [$line['qty'], $line['id'], 'PUBLISHED', $line['qty']]
                );

                if ($inserted !== 1) {
                    throw new RuntimeException('«' . $line['title'] . '» уже распродано — уменьшите количество.');
                }
            }

            insert('commerce_order', [
                'id' => $id,
                'customer_name' => $checkout['customer_name'],
                'customer_email' => $checkout['customer_email'],
                'customer_phone' => $checkout['customer_phone'] !== '' ? $checkout['customer_phone'] : null,
                'address' => $checkout['address'] !== '' ? $checkout['address'] : null,
                'comment' => $checkout['comment'] !== '' ? $checkout['comment'] : null,
                'currency' => $currency,
                'total' => $total / 100,
                'status' => 'NEW',
                'created_at' => utcStamp($moment),
                'updated_at' => utcStamp($moment),
            ]);

            foreach ($lines as $line) {
                insert('commerce_order_item', [
                    'id' => newId(),
                    'order_id' => $id,
                    'product_id' => $line['id'],
                    'sku' => (string) $line['sku'],
                    'title' => (string) $line['title'],
                    'currency' => $line['currency'],
                    'unit_price' => $line['price_cents'] / 100,
                    'qty' => $line['qty'],
                ]);
            }

            return (int) value('SELECT number FROM ' . t('commerce_order') . ' WHERE id = ?', [$id]);
        });
    } catch (RuntimeException $reason) {
        return [
            'ok' => false,
            'error' => $reason->getMessage(),
            'id' => '',
            'number' => 0,
            'total_cents' => $total,
        ];
    }

    commerceCartClear();
    logInfo('commerce.order_created', ['orderId' => $id, 'number' => $number, 'total_cents' => $total]);

    return [
        'ok' => true,
        'error' => '',
        'id' => $id,
        'number' => $number,
        'total_cents' => $total,
    ];
}

/**
 * Заказ по номеру для страницы подтверждения. Подтверждение проверяет, что номер в
 * недавнем списке сессии: страница заказа без отметки сессии не открывается посторонним.
 *
 * @return array<string, mixed>|null
 */
function commerceOrderByNumber(int $number): ?array
{
    if ($number <= 0) {
        return null;
    }

    return one(
        'SELECT id, number, customer_name, customer_email, currency, total, status, created_at'
        . ' FROM ' . t('commerce_order') . ' WHERE number = ?',
        [$number]
    );
}

/**
 * Отметка «номер оформлен в этой сессии»: список действующих номеров держится в сессии,
 * сверху лежат самые свежие; страница подтверждения не открывается, если номер не отмечен.
 */
function commerceOrderConfirmSessionMark(int $number): void
{
    startSession();
    $marks = (array) ($_SESSION['commerce_orders_done'] ?? []);
    $marks[] = $number;
    $_SESSION['commerce_orders_done'] = array_slice(array_unique(array_map('intval', $marks)), -20);
}

/** @param array{id?: mixed} $order */
function commerceOrderConfirmAllowed(int $number, array $order = []): bool
{
    $marks = (array) ($_SESSION['commerce_orders_done'] ?? []);

    return in_array($number, array_map('intval', $marks), true);
}

/**
 * Список заказов админ-экрана: строки с фильтром по статусу и пагинатором.
 *
 * @return array{rows: list<array<string, mixed>>, total: int}
 */
function commerceOrdersList(string $status, int $page, int $perPage = 20): array
{
    $where = [];
    $args = [];

    if (in_array($status, COMMERCE_ORDER_STATUSES, true)) {
        $where[] = 'status = ?';
        $args[] = $status;
    }

    $clause = $where === [] ? ' WHERE 1=1' : ' WHERE ' . implode(' AND ', $where);
    $total = (int) value(
        sprintf('SELECT COUNT(*) FROM %s%s', t('commerce_order'), $clause),
        $args
    );
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        sprintf(
            'SELECT id, number, customer_name, customer_email, currency, total, status, created_at'
            . ' FROM %s%s ORDER BY created_at DESC, number DESC LIMIT %d OFFSET %d',
            t('commerce_order'),
            $clause,
            $perPage,
            $offset
        ),
        $args
    );

    return ['rows' => $rows, 'total' => $total];
}

/**
 * @return list<array{id: string, sku: string, title: string, currency: string, unit_price: float, qty: int}>
 */
function commerceOrderItems(string $orderId): array
{
    $rows = all(
        'SELECT id, sku, title, currency, unit_price, qty FROM ' . t('commerce_order_item')
        . ' WHERE order_id = ? ORDER BY title',
        [$orderId]
    );

    /** @var list<array{id: string, sku: string, title: string, currency: string, unit_price: float, qty: int}> */
    return $rows;
}

/**
 * Смена статуса заказа. Отмена возвращает остаток против списания — товар снова увидит
 * витрина. Побочное движение статусов запрещено: SHIPPED не превращается в CANCELLED
 * кнопкой «отменить» экрана.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceSetOrderStatus(string $orderId, string $status): array
{
    $order = one('SELECT id, status FROM ' . t('commerce_order') . ' WHERE id = ?', [$orderId]);

    if ($order === null) {
        return ['ok' => false, 'error' => 'Заказа уже нет — обновите список.', 'notice' => ''];
    }

    $previous = (string) $order['status'];

    if ($previous === $status) {
        return ['ok' => false, 'error' => 'Заказ уже в этом статусе.', 'notice' => ''];
    }

    if (!in_array($status, COMMERCE_ORDER_STATUSES, true)) {
        return ['ok' => false, 'error' => 'Статус выбирается из списка.', 'notice' => ''];
    }

    if ($previous === 'SHIPPED') {
        return ['ok' => false, 'error' => 'Выполненный заказ не меняется — только повторным оформлением.', 'notice' => ''];
    }

    tx(static function () use ($orderId, $status): void {
        run(
            'UPDATE ' . t('commerce_order') . ' SET status = ?, updated_at = ? WHERE id = ?',
            [$status, utcStamp(nowUtc()), $orderId]
        );

        if ($status === 'CANCELLED') {
            run(
                'UPDATE ' . t('commerce_product') . ' p'
            . ' JOIN ' . t('commerce_order_item') . ' oi ON oi.product_id = p.id'
            . ' SET p.stock = p.stock + oi.qty WHERE oi.order_id = ?',
                [$orderId]
            );
        }
    });

    logInfo('commerce.order_status', ['orderId' => $orderId, 'status' => $status, 'previous' => $previous]);

    return ['ok' => true, 'error' => '', 'notice' => 'order_updated'];
}
