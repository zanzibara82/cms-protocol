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
 * Разбор адреса на части с проверкой схемы и учётных данных.
 *
 * Адрес вебхука — это куда сервер сам сделает запрос. Поэтому мало «начинается
 * с http://»: нужен ещё запрет внутренних диапазонов (см. commerceWebhookIpDenied).
 * Разбор отделён от разрешения имён: форма проверяет адрес мгновенно и без сети, а
 * доставка повторяет проверку с разрешением — имя может указывать внутрь и сменить
 * адрес между сохранением и отправкой (DNS rebinding).
 *
 * @return array{problem: string, host: string}
 */
function commerceWebhookUrlParse(string $url): array
{
    $parts = parse_url($url);

    if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
        return ['problem' => 'Адрес не читается: нет схемы или имени хоста.', 'host' => ''];
    }

    $scheme = strtolower((string) $parts['scheme']);

    if ($scheme !== 'http' && $scheme !== 'https') {
        return ['problem' => 'Адрес начинается с http:// или https://.', 'host' => ''];
    }

    // Логин и пароль в адресе уезжают в заголовок Authorization: секрет вебхука
    // не должен попасть в чужие руки этим путём.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['problem' => 'Адрес приёмника не содержит логина и пароля.', 'host' => ''];
    }

    $host = (string) $parts['host'];

    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }

    if ($host === '') {
        return ['problem' => 'Адрес не читается: нет имени хоста.', 'host' => ''];
    }

    return ['problem' => '', 'host' => $host];
}

/** Причина отказа адреса вебхука: пустая строка — адрес годен. */
function commerceWebhookUrlProblem(string $url, ?callable $resolve = null): string
{
    $parsed = commerceWebhookUrlParse($url);

    if ($parsed['problem'] !== '') {
        return $parsed['problem'];
    }

    $host = $parsed['host'];
    $literal = filter_var($host, FILTER_VALIDATE_IP);

    if ($literal !== false) {
        return commerceWebhookIpDenied((string) $literal)
            ? 'Адрес приёмника указывает на внутренний адрес сети.'
            : '';
    }

    /** @var list<string> $addresses */
    $addresses = $resolve !== null ? (array) $resolve($host) : commerceWebhookResolveHost($host);

    if ($addresses === []) {
        return 'Адрес приёмника не удалось разрешить в IP.';
    }

    foreach ($addresses as $address) {
        if ($address === '' || commerceWebhookIpDenied($address)) {
            return 'Адрес приёмника указывает на внутренний адрес сети.';
        }
    }

    return '';
}

/**
 * Проверка адреса для формы: без сети и без разрешения имён. Разрешение на сохранении
 * дало бы ложные отказы (чужой DNS на прогонной машине, переезд приёмника), а саму
 * защиту всё равно обязан повторить транспорт — перед запросом.
 */
function commerceWebhookUrlSavedProblem(string $url): string
{
    $parsed = commerceWebhookUrlParse($url);

    if ($parsed['problem'] !== '') {
        return $parsed['problem'];
    }

    $literal = filter_var($parsed['host'], FILTER_VALIDATE_IP);

    return $literal !== false && commerceWebhookIpDenied((string) $literal)
        ? 'Адрес приёмника указывает на внутренний адрес сети.'
        : '';
}

/**
 * Адреса имени хоста: IPv4 и IPv6. Разрешение вынесено отдельной функцией, чтобы
 * проверку можно было подменить в тесте и не зависеть от DNS прогонной машины.
 *
 * @return list<string>
 */
function commerceWebhookResolveHost(string $host): array
{
    $addresses = [];

    $four = @gethostbynamel($host);
    if (is_array($four)) {
        foreach ($four as $address) {
            if ($address !== '') {
                $addresses[] = $address;
            }
        }
    }

    $six = @dns_get_record($host, DNS_AAAA);
    if (is_array($six)) {
        foreach ($six as $record) {
            $value = $record['ipv6'] ?? null;
            if (is_string($value) && $value !== '') {
                $addresses[] = $value;
            }
        }
    }

    return array_values(array_unique($addresses));
}

/**
 * Внутренний ли адрес. Список сетей шире «приватных диапазонов» PHP: встроенные флаги
 * пропускают 100.64.0.0/10 (адреса операторов) и диапазоны документации, а нам нужен
 * запрет всего, что не является публичным адресом.
 */
function commerceWebhookIpDenied(string $ip): bool
{
    $binary = @inet_pton($ip);

    if ($binary === false) {
        return true;
    }

    // IPv4-в-IPv6 (::ffff:127.0.0.1) проверяется как тот же IPv4: иначе запрет обходится записью.
    if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
        $binary = substr($binary, 12);
    }

    foreach (COMMERCE_WEBHOOK_DENIED_NETWORKS as $network) {
        if (commerceWebhookCidrMatch($binary, $network)) {
            return true;
        }
    }

    return false;
}

/** Совпадение двоичного адреса с сетью в записи CIDR. */
function commerceWebhookCidrMatch(string $binary, string $cidr): bool
{
    $slash = strpos($cidr, '/');

    if ($slash === false) {
        return false;
    }

    $network = @inet_pton(substr($cidr, 0, $slash));
    $bits = (int) substr($cidr, $slash + 1);

    if ($network === false || strlen($network) !== strlen($binary)) {
        return false;
    }

    $whole = intdiv($bits, 8);

    if ($whole > 0 && substr($binary, 0, $whole) !== substr($network, 0, $whole)) {
        return false;
    }

    $rest = $bits % 8;

    if ($rest === 0 || $whole >= strlen($binary)) {
        return true;
    }

    $mask = (0xFF << (8 - $rest)) & 0xFF;

    return (ord($binary[$whole]) & $mask) === (ord($network[$whole]) & $mask);
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
    } else {
        $problem = commerceWebhookUrlSavedProblem($values['url']);

        if ($problem !== '') {
            $errors['url'] = $problem;
        }
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

    // Повторная проверка перед запросом: имя могло разрешиться внутрь уже после
    // сохранения (DNS rebinding), а строку в базе мог положить импорт или миграция.
    if (commerceWebhookUrlProblem((string) $receiver['url']) !== '') {
        logWarn('commerce.webhook_denied', ['webhookId' => (string) $receiver['id']]);

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
    // curl_close() с PHP 8.0 ничего не освобождает, а с 8.5 объявлен устаревшим.

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
