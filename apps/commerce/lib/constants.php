<?php

declare(strict_types=1);

// Договорные атрибуты раздела: статусы, границы адресов и сберегательные ключи сессии.
// LATиница в этих константах допустима: они ходят в базу и в логи, но в интерфейс их
// печатает только map-commerce-подписей (COMMERCE_STATUS_LABELS) русскими словами.

/** @var list<string> */
const COMMERCE_PRODUCT_STATUSES = ['DRAFT', 'PUBLISHED'];

/** @var array<string, string> */
const COMMERCE_PRODUCT_STATUS_LABELS = [
    'DRAFT' => 'Черновик',
    'PUBLISHED' => 'Публикуется',
];

/** @var list<string> */
const COMMERCE_ORDER_STATUSES = ['NEW', 'SHIPPED', 'CANCELLED'];

/** @var array<string, string> */
const COMMERCE_ORDER_STATUS_LABELS = [
    'NEW' => 'Новый',
    'SHIPPED' => 'Выполнен',
    'CANCELLED' => 'Отменён',
];

// Адрес «c» служит странице рубрики, «cart» и «order» — служебные экраны витрины,
// а «admin» занят при Пути администратора. Товар с таким адресом не создаётся:
// приоритет у статических маршрутов, и молчаливый выкуп сегмента человеком закрыт.
/** @var list<string> */
const COMMERCE_RESERVED_SLUGS = ['c', 'cart', 'order', 'admin'];

const COMMERCE_SLUG_MIN = 2;
const COMMERCE_SLUG_MAX = 60;

// Копия слота сессии с собственным префиксом: раздел не тянет ключи чужих разделов.
/** @var list<string> */
const COMMERCE_SESSION_CART = 'commerce_cart';

const COMMERCE_CART_MAX_QTY = 999;
const COMMERCE_CART_MAX_LINES = 50;

const COMMERCE_TITLE_MAX = 160;
const COMMERCE_LEAD_MAX = 300;
const COMMERCE_SKU_MAX = 64;
const COMMERCE_PRICE_MAX = 999999.99;
const COMMERCE_QTY_MAX = 999;
const COMMERCE_NAME_MAX = 160;
const COMMERCE_EMAIL_MAX = 190;
const COMMERCE_PHONE_MAX = 32;
const COMMERCE_ADDRESS_MAX = 300;
const COMMERCE_ORDER_COMMENT_MAX = 500;
const COMMERCE_CURRENCY_DEFAULT = 'RUB';

/** @var list<string> */
const COMMERCE_CURRENCIES = ['RUB', 'USD', 'EUR'];
