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

// Домашняя страница регистрируется здесь временно: с задачи 6 её отдаёт registerCoreRoutes().
addRoute('GET', '/', static function (): void {
    render('home', [], ['title' => (string) configValue('SITE_NAME', 'Школа'), 'description' => 'Сайт школы английского языка']);
});

dispatch();
