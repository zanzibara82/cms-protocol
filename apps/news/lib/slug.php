<?php

declare(strict_types=1);

// Транслит для адресов — 33 буквы русского алфавита. Список перенесён из src/lib/slug.ts
// Next.js-портала без изменений, чтобы при переносе содержимого адреса материалов совпали.
/** @var array<string, string> */
const NEWS_TRANSLIT = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
    'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
    'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
    'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
    'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
];

function slugify(string $input): string
{
    $lowered = trim(mb_strtolower($input));
    $out = '';

    foreach (preg_split('//u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        if (array_key_exists($char, NEWS_TRANSLIT)) {
            $out .= NEWS_TRANSLIT[$char];
        } elseif (preg_match('/[a-z0-9]/', $char) === 1) {
            $out .= $char;
        } elseif (preg_match('/[\s\-_.\/]/', $char) === 1) {
            $out .= '-';
        }
        // Прочие символы — знаки препинания и прочая графика — отбрасываются.
    }

    $out = trim((string) preg_replace('/-+/', '-', $out), '-');

    return rtrim(mb_substr($out, 0, NEWS_SLUG_MAX), '-');
}

/**
 * Свободный адрес на основе base: при коллизии добавляет -2, -3… Пустую основу меняет на
 * fallback — у раздела новостей он свой, чтобы материал не назывался уроком.
 *
 * Проверка занятости приходит вызывающим кодом: правило не ходит в базу, поэтому его одинаково
 * проверяют и лента, и форма, и тест без подключения.
 */
function ensureUniqueSlug(string $base, callable $exists, string $fallback = 'novost'): string
{
    $root = $base !== '' ? $base : $fallback;
    $candidate = $root;

    for ($n = 2; $exists($candidate); $n++) {
        $candidate = $root . '-' . $n;
    }

    return $candidate;
}

/**
 * План адреса материала: что положить в базу и что сказать человеку.
 *
 * Пустая строка в поле адреса означает «соберите из заголовка» — тогда коллизия решается
 * нумерацией. Вписанный руками адрес не нумеруется: человек указал конкретную ссылку, и молча
 * заменить её на другую значит сохранить материал не туда, куда он просил.
 *
 * @return array{slug: string, error: string}
 */
function newsSlugPlan(string $typedSlug, string $title, callable $taken): array
{
    $typed = slugify($typedSlug);

    if ($typed !== '') {
        // Занятость служебного сегмента проверяется до длины: однобуквенные адреса «c» и «t»
        // из NEWS_RESERVED_SLUGS человек может вписать целиком, и отказ обязан называть причину —
        // служебную страницу раздела, а не формальную длину.
        if (in_array($typed, NEWS_RESERVED_SLUGS, true)) {
            return [
                'slug' => '',
                'error' => 'Адрес «' . $typed . '» занят служебной страницей раздела — выберите другой.',
            ];
        }

        if (mb_strlen($typed) < NEWS_SLUG_MIN) {
            return [
                'slug' => '',
                'error' => 'Адрес короче ' . NEWS_SLUG_MIN . ' символов — по нему материал открывают по ссылке.',
            ];
        }

        if ($taken($typed) === true) {
            return ['slug' => '', 'error' => 'По такому адресу уже есть материал — придумайте другой.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = slugify($title);

    if (mb_strlen($base) < NEWS_SLUG_MIN) {
        return [
            'slug' => '',
            'error' => 'Адрес не получился: из такого заголовка его не собрать ('
                . NEWS_SLUG_MIN . ' символов минимум). Впишите адрес сами.',
        ];
    }

    return ['slug' => ensureUniqueSlug($base, $taken), 'error' => ''];
}

/**
 * Список прежних адресов материала. Прежний адрес ставится первым, совпадения и повторы
 * убираются, а хвост длиннее OLD_SLUGS_MAX отрезается: старые адреса нужны для постоянного
 * перенаправления, а не для того, чтобы хранить всю историю правок.
 *
 * @param list<string> $slugs
 * @return list<string>
 */
function rememberOldSlug(array $slugs, string $previous, string $next): array
{
    if ($previous === '' || $previous === $next) {
        return $slugs;
    }

    $out = [$previous];

    foreach ($slugs as $slug) {
        if ($slug === '' || $slug === $next || in_array($slug, $out, true)) {
            continue;
        }

        $out[] = $slug;
    }

    return array_slice($out, 0, OLD_SLUGS_MAX);
}
