<?php

declare(strict_types=1);

// Платное продвижение (C5): покупка услуг заказами магазина. Кабинет создаёт заказ
// tbl_commerce_order со служебной позицией и строкой tbl_classifieds_promotion; после
// оплаты (PAID) заказ магазина вызывает хук, и услуга применяется объявлению.
// Ссылку FK на заказ не делаем: магазин может быть не установлен, а история продвижений
// остаётся читаемой по номеру заказа. Статусы: NEW — ждёт оплату, ACTIVE — применено,
// CANCELLED — заказ отменён до оплаты.

/** Продвижение доступно только вместе с активным магазином. */
function classifiedsPromotionsAvailable(): bool
{
    return isAppActive('commerce');
}

/**
 * Справочник услуг для кабинета: цена берётся из товара магазина с фиксированным SKU,
 * чтобы показанная цена и цена заказа не расходились. Нет товара — fallback-константа.
 *
 * @return array<string, array{kind: string, sku: string, title: string, price: float, currency: string, label: string}>
 */
function classifiedsPromotionServices(): array
{
    if (!classifiedsPromotionsAvailable()) {
        return [];
    }

    $out = [];

    foreach (CLASSIFIEDS_PROMO_KINDS as $kind => $label) {
        $sku = (string) CLASSIFIEDS_PROMO_SERVICE_SKU[$kind];
        $row = one(
            'SELECT price_retail, currency FROM ' . t('commerce_product') . ' WHERE sku = ?',
            [$sku]
        );

        $price = $row === null
            ? (float) CLASSIFIEDS_PROMO_FALLBACK_PRICES[$kind]
            : (float) $row['price_retail'];
        $currency = $row === null ? 'RUB' : (string) $row['currency'];

        $out[$kind] = [
            'kind' => (string) $kind,
            'sku' => $sku,
            'title' => (string) $label,
            'price' => $price,
            'currency' => $currency,
            'label' => commerceFormatMoney(commerceCents($price), $currency),
        ];
    }

    return $out;
}

/**
 * Товар службы магазина: при первой нужде создаётся черновиком магазина с фиксированным SKU.
 * Админ магазина задаёт ему свою цену/обложку — кабинет после создания читает цену оттуда.
 *
 * @return array{id: string, price: float, currency: string}
 */
function classifiedsPromotionEnsureService(string $kind): array
{
    $sku = (string) (CLASSIFIEDS_PROMO_SERVICE_SKU[$kind] ?? '');
    $title = (string) (CLASSIFIEDS_PROMO_SERVICE_TITLES[$kind] ?? '');
    $fallbackPrice = (float) (CLASSIFIEDS_PROMO_FALLBACK_PRICES[$kind] ?? 0.0);
    $moment = utcStamp(nowUtc());
    $row = one(
        'SELECT id, price_retail, currency FROM ' . t('commerce_product') . ' WHERE sku = ?',
        [$sku]
    );

    if ($row === null) {
        $id = newId();
        insert('commerce_product', [
            'id' => $id,
            'slug' => $sku,
            'sku' => $sku,
            'title' => $title,
            'price_retail' => $fallbackPrice,
            'currency' => 'RUB',
            'stock' => 0,
            'status' => 'DRAFT',
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
        logInfo('classifieds.promotion_service_created', ['sku' => $sku, 'id' => $id]);

        return ['id' => $id, 'price' => $fallbackPrice, 'currency' => 'RUB'];
    }

    return [
        'id' => (string) $row['id'],
        'price' => (float) $row['price_retail'],
        'currency' => (string) $row['currency'],
    ];
}

/**
 * Покупка услуги: заказ магазина + строка продвижения. Повтор покупки разрешён — это
 * законный сценарий «поднять ещё раз» и «продлить ещё на один срок»; выделение при
 * активном окне отказом закрывается.
 *
 * @param array<string, string> $user строка tbl_user (id, email, name) продавца
 * @return array{ok: bool, error: string, order_id: string, order_number: int, notice: string}
 */
function classifiedsPromotionCreate(
    string $kind,
    string $listingId,
    array $user,
    ?DateTimeImmutable $now = null
): array {
    if (!array_key_exists($kind, CLASSIFIEDS_PROMO_KINDS)) {
        return classifiedsPromotionFailure('Такой услуги нет.');
    }

    if (!classifiedsPromotionsAvailable()) {
        return classifiedsPromotionFailure('Магазин не установлен — платное продвижение недоступно.');
    }

    $listing = classifiedsListingById($listingId);

    if ($listing === null || (string) $listing['owner_id'] !== (string) $user['id']) {
        return classifiedsPromotionFailure('Объявление не найдено — возможно, оно уже удалено.');
    }

    if ((string) $listing['status'] !== 'PUBLISHED') {
        return classifiedsPromotionFailure('Продвигать можно только опубликованное объявление.');
    }

    $moment = $now ?? nowUtc();

    if ($kind !== 'EXTEND' && classifiedsListingIsExpired($listing, $moment)) {
        return classifiedsPromotionFailure('Срок публикации истёк — продлите объявление платной услугой.');
    }

    if ($kind === 'FEATURED') {
        $until = fromDb((string) $listing['featured_until']);

        if ($until !== null && $until > $moment) {
            return classifiedsPromotionFailure('Объявление уже выделено до ' . formatRuDate($until) . '.');
        }
    }

    $orderId = newId();
    $stamp = utcStamp($moment);
    $kindLabel = (string) CLASSIFIEDS_PROMO_KINDS[$kind];
    $sku = (string) CLASSIFIEDS_PROMO_SERVICE_SKU[$kind];
    $serviceTitle = (string) CLASSIFIEDS_PROMO_SERVICE_TITLES[$kind];
    /** @var int $orderNumber */
    $orderNumber = tx(static function () use (
        $kind,
        $kindLabel,
        $sku,
        $serviceTitle,
        $listing,
        $user,
        $orderId,
        $stamp
    ): int {
        $service = classifiedsPromotionEnsureService($kind);

        insert('commerce_order', [
            'id' => $orderId,
            'customer_name' => (string) $user['name'],
            'customer_email' => (string) $user['email'],
            'customer_phone' => null,
            'address' => null,
            'comment' => 'Услуга доски: ' . $kindLabel,
            'currency' => $service['currency'],
            'total' => $service['price'],
            'status' => 'NEW',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $orderNumber = (int) value('SELECT number FROM ' . t('commerce_order') . ' WHERE id = ?', [$orderId]);

        insert('commerce_order_item', [
            'id' => newId(),
            'order_id' => $orderId,
            'product_id' => $service['id'],
            'variant_id' => null,
            'sku' => $sku,
            'title' => mb_substr($serviceTitle . ' — ' . (string) $listing['title'], 0, 160),
            'currency' => $service['currency'],
            'unit_price' => $service['price'],
            'qty' => 1,
        ]);

        insert('classifieds_promotion', [
            'id' => newId(),
            'listing_id' => (string) $listing['id'],
            'user_id' => (string) $user['id'],
            'kind' => $kind,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'amount' => $service['price'],
            'currency' => $service['currency'],
            'status' => 'NEW',
            'created_at' => $stamp,
            'applied_at' => null,
        ]);

        return $orderNumber;
    });

    logInfo('classifieds.promotion_ordered', [
        'kind' => $kind,
        'listingId' => (string) $listing['id'],
        'orderId' => $orderId,
        'orderNumber' => $orderNumber,
    ]);

    return [
        'ok' => true,
        'error' => '',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'notice' => 'promotion_ordered',
    ];
}

/**
 * Отказ покупки: форма результата совпадает с успешной, чтобы вызывающий код не ветвился.
 *
 * @return array{ok: bool, error: string, order_id: string, order_number: int, notice: string}
 */
function classifiedsPromotionFailure(string $error): array
{
    return [
        'ok' => false,
        'error' => $error,
        'order_id' => '',
        'order_number' => 0,
        'notice' => '',
    ];
}

/**
 * Хук смены статуса заказа магазина: подписи добавляяет boot.php. Оплата включает
 * услугу, отмена до оплаты закрывает её отказом. Раздел доски выключен — хук молчит.
 */
function classifiedsPromotionOrderStatus(string $orderId, string $status): void
{
    if (!isAppActive('classifieds')) {
        return;
    }

    if ($status === 'PAID') {
        classifiedsPromotionActivateByOrder($orderId);

        return;
    }

    if ($status === 'CANCELLED') {
        classifiedsPromotionCancelByOrder($orderId);
    }
}

/**
 * Применение оплаченной услуги. Строка выбирается только в статусе NEW: повторный
 * прогон хука по тому же заказу не применяет услугу второй раз.
 */
function classifiedsPromotionActivateByOrder(string $orderId): void
{
    $promo = one(
        'SELECT id, kind, listing_id FROM ' . t('classifieds_promotion') . " WHERE order_id = ? AND status = 'NEW'",
        [$orderId]
    );

    if ($promo === null) {
        return;
    }

    $moment = nowUtc();
    $kind = (string) $promo['kind'];
    $listingId = (string) $promo['listing_id'];

    tx(static function () use ($kind, $listingId, $promo, $moment): void {
        $stamp = utcStamp($moment);

        if ($kind === 'BUMP') {
            run(
                'UPDATE ' . t('classifieds_listing') . ' SET boosted_at = ? WHERE id = ?',
                [$stamp, $listingId]
            );
        } elseif ($kind === 'FEATURED') {
            run(
                'UPDATE ' . t('classifieds_listing') . ' SET featured_until = ? WHERE id = ?',
                [utcStamp($moment->modify('+' . CLASSIFIEDS_FEATURED_DAYS . ' days')), $listingId]
            );
        } elseif ($kind === 'EXTEND') {
            $listing = one('SELECT expires_at FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$listingId]);

            if ($listing !== null) {
                $base = fromDb((string) $listing['expires_at']);

                if ($base === null || $base < $moment) {
                    $base = $moment;
                }

                run(
                    'UPDATE ' . t('classifieds_listing') . ' SET expires_at = ? WHERE id = ?',
                    [utcStamp($base->modify('+' . CLASSIFIEDS_LISTING_DAYS . ' days')), $listingId]
                );
            }
        }

        run(
            'UPDATE ' . t('classifieds_promotion') . ' SET status = ?, applied_at = ? WHERE id = ?',
            ['ACTIVE', $stamp, (string) $promo['id']]
        );
    });

    logInfo('classifieds.promotion_applied', [
        'orderId' => $orderId,
        'kind' => $kind,
        'listingId' => $listingId,
    ]);
}

/** Отменённый до оплаты заказ закрывает покупку отказом, применённый не трогает. */
function classifiedsPromotionCancelByOrder(string $orderId): void
{
    $cancelled = run(
        'UPDATE ' . t('classifieds_promotion') . " SET status = 'CANCELLED' WHERE order_id = ? AND status = 'NEW'",
        [$orderId]
    );

    if ($cancelled === 1) {
        logInfo('classifieds.promotion_cancelled', ['orderId' => $orderId]);
    }
}

/**
 * Сумма услуги словами. Валюту знает магазин, поэтому без активного магазина сумма
 * не показывается: покупок тогда и быть не может.
 */
function classifiedsPromotionAmountLabel(?float $amount, string $currency): string
{
    if ($amount === null || !classifiedsPromotionsAvailable()) {
        return '';
    }

    return commerceFormatMoney(commerceCents($amount), $currency);
}

/**
 * Покупки продавца для кабинета: прямоугольник таблицы с адресом объявления.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsUserPromotions(string $userId): array
{
    return all(
        'SELECT p.id, p.kind, p.amount, p.currency, p.status, p.order_number, p.created_at, p.applied_at,'
        . ' l.slug AS listing_slug, l.title AS listing_title'
        . ' FROM ' . t('classifieds_promotion') . ' p'
        . ' JOIN ' . t('classifieds_listing') . ' l ON l.id = p.listing_id'
        . ' WHERE p.user_id = ?'
        . ' ORDER BY p.created_at DESC, p.id DESC',
        [$userId]
    );
}

/**
 * Реестр продвижений для админки: последние 200 строк с продавцом объявлением.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsAdminPromotions(): array
{
    return all(
        'SELECT p.id, p.kind, p.amount, p.currency, p.status, p.order_number, p.created_at, p.applied_at,'
        . ' l.slug AS listing_slug, l.title AS listing_title, u.email AS seller_email'
        . ' FROM ' . t('classifieds_promotion') . ' p'
        . ' JOIN ' . t('classifieds_listing') . ' l ON l.id = p.listing_id'
        . ' JOIN ' . t('user') . ' u ON u.id = p.user_id'
        . ' ORDER BY p.created_at DESC, p.id DESC'
        . ' LIMIT 200'
    );
}

/** Счётчики статусов продвижения для шапки экрана админки.
 * @return array{NEW: int, ACTIVE: int, CANCELLED: int}
 */
function classifiedsPromotionCounts(): array
{
    $counts = ['NEW' => 0, 'ACTIVE' => 0, 'CANCELLED' => 0];
    $rows = all(
        'SELECT status, COUNT(*) AS c FROM ' . t('classifieds_promotion') . ' GROUP BY status'
    );

    foreach ($rows as $row) {
        $key = (string) $row['status'];

        if (array_key_exists($key, $counts)) {
            $counts[$key] = (int) $row['c'];
        }
    }

    return $counts;
}
