<?php

declare(strict_types=1);

// Маршруты раздела «SEO и статистика». Правила и обработчики нужны на каждом запросе, а в
// боевом процессе их не подключает никто: require_once здесь — единственная точка, где
// база раздела встречается с маршрутами.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/admin-stats.php';

// Экран метаданных: справочник адресов и форма. Адрес /{id} открывает правку выбранной
// записи; POST на тот же адрес сохраняет её или удаляет.
addRoute('GET', '/seo/admin', 'seoAdminPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/{id}', 'seoAdminPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin', 'seoAdminAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/{id}', 'seoAdminAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Экраны статистики: обзор, страницы, источники и настройки сбора. Адреса статические и
// объявлены раньше параметрического /seo/admin/{id} — иначе «статистика» была бы адресом
// записи метаданных.
addRoute('GET', '/seo/admin/stats', 'seoStatsOverviewPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/pages', 'seoStatsPagesPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/sources', 'seoStatsSourcesPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/settings', 'seoStatsSettingsPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Действия настроек: сохранить сбор и очистить сырьё старше срока хранения. Оба действия
// идут на один адрес и различаются полем op — так их видит и человек в адресной строке.
addRoute('POST', '/seo/admin/settings', 'seoStatsSettingsAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Приёмник событий счётчика. Адрес открыт мимо входа: его зовёт страница посетителя, у
// которого нет ни входа, ни сессии. Подпись раздела проверяется внутри обработчика. Метка
// sessionless убирает и cookie ядра: счётчику сессия не нужна, а режим «без cookies» из
// спецификации §7 должен быть правдой и на уровне ответа, а не только на уровне скрипта.
addRoute('POST', '/seo/collect', 'seoCollectorAccept', ['app' => 'seo', 'sessionless' => true]);
