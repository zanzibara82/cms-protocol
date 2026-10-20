<?php

declare(strict_types=1);

// Транслит для адресов категорий и объявлений — тот же список 33 букв русского алфавита,
// что у новостного раздела и магазина: одинаковый вход даёт одинаковые адреса во всех
// разделах. Своё имя константы у раздела собственное, чтобы таблицы правок не мешались
// между разделами.
/** @var array<string, string> */
const CLASSIFIEDS_TRANSLIT = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
    'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
    'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
    'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
    'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
];

function classifiedsSlugify(string $input): string
{
    $lowered = trim(mb_strtolower($input));
    $out = '';

    foreach (preg_split('//u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        if (array_key_exists($char, CLASSIFIEDS_TRANSLIT)) {
            $out .= CLASSIFIEDS_TRANSLIT[$char];
        } elseif (preg_match('/[a-z0-9]/', $char) === 1) {
            $out .= $char;
        } elseif (preg_match('/[\s\-_.\/]/', $char) === 1) {
            $out .= '-';
        }
        // Знаки препинания и прочая графика отбрасываются.
    }

    $out = trim((string) preg_replace('/-+/', '-', $out), '-');

    return rtrim(mb_substr($out, 0, CLASSIFIEDS_SLUG_MAX), '-');
}

/**
 * Свободный адрес на основе base: при коллизии добавляет -2, -3… Пустую основу меняет на
 * fallback со словом раздела, чтобы объявление не называлось уроком новостей.
 *
 * Занятость приходит вызывающим кодом: правило не ходит в базу, поэтому его одинаково
 * проверяют и экран формы, и тест без подключения.
 */
function classifiedsUniqueSlug(string $base, callable $exists, string $fallback = 'obyavlenie'): string
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
 * и молча заменить её на другую значит сохранить объявление не туда, куда он просил.
 *
 * @param 'category'|'listing' $kind
 * @return array{slug: string, error: string}
 */
function classifiedsSlugPlan(string $typedSlug, string $title, callable $taken, string $kind): array
{
    $typed = classifiedsSlugify($typedSlug);
    $noun = $kind === 'category' ? 'категория' : 'объявление';

    if ($typed !== '') {
        // Занятость служебного сегмента проверяется до длины: короткие адреса «c» человек
        // может вписать целиком, и отказ обязан называть причину — служебную страницу
        // доски, а не формальную границу длины.
        if (in_array($typed, CLASSIFIEDS_RESERVED_SLUGS, true)) {
            return [
                'slug' => '',
                'error' => 'Адрес «' . $typed . '» занят служебной страницей доски — выберите другой.',
            ];
        }

        if (mb_strlen($typed) < CLASSIFIEDS_SLUG_MIN) {
            return [
                'slug' => '',
                'error' => 'Адрес короче ' . CLASSIFIEDS_SLUG_MIN . ' символов — по нему страницу открывают по ссылке.',
            ];
        }

        if ($taken($typed) === true) {
            return ['slug' => '', 'error' => 'По такому адресу уже есть ' . $noun . ' — придумайте другой.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = classifiedsSlugify($title);

    if (mb_strlen($base) < CLASSIFIEDS_SLUG_MIN) {
        return [
            'slug' => '',
            'error' => 'Адрес не получился: из такого названия его не собрать ('
                . CLASSIFIEDS_SLUG_MIN . ' символов минимум). Впишите адрес сами.',
        ];
    }

    return ['slug' => classifiedsUniqueSlug($base, $taken), 'error' => ''];
}
