<?php

declare(strict_types=1);

// Константа обязана появиться до подключения ядра: иначе первый же respond() в тестах успеет выйти.
define('PHPUNIT_RUNNING', true);

putenv('CMS_DB_NAME=cms_test');
$_ENV['CMS_DB_NAME'] = 'cms_test';
putenv('CMS_CONFIG_FILE=' . dirname(__DIR__) . '/config.sample.php');
putenv('CMS_DB_HOST=' . (getenv('CMS_DB_HOST') ?: 'db'));

// Каталоги фикстур выставляются до подключения ядра: config() кеширует результат в static-переменной
// и применяет переменные CMS_* один раз, поэтому putenv после require уже ничего не изменит.
putenv('CMS_EXTRA_APPS_DIR=' . dirname(__DIR__) . '/tests/fixtures/apps');
putenv('CMS_EXTRA_THEME_DIR=' . dirname(__DIR__) . '/tests/fixtures/theme');

// Прогон на установленной копии не должен открывать установщик: HTTP-наборы удаляют
// public/install.lock, чтобы проверять экраны setup.php. Запоминаем настоящий замок до первого
// теста и возвращаем его после прогона — иначе тесты меняли бы состояние живой копии.
$installLockPath = dirname(__DIR__) . '/public/install.lock';
$installLockContents = is_file($installLockPath) ? file_get_contents($installLockPath) : false;
if ($installLockContents !== false) {
    register_shutdown_function(static function () use ($installLockPath, $installLockContents): void {
        file_put_contents($installLockPath, $installLockContents);
        @chmod($installLockPath, 0644);
    });
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/core/bootstrap.php';
// core/db.php больше не подключается здесь: он входит в список core/bootstrap.php (задача 6),
// а отдельный require привёл бы к «Cannot redeclare db()».
require dirname(__DIR__) . '/tests/TestDb.php';
require_once dirname(__DIR__) . '/apps/news/lib/boot.php';
require_once dirname(__DIR__) . '/apps/commerce/lib/boot.php';
require_once dirname(__DIR__) . '/apps/classifieds/lib/boot.php';
require_once dirname(__DIR__) . '/apps/seo/lib/boot.php';
require_once dirname(__DIR__) . '/apps/learn/lib/boot.php';
