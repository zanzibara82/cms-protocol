<?php

declare(strict_types=1);

// Словарь раздела «Доска объявлений». Строковый префикс таблиц — свой, магазин не при делах.
// Статусы — верхним регистром, как у новостей и магазина: колонка VARCHAR(16), список статусов
// живёт кодом (§ ядра). C4 добавил проверку: отправленное продавцом ждёт решения редактора.
const CLASSIFIEDS_STATUS = [
    'DRAFT' => 'DRAFT',
    'PENDING_REVIEW' => 'PENDING_REVIEW',
    'PUBLISHED' => 'PUBLISHED',
    'REJECTED' => 'REJECTED',
];
const CLASSIFIEDS_STATUS_LABELS = [
    'DRAFT' => 'Черновик',
    'PENDING_REVIEW' => 'На проверке',
    'PUBLISHED' => 'Опубликовано',
    'REJECTED' => 'Отклонено',
];
const CLASSIFIEDS_CURRENCY = 'RUB';
const CLASSIFIEDS_CURRENCY_LABEL = '₽';

// Оговорки адресов: доска отдаёт публичные пути /classifieds, /classifieds/c/{slug} и
// /classifieds/{slug}. Служебные сегменты заняты маршрутами, поэтому объявления и категории
// не могут их увести (правило новостного раздела, проверяется стражем маршрутов).
const CLASSIFIEDS_RESERVED_SLUGS = ['c', 'search', 'admin', 'cabinet', 'register', 'seller'];

// Цены на доске пишут люди, а не бухгалтерия: знак и порядок заведут при ошибке,
// поэтому верхнего предела DECIMAL(12,2) хватает с запасом.
const CLASSIFIEDS_TITLE_MAX = 140;
const CLASSIFIEDS_BODY_MAX = 10000;
const CLASSIFIEDS_LOCATION_MAX = 120;

// Пределы адресов. Минимум — три символа, как у новостей: адрес объявления печатается
// в конкретных ссылках, двухбуквенные «ab» приносят больше шума, чем смысла.
const CLASSIFIEDS_SLUG_MIN = 3;
const CLASSIFIEDS_SLUG_MAX = 60;

// Кабинет: минимальные пределы формы регистрации. Окно и лимит попыток по IP — своё,
// чтобы незваные гости не завели сотни продавцов одной рассылкой: попытки регистрации пишутся
// в tbl_classifieds_registration_attempt, тем же паровозом, что и журнал неудачных входов ядра.
const CLASSIFIEDS_REGISTER_LIMIT_PER_IP = 10;
const CLASSIFIEDS_REGISTER_WINDOW = 60;
const CLASSIFIEDS_EMAIL_MAX = 190;
const CLASSIFIEDS_PASSWORD_MIN = 8;
const CLASSIFIEDS_NAME_MAX = 120;

// Галерея C2: к главному снимку добавляются дополнительные. Предел в шесть кадров — один
// экран просмотра; больше на доске объявлений всё равно не смотрят.
const CLASSIFIEDS_GALLERY_MAX = 5;

// Характеристики категорий. Набор типов на этом этапе — те, что дают форму и фильтр без
// отдельного хранилища вариантов: строка, число, список и «да/нет». Остальные типы ТЗ
// (дата, диапазон, изображение, гео) приедут со своими фильтрами.
const CLASSIFIEDS_ATTRIBUTE_KINDS = [
    'text' => 'Строка',
    'number' => 'Число',
    'select' => 'Список',
    'bool' => 'Да/нет',
];
const CLASSIFIEDS_ATTRIBUTE_KEY_MAX = 40;
const CLASSIFIEDS_ATTRIBUTE_TITLE_MAX = 120;
const CLASSIFIEDS_ATTRIBUTE_UNIT_MAX = 20;
const CLASSIFIEDS_ATTRIBUTE_VALUE_MAX = 255;
const CLASSIFIEDS_ATTRIBUTE_OPTION_MAX = 80;
const CLASSIFIEDS_ATTRIBUTE_OPTIONS_MAX = 50;

// Витрина C2: поиск по словам, сортировки и вид каталога. Релевантность имеет смысл только
// вместе с запросом, поэтому без q обработчик возвращает сортировку по дате.
const CLASSIFIEDS_SEARCH_MAX = 120;
const CLASSIFIEDS_SORT_DEFAULT = 'date';
const CLASSIFIEDS_SORTS = [
    'date' => 'Сначала новые',
    'price_asc' => 'Цена: по возрастанию',
    'price_desc' => 'Цена: по убыванию',
    'relevance' => 'По релевантности',
];
const CLASSIFIEDS_VIEWS = ['grid', 'list'];
const CLASSIFIEDS_VIEW_DEFAULT = 'grid';

// C3: срок жизни публикации. Продление сдвигает дату от текущего момента, а не от старой,
// чтобы «продлить за неделю до конца» не теряло эту неделю.
const CLASSIFIEDS_LISTING_DAYS = 30;

const CLASSIFIEDS_PHONE_MAX = 40;
const CLASSIFIEDS_CITY_MAX = 120;
const CLASSIFIEDS_ABOUT_MAX = 2000;
const CLASSIFIEDS_VIEW_LABELS = ['grid' => 'Сетка', 'list' => 'Список'];

// C4: проверка и обратная связь. Причина отказа ограничена той же длиной, что колонка;
// жалоба и письмо — обычным текстом, но с пределом, чтобы форма не заливала базу.
const CLASSIFIEDS_MODERATION_NOTE_MAX = 500;
const CLASSIFIEDS_REPORT_COMMENT_MAX = 1000;
const CLASSIFIEDS_MESSAGE_MAX = 2000;
const CLASSIFIEDS_MESSAGE_PREVIEW = 120;

const CLASSIFIEDS_REPORT_REASONS = [
    'spam' => 'Спам или реклама',
    'fraud' => 'Мошенничество',
    'forbidden' => 'Запрещённый товар',
    'wrong_category' => 'Не та категория',
    'other' => 'Другое',
];
const CLASSIFIEDS_REPORT_STATUS = ['NEW' => 'NEW', 'RESOLVED' => 'RESOLVED'];

const CLASSIFIEDS_NOTIFICATION_KINDS = [
    'message' => 'Сообщение',
    'moderation' => 'Проверка объявления',
    'report' => 'Ответ на жалобу',
];
