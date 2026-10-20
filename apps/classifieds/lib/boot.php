<?php

declare(strict_types=1);

// Подключение рабочего слоя раздела. Правила нужны контроллерам при каждом запросе,
// а в боевом процессе их не подключает никто, поэтому require_once здесь — единственная
// точка, где константы встречаются с помощниками.
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/listings.php';
require_once __DIR__ . '/upload.php';
