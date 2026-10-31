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
    // Ключи конструктора страниц: по одному на действие (создание, правка, удаление страницы;
    // добавление, правка, перестановка, удаление секции). Видимость секции делит ключ с правкой
    // полей — и то и другое сохранение секции, объяснение одно и то же.
    'page_created' => 'Страница создана.',
    'page_saved' => 'Страница сохранена.',
    'page_deleted' => 'Страница удалена вместе с блоками.',
    'section_created' => 'Секция добавлена в конец страницы.',
    'section_saved' => 'Секция сохранена.',
    'section_moved' => 'Порядок секций изменён.',
    'section_deleted' => 'Секция удалена.',
    // Ключи меню шапки и подвала: по одному на действие пункта (создание, сохранение, перестановка,
    // удаление). Видимость делит ключ с сохранением — и то и другое сохранение пункта.
    'menu_created' => 'Пункт меню добавлен в конец слота.',
    'menu_saved' => 'Пункт меню сохранён.',
    'menu_moved' => 'Порядок пунктов изменён.',
    'menu_deleted' => 'Пункт меню удалён.',
    // Предупреждение шага 1 установщика: настройки записаны, но права 600 выставить не удалось.
    // Текст идентичен warning из setupWriteConfig() — человек видит его плашкой на шаге 2.
    'config-permissions' => 'Настройки записаны, но права 600 выставить не удалось — поправьте их в панели хостинга.',
    // Отказ шага 2 установщика: в отличие от 'config-permissions' (шаг 1 прошёл, предупреждение о
    // правах) и от 'site-installed' (шаг 2 завершился успехом) этот ключ означает, что шаг 2
    // попросили без отметки сессии перехода — шага 1 человек не проходил. Показывается плашкой на
    // экране шага 1, куда редиректит гейт, а не на шаге 2, который как раз и закрыт.
    'db-step-required' => 'Второй шаг недоступен: сначала пройдите первый шаг с параметрами базы.',
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
