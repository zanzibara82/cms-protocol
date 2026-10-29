<?php

declare(strict_types=1);

// Поисковая аналитика из официальных API (S4, спецификация §9). Раздел умеет две вещи:
// подключить официальный источник данных (Google Search Console) и показать то, что этот
// источник отдал. Своя аналитика (S2) и данные поисковика лежат в разных таблицах и не
// смешиваются: своя статистика считает наши собственные события, здесь лежит то, что прислал
// чужой API.
//
// Честность данных: показ и клик пришли от поисковика, а не от нас; запросы, которых поисковик
// не отдал, мы не достраиваем из рефереров; полной видимости органики не существует, и на экране
// написано, что выборка неполна.
//
// Секреты: клиентский секрет и токены лежат в базе и никогда не печатаются на экран обратно.
// Доступ к базе — доступ к интеграции, и об этом сказано прямо на экране подключения.
//
// Транспорт: запросы к внешнему API идут через seoSearchHttp(), и там есть фильтр
// 'seo_search_http' — им тесты подменяют ответ, не выходя в сеть. Реальный запрос идёт только
// по https, с проверкой сертификата, без переходов: токен не должен уехать на чужой адрес.

const SEO_SEARCH_OPTION_PROVIDER = 'seo_search_provider';
const SEO_SEARCH_OPTION_SITE = 'seo_search_site';
const SEO_SEARCH_OPTION_CLIENT_ID = 'seo_search_client_id';
const SEO_SEARCH_OPTION_REDIRECT = 'seo_search_redirect';
const SEO_SEARCH_OPTION_SECRET = 'seo_search_secret';
const SEO_SEARCH_OPTION_LAST_IMPORT = 'seo_search_last_import';

const SEO_SEARCH_PROVIDER_GOOGLE = 'google';
const SEO_SEARCH_PROVIDER_TEST = 'test';

const SEO_SEARCH_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
const SEO_SEARCH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const SEO_SEARCH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const SEO_SEARCH_API_URL = 'https://searchconsole.googleapis.com/webmasters/v3/sites';

// Лимиты импорта (спецификация §9 «лимиты»). Они же ограничивают расход квоты API: период
// длиннее потолка разбивается на части человеком, а не молчаливый обход лимитов поисковика.
const SEO_SEARCH_RANGE_MAX_DAYS = 92;
const SEO_SEARCH_ROWS_MAX = 25000;
const SEO_SEARCH_PAGE_MAX = 1000;
const SEO_SEARCH_IMPORT_INTERVAL = 60;
const SEO_SEARCH_HTTP_TIMEOUT = 20;
const SEO_SEARCH_HTTP_MAX = 4194304;
const SEO_SEARCH_STATE_TTL = 900;
const SEO_SEARCH_QUERY_MAX = 255;
const SEO_SEARCH_PAGE_URL_MAX = 255;

/**
 * Источники данных, которые раздел умеет читать.
 *
 * @return array<string, string>
 */
function seoSearchProviders(): array
{
    return [
        SEO_SEARCH_PROVIDER_GOOGLE => 'Google Search Console',
        SEO_SEARCH_PROVIDER_TEST => 'Тестовый источник',
    ];
}

/**
 * Измерения, по которым показываются строки. Тестовое «измерение» не назовём измерением
 * поисковика: в таблице видно, откуда строка.
 *
 * @return array<string, string>
 */
function seoSearchDimensions(): array
{
    return [
        'query' => 'Запросы',
        'page' => 'Страницы',
        'country' => 'Страны',
        'device' => 'Устройства',
    ];
}

/**
 * Ключ подписи состояния входа: он общий для раздела и нужен, чтобы подделать переход
 * «от поисковика» было нельзя. Создаётся при первом обращении — как и ключ счётчика.
 */
function seoSearchSecret(): string
{
    $secret = trim(getOption(SEO_SEARCH_OPTION_SECRET, ''));

    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        setOption(SEO_SEARCH_OPTION_SECRET, $secret);
    }

    return $secret;
}

/**
 * Настройки подключения: источник, адрес сайта в поисковике, идентификатор клиента и адрес
 * возврата. Секретов здесь нет — секреты лежат в таблице подключения.
 *
 * @return array{provider: string, site: string, clientId: string, redirectUri: string}
 */
function seoSearchSettings(): array
{
    $provider = trim(getOption(SEO_SEARCH_OPTION_PROVIDER, ''));
    $redirect = trim(getOption(SEO_SEARCH_OPTION_REDIRECT, ''));

    return [
        'provider' => array_key_exists($provider, seoSearchProviders()) ? $provider : '',
        'site' => trim(getOption(SEO_SEARCH_OPTION_SITE, '')),
        'clientId' => trim(getOption(SEO_SEARCH_OPTION_CLIENT_ID, '')),
        'redirectUri' => $redirect !== '' ? $redirect : seoSearchDefaultRedirect(),
    ];
}

/**
 * Адрес возврата по умолчанию: наш собственный обработчик входа. Он не приходит из строки
 * запроса — иначе токен ушёл бы туда, куда указал бы чужой.
 */
function seoSearchDefaultRedirect(): string
{
    return absoluteUrl('/seo/admin/search/callback');
}

/**
 * Подключение источника: строка из таблицы. null — источник не подключён.
 *
 * @return array<string, mixed>|null
 */
function seoSearchConnection(): ?array
{
    $settings = seoSearchSettings();

    if ($settings['provider'] === '' || $settings['site'] === '') {
        return null;
    }

    $row = one(
        'SELECT * FROM ' . t('seo_search_connection') . ' WHERE provider = ? AND site_url = ?',
        [$settings['provider'], $settings['site']]
    );

    return $row;
}

/**
 * Состояние подключения для экрана: 'none' — источник не настроен, 'configured' — настройки
 * есть, но токена нет, 'connected' — токен получен и им можно читать данные.
 */
function seoSearchState(): string
{
    $settings = seoSearchSettings();

    if ($settings['provider'] === '' || $settings['site'] === '') {
        return 'none';
    }

    $connection = seoSearchConnection();

    if ($connection === null) {
        return 'configured';
    }

    return (string) ($connection['access_token'] ?? '') !== '' ? 'connected' : 'configured';
}

/**
 * Подпись состояния перехода: метка времени, источник и случайный хвост. Подпись проверяется
 * при возврате из поисковика — без неё подделать «код» невозможно.
 */
function seoSearchStateToken(): string
{
    $settings = seoSearchSettings();
    $payload = implode('|', [$settings['provider'], $settings['site'], (string) utcTimestamp(nowUtc()), bin2hex(random_bytes(8))]);
    $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

    return $encoded . '.' . hash_hmac('sha256', $encoded, seoSearchSecret());
}

/**
 * Проверка подписи состояния. Возвращает false и на просроченной подписи: человек мог
 * оставить вкладку открытой и вернуться к ней через полчаса.
 */
function seoSearchStateValid(string $state): bool
{
    $parts = explode('.', $state);

    if (count($parts) !== 2) {
        return false;
    }

    [$encoded, $signature] = $parts;
    $expected = hash_hmac('sha256', $encoded, seoSearchSecret());

    if (!hash_equals($expected, $signature)) {
        return false;
    }

    $payload = base64_decode(strtr($encoded, '-_', '+/'), true);

    if (!is_string($payload)) {
        return false;
    }

    $fields = explode('|', $payload);

    if (count($fields) !== 4) {
        return false;
    }

    [$provider, $site, $stamp] = $fields;
    $settings = seoSearchSettings();

    if ($provider !== $settings['provider'] || $site !== $settings['site']) {
        return false;
    }

    return (utcTimestamp(nowUtc()) - (int) $stamp) <= SEO_SEARCH_STATE_TTL;
}

/**
 * Сохранение подключения. Пустой секрет при сохранении означает «оставить прежний»: секрет на
 * экране не показывается, поэтому иначе его нельзя было бы уберечь при повторной правке.
 *
 * @param array<string, string> $values
 */
function seoSearchSaveConnection(array $values): void
{
    $provider = (string) $values['provider'];
    $site = (string) $values['site'];
    $clientId = (string) $values['client_id'];
    $secret = trim((string) $values['client_secret']);
    $stamp = utcStamp(nowUtc());

    $existing = one(
        'SELECT * FROM ' . t('seo_search_connection') . ' WHERE provider = ? AND site_url = ?',
        [$provider, $site]
    );

    if ($existing === null) {
        insert('seo_search_connection', [
            'id' => newId(),
            'provider' => $provider,
            'site_url' => $site,
            'client_id' => $clientId,
            'client_secret' => $secret,
            'access_token' => '',
            'refresh_token' => '',
            'scope' => '',
            'expires_at' => null,
            'connected_at' => null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return;
    }

    run(
        'UPDATE ' . t('seo_search_connection')
        . ' SET client_id = ?, client_secret = ?, updated_at = ? WHERE id = ?',
        [
            $clientId,
            $secret !== '' ? $secret : (string) ($existing['client_secret'] ?? ''),
            $stamp,
            (string) $existing['id'],
        ]
    );
}

/**
 * Отключение источника: строка подключения удаляется целиком, вместе с токенами.
 */
function seoSearchDeleteConnection(): void
{
    $settings = seoSearchSettings();

    run(
        'DELETE FROM ' . t('seo_search_connection') . ' WHERE provider = ? AND site_url = ?',
        [$settings['provider'], $settings['site']]
    );
}

/**
 * Исходящий запрос. Фильтр 'seo_search_http' отдаёт готовый ответ — им пользуются тесты, и
 * сеть при них не трогается. Настоящий запрос идёт только по https, с проверкой сертификата
 * и без переходов: токен не должен уехать на адрес, указанный ответом.
 *
 * @param array{body?: string, form?: array<string, string>, headers?: list<string>} $options
 * @return array{status: int, body: string, error: string}
 */
function seoSearchHttp(string $method, string $url, array $options = []): array
{
    $stubbed = applyFilters('seo_search_http', null, $method, $url, $options);

    if (is_array($stubbed)) {
        return [
            'status' => (int) ($stubbed['status'] ?? 0),
            'body' => (string) ($stubbed['body'] ?? ''),
            'error' => (string) ($stubbed['error'] ?? ''),
        ];
    }

    if (!str_starts_with($url, 'https://')) {
        return ['status' => 0, 'body' => '', 'error' => 'Запрос разрешён только по https.'];
    }

    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'На хостинге нет расширения curl.'];
    }

    $handle = curl_init($url);

    if ($handle === false) {
        return ['status' => 0, 'body' => '', 'error' => 'Не удалось начать запрос.'];
    }

    $headers = $options['headers'] ?? [];
    $body = (string) ($options['body'] ?? '');

    if (isset($options['form'])) {
        $body = http_build_query($options['form']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => SEO_SEARCH_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Проверка сертификата и имени хоста обязательна: без неё токен можно было бы выманить
        // подменой сертификата. Переходы не разворачиваем: адрес назначения задаёт источник,
        // а не отвечающий сервер.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'NovaSEO/1.0 (+CMS)',
    ]);

    if ($body !== '') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if (!is_string($response)) {
        return ['status' => $status, 'body' => '', 'error' => $error !== '' ? $error : 'Ответ не получен.'];
    }

    if (strlen($response) > SEO_SEARCH_HTTP_MAX) {
        return ['status' => $status, 'body' => '', 'error' => 'Ответ слишком велик: импорт остановлен.'];
    }

    return ['status' => $status, 'body' => $response, 'error' => $error];
}

/**
 * Обмен кода на токены. Возвращает ['ok' => bool, 'error' => string, 'tokens' => array].
 *
 * @param array<string, string> $form
 * @return array{ok: bool, error: string, tokens: array<string, mixed>}
 */
function seoSearchTokenRequest(array $form): array
{
    $response = seoSearchHttp('POST', SEO_SEARCH_TOKEN_URL, ['form' => $form]);

    if ($response['error'] !== '') {
        return ['ok' => false, 'error' => 'Источник не ответил: ' . $response['error'], 'tokens' => []];
    }

    if ($response['status'] < 200 || $response['status'] >= 300) {
        return [
            'ok' => false,
            'error' => 'Источник отказал в выдаче токена (код ' . (string) $response['status'] . ').',
            'tokens' => [],
        ];
    }

    $data = json_decode($response['body'], true);

    if (!is_array($data) || !isset($data['access_token'])) {
        return ['ok' => false, 'error' => 'Источник вернул неожиданный ответ.', 'tokens' => []];
    }

    return ['ok' => true, 'error' => '', 'tokens' => $data];
}

/**
 * Обмен кода на токены для текущего источника.
 *
 * @return array{ok: bool, error: string, tokens: array<string, mixed>}
 */
function seoSearchExchange(string $code): array
{
    $settings = seoSearchSettings();
    $connection = seoSearchConnection();

    if ($settings['provider'] === SEO_SEARCH_PROVIDER_TEST) {
        // Тестовый источник входом не пользовался: кода у него нет и быть не может.
        return ['ok' => false, 'error' => 'Тестовый источник не требует входа.', 'tokens' => []];
    }

    $secret = (string) ($connection['client_secret'] ?? '');

    if ($secret === '') {
        return ['ok' => false, 'error' => 'Клиентский секрет не задан.', 'tokens' => []];
    }

    return seoSearchTokenRequest([
        'code' => $code,
        'client_id' => $settings['clientId'],
        'client_secret' => $secret,
        'redirect_uri' => $settings['redirectUri'],
        'grant_type' => 'authorization_code',
    ]);
}

/**
 * Обновление токена по сохранённому refresh-токену.
 *
 * @param array<string, mixed> $connection
 * @return array{ok: bool, error: string, tokens: array<string, mixed>}
 */
function seoSearchRefresh(array $connection): array
{
    $refresh = (string) ($connection['refresh_token'] ?? '');

    if ($refresh === '') {
        return ['ok' => false, 'error' => 'Обновляющего токена нет — нужен повторный вход.', 'tokens' => []];
    }

    $result = seoSearchTokenRequest([
        'refresh_token' => $refresh,
        'client_id' => (string) ($connection['client_id'] ?? ''),
        'client_secret' => (string) ($connection['client_secret'] ?? ''),
        'grant_type' => 'refresh_token',
    ]);

    if ($result['ok'] && !isset($result['tokens']['refresh_token'])) {
        // Источник не вернул новый refresh-токен: прежний остаётся в силе.
        $result['tokens']['refresh_token'] = $refresh;
    }

    return $result;
}

/**
 * Сохранение выданных токенов в строку подключения.
 *
 * @param array<string, mixed> $tokens
 */
function seoSearchStoreTokens(array $tokens): void
{
    $connection = seoSearchConnection();

    if ($connection === null) {
        return;
    }

    $expires = isset($tokens['expires_in'])
        ? (int) $tokens['expires_in']
        : 3600;
    $stamp = nowUtc();

    run(
        'UPDATE ' . t('seo_search_connection')
        . ' SET access_token = ?, refresh_token = ?, scope = ?, expires_at = ?, connected_at = ?, updated_at = ?'
        . ' WHERE id = ?',
        [
            (string) ($tokens['access_token'] ?? ''),
            (string) ($tokens['refresh_token'] ?? ($connection['refresh_token'] ?? '')),
            (string) ($tokens['scope'] ?? ($connection['scope'] ?? '')),
            utcStamp($stamp->modify('+' . $expires . ' seconds')),
            $connection['connected_at'] === null ? utcStamp($stamp) : (string) $connection['connected_at'],
            utcStamp($stamp),
            (string) $connection['id'],
        ]
    );
}

/**
 * Токен, готовый к запросу. Если срок истёк, токен обновляется; если обновиться не удалось —
 * честная причина, а не тишина.
 *
 * @param array<string, mixed> $connection
 * @return array{ok: bool, error: string, token: string}
 */
function seoSearchAccessToken(array $connection): array
{
    // Срок действия читается из базы как дата, а не как строка: сравнение строк с текущим
    // временем ошиблось бы на формате, а молчаливый «не истёк» обернулся бы отказом источника.
    $expires = fromDb((string) ($connection['expires_at'] ?? ''));

    if ($expires !== null && $expires <= nowUtc()) {
        $refreshed = seoSearchRefresh($connection);

        if (!$refreshed['ok']) {
            return ['ok' => false, 'error' => $refreshed['error'], 'token' => ''];
        }

        seoSearchStoreTokens($refreshed['tokens']);
        $connection = seoSearchConnection() ?? $connection;
    }

    $token = (string) ($connection['access_token'] ?? '');

    if ($token === '') {
        return ['ok' => false, 'error' => 'Токена нет — нужен вход в источник.', 'token' => ''];
    }

    return ['ok' => true, 'error' => '', 'token' => $token];
}

/**
 * Измерения для запроса к API: у каждого измерения свой набор колонок ответа.
 *
 * @return list<string>
 */
function seoSearchDimensionsFor(string $dimension): array
{
    return match ($dimension) {
        'page' => ['page'],
        'country' => ['country'],
        'device' => ['device'],
        default => ['query', 'page'],
    };
}

/**
 * Чтение строк из источника. Возвращает ['ok' => bool, 'error' => string, 'rows' => list].
 *
 * Параметр $retried закрывает повтор: 401 с живым refresh-токеном может не проходить и
 * после обновления (отозвано правило свойства, недостаточный scope) — без флага каждый
 * успешный refresh порождал новую попытку с тем же исходом, до переполнения стека.
 * Обещание комментария «обновляем и повторяем один раз» теперь держит и сам код.
 *
 * @param array<string, mixed> $connection
 * @return array{ok: bool, error: string, rows: list<array<string, mixed>>}
 */
function seoSearchFetchRows(array $connection, string $from, string $to, string $dimension, bool $retried = false): array
{
    $settings = seoSearchSettings();

    if ($settings['provider'] === SEO_SEARCH_PROVIDER_TEST) {
        return [
            'ok' => true,
            'error' => '',
            'rows' => seoSearchTestRows($from, $to, $dimension),
        ];
    }

    $token = seoSearchAccessToken($connection);

    if (!$token['ok']) {
        return ['ok' => false, 'error' => $token['error'], 'rows' => []];
    }

    $url = SEO_SEARCH_API_URL . '/' . rawurlencode((string) $connection['site_url']) . '/searchAnalytics/query';
    $body = json_encode([
        'startDate' => $from,
        'endDate' => $to,
        'dimensions' => seoSearchDimensionsFor($dimension),
        'rowLimit' => SEO_SEARCH_PAGE_MAX,
        'startRow' => 0,
        'dataState' => 'final',
    ]);

    $response = seoSearchHttp('POST', $url, [
        'body' => (string) $body,
        'headers' => [
            'Authorization: Bearer ' . $token['token'],
            'Content-Type: application/json',
        ],
    ]);

    if ($response['error'] !== '') {
        return ['ok' => false, 'error' => 'Источник не ответил: ' . $response['error'], 'rows' => []];
    }

    if ($response['status'] === 401) {
        // Токен истёк, а срок в базе не совпал: обновляем и повторяем один раз.
        // Второй 401 по свежему токену — честный отказ, а не новая сетевая попытка.
        if ($retried) {
            return ['ok' => false, 'error' => 'Источник отказал в доступе и после обновления токена.', 'rows' => []];
        }

        $refreshed = seoSearchRefresh($connection);

        if ($refreshed['ok']) {
            seoSearchStoreTokens($refreshed['tokens']);

            return seoSearchFetchRows(
                seoSearchConnection() ?? $connection,
                $from,
                $to,
                $dimension,
                true
            );
        }

        return ['ok' => false, 'error' => 'Источник отказал в доступе: ' . $refreshed['error'], 'rows' => []];
    }

    if ($response['status'] < 200 || $response['status'] >= 300) {
        return [
            'ok' => false,
            'error' => 'Источник ответил кодом ' . (string) $response['status'] . '.',
            'rows' => [],
        ];
    }

    $data = json_decode($response['body'], true);

    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Источник вернул неожиданный ответ.', 'rows' => []];
    }

    $rows = [];
    $keys = seoSearchDimensionsFor($dimension);

    foreach ((array) ($data['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $values = array_values((array) ($row['keys'] ?? []));
        $entry = [
            'day' => $from,
            'query' => '',
            'page' => '',
            'country' => '',
            'device' => '',
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
        ];

        foreach ($keys as $index => $name) {
            $value = mb_substr(trim((string) ($values[$index] ?? '')), 0, SEO_SEARCH_QUERY_MAX);
            $entry[$name] = $value;
        }

        $rows[] = $entry;
    }

    return ['ok' => true, 'error' => '', 'rows' => $rows];
}

/**
 * Строки тестового источника. Данные выдуманы по правилу: без сети, без случайности, один и
 * тот же день всегда даёт один и тот же результат. На экране такие строки помечены как
 * тестовые — иначе они сошли бы за настоящие показатели поисковика.
 *
 * @return list<array<string, mixed>>
 */
function seoSearchTestRows(string $from, string $to, string $dimension): array
{
    $queries = ['тестовый запрос', 'раздел каталога', 'как оформить заказ', 'контакты'];
    $pages = ['/', '/catalog', '/how-to', '/contacts'];
    $countries = ['ru', 'us', 'de'];
    $devices = ['desktop', 'mobile', 'tablet'];
    $rows = [];
    $day = parseUtcDay($from);
    $last = parseUtcDay($to);

    if ($day === null || $last === null) {
        return [];
    }

    while ($day <= $last && count($rows) < SEO_SEARCH_ROWS_MAX) {
        foreach ($queries as $index => $query) {
            $seed = crc32(utcDayKey($day) . '|' . $query);
            $impressions = 10 + ($seed % 90);
            $clicks = $impressions > 0 ? (int) floor($impressions * (($seed % 17) / 100)) : 0;
            $entry = [
                'day' => utcDayKey($day),
                'query' => '',
                'page' => '',
                'country' => '',
                'device' => '',
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round($clicks / $impressions, 5) : 0.0,
                'position' => round(1 + (($seed % 40) / 10), 2),
            ];

            match ($dimension) {
                'page' => $entry['page'] = $pages[$index % count($pages)],
                'country' => $entry['country'] = $countries[$index % count($countries)],
                'device' => $entry['device'] = $devices[$index % count($devices)],
                default => $entry['query'] = $query,
            };

            $rows[] = $entry;
        }

        $day = $day->modify('+1 day');
    }

    return $rows;
}

/**
 * Импорт данных за период. Проверяет лимиты до обращения к источнику: длинный период и
 * частые запуски — это расход квоты, а не «ещё немного данных».
 *
 * @return array{ok: bool, error: string, rows: int, stored: int}
 */
function seoSearchImport(string $from, string $to, string $dimension): array
{
    $settings = seoSearchSettings();

    if ($settings['provider'] === '' || $settings['site'] === '') {
        return ['ok' => false, 'error' => 'Источник не настроен.', 'rows' => 0, 'stored' => 0];
    }

    $start = parseUtcDay($from);
    $end = parseUtcDay($to);

    if ($start === null || $end === null) {
        return ['ok' => false, 'error' => 'Даты периода не поняты.', 'rows' => 0, 'stored' => 0];
    }

    if ($start > $end) {
        return ['ok' => false, 'error' => 'Начало периода позже конца.', 'rows' => 0, 'stored' => 0];
    }

    $days = (int) $start->diff($end)->days + 1;

    if ($days > SEO_SEARCH_RANGE_MAX_DAYS) {
        return [
            'ok' => false,
            'error' => 'Период длиннее ' . (string) SEO_SEARCH_RANGE_MAX_DAYS . ' дней: импортируйте по частям.',
            'rows' => 0,
            'stored' => 0,
        ];
    }

    $last = (int) getOption(SEO_SEARCH_OPTION_LAST_IMPORT, '0');

    if ($last > 0 && (utcTimestamp(nowUtc()) - $last) < SEO_SEARCH_IMPORT_INTERVAL) {
        $wait = SEO_SEARCH_IMPORT_INTERVAL - (utcTimestamp(nowUtc()) - $last);
        return [
            'ok' => false,
            'error' => 'Между импортами должно пройти не меньше ' . (string) SEO_SEARCH_IMPORT_INTERVAL
                . ' секунд (осталось ' . (string) $wait . ').',
            'rows' => 0,
            'stored' => 0,
        ];
    }

    $connection = seoSearchConnection();

    if ($connection === null) {
        return ['ok' => false, 'error' => 'Подключение к источнику не найдено.', 'rows' => 0, 'stored' => 0];
    }

    $fetched = seoSearchFetchRows($connection, utcDayKey($start), utcDayKey($end), $dimension);

    if (!$fetched['ok']) {
        return ['ok' => false, 'error' => $fetched['error'], 'rows' => 0, 'stored' => 0];
    }

    $rows = array_slice($fetched['rows'], 0, SEO_SEARCH_ROWS_MAX);
    $stored = seoSearchStoreRows($rows, (string) $connection['site_url'], (string) $connection['provider']);
    setOption(SEO_SEARCH_OPTION_LAST_IMPORT, (string) utcTimestamp(nowUtc()));

    return ['ok' => true, 'error' => '', 'rows' => count($rows), 'stored' => $stored];
}

/**
 * Запись строк. Повторный импорт того же дня и того же набора обновляет счётчики, а не
 * добавляет вторую стрку: иначе отчёт считал бы одно и то же дважды.
 *
 * @param list<array<string, mixed>> $rows
 */
function seoSearchStoreRows(array $rows, string $site, string $provider): int
{
    $stamp = utcStamp(nowUtc());
    $stored = 0;

    foreach ($rows as $row) {
        $day = (string) ($row['day'] ?? '');
        $query = mb_substr((string) ($row['query'] ?? ''), 0, SEO_SEARCH_QUERY_MAX);
        $page = mb_substr((string) ($row['page'] ?? ''), 0, SEO_SEARCH_PAGE_URL_MAX);
        $country = mb_substr((string) ($row['country'] ?? ''), 0, 8);
        $device = mb_substr((string) ($row['device'] ?? ''), 0, 16);
        $key = sha1(implode('|', [$provider, $site, $day, $query, $page, $country, $device]));

        run(
            'INSERT INTO ' . t('seo_search_row')
            . ' (id, row_key, provider, site_url, day, query, page, country, device, clicks, impressions, ctr, position, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions),'
            . ' ctr = VALUES(ctr), position = VALUES(position)',
            [
                newId(),
                $key,
                $provider,
                $site,
                $day,
                $query,
                $page,
                $country,
                $device,
                max(0, (int) ($row['clicks'] ?? 0)),
                max(0, (int) ($row['impressions'] ?? 0)),
                min(1.0, max(0.0, (float) ($row['ctr'] ?? 0))),
                max(0.0, (float) ($row['position'] ?? 0)),
                $stamp,
            ]
        );

        $stored++;
    }

    return $stored;
}

/**
 * Данные за период по одному измерению. CTR считается как «клики к показам», а не как среднее
 * по строкам: среднее по CTR-ам искажает картину, когда строки разной величины.
 *
 * Запрос на каждое измерение написан целиком: имя колонки в текст запроса не подставляется —
 * так строка не может превратиться в произвольный SQL.
 *
 * @return list<array<string, mixed>>
 */
function seoSearchSummary(string $from, string $to, string $dimension): array
{
    $settings = seoSearchSettings();

    if ($settings['provider'] === '' || $settings['site'] === '') {
        return [];
    }

    $sql = match ($dimension) {
        'page' => 'SELECT page AS name, SUM(clicks) AS clicks, SUM(impressions) AS impressions,'
            . ' CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,'
            . ' AVG(position) AS position FROM ' . t('seo_search_row')
            . ' WHERE provider = ? AND site_url = ? AND day >= ? AND day <= ? AND page <> \'\''
            . ' GROUP BY page ORDER BY clicks DESC, impressions DESC LIMIT 100',
        'country' => 'SELECT country AS name, SUM(clicks) AS clicks, SUM(impressions) AS impressions,'
            . ' CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,'
            . ' AVG(position) AS position FROM ' . t('seo_search_row')
            . ' WHERE provider = ? AND site_url = ? AND day >= ? AND day <= ? AND country <> \'\''
            . ' GROUP BY country ORDER BY clicks DESC, impressions DESC LIMIT 100',
        'device' => 'SELECT device AS name, SUM(clicks) AS clicks, SUM(impressions) AS impressions,'
            . ' CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,'
            . ' AVG(position) AS position FROM ' . t('seo_search_row')
            . ' WHERE provider = ? AND site_url = ? AND day >= ? AND day <= ? AND device <> \'\''
            . ' GROUP BY device ORDER BY clicks DESC, impressions DESC LIMIT 100',
        default => 'SELECT query AS name, SUM(clicks) AS clicks, SUM(impressions) AS impressions,'
            . ' CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,'
            . ' AVG(position) AS position FROM ' . t('seo_search_row')
            . ' WHERE provider = ? AND site_url = ? AND day >= ? AND day <= ? AND query <> \'\''
            . ' GROUP BY query ORDER BY clicks DESC, impressions DESC LIMIT 100',
    };

    return all($sql, [$settings['provider'], $settings['site'], $from, $to]);
}

/**
 * Сохранение настроек подключения: источник, адрес сайта в поисковике, идентификатор клиента и
 * адрес возврата. Секретов среди них нет.
 *
 * @param array<string, string> $values
 */
function seoSearchSaveSettings(array $values): void
{
    setOption(SEO_SEARCH_OPTION_PROVIDER, (string) $values['provider']);
    setOption(SEO_SEARCH_OPTION_SITE, (string) $values['site']);
    setOption(SEO_SEARCH_OPTION_CLIENT_ID, (string) $values['client_id']);
    setOption(SEO_SEARCH_OPTION_REDIRECT, (string) $values['redirect']);
}

/**
 * Проверка полей подключения. Сайтом в поисковике может быть либо адрес свойства
 * (https://example.com/), либо его запись вида sc-domain:example.com — другие формы поисковик
 * не принимает, а молча принять неверную значит потом удивляться пустому отчёту.
 *
 * @param array<string, string> $values
 * @return array{values: array<string, string>, errors: array<string, string>}
 */
function seoSearchValidate(array $values): array
{
    $provider = trim((string) ($values['provider'] ?? ''));
    $site = trim((string) ($values['site'] ?? ''));
    $clientId = trim((string) ($values['client_id'] ?? ''));
    $secret = trim((string) ($values['client_secret'] ?? ''));
    $errors = [];

    if (!array_key_exists($provider, seoSearchProviders())) {
        $errors['provider'] = 'Источник не выбран.';
    }

    if ($site === '') {
        $errors['site'] = 'Адрес сайта в поисковике не задан.';
    } elseif (preg_match('/^sc-domain:[a-z0-9]([a-z0-9.-]{0,180})$/i', $site) !== 1
        && preg_match('#^https://[a-z0-9]([a-z0-9.-]{0,180})(/.*)?$#i', $site) !== 1
    ) {
        $errors['site'] = 'Адрес сайта должен быть вида https://example.com/ или sc-domain:example.com.';
    }

    // У тестового источника своего клиента нет: он ничего не выдаёт и никуда не ходит.
    if ($provider !== SEO_SEARCH_PROVIDER_TEST && $clientId === '') {
        $errors['client_id'] = 'Идентификатор клиента не задан.';
    }

    if (mb_strlen($clientId) > 190) {
        $errors['client_id'] = 'Идентификатор клиента длиннее 190 символов.';
    }

    if (mb_strlen($secret) > 255) {
        $errors['client_secret'] = 'Клиентский секрет длиннее 255 символов.';
    }

    return [
        'values' => [
            'provider' => $provider,
            'site' => $site,
            'client_id' => $clientId,
            'client_secret' => $secret,
            'redirect' => trim((string) ($values['redirect'] ?? '')),
        ],
        'errors' => $errors,
    ];
}

/**
 * Адрес входа в источник. Пустая строка — входить некуда: источник не выбран, клиент не задан
 * или это тестовый источник, у которого входа нет вовсе.
 */
function seoSearchAuthorizeUrl(): string
{
    $settings = seoSearchSettings();

    if ($settings['provider'] !== SEO_SEARCH_PROVIDER_GOOGLE || $settings['clientId'] === '') {
        return '';
    }

    return SEO_SEARCH_AUTH_URL . '?' . http_build_query([
        'client_id' => $settings['clientId'],
        'redirect_uri' => $settings['redirectUri'],
        'response_type' => 'code',
        'scope' => SEO_SEARCH_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => seoSearchStateToken(),
    ]);
}
