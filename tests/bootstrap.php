<?php

declare(strict_types=1);

putenv('CMS_DB_NAME=cms_test');
$_ENV['CMS_DB_NAME'] = 'cms_test';
putenv('CMS_CONFIG_FILE=' . dirname(__DIR__) . '/config.sample.php');
putenv('CMS_DB_HOST=' . (getenv('CMS_DB_HOST') ?: 'db'));

// Каталоги фикстур выставляются до подключения ядра: config() кеширует результат в static-переменной
// и применяет переменные CMS_* один раз, поэтому putenv после require уже ничего не изменит.
putenv('CMS_EXTRA_APPS_DIR=' . dirname(__DIR__) . '/tests/fixtures/apps');
putenv('CMS_EXTRA_THEME_DIR=' . dirname(__DIR__) . '/tests/fixtures/theme');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/db.php';
require dirname(__DIR__) . '/tests/TestDb.php';
