<?php

declare(strict_types=1);

// Правила раздела подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключит этот же файл, когда появятся контроллеры (задача 16).
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/validators.php';
