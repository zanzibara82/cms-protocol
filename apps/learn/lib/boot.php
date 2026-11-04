<?php

declare(strict_types=1);

// Правила школы подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключает этот же файл для контроллеров.
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/draft.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/lesson-pick.php';
require_once __DIR__ . '/placement.php';
require_once __DIR__ . '/award.php';
require_once __DIR__ . '/focus.php';
require_once __DIR__ . '/progress.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/validators.php';
