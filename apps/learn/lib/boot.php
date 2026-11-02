<?php

declare(strict_types=1);

// Правила школы подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключит этот же файл, когда появятся контроллеры.
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/draft.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/lesson-pick.php';
require_once __DIR__ . '/placement.php';
require_once __DIR__ . '/award.php';
require_once __DIR__ . '/focus.php';
