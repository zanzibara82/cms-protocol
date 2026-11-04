<?php

declare(strict_types=1);

// Ученический контур: курс, модуль и урок. Пути записаны литералами, а вход проверяет
// обработчик `learnUser()`: приватный гейт ядра пускает только редакторов и админов,
// а ученик — отдельная роль. Порядок объявления повторяет вложенность адресов.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/student.php';
require_once __DIR__ . '/ui/exercise.php';
require_once __DIR__ . '/cabinet.php';
require_once __DIR__ . '/admin.php';

addRoute('GET', '/learn', 'learnCoursePage', ['app' => 'learn']);
addRoute('GET', '/learn/module/{id}', 'learnModulePage', ['app' => 'learn']);
addRoute('GET', '/lesson/{slug}', 'learnLessonPage', ['app' => 'learn']);
addRoute('POST', '/lesson/{slug}', 'learnLessonAction', ['app' => 'learn']);
addRoute('GET', '/learn/register', 'learnRegisterPage', ['app' => 'learn']);
addRoute('POST', '/learn/register', 'learnRegisterAction', ['app' => 'learn']);
addRoute('GET', '/onboarding', 'learnOnboardingPage', ['app' => 'learn']);
addRoute('POST', '/onboarding', 'learnOnboardingAction', ['app' => 'learn']);
addRoute('GET', '/placement-test', 'learnPlacementPage', ['app' => 'learn']);
addRoute('POST', '/placement-test', 'learnPlacementAction', ['app' => 'learn']);
addRoute('GET', '/dashboard', 'learnDashboardPage', ['app' => 'learn']);
addRoute('GET', '/progress', 'learnProgressPage', ['app' => 'learn']);

// Контент-админка раздела «Курсы»: права проверяет learnUser() в каждом контроллере,
// а затем roleGrantsManage() — только редактор и администратор. Пути — строковые литералы,
// один POST-обработчик с op на экране курсов и уроков.
addRoute('GET', '/learn/admin', 'learnAdminSummary', ['app' => 'learn']);
addRoute('GET', '/learn/admin/courses', 'learnAdminCourses', ['app' => 'learn']);
addRoute('POST', '/learn/admin/courses', 'learnAdminCoursesAction', ['app' => 'learn']);
addRoute('GET', '/learn/admin/lessons', 'learnAdminLessons', ['app' => 'learn']);
addRoute('POST', '/learn/admin/lessons', 'learnAdminLessonsAction', ['app' => 'learn']);
addRoute('GET', '/learn/admin/lessons/new', 'learnAdminLessonNew', ['app' => 'learn']);
addRoute('POST', '/learn/admin/lessons/new', 'learnAdminLessonSaveAction', ['app' => 'learn']);
addRoute('GET', '/learn/admin/lessons/{id}', 'learnAdminLessonEdit', ['app' => 'learn']);
addRoute('POST', '/learn/admin/lessons/{id}', 'learnAdminLessonSaveAction', ['app' => 'learn']);
