<?php

declare(strict_types=1);

// Ключ уведомления приходит из адресной строки, а текст — из словаря раздела. Неизвестный ключ
// не печатается: иначе ?notice=<script> стал бы дырой. Фильтр notice_text даёт приложению
// подменить формулировку, не трогая словарь.
const CORE_NOTICE_TEXT = [
    'signed-out' => 'Вы вышли из аккаунта.',
    // Ключ установки сайта отличается от 'installed' (установлено приложение): словари один,
    // а смыслы разные, и тексты должны различаться.
    'site-installed' => 'Сайт установлен. Войдите адресом администратора.',
    'installed' => 'Приложение установлено.',
    'updated' => 'Приложение обновлено.',
    'enabled' => 'Приложение включено.',
    'disabled' => 'Приложение выключено.',
    'settings-saved' => 'Настройки сохранены.',
];

/** @param array<string, string> $dictionary */
function noticeText(string $key, array $dictionary): ?string
{
    $text = $dictionary[$key] ?? null;
    if (!is_string($text)) {
        return null;
    }

    $filtered = applyFilters('notice_text', $text, $key);

    return is_string($filtered) ? $filtered : null;
}

/** @param array<string, string> $dictionary */
function noticeOfRequest(array $dictionary): ?string
{
    $key = queryString('notice');

    return is_string($key) && $key !== '' ? noticeText($key, $dictionary) : null;
}
