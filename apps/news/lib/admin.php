<?php

declare(strict_types=1);

// Правила экранов админки раздела: полоса вкладок, набор кнопок строки списка и адрес возврата
// после действия. База не нужна ни одному из них, поэтому правила живут отдельно от контроллеров
// и проверяются unit-набором.

// Сводка раздела — её адрес читают манифест (adminNav), вкладки и ссылка «на главный экран».
const NEWS_ADMIN_PATH = '/news/admin';

// Адреса экранов справочников. Их читают вкладки, действия, шаблоны и HTTP-тесты — один раз
// здесь, а не шестью строками в четырёх файлах. Адреса объявлены до вкладок, потому что вкладки
// на них ссылаются.
const NEWS_ADMIN_CATEGORIES_PATH = '/news/admin/categories';
const NEWS_ADMIN_TAGS_PATH = '/news/admin/tags';

// Вкладки самого раздела. Ядро показывает свои вкладки плюс одну — «Новости» из манифеста;
// эта полоса живёт внутри экрана и ведёт по экранам раздела. NEWS_ADMIN_ITEMS_PATH объявлена в
// lib/items.php, который boot.php подключает раньше этого файла, поэтому к моменту вычисления
// вкладок она уже определена. Порядок вкладок — порядок экрана: справочники стоят после материалов.
/** @var list<array{href: string, label: string}> */
const NEWS_ADMIN_TABS = [
    ['href' => NEWS_ADMIN_PATH, 'label' => 'Сводка'],
    ['href' => NEWS_ADMIN_ITEMS_PATH, 'label' => 'Материалы'],
    ['href' => NEWS_ADMIN_CATEGORIES_PATH, 'label' => 'Рубрики'],
    ['href' => NEWS_ADMIN_TAGS_PATH, 'label' => 'Теги'],
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

// ── Справочники ──────────────────────────────────────────

// Адреса экранов объявлены в начале файла, рядом с NEWS_ADMIN_PATH: их вычисляют вкладки.
// Здесь — договор действий двух справочников: что вообще возможно и как это называется.
// Константы стоят выше функций вместе с остальными: их читают шаблоны, действия и тесты.
// Кнопки строки (`newsCategoryRowActions()` и `newsTagRowActions()`) остались внизу.

// Действия рубрики ровно в том порядке, в котором их разбирает match в actions/catalog.php.
// Страж задачи 20 сверяет этот список с обработчиком: новое действие без ветки match не пройдёт.
/** @var list<string> */
const NEWS_CATEGORY_OPS = ['create', 'rename', 'describe', 'show', 'hide', 'up', 'down', 'delete'];

/** @var array<string, string> */
const NEWS_CATEGORY_OP_LABEL = [
    'create' => 'Добавить рубрику',
    'rename' => 'Переименовать',
    'describe' => 'Сохранить описание',
    'show' => 'Показать',
    'hide' => 'Скрыть',
    'up' => 'Вверх',
    'down' => 'Вниз',
    'delete' => 'Удалить',
];

/** @var list<string> */
const NEWS_TAG_OPS = ['rename', 'delete'];

/** @var array<string, string> */
const NEWS_TAG_OP_LABEL = [
    'rename' => 'Переименовать',
    'delete' => 'Удалить',
];

// Потолок подписи отказа: текст печатается на экране и ложится в журнал, поэтому это короткое имя
// действия, а не содержимое запроса.
const NEWS_UNKNOWN_ACTION_MAX = 80;

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

/**
 * Оповещение о перекосе закрепления — одна функция на два экрана.
 *
 * Сводка и список печатают его буквально одним текстом: пока он был переписан в оба шаблона,
 * правка формулировки расходилась, а второй экземпляр не проверялся ни одним тестом. Здесь
 * правило (`tooManyPinned()`) и разметка живут вместе, а шаблоны только вставляют значение —
 * пустая строка значит «предупреждать не о чем».
 */
function newsPinnedSkewAlert(int $pinned): string
{
    if (!tooManyPinned($pinned)) {
        return '';
    }

    return '<p class="rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert">'
        . sprintf(
            'Закреплено %1$d %2$s — это больше %3$d: закреплённые идут первыми в ленте, в рубриках '
                . 'и в подборке тегов и вытесняют обычную ленту с первой страницы.',
            $pinned,
            plural($pinned, ['материал', 'материала', 'материалов']),
            PINNED_WARN_LIMIT
        ) . '</p>';
}

// ── Справочники: кнопки строк ────────────────────────────

// Константы действий двух справочников объявлены в начале файла рядом с остальными: их читают
// другие файлы. Здесь остаются сами кнопки строки.

/**
 * Кнопки строки справочника рубрик.
 *
 * `show` и `hide` — два намерения одного переключения: экран печатает ту подпись, что
 * соответствует состоянию строки, а toggleCategoryVisibility() делает ровно то, что обещает
 * следующая за кнопкой плашка.
 *
 * @param array{id: string, slug: string, title: string, description: ?string, order: int, visible: bool, items: array{total: int, visible: int}} $row
 *
 * @return list<array{op: string, label: string}>
 */
function newsCategoryRowActions(array $row): array
{
    $toggle = $row['visible'] === true ? 'hide' : 'show';

    $ops = [$toggle, 'up', 'down', 'delete'];
    $out = [];

    foreach ($ops as $op) {
        $out[] = ['op' => $op, 'label' => NEWS_CATEGORY_OP_LABEL[$op]];
    }

    return $out;
}

/**
 * Кнопки строки тегов. Переименование — поле с формой, а не кнопка строки: его подпись читает
 * шаблон прямо из NEWS_TAG_OP_LABEL. Удаление, наоборот, кнопка: шаблон собирает её тем же
 * циклом, что и кнопки строки рубрики.
 *
 * @param array{id: string, slug: string, label: string, items: int} $row
 *
 * @return list<array{op: string, label: string}>
 */
function newsTagRowActions(array $row): array
{
    return [['op' => 'delete', 'label' => NEWS_TAG_OP_LABEL['delete']]];
}

/** @return array{ok: false, notice: string, error: string} */
function catalogFailure(string $error): array
{
    return ['ok' => false, 'notice' => '', 'error' => $error];
}
