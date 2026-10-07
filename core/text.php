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
