<?php

declare(strict_types=1);

// Порядок подключения важен: date.php нужен log.php, config-loader.php — всем остальным.
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/escape.php';
require_once __DIR__ . '/date.php';
require_once __DIR__ . '/config-loader.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/text.php';
require_once __DIR__ . '/id.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notice.php';
require_once __DIR__ . '/form.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/auth.php';
// routes.php идёт после auth.php, request.php и router.php: его обработчики регистрируются через addRoute().
require_once __DIR__ . '/routes.php';
// Реестр приложений подключается после маршрутизатора: его функции нужны dispatch()-гейту и админке.
require_once __DIR__ . '/apps.php';
// Миграции читают реестр (planApp сверяет манифесты), поэтому идут сразу после него.
require_once __DIR__ . '/migrations.php';
// Настройки сайта читают установщик и экраны админки, поэтому подключаются до них.
require_once __DIR__ . '/options.php';
// Seo-слой читает настройки сайта (absoluteUrl строит адреса из SITE_URL), поэтому подключается
// после options.php и раньше потребителей: его функции зовут маршруты /sitemap.xml и /robots.txt.
require_once __DIR__ . '/seo.php';
// Каркас админки подключается последним: его функции зовут appManifests(), isAppActive() и getOption(),
// а сами экраны админки их только вызывают. В установщике этот каркас не нужен, но лишний require
// безопасен — public/setup.php ничего из него не печатает.
require_once dirname(__DIR__) . '/admin/layout.php';

// Классы ядра (Mailer, SmtpSocket, MigrationPlan) подгружаются по имени.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Cms\\Core\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Cms\\Core\\'))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

date_default_timezone_set('UTC');

// Ошибки пишем в журнал, а наружу отдаём человеческий текст без дампа стека.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (($severity & error_reporting()) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $error): void {
    logError('uncaught', ['message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    require dirname(__DIR__) . '/core/templates/error.php';
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        logError('fatal', $error);
    }
});

function securityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}
