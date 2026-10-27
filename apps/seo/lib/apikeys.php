<?php

declare(strict_types=1);

// API-ключи машинного чтения (S6, спецификация §18). Отчёты статистики — внутренние числа
// сайта, и неавторизованный читатель их не получает. Экран заводит ключи, а машинные адреса
// /seo/api/v1/* принимают их заголовком «Authorization: Bearer …»: у машины нет ни входа в
// админку, ни сессии, поэтому сессийный ворот им не помогает.
//
// Ключ хранится только хешем (sha256): база его больше нигде не показывает. Полный ключ
// печатается ровно один раз — на ответе формы создания; после закрытия этой страницы вернуть
// его нельзя, только отозвать и завести новый. По такому же правилу живут секрет вебхуков
// ядра и секрет приёмника счётчика.

const SEO_API_KEY_PREFIX = 'seoa_';
const SEO_API_KEY_RANDOM_BYTES = 20;

// Скрытая часть ключа ровно 40 знаков: форма «seoa_» плюс 40 шестнадцатеричных. Значение
// короче или с чужим префиксом в базу не смотрит вовсе — иначе пустому заголовку оставались
// бы лишние запросы.
const SEO_API_KEY_HEX_LENGTH = 40;

// Список ключей не резиновый: чек-лист хостинга кладёт память прежде удобства, а текущая
// потребность — единицы ключей, по одному на интеграцию.
const SEO_API_KEY_MAX = 20;

// Пара запретов для машинного адреса: отчёты не должны ни попадать в поиск, ни оставаться
// в кэше прокси. Приёмник счётчика несёт те же запреты — машинные адреса раздела единообразны.
const SEO_API_HEADERS = [
    'Content-Type' => 'application/json; charset=utf-8',
    'X-Robots-Tag' => 'noindex',
    'Cache-Control' => 'no-store',
];

/**
 * Машинное имя ключа: префикс плюс случайные байты. Префикс в самом ключе — часть его формы:
 * человек, вставив ключ в конфигурацию читателя, видит, чья это метка.
 */
function seoApiKeyGenerate(): string
{
    return SEO_API_KEY_PREFIX . bin2hex(random_bytes(SEO_API_KEY_RANDOM_BYTES));
}

/**
 * Форма ключа: префикс ровно такой и скрытая часть ровно такой длины. Разбор честный:
 * значение, приведённое не в форме, отклоняется целиком, а не «по образцу».
 */
function seoApiKeyShape(string $key): ?string
{
    $clean = trim($key);

    if (!str_starts_with($clean, SEO_API_KEY_PREFIX)) {
        return null;
    }

    $secret = substr($clean, strlen(SEO_API_KEY_PREFIX));

    if (strlen($secret) !== SEO_API_KEY_HEX_LENGTH || preg_match('/^[0-9a-f]+$/', $secret) !== 1) {
        return null;
    }

    return $clean;
}

/**
 * Хеш ключа для базы: уникальный индекс не даёт двум ключам подкрутиться, а сравнение
 * по индексу не открывает угадывание по времени ответа — совпадение либо есть, либо нет.
 */
function seoApiKeyHash(string $key): string
{
    return hash('sha256', $key);
}

/**
 * Аутентификация машинного запроса: заголовок обязан нести ровно «Bearer <ключ>» — слово,
 * пробел и ключ без хвоста. Прежняя проверка принимала «Bearerseoa_…» (префикс без пробела)
 * и «Bearer … лишнее»: первое проходило форму, потому что `substr(6)` срезал одну букву,
 * второе — потому что хвост отбрасывался молча. Отвечает парой ключа-владельца либо null —
 * вызывающий сам решает, каким кодом отказать.
 *
 * @return array{id: string, label: string}|null
 */
function seoApiKeyAuthenticate(string $header): ?array
{
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
        return null;
    }

    $clean = seoApiKeyShape($matches[1]);

    if ($clean === null) {
        return null;
    }

    $row = one(
        'SELECT id, label FROM ' . t('seo_api_key') . ' WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1',
        [seoApiKeyHash($clean)]
    );

    if ($row === null) {
        return null;
    }

    run('UPDATE ' . t('seo_api_key') . ' SET last_used_at = ? WHERE id = ?', [utcStamp(nowUtc()), (string) $row['id']]);

    return ['id' => (string) $row['id'], 'label' => (string) $row['label']];
}

/**
 * Список ключей для экрана: свежие сверху, отзывчивость видна строкой. Полного ключа здесь
 * нет и не будет: база держит хеш, а экран обещает «показывается один раз».
 *
 * @return list<array{id: string, label: string, prefix: string, created_at: string, last_used_at: string, revoked_at: string}>
 */
function seoApiKeysList(): array
{
    $rows = all(
        'SELECT id, label, key_prefix, created_at, last_used_at, revoked_at FROM ' . t('seo_api_key')
        . ' ORDER BY created_at DESC',
        []
    );

    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'label' => (string) $row['label'],
            'prefix' => (string) $row['key_prefix'],
            'created_at' => (string) $row['created_at'],
            'last_used_at' => (string) ($row['last_used_at'] ?? ''),
            'revoked_at' => (string) ($row['revoked_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Метка кандидата: без пустых и в пределах колонки. Причина кратко, в один шаг:
 * человек набирает её сам, и «что не так» обязан сказать отказ, а не догадка.
 *
 * @return array{ok: bool, label: string, error: string}
 */
function seoApiKeyValidateLabel(string $raw): array
{
    $label = trim($raw);

    if ($label === '') {
        return ['ok' => false, 'label' => '', 'error' => 'Подпишите ключ в одно слово или два — например «Кабинет отчётов».'];
    }

    if (mb_strlen($label) > 190) {
        return ['ok' => false, 'label' => mb_substr($label, 0, 190), 'error' => 'Метка длиннее 190 знаков — сократите её.'];
    }

    return ['ok' => true, 'label' => $label, 'error' => ''];
}

/**
 * Создание ключа: метка обязательна, число ключей ограничено. Полный ключ возвращается
 * ровно один раз — на ответе формы; его хеш уходит в базу, а сам он нигде больше не живёт.
 * Ключ уведомления возвращается рядом: действие пересылает его, как и у отзыва, не выписывая
 * литерал в точке показа.
 *
 * @return array{ok: bool, error: string, key: string, id: string, notice: string}
 */
function seoApiKeyCreate(string $label): array
{
    $check = seoApiKeyValidateLabel($label);

    if (!$check['ok']) {
        return ['ok' => false, 'error' => $check['error'], 'key' => '', 'id' => '', 'notice' => ''];
    }

    // Отозванная строка остаётся историей экрана, но места в пределе не занимает: иначе
    // «отзовите один» было бы тупиком — после двадцатого когда-либо созданного ключа
    // создание отвергалось бы навсегда.
    if ((int) value('SELECT COUNT(*) FROM ' . t('seo_api_key') . ' WHERE revoked_at IS NULL', []) >= SEO_API_KEY_MAX) {
        return ['ok' => false, 'error' => 'Действующих ключей уже ' . SEO_API_KEY_MAX . ' — отзовите один, прежде чем заводить новый.', 'key' => '', 'id' => '', 'notice' => ''];
    }

    $key = seoApiKeyGenerate();
    $id = newId();
    $stamp = utcStamp(nowUtc());

    insert('seo_api_key', [
        'id' => $id,
        'label' => $check['label'],
        'key_prefix' => substr($key, 0, 12),
        'key_hash' => seoApiKeyHash($key),
        'created_at' => $stamp,
        'last_used_at' => null,
        'revoked_at' => null,
    ]);

    logInfo('seo.api_key.created', ['id' => $id]);

    return ['ok' => true, 'error' => '', 'key' => $key, 'id' => $id, 'notice' => 'api_key_created'];
}

/**
 * Отзыв ключа: строка остаётся — «вчера ключом пользовались» не должно исчезать из экрана,
 * а «ещё раз с тем же ключом» обязано стать отказом. Отзывать можно только живой ключ:
 * повторное отзыв ничего не меняет и объясняет это.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function seoApiKeyRevoke(string $id): array
{
    $row = one(
        'SELECT id, revoked_at FROM ' . t('seo_api_key') . ' WHERE id = ? LIMIT 1',
        [mb_substr(trim($id), 0, 26)]
    );

    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Такого ключа уже нет — обновите список.'];
    }

    if ($row['revoked_at'] !== null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Ключ уже отозван.'];
    }

    run('UPDATE ' . t('seo_api_key') . ' SET revoked_at = ? WHERE id = ?', [utcStamp(nowUtc()), (string) $row['id']]);
    logInfo('seo.api_key.revoked', ['id' => (string) $row['id']]);

    return ['ok' => true, 'error' => '', 'notice' => 'api_key_revoked'];
}
