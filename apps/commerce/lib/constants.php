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
const COMMERCE_ORDER_STATUSES = ['NEW', 'PAID', 'SHIPPED', 'DONE', 'CANCELLED'];

/** @var array<string, string> */
const COMMERCE_ORDER_STATUS_LABELS = [
    'NEW' => 'Новый',
    'PAID' => 'Оплачен',
    'SHIPPED' => 'Отправлен',
    'DONE' => 'Доставлен',
    'CANCELLED' => 'Отменён',
];

// Живой цикл заказа (M3): откуда — куда можно. Отмена только до отправки: после неё
// товар уже уехал к покупателю. Оплаченный и доставленный — конечные точки распечатанных
// печатей, из них больше нет перехода.
/** @var array<string, list<string>> */
const COMMERCE_ORDER_FLOW = [
    'NEW' => ['PAID', 'CANCELLED'],
    'PAID' => ['SHIPPED', 'CANCELLED'],
    'SHIPPED' => ['DONE'],
    'DONE' => [],
    'CANCELLED' => [],
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

// Хост заказов по номеру и e-mail: счётчик проваленных попыток и метка заморозки
// «до какого табло» в сессии. Строгий порог — прямо в константе: сессия клиента
// чистится сама по выходу, и не должен превратиться в вечную баня штрих.
const COMMERCE_SESSION_LOOKUP_FAILS = 'commerce_lookup_fails';
const COMMERCE_SESSION_LOOKUP_UNTIL = 'commerce_lookup_until';
const COMMERCE_LOOKUP_MAX_FAILS = 5;
const COMMERCE_LOOKUP_COOLDOWN_MIN = 10;

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

// Скидки (M4): купоны вводит покупатель, промо-правила работают сами. Оба вида несут
// одинаковый размер скидки — процент или фиксированную сумму в валюте заказа.
/** @var list<string> */
const COMMERCE_DISCOUNT_KINDS = ['PERCENT', 'FIXED'];

/** @var array<string, string> */
const COMMERCE_DISCOUNT_KIND_LABELS = [
    'PERCENT' => 'Процент',
    'FIXED' => 'Фиксированно',
];

const COMMERCE_COUPON_CODE_MAX = 40;

// Купон идёт из корзины к оформлению с сессией: код набирается на экране корзины,
// валидность и визиты купона экран показывает свежими данными базы, а не обещаниями.
const COMMERCE_SESSION_COUPON = 'commerce_coupon';
