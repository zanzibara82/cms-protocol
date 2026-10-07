<?php

declare(strict_types=1);

// Тесты всегда работают с cms_test: имя базы выставляется до подключения ядра.
putenv('CMS_DB_NAME=cms_test');
$_ENV['CMS_DB_NAME'] = 'cms_test';

require dirname(__DIR__) . '/vendor/autoload.php';
