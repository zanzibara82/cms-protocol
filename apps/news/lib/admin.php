<?php

declare(strict_types=1);

// Правила экранов админки раздела: полоса вкладок, набор кнопок строки списка и адрес возврата
// после действия. База не нужна ни одному из них, поэтому правила живут отдельно от контроллеров
// и проверяются unit-набором.

// Сводка раздела — её адрес читают манифест (adminNav), вкладки и ссылка «на главный экран».
const NEWS_ADMIN_PATH = '/news/admin';

// Вкладки самого раздела. Ядро показывает свои вкладки плюс одну — «Новости» из манифеста;
// эта полоса живёт внутри экрана и ведёт по экранам раздела.
/** @var list<array{href: string, label: string}> */
const NEWS_ADMIN_TABS = [
    ['href' => '/news/admin', 'label' => 'Сводка'],
    ['href' => '/news/admin/items', 'label' => 'Материалы'],
];

// Подписи кнопок строки списка. Это именно подписи: право на переход проверяет сервер
// (setItemStatus() и setItemPinned()), а не наличие кнопки на экране.
/** @var array<string, string> */
const NEWS_ROW_OP_LABEL = [
    'publish' => 'На печать',
    'unpublish' => 'Снять с сайта',
    'pin' => 'Закрепить',
    'unpin' => 'Открепить',
];

// Потолок адреса возврата: значение приходит из поля формы и уходит в заголовок Location.
const NEWS_ADMIN_BACK_MAX = 300;

/**
 * Кнопки одной строки списка.
 *
 * Поля `open` и `pinned` берутся из разобранной строки (adminItemRow()), а не пересчитываются
 * здесь: видимость зависит от момента «сейчас», и второй вызов isOpen() на другой момент
 * разошёлся бы с вкладкой, под которой человек эту строку видит.
 *
 * @param array<string, mixed> $item
 *
 * @return list<array{op: string, label: string}>
 */
function newsRowActions(array $item): array
{
    $status = (string) ($item['status'] ?? '');
    $ops = [];

    if ($status === NEWS_STATUS['DRAFT'] || $status === '') {
        $ops[] = 'publish';
    } elseif ($status === NEWS_STATUS['PUBLISHED']) {
        $ops[] = 'unpublish';
    }

    // Закрепление предлагают только тому, что читатель видит: черновику и не наступившему сроку
    // оно ничего не даёт, а сервер такой переход отклоняет.
    if (($item['open'] ?? false) === true) {
        $ops[] = (($item['pinned'] ?? false) === true) ? 'unpin' : 'pin';
    }

    $out = [];

    foreach ($ops as $op) {
        $out[] = ['op' => $op, 'label' => NEWS_ROW_OP_LABEL[$op]];
    }

    return $out;
}

/**
 * Полоса вкладок раздела. Экран правки (`/news/admin/items/{id}`) вкладки не имеет: он лежит
 * внутри «Материалов», и точное совпадение адреса оставляло бы человека без отмеченной вкладки.
 *
 * Классы вкладок совпадают с админскими вкладками ядра (admin/layout.php): зона нажатия
 * min-h-[44px] с inline-flex — глобальное требование проекта, а страж стилей требует, чтобы
 * каждый класс жил в собранном артефакте.
 */
function newsAdminNav(string $current): string
{
    $items = '';

    foreach (NEWS_ADMIN_TABS as $tab) {
        $href = (string) $tab['href'];
        $active = $href === $current;
        $class = $active
            ? 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream'
            : 'inline-flex min-h-[44px] items-center rounded-lg px-3 py-2 text-sm font-medium text-ink/70 hover:bg-ink/5';
        $aria = $active ? ' aria-current="page"' : '';

        $items .= '<li><a class="' . $class . '" href="' . e($href) . '"' . $aria . '>'
            . e((string) $tab['label']) . '</a></li>';
    }

    return '<nav aria-label="Экраны раздела «Новости»"><ul class="flex flex-wrap gap-2">' . $items . '</ul></nav>';
}

/**
 * Адрес возврата после действия кнопки.
 *
 * Поле `back` нужно, чтобы действие вернуло человека в тот же список с теми же вкладкой, рубрикой
 * и страницей. Но приходит оно из браузера, поэтому принимается только путь этого списка: чужой
 * адрес — это открытый редирект, а перенос в заголовке — разрыв ответа.
 */
function newsAdminBackHref(string $raw): string
{
    $value = mb_substr(trim($raw), 0, NEWS_ADMIN_BACK_MAX);

    if ($value === '' || str_contains($value, "\r") || str_contains($value, "\n")) {
        return NEWS_ADMIN_ITEMS_PATH;
    }

    if (!str_starts_with($value, NEWS_ADMIN_ITEMS_PATH)) {
        return NEWS_ADMIN_ITEMS_PATH;
    }

    // После пути списка допускаются только конец строки и «?» с параметрами: «/news/admin/items/new»
    // и «/news/admin/items2» — не список.
    $rest = substr($value, strlen(NEWS_ADMIN_ITEMS_PATH));

    return ($rest === '' || str_starts_with($rest, '?')) ? $value : NEWS_ADMIN_ITEMS_PATH;
}
