<?php

declare(strict_types=1);

// Правила раздела подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключит этот же файл, когда контроллеры и экраны встретятся.
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/orders.php';
