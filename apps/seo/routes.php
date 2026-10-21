<?php

declare(strict_types=1);

// Маршруты раздела «SEO и статистика». Правила и обработчики нужны на каждом запросе, а в
// боевом процессе их не подключает никто: require_once здесь — единственная точка, где
// база раздела встречается с маршрутами.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/admin.php';

// Экран метаданных: справочник адресов и форма. Адрес /{id} открывает правку выбранной
// записи; POST на тот же адрес сохраняет её или удаляет.
addRoute('GET', '/seo/admin', 'seoAdminPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/{id}', 'seoAdminPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin', 'seoAdminAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/{id}', 'seoAdminAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
