<?php

declare(strict_types=1);

// Встроенный сервер PHP отдаёт существующие файлы сам, только если сценарий-маршрутизатор
// вернул false. Без этой строки под `php -S` ни `/assets/app.css`, ни `/setup.php` не открылись бы:
// каждый запрос уходил бы в маршрутизатор и заканчивался 404. На Apache то же делает правило
// `RewriteCond %{REQUEST_FILENAME} !-f` в `public/.htaccess`.
if (PHP_SAPI === 'cli-server') {
    $requested = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $file = __DIR__ . $requested;
    // Замок установщика — не раздача: он обязан отвечать 404, а не скачиваться.
    if ($requested !== '/' && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'lock') {
        return false;
    }
}

require dirname(__DIR__) . '/core/bootstrap.php';

securityHeaders();

// Сессию поднимаем до маршрутизатора: обработчик /login обязан увидеть его сразу после входа,
// а гейт приватных путей — прочитать номер версии из неё.
startSession();

// Домашняя страница, вход и выход живут в ядре: registerCoreRoutes() регистрирует их вместо
// временной строки задачи 5.
registerCoreRoutes();

dispatch();
