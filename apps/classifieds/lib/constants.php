<?php

declare(strict_types=1);

// Словарь раздела «Доска объявлений». Строковый префикс таблиц — свой, магазин не при делах.
// Статусы — верхним регистром, как у новостей и магазина: колонка VARCHAR(16), список статусов
// живёт кодом (§ ядра), в C1 их два; модерации и завершения срока появятся со своим этапом.
const CLASSIFIEDS_STATUS = ['DRAFT' => 'DRAFT', 'PUBLISHED' => 'PUBLISHED'];
const CLASSIFIEDS_CURRENCY = 'RUB';
const CLASSIFIEDS_CURRENCY_LABEL = '₽';

// Оговорки адресов: доска отдаёт публичные пути /classifieds, /classifieds/c/{slug} и
// /classifieds/{slug}. Служебные сегменты заняты маршрутами, поэтому объявления и категории
// не могут их увести (правило новостного раздела, проверяется стражем маршрутов).
const CLASSIFIEDS_RESERVED_SLUGS = ['c', 'admin', 'cabinet', 'register'];

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
