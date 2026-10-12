### Задача 17: Экраны админки раздела — сводка и список материалов с кнопками строки

**Files:**
- Create: `apps/news/lib/admin.php`
- Create: `apps/news/admin.php` (контроллеры экранов), `apps/news/actions/items.php` (действие строки)
- Create: `apps/news/templates/admin/summary.php`, `apps/news/templates/admin/items.php`
- Modify: `apps/news/routes.php` (три адреса админки + подключение файлов раздела)
- Modify: `apps/news/lib/boot.php` (подключение `admin.php` сразу за `items.php`)
- Modify: `apps/news/lib/items.php` (`onSiteItemCount()` — счётчик «на сайте» по правилу ленты)
- Modify: `core/apps.php` (`registerAppRoutes()` — снимок маршрутов)
- Modify: `core/response.php` (`queryAppend()`), `core/routes.php` (`finishAppAction()` — склейка параметров)
- Modify: `core/notice.php` (помощники отказа), `core/templates/admin/apps.php` (плашка на общий шаблон)
- Create: `core/templates/admin/alert.php` (плашка отказа — один разметочный файл для ядра и раздела)
- Modify: `tests/guard/RouteHandlersTest.php` (один тест)
- Create: `tests/guard/FailureChannelTest.php` (страж канала отказа)
- Test: `tests/unit/NewsAdminRulesTest.php`, `tests/unit/QueryAppendTest.php`, `tests/http/NewsAdminTest.php`

**Порт:** `english-portal/src/app/news/admin/page.tsx` (сводка), `admin/items/page.tsx` (список с
вкладками, фильтром рубрик, поиском и пагинацией), `components/news-row-actions.tsx` (кнопки строки)
и `server/items.ts` (функция `newsRowAction`). Источник истины — они; расхождения названы ниже.

**Потребляет:**
- `core/apps.php`: `registerAppRoutes()`, `appManifests()`; `core/router.php`: `addRoute()`,
  `routeTable()`, `routesReset()`, `dispatch()`
- `core/response.php`: `redirect()`, `redirectWithNotice()`, `forbidden()`; `core/csrf.php`: `csrfCheck()`,
  `csrfField()`; `core/notice.php`: `noticeOfRequest()`
- `core/auth.php`: `getEditorAccess()`, `currentUser()`; `core/request.php`: `postString()`, `queryString()`,
  `requestPath()` (`queryString()` и `postString()` живут в `core/request.php`, а не в `core/form.php`)
- `core/template.php`: `render()`; `core/escape.php`: `e()`; `core/text.php`: `plural()`
- `core/date.php`: `nowUtc()`; `admin/layout.php`: `adminShell()`, `adminNotice()`
- `core/routes.php` (задача 8): `finishAppAction(array $result, string $to, string $notice): void`
- `constants.php`: `NEWS_STATUS`, `NEWS_STATUS_LABEL`, `NEWS_FILTER_LABEL`, `NEWS_FILTERS`,
  `ADMIN_NEWS_PER_PAGE`, `PINNED_WARN_LIMIT`, `SEARCH_MAX_LENGTH`, `NEWS_NOTICE_TEXT`
- `visibility.php`: `tooManyPinned(int $count): bool`
- `catalog.php`: `categoryOptions(): list<array{id: string, title: string, visible: bool}>`
- `items.php`: `NEWS_ADMIN_ITEMS_PATH`, `NEWS_ID_MAX`, `parseAdminFilters()`, `adminItemsHref()`,
  `adminItemPage()`, `adminItemCounts()`, `pinnedVisibleCount()`, `setItemStatus()`, `setItemPinned()`,
  `itemFailure()`
- `feed.php`: `pageWindow()`, `newsHref()`

**Производит** (это читают задача 18 — форма материала, задача 19 — справочники, и стражи задачи 20):

```
const NEWS_ADMIN_PATH = '/news/admin';
const NEWS_ADMIN_TABS = [['href' => '/news/admin', 'label' => 'Сводка'], ['href' => '/news/admin/items', 'label' => 'Материалы']];
const NEWS_ROW_OP_LABEL = ['publish' => 'На печать', 'unpublish' => 'Снять с сайта', 'pin' => 'Закрепить', 'unpin' => 'Открепить'];

newsRowActions(array $item): list<array{op: string, label: string}>
newsAdminNav(string $current): string
newsAdminBackHref(string $raw): string
newsPinnedSkewAlert(int $pinned): string
newsAdminSummary(array $params = []): void
newsAdminItems(array $params = []): void
newsItemsAction(array $params = []): void
appRoutesSnapshot(): array  // core/apps.php: снимок маршрутов разделов
```

`NEWS_ADMIN_TABS` в задаче 19 получает ещё две вкладки — «Рубрики» и «Теги». Их здесь нет намеренно:
вкладка на несуществующий адрес была бы битой ссылкой ровно один коммит.

**Три правила этого файла:**

1. **Список материалов — единственный экран раздела, где выборка без `visibleWhere()` уже была сделана
   до нас.** Задача 15 вынесла её в `adminItemPage()`, здесь только вызывают её. Никакого нового
   `SELECT … FROM tbl_news_item` в контроллерах и шаблонах этой задачи нет — иначе страж видимости
   (задача 20) потребовал бы вносить файл в белый список.
2. **Кнопка решает действие, а право на переход проверяет сервер.** Набор кнопок — это подписи
   (`NEWS_ROW_OP_LABEL`), а не разрешение: `setItemStatus()` и `setItemPinned()` отклоняют то же самое,
   что экран не показывает, и текстом отказа отвечают в список.
3. **Адрес возврата приходит из браузера, поэтому он обезврежен.** `back` принимает только путь
   этого же списка; всё остальное — чистый адрес списка. Без этого поля нельзя сохранить фильтры,
   а без проверки — получили бы открытый редирект (`Location:` на чужой хост) из формы, которая
   выглядит как наша.

**Четыре расхождения с портируемым кодом:**

- Уведомление живёт в адресной строке (`?notice=<ключ словаря>`), а не в состоянии формы: в Next.js
  `useActionState` возвращал ответ под конкретную строку, в PHP без JavaScript страница
  перезагружается, и PRG-редирект — единственный способ не потерять ответ при обновлении.
  Под строкой при этом ничего не показывается: плашка одна, вверху экрана.
- Причина отказа в адрес не уезжает: свободный текст светился бы в истории браузера и в
  access-логе хостинга. Она пишется в сессионный flash (`$_SESSION['admin_error']`), а в адрес
  ставится только ключ `migration-failed` — тот же приём, что в экране «Приложения» (`задача 8`,
  `finishAppAction()`). Читать экран обязан помощник ядра, а не своё перечисление ключей.
- Кнопки строк идут отдельной формой каждая с полем `op`, а не `name="op" value=…` на кнопке:
  имя кнопки не попадает в POST, если человек отправил форму клавишей Enter (тот же приём, что
  `core/templates/admin/apps.php`).
- Ссылка заголовка строки ведёт на публичную страницу материала (`/news/{slug}`, а для невидимого —
  `/news/{slug}?draft=1`), а не на форму правки: адрес правки появляется в задаче 18 вместе с самой
  формой, и до неё вела бы в 404.
- Панели «Рубрики» и «Теги» на сводке появляются в задаче 19: до неё их экранов нет.
- Кнопки «Новый материал» на сводке и в списке нет до задачи 18: она ведёт на форму, которую эта
  задача не создаёт. Добавляет её задача 18 — одной строкой в каждый из двух шаблонов.

- [ ] **Шаг 1: Написать падающий тест правил экрана**

Создать `tests/unit/NewsAdminRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Правила экранов админки: какие кнопки положены строке, как выглядит полоса вкладок раздела
// и какой адрес возврата принимает действие. База здесь не нужна ни одному правилу, поэтому
// проверка живёт в unit-наборе: редирект на чужой хост ловится до первого запроса.
final class NewsAdminRulesTest extends TestCase
{
    /**
     * Строка списка в том виде, как её отдаёт adminItemRow(): шаблон читает готовые поля
     * и не пересчитывает видимость сам.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge(
            ['id' => 'id-materiala', 'status' => NEWS_STATUS['DRAFT'], 'open' => false, 'pinned' => false],
            $overrides
        );
    }

    public function testЧерновикуТолькоПечать(): void
    {
        self::assertSame(
            [['op' => 'publish', 'label' => 'На печать']],
            newsRowActions($this->item()),
            'Черновик нельзя снять с сайта — его на сайте и нет'
        );
    }

    public function testВидуНаСайтеСнятиеИЗакрепление(): void
    {
        self::assertSame(
            [
                ['op' => 'unpublish', 'label' => 'Снять с сайта'],
                ['op' => 'pin', 'label' => 'Закрепить'],
            ],
            newsRowActions($this->item(['status' => NEWS_STATUS['PUBLISHED'], 'open' => true]))
        );

        self::assertSame(
            [
                ['op' => 'unpublish', 'label' => 'Снять с сайта'],
                ['op' => 'unpin', 'label' => 'Открепить'],
            ],
            newsRowActions($this->item(['status' => NEWS_STATUS['PUBLISHED'], 'open' => true, 'pinned' => true]))
        );
    }

    public function testЖдущемуСрокаЗакреплениеНеПредлагается(): void
    {
        // Материал уже со статусом «опубликована», но срок не наступил: в ленте его нет,
        // а закрепление имеет смысл только там. Снятие остаётся — строку надо вернуть в черновик.
        self::assertSame(
            [['op' => 'unpublish', 'label' => 'Снять с сайта']],
            newsRowActions($this->item(['status' => NEWS_STATUS['PUBLISHED'], 'open' => false]))
        );
    }

    public function testНезнакомомуСтатусуКнопокНет(): void
    {
        self::assertSame([], newsRowActions($this->item(['status' => 'ARCHIVED'])), 'Строки из будущего не печатают кнопок');
    }

    public function testПолосаВкладокЗнаетДваЭкрана(): void
    {
        self::assertCount(2, NEWS_ADMIN_TABS);

        foreach (NEWS_ADMIN_TABS as $tab) {
            self::assertStringStartsWith('/news/admin', (string) $tab['href'], (string) $tab['label']);
            self::assertNotSame('', (string) $tab['label']);
        }
    }

    public function testПолосаВкладокОтмечаетТекущийЭкран(): void
    {
        $nav = newsAdminNav('/news/admin/items');

        self::assertSame(1, substr_count($nav, 'aria-current="page"'), 'Отмеченной бывает одна вкладка');
        self::assertStringContainsString('href="/news/admin/items" aria-current="page"', $nav);
        self::assertStringContainsString('>Сводка</a>', $nav);
        self::assertStringContainsString('aria-label="Экраны раздела', $nav);
    }

    public function testВозвратПринимаетТолькоАдресСписка(): void
    {
        $cases = [
            ['/news/admin/items', '/news/admin/items'],
            ['/news/admin/items?status=DRAFT&page=2', '/news/admin/items?status=DRAFT&page=2'],
            ['', '/news/admin/items'],
            ['/news', '/news/admin/items'],
            ['/news/admin', '/news/admin/items'],
            ['/news/admin/items/123', '/news/admin/items'],
            ['/news/admin/items2', '/news/admin/items'],
            ['//evil.example/news/admin/items', '/news/admin/items'],
            ['https://evil.example/news/admin/items', '/news/admin/items'],
            ["/news/admin/items\nX-Forwarded-For: evil", '/news/admin/items'],
        ];

        foreach ($cases as [$given, $want]) {
            self::assertSame($want, newsAdminBackHref($given), 'Возврат: ' . str_replace("\n", '\n', $given));
        }
    }

    public function testВозвратОбрезаетсяПоПределу(): void
    {
        // Поле приходит из браузера и обязано иметь потолок: бесконечный Location — подарок
        // тому, кто решит раздуть заголовок.
        $long = '/news/admin/items?q=' . str_repeat('а', 400);

        // mb_strlen, а не strlen: обрезка идёт по символам, а кириллица в UTF-8 занимает два байта,
        // и байтовая длина ничего про предел не сказала бы.
        self::assertSame(300, mb_strlen(newsAdminBackHref($long)));
    }

    public function testОповещениеОПерекосеСобираетсяВОдномМесте(): void
    {
        // Оповещение печатают два экрана, и текст у них обязан быть дословно один: пока он был
        // переписан в оба шаблона, правка формулировки расходилась между экранами.
        self::assertSame('', newsPinnedSkewAlert(0), 'Нет закреплённых — не о чем предупреждать');
        self::assertSame('', newsPinnedSkewAlert(PINNED_WARN_LIMIT), 'Ровно предел — ещё не перекос');

        $alert = newsPinnedSkewAlert(PINNED_WARN_LIMIT + 1);

        self::assertStringStartsWith('<p class="rounded-lg border border-bad/30 bg-bad/10', $alert);
        self::assertStringContainsString('role="alert"', $alert, 'Оповещение обязан прочесть скринридер');
        self::assertStringContainsString('Закреплено 6 материалов — это больше 5', $alert);
    }

    public function testКаждаяКнопкаВедётКИзвестномуУведомлению(): void
    {
        self::assertSame(['publish', 'unpublish', 'pin', 'unpin'], array_keys(NEWS_ROW_OP_LABEL));

        // Ключи, которые эти четыре кнопки способны вернуть в адресную строку (publish даёт
        // published или scheduled, повторное нажатие — unchanged всегда).
        foreach (['published', 'scheduled', 'unpublished', 'pinned', 'unpinned', 'unchanged'] as $key) {
            self::assertArrayHasKey($key, NEWS_NOTICE_TEXT, $key);
        }
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsAdminRulesTest`
Expected: FAIL — `Call to undefined function newsRowActions()`.

- [ ] **Шаг 2: Написать правила экрана**

Создать `apps/news/lib/admin.php`:

```php
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
 */
function newsAdminNav(string $current): string
{
    $items = '';

    foreach (NEWS_ADMIN_TABS as $tab) {
        $href = (string) $tab['href'];
        $active = $href === $current;
        $class = $active
            ? 'rounded-lg bg-ink px-3 py-1.5 text-sm font-medium text-cream'
            : 'rounded-lg px-3 py-1.5 text-sm font-medium text-ink/70 hover:bg-ink/5';
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
```

Изменить `apps/news/lib/boot.php` — добавить подключение сразу после строки с `items.php`:

```php
require_once __DIR__ . '/admin.php';
```

`admin.php` подключается после `items.php`: он читает `NEWS_ADMIN_ITEMS_PATH` и опирается на
`NEWS_STATUS` и `NEWS_NOTICE_TEXT`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/admin.php"`
Expected: `No syntax errors detected`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsAdminRulesTest`
Expected: PASS — 10 проверок.

- [ ] **Шаг 3: Починить повторную регистрацию маршрутов раздела**

**Здесь всплывает ловушка, заложенная задачей 16.** Наборы тестов идут в одном процессе PHPUnit
(порядок в `phpunit.xml`: unit → db → guard → http). `tests/db/AppsRegistryTest` вызывает
`registerAppRoutes()` первым, и с появлением `apps/news/routes.php` этот вызов исполняет файл.
`RouteHandlersTest` потом делает `routesReset()` и зовёт `registerAppRoutes()` снова — а `require_once`
уже не исполняет файл, поэтому таблица остаётся без маршрутов раздела. Страж молча проверяет пустой
список, и критерии §12.5 (приватные экраны раздела закрыты в `robots.txt`) перестают проверяться.
Таблица маршрутов обязана оставаться полной после сброса.

Создать падающий тест — в `tests/guard/RouteHandlersTest.php` добавить метод:

```php
    public function testПослеСбросаТаблицыМаршрутыРазделаНаМесте(): void
    {
        // Первый registerAppRoutes() уже исполнил apps/news/routes.php в этом процессе.
        // require_once файл повторно не выполнит, поэтому маршруты возвращаются из снимка.
        routesReset();
        registerCoreRoutes();
        registerAppRoutes();

        $paths = [];

        foreach (routeTable() as $route) {
            $paths[] = (string) $route['method'] . ' ' . (string) $route['pattern'];
        }

        self::assertContains('GET /news', $paths, 'Маршруты «Новостей» после сброса');
        self::assertContains('GET /news/{slug}', $paths, 'Карточка материала после сброса');
    }
```

Сообщение у первой проверки намеренно отличается от «Публичные маршруты раздела после сброса»,
которое уже стоит в этом файле на фикстурном `GET /demo`: два разных утверждения с одним текстом
читаются как одна проверка, а падать они обязаны по разным причинам. Тот же повод не дублировать
комментарий про `require_once` — он объясняется один раз, в начале метода.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter RouteHandlersTest`
Expected: FAIL — `Маршруты «Новостей» после сброса` (в таблице только маршруты ядра).

Заменить `registerAppRoutes()` в `core/apps.php` целиком и добавить рядом снимок:

```php
/**
 * Снимок маршрутов разделов: какие файлы уже исполнены и что каждый из них добавил в таблицу.
 * Нужен потому, что routes.php исполняется в процессе один раз (require_once), а таблицы маршрутов
 * между наборами тестов сбрасываются. Форма строки — ровно та, что отдаёт routeTable(): обработчик
 * бывает и именем функции, и замыканием, поэтому тип надо переписывать целиком, а не сводить к строке.
 *
 * @return array{files: list<string>, routes: list<array{method: string, pattern: string, handler: string|Closure, meta: array<string, mixed>, static: bool}>}
 */
function &appRoutesSnapshot(): array
{
    static $state = ['files' => [], 'routes' => []];

    return $state;
}

// Маршруты регистрируются для всех приложений из сборки, включая неустановленные: dispatch()
// отвечает 404 по метке app, а таблица маршрутов остаётся полной для стража.
function registerAppRoutes(): void
{
    $snapshot = &appRoutesSnapshot();

    // Файлы уже исполнены — функции контроллеров определены, а таблицу заполняем снимком.
    if ($snapshot['files'] !== []) {
        foreach ($snapshot['routes'] as $route) {
            addRoute($route['method'], $route['pattern'], $route['handler'], $route['meta']);
        }

        return;
    }

    $before = count(routeTable());

    foreach (appManifests() as $manifest) {
        $routes = rtrim((string) $manifest['dir'], '/') . '/routes.php';

        if (is_file($routes)) {
            require_once $routes;
            $snapshot['files'][] = $routes;
        }
    }

    // В снимок попадает только то, что добавили разделы: маршруты ядра регистрируются отдельно
    // и на повторе не должны удваиваться.
    $snapshot['routes'] = array_slice(routeTable(), $before);
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter RouteHandlersTest`
Expected: PASS.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter AppsRegistryTest`
Expected: PASS — `testМаршрутыПриложенийРегистрируютсяСМеткойПриложения` работает по-прежнему
(он вызывает `registerAppRoutes()` после `routesReset()`, то есть как раз ветку снимка).

- [ ] **Шаг 4: Проверить подключение правил раздела и дочинить склейку адреса**

Здесь ловушка того же рода, что и в шаге 3, и она дороже: без неё набор `http` падает на первом же
запросе в раздел.

**4а. Правила раздела должны быть подключены к запросу — проверить, что подключение на месте.**
`apps/news/lib/boot.php` читают `tests/bootstrap.php` и `apps/news/seed.php` — в боевом процессе его
не подключает никто, кроме самого раздела. Задача 16 ставит `require_once __DIR__ . '/lib/boot.php';`
в начало `apps/news/routes.php` (шаг 5 той задачи), потому что контроллеры оттуда же зовут `findFeed()`
и `parseQuery()`. Набор `db` и `unit` этой щели не видят: там файл подключён `tests/bootstrap.php`, а
набор `http` ходит в отдельный процесс `php -S`, где других подключений нет.

Run: `grep -n "require_once __DIR__ . '/lib/boot.php'" apps/news/routes.php`
Expected: строка найдена сразу после `declare(strict_types=1);`. Если её нет — добавить:

```php
require_once __DIR__ . '/lib/boot.php';
```

**Почему это безопасно после `routesReset()`.** `require_once` исполнит `boot.php` один раз за
процесс — так же, как и `routes.php`. Константы и функции правил останутся определены, а таблица
маршрутов на повторе восстанавливается снимком из шага 3. Обратное направление (чтобы `boot.php`
подключал `routes.php`) по-прежнему запрещено тем же рассуждением, что записан в задаче 16.

**4б. Раздел возвращается в адрес со своими фильтрами — склейку надо проверять, а не повторять.**
Ядро уже выбирает разделитель, но делает это одной строкой в `redirectWithNotice()`:

```php
$separator = str_contains($to, '?') ? '&' : '?';
redirect($to . $separator . 'notice=' . rawurlencode($notice));
```

История у этого выбора не украшение: так `finishAppAction()` склеивал отказ вслепую
(`$to . '?error=' . …`), и список раздела — адрес фильтра — терял человека на второй `?`:
`/news/admin/items?status=DRAFT&page=2?notice=…` браузер читает как один параметр `status` с
оборванным значением, и фильтр исчезает. Исправление приехало вместе с фикс-раундом ветки, но
проверки на нём нет: единица «значение с запятой и знаком вопроса обязано доехать до адреса
кодом» не написана, а разделу склейка нужна в двух местах — возврат в список после кнопки строки
и возврат после сохранения формы.

Вынести склейку в ядро и пользоваться ею обоим местам. Создать падающий тест
`tests/unit/QueryAppendTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Разделитель параметров — единственное место, где адрес встречается с вопросом. Ядро выбирал его
// inline, и этот выбор до сих пор не проверялся: ошибись он на адресе со своими параметрами, человек
// потерял бы фильтр молча. Разделу склейка нужна в двух местах, поэтому она становится помощником.
final class QueryAppendTest extends TestCase
{
    public function testПервыйПараметрИдётПослеЗнакаВопроса(): void
    {
        self::assertSame('/admin/apps?notice=installed', queryAppend('/admin/apps', 'notice', 'installed'));
    }

    public function testСледующийПараметрИдётПослеАмперсанда(): void
    {
        self::assertSame(
            '/news/admin/items?status=DRAFT&notice=published',
            queryAppend('/news/admin/items?status=DRAFT', 'notice', 'published')
        );
    }

    public function testЗначениеИлючкаКодируются(): void
    {
        // Помощник общий: и имя параметра, и значение кодируются одинаково, какие бы ни подставили.
        self::assertSame('/x?q=a%20b%26c%3Fd', queryAppend('/x', 'q', 'a b&c?d'));
    }

    public function testПустоеЗначениеВсёРавноПишется(): void
    {
        // Плашка «ничего не изменилось» приходит ключом, а не текстом, и пустой ключ — тоже ключ:
        // без параметра страница не отличила бы «действия не было» от «действия не назвали».
        self::assertSame('/news/admin/items?notice=', queryAppend('/news/admin/items', 'notice', ''));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter QueryAppendTest`
Expected: FAIL — `Call to undefined function queryAppend()`.

Добавить в `core/response.php` (перед `redirectWithNotice()`):

```php
/**
 * Дописывает в адрес параметр: «?» перед первым и «&» после него. Возвращает строку, а не редиректит,
 * поэтому проверять склейку можно без выхода из процесса.
 */
function queryAppend(string $to, string $key, string $value): string
{
    $separator = str_contains($to, '?') ? '&' : '?';

    return $to . $separator . rawurlencode($key) . '=' . rawurlencode($value);
}
```

Заменить тело `redirectWithNotice()` целиком:

```php
// PRG: после действия человек видит GET-страницу, а не повторную отправку формы при обновлении.
function redirectWithNotice(string $to, string $notice): never
{
    redirect(queryAppend($to, 'notice', $notice));
}
```

Ветка отказа в `finishAppAction()` (`core/routes.php`) правки не требует — она уже устроена так, как
сказано в пункте «расхождения» выше: причина ложится в сессионный flash, в адрес едет ключ. Слова тут
не держат ничего, поэтому инвариант закрепляет страж исходников: он краснеет, если кто-то «для
удобства» вернёт текст в URL или, наоборот, выключит flash. Создать `tests/guard/FailureChannelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Причина сбоя — текст драйвера, базы или filesystem: в адресной строке он светился бы в истории
// браузера и в access-логе хостинга, а экран печатал бы чужую строку из `?error=`. Разрешённый канал
// один: сессионный flash `admin_error` + ключ словаря `migration-failed` в адресе.
//
// Страж стережёт канал, а не имена ключей: `redirectWithNotice($to, $notice)` — законный вызов,
// переменная с ключом живёт в обработчике, и за её содержание отвечает NoticeKeyTest. Попытка
// проверить здесь и тип аргумента даёт ложные красные строки на легальном коде ядра.
//
// Страж читает те же корни, что и остальные проверки исходников (core, admin, theme, apps, public):
// проверка по всему дереву краснела бы на собственных тестовых фикстурах.
final class FailureChannelTest extends TestCase
{
    /** @var list<string> */
    private const ROOTS = ['core', 'admin', 'theme', 'apps', 'public'];

    /** @return array<string, string> паттерн => что он делает не так */
    private function forbidden(): array
    {
        return [
            '/queryString\(\s*([\'"])error\1/i' => 'читает причину отказа из адресной строки',
            '/queryAppend\(\s*[^,()]+,\s*([\'"])error\1/i' => 'пишет причину отказа в адресную строку',
            '/[\'"][^\'"]*\?error=/i' => 'склеивает отказ с адресом вручную',
        ];
    }

    public function testНиОдинИсходникНеПереводитПричинуОтказаЧерезАдрес(): void
    {
        $files = $this->phpFiles();
        // Пустой список — сломанный поиск, а не «нарушений нет»: без этой строки шаг зелёный и при
        // переезде дерева, и при сбое прав доступа.
        self::assertNotSame([], $files, 'Ни одного PHP-файла не найдено — страж проверяет пустоту');

        $violations = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->forbidden() as $pattern => $what) {
                if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $violations[] = $this->relative($file) . ': ' . $what . ' — ' . trim($match[0]);
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Причина отказа уехала в адресную строку');
    }

    // Обратная половина инварианта: flash-канал обязан оставаться включённым. Если его выбросить,
    // первая проверка пройдёт молча — в адресе отказа нет, но экрану показывать будет нечего.
    public function testЯдроПишетПричинуОтказаВСессионныйFlash(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/core/routes.php');

        self::assertMatchesRegularExpression(
            '/\$_SESSION\[\s*[\'"]admin_error[\'"]\s*\]\s*=/',
            $routes,
            'finishAppAction() больше не пишет причину в flash — экран админки останется без объяснения'
        );
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (self::ROOTS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }

            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

        sort($out);

        return $out;
    }

    private function relative(string $file): string
    {
        return str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $file);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter FailureChannelTest`
Expected: PASS на текущем ядре. Проверить силу: временно заменить в `finishAppAction()` строку
`redirectWithNotice($to, 'migration-failed');` на
`redirect(queryAppend($to, 'error', (string) ($result['error'] ?? 'Действие не удалось.')));` — это
ровно тот возврат к «плашке прямо в URL», из-за которого страж и заведён; он обязан покраснеть на
втором паттерне. Затем убрать правку. Мутация обязательна: страж, который никогда не краснел, —
украшение.

Экраны раздела получают плашку отказа теми же помощниками ядра, что и экран «Приложения». Добавить в
`core/notice.php`:

```php
/**
 * Причина отказа живёт в сессионном flash и показывается ровно один раз: второй показ означал бы,
 * что человек увидел старую причину свежего действия.
 */
function takeAdminFlashReason(): string
{
    startSession();
    $flashed = $_SESSION['admin_error'] ?? '';
    unset($_SESSION['admin_error']);

    return is_string($flashed) ? $flashed : '';
}

// Заголовок отказа — из словаря ядра по ключу 'migration-failed'. Пустая строка означает «отказа нет»:
// ни один экран не должен выдумывать свою формулировку сбоя.
function adminFailureTitle(string $reason): string
{
    if ($reason === '' && !requestNoticeIsFailure()) {
        return '';
    }

    return (string) (noticeText('migration-failed', CORE_NOTICE_TEXT) ?? '');
}
```

И перевести `coreAdminApps()` на них: блок чтения flash и сборки заголовка (комментарий «Причина
сбоя приезжает из сессионного flash» и две строки после него) заменить на `$alertReason =
takeAdminFlashReason();` и `$alertTitle = adminFailureTitle($alertReason);`. Смысл тот же, но остаётся
один источник истины о том, где причина лежит и как называется. Консистенцию ключа отказа и словаря
уже стережёт `tests/guard/NoticeKeyTest.php` — новый страж к нему не дублирует, а дописывает то, чего
он видеть не может: канал, а не имя ключа.

Вместе с помощниками выводится наружу сама плашка — как шаблон ядра, чтобы пять экранов раздела и
экран «Приложения» не размечали один и тот же красный блок каждый по-своему. Создать
`core/templates/admin/alert.php`:

```php
<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $reason */

if ($title === '' && $reason === '') {
    return;
}

echo '<div class="mb-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3" role="alert">';

if ($title !== '') {
    echo '<p class="text-sm font-medium text-bad">' . e($title) . '</p>';
}

if ($reason !== '') {
    echo '<p class="mt-1 text-sm text-bad">' . e($reason) . '</p>';
}

echo '</div>';
```

Форма файла не украшение: `OutputEscapingTest` разбирает печатающее выражение целиком, поэтому
условие внутри тернарника (`$title !== '' ? … : ''`) стало бы нарушением — переменная оказалась бы
в `echo` накрытая только обычной круглой скобкой, а не вызовом-обёрткой. Отдельные `if` с `echo`
проходят страж честно.

Вызов `partial('admin/alert', …)` разрешается по иерархии шаблонов (§5.6): приложение → тема → ядро,
то есть тема может переписать вид плашки, не трогая код. Если файла нет, `resolveTemplate()` молча
вернёт `theme/404.php` — подмена будет видна по отсутствию `role="alert"` в тесте отказа.

Перевести на неё и экран ядра: в `core/templates/admin/apps.php` блок комментариев «Отказ: заголовок
берётся из словаря…» с тернарником, собирающим `$alert`, заменить на

```php
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);
```

Строка вывода (`/* @safe html */ echo adminShell(…)`) остаётся как есть: `$alert` — агрегатор
разметки, собранный тем же файлом.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l core/templates/admin/alert.php && php -l core/templates/admin/apps.php"`
Expected: `No syntax errors detected` для обоих файлов.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter QueryAppendTest`
Expected: PASS — 4 проверки.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter AdminAppsTest`
Expected: PASS — экран ядра работает на новом помощнике (`/admin/apps` параметров не несёт,
разделитель для него остаётся «?»). Проверка отказа печатает и заголовок, и причину и `role="alert"`:
если `core/templates/admin/alert.php` не создан, `resolveTemplate()` подставит 404 и эта проверка
покраснеет сама.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsPublicTest`
Expected: PASS — с подключением `boot.php` раздел начинает отвечать в настоящем процессе; до этого
теста проверка шла вслепую, потому что `unit` и `db` подключают файл сами.

- [ ] **Шаг 5: Написать падающий HTTP-тест двух экранов и кнопок строки**

Создать `tests/http/NewsAdminTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
use Cms\Tests\TestDb;
use DateInterval;
use PHPUnit\Framework\TestCase;

// Экраны админки раздела проверяются настоящим запросом: редирект анонима, плашка после действия,
// отказ кнопки и разметка строки видны только в ответе сервера. Функция, которая собирает эти
// строки, про коды ответа ничего не знает.
final class NewsAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];

    /**
     * Закладка: шесть закреплённых (предел `PINNED_WARN_LIMIT` — пять, поэтому предупреждение
     * экрана обязано быть видно на настоящих числах, а не только в правиле tooManyPinned()),
     * черновик, материал с ненаступившим сроком, материал в скрытой рубрике и пятнадцать
     * обычных — всего 24, чтобы список встал ровно в две страницы по 20.
     */
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        foreach (range(1, 6) as $index) {
            self::create(self::input([
                'title' => 'Закреплённый материал ' . $index,
                'slug' => 'zakreplennyj-' . $index,
                'pinned' => true,
            ]), $index);
        }

        self::create(self::input([
            'title' => 'Черновик поездки',
            'slug' => 'chernovik-poezdki',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 7);

        self::create(self::input([
            'title' => 'Выпускной через час',
            'slug' => 'vypusknov-cherez-chas',
            'publishedAt' => nowUtc()->add(new DateInterval('PT1H')),
        ]), 8);

        self::create(self::input([
            'title' => 'Репетиция в закрытой рубрике',
            'slug' => 'repeticija',
            'categoryId' => categoryIdOf('metodika'),
        ]), 9);

        foreach (range(1, 15) as $index) {
            self::create(self::input([
                'title' => 'Материал ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'slug' => 'material-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]), 9 + $index);
        }

        // Рубрика скрывается после закладки: материал в ней уже опубликован, и экран обязан
        // сказать об этом прямо в строке.
        toggleCategoryVisibility(categoryIdOf('metodika'));

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function input(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Осенний бал',
            'slug' => 'osennij-bal',
            'lead' => 'Короткий лид про вечер.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => [],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    /**
     * Создание с своим моментом: `updated_at` решает порядок списка, а без разных секунд его
     * решила бы база, и страница первой/второй перестала бы быть предсказуемой.
     *
     * @param array<string, mixed> $input
     */
    private static function create(array $input, int $agoMinutes): string
    {
        $result = createItem($input, self::EDITOR, nowUtc()->sub(new DateInterval('PT' . $agoMinutes . 'M')));

        self::assertTrue($result['ok'], (string) $result['error']);

        return (string) $result['id'];
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(string $op, string $id, array $extra = []): array
    {
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);
        $fields = [
            '_token' => $this->token($page['body']),
            'op' => $op,
            'id' => $id,
            'back' => NEWS_ADMIN_ITEMS_PATH,
        ];

        return Kernel::post(NEWS_ADMIN_ITEMS_PATH, array_merge($fields, $extra));
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаСводкеРазделаУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin', $page['headers']['location']);
    }

    public function testАнонимНаСпискеРазделаУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fitems', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапретАНеРедирект(): void
    {
        // Редирект на форму потерял бы тело запроса, поэтому приватный POST отвечает сразу.
        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH, ['op' => 'publish', 'id' => 'любой']);

        self::assertSame(403, $response['status']);
    }

    public function testЭкраныРазделаЗакрытыВRobots(): void
    {
        // Критерий §12.5: закрытость админки раздела — свойство ответа сервера, а не манифеста.
        $page = Kernel::get('/robots.txt');

        self::assertStringContainsString('Disallow: /news/admin', $page['body']);
    }

    // ── Сводка ────────────────────────────────────────────

    public function testСводкаСчитаетМатериалыПоВкладкам(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        self::assertStringContainsString('<title>Новости — админка</title>', $page['body']);
        self::assertStringContainsString('всего материалов</dt><dd class="font-medium tabular-nums">24</dd>', $page['body']);
        self::assertStringContainsString('черновиков</dt><dd class="font-medium tabular-nums">1</dd>', $page['body']);
        self::assertStringContainsString('ждут срока</dt><dd class="font-medium tabular-nums">1</dd>', $page['body']);
        self::assertStringContainsString('на сайте</dt><dd class="font-medium tabular-nums">21</dd>', $page['body'], 'без одного материала в скрытой рубрике');
        self::assertStringContainsString('закреплено из 24 материала</dt><dd class="font-medium tabular-nums">6</dd>', $page['body']);
    }

    public function testСводкаПредупреждаетОПерекосеЛенты(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertStringContainsString('Закреплено 6 материалов — это больше 5', $page['body']);
        self::assertStringContainsString('role="alert"', $page['body'], 'Предупреждение обязан прочесть скринридер');
    }

    public function testСводкаНесётПолосуЭкрановРазделаИСсылкуНаЛенту(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertStringContainsString('aria-label="Экраны раздела «Новости»"', $page['body']);
        self::assertStringContainsString('href="/news/admin" aria-current="page"', $page['body']);
        self::assertStringContainsString('>Материалы</a>', $page['body']);
        self::assertStringContainsString('href="/news"', $page['body'], 'Ссылка на ленту с админки — единственный путь посмотреть результат');
        self::assertStringNotContainsString('/news/admin/categories', $page['body'], 'Панели справочников появляются в задаче 19');
    }

    // ── Список ────────────────────────────────────────────

    public function testСписокОткрываетсяИПоказываетПервуюСтраницу(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Материалы — админка</title>', $page['body']);
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'Список закрыт от индексирования, как и сводка');
        self::assertStringContainsString('24 материала', $page['body'], 'Число согласуется с русским plural()');
        self::assertStringContainsString('Закреплённый материал 1', $page['body']);
        self::assertStringContainsString('Материал 11', $page['body'], 'Двадцатая строка первой страницы');
        self::assertStringNotContainsString('Материал 12', $page['body'], 'Одиннадцатая строка уже на второй странице');
        self::assertStringNotContainsString('/news/admin/categories', $page['body']);
    }

    public function testСписокПредупреждаетОПерекосеТемЖеТекстом(): void
    {
        // Оповещение собирает одна функция (newsPinnedSkewAlert), а экранов у него два. Проверка
        // держится именно на списке: пока текст был переписан в оба шаблона, второй экземпляр
        // не проверялся ни одним тестом и мог расходиться со сводкой.
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertStringContainsString('Закреплено 6 материалов — это больше 5', $page['body']);
        self::assertStringContainsString('role="alert"', $page['body'], 'Предупреждение обязан прочесть скринридер');
    }

    public function testСтрокаСпискаНесётДанныеИКнопки(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertStringContainsString('опубликована', $page['body'], 'Подпись состояния из NEWS_STATUS_LABEL печатается как в словаре');
        self::assertStringContainsString('закреплена', $page['body']);
        self::assertStringContainsString('Репетиция в закрытой рубрике', $page['body']);
        self::assertStringContainsString('Методика (скрыта)', $page['body'], 'Экран обязан сказать, что рубрика снята с показа');
        self::assertStringContainsString('>Снять с сайта</button>', $page['body']);
        self::assertStringContainsString('>Открепить</button>', $page['body']);
        self::assertStringContainsString('name="back"', $page['body'], 'Действие возвращает в тот же список с теми же условиями');
    }

    public function testВкладкаЧерновикиНеПоказываетОпубликованное(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Черновик поездки', $page['body']);
        self::assertStringNotContainsString('Закреплённый материал 1', $page['body']);
        self::assertStringContainsString('>На печать</button>', $page['body']);
        self::assertStringNotContainsString('>Снять с сайта</button>', $page['body'], 'Черновику снятие не предлагается');

        // Скрытое поле возврата несёт адрес списка вместе с фильтром вкладки: «На печать» из
        // «Черновиков» возвращает в те же «Черновики», а не на полный список. Без этой проверки
        // ветка плана про возврат с фильтрами не закреплена ни одним тестом.
        self::assertStringContainsString(
            'name="back" value="/news/admin/items?status=DRAFT"',
            $page['body'],
            'Действие возвращает в тот же список с той же вкладкой'
        );
    }

    public function testЖдущиеСрокаПоказываютСрокИНеПредлагаютЗакрепление(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?status=scheduled');

        self::assertStringContainsString('Выпускной через час', $page['body']);
        self::assertStringContainsString('>Снять с сайта</button>', $page['body']);
        self::assertStringNotContainsString('>Закрепить</button>', $page['body'], 'Материала в ленте ещё нет — закрепление сервер отклонит');
    }

    public function testПоискРаботаетПоАдресуИНеЛомаетСсылки(): void
    {
        $this->login();
        // Поиск по slug, а не по русскому заголовку: адрес — единственное поле списка, которое
        // в запросе не зависит от кодировки URL.
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=chernovik');

        self::assertStringContainsString('Черновик поездки', $page['body']);
        self::assertStringNotContainsString('Закреплённый материал 1', $page['body'], 'Фильтр показывает одну строку из двадцати четырёх');
        self::assertStringContainsString('value="chernovik"', $page['body'], 'Поле поиска возвращает введённое');
        self::assertStringNotContainsString('Страницы материалов', $page['body'], 'Пагинатор по одной строке не рисуется');
    }

    public function testФильтрПоРубрикеОграничиваетСписок(): void
    {
        $this->login();
        $metodika = categoryIdOf('metodika');
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?cat=' . rawurlencode($metodika));

        self::assertStringContainsString('Репетиция в закрытой рубрике', $page['body']);
        self::assertStringNotContainsString('Закреплённый материал 1', $page['body']);
        self::assertStringContainsString('Методика (скрыта)</a>', $page['body'], 'Чип активной рубрики остаётся ссылкой и говорит, что рубрика снята с показа');
    }

    public function testЧужойФильтрДаётПустойСписокАНеОшибку(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?cat=net-takoj-rubriki');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Под эти условия материалов нет.', $page['body']);
        self::assertStringContainsString('>Сбросить фильтры</a>', $page['body']);
    }

    public function testВтораяСтраницаНесётОстальныеСтроки(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?page=2');

        self::assertStringContainsString('Материал 15', $page['body']);
        self::assertStringContainsString('Материал 12', $page['body']);
        self::assertStringNotContainsString('Материал 11', $page['body']);
        self::assertStringContainsString('aria-current="page">2</span>', $page['body'], 'Текущая страница отмечена, а не ссылки на себя');
        self::assertStringContainsString('href="' . NEWS_ADMIN_ITEMS_PATH . '">1</a>', $page['body'], 'Ссылка на первую страницу ведёт обратно');
    }

    // ── Кнопки строки ─────────────────────────────────────

    public function testПечатьЧерновикаВозвращаетНаСписокСУведомлением(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Собрание кружка',
            'slug' => 'sobranie-kruzhka',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 30);

        $response = $this->press('publish', $id, ['back' => NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT&page=2']);

        self::assertSame(302, $response['status']);
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT&page=2&notice=published',
            $response['headers']['location'],
            'Фильтр и страница теряться не должны'
        );

        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT&page=2');
        self::assertStringContainsString(NEWS_NOTICE_TEXT['published'], $page['body']);
        self::assertStringContainsString('role="status"', $page['body']);
    }

    public function testОтказПубликацииБезТекстаЧитаетсяНаТойЖеСтранице(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Пустая заметка',
            'slug' => 'pustaya-zametka',
            'status' => NEWS_STATUS['DRAFT'],
            'body' => [],
        ]), 31);

        $response = $this->press('publish', $id);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringNotContainsString('error=', $response['headers']['location'],
            'Причина отказа не должна уезжать в адрес: он остаётся в истории браузера и в access-логе');

        // Отказ читается на экране: заголовок берётся из словаря по ключу, а подробная причина
        // приезжает из сессионного flash. В базе от отказа нет ничего, и страница без flash
        // показать его не обязана.
        $page = Kernel::get($response['headers']['location']);
        self::assertStringContainsString(NEWS_PUBLISH_NEEDS_TEXT, $page['body']);
        self::assertStringContainsString('role="alert"', $page['body']);

        // Строка ищется по адресу, а не читается с первой страницы: у созданной с запасом по
        // времени строки `updated_at` самый старый, и на первой странице её бы не было.
        $row = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=pustaya-zametka');
        self::assertStringContainsString('Пустая заметка', $row['body'], 'Отказ не снял материал с места');
        self::assertStringContainsString('>На печать</button>', $row['body'], 'Отказ не перевёл материал в опубликованные');
    }

    public function testСнятиеОставляетСтрокуВЧерновикахСоСроком(): void
    {
        $this->login();
        $publishedAt = nowUtc()->sub(new DateInterval('PT2H'));
        $id = self::create(self::input([
            'title' => 'Снятая линейка',
            'slug' => 'snytaja-linejka',
            'publishedAt' => $publishedAt,
        ]), 32);

        $response = $this->press('unpublish', $id);
        self::assertStringContainsString('notice=unpublished', $response['headers']['location']);

        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT');
        self::assertStringContainsString('Снятая линейка', $page['body']);
        self::assertStringContainsString(formatRuDateTime($publishedAt), $page['body'], 'Срок после снятия сохраняется');
    }

    public function testЗакреплениеИОткреплениеМеняютНаборКнопок(): void
    {
        $this->login();
        $id = self::create(self::input(['title' => 'Обычная строка', 'slug' => 'obichnaja-strocka']), 33);

        $response = $this->press('pin', $id);
        self::assertStringContainsString('notice=pinned', $response['headers']['location']);

        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=obichnaja-strocka');
        self::assertStringContainsString('>Открепить</button>', $page['body']);
        self::assertStringNotContainsString('>Закрепить</button>', $page['body'], 'Кнопка обязана смениться, а не удваиваться');

        $this->press('unpin', $id);
        $again = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=obichnaja-strocka');
        self::assertStringContainsString('>Закрепить</button>', $again['body']);
    }

    public function testНеизвестноеДействиеОтвечаетТекстомАНеПустотой(): void
    {
        $this->login();
        $id = self::create(self::input(['title' => 'Строка без действий', 'slug' => 'bez-dejstvij']), 34);

        $response = $this->press('teleport', $id);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringContainsString(
            'Неизвестное действие со материалом.',
            Kernel::get($response['headers']['location'])['body']
        );
    }

    public function testЧужойТокенНеВыполняетДействие(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Непечатанный',
            'slug' => 'nepechatannyj',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 35);

        $response = $this->press('publish', $id, ['_token' => 'чужой-токен']);

        self::assertSame(403, $response['status']);
        self::assertSame(
            NEWS_STATUS['DRAFT'],
            (string) value('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]),
            'Отказ CSRF не должен сдвинуть материал'
        );
    }

    public function testЧужойАдресВозвратаНеУводитСРаздела(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Возврат наружу',
            'slug' => 'vozvrat-naruzhu',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 36);

        $response = $this->press('publish', $id, ['back' => 'https://evil.example/news/admin/items']);

        self::assertSame(302, $response['status']);
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?notice=published',
            $response['headers']['location'],
            'Открытый редирект из формы — это выход с сайта, поэтому возврат принимается только свой'
        );
    }

    public function testСписокНеОтдаётСыройHTMLВСтроке(): void
    {
        $this->login();
        self::create(self::input([
            'title' => '<script>alert(1)</script>',
            'slug' => 'ekraniruju-titul',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 37);

        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=ekraniruju-titul');

        self::assertStringNotContainsString('<script>alert(1)</script>', $page['body']);
        self::assertStringContainsString('&lt;script&gt;', $page['body']);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsAdminTest`
Expected: FAIL — `/news/admin` отвечает 404: маршрутов админки раздела ещё нет.

**Пять ловушек этого теста:**

- `assertTrue($result['ok'], (string) $result['error'])` в `create()` обрывает закладку на первой же
  ошибке: без него сломанный сид превращается в десяток падений с невнятным «в списке нет строки»,
  а текст отказа в сообщении говорит, какой именно материал не создался.
- `input()` задаёт и `slug`, потому что заголовок кириллицей даёт адрес, который тест не предскажет,
  а поиск в одном случае идёт именно по адресу.
- Порядок строк держится на разных `updated_at` (`$agoMinutes`): у материалов одной секунды порядок
  решила бы база, и «двадцатая строка первой страницы» гуляла бы между прогонами.
- Кнопки проверяются по тексту внутри `<button>`, а не по `value="publish"` скрытого поля: экран
  обязан быть читаемым человеком, и именно это сравнивает критерий §12.4.
- Отказ читается открытием `$response['headers']['location']`, а не чистой ссылкой списка: текста
  ошибки в базе нет, он лежит в сессионном flash, и страница без перехода по адресу редиректа показать
  его не обязана. В адресе — только ключ `migration-failed`.

- [ ] **Шаг 6: Контроллеры, действие строки и маршруты**

Создать `apps/news/admin.php` — контроллеры двух экранов:

```php
<?php

declare(strict_types=1);

// Контроллеры экранов админки раздела. Право на вход проверяет гейт маршрута (permission => 'login'),
// поэтому повторной проверки доступа здесь нет: она была бы второй копией того же правила, а
// расхождение между двумя формулировками — это дыра.
//
// Один `nowUtc()` на экран: вкладки, закрепление и «ещё не время» разбираются по одному моменту,
// иначе счётчики разошлись бы между собой на границе срока.

/** @param array<string, string> $params */
function newsAdminSummary(array $params = []): void
{
    $now = nowUtc();
    $counts = adminItemCounts($now);
    $pinned = pinnedVisibleCount($now);
    $alertReason = takeAdminFlashReason();

    render('news/admin/summary', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(NEWS_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'counts' => $counts,
        'onSite' => onSiteItemCount($now),
        'pinned' => $pinned,
        'waiting' => $counts['scheduled'],
    ], ['title' => 'Новости — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function newsAdminItems(array $params = []): void
{
    $filters = parseAdminFilters([
        'status' => (string) queryString('status', ''),
        'cat' => (string) queryString('cat', ''),
        'q' => (string) queryString('q', ''),
        'page' => (string) queryString('page', '1'),
    ]);

    $now = nowUtc();
    $alertReason = takeAdminFlashReason();

    render('news/admin/items', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(NEWS_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'filters' => $filters,
        'listing' => adminItemPage($filters, $now),
        'counts' => adminItemCounts($now),
        'onSite' => onSiteItemCount($now),
        'pinned' => pinnedVisibleCount($now),
        'categories' => categoryOptions(),
    ], ['title' => 'Материалы — админка', 'noindex' => true]);
}
```

Создать `apps/news/actions/items.php`:

```php
<?php

declare(strict_types=1);

// Действие кнопки строки списка. CSRF проверяется первым: до проверки токена нельзя ни менять
// базу, ни узнавать, существует ли материал.
//
// Право на переход проверяет lib/items.php, а не набор кнопок на экране: человек может послать
// op=pin черновику хоть curl'ом, и он обязан получить тот же отказ, что и тот, кто читает текст.
/** @param array<string, string> $params */
function newsItemsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);
    $back = newsAdminBackHref(postString('back'));

    $result = match ($op) {
        'publish' => setItemStatus($id, NEWS_STATUS['PUBLISHED']),
        'unpublish' => setItemStatus($id, NEWS_STATUS['DRAFT']),
        'pin' => setItemPinned($id, true),
        'unpin' => setItemPinned($id, false),
        default => itemFailure('Неизвестное действие со материалом.'),
    };

    finishAppAction($result, $back, (string) ($result['notice'] ?? ''));
}
```

`mb_substr(..., 0, NEWS_ID_MAX)` — та же граница, что стоит в `parseAdminFilters()` для идентификатора
рубрики: чужая строка в 400 символов не обязана уходить в `WHERE id = ?` целиком, длинный идентификатор
всё равно ничего не нашёл бы.

Изменить `apps/news/routes.php` — после подключения `lib/boot.php` (задача 16, шаг 5) добавить два
подключения файлов контроллеров:

```php
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/actions/items.php';
```

и в конец блока `addRoute()` — три строки после `addRoute('GET', '/news/{slug}', …)`:

```php
// Экраны админки раздела. Статические пути идут раньше параметрических по правилу маршрутизатора
// (core/router.php сортирует таблицу по признаку `static`), поэтому /news/admin не перехватывается
// адресом материала — даже если кто-то опубликует новость с адресом «admin».
addRoute('GET', '/news/admin', 'newsAdminSummary', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/news/admin/items', 'newsAdminItems', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/items', 'newsItemsAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
```

`permission => 'login'`, а не `'manage'`: раздел правит контент-менеджер, а удаление раздела и
обновления — дело администратора. Заодно это то же право, что у `getEditorAccess()`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/admin.php && php -l apps/news/actions/items.php && php -l apps/news/routes.php"`
Expected: `No syntax errors detected` трижды.

- [ ] **Шаг 7: Шаблоны двух экранов**

Оба шаблона собирают строки разметки в PHP и печатают один раз через `adminShell()` — так же, как
экран ядра `core/templates/admin/apps.php`. Ни один экран не читает базу сам: всё посчитано в
контроллере, и страж видимости (задача 20) не найдёт здесь нового `SELECT … FROM tbl_news_item`.

Создать `apps/news/templates/admin/summary.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var array<string, int> $counts
 * @var int $onSite
 * @var int $pinned
 * @var int $waiting
 */
$itemForms = ['материал', 'материала', 'материалов'];

$lines = '';
foreach ([
    ['всего материалов', $counts['all']],
    ['черновиков', $counts['DRAFT']],
    ['ждут срока', $counts['scheduled']],
    ['на сайте', $onSite],
    ['закреплено из ' . $counts['all'] . ' ' . plural($counts['all'], $itemForms), $pinned],
] as [$label, $value]) {
    $lines .= '<div class="flex items-baseline justify-between gap-2 border-b border-ink/10 py-1 last:border-0">'
        . '<dt class="text-sm text-ink/60">' . e($label) . '</dt>'
        . '<dd class="font-medium tabular-nums">' . e((string) $value) . '</dd></div>';
}

$panel = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold"><a class="underline underline-offset-4 hover:text-accent" href="'
    . e(NEWS_ADMIN_ITEMS_PATH) . '">Материалы</a></h2>'
    . '<dl class="mt-2">' . $lines . '</dl></section>';

$waitingText = $waiting === 0
    ? 'Материалов, которые ждут своего срока, нет.'
    : sprintf('По сроку выйдут сами: %1$d %2$s.', $waiting, plural($waiting, $itemForms));

$alert = newsPinnedSkewAlert($pinned);

$foot = '<p class="text-sm text-ink/60">Выход по сроку работает без задания: строка уже опубликована, '
    . 'а лента, блоки и карта сайтов показывают её, когда наступит срок. Проверить её можно по ссылке '
    . '«на сайте» в списке.</p>';

$content = $nav . '<p class="text-sm text-ink/70">' . e($waitingText) . '</p>'
    . $alert
    . '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">' . $panel . '</div>'
    . '<p class="flex flex-wrap items-center gap-3"><a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm text-cream hover:opacity-90" '
    . 'href="' . e(NEWS_ADMIN_ITEMS_PATH) . '">Открыть список</a>'
    . '<a class="inline-flex min-h-[44px] items-center text-sm text-ink/70 underline underline-offset-4 hover:text-ink" href="/news">Посмотреть ленту</a></p>'
    . $foot;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason])
    . adminShell('Новости', $content, $notice);
```

Создать `apps/news/templates/admin/items.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var array{status: string, categoryId: string, query: string, page: int} $filters
 * @var array{items: list<array<string, mixed>>, total: int, pages: int, current: int, window: list<int>, perPage: int, href: string} $listing
 * @var array<string, int> $counts
 * @var int $onSite
 * @var int $pinned
 * @var list<array{id: string, title: string, visible: bool}> $categories
 */
$itemForms = ['материал', 'материала', 'материалов'];
$CHIP = 'rounded-lg px-3 py-1.5 text-sm font-medium';
$CHIP_ON = $CHIP . ' bg-ink text-cream';
$CHIP_OFF = $CHIP . ' border border-ink/10 bg-cream hover:bg-ink/5';

$tabs = '';
foreach (NEWS_FILTERS as $filter) {
    $active = $filter === $filters['status'];
    $tabs .= '<a class="' . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
        . e(adminItemsHref(array_merge($filters, ['status' => $filter, 'page' => 1]))) . '"'
        . ($active ? ' aria-current="true"' : '') . '>' . e(NEWS_FILTER_LABEL[$filter])
        . '<span class="ml-1.5 tabular-nums opacity-70">' . e((string) ($counts[$filter] ?? 0)) . '</span></a>';
}

$chips = '';
if ($categories !== []) {
    $all = '<a class="' . e($filters['categoryId'] === '' ? $CHIP_ON : $CHIP_OFF) . '" href="'
        . e(adminItemsHref(array_merge($filters, ['categoryId' => '', 'page' => 1]))) . '">Все рубрики</a>';

    foreach ($categories as $category) {
        $active = (string) $category['id'] === $filters['categoryId'];
        $chips .= '<a class="' . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
            . e(adminItemsHref(array_merge($filters, ['categoryId' => (string) $category['id'], 'page' => 1]))) . '">'
            . e((string) $category['title']) . ((bool) $category['visible'] === false ? ' (скрыта)' : '') . '</a>';
    }

    $chips = '<nav aria-label="Материалы по рубрике" class="flex flex-wrap gap-1">' . $all . $chips . '</nav>';
}

$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="' . e(NEWS_ADMIN_ITEMS_PATH) . '">'
    . ($filters['status'] !== 'all'
        ? '<input type="hidden" name="status" value="' . e($filters['status']) . '">' : '')
    . ($filters['categoryId'] !== ''
        ? '<input type="hidden" name="cat" value="' . e($filters['categoryId']) . '">' : '')
    . '<label class="block min-w-0 flex-1 sm:max-w-xs" for="q">'
    . '<span class="text-sm text-ink/60">Поиск по заголовку, лиду или адресу</span>'
    . '<input class="mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" id="q" name="q" '
    . 'maxlength="' . e((string) SEARCH_MAX_LENGTH) . '" value="' . e($filters['query']) . '" placeholder="осенний бал">'
    . '</label>'
    . '<button class="rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Найти</button>';

// Сброс ведёт на чистый адрес списка: ссылка, которая сохранила бы хотя бы один фильтр, была бы
// вторым способом сказать «показать всё» — и люди бы не нашли его.
if ($filters['query'] !== '' || $filters['categoryId'] !== '' || $filters['status'] !== 'all') {
    $search .= '<a class="px-2 py-2 text-sm text-ink/60 underline underline-offset-4 hover:text-ink" href="'
        . e(NEWS_ADMIN_ITEMS_PATH) . '">Сбросить</a>';
}

$search .= '</form>';
$filtersHtml = '<nav aria-label="Материалы по состоянию" class="flex flex-wrap gap-1">' . $tabs . '</nav>'
    . $chips . $search;

// Кнопка строки — своя форма с скрытыми `op`, `id` и `back`: имя кнопки не доходит до сервера,
// если человек отправил форму клавишей Enter, а `back` держит фильтр и страницу.
$opClass = static function (string $op): string {
    // Сплошная кнопка одна — «На печать»; снятие с сайта, закрепление и открепление остаются
    // контурными. Отдельная ветка на каждый контурный случай в точности повторила бы `default`,
    // и правка цвета размечалась бы в двух местах.
    return match ($op) {
        'publish' => 'bg-ink text-cream hover:opacity-90',
        default => 'border border-ink/20 hover:bg-ink/5',
    };
};

$rows = '';
foreach ($listing['items'] as $item) {
    // Кнопки пересобираются для каждой строки: переменная обнуляется здесь, и внешний ноль
    // перед циклом был бы мёртвой записью.
    $buttons = '';
    $id = (string) $item['id'];

    foreach (newsRowActions($item) as $action) {
        $buttons .= '<form class="inline" method="post" action="' . e(NEWS_ADMIN_ITEMS_PATH) . '">' . csrfField()
            . '<input type="hidden" name="op" value="' . e((string) $action['op']) . '">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<input type="hidden" name="back" value="' . e($listing['href']) . '">'
            . '<button class="rounded-lg px-2.5 py-1 text-sm ' . e($opClass((string) $action['op'])) . '" type="submit">'
            . e((string) $action['label']) . '</button></form>';
    }

    if ($buttons === '') {
        $buttons = '<span class="text-sm text-ink/50">Действий нет</span>';
    }

    // Адрес материала: невидимый материал открывается только по прямой ссылке с ?draft=1, и список
    // обязан вести именно туда — иначе редактор жалуется на 404 там, где всё работает.
    $publicHref = (string) $item['href'] . ($item['open'] === true ? '' : '?draft=1');
    $badge = $item['open'] === true
        ? 'rounded-lg bg-good/10 px-2 py-0.5 text-xs font-medium text-good'
        : ($item['scheduled'] === true
            ? 'rounded-lg bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent'
            : 'rounded-lg border border-ink/20 px-2 py-0.5 text-xs font-medium text-ink/70');

    $tags = (array) $item['tags'];
    $meta = '<span>срок: ' . e((string) $item['published']) . '</span>';

    if ($item['pinned'] === true) {
        $meta .= '<span class="font-medium text-ink">закреплена</span>';
    }

    $meta .= '<span>рубрика: ' . e((string) $item['categoryTitle'])
        . ($item['categoryVisible'] === true ? '' : ' (скрыта)') . '</span>';

    if ($tags !== []) {
        $meta .= '<span>теги: ' . e(implode(', ', array_map('strval', $tags))) . '</span>';
    }

    if ((string) $item['authorEmail'] !== '') {
        $meta .= '<span>печать: ' . e((string) $item['authorEmail']) . '</span>';
    }

    $rows .= '<li class="rounded-lg border border-ink/10 bg-white p-3">'
        . '<div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">'
        . '<a class="min-w-0 flex-1 font-medium hover:underline" href="' . e($publicHref) . '">'
        . e((string) $item['title']) . '</a>'
        . '<span class="' . e($badge) . '">' . e((string) $item['statusLabel']) . '</span></div>'
        . '<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink/60">' . $meta . '</div>'
        . '<div class="mt-2 flex flex-wrap gap-2">' . $buttons . '</div></li>';
}

$pager = '';
if ($listing['pages'] > 1) {
    $numbers = '';

    foreach ($listing['window'] as $number) {
        $numbers .= '<li>' . ($number === $listing['current']
            ? '<span class="rounded-lg border border-accent bg-accent/10 px-2.5 py-1 text-sm font-medium tabular-nums" aria-current="page">'
                . e((string) $number) . '</span>'
            : '<a class="rounded-lg border border-ink/10 bg-cream px-2.5 py-1 text-sm tabular-nums hover:border-ink/20" href="'
                . e(adminItemsHref(array_merge($filters, ['page' => $number]))) . '">' . e((string) $number) . '</a>')
            . '</li>';
    }

    $pager = '<nav aria-label="Страницы материалов"><ul class="flex flex-wrap gap-2">' . $numbers . '</ul></nav>';
}

$empty = '<div class="rounded-lg border border-ink/10 bg-white py-10 text-center text-ink/60">'
    . '<p class="text-sm">Под эти условия материалов нет.</p>'
    . '<p class="mt-2"><a class="text-sm underline underline-offset-4 hover:text-ink" href="'
    . e(NEWS_ADMIN_ITEMS_PATH) . '">Сбросить фильтры</a></p></div>';

$heading = '<div class="flex flex-wrap items-end justify-between gap-3"><div class="space-y-1">'
    . '<p class="text-sm text-ink/70">' . e((string) $listing['total']) . ' ' . e(plural((int) $listing['total'], $itemForms))
    . ' · на сайте ' . e((string) $onSite) . ', ждут срока ' . e((string) $counts['scheduled'])
    . ', черновиков ' . e((string) $counts['DRAFT']) . '</p></div></div>';

$alert = newsPinnedSkewAlert($pinned);

$foot = '<p class="text-sm text-ink/60">На странице ' . e((string) ADMIN_NEWS_PER_PAGE) . ' материалов. '
    . 'Текст, рубрику и теги правят в форме материала; «На печать» выходит с тем сроком, что стоит в поле, '
    . 'а снятие оставляет строку в черновиках со сроком.</p>';

$content = $nav . $heading
    . $alert
    . $filtersHtml
    . ($rows === '' ? $empty : '<ul class="space-y-2">' . $rows . '</ul>')
    . $pager
    . $foot;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason])
    . adminShell('Материалы', $content, $notice);
```

Три вещи в этих шаблонах — не украшение:

- `$opClass()` возвращает класс из `match` по известному `op`, а не из данных строки: кнопка получает
  цвет из кода, и подставить в `class=""` что-то пришедшее из базы через неё нельзя.
- Пустой список — это `<div>` с текстом и ссылкой, а не `0 строк`: пустота без объяснения читается
  как сломанный фильтр.
- `$badge` различает «на сайте», «ждёт срока» и «черновик» тремя цветами из токенов темы, а не одним:
  срок, который ещё не наступил, выглядит опубликованным по подписи, и человек решает по цвету.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsAdminTest`
Expected: PASS — 24 проверки.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/templates/admin/summary.php && php -l apps/news/templates/admin/items.php"`
Expected: `No syntax errors detected` дважды.

- [ ] **Шаг 8: Прогнать ворота целиком**

Run: `bash scripts/gate.sh`
Expected: `php -l`, PHPStan (уровень 6, без ошибок), PHP-CS-Fixer (без правок) и все четыре набора —
зелёные. Отдельно смотреть:

- `tests/guard/CsrfCoverageTest.php` — `newsItemsAction()` обязан найтись в списке POST-обработчиков
  и пройти проверку `csrfCheck()`; каждая `<form method="post">` в `items.php` несёт своё `csrfField()`.
- `tests/guard/OutputEscapingTest.php` — оба шаблона печатают разметку собранной строкой и заканчиваются
  пометкой `@safe html`; коротких `<?= ?>` в них нет ни одного.
- `tests/guard/SqlSafetyTest.php` — в шаблонах и контроллерах нет ни одного `SELECT`: выборка живёт
  в `lib/items.php`, и страж видимости задачи 20 потому и белый список, что файлов с запросами ровно
  столько, сколько объявлено.
- `tests/guard/DateUsageTest.php` — `formatRuDateTime()` и `nowUtc()` вызываются из кода раздела, а
  `new DateInterval` встречается только в тесте закладки.
- `tests/guard/RouteHandlersTest.php` — три новых адреса зарегистрированы, обработчики callable,
  `/news/admin` закрыт в `robots.txt`.

Если PHPStan уровень 6 придерётся к `array_merge($filters, ['status' => $filter, 'page' => 1])`
(объединение двух форм он расширяет до `array<string, mixed>`, а `adminItemsHref()` ждёт четыре
известных ключа), объединение раскладывается явным литералом — тот же результат, но форма видна:

```php
$href = adminItemsHref([
    'status' => $filter,
    'categoryId' => $filters['categoryId'],
    'query' => $filters['query'],
    'page' => 1,
]);
```

- [ ] **Шаг 9: Проверить экраны руками и закоммитить**

Run:
```bash
docker compose -f docker-compose.dev.yml up -d db
bash scripts/db-reset
rm -f public/install.lock
docker compose -f docker-compose.dev.yml run --rm -T --service-ports -e CMS_DB_NAME=cms_test php php -S 0.0.0.0:8080 -t public public/index.php
# следующим окном, когда стенд отвечает: ядро ставится формой http://localhost:8080/setup.php
docker compose -f docker-compose.dev.yml run --rm -T -e CMS_DB_NAME=cms_test php php -r 'require "config.php"; require "core/bootstrap.php"; installApp("news");'
```

Ядро ставится только формой в браузере: у `public/setup.php` нет ветки разбора аргументов команды, он
знает POST. Строка вида `php public/setup.php --admin-email=… --admin-password=…` из прежнего текста
плана ничего не устанавливала — в терминале печатается форма, база остаётся пустой, замок не пишется.
Открытым нужно держать и стенд: `php -S` в `docker compose run` работает на переднем плане, поэтому
установка формы и команда раздела идут вторым окном, пока первый не закрыт.

Четыре вещи в этом блоке — не оформление, а условие, чтобы он запустился:

- `bash scripts/db-reset` выполняется на хосте: скрипт сам зовёт `docker compose exec db`, а внутри
  контейнера `php` docker-клиента нет. Пересоздаёт он только `cms_test`.
- `rm -f public/install.lock`: замок лежит в рабочем каталоге и про базу ничего не знает, поэтому
  после любого прогона установщика — вручную или набором `http` — его надо снять, иначе повторный
  обход получит отказ вместо формы.
- `-e CMS_DB_NAME=cms_test` в обеих командах блока: экраны руками проверяются на сброшенной тестовой
  базе, а `cms_dev` остаётся разработчику. Без этой строки установщик напишет замок и администратора
  в `cms_dev`, и повторный обход придётся начинать с ручной чистки — форма ставит ядро в ту базу,
  которую видит контейнер стенда.
- `run` не публикует порты сервиса — в отличие от `up`, где за это отвечает `-P`. Здесь нужен
  `--service-ports`, иначе контейнер поднимется, а `http://localhost:8080` останется без ответа:
  обход в браузере упрётся в пустой порт, и непонятно, экран виноват или команда. Отдельно
  `php -S` обязан получить скрипт-роутер `public/index.php`: без него статика и `/setup.php`
  под встроенным сервером не отвечают.

Открыть в браузере `http://localhost:8080/news/admin`, войти `admin@school.dev`, убедиться:

- сводка показывает пять строк счётчика, предупреждение о перекосе и ссылку на ленту;
- «Материалы» открывает список, кнопки строки работают, а после «На печать» человек возвращается
  в ту же вкладку с зелёной плашкой;
- на узком экране (360 px) вкладки переносятся, а не обрезаются — требование адаптивности
  360…1440 касается и этой админки.

Run:
```bash
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git add apps/news/admin.php apps/news/actions/items.php apps/news/lib/admin.php apps/news/lib/boot.php \
  apps/news/lib/items.php apps/news/routes.php apps/news/templates/admin core/apps.php core/response.php \
  core/routes.php core/notice.php core/templates/admin public/assets/app.css \
  tests/guard/FailureChannelTest.php \
  tests/unit/NewsAdminRulesTest.php tests/unit/QueryAppendTest.php \
  tests/guard/RouteHandlersTest.php tests/http/NewsAdminTest.php
git commit -m "Экраны админки «Новостей»: сводка, список с фильтрами и кнопки строки"
```

Коммит забирает правки ядра, без которых раздел не работает: `queryAppend()` вместе с
`redirectWithNotice()` в `core/response.php` (шаг 4), склейку параметров в `finishAppAction()`
(`core/routes.php`) и помощники отказа в `core/notice.php` с общей плашкой
`core/templates/admin/alert.php`. Позиция `core/apps.php` в списке — страховка: снимок маршрутов уехал
в дерево ещё фикс-раундом задач 8 и 16, поэтому в этом коммите у файла изменений нет; если шаг 3
придётся делать заново, он уедет в этот же коммит, а не в «следующий коммит про мелочи».

В этом списке легко пропустить позицию `public/assets/app.css`, и зря: разметка новых экранов принесла
правила, которых в собранном файле ещё нет. `tabular-nums`, `gap-x-3`, `items-baseline`,
`sm:max-w-xs` и `underline-offset-4` в исходниках встречаются только в `apps/news/templates/admin/`,
а в артефакте из коммита задачи 16 у каждого из них ноль вхождений. Если оставить пересборку
незакоммиченной, первый шаг ворот на чистой копии сравнит свежую сборку с устаревшим артефактом
из репозитория и покраснеет — а экраны у разработчика при этом выглядят целыми, потому что его
рабочий файл стилей новый. Порядок тот же, что в задаче 16: `bash scripts/css` на хосте, артефакт
в тот же коммит, что и разметка, которая его породила.

Вторая позиция, которую легко потерять, — `apps/news/lib/items.php`. Счётчик «на сайте» взялся не из
статуса строки в базе, а из правила самой ленты: материал опубликован, но стоит в снятой с показа
рубрике — и читатель его не видит. Поэтому `onSiteItemCount()` вызывается из `apps/news/admin.php`
(оба экрана — сводка и список), а живёт в `lib/items.php`, рядом с `feedScope()`. Если оставить этот
файл за коммитом, на чистой копии первый же заход в админку раздела упрётся в отсутствующую функцию,
а у исполнителя всё продолжит работать, потому что его рабочий файл на месте. Проверка та же, что и
со стилями: `git diff --stat apps/news/lib/items.php` обязан показать свои строки в индексе до
коммита, а не после.

---

### Задача 18: Форма материала — создание, правка и загрузка обложки

**Files:**
- Create: `apps/news/lib/form.php` (правила экрана формы), `apps/news/lib/upload.php` (приём обложки)
- Create: `apps/news/actions/item.php` (POST сохранения), `apps/news/templates/admin/item-form.php`
- Create: `public/uploads/news/.gitkeep`, `public/uploads/.htaccess`
- Modify: `apps/news/admin.php` (два контроллера экрана + общая сборка формы)
- Modify: `apps/news/routes.php` (четыре адреса формы)
- Modify: `apps/news/lib/boot.php` (два подключения в конец списка)
- Modify: `apps/news/templates/admin/summary.php`, `apps/news/templates/admin/items.php`
  (кнопка «Новый материал» и ссылка «Править»)
- Modify: `.gitignore` (исключение для каталога загрузок)
- Modify: `tests/guard/WebServerConfigTest.php` (барьеры каталога загрузок — тот же страж)
- Modify: `tests/http/Kernel.php` (аннотация `post()`: значением может быть `\CURLFile`)
- Test: `tests/unit/NewsFormRulesTest.php`, `tests/http/NewsItemFormTest.php`

**Порт:** `english-portal/src/apps/news/components/news-form.tsx` (все 14 полей, `Hint({error, text})`,
две кнопки с `name="intent"`, `order-first` у кнопки выпуска, ссылка «К списку материалов»), и
`server/items.ts` в части создания и правки — она перенесена задачей 17 в `lib/items.php`, здесь к ней
ничего не добавляется. Источник истины по загрузке — спецификация §7 и §8.5, потому что в портале
картинку принимал route handler на Node, а в PHP приём файла пишется заново.

**Потребляет:**
- `core/request.php`: `postAll()`, `postString()`, `uploadedFile()`, `queryString()`;
  `core/form.php`: `formValues()`, `isOn()` (перечисленное первое — файл запроса, `core/form.php`
  даёт только нормализацию полей)
- `core/auth.php`: `getEditorAccess()`; `core/csrf.php`: `csrfCheck()`, `csrfField()`
- `core/response.php`: `redirect()`, `redirectWithNotice()`, `forbidden()`; `core/request.php`: `requestPath()`
- `core/routes.php` (задача 8): `finishAppAction(array $result, string $to, string $notice): void` —
  единственный канал отказа раздела (причина во flash, в адресе только ключ)
- `core/id.php`: `newId()` (26 символов алфавита `0123456789abcdefghjkmnpqrstvwxyz`)
- `core/template.php`: `render()`; `core/escape.php`: `e()`; `admin/layout.php`: `adminShell()`, `adminNotice()`
- `constants.php`: `NEWS_STATUS`, `NEWS_STATUS_LABEL`, `NEWS_RESERVED_SLUGS`, `NEWS_SLUG_MAX`,
  `NEWS_NOTICE_TEXT`, `NEWS_FIELDS`, `TAG_FIELDS`, `fieldLimit()`
- `validators.php`: `NEWS_FORM_FIELDS`, `NEWS_FORM_MAX`, `parseNewsForm()`, `newsFormErrors()`,
  `resolveStatus()`
- `items.php`: `NEWS_ADMIN_ITEMS_PATH`, `NEWS_ID_MAX`, `NEWS_PUBLISH_NEEDS_TEXT`, `itemForForm()`,
  `createItem()`, `updateItem()`, `itemFailure()`
- `admin.php` (задача 17): `newsAdminNav()`, `newsAdminBackHref()`; `catalog.php`: `categoryOptions()`

**Производит** (это читают задачи 19–20: справочники повторяют и приём файла, и отказ на месте):

```
const NEWS_ITEM_NEW_PATH = '/news/admin/items/new';

newsFormButtons(string $status): list<array{intent: string, label: string, tone: string}>
newsFormValues(array $item, array $raw): array<string, string>

const NEWS_UPLOAD_PREFIX = '/uploads/news/';
const NEWS_IMAGE_MAX_MB = 5;
const NEWS_IMAGE_MAX_BYTES = 5242880;
NEWS_IMAGE_MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

newsUploadDir(): string
newsImageRefusalOf(int $code): string
newsImageInspect(array $file): array{ext: string, error: string}
newsImageHrefOf(string $ext): string
newsImageStore(array $file): array{ok: bool, href: string, error: string}
newsImageDelete(string $href): void

newsItemNew(array $params = []): void
newsItemEdit(array $params = []): void
renderNewsItemForm(array $item, array $raw, array $errors, string $back, string $error = ''): void
newsItemSaveAction(array $params = []): void
```

**Четыре правила этого экрана:**

1. **Создание и правка различаются адресом, а не полем формы.** `POST /news/admin/items/new` создаёт,
   `POST /news/admin/items/{id}` правит. Скрытого `id` в действии нет намеренно: поле, пришедшее из
   браузера, было бы вторым мнением о том, какую строку правят, и расхождение с адресом пришлось бы
   разрешать кодом. Адрес — единственное, чему здесь верят.
2. **Успех уходит редиректом, отказ остаётся на месте.** Сохранение — `redirectWithNotice($back, $notice)`
   (PRG, как и кнопки списка). Отказ формы — тот же адрес, ответ 200, те же имена полей и набранный текст
   внутри: редирект потерял бы то, что человек написал, а §8.3 требует вернуть набранное в форму.
3. **Обратный адрес живёт в поле `back` формы и проходит `newsAdminBackHref()`.** Она принимает чистый
   `/news/admin/items` и список с параметрами, но **не** `/news/admin/items/new` и не
   `/news/admin/items/{id}` — возврат из формы ведёт в список, а не в саму форму. Сохранение из списка,
   открытого с фильтром, возвращает в тот же фильтр; прямой заход в форму без `back` даёт чистый список.
4. **Путь картинки генерируется сервером.** `public/uploads/news/<newId()>.<расширение>`; расширение
   берётся из содержимого (`finfo`), а не из имени, присланное имя файла не читается вообще. Текстовое
   поле `cover` по-прежнему принимает путь сайта — это значение никогда не открывается как файл, оно
   уходит в `src`/`og:image` и проверяется `imagePath()` (только `/…`, не `//…`, без `..` и его
   процент-кодов).

**Три расхождения с портируемым кодом:**

- В портале форма несла скрытое `id`, и один обработчик решал, создавать или править. Здесь решение
  принимает адрес (правило 1), поэтому `id` в форме отсутствует.
- В портале картинка грузилась отдельным POST на route handler, и форма получала уже путь. Здесь файл
  идёт в той же форме (`enctype="multipart/form-data"`, поле `cover_file`) — отдельная кнопка «загрузить»
  потребовала бы JavaScript, а §8.5 требует, чтобы все экраны работали без него.
- Отказ в портале возвращался через `useActionState` тем же состоянием формы. Здесь отказ — обычный ответ
  200 с собранными значениями: без JS больше способа вернуть набранное нет.

**Три ловушки, которые эта задача закрывает:**

- **`php_flag engine off` в корневом `public/.htaccess` выключил бы PHP на всём сайте.** Отключать
  исполнение обязан только каталог загрузок: правило живёт в `public/uploads/.htaccess` (шаг 7), и задача 1
  в корневом файле его не ставит.
- **`.gitignore` прячет `public/uploads/*` — вместе с `.htaccess` и с `.gitkeep` каталога обложек.**
  Без исключения файл защиты не попал бы в репозиторий и на сервер, а без пустого каталога первый же
  отказ был бы «каталог недоступен»; загруженные картинки git показывал бы как изменения рабочего дерева.
  Нужны строки-исключения (шаг 7).
- **Загруженные файлы нельзя попадать под `rsync --delete`.** Иначе следующий деплой сотрёт все обложки,
  которые люди загрузили на сервере. Исключение добавляется в `scripts/deploy.sh` задачей 21, и она же
  ставит этот пункт в чек-лист хостинга §11 — здесь фиксируем это как долг, а не как «сделано».

- [ ] **Шаг 1: Написать падающий unit-тест правил формы и приёмки картинки**

Создать `tests/unit/NewsFormRulesTest.php`:

```php
<?php

declare(strict_types=1);

// Подпространство набора — со строчной буквы: PSR-4 (Cms\Tests\ → tests/) ищет файл tests/unit/,
// а на Linux регистр в пути значим, поэтому «Unit» здесь означало бы «класс не найден».
namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Правила экрана формы проверяются без базы и без HTTP: подписи кнопок, порядок и форма значений,
// разбор присланного файла. Перенос файла на диск проверяется набором `http` — только там
// файл приходит настоящим multipart-запросом и `is_uploaded_file()` отвечает правду.
final class NewsFormRulesTest extends TestCase
{
    // Корень проекта берётся вызовом `dirname()` на месте, а не константой класса: PHP не вычисляет
    // функцию в выражении константы (`Fatal error: Constant expression contains invalid operations`),
    // а остальные наборы этого проекта пишут `dirname(__DIR__, 2)` прямо в коде проверки.

    // ── Кнопки ────────────────────────────────────────────

    public function testКнопкиНовогоМатериала(): void
    {
        $buttons = newsFormButtons('');

        self::assertSame(['draft', 'publish'], array_column($buttons, 'intent'), 'Черновик обязан стоять первым в разметке');
        self::assertSame('Сохранить черновик', $buttons[0]['label']);
        self::assertSame('На печать', $buttons[1]['label']);
        self::assertSame('primary', $buttons[1]['tone']);
        self::assertSame('plain', $buttons[0]['tone']);
    }

    public function testКнопкиЧерновикаСовпадаютСНовымМатериалом(): void
    {
        self::assertSame(newsFormButtons(''), newsFormButtons(NEWS_STATUS['DRAFT']));
    }

    public function testКнопкиОпубликованногоМатериалаМеняютСмысл(): void
    {
        $buttons = newsFormButtons(NEWS_STATUS['PUBLISHED']);

        // На сайте: «черновая» кнопка снимает материал, а печатающая сохраняет как есть.
        self::assertSame('Вернуть в черновики', $buttons[0]['label']);
        self::assertSame('danger', $buttons[0]['tone']);
        self::assertSame('Сохранить', $buttons[1]['label']);
        self::assertSame('plain', $buttons[1]['tone']);
    }

    // ── Значения полей ────────────────────────────────────

    public function testПустыеЗначенияНовогоМатериалаИдутВПорядкеПолейФормы(): void
    {
        $values = newsFormValues([], []);

        self::assertSame(NEWS_FORM_FIELDS, array_keys($values), 'Шаблон печатает поля по этому же списку');
        self::assertSame(array_fill_keys(NEWS_FORM_FIELDS, ''), $values);
    }

    public function testСохранённаяСтрокаВозвращаетсяВФормуКакТекст(): void
    {
        $values = newsFormValues([
            'title' => 'Осенний бал',
            'bodyText' => "## Кому интересно\nПриходите в пятницу.",
            'publishedAt' => '2026-10-04T09:00',
            'pinned' => true,
            'noindex' => false,
            'status' => NEWS_STATUS['PUBLISHED'],
        ], []);

        self::assertSame('Осенний бал', $values['title']);
        self::assertSame("## Кому интересно\nПриходите в пятницу.", $values['body'], 'textarea читает текст абзацев, а не JSON');
        self::assertSame('2026-10-04T09:00', $values['publishedAt']);
        self::assertSame('on', $values['pinned'], 'Флаг возвращается в тот же вид, что отдаёт браузер');
        self::assertSame('', $values['noindex']);
        self::assertSame(NEWS_STATUS['PUBLISHED'], $values['status']);
    }

    public function testНабранныйТекстВажнееСохранённого(): void
    {
        // После отказа человек обязан увидеть то, что писал, а не то, что лежит в базе.
        $values = newsFormValues(
            ['title' => 'Старый заголовок'],
            ['title' => 'Новый заголовок', 'lead' => '', 'slug' => '']
        );

        self::assertSame('Новый заголовок', $values['title']);
        self::assertSame('', $values['seoTitle'], 'Не пришло — пусто, а не «undefined»');
    }

    // ── Приём картинки ────────────────────────────────────

    public function testИмяФайлаГенерируетСервер(): void
    {
        $href = newsImageHrefOf('png');

        self::assertMatchesRegularExpression('#^/uploads/news/[0-9a-z]{26}\.png$#', $href);
        self::assertNotSame($href, newsImageHrefOf('png'), 'Два вызова дают два разных имени');
    }

    public function testКаталогЗагрузокЛежитВПубличномКаталоге(): void
    {
        $dir = str_replace('\\', '/', newsUploadDir());

        self::assertStringEndsWith('/public/uploads/news', $dir);
        self::assertStringStartsWith(str_replace('\\', '/', dirname(__DIR__, 2)), $dir, 'За пределами проекта писать некуда');
    }

    public function testОтказПоКодуЗагрузкиЧеловеческий(): void
    {
        $iniSize = newsImageRefusalOf(UPLOAD_ERR_INI_SIZE);
        $partial = newsImageRefusalOf(UPLOAD_ERR_PARTIAL);

        self::assertNotSame('', $iniSize);
        self::assertNotSame($iniSize, $partial, 'Разные причины не должны звучать одинаково');
        self::assertStringNotContainsString('UPLOAD_', $iniSize, 'Константу PHP человеку показывать нельзя');
    }

    public function testФайлБольшеПятиМегабайтОтклоняется(): void
    {
        $check = newsImageInspect([
            'name' => 'bal.png',
            'type' => 'image/png',
            'tmp_name' => dirname(__DIR__, 2) . '/public/assets/og-default.png',
            'error' => UPLOAD_ERR_OK,
            'size' => NEWS_IMAGE_MAX_BYTES + 1,
        ]);

        self::assertSame('', $check['ext']);
        self::assertStringContainsString('5 МБ', $check['error']);
    }

    public function testРасширениеБерётсяИзСодержимогоАНеИзИмени(): void
    {
        // Настоящий PNG, названный `.jpg`: сохранится как `.png`, потому что расширение берётся из finfo.
        $check = newsImageInspect([
            'name' => 'bal.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => dirname(__DIR__, 2) . '/public/assets/og-default.png',
            'error' => UPLOAD_ERR_OK,
            'size' => 2048,
        ]);

        self::assertSame('png', $check['ext']);
        self::assertSame('', $check['error']);
    }

    public function testНеКартинкаОтклоняется(): void
    {
        $check = newsImageInspect([
            'name' => 'podrobnyj.php.png',
            'type' => 'image/png',
            'tmp_name' => dirname(__DIR__, 2) . '/config.sample.php',
            'error' => UPLOAD_ERR_OK,
            'size' => 512,
        ]);

        self::assertSame('', $check['ext']);
        self::assertStringContainsString('jpg, png или webp', $check['error']);
    }

    public function testУдалениеТрогаетТолькоКаталогЗагрузок(): void
    {
        $foreign = dirname(__DIR__, 2) . '/config.sample.php';
        newsImageDelete('/uploads/../config.sample.php');
        newsImageDelete('');
        newsImageDelete('/uploads/news/takogo-fajla-net.png');

        self::assertFileExists($foreign, 'Адрес вне каталога загрузок не удаляется');
    }

    public function testОдинКлючУведомленияНаКаждыйИсходФормы(): void
    {
        // Форма отвечает шестью ключами; каждый обязан быть в словаре §8.3.
        foreach (['created', 'published', 'scheduled', 'updated', 'unpublished', 'slugChanged'] as $key) {
            self::assertArrayHasKey($key, NEWS_NOTICE_TEXT);
        }
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsFormRulesTest`
Expected: FAIL — `Call to undefined function newsFormButtons()`.

- [ ] **Шаг 2: Правила экрана формы и приём файла**

Создать `apps/news/lib/form.php`:

```php
<?php

declare(strict_types=1);

// Правила экрана формы материала: подписи кнопок и значения полей. База здесь не нужна, поэтому
// правила живут отдельно от контроллера и проверяются unit-набором — так же, как полоса вкладок
// и адрес возврата в lib/admin.php.

// Адрес создания. Правка — `/news/admin/items/{id}`, то есть ключ `editHref` строки `itemForForm()`:
// создание и правка различаются адресом, а не полем формы.
const NEWS_ITEM_NEW_PATH = '/news/admin/items/new';

/**
 * Две кнопки формы. В разметке черновик стоит первым: браузер нажимает первую кнопку при Enter в
 * текстовом поле, и первой кнопкой выпуска форма выкладывала бы недописанный материал на сайт.
 * `order-first` в шаблоне возвращает вид из плана — кнопка выпуска слева.
 *
 * `tone` — имя, а не класс: шаблон сам решает, каким классом его раскрасить, как это делает
 * `$opClass()` в списке материалов.
 *
 * @return list<array{intent: string, label: string, tone: string}>
 */
function newsFormButtons(string $status): array
{
    $onSite = $status === NEWS_STATUS['PUBLISHED'];

    return [
        [
            'intent' => 'draft',
            'label' => $onSite ? 'Вернуть в черновики' : 'Сохранить черновик',
            'tone' => $onSite ? 'danger' : 'plain',
        ],
        [
            'intent' => 'publish',
            'label' => $onSite ? 'Сохранить' : 'На печать',
            'tone' => $onSite ? 'plain' : 'primary',
        ],
    ];
}

/**
 * Что показать в полях. Приоритет у того, что человек вписал: отказ обязан вернуть набранный текст,
 * а не то, что лежит в базе (§8.3).
 *
 * @param array<string, mixed> $item строка `itemForForm()`; пустой массив — новый материал
 * @param array<string, string> $raw поля, вернувшиеся из POST
 *
 * @return array<string, string>
 */
function newsFormValues(array $item, array $raw): array
{
    if ($raw !== []) {
        return formValues($raw, NEWS_FORM_FIELDS);
    }

    $out = [];

    foreach (NEWS_FORM_FIELDS as $field) {
        $out[$field] = match ($field) {
            // Флаги возвращаются в том же виде, в котором их отдаёт браузер, — иначе проверка
            // длины увидит одно, а чекбокс другое.
            'pinned', 'noindex' => ($item[$field] ?? false) === true ? 'on' : '',
            // textarea читает текст абзацев, а не JSON: `bodyText` собран `bodyToFormText()`.
            'body' => (string) ($item['bodyText'] ?? ''),
            default => (string) ($item[$field] ?? ''),
        };
    }

    return $out;
}
```

Создать `apps/news/lib/upload.php`:

```php
<?php

declare(strict_types=1);

// Приём обложки. Файл — единственное место раздела, где запрос приходит с содержимым больше строк,
// поэтому всё решение о том, что можно сохранить, живёт здесь: код загрузки, предел размера и
// формат по содержимому.

// Каталог загрузок доступен по `/uploads/news/…`, и он же — единственная запись на диск раздела.
const NEWS_UPLOAD_PREFIX = '/uploads/news/';

const NEWS_IMAGE_MAX_MB = 5;
const NEWS_IMAGE_MAX_BYTES = NEWS_IMAGE_MAX_MB * 1024 * 1024;

// Соответствие MIME → расширение. Белого списка расширений недостаточно: имя пришедшего файла
// пишет человек, а расширение нужно то, которым файл читается.
/** @var array<string, string> */
const NEWS_IMAGE_MIME_EXT = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function newsUploadDir(): string
{
    return dirname(__DIR__, 3) . '/public/uploads/news';
}

/**
 * Тексты отказов по константам PHP. Человек читает «уменьшите картинку», а не
 * «UPLOAD_ERR_INI_SIZE»: про лимит хостинга он судить не может.
 */
function newsImageRefusalOf(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Картинка больше разрешения сервера — уменьшите её.',
        UPLOAD_ERR_PARTIAL => 'Картинка дошла не целиком — попробуйте ещё раз.',
        UPLOAD_ERR_NO_FILE => 'Файл не пришёл — выберите его ещё раз.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Серверу некуда принять файл — сообщите администратору.',
        UPLOAD_ERR_EXTENSION => 'Загрузку остановила настройка сервера — сообщите администратору.',
        default => 'Картинку не удалось принять — попробуйте ещё раз.',
    };
}

/**
 * Проверка присланного файла: код, размер, формат по содержимому. На диск ничего не пишется,
 * поэтому функция проверяется unit-набором на настоящих файлах.
 *
 * @param array<string, mixed> $file запись `$_FILES`
 *
 * @return array{ext: string, error: string}
 */
function newsImageInspect(array $file): array
{
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code !== UPLOAD_ERR_OK) {
        return ['ext' => '', 'error' => newsImageRefusalOf($code)];
    }

    if ((int) ($file['size'] ?? 0) > NEWS_IMAGE_MAX_BYTES) {
        return ['ext' => '', 'error' => 'Картинка больше ' . NEWS_IMAGE_MAX_MB . ' МБ — уменьшите её перед загрузкой.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');

    if ($tmp === '' || !is_file($tmp)) {
        return ['ext' => '', 'error' => 'Файл не дошёл до сервера — попробуйте ещё раз.'];
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $ext = NEWS_IMAGE_MIME_EXT[$mime] ?? '';

    if ($ext === '') {
        return ['ext' => '', 'error' => 'Обложкой может быть только jpg, png или webp — этот файл читается как другой формат.'];
    }

    return ['ext' => $ext, 'error' => ''];
}

/**
 * Адрес будущего файла. Имя генерирует `newId()`: присланное имя не участвует, поэтому «.php»
 * в нём не может ни во что вылиться, а два одинаковых файла не перетирают друг друга.
 */
function newsImageHrefOf(string $ext): string
{
    return NEWS_UPLOAD_PREFIX . newId() . '.' . $ext;
}

/**
 * Перенос файла в каталог загрузок. `is_uploaded_file()` обязателен: он единственный отличает
 * настоящий multipart-запрос от пути, который кто-то подставил в `$_FILES` руками.
 *
 * @param array<string, mixed> $file запись `$_FILES`
 *
 * @return array{ok: bool, href: string, error: string}
 */
function newsImageStore(array $file): array
{
    $check = newsImageInspect($file);

    if ($check['error'] !== '') {
        return ['ok' => false, 'href' => '', 'error' => $check['error']];
    }

    $dir = newsUploadDir();

    if (!is_dir($dir) && !mkdir($dir, 02775, true)) {
        return ['ok' => false, 'href' => '', 'error' => 'Каталог загрузок недоступен — сообщите администратору.'];
    }

    $href = newsImageHrefOf($check['ext']);
    $tmp = (string) ($file['tmp_name'] ?? '');

    if (!is_uploaded_file($tmp) || !move_uploaded_file($tmp, $dir . '/' . basename($href))) {
        return ['ok' => false, 'href' => '', 'error' => 'Картинку не удалось сохранить на сервере — попробуйте ещё раз.'];
    }

    return ['ok' => true, 'href' => $href, 'error' => ''];
}

/**
 * Удаление картинки, на которую никто не сослался. Работает только внутри каталога загрузок:
 * адрес, который туда не попадает, — не наш адрес, и трогать его нельзя.
 */
function newsImageDelete(string $href): void
{
    if ($href === '' || !str_starts_with($href, NEWS_UPLOAD_PREFIX) || str_contains($href, '..')) {
        return;
    }

    $path = newsUploadDir() . '/' . basename($href);

    if (is_file($path)) {
        unlink($path);
    }
}
```

Изменить `apps/news/lib/boot.php` — дописать два подключения в конец списка, после `seo.php`:

```php
require_once __DIR__ . '/form.php';
require_once __DIR__ . '/upload.php';
```

`form.php` подключается после `validators.php` и `admin.php` (он читает `formValues()` из ядра,
`NEWS_FORM_FIELDS` и `NEWS_STATUS`), `upload.php` — последним: он не читает правил раздела, только
`newId()` из ядра, и следующий за ним файл не нужен.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/form.php && php -l apps/news/lib/upload.php"`
Expected: `No syntax errors detected` дважды.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsFormRulesTest`
Expected: PASS — 14 проверок.

- [ ] **Шаг 3: Написать падающий HTTP-тест формы**

Создать `tests/http/NewsItemFormTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
use Cms\Tests\TestDb;
use CURLFile;
use PHPUnit\Framework\TestCase;

// Форма материала проверяется настоящим запросом: без JS браузер отправляет поля как есть, и
// только в ответе сервера видно, вернулся ли набранный текст, ушёл ли человек в список с плашкой
// и лёг ли файл на диск тем именем, которое решил сервер.
final class NewsItemFormTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor-form@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-form-1', 'email' => 'redaktor-form@shkola.ru'];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        // Адрес заготовленной строки совпадает с тем, что даёт `slugify()` по таблице `NEWS_TRANSLIT`
        // (`й` → `y`), и только поэтому проверка «занятый адрес» ниже остаётся проверкой: форма
        // транслитерирует вписанный кириллицей адрес и обязана увидеть занятой адрес.
        $bal = createItem(self::data('Осенний бал', 'osenniy-bal'), self::EDITOR);
        $draft = createItem(self::data('Черновик экскурсии', 'chernovik-ekskursii', [
            'status' => NEWS_STATUS['DRAFT'],
            'body' => [],
        ]), self::EDITOR);

        self::assertTrue($bal['ok'] && $draft['ok']);

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        // Загрузки проверяются настоящие, файл остаётся на диске: подчищаем только картинки,
        // `.gitkeep` обязан выжить.
        foreach (self::uploadFiles() as $file) {
            unlink($file);
        }
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    /** @param array<string, mixed> $overrides */
    private static function data(string $title, string $slug, array $overrides = []): array
    {
        return array_merge([
            'title' => $title,
            'slug' => $slug,
            'lead' => 'Короткий лид.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => [],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    /** @return list<string> */
    private static function uploadFiles(): array
    {
        $out = [];

        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            foreach (glob(dirname(__DIR__, 2) . '/public/uploads/news/*.' . $ext) ?: [] as $file) {
                $out[] = $file;
            }
        }

        return $out;
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    /**
     * Поля создания: токен берётся из формы нового материала. `cover_file` — CURLFile, поэтому
     * аннотация шире строк: её принимает `Kernel::post`.
     *
     * @param array<string, string|\CURLFile> $overrides
     *
     * @return array<string, string|\CURLFile>
     */
    private function fields(array $overrides = []): array
    {
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        $fields = [
            '_token' => $this->token($page['body']),
            'title' => 'Открытая запись',
            'slug' => 'otkrytaya-zapis',
            'lead' => 'Как попасть в класс.',
            'cover' => '',
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => "## Кому интересно\nПриходите в пятницу.",
            'tags' => 'запись, открытый урок',
            'status' => '',
            'publishedAt' => '',
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'back' => NEWS_ADMIN_ITEMS_PATH,
            'intent' => 'draft',
        ];

        return array_merge($fields, $overrides);
    }

    /** @param array<string, string|\CURLFile> $overrides @return array<string, string|\CURLFile> */
    private function editFields(string $id, array $overrides = []): array
    {
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id));

        $fields = [
            '_token' => $this->token($page['body']),
            'title' => 'Открытая запись',
            'slug' => 'otkrytaya-zapis',
            'lead' => 'Как попасть в класс.',
            'cover' => '',
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => "## Кому интересно\nПриходите в пятницу.",
            'tags' => 'запись',
            'status' => '',
            'publishedAt' => '',
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'back' => NEWS_ADMIN_ITEMS_PATH,
            'intent' => 'draft',
        ];

        return array_merge($fields, $overrides);
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаФормуУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fitems%2Fnew', $page['headers']['location']);
    }

    public function testАнонимСPostНаФормуПолучаетЗапрет(): void
    {
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, ['title' => 'что угодно']);

        self::assertSame(403, $response['status']);
    }

    public function testФормаБезТокенаОтвергнута(): void
    {
        $this->login();
        $fields = $this->fields();
        unset($fields['_token']);
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $fields);

        self::assertSame(403, $response['status']);
    }

    // ── Разметка экрана ───────────────────────────────────

    public function testНовыйМатериалОткрываетсяПустымФормой(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Новый материал — админка</title>', $page['body']);
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        self::assertStringContainsString('enctype="multipart/form-data"', $page['body'], 'Без enctype файл не дойдёт');
        self::assertStringContainsString('name="intent" value="draft"', $page['body']);
        self::assertStringContainsString('name="intent" value="publish"', $page['body']);
        self::assertStringNotContainsString('name="id"', $page['body'], 'Идентификатор в форме не нужен: решает адрес');

        foreach (NEWS_FORM_FIELDS as $field) {
            self::assertStringContainsString('name="' . $field . '"', $page['body'], 'Нет поля ' . $field);
        }
    }

    public function testЧерновикИдётПервымВразметкеАПечатьПервойНаЭкране(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        $draft = strpos($page['body'], 'value="draft"');
        $publish = strpos($page['body'], 'value="publish"');

        self::assertIsInt($draft);
        self::assertIsInt($publish);
        self::assertLessThan($publish, $draft, 'Enter обязан сохранять черновик, а не печатать');
        self::assertMatchesRegularExpression('/class="[^"]*order-first"[^>]*value="publish"/', $page['body'],
            'Кнопка выпуска стоит первой визуально');
    }

    public function testПодсказкаПроФорматКартинкиСтоитНаДвухПолях(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertSame(2, substr_count($page['body'], 'Картинка горизонтальная, 1200×630.'), '§8.5: у обложки и у OG-картинки');
    }

    public function testВкладкаМатериалыОстаётсяОтмеченной(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertStringContainsString('aria-label="Экраны раздела «Новости»"', $page['body']);
        self::assertStringContainsString('href="/news/admin/items" aria-current="page"', $page['body']);
    }

    public function testФормаПравкиВозвращаетСохранённое(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Поступление в 5 класс', 'postuplenie-v-5-klass'), self::EDITOR)['id'];
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Правка материала — админка</title>', $page['body']);
        self::assertStringContainsString('value="Поступление в 5 класс"', $page['body']);
        self::assertStringContainsString('Поступление в 5 класс', $page['body']);
        self::assertStringContainsString('опубликована', $page['body'], 'Статус обязан быть назван словами');
        self::assertStringContainsString('href="/news/postuplenie-v-5-klass"', $page['body'], 'Ссылка на карточку с формы');
    }

    public function testЧужойАдресФормыУводитВСписокСОтказом(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '/net-takogo-id');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('notice=migration-failed', $page['headers']['location']);
        self::assertStringNotContainsString('error=', $page['headers']['location'],
            'Причина отказа не уезжает в адрес: её печатает плашка из flash, а адресная строка — нет');
    }

    // ── Создание ──────────────────────────────────────────

    public function testПустаяФормаОтказываетсяИВозвращаетНабранное(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'ок',
            'slug' => '',
            'categoryId' => '',
            'lead' => 'Что я успел написать перед тем, как нажать кнопку',
        ]));

        self::assertSame(200, $response['status'], 'Отказ формы остаётся на том же экране');
        self::assertStringContainsString('role="alert"', $response['body']);
        self::assertStringContainsString('Заголовок короче ' . fieldLimit(NEWS_FIELDS, 'title', 'min') . ' символов', $response['body']);
        self::assertStringContainsString('Выберите рубрику', $response['body']);
        self::assertStringContainsString('value="ок"', $response['body'], '§8.3: набранный текст возвращается в форму');
        self::assertStringContainsString('Что я успел написать', $response['body']);
    }

    public function testЧерновикСохраняетсяБезТекстаИНеПопадаетВЛенту(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Заметка без текста',
            'slug' => 'zametka-bez-teksta',
            'body' => '',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=created', $response['headers']['location']);

        $feed = Kernel::get('/news');
        self::assertStringNotContainsString('Заметка без текста', $feed['body']);
        self::assertSame(404, Kernel::get('/news/zametka-bez-teksta')['status']);
    }

    public function testПечатьБезТекстаОтказывается(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Недописанный материал',
            'slug' => 'nedopisannyj-material',
            'body' => '',
            'intent' => 'publish',
        ]));

        self::assertSame(200, $response['status']);
        self::assertStringContainsString(NEWS_PUBLISH_NEEDS_TEXT, $response['body']);
        self::assertStringContainsString('value="Недописанный материал"', $response['body']);
        self::assertNull(value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['nedopisannyj-material']));
    }

    public function testЗанятыйАдресОтказываетсяНаМесте(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Второй осенний бал',
            'slug' => 'Осенний бал',
        ]));

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('role="alert"', $response['body']);
        self::assertStringContainsString('value="Второй осенний бал"', $response['body'], 'Набранное обязано остаться');
    }

    public function testСлужебныйАдресРазделаНеЗанимается(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields(['slug' => 'admin']));

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('занят служебной страницей раздела', $response['body']);
    }

    public function testПечатьДелаетМатериалВидимым(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Выпускной 2026',
            'slug' => 'vypusknoj-2026',
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=published', $response['headers']['location']);
        self::assertStringContainsString('Выпускной 2026', Kernel::get('/news')['body']);
        self::assertStringContainsString('Выпускной 2026', Kernel::get('/news/vypusknoj-2026')['body']);
    }

    public function testСрокВБудущемЭтоРасписание(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Ёлка через неделю',
            'slug' => 'elka-cherez-nedelyu',
            'publishedAt' => date('Y-m-d\TH:i', time() + 604800),
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=scheduled', $response['headers']['location']);
        self::assertStringNotContainsString('Ёлка через неделю', Kernel::get('/news')['body']);
    }

    public function testОбратныйАдресСохраняетсяПослеЗаписи(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Родительское собрание',
            'slug' => 'roditelskoe-sobranie',
            'back' => NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT',
        ]));

        self::assertSame(302, $response['status']);
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT&notice=created',
            $response['headers']['location'],
            'Действие обязано вернуть в ту же вкладку и на ту же страницу'
        );
    }

    // ── Правка ────────────────────────────────────────────

    public function testПравкаЧерновикаВозвращаетПлашкуОбОбновлении(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Правимый черновик', 'pravimyj-chernovik', [
            'status' => NEWS_STATUS['DRAFT'],
            'body' => [],
        ]), self::EDITOR)['id'];

        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->editFields($id, [
            'title' => 'Правимый черновик 2',
            'slug' => 'pravimyj-chernovik',
            'status' => NEWS_STATUS['DRAFT'],
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=updated', $response['headers']['location']);
    }

    public function testСменаАдресаЗапоминаетПрежний(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Старый заголовок', 'staryj-zagolovok'), self::EDITOR)['id'];

        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->editFields($id, [
            'title' => 'Старый заголовок',
            'slug' => 'novyj-zagolovok',
            'status' => NEWS_STATUS['PUBLISHED'],
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=slugChanged', $response['headers']['location']);

        $old = Kernel::get('/news/staryj-zagolovok');
        self::assertSame(301, $old['status']);
        self::assertStringContainsString('/news/novyj-zagolovok', $old['headers']['location']);
    }

    public function testПравкаУдалённойСтрокиУводитВСписок(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Удалённый материал', 'udalennyj-material'), self::EDITOR)['id'];
        run('DELETE FROM ' . t('news_item') . ' WHERE id = ?', [$id]);

        // Поля берутся из формы создания: форма правки удалённой строки уже не отдаст токен.
        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->fields());

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringNotContainsString('error=', $response['headers']['location'],
            'Причина отказа не уезжает в адрес: её печатает плашка из flash, а адресная строка — нет');
    }

    // ── Загрузка обложки ──────────────────────────────────

    public function testКартинкаЛожитсяВКаталогЗагрузокИОтдаётсяПоАдресу(): void
    {
        $this->login();
        $png = dirname(__DIR__, 2) . '/public/assets/og-default.png';

        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Бал с обложкой',
            'slug' => 'bal-s-oblozhkoj',
            'cover_file' => new CURLFile($png, 'image/png', 'bal.png'),
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        parse_str((string) parse_url($response['headers']['location'], PHP_URL_QUERY), $query);

        $id = (string) value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['bal-s-oblozhkoj']);
        $cover = (string) itemForForm($id)['cover'];

        self::assertMatchesRegularExpression('#^/uploads/news/[0-9a-z]{26}\.png$#', $cover);
        self::assertFileExists(dirname(__DIR__, 2) . '/public' . $cover);
        self::assertSame(200, Kernel::get($cover)['status']);
        self::assertNotSame('', (string) $query['notice']);
    }

    public function testТекстПодВидомКартинкиНеСохраняется(): void
    {
        $this->login();
        $fake = tempnam(sys_get_temp_dir(), 'cms-fake-') . '.php.png';
        file_put_contents($fake, "<?php echo 'привет';");

        $before = count(self::uploadFiles());
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Мнимая картинка',
            'slug' => 'mimaya-kartinka',
            'cover_file' => new CURLFile($fake, 'image/png', 'podrobnyj.php.png'),
        ]));
        unlink($fake);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('jpg, png или webp', $response['body']);
        self::assertCount($before, self::uploadFiles(), 'Отказ не оставляет файла на диске');
    }

    public function testНоваяКартинкаПеретираетПутьОбложки(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Материал с обложкой', 'material-s-oblozhkoj', [
            'cover' => '/uploads/news/staroe-imenya-net.png',
        ]), self::EDITOR)['id'];

        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->editFields($id, [
            'title' => 'Материал с обложкой',
            'slug' => 'material-s-oblozhkoj',
            'tags' => 'бал, обложка',
            'cover_file' => new CURLFile(dirname(__DIR__, 2) . '/public/assets/og-default.png', 'image/png', 'novoe.png'),
        ]));

        self::assertSame(302, $response['status']);
        self::assertMatchesRegularExpression(
            '#^/uploads/news/[0-9a-z]{26}\.png$#',
            (string) itemForForm($id)['cover']
        );
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsItemFormTest`
Expected: FAIL — `/news/admin/items/new` отвечает 404: маршрутов формы ещё нет.

**Пять ловушек этого теста:**

- `$this->fields()` берёт токен со страницы формы, а `testАнонимСPostНаФормуПолучаетЗапрет` — нет:
  аноним обязан упереться в 403 **до** `csrfCheck()`, иначе проверка токена выдала бы существование
  адреса тому, кому адрес не показан.
- `testПравкаУдалённойСтрокиУводитВСписок` удаляет строку прямым `run()`: набор `http` ходит в тот же
  MySQL, что и тест, поэтому база меняется из теста. Это единственное место задачи, где запрос пишется
  в тесте, и `SqlSafetyTest` каталог `tests/` не сканирует.
- `testСрокВБудущемЭтоРасписание` считает срок `time() + 604800`: формат `datetime-local` разбор
  `parsePublishedAt()` читает по московским часам, а разница между UTC и Москвой на неделю вперёд
  на результат не влияет.
- `count(self::uploadFiles())` до и после мнимой картинки — единственная проверка, что файл не лёг на
  диск: отказ формы виден и по тексту, а осиротевший файл на боевом сервере не увидит никто.
- `tearDownAfterClass` удалит все картинки из каталога загрузок. Так и задумано: набор `http` —
  единственный, кто туда пишет, а `.gitkeep` расширением не является и остаётся.

- [ ] **Шаг 4: Контроллеры экрана, POST-действие и маршруты**

Дополнить `apps/news/admin.php` (после `newsAdminItems()`) тремя функциями:

```php
// Экран формы. Создание и правка различаются адресом, поэтому `$params['id']` — единственное,
// что говорит контроллеру, новую строку показывать или сохранённую.
//
// Право на переход проверяет гейт маршрута (`permission => 'login'`), а POST-действие перечитывает
// `getEditorAccess()`: роль и блокировка могли измениться посреди открытой формы, и имя автора
// обязано соответствовать тому, кто сейчас за клавиатурой.

/** @param array<string, string> $params */
function newsItemNew(array $params = []): void
{
    renderNewsItemForm([], [], [], newsAdminBackHref((string) queryString('back', '')));
}

/** @param array<string, string> $params */
function newsItemEdit(array $params = []): void
{
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, NEWS_ID_MAX);
    $item = itemForForm($id);
    $back = newsAdminBackHref((string) queryString('back', ''));

    if ($item === null) {
        // Строки нет — в форму вести нечего: человек оказывается в списке с тем же текстом отказа,
        // что даёт кнопка строки удалённого материала. Канал один на весь раздел: причина в flash,
        // в адрес — только ключ словаря (тот же `finishAppAction()`, что и кнопки списка).
        finishAppAction(itemFailure('Материала уже нет — обновите страницу.'), $back, '');
    }

    renderNewsItemForm($item, [], [], $back);
}

/**
 * Один экран в трёх исходах: чистая форма, форма после отказа разбора, форма после отказа записи.
 * Везде один и тот же набор имён полей, поэтому `render()` вызывается отсюда, а не из трёх мест:
 * расхождение между ними — это экран, который в одном из исходов теряет половину полей.
 *
 * @param array<string, mixed> $item пустой массив — новый материал
 * @param array<string, string> $raw введённые поля (пусто — значит брать из `$item`)
 * @param array<string, string> $errors отказы по именам полей
 */
function renderNewsItemForm(array $item, array $raw, array $errors, string $back, string $error = ''): void
{
    $isNew = $item === [];
    $status = (string) ($item['status'] ?? '');

    render('news/admin/item-form', [
        'nav' => newsAdminNav(NEWS_ADMIN_ITEMS_PATH),
        'notice' => (string) (noticeOfRequest(NEWS_NOTICE_TEXT) ?? ''),
        'error' => $error,
        'errors' => $errors,
        'values' => newsFormValues($item, $raw),
        'buttons' => newsFormButtons($status),
        'action' => $isNew ? NEWS_ITEM_NEW_PATH : (string) $item['editHref'],
        'back' => $back,
        'isNew' => $isNew,
        'statusLabel' => (string) ($item['statusLabel'] ?? ''),
        'publicHref' => (string) ($item['href'] ?? ''),
        'updated' => (string) ($item['updated'] ?? ''),
        'oldSlugs' => count((array) ($item['oldSlugs'] ?? [])),
        'categories' => categoryOptions(),
    ], [
        'title' => ($isNew ? 'Новый материал' : 'Правка материала') . ' — админка',
        'noindex' => true,
    ]);
}
```

`newsAdminNav(NEWS_ADMIN_ITEMS_PATH)`, а не `requestPath()`: у экрана формы своей вкладки нет, он лежит
внутри «Материалов», и точное сравнение адреса в `newsAdminNav()` оставило бы человека без отмеченной
вкладки (это же рассуждение записано в задаче 17 над `newsAdminNav()`).

Создать `apps/news/actions/item.php`:

```php
<?php

declare(strict_types=1);

// Сохранение материала из формы. CSRF первым: до проверки токена нельзя ни узнавать, существует ли
// строка, ни трогать диск.
//
// Успех уходит PRG-редиректом, отказ остаётся на этом же адресе ответом 200: редирект потерял бы
// набранный текст, а §8.3 требует вернуть его в форму.
/** @param array<string, string> $params */
function newsItemSaveAction(array $params = []): void
{
    csrfCheck();

    $access = getEditorAccess();

    if ($access['kind'] !== 'granted') {
        // Гейт маршрута отвечает 403 анониму; здесь та же проверка держит смену роли и блокировку
        // посреди открытой формы.
        forbidden();
    }

    $editor = $access['editor'];
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, NEWS_ID_MAX);
    $back = newsAdminBackHref(postString('back'));
    $item = $id === '' ? [] : (array) itemForForm($id);

    if ($id !== '' && $item === []) {
        // Строку удалили, пока форма была открыта: тот же канал отказа, что у кнопок списка.
        finishAppAction(itemFailure('Материала уже нет — обновите страницу.'), $back, '');
    }

    // Статус решает нажатая кнопка. Скрытое поле `status` приходит раньше кнопки, и его чтение
    // вернуло бы прошлый статус, а не выбранное действие (NEWS_STATUS_BY_INTENT, §8.3).
    $raw = formValues(postAll(), NEWS_FORM_FIELDS);
    $raw['status'] = resolveStatus(postString('intent'), $raw['status']);

    $parsed = parseNewsForm($raw);

    if (isset($parsed['errors'])) {
        renderNewsItemForm($item, $raw, $parsed['errors'], $back);

        return;
    }

    $data = $parsed['data'];
    $file = uploadedFile('cover_file');
    $href = '';

    if ($file !== null) {
        $stored = newsImageStore($file);

        if (($stored['ok'] ?? false) !== true) {
            renderNewsItemForm($item, $raw, ['cover' => (string) $stored['error']], $back);

            return;
        }

        // Присланный файл важнее текстового поля: сервер решил имя и формат, а путь из `cover`
        // человек мог вписать старый.
        $href = (string) $stored['href'];
        $data['cover'] = $href;
    }

    $result = $id === '' ? createItem($data, $editor) : updateItem($id, $data, $editor);

    if (($result['ok'] ?? false) !== true) {
        if ($href !== '') {
            // Файл уже на диске, но на него никто не сослался: удалить дешевле, чем оставить
            // осиротевшую картинку, которую никто не найдёт.
            newsImageDelete($href);
        }

        renderNewsItemForm($item, $raw, [], $back, (string) $result['error']);

        return;
    }

    redirectWithNotice($back, (string) $result['notice']);
}
```

Если PHPStan уровень 6 не сузит `$access` до ветки `granted` (объединение форм он раскрывает
осторожно), проверка выносится в тернарник, а `forbidden()` — в отдельную ветку:

```php
$editor = $access['kind'] === 'granted' ? $access['editor'] : null;

if ($editor === null) {
    forbidden();
}
```

Прежний адрес после замены картинки **не** удаляется: на тот же путь мог ссылаться `ogImage` этого же
материала или другой записи, а ссылка в мессенджере хранит его в своём кеше. Чистка осиротевших файлов
— отдельная задача, а не действие формы.

Изменить `apps/news/routes.php` — после двух подключений задачи 17 добавить третье:

```php
require_once __DIR__ . '/actions/item.php';
```

и четыре строки в конец блока `addRoute()`:

```php
// Форма материала. Пути пишутся строковыми литералами, а не константами: страж
// `CsrfCoverageTest::postHandlers()` ищет POST-обработчик регуляркой
// addRoute('POST', 'путь', 'имя') — путь в виде константы в этот шаблон не попадает, и обработчик
// исчез бы из-под проверки CSRF незаметно.
addRoute('GET', '/news/admin/items/new', 'newsItemNew', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/items/new', 'newsItemSaveAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/news/admin/items/{id}', 'newsItemEdit', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/items/{id}', 'newsItemSaveAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
```

`/news/admin/items/new` и `/news/admin/items/{id}` сосуществуют: `matchRoute()` перед поиском
расставляет точные пути раньше параметрических (`core/router.php`, признак `static`), поэтому создание
не уезжает в правку с `id = 'new'`, а `newsAdminBackHref()` — наоборот — адрес создания списком не
считает и возврат в список из формы даёт чистый путь.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/admin.php && php -l apps/news/actions/item.php && php -l apps/news/routes.php"`
Expected: `No syntax errors detected` трижды.

- [ ] **Шаг 5: Шаблон формы**

Создать `apps/news/templates/admin/item-form.php`. Стиль тот же, что у двух экранов задачи 17: строки
разметки собираются в PHP, печатается один раз через `adminShell()`, и весь файл живёт в `<?php`-режиме
без `<?=` — иначе `OutputEscapingTest` потребовал бы пометку на каждой короткой вставке.

```php
<?php

declare(strict_types=1);

/**
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<array{intent: string, label: string, tone: string}> $buttons
 * @var string $action
 * @var string $back
 * @var bool $isNew
 * @var string $statusLabel
 * @var string $publicHref
 * @var string $updated
 * @var int $oldSlugs
 * @var string $nav
 * @var string $notice
 * @var string $error
 * @var list<array{id: string, title: string, visible: bool}> $categories
 */
$INPUT = 'mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$TITLE_MIN = fieldLimit(NEWS_FIELDS, 'title', 'min');
$textHint = 'Картинка горизонтальная, 1200×630.';

$reserved = '';
foreach (NEWS_RESERVED_SLUGS as $slug) {
    $reserved .= ($reserved === '' ? '' : ', ') . '«' . $slug . '»';
}

// Одна сборка строки поля: подпись, контрол и подсказка, которую заменяет отказ (§8.5 — человек
// видит, что править именно здесь, а не ищет причину в плашке вверху экрана).
$row = static function (array $field, array $values, array $errors) use ($INPUT): string {
    $name = (string) $field['name'];
    $error = (string) ($errors[$name] ?? '');
    $hint = $error !== '' ? $error : (string) ($field['hint'] ?? '');
    $value = (string) ($values[$name] ?? '');
    $invalid = $error === '' ? '' : ' aria-invalid="true"';
    $max = isset($field['max']) ? ' maxlength="' . e((string) $field['max']) . '"' : '';
    $mono = ($field['mono'] ?? false) === true ? ' font-mono' : '';
    $control = $field['type'] === 'textarea'
        ? '<textarea class="' . e($INPUT . $mono) . '" id="f-' . e($name) . '" name="' . e($name) . '"'
            . $max . $invalid . ' rows="' . e((string) ($field['rows'] ?? 4)) . '">' . e($value) . '</textarea>'
        : '<input class="' . e($INPUT . $mono) . '" id="f-' . e($name) . '" name="' . e($name) . '"'
            . ' type="' . e((string) $field['type']) . '" value="' . e($value) . '"' . $max . $invalid . '>';

    return '<div class="space-y-1"><label class="block text-sm" for="f-' . e($name) . '">'
        . '<span class="font-medium">' . e((string) $field['label']) . '</span>' . $control . '</label>'
        . '<p class="' . e($error === '' ? 'text-sm text-ink/60' : 'text-sm text-bad') . '">' . e($hint) . '</p></div>';
};

$two = static function (string $first, string $second): string {
    return '<div class="grid gap-4 sm:grid-cols-2">' . $first . $second . '</div>';
};

$check = static function (array $field, array $values, array $errors): string {
    $name = (string) $field['name'];
    $error = (string) ($errors[$name] ?? '');

    return '<label class="flex items-start gap-2 text-sm">'
        . '<input class="mt-1 size-4 rounded border-ink/20 bg-white" type="checkbox" id="f-' . e($name) . '"'
        . ' name="' . e($name) . '" value="on"' . ($values[$name] === 'on' ? ' checked' : '') . '>'
        . '<span><span class="font-medium">' . e((string) $field['label']) . '</span>'
        . '<span class="block ' . e($error === '' ? 'text-ink/60' : 'text-bad') . '">'
        . e($error !== '' ? $error : (string) $field['hint']) . '</span></span></label>';
};

$categoryOptionsHtml = '';
foreach ($categories as $category) {
    $categoryOptionsHtml .= '<option value="' . e((string) $category['id']) . '"'
        . ((string) $category['id'] === $values['categoryId'] ? ' selected' : '') . '>'
        . e((string) $category['title']) . ((bool) $category['visible'] === false ? ' (скрыта)' : '') . '</option>';
}

$fields = $row([
    'name' => 'title', 'label' => 'Заголовок', 'type' => 'text', 'max' => NEWS_FORM_MAX['title'],
    'hint' => 'От ' . $TITLE_MIN . ' до ' . NEWS_FORM_MAX['title'] . ' символов. По заголовку материал находят в ленте и в поиске.',
], $values, $errors);

$fields .= $two(
    $row([
        'name' => 'slug', 'label' => 'Адрес', 'type' => 'text', 'max' => NEWS_SLUG_MAX, 'mono' => true,
        'hint' => 'Пустое поле — адрес сложится из заголовка. Занятые адреса раздела: ' . $reserved . '.',
    ], $values, $errors),
    '<div class="space-y-1"><label class="block text-sm" for="f-categoryId">'
        . '<span class="font-medium">Рубрика</span>'
        . '<select class="' . e($INPUT) . '" id="f-categoryId" name="categoryId"'
        . (isset($errors['categoryId']) ? ' aria-invalid="true"' : '') . '>'
        . '<option value="">Выберите рубрику</option>'
        . $categoryOptionsHtml
        . '</select></label>'
        . '<p class="' . e(isset($errors['categoryId']) ? 'text-sm text-bad' : 'text-sm text-ink/60') . '">'
        . e((string) ($errors['categoryId'] ?? 'Рубрики заводят в справочнике раздела.')) . '</p></div>'
);

$fields .= $row([
    'name' => 'lead', 'label' => 'Лид', 'type' => 'textarea', 'rows' => 3, 'max' => NEWS_FORM_MAX['lead'],
    'hint' => 'До ' . NEWS_FORM_MAX['lead'] . ' символов. Можно оставить пустым: тогда карточка покажет только заголовок.',
], $values, $errors);

$fields .= $row([
    'name' => 'body', 'label' => 'Текст', 'type' => 'textarea', 'rows' => 16, 'mono' => true,
    'hint' => 'Абзац — блок строк до пустой строки; первая строка «## » задаёт его заголовок, выделение — **звёздочками**. '
        . fieldLimit(NEWS_FIELDS, 'paragraphs') . ' абзацев хватает на лонгрид.',
], $values, $errors);

// Обложка — поле пути и поле файла в одной форме. Файл перетирает путь: имя и расширение решает
// сервер, а текстовое поле остаётся для картинки, которая уже лежит на сайте.
$coverError = (string) ($errors['cover'] ?? '');
$fields .= $two(
    $row([
        'name' => 'tags', 'label' => 'Теги', 'type' => 'text', 'max' => NEWS_FORM_MAX['tags'],
        'hint' => 'Названия через запятую, до ' . fieldLimit(TAG_FIELDS, 'perItem') . ' штук. Новое название заведётся в справочнике само.',
    ], $values, $errors),
    '<div class="space-y-1"><label class="block text-sm" for="f-cover">'
        . '<span class="font-medium">Обложка</span>'
        . '<input class="' . e($INPUT . ' font-mono') . '" id="f-cover" name="cover" type="text" maxlength="'
        . e((string) NEWS_FORM_MAX['cover']) . '" value="' . e($values['cover']) . '"'
        . ($coverError === '' ? '' : ' aria-invalid="true"') . ' placeholder="/uploads/news/…">'
        . '</label>'
        . '<p class="' . e($coverError === '' ? 'text-sm text-ink/60' : 'text-sm text-bad') . '">'
        . e($coverError !== '' ? $coverError : 'Путь картинки этого сайта. ' . $textHint) . '</p>'
        . '<input class="block w-full text-sm" id="f-cover_file" name="cover_file" type="file"'
        . ' accept="image/jpeg,image/png,image/webp">'
        . '<label class="block text-sm text-ink/60" for="f-cover_file">или загрузите файл — jpg, png или webp до '
        . NEWS_IMAGE_MAX_MB . ' МБ</label></div>'
);

$publish = $row([
    'name' => 'publishedAt', 'label' => 'Срок публикации', 'type' => 'datetime-local',
    'hint' => 'Пустое поле значит «сразу». Время считается по Москве: материал появится сам, когда наступит срок.',
], $values, $errors);

$publish .= $check([
    'name' => 'pinned', 'label' => 'Закрепить первым',
    'hint' => 'Материал идёт перед остальными в ленте, в рубрике и в подборке тегов.',
], $values, $errors);

$seo = '<p class="text-sm text-ink/60">Всё это можно оставить пустым: карточка соберёт заголовок и описание из лида.</p>'
    . $row([
        'name' => 'seoTitle', 'label' => 'Заголовок в поисковике', 'type' => 'text', 'max' => NEWS_FORM_MAX['seoTitle'],
        'hint' => 'До ' . NEWS_FORM_MAX['seoTitle'] . ' символов.',
    ], $values, $errors)
    . $row([
        'name' => 'seoDescription', 'label' => 'Описание в поисковике', 'type' => 'textarea', 'rows' => 2,
        'max' => NEWS_FORM_MAX['seoDescription'],
        'hint' => 'До ' . NEWS_FORM_MAX['seoDescription'] . ' символов.',
    ], $values, $errors);

$seo .= $two(
    $row([
        'name' => 'ogImage', 'label' => 'Картинка для ссылок', 'type' => 'text', 'max' => NEWS_FORM_MAX['ogImage'],
        'mono' => true,
        'hint' => 'Путь этого сайта. ' . $textHint . ' Пустая — возьмётся обложка.',
    ], $values, $errors),
    $check([
        'name' => 'noindex', 'label' => 'Не показывать поисковикам',
        'hint' => 'Для служебных материалов: адрес остаётся по ссылке, но в поиск не попадает.',
    ], $values, $errors)
);

$buttonsHtml = '';
foreach ($buttons as $button) {
    $tone = (string) $button['tone'];
    $class = match ($tone) {
        'primary' => 'rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90',
        'danger' => 'rounded-lg border border-bad/30 bg-white px-4 py-2 font-medium text-bad hover:bg-bad/10',
        default => 'rounded-lg border border-ink/20 px-4 py-2 font-medium hover:bg-ink/5',
    };
    $order = (string) $button['intent'] === 'publish' ? ' order-first' : '';

    $buttonsHtml .= '<button class="' . e($class . $order) . '" type="submit" name="intent"'
        . ' value="' . e((string) $button['intent']) . '">' . e((string) $button['label']) . '</button>';
}

// Строка состояния над формой: человек, который правит опубликованный материал, обязан видеть, что
// он уже на сайте, до того как нажмёт кнопку.
$status = $isNew
    ? '<p class="text-sm text-ink/60">Новый материал. «Сохранить черновик» оставит его у вас в списке, '
        . '«На печать» покажет его в ленте сразу или по сроку.</p>'
    : '<p class="text-sm text-ink/60">Статус: <span class="font-medium text-ink">' . e($statusLabel) . '</span>'
        . ' · изменён: ' . e($updated)
        . ($oldSlugs > 0 ? ' · прежних адресов: ' . e((string) $oldSlugs) : '')
        . ' · <a class="underline underline-offset-4 hover:text-ink" href="' . e($publicHref) . '">карточка на сайте</a></p>';

$hidden = '<input type="hidden" name="status" value="' . e($values['status']) . '">'
    . '<input type="hidden" name="back" value="' . e($back) . '">';

$form = '<form class="space-y-6" method="post" action="' . e($action) . '" enctype="multipart/form-data">'
    . csrfField() . $hidden
    . '<section class="space-y-4"><h2 class="font-semibold">Материал</h2>' . $fields . '</section>'
    . '<section class="space-y-4"><h2 class="font-semibold">Выход на сайт</h2>' . $publish . '</section>'
    . '<section class="space-y-4"><h2 class="font-semibold">Для поисковика</h2>' . $seo . '</section>'
    . '<div class="flex flex-wrap items-center gap-2">' . $buttonsHtml . '</div>'
    . '<p class="text-sm"><a class="underline underline-offset-4 hover:text-ink" href="' . e($back) . '">'
    . 'К списку материалов</a></p></form>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => '', 'reason' => $error])
    . adminShell($isNew ? 'Новый материал' : 'Правка материала', $status . $nav . $form, $notice);
```

Три вещи в этом шаблоне обязательны, и каждая — находка стража:

- `$row()` читает пределы из `NEWS_FORM_MAX` и `fieldLimit()`, а не пишет числа в разметку (§8.1:
  «литерал в разметке — находка стража»). Предел `slug` взят из `NEWS_SLUG_MAX`, потому что у `slug`
  нет ключа в `NEWS_FORM_MAX` — его режет `slugify()`.
- `$categoryOptionsHtml` собирается **до** `$fields`, где используется: PHP вычисляет строку в момент
  конкатенации, и блок после `$fields` означал бы пустой список рубрик в отказе — форма не вернула бы
  выбранную рубрику. Переставлять этот порядок назад нельзя.
- `name="intent"` на обеих кнопках, а не отдельная форма на каждую, как в списке: здесь одно поле
  решает намерение, и скрытое `status` — страховка на случай, если браузер не добавил значение кнопки
  (тогда материал остаётся в том же статусе, в каком был).

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/templates/admin/item-form.php"`
Expected: `No syntax errors detected`.

- [ ] **Шаг 6: Кнопка «Новый материал» и ссылка «Править»**

Изменить `apps/news/templates/admin/summary.php` — в строку со ссылками после панели добавить создание:

```php
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm text-cream hover:opacity-90" '
    . 'href="' . e(NEWS_ITEM_NEW_PATH) . '">Новый материал</a>'
```

перед существующей ссылкой «Открыть список», и в `@var`-блок шаблона ничего дописывать не нужно:
адрес строится константой из `lib/form.php`, которую подключает `lib/boot.php`.

Изменить `apps/news/templates/admin/items.php` — в цикле строк, после того как собраны `$buttons`
из кнопок действия:

```php
    // Ссылка «Править» ведёт на форму задачи 18 и несёт обратный адрес: вернувшись из сохранения,
    // человек попадает в тот же список — с той же вкладкой, рубрикой и страницей. Она добавляется
    // уже после проверки пустоты: иначе сама заполнила бы $buttons, и «Действий нет» не показался
    // бы никогда.
    $buttons .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 text-sm hover:bg-ink/5" href="'
        . e(queryAppend((string) $item['editHref'], 'back', $listing['href'])) . '">Править</a>';
```

Порядок важен: `$buttons` проверяется на пустоту («Действий нет») до этой строки, поэтому ссылка
оказывается **после** проверки — иначе ссылка сама заполнила бы `$buttons`, и «Действий нет» не
показался бы никогда.

Заодно в задаче 17 была оговорка, что ссылка заголовка строки ведёт на публичную страницу материала,
а адрес правки появится вместе с формой. Он появился: `$item['editHref']` строился `adminItemRow()`
с задачи 13 и до этого не использовался нигде.

- [ ] **Шаг 7: Каталог загрузок и его защита**

Создать `public/uploads/news/.gitkeep` — пустой файл.

Создать `public/uploads/.htaccess`:

```apache
# Каталог загрузок принимает файлы, которые пришли от людей. PHP в нём не исполняется: картинка,
# переименованная в .php, обязана отдаваться текстом ошибки или 404, а не выполнять код.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>

<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phar)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# Список файлов каталога не раздаётся.
Options -Indexes
```

Голый `Require all denied` здесь стоять не может: на Apache 2.2 это неизвестная директива, то есть
500 на весь сайт, а не отказ по каталогу. Тот же мотив, что и в корневом файле (задача 1, шаг 4),
его же формой пользуется и страж `tests/guard/WebServerConfigTest.php` — см. ниже, вторая проверка
файла.

Этот блок живёт здесь, а не в корневом `public/.htaccess` (задача 1, шаг 4): в корне он выключал бы PHP на всём
сайте, включая `public/index.php`, — поэтому в задаче 1 его сознательно не было.

Барьеры этого файла `php -S` не читает, поэтому их стережёт тот же страж, что и корневой `.htaccess`.
Изменить `tests/guard/WebServerConfigTest.php` — добавить константу, тест и разбор:

```php
    private const UPLOADS_CONFIG = 'public/uploads/.htaccess';

    // Каталог загрузок — единственное место, куда ложится то, что принесли люди. Проверяется тем же
    // механизмом, что и корень: голый «Require» без обёртки роняет сайт 500-м, а отказ, который не
    // накрывает php-расширения, оставляет исполняемый файл по адресу картинки.
    public function testКаталогЗагрузокДержитСвоиБарьеры(): void
    {
        $path = dirname(__DIR__, 2) . '/' . self::UPLOADS_CONFIG;

        self::assertFileExists($path, 'У каталога загрузок нет .htaccess: PHP в нём исполняется');

        $config = (string) file_get_contents($path);
        self::assertSame(
            [],
            $this->uploadsViolations($config, self::UPLOADS_CONFIG),
            'Барьеры каталога загрузок обязаны быть на месте и в форме, живой на хостинге'
        );
    }

    /** @return list<string> */
    private function uploadsViolations(string $config, string $relative): array
    {
        $found = [];

        if (preg_match('/^\s*Options\b[^\n]*-Indexes/m', $config) !== 1) {
            $found[] = $relative . ': нет Options -Indexes — содержимое каталога отдаётся списком';
        }

        if (! $this->hasDeniedWithFallback($config)) {
            $found[] = $relative . ': нет отказа с обёрткой mod_authz_core и запасной веткой — неизвестная директива роняет сайт 500-м';
        }

        if (preg_match('/<FilesMatch[^>]*php/i', $config) !== 1) {
            $found[] = $relative . ': отказ не накрывает php-расширения — файл, переименованный в .php, остаётся исполняемым';
        }

        return $found;
    }
```

Проверить силу: в образце шага 7 заменить обёрнутый отказ на `Require all denied` — находка обязана
появиться ровно одна; затем вернуть как было. На пустом тексте краснеют все три.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter WebServerConfigTest`
Expected: PASS — четыре метода (три корневых + каталог загрузок).

Изменить `.gitignore` — в блок каталога загрузок, сразу после `!/public/uploads/.htaccess` из задачи 1, добавить три строки правила:

```
# Каталог обложек «Новостей» обязан быть в репозитории пустым: без него первая же загрузка падает.
# Маска `/public/uploads/*` до содержимого этого каталога не достреливает (`*` не переходит через
# `/`), а разрешение каталога возвращает git внутрь него — поэтому содержимое запрещено своей
# маской, и только потом `.gitkeep` включён обратно по имени.
!/public/uploads/news/
/public/uploads/news/*
!/public/uploads/news/.gitkeep
```

Порядок этих трёх строк значим: решает последнее совпадение, поэтому `!/public/uploads/news/.gitkeep`
обязан идти после маски содержимого, иначе пустой каталог исчезнет из репозитория. Проверка —
страж `tests/guard/UploadsIgnoreTest.php`, он перечитывает правила `.gitignore` и отвечает на вопрос
«что git считает игнорируемым», а не «есть ли нужная строка в файле».

Run: `docker compose -f docker-compose.dev.yml run --rm -T php php -r 'require "config.php"; require "core/bootstrap.php"; echo is_dir("public/uploads/news") ? "есть" : "нет", PHP_EOL;'`
Expected: `есть` (после `git add` каталога с `.gitkeep`).

- [ ] **Шаг 8: Разрешить `\CURLFile` в транспортном помощнике**

Изменить аннотацию `Kernel::post()` (`tests/http/Kernel.php:1964`):

```php
    /** @param array<string, string|\CURLFile> $fields */
    public static function post(string $path, array $fields = [], array $options = []): array
```

Значение `array<string, string>` отсекало бы `\CURLFile` на уровне PHPStan, а `CURLOPT_POSTFIELDS`
принимает его нативно и сам переключает запрос на `multipart/form-data`. Меняется только аннотация:
`self::request()` передаёт массив дальше без разбора, и `$options` остаётся `array<string, mixed>`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsItemFormTest`
Expected: PASS — 23 проверки, все новые: форма создаёт, правит и принимает файл.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsAdminTest`
Expected: PASS — 24 проверки. Одна из них утверждает, что в списке **нет** ссылки на справочники
задачи 19; кнопки этой задачи её не ломают.

- [ ] **Шаг 9: Прогнать ворота целиком**

Run: `bash scripts/gate.sh`
Expected: `php -l`, PHPStan (уровень 6, без ошибок), PHP-CS-Fixer (без правок) и все четыре набора —
зелёные. Отдельно смотреть:

- `tests/guard/CsrfCoverageTest.php` — `newsItemSaveAction()` обязан найтись среди POST-обработчиков
  (для этого пути в `addRoute()` пишутся литералами — см. шаг 4) и пройти проверку `csrfCheck()`;
  единственная `method="post"` форма `item-form.php` несёт своё `csrfField()`.
- `tests/guard/OutputEscapingTest.php` — шаблон не печатает ни одного `<?= ?>`: вся разметка собирается
  строками и уходит одним `echo` с пометкой `@safe html`. Пометка стоит на строке с `adminShell(`,
  что входит в белый список стража.
- `tests/guard/SqlSafetyTest.php` — `lib/upload.php` не знает про базу, а действия формы пишут через
  `createItem()`/`updateItem()`; новых `SELECT` в шаблонах и контроллерах нет.
- `tests/guard/RouteHandlersTest.php` — четыре новых адреса зарегистрированы, обработчики callable,
  все четыре лежат под `Disallow: /news/admin`.
- PHPStan уровень 6. Два места, где он чаще всего придирается: `$access['editor']` после проверки
  `kind` (выход — тернарник из шага 4) и `$parsed['data']` после `isset($parsed['errors'])` — проверка
  обязана оставаться `isset()`, а не присваиванием `$errors = $parsed['errors'] ?? []`: через переменную
  объединение форм не сужается.

- [ ] **Шаг 10: Проверить экран руками и закоммитить**

Run:
```bash
docker compose -f docker-compose.dev.yml up -d db
bash scripts/db-reset
rm -f public/install.lock
docker compose -f docker-compose.dev.yml run --rm -T -e CMS_DB_NAME=cms_test php php scripts/make-og-default.php
docker compose -f docker-compose.dev.yml run --rm -T --service-ports -e CMS_DB_NAME=cms_test php php -S 0.0.0.0:8080 -t public public/index.php
# следующим окном, когда стенд отвечает: ядро ставится формой http://localhost:8080/setup.php
docker compose -f docker-compose.dev.yml run --rm -T -e CMS_DB_NAME=cms_test php php -r 'require "config.php"; require "core/bootstrap.php"; installApp("news");'
```

Команды те же, что в шаге 9 задачи 17, и правила те же: `db-reset` — на хосте, замок снять вручную,
установщик ядра — только форма (у `public/setup.php` нет ветки разбора аргументов),
`-e CMS_DB_NAME=cms_test` и `--service-ports` обязательны, у `php -S` — скрипт-роутер.

Открыть `http://localhost:8080/news/admin/items`, войти `admin@school.dev` и пройти путь руками:

- «Новый материал» открывает пустую форму, Enter в заголовке сохраняет **черновик**, а не печать;
- печать без текста отказывает на месте, набранное остаётся в полях;
- загруженная картинка появляется в `/uploads/news/…`, карточка показывает её в ленте, а отказ
  («подписать .php как .png») не оставляет файла на диске;
- «Править» из списка, открытого с фильтром по рубрике, возвращает в тот же фильтр с зелёной плашкой;
- на 360 px поля складываются в одну колонку, кнопки переносятся, `datetime-local` остаётся
  читаемым — требование адаптивности 360…1440 касается и этой формы;
- `Ctrl+R` после сохранения не просит «повторить отправку формы» (PRG).

Run:
```bash
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git add apps/news/lib/form.php apps/news/lib/upload.php apps/news/lib/boot.php \
  apps/news/admin.php apps/news/actions/item.php apps/news/routes.php \
  apps/news/templates/admin/item-form.php apps/news/templates/admin/summary.php \
  apps/news/templates/admin/items.php public/uploads/.htaccess public/uploads/news/.gitkeep \
  public/assets/app.css \
  .gitignore tests/http/Kernel.php tests/guard/WebServerConfigTest.php \
  tests/guard/UploadsIgnoreTest.php \
  tests/unit/NewsFormRulesTest.php tests/http/NewsItemFormTest.php
git commit -m "Форма материала «Новостей»: создание, правка и загрузка обложки"
```

Коммит трогает три файла, которые кажутся чужими этой задаче: `public/uploads/.htaccess`,
`public/uploads/news/.gitkeep` и `.gitignore`. Все три — следствия приёма файлов, и разносить их по
разным коммитам нельзя. `public/uploads/.htaccess` выключает исполнение PHP в каталоге загрузок: если
он попадёт в репозиторий позже загруженного файла, между ними на боевом хостинге лежит каталог, куда
редактор уже может записать исходник. `.gitkeep` и три строки правила в `.gitignore` нужны, чтобы каталог
загрузок не пропал из репозитория при правиле `/public/uploads/*` — пустой каталог git не хранит, а без
каталога `move_uploaded_file()` падает на ровном месте.

Позиция `public/assets/app.css` в этом списке — не копия пояснения к задаче 17, а проверка, сделанная
по разметке этой задачи. Классы `items-start` и `size-4` из строки чекбокса обложки (шаг 5,
`apps/news/templates/admin/item-form.php`) в собранном артефакте из `HEAD` отсутствуют: у каждого
ноль вхождений, тогда как в списке `@source` файл новой формы попадает под `../apps/**/templates/**/*.php`
и сборка обязана их породить. Значит пересборка (`bash scripts/css` на хосте) обязательна, а артефакт
обязан уехать в тот же коммит, что и разметка, — иначе первый шаг ворот на чистой копии сравнит свежую
сборку с устаревшим файлом и покраснеет. Если шаг свежести назовёт ещё классы, их пересобирают тем же
`bash scripts/css`, и результат идёт в этот же коммит: правило «артефакт и разметка, которая его
породила, не разъезжаются по коммитам» сильнее экономии на diff.
