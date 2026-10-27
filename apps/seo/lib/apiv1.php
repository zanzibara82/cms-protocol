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
// отказ 400, а не молчаливая подмена. Состав разрезов читается из реестра reports.php: «что
// предлагается машиной» и «что принимается файлом и экраном» не расходятся.
//
// Сессии и cookie здесь нет: адреса метки sessionless, ядро не открывает ни то, ни другое.
// Режим csrfCheck('public') в этих адресах не применяется: тот режим сверяет подпись раздела
// в теле POST-запроса (приёмник счётчика), а здесь запрос читающий, GET — CSRF у чтения нет,
// и его роль играет сам Bearer-ключ.

const SEO_API_PERIOD_DEFAULT = 7;
const SEO_API_PERIOD_MAX = 365;

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
 * Заголовок Authorization из доступных источников. nginx с PHP-FPM отдаёт его в
 * REDIRECT_HTTP_AUTHORIZATION, а CGI — иногда только через getallheaders(). Один общий
 * вход, чтобы API читал ключ на любом из этих хостингов, а не только там, где переменная
 * положилась под ожидаемым именем.
 */
function seoApiV1AuthHeader(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
        $value = $_SERVER[$name] ?? '';

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Ключ запроса: шапка Authorization с Bearer и обратная сверка по хешу. Отзываемый ключ не
 * отвечает и при известном секрете: запись уже помечена отозванной.
 *
 * @return array{id: string, label: string}|null
 */
function seoApiV1Auth(): ?array
{
    return seoApiKeyAuthenticate(seoApiV1AuthHeader());
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
 * Разрез с машинным адресом: имя из реестра отчётов, а не отдельный список. Экран, файл и
 * машинный ответ читают одно перечисление, поэтому «объявлено машине» и «собирается» не
 * расходятся.
 */
function seoApiV1Report(string $report): bool
{
    return isset(SEO_REPORTS[$report]) && SEO_REPORTS[$report]['api'];
}

/**
 * Страница API: один обработчик на все разрезы. Ошибки говорятся в JSON — машина сама
 * не поймёт перенаправление на экран входа.
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

    if (!seoApiV1Report($report)) {
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

    if ($source !== '' && !seoReportSupportsSource($report)) {
        seoApiV1Respond(400, ['error' => 'Этот отчёт не фильтруется по источнику']);
    }

    $range = seoStatsPeriod($days);
    $from = $range['from'];
    $to = $range['to'];

    $payload = match ($report) {
        'summary' => seoStatsSummary($from, $to, $source),
        'pages' => seoStatsPages($from, $to, $source),
        'sources' => seoStatsSources($from, $to, $source),
        // Ветки перечислены явно, а остаток падает: новый разрез в реестре без обработчика
        // обязан провалиться заметно (тест перебирает все api-разрезы), а не молча отдать цели.
        'goals' => array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'conversions' => (int) $row['conversions'],
            ],
            seoGoalConversions($from, $to)
        ),
        default => throw new LogicException('Нет обработчика машинного разреза: ' . $report),
    };

    seoApiV1Respond(200, [
        'report' => $report,
        'days' => $days,
        'source' => $source,
        'items' => $payload,
    ]);
}
