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
    // Ключ обновления ядра отличается от 'updated' (обновлено приложение): экран «Приложения»
    // различает «Ядро» и «Разделы», и тексты должны различаться.
    'core-updated' => 'Ядро обновлено.',
    // Заголовок отказа: подробная причина живёт в сессионном flash, в адресной строке — только ключ.
    'migration-failed' => 'Не удалось завершить действие с разделом. Подробности ниже и в журнале ошибок.',
    'enabled' => 'Приложение включено.',
    'disabled' => 'Приложение выключено.',
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

// Ключи отказа: их показывать зелёной плашкой об успехе нельзя — они несут сообщение о сбое.
// Список живёт в одном месте, чтобы ни один экран админки не заводил своё перечисление.
const CORE_FAILURE_NOTICE_KEYS = ['migration-failed'];

function requestNoticeIsFailure(): bool
{
    $key = queryString('notice', '');

    return is_string($key) && $key !== '' && in_array($key, CORE_FAILURE_NOTICE_KEYS, true);
}

// Плашка для экрана админки: заголовок отказа гасится, остальные ключи читаются из словаря как обычно.
/** @param array<string, string> $dictionary */
function noticeForAdminScreen(array $dictionary): string
{
    if (requestNoticeIsFailure()) {
        return '';
    }

    return (string) (noticeOfRequest($dictionary) ?? '');
}

/**
 * Причина отказа живёт в сессионном flash и показывается ровно один раз: второй показ означал бы,
 * что человек увидел старую причину свежего действия.
 */
function takeAdminFlashReason(): string
{
    startSession();
    $flashed = $_SESSION['admin_error'] ?? '';
    unset($_SESSION['admin_error']);

    return is_string($flashed) ? $flashed : '';
}

// Заголовок отказа — из словаря ядра по ключу 'migration-failed'. Пустая строка означает «отказа нет»:
// ни один экран не должен выдумывать свою формулировку сбоя.
function adminFailureTitle(string $reason): string
{
    if ($reason === '' && !requestNoticeIsFailure()) {
        return '';
    }

    return (string) (noticeText('migration-failed', CORE_NOTICE_TEXT) ?? '');
}
