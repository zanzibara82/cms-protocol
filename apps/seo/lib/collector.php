<?php

declare(strict_types=1);

// Приёмник событий счётчика и его подпись (S2). Приёмник открыт мимо входа: к нему обращается
// наш собственный скрипт со страницы посетителя, у которого нет ни входа, ни сессии. Поэтому
// сверка здесь — подпись раздела, а не сессионная метка: страница кладёт подпись в разметку
// скрипта, приёмник сверяет её через фильтр 'csrf_public_token'. Сессия при этом не открывается
// вовсе: cookie ради счётчика означала бы, что режим «без cookies» недостижим.

const SEO_COLLECT_PATH = '/seo/collect';
const SEO_COLLECT_SCRIPT = '/assets/js/seo-analytics.js';

/**
 * Сверка подписи машинного запроса: значение фильтра «true» означает «подпись верна».
 * Подпись живёт сутки, поэтому страница, пришедшая из кэша вчерашней сборки, работает.
 *
 * @param mixed $verified значение фильтра до подписчика
 * @param mixed $token токен из тела запроса
 */
function seoCollectorTokenFilter(mixed $verified, mixed $token): bool
{
    return is_string($token) && seoCollectorTokenValid($token);
}

/**
 * Приёмник событий: пачка событий от нашего скрипта. Ответ — 204 без тела: клиенту нечего
 * читать, а пустой ответ не должен храниться.
 *
 * @param array<string, string> $params
 */
function seoCollectorAccept(array $params = []): void
{
    // Сверка подписи идёт первой — до базы и до счётчиков. Сессия здесь не открывается: приёмник
    // работает без cookie, иначе счётчик сам стал бы тем, от чего его отучают.
    csrfCheck('public');

    $settings = seoStatsSettings();

    if (!$settings['enabled']) {
        respond(204, '', ['Cache-Control' => 'no-store']);
    }

    $raw = postString('payload');

    // Предел тела: пачка событий от нашего скрипта — это килобайты, а тело больше предела мы
    // не разбираем вовсе. Причина не в аккуратности: разбор тела в несколько мегабайт
    // способен исчерпать память процесса, а нехватка памяти в PHP ведёт к аварийному выходу
    // движка — тот самый путь, который закрывает обновление безопасности 21 октября 2026 года
    // (CVE-2026-109618, CVE-2026-109619: повреждение состояния движка при аварийном выходе,
    // заявлена возможность удалённого выполнения кода без аутентификации). Тело приёмника
    // не проходит аутентификацию: подпись сверяется, но кто именно прислал тело, неизвестно.
    if (strlen($raw) > SEO_STATS_MAX_PAYLOAD) {
        logWarn('seo.collect.oversized', ['bytes' => strlen($raw), 'ip' => clientIp()]);
        respond(413, 'Слишком большой запрос приёмника статистики.', [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    $payload = seoStatsParsePayload($raw);

    if ($payload === null) {
        logWarn('seo.collect.malformed', ['path' => requestPath(), 'ip' => clientIp()]);
        respond(400, 'Плохой запрос приёмника статистики.', [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    $result = seoStatsStoreEvents($payload['events'], $payload['visitorId']);

    if ($result['accepted'] > 0) {
        logInfo('seo.collect.accepted', ['accepted' => $result['accepted'], 'rejected' => $result['rejected']]);
    }

    respond(204, '', ['Cache-Control' => 'no-store']);
}

/**
 * Подключение счётчика к публичной странице: тег скрипта с подписью раздела. Закрытые
 * страницы (вход, админка, кабинет) счётчику не подходят: их посещения не трафик сайта.
 *
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function seoCollectorPageMeta(array $meta, string $template = '', array $data = []): array
{
    if (!isAppActive('seo')) {
        return $meta;
    }

    if ((bool) ($meta['noindex'] ?? false)) {
        return $meta;
    }

    $settings = seoStatsSettings();

    if (!$settings['enabled']) {
        return $meta;
    }

    $meta['head'] = (string) ($meta['head'] ?? '') . "\n" . seoCollectorScriptTag($settings['respectDnt']);

    return $meta;
}

/**
 * Тег скрипта счётчика. Конфигурация едет атрибутами, а не кодом внутри тега: политика
 * безопасности запрещает инлайновые скрипты, и конфигурация не должна обходить это правило.
 */
function seoCollectorScriptTag(bool $respectDnt): string
{
    $attributes = [
        'src' => SEO_COLLECT_SCRIPT,
        'data-endpoint' => SEO_COLLECT_PATH,
        'data-token' => seoCollectToken(),
        'data-respect-dnt' => $respectDnt ? '1' : '0',
    ];

    $tag = '<script';
    foreach ($attributes as $name => $value) {
        $tag .= ' ' . $name . '="' . e($value) . '"';
    }

    return $tag . ' defer></script>';
}
