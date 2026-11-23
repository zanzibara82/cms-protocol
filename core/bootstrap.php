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
// Реестр тем читается иерархией шаблонов (themeRoots()), поэтому идёт сразу после template.php.
// Каталог тем может отсутствовать: тогда сайт работает на встроенной theme/.
require_once __DIR__ . '/theme.php';
// Макеты страницы и ширина секции: каркас `layout.php` читает флаги макета, поэтому модуль
// подключается до шаблонов — иначе каркас темы не нашёл бы описание.
require_once __DIR__ . '/page-layout.php';
require_once __DIR__ . '/request.php';
// Приём картинок конструктора страниц: файл проверяется до записи на диск, а имя генерирует сервер.
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/auth.php';
// routes.php идёт после auth.php, request.php и router.php: его обработчики регистрируются через addRoute().
require_once __DIR__ . '/routes.php';
// Реестр приложений подключается после маршрутизатора: его функции нужны dispatch()-гейту и админке.
require_once __DIR__ . '/apps.php';
// Слой меню строит публичные шапку/подвал и экран /admin/menu. Пункты создаёт только
// администратор; манифесты приложений на состав меню не влияют.
require_once __DIR__ . '/menu.php';
// Миграции читают реестр (planApp сверяет манифесты), поэтому идут сразу после него.
require_once __DIR__ . '/migrations.php';
// Слой страниц читает таблицы ядра: seo возьмёт из него опубликованные адреса, обработчики
// маршрутов — правила слага и секции; оба зовут его функции в рантайме, поэтому файл подключается
// раньше них.
require_once __DIR__ . '/pages.php';
// RELEASE 2.1: журнал действий и журнал согласий — общие сервисы, которые зовут обработчики
// страниц, заявок и разделов. Корзина и дублирование работают поверх слоя страниц.
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/consent.php';
require_once __DIR__ . '/trash.php';
require_once __DIR__ . '/duplicate.php';
require_once __DIR__ . '/bulk.php';
// RELEASE 2.2: история версий, сравнение, редакционный процесс и планировщик. Файлы подключаются
// после слоя страниц: снимки читают его функции, а старые вызовы продолжают работать без них.
require_once __DIR__ . '/revisions.php';
require_once __DIR__ . '/diff.php';
require_once __DIR__ . '/editorial.php';
require_once __DIR__ . '/scheduler.php';
// RELEASE 2.3: свои типы записей, связи, глобальные блоки и экраны управления ими. Модули идут
// после редакции и планировщика: снимки ревизий и расписание уже определены, а маршруты из
// routes.php ссылаются на обработчики по имени в момент запроса.
require_once __DIR__ . '/content-types.php';
require_once __DIR__ . '/content-public.php';
require_once __DIR__ . '/content-admin.php';
// Экраны и JSON-обработчики редакции: функции слоя уже подключены, маршруты регистрирует routes.php.
require_once __DIR__ . '/editorial-routes.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/preview.php';
require_once __DIR__ . '/password-reset.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/feeds.php';
// Правила переноса данных портала из PostgreSQL: чистые функции без базы, потребитель — оболочка
// scripts/import-portal.php. Подключается в продукт, чтобы правила жили под стражами ядра.
require_once __DIR__ . '/import.php';
// Заявки с публичных форм и письма по ним: читают базу, ядро почты и раздел §17 спецификации.
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/lead-mail.php';
// Настройки сайта читают установщик и экраны админки, поэтому подключаются до них.
require_once __DIR__ . '/options.php';
// Оформление темы (цвета и типографика) лежит в опциях, поэтому подключается после options.php.
// Каркас темы печатает свой <style> из designSystemCurrent(), маршруты админки правят значения.
require_once __DIR__ . '/design-system.php';
// Обмен оформлением между сайтами: выгрузка в JSON и проверка ввезённого файла. Читает правила
// манифеста и палитры, поэтому подключается после theme.php и design-system.php.
require_once __DIR__ . '/theme-transfer.php';
// Библиотека готовых шаблонов страниц: наборы блоков конструктора, которые ставятся одним
// действием. Ставит их через createSection(), поэтому подключается после pages.php и trash.php —
// замена содержимого убирает прежние блоки в корзину.
require_once __DIR__ . '/template-library.php';
// Поля шапки и подвала — надстройка над опциями: тема читает их на каждой странице.
require_once __DIR__ . '/settings.php';
// Режим техобслуживания читает настройки, поэтому подключается после них: гейт стоит в dispatch()
// и обязан видеть siteSettingValue().
require_once __DIR__ . '/maintenance.php';
// Seo-слой строит адреса из конфигурации сборки: absoluteUrl читает configValue из config-loader.php,
// поэтому подключается после него и раньше своих потребителей — маршрутов /sitemap.xml и /robots.txt.
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
        header('Cache-Control: no-store');
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

    $hsts = securityHeadersHstsValue(isHttps());

    if ($hsts !== '') {
        header('Strict-Transport-Security: ' . $hsts);
    }
}

/**
 * Значение HSTS для ответа: по HTTP пусто. Заголовок браузер запоминает для всего
 * домена на год, поэтому отдавать его по обычному HTTP нельзя — локальная приёмка
 * на 127.0.0.1 перестала бы открываться по http:// вовсе. Правило чистым
 * помощником: стенд поднимается по HTTP, и проверить обе стороны иначе нечем.
 */
function securityHeadersHstsValue(bool $secure): string
{
    return $secure ? 'max-age=31536000; includeSubDomains' : '';
}
