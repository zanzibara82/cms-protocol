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

// Сдвиг момента на сутки: «вчера» в счётчиках значит вчерашний календарный день, а не
// вчерашние 24 часа. Сдвиг идёт по UTC — московская шкала появляется на краях.
function shiftDays(DateTimeImmutable $date, int $days): DateTimeImmutable
{
    $shifted = $date->modify(($days >= 0 ? '+' : '-') . abs($days) . ' days');

    return $shifted instanceof DateTimeImmutable ? $shifted : $date;
}

// Сдвиг момента на секунды: окна приёма событий считают минуты, а не календарные дни.
function shiftSeconds(DateTimeImmutable $date, int $seconds): DateTimeImmutable
{
    $shifted = $date->modify(($seconds >= 0 ? '+' : '-') . abs($seconds) . ' seconds');

    return $shifted instanceof DateTimeImmutable ? $shifted : $date;
}

// Ключ календарного дня по московской шкале: счётчики разделов считают день по нему,
// иначе ночные события попадали бы в соседние дни.
function moscowDayKey(DateTimeImmutable $date): string
{
    return $date->setTimezone(moscowZone())->format('Y-m-d');
}

// Сдвиг календарного дня по московской шкале: ключ «2026-10-21» плюс сутки назад или вперёд.
function shiftMoscowDay(string $day, int $days): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00', moscowZone());

    if (!$parsed instanceof DateTimeImmutable) {
        return $day;
    }

    return moscowDayKey(shiftDays($parsed, $days));
}

// Ключ календарного дня по UTC: поисковые отчёты считают дни по шкале источника, а не по
// московской, иначе ночные данные уезжали бы в соседний день.
function utcDayKey(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
}

// Разбор календарного дня «ГГГГ-ММ-ДД» в UTC. null — дата не понята: календарность
// проверяется, чтобы 2026-02-31 не превратился в март.
function parseUtcDay(string $raw): ?DateTimeImmutable
{
    $value = trim($raw);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }

    [$y, $m, $d] = array_map('intval', explode('-', $value));

    if (!checkdate($m, $d, $y)) {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value . ' 00:00:00', new DateTimeZone('UTC'));

    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
        return null;
    }

    return $parsed;
}

// Секунды момента: сравнение сроков и подписей состояния идёт по числам, а не по строкам.
function utcTimestamp(DateTimeImmutable $date): int
{
    return $date->getTimestamp();
}

// Штамп по секундам: метка «когда был импорт» хранится числом, а читается как дата.
function utcStampFromTimestamp(int $timestamp): string
{
    return utcStamp(new DateTimeImmutable('@' . $timestamp));
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

// Дни акций «ГГГГ-ММ-ДД» из форм магазина: край 'start' — начало дня по Москве
// (00:00:00), край 'end' — его окно (23:59:59). Момент возвращается в UTC — тем же
// правилом «в базе UTC, Москва только на краях», что и у остального модуля.
// Разбор по дню честный: checkdate() отсекает 2026-02-31, а обратная сборка репексированных
// полей — лишнее в строке после даты.
function parseMoscowDay(string $raw, string $edge = 'start'): ?DateTimeImmutable
{
    $value = trim($raw);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    [$y, $m, $d] = array_map('intval', explode('-', $value));

    if (!checkdate($m, $d, $y)) {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $value . ($edge === 'end' ? ' 23:59:59' : ' 00:00:00'),
        moscowZone()
    );

    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
        return null;
    }

    return $parsed->setTimezone(new DateTimeZone('UTC'));
}
