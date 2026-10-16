<?php

declare(strict_types=1);

// Правила раздела подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключит этот же файл, когда появятся контроллеры (задача 16).
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/feed.php';
require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/items.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/form.php';
require_once __DIR__ . '/upload.php';
