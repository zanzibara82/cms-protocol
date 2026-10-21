<?php

declare(strict_types=1);

// Вебхуки доски (C6): события объявлений и переписки уходят внешнему приёмнику.
// Механика повторяет магазинскую (M6): тело подписывается HMAC-SHA256 секретом строки
// приёмника, транспорт отделён фильтром classifieds_webhook_transport, сбой доставки не
// роняет само действие — причина уезжает в лог и в last_state.
//
// Почему своя копия механики, а не общий модуль ядра: магазинская привязана к своей
// таблице и к событиям заказов, перенос её в ядро тронул бы уже сданную работу M6 и
// потребовал бы переноса данных приёмников. Раздел держит свою копию; общий модуль —
// отдельная задача, когда перенос данных можно будет сделать безопасно.

/** События доски, на которые можно подписаться: ключ — имя события, значение — подпись. */
const CLASSIFIEDS_WEBHOOK_EVENTS = [
    'listing.published' => 'Объявление опубликовано',
    'listing.rejected' => 'Объявление отклонено проверкой',
    'listing.hidden' => 'Объявление снято с публикации',
    'message.received' => 'Продавцу пришло сообщение',
];

const CLASSIFIEDS_WEBHOOK_URL_MAX = 500;
const CLASSIFIEDS_WEBHOOK_SECRET_MIN = 20;
const CLASSIFIEDS_WEBHOOK_SECRET_MAX = 200;
const CLASSIFIEDS_WEBHOOK_TIMEOUT = 5;

/**
 * Список вебхуков для экрана админки: новые сверху.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsWebhooksList(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT id, event, url, active, last_state, last_at, created_at, updated_at'
        . ' FROM ' . t('classifieds_webhook') . ' ORDER BY created_at DESC, id',
        []
    );

    return $rows;
}

/** @return array{id: string, event: string, url: string, secret: string, active: int}|null */
function classifiedsWebhook(string $id): ?array
{
    /** @var array{id: string, event: string, url: string, secret: string, active: int}|null $row */
    $row = one(
        'SELECT id, event, url, secret, active FROM ' . t('classifieds_webhook') . ' WHERE id = ?',
        [$id]
    );

    return $row;
}

/**
 * Разбор формы вебхука: принять можно только http/https-адрес, известное событие и
 * секрет понятного размера.
 *
 * @param array<string, string|int|null> $post
 * @return array{values: array{url: string, secret: string, event: string, active: int}, errors: array<string, string>}
 */
function classifiedsValidateWebhook(array $post): array
{
    $values = [
        'url' => trim((string) ($post['url'] ?? '')),
        'secret' => trim((string) ($post['secret'] ?? '')),
        'event' => trim((string) ($post['event'] ?? '')),
        'active' => isOn((string) ($post['active'] ?? '')) ? 1 : 0,
    ];
    $errors = [];

    if ($values['url'] === '') {
        $errors['url'] = 'Укажите адрес приёмника.';
    } elseif (mb_strlen($values['url']) > CLASSIFIEDS_WEBHOOK_URL_MAX) {
        $errors['url'] = 'Адрес не длиннее ' . CLASSIFIEDS_WEBHOOK_URL_MAX . ' символов.';
    } elseif (preg_match('#^https?://.+#i', $values['url']) !== 1) {
        $errors['url'] = 'Адрес начинается с http:// или https://.';
    }

    if ($values['secret'] === '') {
        $errors['secret'] = 'Укажите секрет — он подписывает события.';
    } elseif (mb_strlen($values['secret']) < CLASSIFIEDS_WEBHOOK_SECRET_MIN) {
        $errors['secret'] = 'Секрет не короче ' . CLASSIFIEDS_WEBHOOK_SECRET_MIN . ' символов.';
    } elseif (mb_strlen($values['secret']) > CLASSIFIEDS_WEBHOOK_SECRET_MAX) {
        $errors['secret'] = 'Секрет не длиннее ' . CLASSIFIEDS_WEBHOOK_SECRET_MAX . ' символов.';
    }

    if (!array_key_exists($values['event'], CLASSIFIEDS_WEBHOOK_EVENTS)) {
        $errors['event'] = 'Выберите событие — на неизвестное подписаться нельзя.';
    }

    return ['values' => $values, 'errors' => $errors];
}

/** Свежий секрет для новой строки: машина его сама и предлагает. */
function classifiedsWebhookSecretSuggest(): string
{
    return substr(bin2hex(random_bytes(24)), 0, 40) . '-' . substr((string) nowUtc()->getTimestamp(), -6);
}

/**
 * Новый вебхук. Значения уже собраны validate-ом.
 *
 * @param array{url: string, secret: string, event: string, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsCreateWebhook(array $values): array
{
    $id = newId();
    $moment = nowUtc();

    insert('classifieds_webhook', [
        'id' => $id,
        'event' => $values['event'],
        'url' => $values['url'],
        'secret' => $values['secret'],
        'active' => $values['active'],
        'last_state' => null,
        'last_at' => null,
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('classifieds.webhook_created', ['webhookId' => $id, 'event' => $values['event']]);

    return ['ok' => true, 'error' => '', 'notice' => 'webhook_saved'];
}

/**
 * Правка вебхука: событие, адрес, секрет и флажок активности.
 *
 * @param array{url: string, secret: string, event: string, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsUpdateWebhook(string $id, array $values): array
{
    $row = classifiedsWebhook($id);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Вебхука уже нет — обновите список.', 'notice' => ''];
    }

    run('UPDATE ' . t('classifieds_webhook')
        . ' SET event = ?, url = ?, secret = ?, active = ?, updated_at = ? WHERE id = ?', [
        $values['event'],
        $values['url'],
        $values['secret'],
        $values['active'],
        utcStamp(nowUtc()),
        $id,
    ]);

    return ['ok' => true, 'error' => '', 'notice' => 'webhook_saved'];
}

/** @return array{ok: bool, error: string, notice: string} */
function classifiedsDeleteWebhook(string $id): array
{
    $done = run('DELETE FROM ' . t('classifieds_webhook') . ' WHERE id = ?', [$id]) === 1;

    if (!$done) {
        return ['ok' => false, 'error' => 'Вебхука уже нет — обновите список.', 'notice' => ''];
    }

    return ['ok' => true, 'error' => '', 'notice' => 'webhook_deleted'];
}

/** Слово последней доставки для экрана: честное «доставлено» или причина отказа. */
function classifiedsWebhookRecord(string $id, string $state): void
{
    run('UPDATE ' . t('classifieds_webhook')
        . ' SET last_state = ?, last_at = ? WHERE id = ?', [
        mb_substr($state, 0, 200),
        utcStamp(nowUtc()),
        $id,
    ]);
}

/**
 * Приёмники события: активные строки, подписанные именно на это событие.
 *
 * @return list<array{id: string, url: string, secret: string, active: int}>
 */
function classifiedsWebhookReceivers(string $event): array
{
    /** @var list<array{id: string, url: string, secret: string, active: int}> $rows */
    $rows = all(
        'SELECT id, url, secret, active FROM ' . t('classifieds_webhook')
        . ' WHERE active = 1 AND event = ? ORDER BY created_at, id',
        [$event]
    );

    return $rows;
}

/**
 * Тело события: только факты, что есть в базе. Секрет в заранее подписанном виде не
 * разлетается — подпись уходит заголовком POST.
 *
 * @param array<string, mixed> $data
 */
function classifiedsWebhookPayload(string $event, array $data): string
{
    $payload = [
        'event' => $event,
        'at' => utcStamp(nowUtc()),
    ] + $data;

    return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Данные объявления для события: факты, что уже есть в базе. Потрохов (переписка,
 * личные данные продавца) в теле события нет — приёмник сам заберёт детали.
 *
 * @param array<string, mixed> $listing
 * @return array<string, mixed>
 */
function classifiedsWebhookListingData(array $listing): array
{
    return [
        'listing' => [
            'id' => (string) $listing['id'],
            'slug' => (string) $listing['slug'],
            'title' => (string) $listing['title'],
            'price' => $listing['price'] === null ? null : (float) $listing['price'],
            'currency' => (string) $listing['currency'],
            'status' => (string) $listing['status'],
            'category_id' => (string) $listing['category_id'],
            'url' => absoluteUrl(classifiedsListingHref((string) $listing['slug'])),
        ],
        'seller_id' => (string) $listing['owner_id'],
    ];
}

/**
 * Толчок события. Ни один сбой доставки не должен помешать самому действию: ошибка
 * уезжает в лог, а приёмник увидит её в своём last_state.
 *
 * @param array<string, mixed> $data
 */
function classifiedsWebhookEvent(string $event, array $data): void
{
    if (!array_key_exists($event, CLASSIFIEDS_WEBHOOK_EVENTS)) {
        return;
    }

    try {
        classifiedsWebhookSend($event, $data);
    } catch (\Throwable $error) {
        logWarn('classifieds.webhook_event', ['event' => $event, 'reason' => $error->getMessage()]);
    }
}

/**
 * Доставка события: каждый активный приёмник события получает своё письмо. Транспорт
 * подменяется фильтром classifieds_webhook_transport: default — curl с таймаутом;
 * подменённый callable получает (webhook, event, payload) и отвечает bool.
 *
 * @param array<string, mixed> $data
 */
function classifiedsWebhookSend(string $event, array $data): void
{
    $receivers = classifiedsWebhookReceivers($event);

    if ($receivers === []) {
        return;
    }

    $payload = classifiedsWebhookPayload($event, $data);

    foreach ($receivers as $receiver) {
        // Фильтр возвращает callable транспорта; без подписчиков — уводится в default.
        $transport = applyFilters('classifieds_webhook_transport', null, $receiver, $event, $payload);

        $delivered = $transport instanceof \Closure
            ? (bool) $transport($receiver, $event, $payload)
            : classifiedsWebhookTransportDefault($receiver, $event, $payload);

        if ($delivered) {
            classifiedsWebhookRecord((string) $receiver['id'], 'Доставлено');
            continue;
        }

        logWarn('classifieds.webhook_failed', [
            'webhookId' => (string) $receiver['id'],
            'event' => $event,
        ]);
        classifiedsWebhookRecord((string) $receiver['id'], 'Не доставлено — проверьте адрес приёмника.');
    }
}

/**
 * Подпись события: HMAC-SHA256 по телу, ключ — секрет вебхука. Одна фабрика для штатного
 * транспорта и для приёмников: расползшаяся вдвоём подпись живёт своей жизнью.
 */
function classifiedsWebhookSignature(string $payload, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, $secret);
}

/**
 * Штатный транспорт. Код 2xx считается доставкой (в том числе 204 с пустым телом —
 * так отвечают многие приёмники), сетевая ошибка — отказом. Секрет не печатается
 * в ответах: подпись ходит только заголовком.
 *
 * @param array{id: string, url: string, secret: string, active: int} $receiver
 */
function classifiedsWebhookTransportDefault(array $receiver, string $event, string $payload): bool
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
        CURLOPT_CONNECTTIMEOUT => CLASSIFIEDS_WEBHOOK_TIMEOUT,
        CURLOPT_TIMEOUT => CLASSIFIEDS_WEBHOOK_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'X-Classifieds-Event: ' . $event,
            'X-Classifieds-Signature: ' . classifiedsWebhookSignature($payload, (string) $receiver['secret']),
        ],
    ]);

    $body = curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = (string) (curl_error($handle) ?: '');
    curl_close($handle);

    if ($body === false) {
        logWarn('classifieds.webhook_transport', ['reason' => $error !== '' ? $error : 'сеть недоступна']);

        return false;
    }

    return $code >= 200 && $code < 300;
}
