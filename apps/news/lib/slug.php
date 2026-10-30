<?php

declare(strict_types=1);

// Адреса материалов. Транслит и нормализация переехали в ядро (core/text.php): список букв тот
// же, что в src/lib/slug.ts портала, а предел длины раздел передаёт сам — NEWS_SLUG_MAX держит
// ширину колонки tbl_news_item.slug.

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
    $typed = slugify($typedSlug, NEWS_SLUG_MAX);

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

    $base = slugify($title, NEWS_SLUG_MAX);

    if (mb_strlen($base) < NEWS_SLUG_MIN) {
        return [
            'slug' => '',
            'error' => 'Адрес не получился: из такого заголовка его не собрать ('
                . NEWS_SLUG_MIN . ' символов минимум). Впишите адрес сами.',
        ];
    }

    return ['slug' => ensureUniqueSlug($base, $taken, 'novost'), 'error' => ''];
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
