<?php

declare(strict_types=1);

// Webhooks (M6): магазин сам сообщает событие заказа внешнему приёмнику. Payload
// собирается из фактов заказа, подпись зовёт HMAC-SHA256 телом события и секретом
// строки вебхука. Транспорт отделён фильтром commerce_webhook_transport — приёмку
// может подменить любой раздел без правки ядра. Ни один сбой доставки не роняет
// оформление: причина уезжает в лог и в last_state.

/**
 * Список вебхуков для экрана админки: новые сверху.
 *
 * @return list<array<string, mixed>>
 */
function commerceWebhooksList(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT id, url, secret, active, last_state, last_at, created_at, updated_at'
        . ' FROM ' . t('commerce_webhook') . ' ORDER BY created_at DESC, id',
        []
    );

    return $rows;
}

/** @return array{id: string, url: string, secret: string, active: int}|null */
function commerceWebhook(string $id): ?array
{
    /** @var array{id: string, url: string, secret: string, active: int}|null $row */
    $row = one(
        'SELECT id, url, secret, active FROM ' . t('commerce_webhook') . ' WHERE id = ?',
        [$id]
    );

    return $row;
}

/**
 * Разбор формы вебхука: принять можно только http/https-адрес и секрет понятного
 * размера. Секрет держится в чужих руках никаких: он подписывает события.
 *
 * @param array<string, string|int|null> $post
 * @return array{values: array{url: string, secret: string, active: int}, errors: array<string, string>}
 */
function commerceValidateWebhook(array $post): array
{
    $values = [
        'url' => trim((string) ($post['url'] ?? '')),
        'secret' => trim((string) ($post['secret'] ?? '')),
        'active' => isOn((string) ($post['active'] ?? '')) ? 1 : 0,
    ];
    $errors = [];

    if ($values['url'] === '') {
        $errors['url'] = 'Укажите адрес приёмника.';
    } elseif (mb_strlen($values['url']) > COMMERCE_WEBHOOK_URL_MAX) {
        $errors['url'] = 'Адрес не длиннее ' . COMMERCE_WEBHOOK_URL_MAX . ' символов.';
    } elseif (preg_match('#^https?://.+#i', $values['url']) !== 1) {
        $errors['url'] = 'Адрес начинается с http:// или https://.';
    }

    if ($values['secret'] === '') {
        $errors['secret'] = 'Укажите секрет — он подписывает события.';
    } elseif (mb_strlen($values['secret']) < COMMERCE_WEBHOOK_SECRET_MIN) {
        $errors['secret'] = 'Секрет не короче ' . COMMERCE_WEBHOOK_SECRET_MIN . ' символов.';
    } elseif (mb_strlen($values['secret']) > COMMERCE_WEBHOOK_SECRET_MAX) {
        $errors['secret'] = 'Секрет не длиннее ' . COMMERCE_WEBHOOK_SECRET_MAX . ' символов.';
    }

    return ['values' => $values, 'errors' => $errors];
}

/** Свежий секрет для новой строки: машина его сама и предлагает. */
function commerceWebhookSecretSuggest(): string
{
    return substr(bin2hex(random_bytes(24)), 0, 40) . '-' . substr((string) nowUtc()->getTimestamp(), -6);
}

/**
 * Новый вебхук. Значения уже собраны validate-ом; validate принимает и пустую строку
 * секрета не умеет — вызывающий код подставляет suggestion до разбора.
 *
 * @param array{url: string, secret: string, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCreateWebhook(array $values): array
{
    $id = newId();
    $moment = nowUtc();

    insert('commerce_webhook', [
        'id' => $id,
        'url' => $values['url'],
        'secret' => $values['secret'],
        'active' => $values['active'],
        'last_state' => null,
        'last_at' => null,
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('commerce.webhook_created', ['webhookId' => $id]);

    return ['ok' => true, 'error' => '', 'notice' => 'webhook_saved'];
}

/**
 * Правка вебхука: адрес, секрет и флажок активности. Ряд unrushed строк gone —
 * причина печатается на форме.
 *
 * @param array{url: string, secret: string, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceUpdateWebhook(string $id, array $values): array
{
    $row = commerceWebhook($id);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Вебхука уже нет — обновите список.', 'notice' => ''];
    }

    run('UPDATE ' . t('commerce_webhook')
        . ' SET url = ?, secret = ?, active = ?, updated_at = ? WHERE id = ?', [
        $values['url'],
        $values['secret'],
        $values['active'],
        utcStamp(nowUtc()),
        $id,
    ]);

    return ['ok' => true, 'error' => '', 'notice' => 'webhook_saved'];
}

/** @return array{ok: bool, error: string, notice: string} */
function commerceDeleteWebhook(string $id): array
{
    $done = run('DELETE FROM ' . t('commerce_webhook') . ' WHERE id = ?', [$id]) === 1;

    if (!$done) {
        return ['ok' => false, 'error' => 'Вебхука уже нет — обновите список.', 'notice' => ''];
    }

    return ['ok' => true, 'error' => '', 'notice' => 'webhook_deleted'];
}

/** Слово последней доставки для экрана: честное «доставлено» или причина отказа. */
function commerceWebhookRecord(string $id, string $state): void
{
    run('UPDATE ' . t('commerce_webhook')
        . ' SET last_state = ?, last_at = ? WHERE id = ?', [
        mb_substr($state, 0, 200),
        utcStamp(nowUtc()),
        $id,
    ]);
}

/**
 * Каркас события: список активных вебхуков к одному приёмнику за приёмку. Секрет
 * в заранее подписанном виде не разлетается — подпись уходит заголовком POST.
 *
 * @return list<array{id: string, url: string, secret: string, active: int}>
 */
function commerceWebhookReceivers(): array
{
    /** @var list<array{id: string, url: string, secret: string, active: int}> $rows */
    $rows = all(
        'SELECT id, url, secret, active FROM ' . t('commerce_webhook')
        . ' WHERE active = 1 ORDER BY created_at, id',
        []
    );

    return $rows;
}

/**
 * Тело события заказа: только факты, что есть в базе. Состав строк сводится счётом:
 * внешний приёмник может запросить детали заказом API, а не экранным потрохом.
 *
 * @param array<string, mixed> $order
 * @return string
 */
function commerceWebhookPayload(string $event, array $order): string
{
    $items = (int) value(
        'SELECT COUNT(*) FROM ' . t('commerce_order_item') . ' WHERE order_id = ?',
        [$order['id']]
    );

    $payload = [
        'event' => $event,
        'number' => (int) $order['number'],
        'status' => (string) $order['status'],
        'total' => number_format((float) $order['total'], 2, '.', ''),
        'currency' => (string) $order['currency'],
        'customer_email' => (string) $order['customer_email'],
        'items' => $items,
        'at' => utcStamp(nowUtc()),
    ];

    return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Толчок события по заказу: строка заказа читается базой (звонящий передаёт только
 * идентификатор), отсутствующий заказ молчит. Webhooks без активных приёмников
 * не звонят вовсе — ни одного curl из пустого каталога.
 */
function commerceWebhookEvent(string $event, string $orderId): void
{
    $order = one(
        'SELECT id, number, status, total, currency, customer_email'
        . ' FROM ' . t('commerce_order') . ' WHERE id = ?',
        [$orderId]
    );

    if ($order === null) {
        return;
    }

    try {
        commerceWebhookSend($event, $order);
    } catch (\Throwable $error) {
        logWarn('commerce.webhook_event', ['reason' => $error->getMessage()]);
    }
}

/**
 * Доставка события: каждый активный вебхук получает своё письмо. Транспорт
 * подменяется фильтром commerce_webhook_transport: default — curl с таймаутом;
 * подменённый callable получает (webhook, event, payload) и отвечает bool.
 *
 * @param array<string, mixed> $order
 */
function commerceWebhookSend(string $event, array $order): void
{
    $receivers = commerceWebhookReceivers();

    if ($receivers === []) {
        return;
    }

    $payload = commerceWebhookPayload($event, $order);

    foreach ($receivers as $receiver) {
        // Фильтр возвращает callable транспорта; без подписчиков — уводится в default.
        $transport = applyFilters('commerce_webhook_transport', null, $receiver, $event, $payload);

        $delivered = $transport instanceof \Closure
            ? (bool) $transport($receiver, $event, $payload)
            : commerceWebhookTransportDefault($receiver, $event, $payload);

        if ($delivered) {
            commerceWebhookRecord((string) $receiver['id'], 'Доставлено');
            continue;
        }

        logWarn('commerce.webhook_failed', [
            'webhookId' => (string) $receiver['id'],
            'event' => $event,
        ]);
        commerceWebhookRecord((string) $receiver['id'], 'Не доставлено — проверьте адрес события.');
    }
}

/**
 * Подпись события: HMAC-SHA256 по телу, ключ — секрет вебхука. Одна фабрика для
 * штатного транспорта и для приёмников: расползшаяся вдвоём подпись живёт своей жизнью.
 */
function commerceWebhookSignature(string $payload, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, $secret);
}

/**
 * Штатный транспорт. Код 2xx считается доставкой, всё остальные коды и ошибки
 * сети — отказ. Секрет не печатается в ответах: подпись ходит только заголовком.
 *
 * @param array{id: string, url: string, secret: string, active: int} $receiver
 */
function commerceWebhookTransportDefault(array $receiver, string $event, string $payload): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $handle = curl_init((string) $receiver['url']);

    if ($handle === false) {
        return false;
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => COMMERCE_WEBHOOK_TIMEOUT,
        CURLOPT_TIMEOUT => COMMERCE_WEBHOOK_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'X-Commerce-Event: ' . $event,
            'X-Commerce-Signature: ' . commerceWebhookSignature($payload, (string) $receiver['secret']),
        ],
    ]);

    $body = curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = (string) (curl_error($handle) ?: '');
    curl_close($handle);

    // Отказ сети — единственный случай, когда транспорт говорит за себя в журнале:
    // коды не-2xx commerceWebhookSend() уже замечает как «Не доставлено».
    if ($body === false) {
        logWarn('commerce.webhook_transport', ['reason' => $error !== '' ? $error : 'транспорт не ответил']);
    }

    return commerceWebhookVerdict(is_string($body) ? $body : false, $code);
}

/**
 * Вердикт доставки по ответу транспорта: код 2xx — доставка, всё остальное — отказ.
 * Тело ничего не решает: приёмники используют 204 и 200 с пустым телом, чтобы сказать
 * «принято, возвращать нечего» — раньше пустой ответ уводился в отказ раньше проверки
 * кода, и честная доставка съезжала в «Не доставлено». Отказ сети выражается ровно
 * одним значением — false от curl_exec: в этой ветке код ответа доверять нельзя.
 *
 * @param string|false $body сырой ответ curl_exec: строка — полученный ответ, false — отказ сети
 */
function commerceWebhookVerdict(string|false $body, int $code): bool
{
    if ($body === false) {
        return false;
    }

    return $code >= 200 && $code < 300;
}
