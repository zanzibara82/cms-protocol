<?php

declare(strict_types=1);

// Транслит для адресов товаров и рубрик — тот же список 33 букв русского алфавита, что в
// новостном разделе (apps/news/lib/slug.php): одинаковый вход даёт одинаковые адреса и в
// каталоге, и в ленте. Своё имя константы у раздела собственное, чтобы таблицы правок не
// мешались между разделами.
/** @var array<string, string> */
const COMMERCE_TRANSLIT = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
    'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
    'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
    'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
    'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
];

function commerceSlugify(string $input): string
{
    $lowered = trim(mb_strtolower($input));
    $out = '';

    foreach (preg_split('//u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        if (array_key_exists($char, COMMERCE_TRANSLIT)) {
            $out .= COMMERCE_TRANSLIT[$char];
        } elseif (preg_match('/[a-z0-9]/', $char) === 1) {
            $out .= $char;
        } elseif (preg_match('/[\s\-_.\/]/', $char) === 1) {
            $out .= '-';
        }
        // Знаки препинания и прочая графика отбрасываются.
    }

    $out = trim((string) preg_replace('/-+/', '-', $out), '-');

    return rtrim(mb_substr($out, 0, COMMERCE_SLUG_MAX), '-');
}

/**
 * Свободный адрес на основе base: при коллизии добавляет -2, -3… Пустую основу меняет на
 * fallback со словом раздела, чтобы товар не назывался уроком новостей.
 *
 * Занятость приходит вызывающим кодом: правило не ходит в базу, поэтому его одинаково
 * проверяют и экран товара, и тест без подключения.
 */
function commerceUniqueSlug(string $base, callable $exists, string $fallback = 'tovar'): string
{
    $root = $base !== '' ? $base : $fallback;
    $candidate = $root;

    for ($n = 2; $exists($candidate); $n++) {
        $candidate = $root . '-' . $n;
    }

    return $candidate;
}

/**
 * План адреса: что положить в базу и что сказать человеку.
 *
 * Пустая строка в поле адреса означает «соберите из названия» — коллизия решается
 * нумерацией. Вписанный руками адрес не нумеруется: человек указал конкретную ссылку,
 * и молча заменить её на другую значит сохранить объект не туда, куда он просил.
 *
 * @return array{slug: string, error: string}
 */
function commerceSlugPlan(string $typedSlug, string $title, callable $taken, string $kind): array
{
    $typed = commerceSlugify($typedSlug);
    $noun = $kind === 'category' ? 'рубрика' : 'товар';

    if ($typed !== '') {
        // Занятость служебного сегмента проверяется до длины: короткие адреса «c» и «cart»
        // человек может вписать целиком, и отказ обязан называть причину — служебную
        // страницу витрины, а не формальную границу длины.
        if (in_array($typed, COMMERCE_RESERVED_SLUGS, true)) {
            return [
                'slug' => '',
                'error' => 'Адрес «' . $typed . '» занят служебной страницей витрины — выберите другой.',
            ];
        }

        if (mb_strlen($typed) < COMMERCE_SLUG_MIN) {
            return [
                'slug' => '',
                'error' => 'Адрес короче ' . COMMERCE_SLUG_MIN . ' символов — по нему страницу открывают по ссылке.',
            ];
        }

        if ($taken($typed) === true) {
            return ['slug' => '', 'error' => 'По такому адресу уже есть ' . $noun . ' — придумайте другой.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = commerceSlugify($title);

    if (mb_strlen($base) < COMMERCE_SLUG_MIN) {
        return [
            'slug' => '',
            'error' => 'Адрес не получился: из такого названия его не собрать ('
                . COMMERCE_SLUG_MIN . ' символов минимум). Впишите адрес сами.',
        ];
    }

    return ['slug' => commerceUniqueSlug($base, $taken), 'error' => ''];
}
