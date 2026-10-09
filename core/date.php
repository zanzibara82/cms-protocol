<?php

declare(strict_types=1);

// Единственное место, где UTC встречается с Москвой. В базе все моменты лежат в UTC, а читатель
// видит московское время: правило «9:00 — значит в девять утра по Москве» работает только так.
// Вне этого файла date(), ->format() и new DateTime запрещены стражем tests/guard/DateUsageTest.php.
const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';
const MOSCOW_OFFSET_SECONDS = 3 * 60 * 60;

const MONTHS_RU_GENITIVE = [
    1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

function moscowZone(): DateTimeZone
{
    return new DateTimeZone('Europe/Moscow');
}

function nowUtc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function utcStamp(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format(DB_DATETIME_FORMAT);
}

function fromDb(?string $raw): ?DateTimeImmutable
{
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat(DB_DATETIME_FORMAT, $value, new DateTimeZone('UTC'));

    return $parsed instanceof DateTimeImmutable ? $parsed : null;
}

// Москва без перехода на летнее время: +03:00 круглый год, поэтому ISO-строку собираем вручную —
// так же, как это делал moscowIso() в Next.js-портале.
function moscowIso(DateTimeImmutable $date): string
{
    return $date->setTimezone(moscowZone())->format('Y-m-d\TH:i:sP');
}

function formatRuDate(?DateTimeImmutable $date): string
{
    if ($date === null) {
        return '—';
    }

    $local = $date->setTimezone(moscowZone());

    return (int) $local->format('j') . ' ' . MONTHS_RU_GENITIVE[(int) $local->format('n')] . ' ' . $local->format('Y');
}

function formatRuDateTime(?DateTimeImmutable $date): string
{
    if ($date === null) {
        return '—';
    }

    return formatRuDate($date) . ', ' . $date->setTimezone(moscowZone())->format('H:i');
}

// Штамп письма по RFC 2822: почтовый сервер сверяет заголовок Date со своим временем, и пояс
// здесь всегда +0000 — момент переводится в UTC до форматирования. Русские названия дней и
// месяцев для дат в письмах не используются, поэтому берётся стандартный английский набор.
function rfc2822Stamp(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' +0000';
}

// Поле datetime-local приносит «2026-10-06T09:00» без пояса: время понимается как московское —
// ровно то, о чём договорён контент-менеджер, назначая публикацию «на девять утра».
function parseFormDateTime(string $raw): ?DateTimeImmutable
{
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, moscowZone())
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value, moscowZone());

    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    // createFromFormat не проверяет календарность: 2026-13-45 превратилось бы в 2027-01-45.
    if ($parsed->format('Y-m-d\TH:i') !== substr($value, 0, 16)) {
        return null;
    }

    return $parsed;
}
