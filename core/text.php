<?php

declare(strict_types=1);

// Русское согласование: 1 материал, 2 материала, 5 материалов. Формы — «один / несколько / много».
/** @param list<string> $forms */
function plural(int $count, array $forms): string
{
    $mod10 = $count % 10;
    $mod100 = $count % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return (string) $forms[0];
    }
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
        return (string) $forms[1];
    }

    return (string) $forms[2];
}

// Короткий текст для мест, где описание не помещается: лид карточки, описание для поисковика.
function excerpt(string $text, int $limit = 160): string
{
    $flat = trim((string) preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($flat) <= $limit) {
        return $flat;
    }

    return rtrim(mb_substr($flat, 0, $limit)) . '…';
}

// Транслит для адресов — 33 буквы русского алфавита. Список перенесён из src/lib/slug.ts
// Next.js-портала без изменений, чтобы при переносе содержимого из PostgreSQL адреса страниц
// и материалов совпали с адресами портала. Общий для ядра и разделов: у каждого раздела свои
// пределы длины, а алфавит один.
/** @var array<string, string> */
const SLUG_TRANSLIT = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
    'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
    'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
    'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
    'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
];

/**
 * Адрес из произвольного текста: русский — транслитом, пробелы и разделители — в «-», прочая
 * графика отбрасывается. Предел caller передаёт сам (NEWS_SLUG_MAX, PAGES_SLUG_MAX — колонки
 * разной ширины), обрезка снимает оборванный хвостовой дефис.
 */
function slugify(string $input, int $max = 80): string
{
    $lowered = trim(mb_strtolower($input));
    $out = '';

    foreach (preg_split('//u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        if (array_key_exists($char, SLUG_TRANSLIT)) {
            $out .= SLUG_TRANSLIT[$char];
        } elseif (preg_match('/[a-z0-9]/', $char) === 1) {
            $out .= $char;
        } elseif (preg_match('/[\s\-_.\/]/', $char) === 1) {
            $out .= '-';
        }
        // Прочие символы — знаки препинания и прочая графика — отбрасываются.
    }

    $out = trim((string) preg_replace('/-+/', '-', $out), '-');

    return rtrim(mb_substr($out, 0, $max), '-');
}

/**
 * Свободный адрес на основе base: при коллизии добавляет -2, -3… Пустую основу меняет на
 * fallback — у каждого раздела он свой (у новостей «novost»), чтобы материал не назывался
 * чужим словом.
 *
 * Проверка занятости приходит вызывающим кодом: правило не ходит в базу, поэтому его одинаково
 * проверяют и лента, и форма, и тест без подключения.
 */
function ensureUniqueSlug(string $base, callable $exists, string $fallback): string
{
    $root = $base !== '' ? $base : $fallback;
    $candidate = $root;

    for ($n = 2; $exists($candidate); $n++) {
        $candidate = $root . '-' . $n;
    }

    return $candidate;
}

/**
 * Предел из справочника полей: у части записей это число (`items`, `paragraphs`), у части —
 * пара min/max (`title`, `label`). Один способ чтения убирает приведения типов из кода раздела
 * и из тестов — PHPStan на уровне 6 не пускает обращение по ключу к записи, которая может
 * оказаться числом. Живёт в ядре: справочники пределов есть и у страниц, и у разделов.
 *
 * @param array<string, array<string, int>|int> $limits
 */
function fieldLimit(array $limits, string $field, string $key = ''): int
{
    $entry = $limits[$field] ?? 0;

    if (is_int($entry)) {
        return $entry;
    }

    return is_array($entry) ? (int) ($entry[$key] ?? 0) : 0;
}
