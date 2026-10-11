### Задача 19: Экраны справочников — рубрики и теги

**Файлы:**
- Создать: `apps/news/actions/catalog.php` (девять действий двух справочников)
- Изменить: `apps/news/lib/admin.php` (четыре вкладки, адреса экранов, списки действий, подписи,
  кнопки строки)
- Изменить: `apps/news/admin.php` (два контроллера — тела не читают базу)
- Изменить: `apps/news/routes.php` (четыре адреса: GET и POST на каждый экран)
- Создать: `apps/news/templates/admin/categories.php`, `apps/news/templates/admin/tags.php`
- Изменить: `apps/news/templates/admin/summary.php` (панели «Рубрики» и «Теги»)
- Изменить: `tests/unit/NewsAdminRulesTest.php` (`assertCount(2, NEWS_ADMIN_TABS)` → 4)
- Тест: `tests/unit/NewsCatalogRulesTest.php`, `tests/http/NewsCatalogTest.php`

**Порт:** `english-portal/src/app/news/admin/categories/page.tsx`, `admin/tags/page.tsx` и
`english-portal/src/apps/news/server/catalog.ts` (девятка действий `create, rename, describe,
visibility, up, down, delete, tagRename, tagDelete`). Источник истины по правилам — задачи 12–13,
где эти функции уже написаны и проверены; здесь только экраны, которые их вызывают.

**Разметка и зона нажатия.** Разметка экранов в этой задаче — образец, а не готовое предписание:
каждый контроль обязан нести класс `min-h-[44px]` (страж `tests/guard/TapTargetTest.php`), а строки
предписанного размеченного кода без него — ошибка текста плана, а не повод убрать класс. Так сделаны
экраны задачи 17: в `apps/news/templates/admin/items.php` класс стоит на каждом контроле.

**Интерфейсы:**
- Потребляет:
  - `core/template.php`: `render()`; `core/escape.php`: `e()`; `admin/layout.php`: `adminShell()`
  - `core/csrf.php`: `csrfCheck()`, `csrfField()`; `core/notice.php`: `noticeForAdminScreen()`,
    `takeAdminFlashReason()`, `adminFailureTitle()` (все три — задача 17, тот же канал отказа, что у
    экранов списка)
  - `core/request.php`: `requestPath()`, `queryString()`, `postString()`
  - `core/response.php`: `redirect()`; `core/routes.php`: `finishAppAction()`
  - `core/text.php`: `plural()`
  - `apps/news/lib/constants.php`: `CATALOG_NOTICE_TEXT`, `CATEGORY_FIELDS`, `TAG_FIELDS`, `fieldLimit()`
  - `apps/news/lib/catalog.php`: `adminCategoryRows()`, `adminTagRows()`, `createCategory()`,
    `renameCategory()`, `updateCategoryDescription()`, `toggleCategoryVisibility()`, `deleteCategory()`,
    `moveCategory()`, `renameTag()`, `deleteTag()`
  - `apps/news/lib/feed.php`: `newsHref()`; `apps/news/lib/items.php`: `NEWS_ID_MAX`
  - `apps/news/lib/admin.php` (задача 17): `NEWS_ADMIN_PATH`, `NEWS_ADMIN_TABS`, `newsAdminNav()`
  - `Cms\Tests\http\Kernel`, `Cms\Tests\TestDb`, `installApp()`, `createItem()`, `categoryIdOf()`,
    `nowUtc()`
- Производит:

```
const NEWS_ADMIN_CATEGORIES_PATH = '/news/admin/categories';
const NEWS_ADMIN_TAGS_PATH = '/news/admin/tags';

const NEWS_CATEGORY_OPS = ['create', 'rename', 'describe', 'show', 'hide', 'up', 'down', 'delete'];
const NEWS_CATEGORY_OP_LABEL = ['create' => 'Добавить рубрику', 'rename' => 'Переименовать', 'describe' => 'Сохранить описание', 'show' => 'Показать', 'hide' => 'Скрыть', 'up' => 'Вверх', 'down' => 'Вниз', 'delete' => 'Удалить'];
const NEWS_TAG_OPS = ['rename', 'delete'];
const NEWS_TAG_OP_LABEL = ['rename' => 'Переименовать', 'delete' => 'Удалить'];

newsCategoryRowActions(array $row): list<array{op: string, label: string}>
newsTagRowActions(array $row): list<array{op: string, label: string}>
catalogFailure(string $error): array{ok: false, notice: '', error: string}
newsAdminCategories(array $params = []): void
newsAdminTags(array $params = []): void
newsCategoriesAction(array $params = []): void
newsTagsAction(array $params = []): void
```

Задача 20 читает эти четыре константы: её страж сверяет обещанные действия с тем, что реально
разбирают `match ($op)` в файлах действий, и с текстами уведомлений.

**Пять правил этого экрана:**

1. **Ни одного нового запроса к базе.** Все выборки уже сделаны в `lib/catalog.php` (задача 13),
   где они проверены на базе. Контроллер вызывает `adminCategoryRows()` и `adminTagRows()`, шаблон
   печатает то, что ему дали. Страж видимости (задача 20) не должен искать оправдания для нового
   файла.
2. **Кнопка — это подпись, а не разрешение.** «Удалить» стоит и на непустой рубрике: сервер
   отвечает текстом «В рубрике 3 материала. Удалить её нельзя…», и человек читает причину, а не
   молча не находит кнопки. То же с тегами. Это ровно то правило, которым задача 17 объясняла
   кнопки строки списка.
3. **Адреса справочников не меняются.** Переименование рубрики и тега правит только `title` /
   `label` (`renameCategory()`, `renameTag()`), и экран обязан это показать: в строке рядом с
   названием печатается её адрес. Критерий §12.14 и §12.15 проверяют именно это.
4. **Поля формы не хранят набранное при отказе.** Отказ — редирект со списком, где в адресе лежит
   только ключ словаря, а причину экран печатает из сессионного flash, поэтому после неудачного
   «Сохранить» поля пусты. Здесь это осознанно и ограничено по объёму: в
   справочнике правится одна короткая строка (название или описание до 300 символов), и текст
   отказа называет причину. Форма материала — другое дело, там набранное возвращает задача 18.
   Расхождение записано в «Отступления от спецификации» (part1) и в списке ниже.
5. **Своей кнопки «Добавить тег» нет.** Тег рождает форма материала (`resolveTagIds()`), в
   справочнике его только переименовывают и удаляют пустой (§8.4, критерий §12.15). Экран, который
   обещает то, чего действие не делает, был бы хуже отсутствующей кнопки.

**Расхождения с портируемым кодом:**

- В портале справочники открывались экраном конструктора блоков и упоминали его в подсказке.
  Пилот конструктора не имеет (не-цели, §3), поэтому текст подсказки говорит только про адрес и
  про то, где рубрика видна читателю.
- `visibility` из портала — одно действие с флагом. Здесь два намерения (`show`, `hide`) и одно
  действие `toggleCategoryVisibility()`: экран печатает ту подпись, что соответствует состоянию
  строки, а уведомление всегда описывает новое состояние, а не намерение. Разойтись они могут
  только на устаревшей странице, и ответ на ней остаётся правдой.
- Порядок меняется кнопками «Вверх»/«Вниз» на каждой строке, включая крайние: у первой «Вверх»
  отвечает «Эта рубрика уже первая.», у последней «Вниз» — «Эта рубрика уже последняя.». Ключи
  `topped` и `bottomed` в `CATALOG_NOTICE_TEXT` нужны именно для этого; кнопкой в `disabled`
  крайние строки не делают — без JavaScript `disabled` не сняться, а серверная проверка остаётся
  единственной инстанцией.
- Поле `back` списка материалов здесь не нужно: у справочника нет вкладок, фильтра и страниц,
  поэтому возврат всегда ведёт на адрес этого же экрана. Поля нет — нечего и очищать,
  открытого редиректа на этих двух адресах не возникает в принципе.

- [ ] **Шаг 1: Написать падающий unit-тест правил экрана**

Создать `tests/unit/NewsCatalogRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

// Правила справочников — про договор экрана: какие действия вообще возможны, как они называются
// и какое уведомление обещано каждому. Тексты берутся из CATALOG_NOTICE_TEXT, а не печатаются
// здесь: иначе тест проверял бы сам себя.
use PHPUnit\Framework\TestCase;

final class NewsCatalogRulesTest extends TestCase
{
    public function testВкладокСталоЧетыреИОниВедутНаЭкраныРаздела(): void
    {
        self::assertSame(
            ['/news/admin', '/news/admin/items', '/news/admin/categories', '/news/admin/tags'],
            array_column(NEWS_ADMIN_TABS, 'href')
        );
        self::assertSame(
            ['Сводка', 'Материалы', 'Рубрики', 'Теги'],
            array_column(NEWS_ADMIN_TABS, 'label')
        );
    }

    public function testПолосаВкладокОтмечаетЭкранРубрик(): void
    {
        $nav = newsAdminNav(NEWS_ADMIN_CATEGORIES_PATH);

        self::assertSame(1, substr_count($nav, 'aria-current="page"'));
        self::assertStringContainsString('href="/news/admin/categories" aria-current="page"', $nav);
    }

    public function testДействияРубрикОбещаныРовноВосемь(): void
    {
        self::assertSame(
            ['create', 'rename', 'describe', 'show', 'hide', 'up', 'down', 'delete'],
            NEWS_CATEGORY_OPS
        );
        self::assertSame(NEWS_CATEGORY_OPS, array_keys(NEWS_CATEGORY_OP_LABEL), 'У каждого действия своя подпись');

        foreach (NEWS_CATEGORY_OP_LABEL as $label) {
            self::assertNotSame('', $label);
        }
    }

    public function testДействийТегаДваИСредиНихНетСоздания(): void
    {
        // Критерий §12.15: «создать тег в справочнике нельзя» — проверяется составом действий,
        // пока человек не увидел экран: кнопка «Добавить» здесь была бы обещанием, которое
        // действие не выполняет.
        self::assertSame(['rename', 'delete'], NEWS_TAG_OPS);
        self::assertSame(NEWS_TAG_OPS, array_keys(NEWS_TAG_OP_LABEL));
        self::assertArrayNotHasKey('create', NEWS_TAG_OP_LABEL);
    }

    public function testКаждоеДействиеОбещаетИзвестноеУведомление(): void
    {
        $expected = [
            'create' => ['created'],
            'rename' => ['renamed'],
            'describe' => ['described'],
            'show' => ['shown'],
            'hide' => ['hidden'],
            'up' => ['moved', 'topped'],
            'down' => ['moved', 'bottomed'],
            'delete' => ['deleted'],
            'tagRename' => ['tagRenamed'],
            'tagDelete' => ['tagDeleted'],
        ];

        foreach ($expected as $op => $keys) {
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, CATALOG_NOTICE_TEXT, $op . ' обещает ключ ' . $key);
                self::assertNotSame('', CATALOG_NOTICE_TEXT[$key], $op . ' / ' . $key);
            }
        }
    }

    public function testКнопкиСтрокиРубрикиЗависятОтВидимости(): void
    {
        $visible = ['op' => 'hide', 'label' => 'Скрыть'];
        $hidden = ['op' => 'show', 'label' => 'Показать'];

        $shown = newsCategoryRowActions(['id' => 'x', 'slug' => 'x', 'title' => 'X', 'description' => null, 'order' => 1, 'visible' => true, 'items' => ['total' => 2, 'visible' => 2]]);
        $off = newsCategoryRowActions(['id' => 'x', 'slug' => 'x', 'title' => 'X', 'description' => null, 'order' => 1, 'visible' => false, 'items' => ['total' => 0, 'visible' => 0]]);

        self::assertContains($visible, $shown, 'Видимой рубрике предлагается «Скрыть»');
        self::assertContains($hidden, $off, 'Скрытой — «Показать»');
        self::assertSame(['hide', 'up', 'down', 'delete'], array_column($shown, 'op'));
        self::assertSame(['show', 'up', 'down', 'delete'], array_column($off, 'op'));
    }

    public function testУдалениеЕстьУКаждойСтрокиНезависимоОтСчётчика(): void
    {
        // Правило 2: кнопка — подпись. Право проверяет deleteCategory(), и его отказ обязан
        // быть виден человеком, а не спрятан в разметке.
        $empty = newsTagRowActions(['id' => 'x', 'slug' => 'x', 'label' => 'X', 'items' => 0]);
        $used = newsTagRowActions(['id' => 'x', 'slug' => 'x', 'label' => 'X', 'items' => 4]);

        self::assertSame(['delete'], array_column($empty, 'op'));
        self::assertSame(['delete'], array_column($used, 'op'), 'Тег с материалами тоже получает кнопку — отказ придёт текстом');
    }

    public function testОтказДействияВсегдаИмеетТекст(): void
    {
        $result = catalogFailure('Неизвестное действие с рубрикой.');

        self::assertFalse($result['ok']);
        self::assertSame('', $result['notice'], 'Отказ не несёт ключа уведомления');
        self::assertSame('Неизвестное действие с рубрикой.', $result['error']);
    }

    public function testАдресСтраницыРубрикиСкладываетсяИзЕёАдреса(): void
    {
        self::assertSame('/news/c/zhizn-shkoly', newsHref(['category' => 'zhizn-shkoly']));
        self::assertSame('/news/t/vypusknik', newsHref(['tag' => 'vypusknik']));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsCatalogRulesTest`
Expected: FAIL — `Constant "NEWS_ADMIN_CATEGORIES_PATH" not found`.

Изменить `tests/unit/NewsAdminRulesTest.php` — проверка полоски вкладок задачи 17 ожидывает две
вкладки; заменить в `testПолосаВкладокВедётНаЭкраныРаздела` строку

```php
        self::assertCount(2, NEWS_ADMIN_TABS);
```

на

```php
        self::assertCount(4, NEWS_ADMIN_TABS);
```

(тело метода заменяется одной строкой; остальной тест про каждую вкладку остаётся как есть).

- [ ] **Шаг 2: Правила экрана**

Изменить `apps/news/lib/admin.php` — константу вкладок заменить целиком (`const` нельзя
«дополнить», а unit-тест шага 1 и HTTP-тест задачи 17 читают один и тот же массив):

```php
/** @var list<array{href: string, label: string}> */
const NEWS_ADMIN_TABS = [
    ['href' => NEWS_ADMIN_PATH, 'label' => 'Сводка'],
    ['href' => NEWS_ADMIN_ITEMS_PATH, 'label' => 'Материалы'],
    ['href' => NEWS_ADMIN_CATEGORIES_PATH, 'label' => 'Рубрики'],
    ['href' => NEWS_ADMIN_TAGS_PATH, 'label' => 'Теги'],
];
```

Адреса — константы, а не шесть строковых литералов: `NEWS_ADMIN_PATH` и
`NEWS_ADMIN_ITEMS_PATH` уже так стоят в задаче 17, а две новые ссылки читают те же
`NEWS_ADMIN_CATEGORIES_PATH` и `NEWS_ADMIN_TAGS_PATH` из блока ниже. Литералы разошлись бы с
адресами маршрутов на первом же переименовании, и заметен это стало бы только по `aria-current`.
Порядок вкладок — порядок экрана: справочники стоят после материалов.

Тем же файлом дописать в конец:

```php
// ── Справочники ──────────────────────────────────────────

// Адреса экранов справочников. Их читают вкладки, действия, шаблоны и HTTP-тесты — один раз
// здесь, а не шестью строками в четырёх файлах.
const NEWS_ADMIN_CATEGORIES_PATH = '/news/admin/categories';
const NEWS_ADMIN_TAGS_PATH = '/news/admin/tags';

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
 * шаблон прямо из NEWS_TAG_OP_LABEL.
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
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/admin.php"`
Expected: `No syntax errors detected`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter "NewsCatalogRulesTest|NewsAdminRulesTest"`
Expected: PASS. `NewsAdminRulesTest` зелёный вместе с новым — он ждёт четыре вкладки с этого шага.

- [ ] **Шаг 3: Написать падающий HTTP-тест двух экранов**

Создать `tests/http/NewsCatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Справочники проверяются настоящим запросом: адрес рубрики после переименования, плашка с текстом
// отказа после редиректа и зелёное уведомление действия видны только в ответе сервера.
final class NewsCatalogTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-2', 'email' => 'redaktor@shkola.ru'];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        // Один материал в «Жизни школы» с тегом: рубрика становится непустой (её нельзя удалить),
        // тег — занятым (его нельзя удалить), а пустой справочник остаётся проверять удаление.
        $created = createItem([
            'title' => 'Осенний бал',
            'slug' => 'osennij-bal',
            'lead' => 'Приглашаем на бал.',
            'body' => [['title' => '', 'text' => 'Танцы, песни и чай.']],
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'tags' => ['выпускник'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], self::EDITOR, nowUtc());

        self::assertTrue($created['ok'], (string) $created['error']);

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
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function send(string $path, array $fields): array
    {
        $page = Kernel::get($path);

        return Kernel::post($path, array_merge(['_token' => $this->token($page['body'])], $fields));
    }

    /** Ключ из адреса ответа: `?notice=renamed` или `?notice=migration-failed`. */
    private function noticeOf(array $response): string
    {
        $query = $this->queryOf($response);

        return (string) ($query['notice'] ?? '');
    }

    // Причина отказа живёт в сессионном flash, поэтому её читает экран, куда ведёт редирект, а не
    // заголовок Location. Помощник проверяет и адрес (в нём только ключ словаря), и возвращает тело
    // открытого экрана — так проверка не пропускает случай, когда отказ уехал в адрес.
    private function failureBody(array $response): string
    {
        $location = (string) $response['headers']['location'];
        self::assertStringContainsString('notice=migration-failed', $location);
        self::assertStringNotContainsString('error=', $location,
            'Причина отказа не должна уезжать в адрес: он остаётся в истории браузера и в access-логе');

        return (string) Kernel::get($location)['body'];
    }

    private function locationOf(array $response): string
    {
        $location = (string) ($response['headers']['location'] ?? '');

        return substr($location, strpos($location, '?') === false ? strlen($location) : (int) strpos($location, '?') + 1);
    }

    /** @return array<string, string> */
    private function queryOf(array $response): array
    {
        parse_str($this->locationOf($response), $query);

        return $query;
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаРубрикахУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_CATEGORIES_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fcategories', $page['headers']['location']);
    }

    public function testАнонимНаТегахУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_TAGS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Ftags', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапрет(): void
    {
        $response = Kernel::post(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'create', 'title' => 'Проверка']);

        self::assertSame(403, $response['status']);
    }

    public function testДействиеБезТокенаОтклоняется(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'create', 'title' => 'Без токена']);

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('Проверка формы не прошла', $response['body']);
        self::assertStringNotContainsString('Без токена', $this->body(NEWS_ADMIN_CATEGORIES_PATH), 'Чужая форма не создала рубрику');
    }

    // ── Рубрики ───────────────────────────────────────────

    public function testЭкранРубрикЧитаетсяИНеИндексируется(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_CATEGORIES_PATH);

        self::assertStringContainsString('<title>Рубрики — админка</title>', $body);
        self::assertStringContainsString('noindex, nofollow', $body);
        self::assertStringContainsString('Жизнь школы', $body);
        self::assertStringContainsString('zhizn-shkoly', $body, 'Адрес рубрики печатается в строке');
        self::assertStringContainsString('href="/news/c/zhizn-shkoly"', $body, 'Ссылка на страницу рубрики');
        self::assertStringContainsString('Осенний бал', $this->body(NEWS_ADMIN_ITEMS_PATH . '?cat=' . categoryIdOf('zhizn-shkoly')));
    }

    public function testНоваяРубрикаВстаётВКонецСписка(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'create', 'title' => 'Выпускные', 'description' => '']);

        self::assertSame(302, $response['status']);
        self::assertSame('created', $this->noticeOf($response));

        $rows = adminCategoryRows();
        self::assertSame('Выпускные', (string) end($rows)['title']);
        self::assertSame('vypusknye', (string) end($rows)['slug'], 'Адрес сложен транслитерацией один раз');
    }

    public function testПереименованиеНеМеняетАдрес(): void
    {
        // Критерий §12.14. Адрес остался бы прежним и без теста, но проверить надо именно
        // открытую страницу: переименование, после которого ссылка умерла, выглядит успехом.
        $this->login();
        $id = categoryIdOf('postuplenie');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'rename', 'id' => $id, 'title' => 'Поступление в школу']);

        self::assertSame('renamed', $this->noticeOf($response));

        $page = Kernel::get('/news/c/postuplenie');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Поступление в школу', $page['body']);

        $row = $this->rowOf(adminCategoryRows(), $id);
        self::assertSame('postuplenie', (string) $row['slug'], 'Адрес рубрики не изменился');
    }

    public function testЗанятоеНазваниеОтказываетсяИНеПереименовывает(): void
    {
        $this->login();
        $id = categoryIdOf('metodika');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'rename', 'id' => $id, 'title' => 'Жизнь школы']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('уже есть', $this->failureBody($response));
        self::assertSame('Методика', (string) $this->rowOf(adminCategoryRows(), $id)['title']);
    }

    public function testОписаниеЧитаетсяПодЗаголовкомСтраницы(): void
    {
        $this->login();
        $id = categoryIdOf('zhizn-shkoly');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, [
            'op' => 'describe',
            'id' => $id,
            'title' => 'Жизнь школы',
            'description' => 'Рассказываем про праздники, поездки и школьные будни.',
        ]);

        self::assertSame('described', $this->noticeOf($response));

        $page = Kernel::get('/news/c/zhizn-shkoly');
        self::assertStringContainsString('Рассказываем про праздники, поездки и школьные будни.', $page['body']);
    }

    public function testСкрытиеУбираетСтраницуИМатериалыИзОбщейЛенты(): void
    {
        // Критерий §12.14. Материал остаётся в базе и в рубрике — уходит только из ленты,
        // поэтому проверка читает ленту, а не таблицу.
        $this->login();
        $id = categoryIdOf('zhizn-shkoly');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'hide', 'id' => $id]);

        self::assertSame('hidden', $this->noticeOf($response));
        self::assertSame(404, Kernel::get('/news/c/zhizn-shkoly')['status']);
        self::assertStringNotContainsString('Осенний бал', Kernel::get('/news')['body']);

        $shown = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'show', 'id' => $id]);
        self::assertSame('shown', $this->noticeOf($shown));
        self::assertStringContainsString('Осенний бал', Kernel::get('/news')['body']);
    }

    public function testПорядокМеняетсяКнопкамиАНаКрайнейСтрокеОтвечаетТекстом(): void
    {
        $this->login();
        $order = static fn (): string => implode(',', array_column(adminCategoryRows(), 'slug'));
        $before = $order();

        $moved = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'down', 'id' => categoryIdOf('zhizn-shkoly')]);
        self::assertSame('moved', $this->noticeOf($moved));
        self::assertNotSame($before, $order(), 'Порядок списка изменился');

        $topped = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'up', 'id' => categoryIdOf('zhizn-shkoly')]);
        self::assertSame('moved', $this->noticeOf($topped), 'Кнопка «Вверх» вернула рубрику на первое место');

        $again = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'up', 'id' => categoryIdOf('zhizn-shkoly')]);
        self::assertSame('topped', $this->noticeOf($again), 'У первой рубрики «Вверх» отвечает текстом');
        self::assertSame($before, $order());
    }

    public function testУдалениеНепустойРубрикиОтказываетсяСПонятнымТекстом(): void
    {
        $this->login();
        $id = categoryIdOf('zhizn-shkoly');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame(302, $response['status']);
        $body = $this->failureBody($response);
        self::assertStringContainsString('В рубрике 1 материал', $body);
        self::assertStringContainsString('role="alert"', $body, 'Причина печатается плашкой отказа, а не голым текстом');
        self::assertNotNull($this->rowOf(adminCategoryRows(), $id), 'Рубрика осталась');
    }

    public function testПустаяРубрикаУдаляется(): void
    {
        $this->login();
        $created = createCategory('Кружок рисования');
        self::assertTrue($created['ok'], (string) $created['error']);

        // createCategory() возвращает только «ок / отказ»: идентификатор новой строки берут по
        // её адресу, как это делает экран после редиректа.
        $id = categoryIdOf('kruzhok-riskovaniya');
        self::assertNotSame('', $id, 'Рубрика появилась в базе');

        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame('deleted', $this->noticeOf($response));
        self::assertNull($this->rowOf(adminCategoryRows(), $id));
    }

    public function testЧужогоИлиСтертогоИдентификатораНеЕсть(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'rename', 'id' => 'нет-такого-ident', 'title' => 'Проверка']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('обновите страницу', $this->failureBody($response));
    }

    // ── Теги ──────────────────────────────────────────────

    public function testЭкранТеговПоказываетНадписиИСчётчик(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_TAGS_PATH);

        self::assertStringContainsString('<title>Теги — админка</title>', $body);
        self::assertStringContainsString('выпускник', $body);
        self::assertStringContainsString('href="/news/t/vypusknik"', $body);
        self::assertStringNotContainsString('value="create"', $body, 'Создать тег из справочника нельзя (§12.15)');
    }

    public function testПереименованиеТегаСохраняетЕгоАдрес(): void
    {
        // Критерий §12.15. Надпись русская, адрес — транслит: страница тега обязана открыться
        // по старому адресу с новой надписью.
        $this->login();
        $rows = adminTagRows();
        $tag = $rows[0];
        $response = $this->send(NEWS_ADMIN_TAGS_PATH, [
            'op' => 'rename',
            'id' => (string) $tag['id'],
            'label' => 'Выпускники',
        ]);

        self::assertSame('tagRenamed', $this->noticeOf($response));

        $page = Kernel::get('/news/t/' . $tag['slug']);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Выпускники', $page['body']);
        self::assertSame((string) $tag['slug'], (string) $this->tagRowById((string) $tag['id'])['slug']);
    }

    public function testУдалениеЗанятогоТегаОтказывается(): void
    {
        $this->login();
        $tag = adminTagRows()[0];
        $response = $this->send(NEWS_ADMIN_TAGS_PATH, ['op' => 'delete', 'id' => (string) $tag['id']]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('Тег стоит на 1 материале', $this->failureBody($response));
        self::assertNotNull($this->tagRowById((string) $tag['id']), 'Тег остался');
    }

    public function testПустойТегУдаляется(): void
    {
        // Пустой тег форма материала не рождает, поэтому строку заводят напрямую: проверка
        // удаления справочника, а не создания тегов.
        $this->login();
        $id = newId();
        run(
            'INSERT INTO ' . t('news_tag') . ' (id, slug, label) VALUES (?, ?, ?)',
            [$id, 'pustoj-tag', 'Пустой тег']
        );

        $response = $this->send(NEWS_ADMIN_TAGS_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame('tagDeleted', $this->noticeOf($response));
        self::assertNull($this->tagRowById($id));
    }

    public function testНеизвестноеДействиеОтказываетсяТекстом(): void
    {
        $this->login();
        foreach ([NEWS_ADMIN_CATEGORIES_PATH, NEWS_ADMIN_TAGS_PATH] as $path) {
            $response = $this->send($path, ['op' => 'взломать', 'id' => categoryIdOf('zhizn-shkoly')]);

            self::assertSame(302, $response['status']);
            self::assertStringContainsString('Неизвестное действие', $this->failureBody($response));
        }
    }

    // ── Помощники ─────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function rowOf(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function tagRowById(string $id): ?array
    {
        return $this->rowOf(adminTagRows(), $id);
    }

    private function body(string $path): string
    {
        $response = Kernel::get($path);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsCatalogTest`
Expected: FAIL — 404 на `/news/admin/categories`: адресов справочников ещё нет.

Три места в этом тесте объясняют его сам:

- Отказ читается открытием экрана, куда ведёт `Location`, а не из самого заголовка: текст причины
  живёт в сессионном flash, а в адресе остаётся только ключ `migration-failed` (то же, что в
  задаче 17). Помощник `failureBody()` проверяет и адрес, и тело — поэтому возврат причины в URL
  не пройдёт молча.
- `testСкрытиеУбираетСтраницуИМатериалыИзОбщейЛенты` читает `/news`, а не таблицу: материал
  обязан остаться в базе (§8.4), и только лента различает «скрыт» и «удалён».
- `testПустойТегУдаляется` вставляет строку в `tbl_news_tag` напрямую. Форма материала пустых
  тегов не создаёт, а без живой строки проверка удаления пустого тега была бы проверкой
  отсутствующего идентификатора.

- [ ] **Шаг 4: Контроллеры, действия и маршруты**

Создать `apps/news/actions/catalog.php`:

```php
<?php

declare(strict_types=1);

// Действия двух справочников. CSRF проверяется первым: до проверки токена нельзя ни менять
// базу, ни узнавать, существует ли рубрика по ответу.
//
// Возврат здесь не нужен: у справочника нет вкладок, фильтра и страниц, поэтому редирект ведёт
// на адрес этого же экрана.
//
// Идентификатор режется тем же пределом, что и в фильтрах списка: чужая строка в 400 символов
// не обязана уходить в `WHERE id = ?` целиком.

/** @param array<string, string> $params */
function newsCategoriesAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);

    $result = match ($op) {
        'create' => createCategory(postString('title'), postString('description')),
        'rename' => renameCategory($id, postString('title')),
        'describe' => updateCategoryDescription($id, postString('title'), postString('description')),
        'show' => toggleCategoryVisibility($id),
        'hide' => toggleCategoryVisibility($id),
        'up' => moveCategory($id, 'up'),
        'down' => moveCategory($id, 'down'),
        'delete' => deleteCategory($id),
        default => catalogFailure(catalogUnknown('рубрикой')),
    };

    finishAppAction($result, NEWS_ADMIN_CATEGORIES_PATH, (string) ($result['notice'] ?? ''));
}

/** @param array<string, string> $params */
function newsTagsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);

    $result = match ($op) {
        'rename' => renameTag($id, postString('label')),
        'delete' => deleteTag($id),
        default => catalogFailure(catalogUnknown('тегом')),
    };

    finishAppAction($result, NEWS_ADMIN_TAGS_PATH, (string) ($result['notice'] ?? ''));
}

// Текст отказа печатается на экране и кладётся в журнал, поэтому в нём нет содержимого
// запроса: неизвестное намерение описывается общим словом.
function catalogUnknown(string $what): string
{
    return mb_substr('Неизвестное действие с ' . $what . '.', 0, NEWS_UNKNOWN_ACTION_MAX);
}
```

Изменить `apps/news/admin.php` — дописать два контроллера в конец файла:

```php
// Справочники: экран показывает ровно то, что посчитал lib/catalog.php. Права на вход здесь
// не проверяются — гейт маршрута (permission => 'login') делает это раньше, и вторая проверка
// была бы вторым описанием того же правила.

/** @param array<string, string> $params */
function newsAdminCategories(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('news/admin/categories', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(CATALOG_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'rows' => adminCategoryRows(),
        'titleMax' => fieldLimit(CATEGORY_FIELDS, 'title', 'max'),
        'descriptionMax' => fieldLimit(CATEGORY_FIELDS, 'description', 'max'),
    ], ['title' => 'Рубрики — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function newsAdminTags(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('news/admin/tags', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(CATALOG_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'rows' => adminTagRows(),
        'labelMax' => fieldLimit(TAG_FIELDS, 'label', 'max'),
    ], ['title' => 'Теги — админка', 'noindex' => true]);
}
```

Отказ справочников приезжает тем же каналом, что и отказ списка: `finishAppAction()` пишет причину
во flash, в адрес уходит только ключ `migration-failed`, а экран собирает плашку помощниками ядра.
Своего `?error=` у разделов быть не может — его запрещает `tests/guard/FailureChannelTest.php`.

Контроллер сводки тоже меняется — целиком, тем же телом с двумя новыми ключами:

```php
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
        'pinned' => $pinned,
        'waiting' => $counts['scheduled'],
        'categories' => count(adminCategoryRows()),
        'tags' => count(adminTagRows()),
    ], ['title' => 'Новости — админка', 'noindex' => true]);
}
```

Счётчики справочников берутся из готовых выборок, а не новым `SELECT COUNT(*)` в контроллере:
правило 1 запрещает экрану ходить в базу, а два запроса на админской странице — та цена, которой
стоит это правило.

Изменить `apps/news/routes.php` — после двух подключений задачи 17 добавить третье:

```php
require_once __DIR__ . '/actions/catalog.php';
```

и четыре строки в конец блока `addRoute()`:

```php
addRoute('GET', '/news/admin/categories', 'newsAdminCategories', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/categories', 'newsCategoriesAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('GET', '/news/admin/tags', 'newsAdminTags', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/tags', 'newsTagsAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/actions/catalog.php && php -l apps/news/admin.php && php -l apps/news/routes.php"`
Expected: `No syntax errors detected` трижды.

- [ ] **Шаг 5: Шаблоны двух экранов и две панели сводки**

Создать `apps/news/templates/admin/categories.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var list<array{id: string, slug: string, title: string, description: ?string, order: int, visible: bool, items: array{total: int, visible: int}}> $rows
 * @var int $titleMax
 * @var int $descriptionMax
 */
$itemForms = ['материал', 'материала', 'материалов'];

$input = 'mt-1 w-full rounded-lg border border-ink/20 px-3 py-2 text-sm';
$button = 'rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream hover:opacity-90';
$danger = 'rounded-lg border border-bad/30 px-3 py-1.5 text-sm text-bad hover:bg-bad/10';

$create = '<form class="space-y-3 rounded-lg border border-ink/10 bg-white p-4" method="post" action="'
    . e(NEWS_ADMIN_CATEGORIES_PATH) . '">' . csrfField()
    . '<input type="hidden" name="op" value="create">'
    . '<h2 class="font-semibold">Новая рубрика</h2>'
    . '<p class="text-sm text-ink/60">Адрес складывается из названия один раз и больше не меняется — '
    . 'ссылки, карточки и карта сайтов остаются целыми при любом переименовании.</p>'
    . '<label class="block text-sm">Название'
    . '<input class="' . $input . '" type="text" name="title" required maxlength="' . e((string) $titleMax) . '">'
    . '</label>'
    . '<label class="block text-sm">Описание страницы рубрики (необязательно)'
    . '<textarea class="' . $input . '" name="description" rows="2" maxlength="' . e((string) $descriptionMax) . '"></textarea>'
    . '</label>'
    . '<button class="' . $primary . '" type="submit">' . e(NEWS_CATEGORY_OP_LABEL['create']) . '</button>'
    . '</form>';

$rowsHtml = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $visible = $row['visible'] === true;
    $total = (int) $row['items']['total'];
    $opened = (int) $row['items']['visible'];

    $state = $visible
        ? '<span class="text-sm text-good">видна</span>'
        : '<span class="text-sm text-bad">скрыта — её страница не открывается</span>';

    $counts = sprintf('%1$d %2$s, из них на сайте %3$d.', $total, plural($total, $itemForms), $opened);

    $buttons = '';
    foreach (newsCategoryRowActions($row) as $action) {
        $class = $action['op'] === 'delete' ? $danger : $button;
        $buttons .= '<form class="contents" method="post" action="' . e(NEWS_ADMIN_CATEGORIES_PATH) . '">'
            . csrfField()
            . '<input type="hidden" name="op" value="' . e($action['op']) . '">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<button class="' . $class . '" type="submit">' . e($action['label']) . '</button>'
            . '</form>';
    }

    $rowsHtml .= '<li class="space-y-3 rounded-lg border border-ink/10 bg-white p-4">'
        . '<div class="flex flex-wrap items-baseline justify-between gap-2">'
        . '<h3 class="font-semibold">' . e((string) $row['title']) . '</h3>'
        . '<p class="text-sm text-ink/60">адрес: <code class="rounded bg-ink/5 px-1.5 py-0.5">'
        . e((string) $row['slug']) . '</code></p></div>'
        . '<p class="text-sm">' . $state . ' · ' . e($counts) . '</p>'
        . '<p class="flex flex-wrap items-center gap-2">'
        . '<a class="text-sm underline underline-offset-4 hover:text-accent" href="'
        . e(newsHref(['category' => (string) $row['slug']])) . '">Открыть на сайте</a></p>'
        . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_CATEGORIES_PATH) . '">'
        . csrfField() . '<input type="hidden" name="op" value="rename">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<label class="grow text-sm">Название<input class="' . $input
        . '" type="text" name="title" value="' . e((string) $row['title']) . '" required maxlength="'
        . e((string) $titleMax) . '"></label>'
        . '<button class="' . $button . '" type="submit">' . e(NEWS_CATEGORY_OP_LABEL['rename']) . '</button></form>'
        . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_CATEGORIES_PATH) . '">'
        . csrfField() . '<input type="hidden" name="op" value="describe">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        // Название едет скрытым полем: updateCategoryDescription() проверяет пару «название +
        // описание» целиком, и без него сохранение описания стало бы отказом «название слишком
        // короткое». Правит название форма переименования выше.
        . '<input type="hidden" name="title" value="' . e((string) $row['title']) . '">'
        . '<label class="grow text-sm">Описание страницы<textarea class="' . $input . '" name="description" rows="2" maxlength="'
        . e((string) $descriptionMax) . '">' . e((string) ($row['description'] ?? '')) . '</textarea></label>'
        . '<button class="' . $button . '" type="submit">' . e(NEWS_CATEGORY_OP_LABEL['describe']) . '</button></form>'
        . '<p class="flex flex-wrap gap-2">' . $buttons . '</p>'
        . '</li>';
}

$empty = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-white p-4 text-sm text-ink/60">'
        . 'Рубрик нет. Три стартовые создаются вместе с разделом — если их удалили, добавьте нужные выше.</p>'
    : '';

$content = $nav
    . '<p class="text-sm text-ink/70">Порядок задают кнопки «Вверх» и «Вниз»: он видит и читатель, '
    . 'и плитки рубрик в ленте. Скрытая рубрика не открывается, а её материалы уходят из общей ленты — '
    . 'сами материалы при этом остаются у сайта.</p>'
    . $create . $empty
    . '<ul class="space-y-3">' . $rowsHtml . '</ul>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason])
    . adminShell('Рубрики', $content, $notice);
```

Создать `apps/news/templates/admin/tags.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var list<array{id: string, slug: string, label: string, items: int}> $rows
 * @var int $labelMax
 */
$itemForms = ['материал', 'материала', 'материалов'];

$input = 'mt-1 w-full rounded-lg border border-ink/20 px-3 py-2 text-sm';
$button = 'rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5';
$danger = 'rounded-lg border border-bad/30 px-3 py-1.5 text-sm text-bad hover:bg-bad/10';

$rowsHtml = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $items = (int) $row['items'];

    $rowsHtml .= '<li class="space-y-3 rounded-lg border border-ink/10 bg-white p-4">'
        . '<div class="flex flex-wrap items-baseline justify-between gap-2">'
        . '<h3 class="font-semibold">' . e((string) $row['label']) . '</h3>'
        . '<p class="text-sm text-ink/60">адрес: <code class="rounded bg-ink/5 px-1.5 py-0.5">'
        . e((string) $row['slug']) . '</code></p></div>'
        . '<p class="text-sm">' . e(sprintf('%1$d %2$s с этим тегом.', $items, plural($items, $itemForms))) . '</p>'
        . '<p><a class="text-sm underline underline-offset-4 hover:text-accent" href="'
        . e(newsHref(['tag' => (string) $row['slug']])) . '">Открыть на сайте</a></p>'
        . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_TAGS_PATH) . '">'
        . csrfField() . '<input type="hidden" name="op" value="rename">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<label class="grow text-sm">Надпись<input class="' . $input . '" type="text" name="label" value="'
        . e((string) $row['label']) . '" required maxlength="' . e((string) $labelMax) . '"></label>'
        . '<button class="' . $button . '" type="submit">' . e(NEWS_TAG_OP_LABEL['rename']) . '</button></form>'
        . '<form class="flex flex-wrap gap-2" method="post" action="' . e(NEWS_ADMIN_TAGS_PATH) . '">'
        . csrfField() . '<input type="hidden" name="op" value="delete">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="' . $danger . '" type="submit">' . e(NEWS_TAG_OP_LABEL['delete']) . '</button></form>'
        . '</li>';
}

$empty = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-white p-4 text-sm text-ink/60">'
        . 'Тегов пока нет: их создаёт форма материала, когда редактор вписывает их в поле «Теги».</p>'
    : '';

$content = $nav
    . '<p class="text-sm text-ink/70">Теги вручную не заводятся — их рождает форма материала. '
    . 'Здесь их можно переименовать (адрес тега останется прежним) и удалить пустой. Тег, '
    . 'привязанный хотя бы к одному материалу, справочник не отдаст: с ленты он не уйдёт.</p>'
    . $empty
    . '<ul class="space-y-3">' . $rowsHtml . '</ul>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason])
    . adminShell('Теги', $content, $notice);
```

Изменить `apps/news/templates/admin/summary.php` — три точечные правки, файл целиком не
переписывается.

Первая — в шапку `@var` добавить два ключа после `@var int $waiting`:

```php
 * @var int $categories
 * @var int $tags
```

Вторая — после определения `$panel` (строка, где он заканчивается `'</dl></section>';`) добавить:

```php
// Панели справочников. Числа посчитаны контроллером: экран их только печатает.
$catalogPanels = '';
foreach ([
    ['Рубрики', NEWS_ADMIN_CATEGORIES_PATH, $categories, ['рубрика', 'рубрики', 'рубрик']],
    ['Теги', NEWS_ADMIN_TAGS_PATH, $tags, ['тег', 'тега', 'тегов']],
] as [$heading, $href, $count, $forms]) {
    $catalogPanels .= '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold"><a class="underline underline-offset-4 hover:text-accent" href="'
        . e($href) . '">' . e($heading) . '</a></h2>'
        . '<p class="mt-2 text-sm text-ink/60">' . e((string) $count) . ' ' . e(plural($count, $forms)) . '</p>'
        . '</section>';
}
```

Третья — в сетке панелей заменить `$panel` на `$panel . $catalogPanels`:

```php
    . '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">' . $panel . $catalogPanels . '</div>'
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/templates/admin/categories.php && php -l apps/news/templates/admin/tags.php && php -l apps/news/templates/admin/summary.php"`
Expected: `No syntax errors detected` трижды.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsCatalogTest`
Expected: PASS — 19 проверок.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter "NewsAdminTest|NewsItemFormTest|NewsPublicTest"`
Expected: PASS — панели сводки и четыре вкладки ничего из прежних экранов не сломали.

- [ ] **Шаг 6: Прогнать ворота целиком**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — четыре набора.

Run: `bash scripts/gate.sh`
Expected: зелёные `php -l`, PHPStan 6, PHP-CS-Fixer, PHPUnit.

Что сторожат ворота на этом шаге:

- `tests/guard/RouteHandlersTest.php` — четыре новых адреса, обработчики вызываемы, все они под
  `Disallow: /news/admin` (префикс, а не точный путь: `/news/admin/categories` начинается с него).
- `tests/guard/CsrfCoverageTest.php` — оба новых POST-обработчика начинается с `csrfCheck()`.
- `tests/guard/OutputEscapingTest.php` — в двух новых шаблонах нет `<?= ?>`: разметка собирается
  строками и уходит одним `echo` с пометкой `@safe html`.
- `tests/guard/SqlSafetyTest.php` — в шаблонах, контроллерах и `actions/catalog.php` нет `SELECT`
  вообще и склеивания запросов в частности.
- PHPStan 6. Два места, где он придирается чаще всего: `$row['description']` (строка или null —
  оттень `e((string) ($row['description'] ?? ''))`) и `array_column()` на списковом массиве —
  аргумент обязан быть `array<int|string, mixed>` после `@var`-аннотации `adminCategoryRows()`.

- [ ] **Шаг 7: Проверить экраны руками и закоммитить**

Run:
```bash
docker compose -f docker-compose.dev.yml up -d db
bash scripts/db-reset
rm -f public/install.lock
docker compose -f docker-compose.dev.yml run --rm -T -e CMS_DB_NAME=cms_test php php public/setup.php --admin-email=admin@school.dev --admin-password=длинный-тестовый-пароль
docker compose -f docker-compose.dev.yml run --rm -T -e CMS_DB_NAME=cms_test php php -r 'require "config.php"; require "core/bootstrap.php"; installApp("news");'
docker compose -f docker-compose.dev.yml run --rm -T -e CMS_DB_NAME=cms_test php php -S 0.0.0.0:8080 -t public public/index.php
```

Команды те же, что в шаге 9 задачи 17: `db-reset` — на хосте, замок снять вручную, база тестовая,
`-P` обязателен, у `php -S` — скрипт-роутер.

Открыть `http://localhost:8080/news/admin/categories`, войти и пройти путь руками:

- добавить «Выпускные», посмотреть её адрес в строке и открыть «на сайте» — страница пустая,
  с описанием по умолчанию;
- переименовать её в «Выпускные классы» — адрес в строке остался прежним, ссылка на сайте работает;
- сохранить описание и увидеть его под заголовком страницы рубрики;
- скрыть, увидеть отказ на её странице (404) и «Рубрика скрыта: её страница не открывается…»
  в плашке, показать обратно;
- «Вверх» на первой строке отвечает «Эта рубрика уже первая.»;
- «Удалить» на строке с материалами — текст «В рубрике 1 материал. Удалить её нельзя…»,
  «Удалить» на пустой — плашка «Пустая рубрика удалена.»;
- на вкладке «Теги» добавить тег нельзя (поля создания нет), переименование сохраняет адрес,
  «Удалить» занятого тега отказывает;
- на сводке появились панели «Рубрики» и «Теги» со счётчиками;
- на 360 px поля и кнопки каждой строки складываются в одну колонку, адрес и счётчик не
  обрезаются — требование адаптивности 360…1440 (§12.18) касается и этих двух экранов.

Run:
```bash
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git add apps/news/lib/admin.php apps/news/admin.php apps/news/actions/catalog.php \
  apps/news/routes.php apps/news/templates/admin/categories.php apps/news/templates/admin/tags.php \
  apps/news/templates/admin/summary.php \
  tests/unit/NewsCatalogRulesTest.php tests/unit/NewsAdminRulesTest.php \
  tests/http/NewsCatalogTest.php
git status --short
git commit -m "Новости: экраны справочников — рубрики и теги"
```

# Этап C. Стражи, приёмка и выкладка

### Задача 20: Раздел в общей сборке — карта сайтов, стражи и договор кнопок

**Файлы:**
- Изменить: `apps/news/lib/seo.php` (`newsSitemapUrls()`)
- Изменить: `apps/news/routes.php` (фильтр `sitemap_urls`)
- Создать: `tests/db/NewsSitemapTest.php`, `tests/http/NewsAssemblyTest.php`
- Создать: `tests/guard/NewsVisibilityTest.php`, `tests/guard/AppActionContractTest.php`,
  `tests/db/NewsNoticeCoverageTest.php`
- Изменить: `docs/superpowers/specs/2026-10-06-php-cms-pilot-design.md` (§8.6 — страницы тегов
  исключены из карты сайтов, правка формулировки, а не кода)
- Изменить: `docs/superpowers/specs/2026-10-10-cms-final-specification.md` (строка 336: та же
  формулировка про «видимые материалы, страницы рубрик и тегов»). Правка обязательна в обоих
  документах: итоговая спецификация новее пилотной, и читатель, который откроет только её,
  восстановит адреса тегов в карте по более новому документу и будет чинить то, что сделано верно.
- Тест: перечисленные файлы сами и есть проверка

**Интерфейсы:**
- Потребляет:
  - `core/seo.php`: `sitemapUrls()`, `sitemapXml()`, `coreSitemapUrls()`, `ROBOTS_CORE_DISALLOW`,
    `isRobotsDisallowed()`; `core/hooks.php`: `addFilter()`, `applyFilters()`, `hooksReset()`
  - `core/apps.php`: `isAppActive()`; `core/bootstrap.php`: подключение `core/seo.php` до
    разбора запроса
  - `apps/news/lib/feed.php`: `publishedForSitemap()`; `apps/news/lib/catalog.php`:
    `visibleCategories()`, `categoryIdOf()`, `createCategory()`, `renameCategory()`,
    `updateCategoryDescription()`, `toggleCategoryVisibility()`, `deleteCategory()`, `moveCategory()`,
    `renameTag()`, `deleteTag()`; `apps/news/lib/items.php`:
    `createItem()`, `updateItem()`, `setItemStatus()`, `setItemPinned()`
  - `apps/news/lib/constants.php`: `NEWS_NOTICE_TEXT`, `CATALOG_NOTICE_TEXT`
  - `apps/news/lib/admin.php` (задачи 17 и 19): `NEWS_ROW_OP_LABEL`, `NEWS_CATEGORY_OPS`,
    `NEWS_CATEGORY_OP_LABEL`, `NEWS_TAG_OPS`, `NEWS_TAG_OP_LABEL`
- Производит:
  - `newsSitemapUrls(): list<array{loc: string, lastmod?: string}>` — адрес ленты, открытые
    материалы и страницы видимых рубрик (страниц тегов нет — они закрыты от индекса);
  - фильтр `sitemap_urls` с приоритетом 10, зарегистрированный в `apps/news/routes.php`;
  - три стража, которые читают исходники (`NewsVisibilityTest`, `AppActionContractTest`) и один,
    который считает ключи уведомлений на живой базе (`NewsNoticeCoverageTest`).

**Что сторожится и почему именно так:**

1. **Карта сайтов.** §8.6: в карту попадают видимые материалы и страницы рубрик; скрытые рубрики
   и материалы с `noindex` — нет. Страниц тегов в карте тоже нет, и это уточнение к формулировке
   §8.6: задача 17 отдаёт их с `noindex, nofollow`, а адрес, который страница сама не пускает в
   индекс, карте показывать нельзя. Ядро (§9 задачи 9) уже отбраковывает чужие домены,
   дубликаты и приватные пути; раздел обязан добавить только своё и только когда установлен.
   Проверка `isAppActive('news')` обязательна: таблиц раздела нет в каждой сборке, а запрос к
   несуществующей таблице — это ошибка, а не пустой список.
2. **Видимость.** Единственный способ опубликовать черновик «случайно» — новый запрос к
   `tbl_news_item` без `visibleWhere()`. Страж читает исходники раздела и требует, чтобы каждая
   функция, которая этот стол читает, либо звала `visibleWhere()`, либо стояла в белом списке с
   названной причиной. Белый список — это решение, а не заглушка: редактор обязан видеть черновики,
   а справочник — считать материалы в рубрике.
3. **Договор кнопок.** Кнопка, у которой нет ветки `match`, мёртвая; ветка `match`, у которой нет
   подписи, — кнопка без названия; уведомление, для которого нет текста, — молчание вместо ответа.
   Три константы задачи 19 и `match` в файлах действий проверяются друг о друга по исходникам, а
   ключи уведомлений — вызовами правил на живой базе (второе направление: ни один текст словаря
   не должен быть недостижим).

- [ ] **Шаг 1: Написать падающий тест адресов карты сайта**

Создать `tests/db/NewsSitemapTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Функции ядра глобальны: `core/*.php` не объявляют пространство имён, поэтому вызовы идут без
// `use function` — импорт в Cms\Core указывал бы на несуществующие функции.

// Карта сайта собирается фильтром, поэтому проверяются обе половины: что раздел умеет назвать
// свои адреса и что ядро их принимает, а не любой список из фильтра.
//
// Подставка NewsDbTestCase обязательна: рубрики закладки и чистка перед каждой проверкой дают
// стабильный список адресов, а `testБезУстановленногоРазделаФильтрМолчит` снимает установку —
// без восстановления строки установки следующий тест начал бы с уже снятого раздела.
final class NewsSitemapTest extends NewsDbTestCase
{
    private const EDITOR = ['id' => 'redaktor-3', 'email' => 'redaktor@shkola.ru'];

    protected function setUp(): void
    {
        parent::setUp();

        self::create('na-sajte', 'Материал на сайте', NEWS_STATUS['PUBLISHED'], null, false, 'zhizn-shkoly', ['бал']);
        self::create('chernovik', 'Черновик', NEWS_STATUS['DRAFT'], null, false, 'zhizn-shkoly', []);
        self::create('skoro', 'Ждёт срока', NEWS_STATUS['PUBLISHED'], nowUtc()->add(new DateInterval('PT2H')), false, 'zhizn-shkoly', []);
        self::create('bez-indeksa', 'Без индексации', NEWS_STATUS['PUBLISHED'], null, true, 'postuplenie', []);

        toggleCategoryVisibility(categoryIdOf('metodika'));
    }

    /**
     * @param list<string> $tags
     */
    private static function create(
        string $slug,
        string $title,
        string $status,
        ?\DateTimeImmutable $publishedAt,
        bool $noindex,
        string $categorySlug,
        array $tags
    ): void {
        $result = createItem([
            'title' => $title,
            'slug' => $slug,
            'lead' => 'Коротко о материале.',
            'body' => [['title' => '', 'text' => 'Абзац текста.']],
            'categoryId' => categoryIdOf($categorySlug),
            'tags' => $tags,
            'status' => $status,
            'publishedAt' => $publishedAt,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => $noindex,
        ], self::EDITOR, nowUtc());

        self::assertTrue($result['ok'], (string) $result['error']);
    }

    /** @return list<string> */
    private function locs(): array
    {
        return array_map(static fn (array $url): string => (string) $url['loc'], newsSitemapUrls());
    }

    public function testРазделНазываетЛентуМатериалИРубрику(): void
    {
        $locs = $this->locs();

        self::assertContains('/news', $locs);
        self::assertContains('/news/na-sajte', $locs);
        self::assertContains('/news/c/zhizn-shkoly', $locs);
        // Страница тега закрыта от индекса ещё задачей 17, поэтому адреса тега в карте нет.
        self::assertNotContains('/news/t/bal', $locs);
    }

    public function testЗакрытогоВКартеНет(): void
    {
        $locs = $this->locs();

        // Черновик и не наступивший срок, материал с запретом индексации и скрытая рубрика (§8.6).
        self::assertNotContains('/news/chernovik', $locs);
        self::assertNotContains('/news/skoro', $locs);
        self::assertNotContains('/news/bez-indeksa', $locs);
        self::assertNotContains('/news/c/metodika', $locs);
    }

    public function testДатаПоследнейПравкиУМатериалаЕстьУЛентыНет(): void
    {
        $entries = newsSitemapUrls();

        $byLoc = [];
        foreach ($entries as $entry) {
            $byLoc[(string) $entry['loc']] = $entry;
        }

        self::assertArrayHasKey('lastmod', $byLoc['/news/na-sajte']);
        self::assertSame(10, strlen((string) $byLoc['/news/na-sajte']['lastmod']), 'Дата в формате ГГГГ-ММ-ДД');
        self::assertArrayNotHasKey('lastmod', $byLoc['/news'], 'У ленты, что меняется каждый день, даты нет');
    }

    public function testЯдроДобавляетАдресаРазделаПослеГлавной(): void
    {
        addFilter('sitemap_urls', static fn (array $urls): array => array_merge($urls, newsSitemapUrls()), 10);

        $locs = array_map(static fn (array $url): string => (string) $url['loc'], sitemapUrls());
        hooksReset();

        self::assertSame('/', $locs[0], 'Порядок: сначала ядро, потом раздел');
        self::assertContains('/news/na-sajte', $locs);
        self::assertCount(count($locs), array_unique($locs), 'Дублей в карте нет');
    }

    public function testВОтветеСервераКартаСодержитСтраницуМатериала(): void
    {
        addFilter('sitemap_urls', static fn (array $urls): array => array_merge($urls, newsSitemapUrls()), 10);

        $xml = sitemapXml();
        hooksReset();

        // `sitemapXml()` печатает `<loc>` через `absoluteUrl()`, а `config.sample.php` даёт
        // `SITE_URL = http://localhost:8080`: в ответе адрес абсолютный, относительной формы в
        // разметке не бывает ни при каком содержимом. Отрицательная проверка потому берёт голый
        // путь материала, а не тег с префиксом — по образцу `tests/http/SitemapTest.php`.
        self::assertStringContainsString('<loc>http://localhost:8080/news/na-sajte</loc>', $xml);
        self::assertStringNotContainsString('/news/chernovik', $xml);
    }

    public function testБезУстановленногоРазделаФильтрМолчит(): void
    {
        self::assertTrue(isAppActive('news'));

        // Снимаем установку так, как это делает экран «Приложения»: таблицы раздела остаются,
        // строки реестра нет. Фильтр обязан проверить это до первого запроса к разделу.
        $row = one('SELECT id, enabled, installed_at, version FROM ' . t('app_installation') . " WHERE id = 'news'");
        self::assertNotNull($row, 'Раздел установлен — иначе проверять нечего');

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");

        self::assertFalse(isAppActive('news'));

        $locs = array_map(static fn (array $url): string => (string) $url['loc'], $this->locsFiltered());

        self::assertSame([], array_values(array_filter($locs, static fn (string $loc): bool => str_starts_with($loc, '/news'))));

        // Строку возвращает сам тест: подстановка чистит таблицы раздела, а не реестр установок,
        // и без этого шага следующий тест начал бы прогон уже со снятым разделом.
        run(
            'INSERT INTO ' . t('app_installation') . ' (id, enabled, installed_at, version) VALUES (?, ?, ?, ?)',
            [(string) $row['id'], (int) $row['enabled'], (string) $row['installed_at'], (string) $row['version']]
        );

        self::assertTrue(isAppActive('news'));
    }

    /** @return list<array{loc: string, lastmod?: string}> */
    private function locsFiltered(): array
    {
        addFilter('sitemap_urls', static function (array $urls): array {
            if (!isAppActive('news')) {
                return $urls;
            }

            return array_merge($urls, newsSitemapUrls());
        }, 10);

        $out = sitemapUrls();
        hooksReset();

        return $out;
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsSitemapTest`
Expected: FAIL — `Call to undefined function newsSitemapUrls()`.

- [ ] **Шаг 2: Адреса раздела в карте сайтов**

Изменить `apps/news/lib/seo.php` — дописать в конец файла:

```php
/**
 * Адреса карты сайта этого раздела (§8.6): лента, открытые материалы и страницы видимых рубрик.
 *
 * У ленты и у списков даты нет: они меняются на каждом материале, и `<lastmod>` у них показывал
 * бы дату правки последней строки вместо даты правки списка. Ядро при этом всё равно отбракует
 * дубликат и приватный путь (core/seo.php), поэтому раздел не повторяет эту проверку.
 *
 * Страниц тегов в карте нет, и это уточнение к §8.6: задача 17 закрыла их от индекса
 * (`noindex, nofollow` — подборки повторяют ленту и не самостоятельный контент), а обещать
 * адрес поиску и тут же говорить «не индексируй» — значит давать противоречивый сигнал. Рубрика
 * же индексируется (задача 17 снимает `noindex` с первой страницы), поэтому её адрес в карте
 * законен.
 *
 * @return list<array{loc: string, lastmod?: string}>
 */
function newsSitemapUrls(): array
{
    $urls = [['loc' => newsHref()]];

    foreach (publishedForSitemap() as $entry) {
        $urls[] = $entry;
    }

    foreach (visibleCategories() as $category) {
        $urls[] = ['loc' => newsHref(['category' => (string) $category['slug']])];
    }

    return $urls;
}
```

Изменить `apps/news/routes.php` — дописать после блока `addRoute()`:

```php
// Карта сайтов: раздел добавляет свои адреса фильтром, а не правкой ядра (§5.4). Порядок
// фиксирован — ядро кладёт главную первым, раздел дописывает свои следом.
//
// Проверка установки обязательна: таблицы раздела есть не в каждой сборке, и запрос к
// несуществующей таблице — это ошибка, а не пустой список.
addFilter('sitemap_urls', static function (array $urls): array {
    if (!isAppActive('news')) {
        return $urls;
    }

    return array_merge($urls, newsSitemapUrls());
}, 10);
```

Изменить `docs/superpowers/specs/2026-10-06-php-cms-pilot-design.md` — §8.6 формулирует карту
сайта как «видимые материалы, страницы рубрик и тегов», а код раздела (задача 17) закрыл
подборки тегов от индекса. Расхождение решено в пользу кода, и спецификация правится здесь же,
чтобы следующий читатель не восстанавливал адреса тегов по спецификации:

```
страницы рубрик; скрытые рубрики, материалы с `noindex` и подборки тегов (они несут
`noindex, nofollow` — задача 17) в карту не попадают.
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsSitemapTest`
Expected: PASS — 6 проверок.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter "SitemapTest|RobotsTest"`
Expected: PASS — файл `tests/http/SitemapTest.php` задачи 9 править не нужно, и вот почему: его тест
`testЯдроОтдаётТолькоГлавнуюПокаРазделовНет` зовёт `sitemapUrls()` в процессе PHPUnit, а `reset()` в
его `setUpBeforeClass()` пересоздаёт таблицы ядра, поэтому строки `news` в `app_installation` нет и
фильтр раздела возвращает список ядра без изменений. Сам фильтр проверяется с двух сторон:
`NewsSitemapTest` регистрирует его руками и считает адреса на живой базе, а настоящий ответ сервера
с адресами раздела забирает `NewsAssemblyTest` шага 6 — там `apps/news/routes.php` исполняет
процесс `public/index.php`. Если `SitemapTest` всё-таки падает, значит фильтр добавляет адреса при
неустановленном разделе: править надо проверку `isAppActive()`, а не тест.

- [ ] **Шаг 3: Страж видимости**

Создать `tests/guard/NewsVisibilityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Единственный способ опубликовать черновик «случайно» — новый запрос к tbl_news_item без
// visibleWhere(). Страж читает исходники раздела и требует, чтобы каждая функция, которая этот
// стол читала, либо звала visibleWhere(), либо стояла в списке ниже с названной причиной.
final class NewsVisibilityTest extends TestCase
{
    /**
     * Признаки чтения материалов: прямой вызов таблицы, общий FROM публичных выборок и делегирование
     * области видимости. newsItemFrom() и feedScope() — сами по себе не защита, но функция,
     * которая собирает WHERE через feedScope(), получает visibleWhere() оттуда.
     */
    private const READ_MARKERS = ["t('news_item')", 'newsItemFrom(', 'feedScope('];
    private const GUARD_MARKERS = ['visibleWhere(', 'feedScope('];

    private const ALLOWED = [
        // Редактор обязан видеть черновик, не наступивший срок и снятый материал: иначе список
        // «Черновики» был бы пустым, а правка — невозможной.
        'apps/news/lib/items.php' => '*',
        // Справочник считает материалы в рубрике и теге, чтобы сказать, можно ли их удалить.
        'apps/news/lib/catalog.php' => '*',
        // Публичное чтение: три функции, которым материал нужен независимо от срока и статуса.
        'apps/news/lib/feed.php' => ['newsItemFrom', 'findAny', 'findByOldSlug'],
    ];

    public function testЧтениеМатериаловОгражденоВидимостью(): void
    {
        $violations = [];

        foreach ($this->sources() as $file) {
            $relative = $this->relative($file);
            $allowed = self::ALLOWED[$relative] ?? [];

            if ($allowed === '*') {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($file));

            foreach ($this->functions($code) as $name => $body) {
                $reads = false;
                foreach (self::READ_MARKERS as $marker) {
                    if (str_contains($body, $marker)) {
                        $reads = true;
                        break;
                    }
                }

                if (!$reads || in_array($name, $allowed, true)) {
                    continue;
                }

                foreach (self::GUARD_MARKERS as $marker) {
                    if (str_contains($body, $marker)) {
                        continue 2;
                    }
                }

                $violations[] = $relative . ' — ' . $name . '() читает tbl_news_item без visibleWhere()';
            }
        }

        self::assertSame([], $violations, 'Новое чтение материалов обязано быть ограждено видимостью или записано в белый список с причиной');
    }

    public function testБелыйСписокНеДержитЛишнего(): void
    {
        // Белый список — это решение, а не страховка: каждая его строка обязана по-прежнему
        // соответствовать функции, которая читает материалы без проверки видимости.
        $used = [];

        foreach (self::ALLOWED as $file => $names) {
            $path = dirname(__DIR__, 2) . '/' . $file;
            self::assertFileExists($path, $file . ' из белого списка стража больше нет');

            if ($names === '*') {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($path));
            $functions = $this->functions($code);

            foreach ($names as $name) {
                self::assertArrayHasKey($name, $functions, $file . ': функции ' . $name . '() в белом списке нет');

                $reads = false;
                foreach (self::READ_MARKERS as $marker) {
                    if (str_contains((string) $functions[$name], $marker)) {
                        $reads = true;
                    }
                }

                self::assertTrue($reads, $file . ': ' . $name . '() больше не читает материалы — строка списка устарела');
                $used[] = $file . '::' . $name;
            }
        }

        self::assertCount(3, $used, 'Список разрешённых функций читается целиком — три именованные строки feed.php');
    }

    /** @return array<string, string> имя функции → её тело */
    private function functions(string $code): array
    {
        preg_match_all('/\bfunction\s+([a-zA-Z_]\w*)\s*\([^)]*\)[^{]*\{/', $code, $starts, PREG_OFFSET_CAPTURE);

        $out = [];
        $length = strlen($code);

        foreach ($starts[1] as $index => $start) {
            $bodyStart = (int) $starts[0][$index][1] + strlen((string) $starts[0][$index][0]);
            $depth = 1;
            $position = $bodyStart;

            while ($position < $length && $depth > 0) {
                $character = $code[$position];

                if ($character === '{') {
                    ++$depth;
                } elseif ($character === '}') {
                    --$depth;
                }

                ++$position;
            }

            $out[(string) $start[0]] = substr($code, $bodyStart, max(0, $position - $bodyStart));
        }

        return $out;
    }

    private function stripComments(string $code): string
    {
        $out = preg_replace('/(^|\s)\/\/[^\n\r]*/', ' ', $code) ?? $code;
        $out = preg_replace('/#![^\n\r]*/', ' ', $out) ?? $out;
        $out = preg_replace('/\/\*.*?\*\//s', ' ', $out) ?? $out;

        return preg_replace('/(^|\s)#[^\n\r]*/', ' ', $out) ?? $out;
    }

    /** @return list<string> */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach ([$root . '/apps/news/lib', $root . '/apps/news/actions', $root . '/apps/news/templates'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

        foreach ([$root . '/apps/news/admin.php', $root . '/apps/news/routes.php'] as $file) {
            if (is_file($file)) {
                $out[] = $file;
            }
        }

        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
```

`seed.php` под страж не попадает осознанно: он только пишет (закладку и три стартовые рубрики),
а читает ли раздел — вопрос запроса, а не установщика. Комментарии вырезаются, потому что
`visibleWhere()` в пояснительной строке не защищает ни одного запроса.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter NewsVisibilityTest`
Expected: PASS — 2 проверки. Если падает вторая, править надо список `ALLOWED`, а не тест:
расхождение списка и исходников — это и есть тот случай, который страж сторожит.

- [ ] **Шаг 4: Страж договора «действие ↔ обработчик ↔ подпись ↔ текст»**

Создать `tests/guard/AppActionContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Кнопка без ветки match мёртвая, ветка match без подписи — действие без названия, а уведомление
// без текста — молчание вместо ответа. Страж сверяет четыре константы админки (задачи 17 и 19)
// с тем, что реально разбирают файлы действий.
final class AppActionContractTest extends TestCase
{
    /** @var array<string, array{ops: list<string>, default: string}> файл действий → чем он сверяется */
    private const ACTIONS = [
        'apps/news/actions/items.php' => ['ops' => ['NEWS_ROW_OP_LABEL'], 'default' => 'itemFailure'],
        'apps/news/actions/catalog.php' => ['ops' => ['NEWS_CATEGORY_OP_LABEL', 'NEWS_TAG_OP_LABEL'], 'default' => 'catalogFailure'],
    ];

    public function testКаждоеОбещанноеДействиеРазобрано(): void
    {
        $handled = $this->handledOps('apps/news/actions/items.php');

        self::assertSame(array_keys(NEWS_ROW_OP_LABEL), $handled, 'items.php: кнопки списка и match расходятся');

        $catalog = $this->handledOps('apps/news/actions/catalog.php');
        self::assertSame(
            array_merge(NEWS_CATEGORY_OPS, NEWS_TAG_OPS),
            $catalog,
            'catalog.php: порядок действий в match обязан совпадать со списком'
        );
    }

    public function testКаждоеРазобранноеДействиеИмеетПодпись(): void
    {
        $rows = $this->handledOps('apps/news/actions/items.php');
        $catalog = $this->handledOps('apps/news/actions/catalog.php');
        $captions = array_merge(array_keys(NEWS_CATEGORY_OP_LABEL), array_keys(NEWS_TAG_OP_LABEL));

        // Вперёд: действие без подписи — кнопка, у которой нет названия.
        self::assertSame([], array_values(array_diff($rows, array_keys(NEWS_ROW_OP_LABEL))),
            'items.php: разобранное действие обязано иметь подпись');
        self::assertSame([], array_values(array_diff($catalog, $captions)),
            'catalog.php: разобранное действие обязано иметь подпись одного из справочников');

        // Назад: подпись без ветки match — обещание кнопки, которую никто не рисует.
        self::assertSame([], array_values(array_diff(array_keys(NEWS_ROW_OP_LABEL), $rows)),
            'items.php: подпись без ветки match — мёртвая кнопка');
        self::assertSame([], array_values(array_diff($captions, $catalog)),
            'catalog.php: подпись без ветки match — мёртвая кнопка');
    }

    public function testНеизвестноеДействиеОтказывается(): void
    {
        foreach (array_keys(self::ACTIONS) as $file) {
            $code = (string) file_get_contents($this->path($file));

            self::assertSame(
                substr_count($code, 'match ($op)'),
                substr_count($code, 'default =>'),
                $file . ': у каждой ветки разбора обязан быть отказ'
            );

            self::assertMatchesRegularExpression(
                '/default\s*=>\s*' . self::ACTIONS[$file]['default'] . '\(/',
                $code,
                $file . ': отказ обязан возвращать текст, а не пустой результат'
            );
        }
    }

    public function testСпискиДействийСовпадаютСоСвоимиПодписями(): void
    {
        self::assertSame(NEWS_CATEGORY_OPS, array_keys(NEWS_CATEGORY_OP_LABEL));
        self::assertSame(NEWS_TAG_OPS, array_keys(NEWS_TAG_OP_LABEL));
    }

    /**
     * Действия, которые разбирает `match ($op)` файла. Ключ ветки — единственная строка вида
     * `'op' =>` в блоке: правые части веток — вызовы функций, а не массивы, поэтому пары
     * «строка => …» в них нет. Если в ветке появится массив с такими ключами, страж придётся
     * дополнять отдельным утверждением — ограничение записано честно.
     *
     * @return list<string>
     */
    private function handledOps(string $file): array
    {
        $code = (string) file_get_contents($this->path($file));

        preg_match_all('/match \(\$op\)\s*\{(.*?)\n\s*\};/s', $code, $blocks);

        $ops = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([a-z]+)'\s*=>/", (string) $block, $arms);
            foreach ($arms[1] as $op) {
                if ($op !== 'default') {
                    $ops[] = (string) $op;
                }
            }
        }

        return $ops;
    }

    private function path(string $file): string
    {
        return dirname(__DIR__, 2) . '/' . $file;
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter AppActionContractTest`
Expected: PASS — 4 проверки.

- [ ] **Шаг 5: Покрытие текстов уведомлений на живой базе**

Создать `tests/db/NewsNoticeCoverageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Обратная сторона договора: не только каждое действие обязано вернуть известный ключ, но и
// каждый ключ словаря обязано найти. Мёртвый текст — это обещание, которое экран не держит,
// и читается оно хуже отсутствующего.
//
// Подставка NewsDbTestCase даёт каждой проверке три рубрики закладки и пустые справочники:
// достигаемость читается по порядку строк в таблице, а он зависит от числа рубрик.
final class NewsNoticeCoverageTest extends NewsDbTestCase
{
    private const EDITOR = ['id' => 'redaktor-4', 'email' => 'redaktor@shkola.ru'];

    /** @var array<string, string> ключ → результат, который его вернул */
    private array $catalogHits = [];

    /** @var array<string, string> */
    private array $itemHits = [];

    public function testКаждыйТекстСправочниковДостигаетсяДействием(): void
    {
        $this->catalog(createCategory('Проба'), 'created');
        $id = categoryIdOf('proba');
        self::assertNotSame('', $id, 'Рубрика создаётся транслитом из названия');

        $this->catalog(renameCategory($id, 'Проба вторая'), 'renamed', $id);
        $this->catalog(updateCategoryDescription($id, 'Проба вторая', 'Описание'), 'described', $id);
        $this->catalog(toggleCategoryVisibility($id), 'hidden', $id);
        $this->catalog(toggleCategoryVisibility($id), 'shown', $id);

        // Крайние строки: `topped` и `bottomed` возвращаются только реальной первой и реальной
        // последней строкой списка, поэтому их берут из adminCategoryRows(), а не по имени.
        $first = categoryIdOf('zhizn-shkoly');
        $this->catalog(moveCategory($first, 'up'), 'topped', $first);
        $this->catalog(moveCategory($first, 'down'), 'moved', $first);

        $last = $this->lastCategoryId();
        $this->catalog(moveCategory($last, 'down'), 'bottomed', $last);
        $this->catalog(moveCategory($last, 'up'), 'moved', $last);
        $this->catalog(deleteCategory($id), 'deleted', $id);

        // Тег рождает только форма материала (§8.4), поэтому его заводят созданной строкой,
        // а перед удалением снимают с материала: занятого тега deleteTag() не трогает.
        $item = $this->item(createItem($this->input(['slug' => 'dlya-tega']), self::EDITOR, nowUtc()), 'created');
        $tag = $this->tagId();
        $this->catalog(renameTag($tag, 'Пробный тег'), 'tagRenamed', $tag);
        $this->item(updateItem($item, $this->input(['slug' => 'dlya-tega', 'tags' => []]), self::EDITOR, nowUtc()), 'updated', $item);
        $this->catalog(deleteTag($tag), 'tagDeleted', $tag);

        self::assertSame(
            [],
            array_diff(array_keys(CATALOG_NOTICE_TEXT), array_keys($this->catalogHits)),
            'Эти тексты справочников не достигаются ни одним действием'
        );
    }

    public function testКаждыйТекстМатериалаДостигаетсяДействием(): void
    {
        $first = $this->item(createItem($this->input(['slug' => 'pervyj']), self::EDITOR, nowUtc()), 'created');
        $this->item(updateItem($first, $this->input(['slug' => 'pervyj', 'lead' => 'Новый лид.']), self::EDITOR, nowUtc()), 'updated', $first);

        // Новый адрес при том же статусе: прежняя строка уходит в перенаправления, и человек
        // обязан узнать об этом плашкой, а не молча потерять ссылку из закладок (§8.3).
        $this->item(updateItem($first, $this->input(['slug' => 'pervoj-vtoroj']), self::EDITOR, nowUtc()), 'slugChanged', $first);

        $this->item(setItemStatus($first, NEWS_STATUS['PUBLISHED']), 'published', $first);
        $this->item(setItemStatus($first, NEWS_STATUS['DRAFT']), 'unpublished', $first);
        $this->item(setItemStatus($first, NEWS_STATUS['DRAFT']), 'unchanged', $first);

        // Закрепление просят у материала, который уже на сайте (isOpen()), поэтому строку
        // сначала снова выпускают — на черновике кнопка обязана отказать, а не вернуть ключ.
        $this->item(setItemStatus($first, NEWS_STATUS['PUBLISHED']), 'published', $first);
        $this->item(setItemPinned($first, true), 'pinned', $first);
        $this->item(setItemPinned($first, false), 'unpinned', $first);

        // «Ждёт срока» даёт createItem() с будущим publishedAt: третий аргумент setItemStatus() —
        // это «сейчас», а не дата выпуска, и подставить будущий срок им нельзя.
        $this->item(
            createItem(
                $this->input([
                    'slug' => 'chetvertyj',
                    'status' => NEWS_STATUS['PUBLISHED'],
                    'publishedAt' => nowUtc()->add(new DateInterval('PT1H')),
                ]),
                self::EDITOR,
                nowUtc()
            ),
            'scheduled'
        );

        self::assertSame(
            [],
            array_diff(array_keys(NEWS_NOTICE_TEXT), array_keys($this->itemHits)),
            'Эти тексты материала не достигаются ни одним действием'
        );
    }

    public function testОтказНикогдаНеНесётКлючаУведомления(): void
    {
        // Прямое направление договора: успех называет ключ из словаря (его проверяют
        // $this->catalog() и $this->item()), отказ — не называет никакого, иначе экран печатал
        // бы плашку «Рубрика добавлена» там, где ничего не добавилось.
        $draft = $this->item(createItem($this->input(['slug' => 'otkaz']), self::EDITOR, nowUtc()), 'created');

        foreach ([
            renameCategory('net-takogo-identifikatora', 'Новое название'),
            updateCategoryDescription('net-takogo-identifikatora', 'Новое название', ''),
            toggleCategoryVisibility('net-takogo-identifikatora'),
            moveCategory('net-takogo-identifikatora', 'up'),
            deleteCategory('net-takogo-identifikatora'),
            renameTag('net-takogo-identifikatora', 'Новый тег'),
            deleteTag('net-takogo-identifikatora'),
            setItemStatus('net-takogo-identifikatora', NEWS_STATUS['PUBLISHED']),
            setItemPinned('net-takogo-identifikatora', true),
            // Отказ по состоянию, а не по идентификатору: черновик не закрепляют (isOpen()).
            setItemPinned($draft, true),
        ] as $index => $result) {
            self::assertFalse($result['ok'], 'Отказ №' . $index . ' обязан не удаваться');
            self::assertSame('', (string) $result['notice'], 'Отказ №' . $index . ' не несёт ключа');
            self::assertNotSame('', (string) $result['error'], 'Отказ №' . $index . ' называет причину');
        }
    }

    /** @param array{ok: bool, notice: string, error: string} $result */
    private function catalog(array $result, string $expect, string $id = ''): array
    {
        self::assertTrue($result['ok'], $expect . ' / ' . $id . ': ' . (string) $result['error']);
        self::assertSame($expect, (string) $result['notice'], $expect . ' / ' . $id);
        self::assertArrayHasKey($expect, CATALOG_NOTICE_TEXT, 'Ключ есть в словаре?');

        $this->catalogHits[$expect] = $id;

        return $result;
    }

    /** @param array{ok: bool, notice: string, error: string, id: string, slug: string} $result */
    private function item(array $result, string $expect, string $id = ''): string
    {
        self::assertTrue($result['ok'], $expect . ' / ' . $id . ': ' . (string) $result['error']);
        self::assertSame($expect, (string) $result['notice'], $expect . ' / ' . $id);
        self::assertArrayHasKey($expect, NEWS_NOTICE_TEXT, $expect . ': ключ обязан быть в словаре');

        $this->itemHits[$expect] = $id;

        return (string) $result['id'];
    }

    private function lastCategoryId(): string
    {
        $rows = adminCategoryRows();

        return (string) $rows[count($rows) - 1]['id'];
    }

    private function tagId(): string
    {
        $rows = adminTagRows();
        self::assertNotSame([], $rows, 'Тег обязан появиться из формы материала');

        return (string) $rows[0]['id'];
    }

    /**
     * Набор полей для `createItem()`/`updateItem()` — тот же, что отдаёт parseNewsForm() (задача
     * 12) в ключе `data`.
     *
     * Статус по умолчанию — черновик: Published-строка на создании вернула бы `published` или
     * `scheduled` вместо `created`, и тест терял бы ровно тот переход, который проверяет.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides): array
    {
        return array_merge([
            'title' => 'Пробный материал',
            'slug' => 'probnyj',
            'lead' => 'Коротко.',
            'body' => [['title' => '', 'text' => 'Абзац.']],
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'tags' => ['проба'],
            'status' => NEWS_STATUS['DRAFT'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsNoticeCoverageTest`
Expected: PASS — 3 проверки. Порядок тестов здесь важен не для PHPUnit, а для читателя: сначала
словарь справочников, потом материалов.

Четыре решения этого теста:

- Достижимость проверяется вызовом правил, а не чтением исходников: строка `'notice' => 'x'`
  ничего не говорит о том, при каких данных её вернёт функция, а `moveCategory()` отдаёт `topped`
  только на реальной первой строке списка.
- Сравнение идёт через `array_diff` в одну сторону: порядок ключей в словаре — это порядок текстов
  в файле, а не порядок переходов, и `assertSame(array_keys(…))` заставлял бы вызывать действия в
  алфавитном порядке словаря. Обратное направление (ключ вне словаря) проверяют `$this->catalog()`
  и `$this->item()` — они сверяют каждый возвращенный ключ с словарём до того, как он попадёт в
  накопитель.
- `scheduled` даёт `createItem()` с будущим `publishedAt`: третий аргумент `setItemStatus()` — это
  «сейчас», а не дата выпуска, и будущим сроком его не подставить. `unchanged` даёт второй
  вызов `setItemStatus()` в тот же статус.
- Статус по умолчанию в `input()` — черновик. С `PUBLISHED` по умолчанию первая же строка
  вернула бы `published`, и ключ `created` остался бы недостижим — а это ровно тот текст, который
  человек видит после «Сохранить черновик».

- [ ] **Шаг 6: HTTP-приёмка собранного стенда (§12.1–§12.5, §12.9)**

Создать `tests/http/NewsAssemblyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Приёмка целиком: установка экранами, лента после публикации, закрытая админка и карта сайтов
// проверяются в одном процессе на собранном стенде. Поодиночке эти проверки есть в задачах 8, 9,
// 16, 17 и 19; здесь они складываются в критерии §12.1–§12.5 и §12.9.
final class NewsAssemblyTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@school.dev';
    private const ADMIN_PASSWORD = 'длинный-тестовый-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'administrator');

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

    private function login(): void
    {
        $page = Kernel::get('/login');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);

        Kernel::post('/login', [
            '_token' => $matches[1] ?? '',
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
    }

    public function testУстановкаИдётСоЭкранаПриложения(): void
    {
        $this->login();
        $page = Kernel::get('/admin/apps');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Новости', $page['body']);

        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);
        $response = Kernel::post('/admin/apps', [
            '_token' => $matches[1] ?? '',
            'op' => 'install',
            'app' => 'news',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=installed', (string) $response['headers']['location']);

        $installed = appInstallation('news');

        self::assertNotNull($installed);
        self::assertSame('1.0.0', (string) $installed['version']);
        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('app_migration') . ' WHERE app = ?', ['news']));
        self::assertSame(3, (int) value('SELECT COUNT(*) FROM ' . t('news_category')));
    }

    public function testЧужогоРазделаВСборкеНет(): void
    {
        // Этот тест идёт уже после установки, поэтому проверяет не «неустановленный раздел», а
        // другое: несуществующего приложения в сборке нет, и его адрес не открывается.
        self::assertSame(404, Kernel::get('/nalog/krasivo')['status']);
    }

    public function testПослеУстановкиЛентаОткрывается(): void
    {
        $this->login();
        $page = Kernel::get('/news');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('пока нет материалов', $page['body']);
    }

    public function testПубликацияПоказываетМатериалВЧетырёхМестах(): void
    {
        // Критерий §12.9: лента, страница рубрики, страница тега и карта сайтов.
        $this->login();
        $id = $this->createItemViaForm();

        $published = Kernel::post('/news/admin/items', $this->fields('publish', $id));
        self::assertSame(302, $published['status']);
        self::assertStringContainsString(
            'notice=published',
            (string) $published['headers']['location'],
            'Кнопка списка выпускает материал и сообщает об этом тем же ключом'
        );

        self::assertStringContainsString('Осенний бал', Kernel::get('/news')['body']);
        self::assertStringContainsString('Осенний бал', Kernel::get('/news/c/zhizn-shkoly')['body']);
        self::assertStringContainsString('Осенний бал', Kernel::get('/news/t/vypusknik')['body']);

        $sitemap = Kernel::get('/sitemap.xml')['body'];
        // Тот же порядок, что в шаге карты сайтов выше: `sitemapXml()` печатает `<loc>` абсолютным
        // адресом через `absoluteUrl()`, поэтому проверка берёт полный адрес с `SITE_URL`.
        self::assertStringContainsString('<loc>http://localhost:8080/news/osennij-bal</loc>', $sitemap);
    }

    public function testАдминкаЗакрытаИндексироватьНечего(): void
    {
        // Критерии §12.5 в одном ответе: редирект анонима, noindex и robots.txt.
        $anonymous = Kernel::get('/news/admin/categories');
        self::assertSame(302, $anonymous['status']);
        self::assertStringContainsString('/login?next=', (string) $anonymous['headers']['location']);

        $this->login();
        foreach (['/news/admin', '/news/admin/items', '/news/admin/categories', '/news/admin/tags'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status'], $path);
            self::assertStringContainsString('noindex, nofollow', $page['body'], $path);
        }

        $robots = Kernel::get('/robots.txt')['body'];
        self::assertStringContainsString('Disallow: /news/admin', $robots);
        self::assertStringNotContainsString('/news/admin', Kernel::get('/sitemap.xml')['body']);
    }

    public function testФормаРаботаетБезJavaScript(): void
    {
        // §12.17 в миниатюре: добавление рубрики — это POST формы без единого скрипта на странице.
        $this->login();
        $body = Kernel::get(NEWS_ADMIN_CATEGORIES_PATH)['body'];

        self::assertStringNotContainsString('<script', $body, 'Экран справочника не грузит скриптов');

        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);
        $response = Kernel::post(NEWS_ADMIN_CATEGORIES_PATH, [
            '_token' => $matches[1] ?? '',
            'op' => 'create',
            'title' => 'Без скриптов',
            'description' => '',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=created', (string) $response['headers']['location']);
    }

    // ── Помощники ─────────────────────────────────────────

    /** @return array<string, string> */
    private function fields(string $op, string $id): array
    {
        $page = Kernel::get('/news/admin/items');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);

        return [
            '_token' => $matches[1] ?? '',
            'op' => $op,
            'id' => $id,
            'back' => '/news/admin/items',
        ];
    }

    private function createItemViaForm(): string
    {
        $page = Kernel::get('/news/admin/items/new');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);

        // Имена полей — NEWS_FORM_FIELDS (задача 12) плюс намерение кнопки и адрес возврата: их
        // читает newsItemSaveAction() (задача 18). Поле slug пустое — адрес берется из заголовка,
        // так форма и ведёт себя на стенде.
        $response = Kernel::post('/news/admin/items/new', [
            '_token' => $matches[1] ?? '',
            'back' => '/news/admin/items',
            'intent' => 'draft',
            'title' => 'Осенний бал',
            'slug' => '',
            'lead' => 'Приглашаем на бал.',
            'cover' => '',
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => 'Танцы и чай.',
            'tags' => 'выпускник',
            'status' => NEWS_STATUS['DRAFT'],
            'publishedAt' => '',
            'pinned' => '',
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'noindex' => '',
        ]);

        self::assertSame(302, $response['status'], 'Форма обязана принять черновик: ' . $response['body']);
        self::assertSame(
            '/news/admin/items?notice=created',
            (string) $response['headers']['location'],
            'После сохранения — возврат в список с ключом уведомления (PRG)'
        );

        // Адрес ответа — список, идентификатора в нём нет, поэтому строку берут из базы по адресу
        // материала. Так же её находит экран правки, когда человек переходит из списка.
        $id = (string) value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['osennij-bal']);
        self::assertNotSame('', $id, 'Черновик «Осенний бал» появился в базе');

        return $id;
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsAssemblyTest`
Expected: PASS — 6 проверок.

Два места, где этот тест обязан совпадать с собранной формой, а не с вымыслом:

- Имена полей `createItemViaForm()` — ровно `NEWS_FORM_FIELDS` (задача 12) плюс `intent` — намерение
  кнопки, которое читает `newsItemSaveAction()` (задача 18), и `back` — адрес возврата. Если в
  собранном `item.php` имя намерения другое, правится тест, а не форма.
- Возврат после создания — список с ключом `notice=created`: `back` формы указывает на
  `/news/admin/items`, а `newsAdminBackHref()` адрес создания (`/news/admin/items/new`) списком не
  считает. Идентификатор поэтому берётся из базы по адресу материала, а не из ответа. Если стенд
  ведёт человека куда-то ещё — это расхождение с задачей 18, и оно обязано упасть здесь, а не в
  приёмке §12 на хостинге.

- [ ] **Шаг 7: Ворота, руками и коммит**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — четыре набора; в `guard` добавились три новых файла.

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки. PHPStan 6 на тестовых файлах: `preg_match_all()` возвращает
`int|false`, поэтому результат либо присваивается с приведением, либо не читается вовсе — в трёх
новых стражах он читается через `$matches`, что безопаснее.

Проверить руками:

- `/sitemap.xml` на собранном стенде содержит `/news`, `/news/{slug}` и `/news/c/…`, не содержит
  `/news/t/…` (страницы тегов закрыты от индекса) и не содержит `/news/admin`;
- `/robots.txt` содержит `Disallow: /news/admin`;
- на `/admin/apps` после «Выключить» раздел исчезает с сайта (404 на `/news`), а карта сайтов
  перестаёт его перечислять — та же проверка `isAppActive()` в фильтре.

Run:
```bash
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git add apps/news/lib/seo.php apps/news/routes.php \
  docs/superpowers/specs/2026-10-06-php-cms-pilot-design.md \
  tests/db/NewsSitemapTest.php tests/db/NewsNoticeCoverageTest.php \
  tests/http/NewsAssemblyTest.php tests/guard/NewsVisibilityTest.php \
  tests/guard/AppActionContractTest.php
git status --short
git commit -m "Новости: карта сайтов, страж видимости и договор действий"
```
