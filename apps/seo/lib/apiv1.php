<?php

declare(strict_types=1);

// Машинное API статистики (S6, спецификация §8 «Машинный API»): чтение тех же агрегатов,
// что и у панели, но по ключу, а не по сессии владельца. Панель — для человека, API — для
// внешнего наблюдателя (дашборда, локального бота): ключ отзывается в панели «API-ключи»,
// поэтому доступ уходит, а не остаётся жить в старой вкладке.
//
// Разрезы те же, что и на экранах, персонального нет и в базе: адрес посетителя не хранится,
// подпись посетителя — не человек, поэтому ключ не открывает данных больше, чем показывает
// панель. Ответы — JSON с запретом индексирования и кэша.
//
// Период и источник проверяются тем же правилом, что фильтры панели: значение вне границ —
// отказ 400, а не молчаливая подмена.

const SEO_API_PERIOD_DEFAULT = 7;
const SEO_API_PERIOD_MAX = 365;

/** Разрезы с точными ключами: ключи маршрута не меняются без оговорки в чек-листе. */
const SEO_API_V1_REPORTS = [
    'summary' => 'Итоги за период',
    'pages' => 'Популярные страницы',
    'sources' => 'Источники',
    'goals' => 'Цели и конверсии',
];

// Разрезы, принимающие фильтр по источнику. У конверсии источника нет: подпись «фильтр
// применён» на общем списке целей была бы ложью, поэтому такой запрос отвергается.
const SEO_API_V1_SOURCE_REPORTS = ['summary', 'pages', 'sources'];

/**
 * Ответ машины в JSON: один набор заголовков на все коды — запрет индексирования и кэша
 * держится и на ошибке, иначе 401 бы кэшировался и вытеснял ответ с кодом.
 *
 * @param array<string, mixed> $payload
 * @return never
 */
function seoApiV1Respond(int $status, array $payload): never
{
    respond($status, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SEO_API_HEADERS);
}

/**
 * Ключ запроса: шапка Authorization с Bearer и обратная сверка по хешу. Отзываемый ключ не
 * отвечает и при известном секрете: запись уже помечена отозванной.
 *
 * @return array{id: string, label: string}|null
 */
function seoApiV1Auth(): ?array
{
    return seoApiKeyAuthenticate($_SERVER['HTTP_AUTHORIZATION'] ?? '');
}

/**
 * Разбор периода запроса: пустое значение — период по умолчанию, вне границ — отказ.
 *
 * @return array{days: int|null}
 */
function seoApiV1Period(): array
{
    $raw = trim((string) queryString('days', ''));

    if ($raw === '') {
        return ['days' => SEO_API_PERIOD_DEFAULT];
    }

    if (preg_match('/^\d+$/', $raw) !== 1) {
        return ['days' => null];
    }

    $days = (int) $raw;

    if ($days < 1 || $days > SEO_API_PERIOD_MAX) {
        return ['days' => null];
    }

    return ['days' => $days];
}

/**
 * Страница API: один обработчик на четыре разреза. Ошибки говорятся в JSON — машина
 * сама не поймёт перенаправление на экран входа.
 *
 * @param array<string, string> $params
 * @return never
 */
function seoApiV1Page(array $params = []): never
{
    $report = (string) ($params['report'] ?? '');

    // Ключ проверяется раньше белого списка: без права ответ одинаков — 401 на любое имя,
    // и посторонний не узнаёт по коду, какие разрезы существуют.
    $key = seoApiV1Auth();

    if ($key === null) {
        logInfo('seo.api_unauthorized', ['report' => $report]);
        seoApiV1Respond(401, ['error' => 'Требуется действующий ключ API']);
    }

    if (!isset(SEO_API_V1_REPORTS[$report])) {
        seoApiV1Respond(404, ['error' => 'Неизвестный отчёт']);
    }

    $period = seoApiV1Period();

    if ($period['days'] === null) {
        seoApiV1Respond(400, ['error' => 'Период задаётся числом дней от 1 до ' . (string) SEO_API_PERIOD_MAX]);
    }

    $days = (int) $period['days'];
    $source = trim((string) queryString('source', ''));

    if ($source !== '' && !isset(SEO_SOURCES[$source])) {
        seoApiV1Respond(400, ['error' => 'Неизвестный источник']);
    }

    if ($source !== '' && !in_array($report, SEO_API_V1_SOURCE_REPORTS, true)) {
        seoApiV1Respond(400, ['error' => 'Этот отчёт не фильтруется по источнику']);
    }

    $range = seoStatsPeriod($days);
    $from = $range['from'];
    $to = $range['to'];

    $payload = match ($report) {
        'summary' => seoStatsSummary($from, $to, $source),
        'pages' => seoStatsPages($from, $to, $source),
        'sources' => seoStatsSources($from, $to, $source),
        'goals' => array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'conversions' => (int) $row['conversions'],
            ],
            seoGoalConversions($from, $to)
        ),
    };

    seoApiV1Respond(200, [
        'report' => $report,
        'days' => $days,
        'source' => $source,
        'items' => $payload,
    ]);
}
