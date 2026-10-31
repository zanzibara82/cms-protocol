<?php

declare(strict_types=1);

// Слой меню шапки и подвала: пункты живут в tbl_menu_item, правятся на /admin/menu, тема
// рисует их помощниками menuHeaderLinks()/menuFooterSections() (решение 1 дизайн-документа).
// Читатели и операции записи растут в задачах 2–4; в этом файле — пределы, валидатор адреса,
// нормализация строки формы, группировка колонок и сбор ссылок разделов из манифестов.

// Два слота, как у портала; MENU_SLOT_NAMES — названия карт на экране админки.
const MENU_SLOTS = ['HEADER', 'FOOTER'];
const MENU_SLOT_NAMES = ['HEADER' => 'Шапка', 'FOOTER' => 'Подвал'];

// Подпись колонки подвала, когда человек оставил поле пустым (решение 2: колонка обязана быть).
const MENU_SECTION_FALLBACK = 'Ссылки';

// Пределы полей читаются через fieldLimit() (решение 9): label и section — подписи,
// href — адрес пункта, sort — порядок в слоте (у портала order…999).
const MENU_FIELDS = [
    'label' => ['min' => 1, 'max' => 60],
    'section' => ['max' => 60],
    'href' => ['max' => 300],
    'sort' => 999,
];

// Чтения меню никогда не выйдут за LIMIT (страж производительности требует его на каждой выборке).
const MENU_READ_LIMIT = 500;

// Пункт ведёт на якорь этой же страницы, на раздел сайта, на внешнюю страницу, на почту или на
// телефон. Формы — валидатор портала (src/lib/menu.ts): другие схемы отвергаются, иначе пункт
// вывел бы посетителя на скрипт javascript:… или печать data:…. Телефон — только цифры и
// привычные разделители: скобки, пробелы, дефис и точка. Флаг /u не нужен: якоря и слаги
// секций транслитя slugify(), всё в адресах — ASCII.
const MENU_HREF_RE = '~^(?:#[\w-]+|/(?!/)[\w/-]*|https?://\S+|mailto:[^@\s]+@[\w.-]+\.\w+|tel:\+?[\d][\d\s().-]{4,19})$~';

// Файл в папке сайта: в имени есть точка (/files/lesson.pdf), а раздел сайта в MENU_HREF_RE
// точек не допускает. Только один ведущий слэш: без схемы, без пробелов и без //host —
// иначе ссылка вывела бы посетителя на чужой домен.
const MENU_LOCAL_FILE_RE = '~^/(?:[\w-]+/)*[\w-]+(?:\.[\w-]+)+$~';

/**
 * Адрес, который можно отдать посетителю: пункт меню либо файл в папке сайта. Разница с
 * порталом одна: путь не может начинаться с двух слэшей — //host/page это ссылка на чужой
 * домен, а JS-регулярка портала пропускала её (вердикт записан в ledger).
 */
function menuHrefValid(string $href): bool
{
    return preg_match(MENU_HREF_RE, $href) === 1 || preg_match(MENU_LOCAL_FILE_RE, $href) === 1;
}

/**
 * Флаг из формы ('0'/'1', булево из кода, ничего) в колонку таблицы. Пустая и любая непонятная
 * строка читаются как «1»: чекбокс unchecked — ровно строка '0', всё прочее — включено.
 */
function menuFlagOf(mixed $raw): int
{
    if (is_bool($raw)) {
        return $raw ? 1 : 0;
    }

    return (string) $raw === '0' ? 0 : 1;
}

/**
 * Строка формы пункта в колонки таблицы: края обрезаются, длины срезаются до пределов
 * справочника, пустые подпись и адрес — человеческий отказ. Раздел принадлежит подвалу:
 * в строке шапки он уходит молча (решение 2: слот — главный), кривой слот — отказ: пункт
 * записать некуда.
 *
 * @param array<string, mixed> $raw поля формы: slot, section, label, href, blank
 * @return array{slot: string, section: string, label: string, href: string, blank: int, error: string}
 */
function menuItemValuesOf(array $raw): array
{
    $slot = (string) ($raw['slot'] ?? '');
    if (!in_array($slot, MENU_SLOTS, true)) {
        return ['slot' => '', 'section' => '', 'label' => '', 'href' => '', 'blank' => 0,
            'error' => 'Слот пункта потерялся — обновите страницу и повторите.'];
    }

    $label = trim((string) ($raw['label'] ?? ''));
    if ($label === '') {
        return ['slot' => '', 'section' => '', 'label' => '', 'href' => '', 'blank' => 0,
            'error' => 'Подпись не написана — впишите, как пункт называется на сайте.'];
    }

    $href = trim((string) ($raw['href'] ?? ''));
    if ($href === '') {
        return ['slot' => '', 'section' => '', 'label' => '', 'href' => '', 'blank' => 0,
            'error' => 'Адрес не написан — впишите, куда пункт ведёт: якорь (#уровни), раздел сайта (/news), внешняя http(s)://… ссылка, почта mailto:…, телефон tel:… или файл сайта (/files/lesson.pdf).'];
    }

    // Кривой адрес отлавливается до записи: пункт попадает на публичную страницу.
    if (!menuHrefValid($href)) {
        return ['slot' => '', 'section' => '', 'label' => '', 'href' => '', 'blank' => 0,
            'error' => 'Адрес не разобрать — напишите якорь (#уровни), раздел сайта (/news), внешнюю http(s)://… ссылку, почту mailto:…, телефон tel:… или файл сайта (/files/lesson.pdf).'];
    }

    // Раздел принадлежит подвалу: в строке шапки он уходит молча, у подвала — подпись колонки
    // (может остаться пустой — читатель заполнит её запасной «Ссылки» при группировке).
    $section = $slot === 'HEADER' ? '' : mb_substr(trim((string) ($raw['section'] ?? '')), 0, fieldLimit(MENU_FIELDS, 'section', 'max'));

    return [
        'slot' => $slot,
        'section' => $section,
        'label' => mb_substr($label, 0, fieldLimit(MENU_FIELDS, 'label', 'max')),
        'href' => mb_substr($href, 0, fieldLimit(MENU_FIELDS, 'href', 'max')),
        'blank' => menuFlagOf($raw['blank'] ?? '0'),
        'error' => '',
    ];
}

/**
 * Колонки подвала из уже отсортированных строк: колонка занимает место своего первого пункта,
 * одноимённые подписи складываются в одну колонку (порталово groupBySection), пустая подпись —
 * запасная «Ссылки».
 *
 * @param list<array{section: string, label: string, href: string, blank: bool}> $rows
 * @return list<array{title: string, links: list<array{label: string, href: string, blank: bool}>}>
 */
function menuFooterGroups(array $rows): array
{
    $titles = [];
    $buckets = [];
    foreach ($rows as $row) {
        $title = trim((string) $row['section']);
        if ($title === '') {
            $title = MENU_SECTION_FALLBACK;
        }

        $link = ['label' => (string) $row['label'], 'href' => (string) $row['href'], 'blank' => (bool) $row['blank']];
        if (isset($buckets[$title])) {
            $buckets[$title][] = $link;
        } else {
            $buckets[$title] = [$link];
            $titles[] = $title;
        }
    }

    $groups = [];
    foreach ($titles as $title) {
        $groups[] = ['title' => $title, 'links' => $buckets[$title]];
    }

    return $groups;
}

/**
 * Навигация одного приложения из манифеста: publicNav — список пар «адрес — подпись».
 * Выключенное приложение ссылок не отдаёт (пункт вёл бы на 404); битая пара пропускается
 * молча — манифест правит программист, падать на нём сайт не должен. «В новом окне»
 * разделам сайта не нужно (решение 5).
 *
 * @param array<string, mixed> $manifest манифест одного приложения
 * @return list<array{href: string, label: string, blank: bool}>
 */
function menuPublicLinksOf(array $manifest, bool $active): array
{
    $nav = $manifest['publicNav'] ?? null;
    if (!$active || !is_array($nav)) {
        return [];
    }

    $max = fieldLimit(MENU_FIELDS, 'label', 'max');
    $links = [];
    foreach ($nav as $entry) {
        if (!is_array($entry) || count($entry) !== 2) {
            continue;
        }

        $href = is_string($entry[0]) ? trim($entry[0]) : '';
        $label = is_string($entry[1]) ? mb_substr(trim($entry[1]), 0, $max) : '';
        if ($href === '' || $label === '' || !menuHrefValid($href)) {
            continue;
        }

        $links[] = ['href' => $href, 'label' => $label, 'blank' => false];
    }

    return $links;
}
