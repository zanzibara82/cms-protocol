<?php

declare(strict_types=1);

// Режим техобслуживания: пока флажок в настройках включён, посетитель видит заглушку, а вход и
// админка остаются открытыми — иначе режим нечем снять, и сайт закрылся бы навсегда.
//
// Правило путей живёт чистой функцией, а не проверкой внутри dispatch(): оно решает, что увидит
// человек, и его проверяет unit-тест без базы. Список открытых адресов короткий намеренно —
// «дверь» сводится к входу, выходу, сбросу пароля и админке; всё остальное публичное.
const MAINTENANCE_OPEN_PATHS = ['/login', '/logout', '/admin', '/forgot-password', '/reset-password'];

/**
 * Адрес «двери», а не публичного контента. Сравнение по сегментам, а не по префиксу строки:
 * «/administrator» начинается с «/admin», но админкой не является и остаётся закрытым.
 */
function maintenanceAllowsPath(string $path): bool
{
    $clean = '/' . trim($path, '/');

    foreach (MAINTENANCE_OPEN_PATHS as $prefix) {
        if ($clean === $prefix || str_starts_with($clean, $prefix . '/')) {
            return true;
        }
    }

    return false;
}

/** Включён ли режим: значение хранится как '1' в опции, отсутствие ключа — выключено. */
function maintenanceModeOn(): bool
{
    return getOption('maintenance_mode') === '1';
}

// Кому при закрытом сайте положено видеть его как обычно. Редактор тоже правит содержимое и обязан
// проверять вёрстку перед открытием — иначе режим закрывал бы сайт и от того, кто его готовит.
const MAINTENANCE_STAFF_ROLES = [ROLES['admin'], ROLES['editor']];

/**
 * Вошёл ли сотрудник. Гейт режима стоит в dispatch() до разбора маршрута, а сессию там поднимает
 * более поздний шаг — поэтому здесь она поднимается сама, но только когда кука пришла: гостю с
 * пустой колбасой session_start() выдал бы новый идентификатор на каждый заход, хотя ему и так
 * положена заглушка.
 *
 * Проверка повторяет currentUser(), а не доверяет куке: подделанный идентификатор сессии не найдёт
 * пользователя в базе, а отозванная сессия не сойдётся по session_version — оба останутся гостем.
 */
function maintenanceStaffSignedIn(): bool
{
    if (!isset($_COOKIE[SESSION_COOKIE_NAME]) || !is_string($_COOKIE[SESSION_COOKIE_NAME])) {
        return false;
    }

    startSession();

    $user = currentUser();
    if ($user === null || $user['blocked_at'] !== null) {
        return false;
    }

    return in_array((string) $user['role'], MAINTENANCE_STAFF_ROLES, true);
}

/**
 * Единственная точка, где режим закрывает публичный запрос. Вызывается из dispatch() ДО разбора
 * маршрута: заглушку обязаны увидеть и страницы, и ленты, и карта сайта, и несуществующие адреса —
 * иначе по коду ответа (404 против 503) было бы видно, какие адреса существуют.
 */
function maintenanceGuard(): void
{
    if (!maintenanceModeOn()) {
        return;
    }

    // Дверь (вход, админка, сброс пароля) и вошедший сотрудник проходят: первый нужен, чтобы режим
    // можно было снять, второй — чтобы увидеть, что именно он закрыл.
    if (maintenanceAllowsPath(requestPath()) || maintenanceStaffSignedIn()) {
        return;
    }

    // Разметка собирается здесь же, в одном блоке с ответом: страж NoIndexNoStoreSourceTest читает
    // обрамляющий блок вокруг литерала выдачи и требует в нём директиву запрета хранения. Разнести
    // сборку и respond() по разным функциям значило бы вернуть кэшируемую заглушку молча.
    $html = partial('maintenance', [
        'text' => siteSettingValue('maintenance_text'),
        'image' => '',
    ]);

    respond(503, $html, [
        'Content-Type' => 'text/html; charset=utf-8',
        // Заглушка не индексируется и не кэшируется: иначе поисковая машина запомнит её как
        // страницу сайта, а браузер покажет её ещё сутки после снятия режима.
        'X-Robots-Tag' => 'noindex, nofollow',
        'Cache-Control' => 'no-store',
        // Повторный обход через десять минут: сайт вернётся, и поисковик должен это увидеть.
        'Retry-After' => '600',
    ]);
}
