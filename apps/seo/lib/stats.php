<?php

declare(strict_types=1);

// Сбор статистики посещений (S2). Раздел считает собственные посещения: события приходят на
// адрес /seo/collect от нашего же скрипта, ложатся в сырьё (tbl_seo_visit) и в тот же момент
// пополняют агрегаты за день и за час. Панель читает агрегаты, а уникальных посетителей
// считает по сырью — методика подписана на экране и не выдаётся за точность.
//
// Правила приватности раздела: cookie не используется вовсе, адрес посетителя не хранится,
// содержимое форм не собирается, а строка запроса в адрес не попадает — только метки UTM.
// Идентификатор посетителя приходит из localStorage и хранится лишь в виде подписи;
// сессия — это окно бездействия (по умолчанию 30 минут), а не «визит».

// Настройки сбора живут в общей таблице настроек ядра под своими именами.
const SEO_STATS_OPTION_ENABLED = 'seo_stats_enabled';
const SEO_STATS_OPTION_TIMEOUT = 'seo_stats_timeout';
const SEO_STATS_OPTION_RETENTION = 'seo_stats_retention';
const SEO_STATS_OPTION_DNT = 'seo_stats_respect_dnt';
const SEO_STATS_OPTION_SECRET = 'seo_stats_secret';

const SEO_STATS_DEFAULT_TIMEOUT = 30;
const SEO_STATS_DEFAULT_RETENTION = 90;
const SEO_STATS_TIMEOUT_MIN = 5;
const SEO_STATS_TIMEOUT_MAX = 120;
const SEO_STATS_RETENTION_MIN = 7;
const SEO_STATS_RETENTION_MAX = 365;

// Пределы приёма: пачка событий в одном запросе и число событий от одного посетителя в минуту.
const SEO_STATS_MAX_EVENTS = 20;
const SEO_STATS_RATE_LIMIT = 120;

// Предел тела приёмника в байтах. Пачка событий от нашего скрипта занимает килобайты, а тело
// больше предела мы не разбираем вовсе. Причина не в аккуратности: разбор тела в несколько
// мегабайт способен исчерпать память процесса, а нехватка памяти в PHP ведёт к аварийному
// выходу движка — именно тот путь, который закрывает обновление безопасности 21 октября
// 2026 года (CVE-2026-109618, CVE-2026-109619). Подробности — в apps/seo/lib/collector.php.
const SEO_STATS_MAX_PAYLOAD = 65536;

const SEO_STATS_PATH_MAX = 255;
const SEO_STATS_TEXT_MAX = 190;

// Периоды фильтра панели: неделя, месяц, квартал. Число вне списка не «примерно понимается»,
// а заменяется запасным: молчаливая подмена периода хуже прямого отказа — человек увидел бы
// не тот счёт, о котором думал.
const SEO_STATS_PERIODS = [7, 30, 90];
const SEO_STATS_DEFAULT_PERIOD = 30;

// Источники трафика (спецификация §8). Свой источник не заводим: неизвестное значение читается
// как «другие кампании» или «не разобрано», а не выдумывает новую категорию.
const SEO_SOURCE_DIRECT = 'direct';
const SEO_SOURCE_ORGANIC = 'organic';
const SEO_SOURCE_REFERRAL = 'referral';
const SEO_SOURCE_SOCIAL = 'social';
const SEO_SOURCE_EMAIL = 'email';
const SEO_SOURCE_PAID_SEARCH = 'paid_search';
const SEO_SOURCE_PAID_SOCIAL = 'paid_social';
const SEO_SOURCE_DISPLAY = 'display';
const SEO_SOURCE_CAMPAIGN = 'campaign';
const SEO_SOURCE_UNKNOWN = 'unknown';

const SEO_SOURCES = [
    SEO_SOURCE_DIRECT => 'Прямой',
    SEO_SOURCE_ORGANIC => 'Органический поиск',
    SEO_SOURCE_REFERRAL => 'Ссылка',
    SEO_SOURCE_SOCIAL => 'Соцсети',
    SEO_SOURCE_EMAIL => 'Письма',
    SEO_SOURCE_PAID_SEARCH => 'Платный поиск',
    SEO_SOURCE_PAID_SOCIAL => 'Платные соцсети',
    SEO_SOURCE_DISPLAY => 'Баннеры',
    SEO_SOURCE_CAMPAIGN => 'Другие кампании',
    SEO_SOURCE_UNKNOWN => 'Не разобрано',
];

// Домены, по которым источник узнаётся без UTM. Список открытый: поисковик или соцсеть,
// которых здесь нет, попадёт в «ссылка» — это честнее, чем узнать его по подстроке.
const SEO_SEARCH_HOSTS = [
    'google.', 'bing.', 'yandex.', 'duckduckgo.', 'yahoo.', 'baidu.', 'ecosia.',
    'brave.', 'seznam.', 'naver.', 'rambler.',
];

const SEO_SOCIAL_HOSTS = [
    'facebook.', 'fb.com', 'instagram.', 'twitter.', 'x.com', 'vk.com', 'ok.ru',
    't.me', 'telegram.', 'youtube.', 'youtu.be', 'tiktok.', 'linkedin.', 'pinterest.',
    'threads.net', 'reddit.',
];

const SEO_MAIL_HOSTS = [
    'mail.google.com', 'outlook.', 'mail.yahoo.', 'mail.ru', 'fastmail.', 'yandex.ru',
];

// Устройства — грубо: точный список браузеров изучать нечем и незачем.
const SEO_DEVICES = [
    'desktop' => 'Компьютер',
    'mobile' => 'Телефон',
    'tablet' => 'Планшет',
    'unknown' => 'Не разобрано',
];

/**
 * Настройки сбора: значения из таблицы настроек с запасными числами. Промежуток сессии и срок
 * хранения сырья настраивает человек, а не код: и то и другое — обещание посетителю.
 *
 * @return array{enabled: bool, timeout: int, retention: int, respectDnt: bool}
 */
function seoStatsSettings(): array
{
    $timeout = (int) getOption(SEO_STATS_OPTION_TIMEOUT, (string) SEO_STATS_DEFAULT_TIMEOUT);
    $retention = (int) getOption(SEO_STATS_OPTION_RETENTION, (string) SEO_STATS_DEFAULT_RETENTION);

    return [
        'enabled' => getOption(SEO_STATS_OPTION_ENABLED, '1') !== '0',
        'timeout' => seoStatsClamp($timeout, SEO_STATS_TIMEOUT_MIN, SEO_STATS_TIMEOUT_MAX, SEO_STATS_DEFAULT_TIMEOUT),
        'retention' => seoStatsClamp($retention, SEO_STATS_RETENTION_MIN, SEO_STATS_RETENTION_MAX, SEO_STATS_DEFAULT_RETENTION),
        'respectDnt' => getOption(SEO_STATS_OPTION_DNT, '1') !== '0',
    ];
}

/**
 * Число в границах: значение из настроек может оказаться пустым или правленой строкой —
 * молчаливое «0 минут» превратило бы каждую сессию в одну секунду.
 */
function seoStatsClamp(int $value, int $min, int $max, int $fallback): int
{
    if ($value < $min || $value > $max) {
        return $fallback;
    }

    return $value;
}

/**
 * Сохранение настроек сбора. Время бездействия и срок хранения — числа в своих границах;
 * прочее — флаги.
 *
 * @param array<string, mixed> $post
 * @return array{ok: bool, error: string, notice: string}
 */
function seoStatsSaveSettings(array $post): array
{
    $enabled = ($post['enabled'] ?? '') === '1';
    $respectDnt = ($post['respect_dnt'] ?? '') === '1';
    $timeout = (int) ($post['timeout'] ?? 0);
    $retention = (int) ($post['retention'] ?? 0);

    if ($timeout < SEO_STATS_TIMEOUT_MIN || $timeout > SEO_STATS_TIMEOUT_MAX) {
        return [
            'ok' => false,
            'error' => 'Время бездействия — от ' . SEO_STATS_TIMEOUT_MIN . ' до ' . SEO_STATS_TIMEOUT_MAX . ' минут.',
            'notice' => '',
        ];
    }

    if ($retention < SEO_STATS_RETENTION_MIN || $retention > SEO_STATS_RETENTION_MAX) {
        return [
            'ok' => false,
            'error' => 'Срок хранения — от ' . SEO_STATS_RETENTION_MIN . ' до ' . SEO_STATS_RETENTION_MAX . ' дней.',
            'notice' => '',
        ];
    }

    setOption(SEO_STATS_OPTION_ENABLED, $enabled ? '1' : '0');
    setOption(SEO_STATS_OPTION_DNT, $respectDnt ? '1' : '0');
    setOption(SEO_STATS_OPTION_TIMEOUT, (string) $timeout);
    setOption(SEO_STATS_OPTION_RETENTION, (string) $retention);

    return ['ok' => true, 'error' => '', 'notice' => 'stats_saved'];
}

/**
 * Подпись раздела для машинных запросов. Страница кладёт её в разметку скрипта, приёмник
 * сверяет: подпись живёт сутки, вчерашняя тоже принимается — страница из кэша приходит позже.
 * Сама подпись ничего не говорит о посетителе: это метка «запрос с нашего сайта».
 */
function seoCollectToken(): string
{
    return seoCollectTokenFor(seoStatsDayKey(nowUtc()));
}

/**
 * Подпись за день: день считается по московскому календарю, как и агрегаты.
 */
function seoCollectTokenFor(string $day): string
{
    return hash_hmac('sha256', 'seo-collect|' . $day, seoCollectSecret());
}

/**
 * Проверка подписи машинного запроса. Сравнение постоянного времени: подпись короткая, и
 * угадывание по времени ответа не должно быть возможным.
 */
function seoCollectorTokenValid(string $token): bool
{
    $given = trim($token);

    if ($given === '') {
        return false;
    }

    $today = seoCollectTokenFor(seoStatsDayKey(nowUtc()));
    $yesterday = seoCollectTokenFor(shiftMoscowDay(seoStatsDayKey(nowUtc()), -1));

    return hash_equals($today, $given) || hash_equals($yesterday, $given);
}

/**
 * Секрет подписи: он живёт в настройках и появляется при первом обращении. Секрет не в
 * манифесте и не в коде: подпись должна быть неподдельной, а не «примерно такой».
 */
function seoCollectSecret(): string
{
    $secret = trim(getOption(SEO_STATS_OPTION_SECRET, ''));

    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        setOption(SEO_STATS_OPTION_SECRET, $secret);
    }

    return $secret;
}

/**
 * Идентификатор посетителя в виде подписи: случайный номер из localStorage, подписанный
 * секретом раздела. Сам номер не хранится, и по подписи его нельзя обратить вспять.
 */
function seoStatsVisitorHash(string $visitorId): string
{
    $clean = trim($visitorId);

    if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clean) !== 1) {
        return '';
    }

    return substr(hash_hmac('sha256', $clean, seoCollectSecret()), 0, 32);
}

/**
 * Классификация источника трафика (спецификация §8).
 *
 * Порядок: сначала метки UTM — они говорят прямо; потом реферер; отсутствие и того и другого —
 * прямой заход. Тип из UTM не выдумывается: «неизвестно» честнее рекламного источника.
 */
function seoSourceClassify(string $utmSource, string $utmMedium, string $utmCampaign, string $referrerHost): string
{
    $medium = strtolower(trim($utmMedium));

    if ($medium !== '') {
        return match ($medium) {
            'cpc', 'ppc', 'paid_search', 'paidsearch' => SEO_SOURCE_PAID_SEARCH,
            'paid_social', 'paidsocial', 'cpc_social' => SEO_SOURCE_PAID_SOCIAL,
            'social', 'social_network' => SEO_SOURCE_SOCIAL,
            'display', 'banner', 'banner_network' => SEO_SOURCE_DISPLAY,
            'email', 'newsletter', 'e-mail' => SEO_SOURCE_EMAIL,
            'organic' => SEO_SOURCE_ORGANIC,
            'affiliate', 'referral' => SEO_SOURCE_REFERRAL,
            default => SEO_SOURCE_CAMPAIGN,
        };
    }

    if (trim($utmSource) !== '' || trim($utmCampaign) !== '') {
        // Метки кампании без указания типа — это «другие кампании»: рекламный источник
        // без данных о типе выдумывать нельзя.
        return SEO_SOURCE_CAMPAIGN;
    }

    $host = strtolower(trim($referrerHost));

    if ($host === '') {
        return SEO_SOURCE_DIRECT;
    }

    if (seoStatsHostMatches($host, SEO_SEARCH_HOSTS)) {
        return SEO_SOURCE_ORGANIC;
    }

    if (seoStatsHostMatches($host, SEO_SOCIAL_HOSTS)) {
        return SEO_SOURCE_SOCIAL;
    }

    if (seoStatsHostMatches($host, SEO_MAIL_HOSTS)) {
        return SEO_SOURCE_EMAIL;
    }

    return SEO_SOURCE_REFERRAL;
}

/**
 * Домен в списке источников: начало с точки («google.») или сам домен и его поддомены.
 *
 * @param list<string> $needles
 */
function seoStatsHostMatches(string $host, array $needles): bool
{
    foreach ($needles as $needle) {
        if (str_ends_with($needle, '.')) {
            if (str_starts_with($host, $needle)) {
                return true;
            }

            continue;
        }

        if ($host === $needle || str_ends_with($host, '.' . $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Домен из адреса-реферера: без пути, без строки запроса и без «www». Реферер — единственное,
 * что приходит снаружи, и хранить его целиком незачем.
 */
function seoStatsReferrerHost(string $url): string
{
    $clean = trim($url);

    if ($clean === '') {
        return '';
    }

    $host = parse_url($clean, PHP_URL_HOST);

    if (!is_string($host) || $host === '') {
        // Клиент присылает только хост: полный адрес страницы-источника нам не нужен, и
        // отправлять его по сети незачем. Голый хост узнаём по виду — без двоеточия и слэшей.
        $host = preg_match('/^[a-z0-9.\-]+$/i', $clean) === 1 ? $clean : '';
    }

    if ($host === '') {
        return '';
    }

    $host = strtolower($host);

    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    return mb_substr($host, 0, SEO_STATS_TEXT_MAX);
}

/**
 * Адрес страницы без параметров и якоря: строка запроса может нести личные данные, а
 * UTM разбираются отдельно и в адрес не возвращаются.
 */
function seoStatsNormalizePath(string $path): string
{
    $clean = trim($path);

    if ($clean === '') {
        return '';
    }

    $clean = substr($clean, 0, (int) strcspn($clean, '?#'));

    if (!str_starts_with($clean, '/')) {
        return '';
    }

    if (preg_match('/[\x00-\x1F\x7F]/u', $clean) === 1) {
        return '';
    }

    $clean = mb_substr($clean, 0, SEO_STATS_PATH_MAX);

    // Хвостовой слэш — тот же адрес: без этого «/news/» и «/news» считались бы разными
    // страницами, и счётчики разъезжались бы на пустых различиях.
    if (strlen($clean) > 1) {
        $clean = rtrim($clean, '/');
    }

    return $clean === '' ? '/' : $clean;
}

/**
 * Текст события: без управляющих символов и в пределах колонки.
 */
function seoStatsCleanText(string $value): string
{
    $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value));

    return mb_substr($clean, 0, SEO_STATS_TEXT_MAX);
}

/**
 * Устройство по метке клиента: грубо, но честно — точный список браузеров изучать нечем.
 */
function seoStatsDevice(string $raw): string
{
    $clean = strtolower(trim($raw));

    if (isset(SEO_DEVICES[$clean])) {
        return $clean;
    }

    return 'unknown';
}

/**
 * Тело запроса приёмника: JSON с пачкой событий и номером посетителя. Неразобранное тело —
 * отказ, а не пустая пачка: молча проглоченное тело выглядело бы как «посетитель молчал».
 *
 * @return array{visitorId: string, events: list<array<string, mixed>>}|null
 */
function seoStatsParsePayload(string $raw): ?array
{
    $clean = trim($raw);

    if ($clean === '') {
        return null;
    }

    $data = json_decode($clean, true);

    if (!is_array($data)) {
        return null;
    }

    $events = $data['events'] ?? null;

    if (!is_array($events)) {
        return null;
    }

    $visitorId = $data['vid'] ?? '';
    $list = [];

    foreach ($events as $event) {
        if (is_array($event)) {
            $list[] = $event;
        }

        if (count($list) >= SEO_STATS_MAX_EVENTS) {
            break;
        }
    }

    return [
        'visitorId' => is_string($visitorId) ? $visitorId : '',
        'events' => $list,
    ];
}

/**
 * Событие из тела запроса, приведённое к виду строки сырья. Лишнее и негодное отбрасывается:
 * путь без ведущего слэша или с управляющими символами — не страница.
 *
 * @param array<string, mixed> $event
 * @return array{path: string, referrer: string, utmSource: string, utmMedium: string, utmCampaign: string, device: string}|null
 */
function seoStatsEvent(array $event): ?array
{
    $path = seoStatsNormalizePath((string) ($event['p'] ?? ''));

    if ($path === '') {
        return null;
    }

    $utm = is_array($event['u'] ?? null) ? $event['u'] : [];

    return [
        'path' => $path,
        'referrer' => seoStatsReferrerHost((string) ($event['r'] ?? '')),
        'utmSource' => seoStatsCleanText((string) ($utm['s'] ?? '')),
        'utmMedium' => seoStatsCleanText((string) ($utm['m'] ?? '')),
        'utmCampaign' => seoStatsCleanText((string) ($utm['c'] ?? '')),
        'device' => seoStatsDevice((string) ($event['d'] ?? '')),
    ];
}

/**
 * Приём пачки событий: каждое событие ложится в сырьё и пополняет агрегаты. Ответ — числа
 * принятого и отброшенного: они же показываются в журнале, когда счётчик вдруг пустеет.
 *
 * Производительность (S6, спецификация §16): обращения к базе идут пакетами, а не по событию.
 * Сессия визитора читается один раз на пачку — она заведомо одна (события приходят от одного
 * посетителя, а сессия не меняется на середине), визиты вставляются одним многострочным
 * INSERT, агрегаты дня и часа пополняются одним запросом с GROUP-парами разреза, расширения
 * сессии вырождаются в один UPDATE, а список целей конверсий читается один раз на пачку.
 *
 * @param list<array<string, mixed>> $events
 * @return array{accepted: int, rejected: int}
 */
function seoStatsStoreEvents(array $events, string $visitorId): array
{
    $visitor = seoStatsVisitorHash($visitorId);

    if ($visitor === '') {
        return ['accepted' => 0, 'rejected' => count($events)];
    }

    if (!seoStatsWithinRate($visitor)) {
        return ['accepted' => 0, 'rejected' => count($events)];
    }

    $settings = seoStatsSettings();
    $moment = nowUtc();
    $stamp = utcStamp($moment);
    $local = moscowIso($moment);
    $day = substr($local, 0, 10);
    $hour = (int) substr($local, 11, 2);

    $prepared = [];
    $rejected = 0;

    foreach ($events as $event) {
        $eventRow = seoStatsEvent(is_array($event) ? $event : []);

        if ($eventRow === null) {
            $rejected++;
        } else {
            $prepared[] = $eventRow;
        }
    }

    if ($prepared === []) {
        return ['accepted' => 0, 'rejected' => $rejected];
    }

    $session = seoStatsSessionForBatch($visitor, $prepared[0], $stamp, $day, $settings['timeout']);

    $visits = [];
    $dayRows = [];
    $hourRows = [];
    $opensSession = $session['isNew'];

    foreach ($prepared as $preparedEvent) {
        $path = $preparedEvent['path'];
        $source = $session['source'];
        // Сессия в пачке одна, поэтому «новая сессия» засчитывается ровно одному просмотру —
        // первому. Плюс под каждым событием удвоил бы счётчик сессий на пачке из трёх страниц.
        $sessions = $opensSession ? 1 : 0;
        $opensSession = false;

        $visits[] = [
            $session['id'],
            $visitor,
            $path,
            $source,
            $preparedEvent['referrer'] !== '' ? $preparedEvent['referrer'] : null,
            $preparedEvent['utmSource'] !== '' ? $preparedEvent['utmSource'] : null,
            $preparedEvent['utmMedium'] !== '' ? $preparedEvent['utmMedium'] : null,
            $preparedEvent['utmCampaign'] !== '' ? $preparedEvent['utmCampaign'] : null,
            $preparedEvent['device'],
            $day,
            $hour,
            $stamp,
        ];

        // Разрез копится счётчиком: два просмотра одного адреса в одной пачке дают две строки
        // в сырье и два просмотра в агрегате, а не одну перезаписанную.
        $dayKey = $path . '|' . $source;

        if (!isset($dayRows[$dayKey])) {
            $dayRows[$dayKey] = ['day' => $day, 'path' => $path, 'source' => $source, 'views' => 0, 'sessions' => 0];
        }

        $dayRows[$dayKey]['views']++;
        $dayRows[$dayKey]['sessions'] += $sessions;

        if (!isset($hourRows[$source])) {
            $hourRows[$source] = ['day' => $day, 'hour' => $hour, 'source' => $source, 'views' => 0, 'sessions' => 0];
        }

        $hourRows[$source]['views']++;
        $hourRows[$source]['sessions'] += $sessions;
    }

    seoStatsInsertVisits(array_map(
        static fn (array $visit): array => [newId(), ...$visit],
        $visits
    ));
    seoStatsExtendSession($session['id'], $stamp, (string) $prepared[count($prepared) - 1]['path'], count($prepared));
    seoStatsAggregateUpsert('seo_stat_day', 'day, path, source', 'views, sessions', $dayRows);
    seoStatsAggregateUpsert('seo_stat_hour', 'day, hour, source', 'views, sessions', $hourRows);

    $goals = seoStatsEnabledGoals();

    foreach ($prepared as $preparedEvent) {
        seoStatsRecordConversion(
            $session['id'],
            $visitor,
            $preparedEvent['path'],
            $session['source'],
            $preparedEvent['device'],
            $stamp,
            $day,
            $goals
        );
    }

    return ['accepted' => count($prepared), 'rejected' => $rejected];
}

/**
 * Сессия для пачки событий: окно бездействия или новая сессия. Сессия в пачке заведомо одна:
 * события приходят от одного посетителя одним штампом, и внутренний переход по своему сайту
 * не делает посетителя «прямым».
 *
 * @param array{path: string, referrer: string, utmSource: string, utmMedium: string, utmCampaign: string, device: string} $first первое событие пачки
 * @return array{id: string, source: string, isNew: bool}
 */
function seoStatsSessionForBatch(string $visitor, array $first, string $stamp, string $day, int $timeoutMinutes): array
{
    $row = one(
        'SELECT id, source, last_seen_at FROM ' . t('seo_session') . ' WHERE visitor_id = ? ORDER BY last_seen_at DESC LIMIT 1',
        [$visitor]
    );

    if ($row !== null) {
        $last = fromDb(is_string($row['last_seen_at'] ?? null) ? (string) $row['last_seen_at'] : null);

        if ($last !== null && $last->getTimestamp() + $timeoutMinutes * 60 >= nowUtc()->getTimestamp()) {
            return [
                'id' => (string) $row['id'],
                'source' => (string) $row['source'],
                'isNew' => false,
            ];
        }
    }

    $source = seoSourceClassify($first['utmSource'], $first['utmMedium'], $first['utmCampaign'], $first['referrer']);
    $id = newId();

    insert('seo_session', [
        'id' => $id,
        'visitor_id' => $visitor,
        'source' => $source,
        'landing_path' => $first['path'],
        'last_path' => $first['path'],
        // Счётчик просмотров сессии пополняет приём события: сама сессия просмотра не даёт,
        // иначе первая страница считалась бы дважды.
        'views' => 0,
        'started_at' => $stamp,
        'last_seen_at' => $stamp,
        'day' => $day,
    ]);

    return ['id' => $id, 'source' => $source, 'isNew' => true];
}

/**
 * Многострочная вставка визитов: одна строка VALUES на событие. Пачка ограничена пределом
 * тела, поэтому число заполнителей в самом длинном составном запросе заведомо двузначное.
 *
 * @param list<list<string|int|null>> $visits колонки в порядке запроса
 */
function seoStatsInsertVisits(array $visits): void
{
    if ($visits === []) {
        return;
    }

    $values = [];
    $args = [];

    foreach ($visits as $visit) {
        $values[] = '(' . implode(', ', array_fill(0, count($visit), '?')) . ')';
        $args = [...$args, ...$visit];
    }

    run(
        'INSERT INTO ' . t('seo_visit')
        . ' (id, session_id, visitor_id, path, source, referrer_host, utm_source, utm_medium, utm_campaign, device, day, hour, created_at)'
        . ' VALUES ' . implode(', ', $values),
        $args
    );
}

/**
 * Расширение сессии одним UPDATE на пачку: просмотров столько, сколько событий, а «выходом»
 * становится адрес последнего события пачки — ровно то, что давал почешучный UPDATE.
 */
function seoStatsExtendSession(string $sessionId, string $stamp, string $lastPath, int $views): void
{
    run(
        'UPDATE ' . t('seo_session') . ' SET views = views + ?, last_seen_at = ?, last_path = ? WHERE id = ?',
        [$views, $stamp, $lastPath, $sessionId]
    );
}

/**
 * Пополнение агрегатов одним многострочным INSERT … ON DUPLICATE KEY UPDATE (S6). Разрезы
 * дня и часа различаются колонками, а сам запрос читает их из аргументов; число уникальных
 * пар разреза не больше числа событий пачки, поэтому составной список остаётся коротким.
 *
 * @param string $table имя без префикса (seo_stat_day, seo_stat_hour)
 * @param string $columns перечень ключевых колонок
 * @param string $countColumns перечень счётчиков
 * @param array<string, array<string, string|int>> $rows ключ разреза => строка агрегата
 */
function seoStatsAggregateUpsert(string $table, string $columns, string $countColumns, array $rows): void
{
    if ($rows === []) {
        return;
    }

    $first = (array) reset($rows);
    $keys = array_keys($first);
    $columnList = $columns . ', ' . $countColumns;

    $values = [];
    $args = [];

    foreach ($rows as $row) {
        $values[] = '(' . implode(', ', array_fill(0, count($keys), '?')) . ')';
        foreach ($keys as $key) {
            $args[] = $row[$key];
        }
    }

    $updates = 'views = views + VALUES(views), sessions = sessions + VALUES(sessions)';

    run(
        'INSERT INTO ' . t($table) . ' (' . $columnList . ') VALUES ' . implode(', ', $values)
        . ' ON DUPLICATE KEY UPDATE ' . $updates,
        $args
    );
}

/**
 * Цели, задействованные конверсиями: список читается один раз на пачку, а не под каждым
 * событием. Поведение сверки неожиданным не соделается: та же проверка цели, что и раньше.
 *
 * @return list<array<string, mixed>>
 */
function seoStatsEnabledGoals(): array
{
    /** @var list<array<string, mixed>> */
    return all('SELECT id, name, type, pattern, match_type, source, device, enabled, created_at, updated_at FROM ' . t('seo_goal') . ' WHERE enabled = 1 LIMIT 1000');
}

/**
 * Предел приёма: от одного посетителя в минуту не больше положенного числа событий. Метка
 * подписи ограничивает и без этого, но предел нужен и на случай утёкшей подписи.
 */
function seoStatsWithinRate(string $visitor): bool
{
    $minuteAgo = utcStamp(shiftSeconds(nowUtc(), -60));
    $count = (int) value(
        'SELECT COUNT(*) FROM ' . t('seo_visit') . ' WHERE visitor_id = ? AND created_at >= ?',
        [$visitor, $minuteAgo]
    );

    return $count < SEO_STATS_RATE_LIMIT;
}

/**
 * День по московскому календарю: агрегаты и «сегодня» считаются по одному ключу, иначе
 * ночные события попадали бы в соседние дни.
 */
function seoStatsDayKey(\DateTimeImmutable $moment): string
{
    return moscowDayKey($moment);
}

/**
 * Период и предыдущий период такой же длины: «сейчас против тогда» — единственное сравнение,
 * которое что-то значит. Оба окна смотрят назад: «30 дней» заканчивается сегодня и начинается
 * 29 дней назад, а прошлый период — следующий отрезок той же длины до него. Сдвиг вперёд дал бы
 * окно, у которого начало позже конца: такой запрос не находит ничего, и панель молча
 * показывала бы нули на живом счётчике.
 *
 * @return array{from: string, to: string, previousFrom: string, previousTo: string, days: int}
 */
function seoStatsPeriod(int $days): array
{
    $to = moscowDayKey(nowUtc());
    $from = moscowDayKey(shiftDays(nowUtc(), -($days - 1)));
    $previousTo = moscowDayKey(shiftDays(nowUtc(), -$days));
    $previousFrom = moscowDayKey(shiftDays(nowUtc(), -(2 * $days - 1)));

    return [
        'from' => $from,
        'to' => $to,
        'previousFrom' => $previousFrom,
        'previousTo' => $previousTo,
        'days' => $days,
    ];
}

/**
 * Итоги периода: просмотры и сессии из агрегатов, уникальные посетители — из сырья.
 * Методика подписана на экране: «посетитель» здесь — подпись номера из localStorage.
 *
 * @return array{views: int, sessions: int, visitors: int}
 */
function seoStatsSummary(string $from, string $to, string $source = ''): array
{
    $args = [$from, $to, $source, $source];

    $views = (int) value(
        'SELECT COALESCE(SUM(views), 0) FROM ' . t('seo_stat_day')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?)',
        $args
    );
    $sessions = (int) value(
        'SELECT COALESCE(SUM(sessions), 0) FROM ' . t('seo_stat_day')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?)',
        $args
    );
    $visitors = (int) value(
        'SELECT COUNT(DISTINCT visitor_id) FROM ' . t('seo_visit')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?)',
        $args
    );

    return ['views' => $views, 'sessions' => $sessions, 'visitors' => $visitors];
}

/**
 * Просмотры по дням: пропущенные дни достраиваются нулём, иначе график врёт о «провале».
 *
 * @return list<array{day: string, views: int, sessions: int}>
 */
function seoStatsDaily(string $from, string $to, string $source = ''): array
{
    $rows = all(
        'SELECT day, SUM(views) AS views, SUM(sessions) AS sessions FROM ' . t('seo_stat_day')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?) GROUP BY day ORDER BY day',
        [$from, $to, $source, $source]
    );

    $byDay = [];
    foreach ($rows as $row) {
        $byDay[(string) $row['day']] = [
            'views' => (int) $row['views'],
            'sessions' => (int) $row['sessions'],
        ];
    }

    $out = [];
    $day = $from;

    while ($day <= $to) {
        $out[] = [
            'day' => $day,
            'views' => (int) ($byDay[$day]['views'] ?? 0),
            'sessions' => (int) ($byDay[$day]['sessions'] ?? 0),
        ];

        $next = shiftMoscowDay($day, 1);

        if ($next === '') {
            break;
        }

        $day = $next;
    }

    return $out;
}

/**
 * Источники за период: просмотры и сессии из агрегатов, посетители — из сырья. Фильтр по
 * источнику оставляет одну строку разреза — так экран показывает выбранный канал, а не весь
 * список с подписью «выбран».
 *
 * @return list<array{source: string, label: string, views: int, sessions: int, visitors: int}>
 */
function seoStatsSources(string $from, string $to, string $source = ''): array
{
    $args = [$from, $to, $source, $source];

    $rows = all(
        'SELECT source, SUM(views) AS views, SUM(sessions) AS sessions FROM ' . t('seo_stat_day')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?) GROUP BY source ORDER BY views DESC',
        $args
    );

    $visitors = [];
    foreach (all(
        'SELECT source, COUNT(DISTINCT visitor_id) AS visitors FROM ' . t('seo_visit')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?) GROUP BY source',
        $args
    ) as $row) {
        $visitors[(string) $row['source']] = (int) $row['visitors'];
    }

    $out = [];
    foreach ($rows as $row) {
        $source = (string) $row['source'];
        $out[] = [
            'source' => $source,
            'label' => seoStatsSourceLabel($source),
            'views' => (int) $row['views'],
            'sessions' => (int) $row['sessions'],
            'visitors' => (int) ($visitors[$source] ?? 0),
        ];
    }

    return $out;
}

/**
 * Популярные страницы: просмотры из агрегатов, входы и выходы — из сессий (первый и
 * последний адрес сессии).
 *
 * @return list<array{path: string, views: int, entrances: int, exits: int}>
 */
function seoStatsPages(string $from, string $to, string $source = ''): array
{
    $args = [$from, $to, $source, $source];

    $rows = all(
        'SELECT path, SUM(views) AS views FROM ' . t('seo_stat_day')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?) GROUP BY path ORDER BY views DESC LIMIT 100',
        $args
    );

    $entrances = [];
    foreach (all(
        'SELECT landing_path AS path, COUNT(*) AS n FROM ' . t('seo_session')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?) GROUP BY landing_path',
        $args
    ) as $row) {
        $entrances[(string) $row['path']] = (int) $row['n'];
    }

    $exits = [];
    foreach (all(
        'SELECT last_path AS path, COUNT(*) AS n FROM ' . t('seo_session')
        . ' WHERE day >= ? AND day <= ? AND (? = \'\' OR source = ?) GROUP BY last_path',
        $args
    ) as $row) {
        $exits[(string) $row['path']] = (int) $row['n'];
    }

    $out = [];
    foreach ($rows as $row) {
        $path = (string) $row['path'];
        $out[] = [
            'path' => $path,
            'views' => (int) $row['views'],
            'entrances' => (int) ($entrances[$path] ?? 0),
            'exits' => (int) ($exits[$path] ?? 0),
        ];
    }

    return $out;
}

/**
 * Кампании по меткам UTM: то, что разметил рекламный кабинет, а не то, что мы угадали.
 * Фильтр по источнику — по классифицированному каналу визита, а не по метке `utm_source`:
 * «выбранный источник» и «метка в адресе» — разные разрезы, и смешивать их нельзя.
 *
 * @return list<array{source: string, medium: string, campaign: string, views: int}>
 */
function seoStatsCampaigns(string $from, string $to, string $source = ''): array
{
    $rows = all(
        'SELECT utm_source, utm_medium, utm_campaign, COUNT(*) AS views FROM ' . t('seo_visit')
        . ' WHERE day >= ? AND day <= ? AND utm_campaign IS NOT NULL AND utm_campaign <> \'\''
        . ' AND (? = \'\' OR source = ?)'
        . ' GROUP BY utm_source, utm_medium, utm_campaign ORDER BY views DESC LIMIT 50',
        [$from, $to, $source, $source]
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'source' => (string) ($row['utm_source'] ?? ''),
            'medium' => (string) ($row['utm_medium'] ?? ''),
            'campaign' => (string) ($row['utm_campaign'] ?? ''),
            'views' => (int) $row['views'],
        ];
    }

    return $out;
}

/**
 * Просмотры по часам: когда трафик приходит. Часы без событий не пропускаются — иначе
 * график часов рисовал бы провалы там, где трафика просто не было.
 *
 * @return list<array{hour: int, views: int, sessions: int}>
 */
function seoStatsHourly(string $from, string $to): array
{
    $rows = all(
        'SELECT hour, SUM(views) AS views, SUM(sessions) AS sessions FROM ' . t('seo_stat_hour')
        . ' WHERE day >= ? AND day <= ? GROUP BY hour ORDER BY hour',
        [$from, $to]
    );

    $byHour = [];
    foreach ($rows as $row) {
        $byHour[(int) $row['hour']] = [
            'views' => (int) $row['views'],
            'sessions' => (int) $row['sessions'],
        ];
    }

    $out = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $out[] = [
            'hour' => $hour,
            'views' => (int) ($byHour[$hour]['views'] ?? 0),
            'sessions' => (int) ($byHour[$hour]['sessions'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Что именно удалит очистка: столько событий и столько сессий старше срока хранения. Человек
 * должен видеть объём до нажатия, а не после. Граница смотрит назад: «срок 90 дней» оставляет
 * всё, что случилось позже 90 дней назад, и только старое удаляет.
 *
 * @return array{visits: int, sessions: int}
 */
function seoStatsPurgePreview(int $retentionDays): array
{
    $limit = moscowDayKey(shiftDays(nowUtc(), -$retentionDays));

    return [
        'visits' => (int) value('SELECT COUNT(*) FROM ' . t('seo_visit') . ' WHERE day < ?', [$limit]),
        'sessions' => (int) value('SELECT COUNT(*) FROM ' . t('seo_session') . ' WHERE day < ?', [$limit]),
    ];
}

/**
 * Подпись источника: известный источник — его название, неизвестный — «не разобрано».
 */
function seoStatsSourceLabel(string $source): string
{
    return SEO_SOURCES[$source] ?? SEO_SOURCES[SEO_SOURCE_UNKNOWN];
}

/**
 * Очистка сырья: события и сессии старше срока хранения удаляются, агрегаты остаются —
 * счётчики за прошлые дни не должны исчезать вместе с сырьём.
 */
function seoStatsPurge(int $days): int
{
    $limit = moscowDayKey(shiftDays(nowUtc(), -$days));
    $visits = run('DELETE FROM ' . t('seo_visit') . ' WHERE day < ?', [$limit]);
    $sessions = run('DELETE FROM ' . t('seo_session') . ' WHERE day < ?', [$limit]);

    return $visits + $sessions;
}
