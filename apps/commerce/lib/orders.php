<?php

declare(strict_types=1);

// Оформление и учёт заказов. Сборка заказа атомарна: сумма, строки и списание остатка
// идут в одной транзакции, и «продано последней штуки двоим» невозможно — списание через
// UPDATE ... WHERE stock >= qty проверяет остаток моментом обновления строки.

/**
 * Заказ из корзины. Строки приходят из commerceCartLines() — свежие цена и остаток.
 * Строка с вариантом списывает остаток варианта и сохраняет ссылку на него в строке
 * заказа: отмена вернёт остаток варианту, а не родителю.
 *
 * Скидки (M4) идут в сделку целиком: купон с сессии расходует визит одним UPDATE
 * внутри транзакции — последний визит двоим не уведёшь; лучшее работающее промо-правило
 * снимает автоскидку. Итог к оплате, код купона и название правила хранятся в заказе.
 *
 * @param array<string, string> $checkout данные commerceValidateCheckout()
 * @param list<array{id: string, product_id: string, variant_id: string, slug: string, title: string, variant_title: string, sku: string, price_cents: int, currency: string, qty: int, stock: int, line_cents: int}> $lines
 * @return array{ok: bool, error: string, id: string, number: int, total_cents: int}
 */
function commerceOrderCreate(array $checkout, array $lines): array
{
    // Нулевые позиции заказ не составляет: строка «0 × цена» пробила бы охрану списания
    // (WHERE stock >= 0 всегда истинно) и осела бы в базе без остатка. Защита на входе
    // держит контракт «списание через stock >= qty» честным при любой паре строк.
    $lines = array_values(array_filter(
        $lines,
        static fn (array $line): bool => (int) $line['qty'] >= 1
    ));

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
    /** @var array{coupon_code: string, coupon_discount_cents: int, promo_title: string, promo_discount_cents: int, total_cents: int}|null $pricing */
    $pricing = null;

    try {
        $number = tx(static function () use ($id, $checkout, $lines, $total, $currency, $moment, &$pricing) {
            foreach ($lines as $line) {
                if ($line['variant_id'] === '') {
                    $inserted = run(
                        'UPDATE ' . t('commerce_product') . ' SET stock = stock - ?'
                    . ' WHERE id = ? AND status = ? AND stock >= ?',
                        [$line['qty'], $line['product_id'], 'PUBLISHED', $line['qty']]
                    );
                } else {
                    // Позиция варианта: товар обязан остаться в продаже до момента
                    // списания, иначе строка корзины прошла мимо видимости карточки.
                    $inserted = run(
                        'UPDATE ' . t('commerce_variant') . ' SET stock = stock - ?'
                    . ' WHERE id = ? AND stock >= ?',
                        [$line['qty'], $line['variant_id'], $line['qty']]
                    );
                }

                if ($inserted !== 1) {
                    throw new RuntimeException('«' . $line['title']
                        . ($line['variant_title'] !== '' ? ' — ' . $line['variant_title'] : '')
                        . '» уже распродано — уменьшите количество.');
                }
            }

            // Скидки сделки (M4): купон расходует визит здесь же, в транзакции.
            $couponActual = '';
            $couponDiscount = 0;
            $couponCode = commerceSessionCouponCode();

            if ($couponCode !== '') {
                $burn = commerceConsumeCoupon($couponCode, $currency, $total, $moment);

                if (!$burn['ok']) {
                    throw new RuntimeException('Купон «' . $couponCode . '» не применился: ' . $burn['error']);
                }

                $couponActual = $couponCode;
                $couponDiscount = (int) commerceCouponDiscountCents((array) $burn['coupon'], $total);
            }

            $promo = commerceBestPromoDiscount(commerceActivePromos($moment), $total);
            $promoTitle = (string) ($promo['title'] ?? '');
            $promoDiscount = (int) ($promo['discount_cents'] ?? 0);
            $totalDue = max(0, $total - $couponDiscount - $promoDiscount);

            $pricing = [
                'coupon_code' => $couponActual,
                'coupon_discount_cents' => $couponDiscount,
                'promo_title' => $promoTitle,
                'promo_discount_cents' => $promoDiscount,
                'total_cents' => $totalDue,
            ];

            insert('commerce_order', [
                'id' => $id,
                'customer_name' => $checkout['customer_name'],
                'customer_email' => $checkout['customer_email'],
                'customer_phone' => $checkout['customer_phone'] !== '' ? $checkout['customer_phone'] : null,
                'address' => $checkout['address'] !== '' ? $checkout['address'] : null,
                'comment' => $checkout['comment'] !== '' ? $checkout['comment'] : null,
                'currency' => $currency,
                'total' => $totalDue / 100,
                'coupon_code' => $couponActual !== '' ? $couponActual : null,
                'coupon_discount' => $couponDiscount / 100,
                'promo_title' => $promoTitle !== '' ? $promoTitle : null,
                'promo_discount' => $promoDiscount / 100,
                'status' => 'NEW',
                'created_at' => utcStamp($moment),
                'updated_at' => utcStamp($moment),
            ]);

            foreach ($lines as $line) {
                insert('commerce_order_item', [
                    'id' => newId(),
                    'order_id' => $id,
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] !== '' ? $line['variant_id'] : null,
                    'sku' => (string) $line['sku'],
                    'title' => $line['variant_title'] !== ''
                        ? $line['title'] . ' — ' . $line['variant_title'] : $line['title'],
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
    logInfo('commerce.order_created', ['orderId' => $id, 'number' => $number, 'total_cents' => $pricing['total_cents']]);

    commerceMailSend(commerceOrderLetter(
        $number,
        $checkout['customer_email'],
        $checkout['customer_name'],
        'Ваш заказ №' . $number . ' принят. Мы напишем, когда отправим.',
        $lines,
        $pricing,
        $currency
    ));

    // Webhooks заказов (M6): событие создания уходит после письма покупателю,
    // до возврата в контроллер. Сбой доставки ронять оформление не умеет.
    commerceWebhookEvent('order.created', $id);

    return [
        'ok' => true,
        'error' => '',
        'id' => $id,
        'number' => $number,
        'total_cents' => $pricing['total_cents'],
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
        'SELECT id, number, customer_name, customer_email, currency, total, coupon_code, coupon_discount,'
        . ' promo_title, promo_discount, status, created_at'
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
            'SELECT id, number, customer_name, customer_email, currency, total, coupon_code, coupon_discount,'
            . ' promo_title, promo_discount, status, created_at'
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
 * Смена статуса заказа. Статусы идут по жизненному циклу COMMERCE_ORDER_FLOW:
 * заказ нельзя перепрыгнуть («оплачен» минуя «новый») и нельзя вернуть из
 * конечного статуса. Отмена до отправки возвращает остаток против списания —
 * товар снова увидит витрина. После смены покупателю уходит письмо.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceSetOrderStatus(string $orderId, string $status): array
{
    $order = one('SELECT id, status, coupon_code FROM ' . t('commerce_order') . ' WHERE id = ?', [$orderId]);

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

    if (in_array($status, COMMERCE_ORDER_FLOW[$previous] ?? [], true) === false) {
        // Отменить можно только до отправки: после неё позиции уже выбраны со склада.
        if ($status === 'CANCELLED' && in_array($previous, ['SHIPPED', 'DONE'], true)) {
            return [
                'ok' => false,
                'error' => 'Отправленный или завершённый заказ отменить нельзя.',
                'notice' => '',
            ];
        }

        return [
            'ok' => false,
            'error' => 'Статус «' . COMMERCE_ORDER_STATUS_LABELS[$previous]
                . '» не переходит в «' . COMMERCE_ORDER_STATUS_LABELS[$status]
                . '» — ход строго по цепочке: Новый, Оплачен, Отправлен, Доставлен.',
            'notice' => '',
        ];
    }

    // Купон держит код на руках: возврат визита и письмо живут своей сделкой.
    $couponCode = (string) $order['coupon_code'];

    // Статус перепроверяется самой строкой: два параллельных запроса прочли один и тот же
    // статус, а поменять строку успеет один — второй ответит отказом «уже изменился», без
    // двойного возврата остатков и без обхода цепочки (прочитан NEW, поменялся в SHIPPED —
    // отмена от чудесного SHIPPED→CANCELLED не пройдёт: строка уже не NEW).
    $updated = tx(static function () use ($orderId, $status, $previous, $couponCode): bool {
        $updated = run(
            'UPDATE ' . t('commerce_order') . ' SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
            [$status, utcStamp(nowUtc()), $orderId, $previous]
        );

        if ($updated !== 1) {
            return false;
        }

        if ($status === 'CANCELLED') {
            // Товарные позиции возвращают остаток товару, вариантные — варианту.
            run(
                'UPDATE ' . t('commerce_product') . ' p'
            . ' JOIN ' . t('commerce_order_item') . ' oi ON oi.product_id = p.id'
            . ' SET p.stock = p.stock + oi.qty'
            . ' WHERE oi.order_id = ? AND oi.variant_id IS NULL',
                [$orderId]
            );

            run(
                'UPDATE ' . t('commerce_variant') . ' v'
            . ' JOIN ' . t('commerce_order_item') . ' oi ON oi.variant_id = v.id'
            . ' SET v.stock = v.stock + oi.qty WHERE oi.order_id = ?',
                [$orderId]
            );

            // Купона визит возвращается: сделка не состоялась, и скидка не сгорает зря.
            commerceReturnCouponCount((string) $couponCode);
        }

        return true;
    });

    if (!$updated) {
        return [
            'ok' => false,
            'error' => 'Статус заказа уже изменился — обновите список.',
            'notice' => '',
        ];
    }

    logInfo('commerce.order_status', ['orderId' => $orderId, 'status' => $status, 'previous' => $previous]);

    commerceMailStatusChange($orderId);

    // Webhooks заказов (M6): смена статуса уходит письмом и приёмникам; сбой
    // доставки не имеет права изменить исход правки статуса.
    commerceWebhookEvent('order.status_changed', $orderId);

    // Действие для разделов, продающих услуги через заказы магазина (доска C5):
    // подписчик применяет оплату своих услуг или закрывает их при отмене. Сбой
    // подписчика не имеет права изменить исход правки статуса, как и webhook:
    // статус уже записан, поэтому падение подписчика ловится и пишется в журнал.
    try {
        doAction('commerce_order_status', $orderId, $status, $previous);
    } catch (\Throwable $error) {
        logWarn('commerce.order_status_action', ['orderId' => $orderId, 'reason' => $error->getMessage()]);
    }

    return ['ok' => true, 'error' => '', 'notice' => 'order_updated'];
}

/**
 * Письмо покупателю о смене статуса. Данные заново читаются базой: на экране
 * приходят только идентификаторы, а подпись письма обязана быть своей строкой.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceMailStatusChange(string $orderId): array
{
    $order = one(
        'SELECT number, customer_name, customer_email, currency, total, coupon_code, coupon_discount,'
        . ' promo_title, promo_discount, status'
        . ' FROM ' . t('commerce_order') . ' WHERE id = ?',
        [$orderId]
    );

    if ($order === null) {
        return ['ok' => false, 'error' => '', 'notice' => ''];
    }

    $label = (string) (COMMERCE_ORDER_STATUS_LABELS[(string) $order['status']] ?? (string) $order['status']);
    $lines = array_map(
        /**
         * Строка письма по позиции заказа.
         * @param array{title: mixed, sku: mixed, qty: mixed, unit_price: mixed, currency: mixed} $row
         * @return array{title: string, sku: string, qty: int, price_cents: int, currency: string}
         */
        static fn (array $row): array => [
            'title' => (string) $row['title'],
            'sku' => (string) $row['sku'],
            'qty' => (int) $row['qty'],
            'price_cents' => commerceCents((float) $row['unit_price']),
            'currency' => (string) $row['currency'],
        ],
        commerceOrderItems($orderId)
    );
    $totalCents = commerceCents((float) $order['total']);
    $pricing = [
        'coupon_code' => (string) ($order['coupon_code'] ?? ''),
        'coupon_discount_cents' => commerceCents((float) ($order['coupon_discount'] ?? 0)),
        'promo_title' => (string) ($order['promo_title'] ?? ''),
        'promo_discount_cents' => commerceCents((float) ($order['promo_discount'] ?? 0)),
        'total_cents' => $totalCents,
    ];

    commerceMailSend(commerceOrderLetter(
        (int) $order['number'],
        (string) $order['customer_email'],
        (string) $order['customer_name'],
        'Статус вашего заказа №' . (string) $order['number'] . ' теперь «' . $label . '».',
        $lines,
        $pricing,
        (string) $order['currency']
    ));

    return ['ok' => true, 'error' => '', 'notice' => ''];
}
