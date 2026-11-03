<?php

declare(strict_types=1);

// Ученический контур: курс, модуль и урок. Пути записаны литералами, а вход проверяет
// обработчик `learnUser()`: приватный гейт ядра пускает только редакторов и админов,
// а ученик — отдельная роль. Порядок объявления повторяет вложенность адресов.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/student.php';
require_once __DIR__ . '/ui/exercise.php';

addRoute('GET', '/learn', 'learnCoursePage', ['app' => 'learn']);
addRoute('GET', '/learn/module/{id}', 'learnModulePage', ['app' => 'learn']);
addRoute('GET', '/lesson/{slug}', 'learnLessonPage', ['app' => 'learn']);
