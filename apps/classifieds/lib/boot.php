<?php

declare(strict_types=1);

// Подключение рабочего слоя раздела. Правила нужны контроллерам при каждом запросе,
// а в боевом процессе их не подключает никто, поэтому require_once здесь — единственная
// точка, где константы встречаются с помощниками.
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/attributes.php';
require_once __DIR__ . '/gallery.php';
require_once __DIR__ . '/listings.php';
require_once __DIR__ . '/feed.php';
require_once __DIR__ . '/sellers.php';
require_once __DIR__ . '/favorites.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/moderation.php';
require_once __DIR__ . '/promotions.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/webhooks.php';

// Доска покупает услуги заказами магазина: после оплаты (PAID) или отмены заказа
// магазин вызывает хук, и строка продвижения применяется объявлению или закрывается.
addAction('commerce_order_status', 'classifiedsPromotionOrderStatus');
