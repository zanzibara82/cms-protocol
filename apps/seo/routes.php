<?php

declare(strict_types=1);

// Маршруты раздела «SEO и статистика». Правила и обработчики нужны на каждом запросе, а в
// боевом процессе их не подключает никто: require_once здесь — единственная точка, где
// база раздела встречается с маршрутами.
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/admin-stats.php';
require_once __DIR__ . '/admin-monitor.php';
require_once __DIR__ . '/admin-audit.php';
require_once __DIR__ . '/admin-schema.php';
require_once __DIR__ . '/admin-search.php';
require_once __DIR__ . '/admin-conversions.php';
require_once __DIR__ . '/admin-content.php';
require_once __DIR__ . '/admin-funnels.php';
require_once __DIR__ . '/admin-export.php';
require_once __DIR__ . '/admin-api.php';

// Экран метаданных: справочник адресов и форма. Адрес /{id} открывает правку выбранной
// записи; POST на тот же адрес сохраняет её или удаляет.
addRoute('GET', '/seo/admin', 'seoAdminPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/{id}', 'seoAdminPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin', 'seoAdminAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/{id}', 'seoAdminAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Экраны статистики: обзор, страницы, источники и настройки сбора. Адреса статические, и ядро
// перебирает их раньше параметрических (core/router.php:75) — иначе «статистика» была бы
// адресом записи метаданных.
addRoute('GET', '/seo/admin/stats', 'seoStatsOverviewPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/pages', 'seoStatsPagesPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/sources', 'seoStatsSourcesPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/settings', 'seoStatsSettingsPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Действия настроек: сохранить сбор и очистить сырьё старше срока хранения. Оба действия
// идут на один адрес и различаются полем op — так их видит и человек в адресной строке.
addRoute('POST', '/seo/admin/settings', 'seoStatsSettingsAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Мониторинг и аудит (S3). Статические адреса перебираются ядром раньше параметрических
// (core/router.php:75), а «/seo/admin/schema/{id}» не попадает в «/seo/admin/{id}»:
// сегмент параметра слеша не содержит, а в адресе их два.
addRoute('GET', '/seo/admin/errors', 'seoMonitorPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/audit', 'seoAuditPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/schema', 'seoSchemaPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/schema/{id}', 'seoSchemaPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Действия мониторинга, аудита и разметки. Все формы идут на свои адреса и различаются
// полем op — так их видит и человек в адресной строке.
addRoute('POST', '/seo/admin/errors', 'seoMonitorAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/audit', 'seoAuditAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/schema', 'seoSchemaAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/schema/{id}', 'seoSchemaAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Поисковая аналитика (S4). Экран данных доступен любому, кто вошёл в админку, а экран
// подключения — только администратору: там лежит клиентский секрет (право «управлять»).
addRoute('GET', '/seo/admin/search', 'seoSearchPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/search', 'seoSearchAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/search/source', 'seoSearchSourcePage', ['app' => 'seo', 'private' => true, 'permission' => 'manage']);
addRoute('POST', '/seo/admin/search/source', 'seoSearchSourceAction', ['app' => 'seo', 'private' => true, 'permission' => 'manage']);

// Возврат из поисковика: сюда приходит код. Право — «вход»: без входа в админку и подпись
// состояния не помогут, а код утекать не должен никому.
addRoute('GET', '/seo/admin/search/callback', 'seoSearchCallback', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Конверсии, контентная аналитика и воронки (S5). Отчёты считаются системой: цифры в них —
// то, что зафиксировали посещения и цели, а не вручную набраный текст.
addRoute('GET', '/seo/admin/conversions', 'seoConversionsPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/conversions', 'seoConversionsAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/content', 'seoContentPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/funnels', 'seoFunnelsPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/seo/admin/funnels', 'seoFunnelsAction', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// Экспорт отчётов (S6). Экран живёт в навигации, файл скачивается отдельным маршрутом:
// неверные аргументы на скачивании возвращают человека на экран, а не отдают что попало.
addRoute('GET', '/seo/admin/export', 'seoExportPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/seo/admin/export/download', 'seoExportDownloadPage', ['app' => 'seo', 'private' => true, 'permission' => 'login']);

// API-ключи машинного чтения (S6). Управление закрыто редакторам: ключи дают доступ к
// отчётам, поэтому распоряжаются ими на уровне «управлять», как источником поисковика.
addRoute('GET', '/seo/admin/api', 'seoApiKeysPage', ['app' => 'seo', 'private' => true, 'permission' => 'manage']);
addRoute('POST', '/seo/admin/api', 'seoApiKeysAction', ['app' => 'seo', 'private' => true, 'permission' => 'manage']);

// Машинное API (S6). Ключ приходит в заголовке Authorization, сессия не участвует: машина не
// входит в админку. Адреса метки sessionless — без cookie ядра, ответы не кэшируются и не
// индексируются (заголовки ставит respond внутри обработчика). Разрез выбирается из белого
// списка SEO_API_V1_REPORTS: адрес вне списка отвечает 404 в JSON, а не перенаправлением,
// потому что у машины нет экрана входа, куда её можно было бы вернуть.
addRoute('GET', '/seo/api/v1/{report}', 'seoApiV1Page', ['app' => 'seo', 'sessionless' => true]);

// Приёмник событий счётчика. Адрес открыт мимо входа: его зовёт страница посетителя, у
// которого нет ни входа, ни сессии. Подпись раздела проверяется внутри обработчика. Метка
// sessionless убирает и cookie ядра: счётчику сессия не нужна, а режим «без cookies» из
// спецификации §7 должен быть правдой и на уровне ответа, а не только на уровне скрипта.
addRoute('POST', '/seo/collect', 'seoCollectorAccept', ['app' => 'seo', 'sessionless' => true]);
