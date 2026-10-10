### Задача 15: Правила редактора — список материалов, форма и сохранение

**Files:**
- Create: `apps/news/lib/items.php`
- Modify: `apps/news/lib/boot.php` (одна строка)
- Test: `tests/unit/NewsPublishRulesTest.php`, `tests/db/NewsItemsTest.php`

**Порт:** `english-portal/src/apps/news/lib/items.ts` (488 строк). Источник истины — он и его тесты;
расхождения с ним названы ниже явно.

**Потребляет:**
- `core/db.php`: `all()`, `one()`, `value()`, `run()`, `insert(string $table, array $row): void`,
  `tx(callable $fn): mixed`, `t(string $name): string`, `escapeLike(string $value): string`,
  `inPlaceholders(int $count): string`
- `core/date.php`: `nowUtc(): DateTimeImmutable`, `utcStamp(DateTimeImmutable $date): string`,
  `fromDb(?string $raw): ?DateTimeImmutable`, `formatRuDateTime(?DateTimeImmutable $date): string`
- `core/id.php`: `newId(): string`; `core/log.php`: `logInfo(string $event, array $context = []): void`,
  `logWarn(string $event, array $context = []): void`
- `constants.php`: `NEWS_STATUS`, `NEWS_STATUS_LABEL`, `NEWS_FILTERS`, `ADMIN_NEWS_PER_PAGE`,
  `OLD_SLUGS_MAX`, `SEARCH_MAX_LENGTH`
- `visibility.php`: `isOpen(array $row, DateTimeImmutable $now): bool`, `isScheduled(array $row, DateTimeImmutable $now): bool`,
  `visibleWhere(): string`, `visibleArgs(DateTimeImmutable $now): list<string>`
- `slug.php`: `newsSlugPlan(string $typedSlug, string $title, callable $taken): array{slug: string, error: string}`,
  `rememberOldSlug(array $slugs, string $previous, string $next): list<string>`
- `validators.php`: `bodyToFormText(mixed $body): string`, `formPublishedAt(?DateTimeImmutable $date): string`,
  `parseQuery(?string $value): string`
- `catalog.php`: `categoryExists(string $id): bool`, `resolveTagIds(array $labels): list<string>`
- `feed.php`: `pageCount(int $total, int $perPage = NEWS_PER_PAGE): int`, `clampPage(int $page, int $pages): int`,
  `pageWindow(int $current, int $pages): list<int>`, `newsHref(array $target = []): string`,
  `paragraphsOf(mixed $body): list<array{title: string, text: string}>`, `itemTags(string $itemId): list<array{slug: string, label: string, href: string}>`

**Производит** (это читают задача 17 — экраны админки, и задача 18 — форма и POST-обработчики):

```
const NEWS_ID_MAX = 26;
const NEWS_ADMIN_ITEMS_PATH = '/news/admin/items';
const ADMIN_ITEMS_ORDER = 'i.updated_at DESC, i.created_at DESC, i.id DESC';
const NEWS_PUBLISH_NEEDS_TEXT = 'Материал выходит на печать с текстом — допишите абзацы в форме.';

parseAdminFilters(array $raw): array{status: string, categoryId: string, query: string, page: int}
statusFilterOf(string $value): string
pageOf(string $value): int
adminItemsHref(array $filters): string
adminWhere(array $filters, DateTimeImmutable $now): array{where: string, args: list<string>}
adminItemPage(array $filters, DateTimeImmutable $now): array{items, total, pages, current, window}
adminItemRow(array $row, array $tags, DateTimeImmutable $now): array   // чистый разбор строки базы
adminTagsOf(array $itemIds): array<string, list<string>>
adminItemCounts(DateTimeImmutable $now): array<string, int>
pinnedVisibleCount(DateTimeImmutable $now): int
itemForForm(string $id): array|null
oldSlugsOf(string $itemId): list<string>
itemRow(string $id): array<string, mixed>|null
publishedAtOf(mixed $raw): ?DateTimeImmutable
publishDateOf(string $status, ?DateTimeImmutable $publishedAt, DateTimeImmutable $now): ?DateTimeImmutable
publishNotice(string $status, ?DateTimeImmutable $publishedAt, DateTimeImmutable $now, bool $created): string
orNull(string $value): ?string
checkCategory(string $categoryId): string          // '' — рубрика годится
hasParagraphs(mixed $body): bool
publishTextError(string $status, mixed $body): string   // '' — на печать можно
bodyJson(mixed $paragraphs): string
dataOf(array $input, ?DateTimeImmutable $publishedAt, array $editor, bool $publishedNow, DateTimeImmutable $now): array<string, mixed>
updateSet(array $row): list<string>
tagLabelsOf(mixed $raw): list<string>
slugTaken(string $slug, string $exceptId = ''): bool
saveOldSlugs(string $itemId, array $slugs): void
linkItemTags(string $itemId, array $tagIds): void
itemFailure(string $error): array{ok: bool, notice: string, error: string, id: string, slug: string}
itemSuccess(string $notice, string $id, string $slug): array{ok: bool, notice: string, error: string, id: string, slug: string}
createItem(array $input, array $editor, ?DateTimeImmutable $now = null): array{ok, notice, error, id, slug}
updateItem(string $id, array $input, array $editor, ?DateTimeImmutable $now = null): array{ok, notice, error, id, slug}
setItemStatus(string $id, string $to, ?DateTimeImmutable $now = null): array{ok, notice, error, id, slug}
setItemPinned(string $id, bool $pinned, ?DateTimeImmutable $now = null): array{ok, notice, error, id, slug}
```

`$input` — значение ключа `data` из `parseNewsForm()` (§8.5): ключи `title, slug, lead, cover, categoryId,
body, tags, status, publishedAt, pinned, seoTitle, seoDescription, ogImage, noindex`. `$editor` — значение
ключа `editor` из `getEditorAccess()`: `array{id: string, email: string}`.

**Что сюда не входит:** вёрстка списка, формы и кнопок (задачи 17–18), POST-обработчики
(`apps/news/actions/items.php`, задача 17 и `apps/news/actions/item.php`, задача 18) и приём картинки
(`apps/news/lib/upload.php`, задача 18). Здесь только
правила и запросы, которые экраны вызывают.

**Два правила этого файла:**

1. **Выборки редактора не применяют `visibleWhere()` — и это единственное такое место в разделе.**
   Черновик и материал со сроком в будущем обязан видеть тот, кто раздел правит (§8.2). Страж
   `NewsVisibilityGuardTest` (задача 20) разрешает читать `tbl_news_item` без условия видимости только
   `lib/items.php` здесь, `lib/catalog.php` (счётчики материалов в списке рубрик) и тесты; публичные
   выборки живут в `feed.php` и идут через `visibleWhere()`.
2. **Динамический SQL собирается `sprintf()`.** Значения всегда уходят плейсхолдерами; номер страницы и
   предел подставляются через `%d`, потому что оба пришли из кода, а не из запроса.

**Три расхождения с портируемым кодом:**

- `oldSlugs` в PostgreSQL был JSON-массивом в строке материала; здесь это таблица `tbl_news_old_slug`
  (решение 3 спецификации), поэтому список читают `oldSlugsOf()` и пишут `saveOldSlugs()`.
- Порядок списка дополнен хвостом `created_at DESC, i.id DESC`. В портале строки упорядочены только по
  `updatedAt`, и у материалов одной секунды порядок решает база: на второй странице списка материал
  повторялся бы или пропадал. Хвост делает порядок однозначным.
- `updated_at` пишется явно в каждом UPDATE: в схеме PostgreSQL колонку обновляла Prisma (`@updatedAt`),
  в MySQL такого механизма нет, а на этой колонке держится порядок списка.

- [ ] **Шаг 1: Написать падающий тест правил**

Создать `tests/unit/NewsPublishRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

// Правила редактора, которым база не нужна: разбор адресной строки списка, условие вкладки,
// срок публикации, текст сообщения и строка материала. Всё, что читает и пишет tbl_news_item,
// проверяет NewsItemsTest на настоящей базе.
final class NewsPublishRulesTest extends TestCase
{
    private const NOW = '2026-10-07 09:00:00';

    public function testНезнакомаяВкладкаСтановитсяОбщей(): void
    {
        $cases = [
            'пусто' => '',
            'опечатка' => 'published',
            'чужой текст' => '<script>',
            'регистр' => 'draft',
        ];

        foreach ($cases as $label => $value) {
            self::assertSame('all', parseAdminFilters(['status' => $value])['status'], $label);
        }

        foreach (NEWS_FILTERS as $filter) {
            self::assertSame($filter, parseAdminFilters(['status' => $filter])['status'], $filter);
        }
    }

    public function testУсловияСпискаОбрезаютсяПоПределам(): void
    {
        $filters = parseAdminFilters([
            'cat' => str_repeat('a', 40),
            'q' => str_repeat('б', SEARCH_MAX_LENGTH + 30),
            'page' => '3',
        ]);

        self::assertSame(str_repeat('a', NEWS_ID_MAX), $filters['categoryId']);
        self::assertSame(SEARCH_MAX_LENGTH, mb_strlen($filters['query']));
        self::assertSame(3, $filters['page']);
    }

    public function testПределИдентификатораРавенЕгоДлине(): void
    {
        // Если newId() когда-нибудь изменится, фильтр рубрики начнёт обрезать идентификатор:
        // проверка ловит это здесь, а не пустым списком в админке.
        self::assertSame(mb_strlen(newId()), NEWS_ID_MAX);
    }

    public function testНомерСтраницыЧитаетсяТолькоЦелымБольшеЕдиницы(): void
    {
        $cases = [['', 1], ['0', 1], ['1', 1], ['-3', 1], ['abc', 1], ['2', 2], ['17', 17], [' 4 ', 4], ['2.7', 1]];

        foreach ($cases as $case) {
            $raw = (string) $case[0];
            self::assertSame((int) $case[1], parseAdminFilters(['page' => $raw])['page'], sprintf('«%s»', $raw));
        }
    }

    public function testСсылкиСпискаНесутТекущиеУсловия(): void
    {
        self::assertSame(NEWS_ADMIN_ITEMS_PATH, adminItemsHref([]));
        self::assertSame(NEWS_ADMIN_ITEMS_PATH, adminItemsHref(['status' => 'all', 'page' => 1]));
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT', adminItemsHref(['status' => 'DRAFT']));
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '?page=5', adminItemsHref(['page' => 5]));
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?status=scheduled&cat=id-1&q=%D0%BA%D0%BE%D1%82&page=2',
            adminItemsHref(['status' => 'scheduled', 'categoryId' => 'id-1', 'query' => 'кот', 'page' => 2])
        );
    }

    public function testУсловиеВкладкиЖдутСрокаОтличаетПубликациюПоСроку(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $scheduled = adminWhere(parseAdminFilters(['status' => 'scheduled']), $now);
        self::assertStringContainsString("status = '" . NEWS_STATUS['PUBLISHED'] . "'", $scheduled['where']);
        self::assertStringContainsString('published_at IS NULL OR published_at > ?', $scheduled['where']);
        self::assertSame([utcStamp($now)], $scheduled['args']);

        $opened = adminWhere(parseAdminFilters(['status' => 'PUBLISHED']), $now);
        self::assertSame(visibleWhere(), $opened['where'], 'вкладка «Опубликованные» читает то же условие, что и лента');
        self::assertSame(visibleArgs($now), $opened['args']);

        $draft = adminWhere(parseAdminFilters(['status' => 'DRAFT']), $now);
        self::assertSame("status = '" . NEWS_STATUS['DRAFT'] . "'", $draft['where']);
        self::assertSame([], $draft['args']);

        $all = adminWhere(parseAdminFilters([]), $now);
        self::assertSame('1', $all['where'], 'у общей вкладки условия нет');
        self::assertSame([], $all['args']);
    }

    public function testПоискСпискаЭкранируетСпецсимволыLike(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $escaped = adminWhere(parseAdminFilters(['q' => '100%_']), $now);
        self::assertSame(['%100\\%\\_%', '%100\\%\\_%', '%100\\%\\_%'], $escaped['args']);

        $scope = adminWhere(parseAdminFilters(['cat' => 'id-1', 'q' => 'вечер']), $now);
        // Единица появляется только когда условия нет вовсе (общая вкладка): здесь они есть.
        self::assertSame(
            'i.category_id = ? AND (i.title LIKE ? OR i.lead_text LIKE ? OR i.slug LIKE ?)',
            $scope['where']
        );
        self::assertSame(['id-1', '%вечер%', '%вечер%', '%вечер%'], $scope['args']);
    }

    public function testСрокПубликацииПодставляетсяПриВыпуске(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $later = new DateTimeImmutable('2026-10-08 09:00:00');

        self::assertSame($now, publishDateOf(NEWS_STATUS['PUBLISHED'], null, $now), 'выпуск без срока — сейчас');
        self::assertSame($later, publishDateOf(NEWS_STATUS['PUBLISHED'], $later, $now));
        self::assertNull(publishDateOf(NEWS_STATUS['DRAFT'], null, $now));
        self::assertSame($later, publishDateOf(NEWS_STATUS['DRAFT'], $later, $now), 'снятие с сайта срок сохраняет');
    }

    public function testСообщениеПослеСохраненияЗависитОтСтатусаИСрока(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $later = new DateTimeImmutable('2026-10-07 18:00:00');

        /** @var list<array{0: string, 1: DateTimeImmutable|null, 2: bool, 3: string}> $cases */
        $cases = [
            [NEWS_STATUS['DRAFT'], null, true, 'created'],
            [NEWS_STATUS['DRAFT'], $later, true, 'created'],
            [NEWS_STATUS['DRAFT'], null, false, 'unpublished'],
            [NEWS_STATUS['PUBLISHED'], $later, false, 'scheduled'],
            [NEWS_STATUS['PUBLISHED'], $now, false, 'published'],
            [NEWS_STATUS['PUBLISHED'], null, false, 'published'],
        ];

        foreach ($cases as $case) {
            self::assertSame(
                $case[3],
                publishNotice($case[0], $case[1], $now, $case[2]),
                $case[0] . ' / ' . ($case[2] ? 'создание' : 'правка')
            );
        }
    }

    public function testКаждыйКлючСообщенияЕстьВСловаре(): void
    {
        $keys = ['created', 'published', 'scheduled', 'updated', 'unpublished', 'pinned', 'unpinned', 'unchanged', 'slugChanged'];

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, NEWS_NOTICE_TEXT, $key);
            self::assertNotSame('', NEWS_NOTICE_TEXT[$key], $key);
        }
    }

    public function testПустоеПолеСтановитсяНулевымЗначением(): void
    {
        self::assertNull(orNull(''));
        self::assertSame('Описание', orNull('Описание'));
    }

    public function testНаПечатьНужныАбзацы(): void
    {
        self::assertTrue(hasParagraphs([['title' => '', 'text' => 'Текст']]));
        self::assertTrue(hasParagraphs('[{"title":"","text":"Текст"}]'), 'колонка базы приходит строкой JSON');
        self::assertFalse(hasParagraphs([]));
        self::assertFalse(hasParagraphs(''));
        self::assertFalse(hasParagraphs('{не json'));
        self::assertFalse(hasParagraphs([['title' => '', 'text' => '   ']]), 'абзац из пробелов текстом не считается');

        self::assertSame('', publishTextError(NEWS_STATUS['DRAFT'], []));
        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, publishTextError(NEWS_STATUS['PUBLISHED'], []));
        self::assertSame('', publishTextError(NEWS_STATUS['PUBLISHED'], [['title' => '', 'text' => 'Текст']]));
    }

    public function testСтрокаМатериалаСобираетсяИзПроверенныхПолей(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $editor = ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];

        $input = [
            'title' => 'Вечер встречи',
            'lead' => '',
            'cover' => null,
            'categoryId' => 'kategoriya-1',
            'body' => [['title' => 'Заголовок', 'text' => 'Текст']],
            'status' => NEWS_STATUS['DRAFT'],
            'pinned' => true,
            'seoTitle' => '',
            'seoDescription' => 'Описание',
            'ogImage' => '/uploads/news/og.jpg',
            'noindex' => false,
        ];

        $draft = dataOf($input, null, $editor, false, $now);

        self::assertNull($draft['lead'], 'пустой лид — NULL, а не пустая строка');
        self::assertNull($draft['cover']);
        self::assertNull($draft['seo_title']);
        self::assertNull($draft['published_at']);
        self::assertSame(1, $draft['pinned']);
        self::assertSame(0, $draft['noindex']);
        self::assertSame('Описание', $draft['seo_description']);
        self::assertSame('/uploads/news/og.jpg', $draft['og_image']);
        self::assertSame(utcStamp($now), $draft['updated_at']);
        self::assertArrayNotHasKey('author_id', $draft, 'черновик снимок автора не ставит');
        self::assertArrayNotHasKey('author_email', $draft);
        self::assertSame(
            [['title' => 'Заголовок', 'text' => 'Текст']],
            json_decode((string) $draft['body'], true, 3, JSON_THROW_ON_ERROR)
        );

        $published = dataOf($input, $now, $editor, true, $now);

        self::assertSame('redaktor-1', $published['author_id']);
        self::assertSame('redaktor@shkola.ru', $published['author_email']);
        self::assertSame(utcStamp($now), $published['published_at']);
    }

    public function testСтрокаСпискаЧитаетСловарьСтатусов(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $row = [
            'id' => 'id-1',
            'slug' => 'vecher',
            'title' => 'Вечер встречи',
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp($now->sub(new DateInterval('PT1H'))),
            'pinned' => 1,
            'updated_at' => utcStamp($now),
            'author_email' => null,
            'category_title' => 'Жизнь школы',
            'category_visible' => 0,
        ];

        $item = adminItemRow($row, ['Выпускник'], $now);

        self::assertSame('опубликована', $item['statusLabel']);
        self::assertTrue($item['open']);
        self::assertFalse($item['scheduled']);
        self::assertTrue($item['pinned']);
        self::assertSame('Жизнь школы', $item['categoryTitle']);
        self::assertFalse($item['categoryVisible'], 'скрытая рубрика видна в списке, но помечена');
        self::assertSame('/news/vecher', $item['href']);
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '/id-1', $item['editHref']);
        self::assertSame(['Выпускник'], $item['tags']);
        self::assertSame('', $item['authorEmail']);
        self::assertNotSame('—', $item['published']);

        $waiting = adminItemRow(
            array_merge($row, ['published_at' => utcStamp($now->add(new DateInterval('PT1H')))]),
            [],
            $now
        );

        self::assertFalse($waiting['open']);
        self::assertTrue($waiting['scheduled']);

        $draft = adminItemRow(array_merge($row, ['status' => NEWS_STATUS['DRAFT'], 'published_at' => null]), [], $now);

        self::assertSame('черновик', $draft['statusLabel']);
        self::assertFalse($draft['open']);
        self::assertFalse($draft['scheduled']);
        self::assertSame('—', $draft['published'], 'у черновика срока нет');
    }

    public function testИмяКолонкиВОбновленииПроверяется(): void
    {
        self::assertSame(['title = ?', 'pinned = ?'], updateSet(['title' => 'Вечер', 'pinned' => 1]));

        $this->expectException(InvalidArgumentException::class);
        updateSet(['title; DROP TABLE tbl_user' => 'x']);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsPublishRulesTest`
Expected: FAIL — `Call to undefined function parseAdminFilters()`.

- [ ] **Шаг 2: Написать `apps/news/lib/items.php`**

```php
<?php

declare(strict_types=1);

// Раздел глазами редактора: вкладки списка, строка формы, сохранение материала и кнопки состояния.
// Порт english-portal/src/apps/news/lib/items.ts. Публичная лента читает свои выборки в feed.php;
// выборки этого файла сознательно НЕ ограничены наступившим сроком — черновик и «ещё не время»
// обязан видеть тот, кто раздел правит (§8.2).
//
// Это единственное место раздела, которое читает tbl_news_item без visibleWhere(), и оно названо
// в страже видимости (задача 20), чтобы правило не разъехалось молча.

// Идентификатор материала и рубрики приходит из адресной строки: столько занимает строковый
// идентификатор из core/id.php, и более длинное значение не нашло бы строку.
const NEWS_ID_MAX = 26;

// Путь списка администратора — одна строка на ссылки, маршруты и форму.
const NEWS_ADMIN_ITEMS_PATH = '/news/admin/items';

// Порядок списка — по последней правке, а не по сроку: редактор возвращается к тому, что трогал
// последним, а «закреплённые вперёд» здесь только путали бы картину. Хвост из created_at и id
// обязателен: у строк одной секунды порядок иначе решала бы база, и материал повторялся бы на двух
// страницах списка или пропадал между ними.
const ADMIN_ITEMS_ORDER = 'i.updated_at DESC, i.created_at DESC, i.id DESC';

// Единственная формулировка правила «на печать выходят с текстом»: её читают и кнопка списка, и
// форма, чтобы два пути не разошлись ни словами, ни смыслом. Пустая статья на сайте — пустая
// карточка в ленте.
const NEWS_PUBLISH_NEEDS_TEXT = 'Материал выходит на печать с текстом — допишите абзацы в форме.';

// ── Вкладки и ссылки списка ──────────────────────────────

// Неизвестное значение вкладки — «все материалы»: адрес пишет человек или чужой скрипт,
// и падать из-за опечатки незачем.
function statusFilterOf(string $value): string
{
    return in_array($value, NEWS_FILTERS, true) ? $value : 'all';
}

// Номер страницы читается только целым числом: «2.7» и «abc» вернули бы отрицательное смещение,
// а MySQL ответил бы ошибкой на OFFSET -1.
function pageOf(string $value): int
{
    $raw = trim($value);

    if (preg_match('/^\d+$/', $raw) !== 1) {
        return 1;
    }

    $page = (int) $raw;

    return $page > 1 ? $page : 1;
}

/**
 * @param array{status?: string, cat?: string, q?: string, page?: string} $raw
 *
 * @return array{status: string, categoryId: string, query: string, page: int}
 */
function parseAdminFilters(array $raw): array
{
    return [
        'status' => statusFilterOf((string) ($raw['status'] ?? '')),
        'categoryId' => mb_substr(trim((string) ($raw['cat'] ?? '')), 0, NEWS_ID_MAX),
        'query' => parseQuery($raw['q'] ?? null),
        'page' => pageOf((string) ($raw['page'] ?? '')),
    ];
}

/**
 * Все ссылки списка несут текущие условия: ссылка, которая их теряет, возвращает человека в другой
 * список, чем тот, что он видел. Пустые значения в строку запроса не пишутся, а первая страница не
 * пишется вовсе — `?page=1` дублировал бы чистую ссылку в глазах поисковика.
 *
 * @param array{status?: string, categoryId?: string, query?: string, page?: int} $filters
 */
function adminItemsHref(array $filters): string
{
    $parts = [];
    $status = statusFilterOf((string) ($filters['status'] ?? ''));

    if ($status !== 'all') {
        $parts[] = 'status=' . rawurlencode($status);
    }

    $category = mb_substr(trim((string) ($filters['categoryId'] ?? '')), 0, NEWS_ID_MAX);

    if ($category !== '') {
        $parts[] = 'cat=' . rawurlencode($category);
    }

    $query = parseQuery($filters['query'] ?? null);

    if ($query !== '') {
        $parts[] = 'q=' . rawurlencode($query);
    }

    $page = (int) ($filters['page'] ?? 1);

    if ($page > 1) {
        $parts[] = 'page=' . $page;
    }

    return $parts === [] ? NEWS_ADMIN_ITEMS_PATH : NEWS_ADMIN_ITEMS_PATH . '?' . implode('&', $parts);
}

/**
 * Условие вкладки. «Ждут срока» и «опубликованные» — один статус в базе, их различает срок,
 * поэтому момент приходит аргументом, а не подставляется в текст условия.
 *
 * Колонки условия видимости написаны без префикса — так их отдаёт visibleWhere(), и в соединении
 * с рубрикой они однозначны: в tbl_news_category нет ни `status`, ни `published_at`. Все колонки,
 * которые есть в обеих таблицах (`slug`, `title`), написаны с префиксом `i.`.
 *
 * @param array{status?: string, categoryId?: string, query?: string, page?: int} $filters
 *
 * @return array{where: string, args: list<string>}
 */
function adminWhere(array $filters, DateTimeImmutable $now): array
{
    $parts = [];
    $args = [];
    $status = statusFilterOf((string) ($filters['status'] ?? ''));

    if ($status === 'scheduled') {
        // Строка без срока считается ждущей: isOpen() её не открывает, значит вкладка «Ждут срока»
        // обязана её показать — иначе материал потерялся бы между вкладками.
        $parts[] = sprintf(
            "status = '%s' AND (published_at IS NULL OR published_at > ?)",
            NEWS_STATUS['PUBLISHED']
        );
        $args[] = utcStamp($now);
    } elseif ($status === NEWS_STATUS['PUBLISHED']) {
        // Вкладка «Опубликованные» показывает то же, что видит читатель: одно условие на двоих.
        $parts[] = visibleWhere();
        $args = visibleArgs($now);
    } elseif ($status === NEWS_STATUS['DRAFT']) {
        $parts[] = sprintf("status = '%s'", NEWS_STATUS['DRAFT']);
    }

    $categoryId = mb_substr(trim((string) ($filters['categoryId'] ?? '')), 0, NEWS_ID_MAX);

    if ($categoryId !== '') {
        // Чужой идентификатор рубрики — не ошибка, а пустой список.
        $parts[] = 'i.category_id = ?';
        $args[] = $categoryId;
    }

    $query = parseQuery($filters['query'] ?? null);

    if ($query !== '') {
        $like = '%' . escapeLike($query) . '%';
        $parts[] = '(i.title LIKE ? OR i.lead_text LIKE ? OR i.slug LIKE ?)';
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
    }

    // Пустое условие — единица: `WHERE 1` читается как «все строки», а без него каждому вызову
    // пришлось бы ветвиться на «есть условие» и «условия нет».
    return ['where' => $parts === [] ? '1' : implode(' AND ', $parts), 'args' => $args];
}

// ── Список ───────────────────────────────────────────────

/**
 * Надписи тегов для строк страницы — одним запросом: по запросу на строку список из двадцати
 * материалов открывался бы сорока запросами, а виртуальный хостинг считает каждый.
 *
 * @param list<string> $itemIds
 *
 * @return array<string, list<string>>
 */
function adminTagsOf(array $itemIds): array
{
    $ids = array_values(array_filter($itemIds, static fn (string $id): bool => $id !== ''));

    if ($ids === []) {
        return [];
    }

    $sql = sprintf(
        'SELECT link.item_id, tg.label FROM %s link JOIN %s tg ON tg.id = link.tag_id'
            . ' WHERE link.item_id IN (%s) ORDER BY tg.label',
        t('news_item_tag'),
        t('news_tag'),
        inPlaceholders(count($ids))
    );

    $out = [];

    foreach (all($sql, $ids) as $row) {
        $out[(string) $row['item_id']][] = (string) $row['label'];
    }

    return $out;
}

/**
 * Строка списка из строки базы. Моменты приводятся к московскому времени здесь: шаблон не имеет
 * права сам решать, в каком времени печатать (страж DateUsageTest не пускает туда date() и format()).
 *
 * @param array<string, mixed> $row
 * @param list<string> $tags
 *
 * @return array{
 *     id: string, slug: string, title: string, status: string, statusLabel: string,
 *     open: bool, scheduled: bool, pinned: bool, href: string, editHref: string,
 *     publishedAt: DateTimeImmutable|null, published: string,
 *     updatedAt: DateTimeImmutable|null, updated: string,
 *     categoryTitle: string, categoryVisible: bool, tags: list<string>, authorEmail: string
 * }
 */
function adminItemRow(array $row, array $tags, DateTimeImmutable $now): array
{
    $id = (string) $row['id'];
    $slug = (string) $row['slug'];
    $status = (string) $row['status'];
    $published = fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null);
    $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

    return [
        'id' => $id,
        'slug' => $slug,
        'title' => (string) $row['title'],
        'status' => $status,
        'statusLabel' => NEWS_STATUS_LABEL[$status] ?? $status,
        'open' => isOpen($row, $now),
        'scheduled' => isScheduled($row, $now),
        'pinned' => (int) ($row['pinned'] ?? 0) === 1,
        'href' => newsHref(['slug' => $slug]),
        'editHref' => NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id),
        'publishedAt' => $published,
        'published' => formatRuDateTime($published),
        'updatedAt' => $updated,
        'updated' => formatRuDateTime($updated),
        // Рубрика присоединяется LEFT JOIN: строка с удалённой рубрикой обязана открыться в списке
        // и сказать об этом, а не уронить страницу.
        'categoryTitle' => (string) ($row['category_title'] ?? ''),
        'categoryVisible' => (int) ($row['category_visible'] ?? 0) === 1,
        'tags' => $tags,
        'authorEmail' => (string) ($row['author_email'] ?? ''),
    ];
}

/**
 * Страница списка администратора.
 *
 * @param array{status?: string, categoryId?: string, query?: string, page?: int} $filters
 *
 * @return array{
 *     items: list<array<string, mixed>>, total: int, pages: int, current: int, window: list<int>,
 *     perPage: int, href: string
 * }
 */
function adminItemPage(array $filters, DateTimeImmutable $now): array
{
    $scope = adminWhere($filters, $now);
    $from = sprintf('%s i LEFT JOIN %s c ON c.id = i.category_id', t('news_item'), t('news_category'));

    $total = (int) value(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $from, $scope['where']), $scope['args']);
    $pages = pageCount($total, ADMIN_NEWS_PER_PAGE);
    $current = clampPage(pageOf((string) ($filters['page'] ?? 1)), $pages);

    $sql = sprintf(
        'SELECT i.id, i.slug, i.title, i.status, i.published_at, i.pinned, i.updated_at, i.author_email,'
            . ' c.title AS category_title, c.visible AS category_visible'
            . ' FROM %s WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
        $from,
        $scope['where'],
        ADMIN_ITEMS_ORDER,
        ADMIN_NEWS_PER_PAGE,
        ($current - 1) * ADMIN_NEWS_PER_PAGE
    );

    $rows = all($sql, $scope['args']);
    $tags = adminTagsOf(array_map(static fn (array $row): string => (string) $row['id'], $rows));
    $items = [];

    foreach ($rows as $row) {
        $items[] = adminItemRow($row, $tags[(string) $row['id']] ?? [], $now);
    }

    return [
        'items' => $items,
        'total' => $total,
        'pages' => $pages,
        'current' => $current,
        'window' => pageWindow($current, $pages),
        'perPage' => ADMIN_NEWS_PER_PAGE,
        'href' => adminItemsHref($filters),
    ];
}

/**
 * Счётчики вкладок. Число берётся четырьмя запросами, а не группировкой по статусу: срок публикации —
 * часть условия вкладки, и группировка по одной колонке его не учитывает.
 *
 * @return array<string, int>
 */
function adminItemCounts(DateTimeImmutable $now): array
{
    $counts = [];

    foreach (NEWS_FILTERS as $filter) {
        $scope = adminWhere(parseAdminFilters(['status' => $filter]), $now);
        $counts[$filter] = (int) value(
            sprintf('SELECT COUNT(*) FROM %s i WHERE %s', t('news_item'), $scope['where']),
            $scope['args']
        );
    }

    return $counts;
}

/** Закреплённых и открытых материалов — столько же, сколько их видит читатель на первой странице. */
function pinnedVisibleCount(DateTimeImmutable $now): int
{
    return (int) value(
        sprintf('SELECT COUNT(*) FROM %s i WHERE %s AND i.pinned = 1', t('news_item'), visibleWhere()),
        visibleArgs($now)
    );
}

// ── Форма ────────────────────────────────────────────────

/** @return list<string> */
function oldSlugsOf(string $itemId): array
{
    if ($itemId === '') {
        return [];
    }

    $sql = 'SELECT slug FROM ' . t('news_old_slug') . ' WHERE item_id = ? ORDER BY created_at DESC, slug DESC';
    $out = [];

    foreach (all($sql, [$itemId]) as $row) {
        $out[] = (string) $row['slug'];
    }

    return $out;
}

/**
 * Строка формы. Правит тот материал, что лежит в базе, а не только тот, что видно на сайте:
 * в форму обязаны вернуться и черновик, и материал со сроком в будущем.
 *
 * Поля формы — строки: textarea читает текст абзацев, а не JSON, а поле тегов — одна строка через
 * запятую, а не список. Возврат введённого после отказа идёт в те же имена.
 *
 * @return array{
 *     id: string, slug: string, title: string, lead: string, cover: string, categoryId: string,
 *     categoryTitle: string, categoryVisible: bool, bodyText: string, tags: string, status: string,
 *     statusLabel: string, publishedAt: string, pinned: bool, seoTitle: string, seoDescription: string,
 *     ogImage: string, noindex: bool, oldSlugs: list<string>, authorEmail: string,
 *     updatedAt: DateTimeImmutable|null, updated: string, href: string, editHref: string
 * }|null
 */
function itemForForm(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    $sql = sprintf(
        'SELECT i.id, i.slug, i.title, i.lead_text, i.cover, i.category_id, i.body, i.status, i.published_at,'
            . ' i.pinned, i.seo_title, i.seo_description, i.og_image, i.noindex, i.author_email, i.updated_at,'
            . ' c.title AS category_title, c.visible AS category_visible'
            . ' FROM %s i LEFT JOIN %s c ON c.id = i.category_id WHERE i.id = ?',
        t('news_item'),
        t('news_category')
    );

    $row = one($sql, [$id]);

    if ($row === null) {
        return null;
    }

    $itemId = (string) $row['id'];
    $slug = (string) $row['slug'];
    $status = (string) $row['status'];
    $published = fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null);
    $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);
    $labels = [];

    foreach (itemTags($itemId) as $tag) {
        $labels[] = (string) $tag['label'];
    }

    return [
        'id' => $itemId,
        'slug' => $slug,
        'title' => (string) $row['title'],
        'lead' => (string) ($row['lead_text'] ?? ''),
        'cover' => (string) ($row['cover'] ?? ''),
        'categoryId' => (string) $row['category_id'],
        'categoryTitle' => (string) ($row['category_title'] ?? ''),
        'categoryVisible' => (int) ($row['category_visible'] ?? 0) === 1,
        'bodyText' => bodyToFormText(paragraphsOf($row['body'] ?? null)),
        'tags' => implode(', ', $labels),
        'status' => $status,
        'statusLabel' => NEWS_STATUS_LABEL[$status] ?? $status,
        'publishedAt' => formPublishedAt($published),
        'pinned' => (int) ($row['pinned'] ?? 0) === 1,
        'seoTitle' => (string) ($row['seo_title'] ?? ''),
        'seoDescription' => (string) ($row['seo_description'] ?? ''),
        'ogImage' => (string) ($row['og_image'] ?? ''),
        'noindex' => (int) ($row['noindex'] ?? 0) === 1,
        'oldSlugs' => oldSlugsOf($itemId),
        'authorEmail' => (string) ($row['author_email'] ?? ''),
        'updatedAt' => $updated,
        'updated' => formatRuDateTime($updated),
        'href' => newsHref(['slug' => $slug]),
        'editHref' => NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($itemId),
    ];
}

// ── Срок, текст и сообщения ──────────────────────────────

// Опубликованная строка обязана иметь срок: без него её не показывает ни лента, ни карта сайта,
// а вкладка «Опубликованные» строится на наступившем сроке. Пустое поле при выпуске — «сейчас».
function publishDateOf(string $status, ?DateTimeImmutable $publishedAt, DateTimeImmutable $now): ?DateTimeImmutable
{
    if ($status !== NEWS_STATUS['PUBLISHED']) {
        return $publishedAt;
    }

    return $publishedAt ?? $now;
}

/**
 * Что сказать человеку после сохранения: выпуск по сроку — «ждёт срока», выпуск без срока — «на сайте»,
 * черновик при создании — «сохранён», при правке — «снят с сайта». Ключ читает NEWS_NOTICE_TEXT,
 * а страница — noticeOfRequest() из core/notice.php.
 */
function publishNotice(string $status, ?DateTimeImmutable $publishedAt, DateTimeImmutable $now, bool $created): string
{
    if ($status !== NEWS_STATUS['PUBLISHED']) {
        return $created ? 'created' : 'unpublished';
    }

    return $publishedAt !== null && $publishedAt > $now ? 'scheduled' : 'published';
}

// Пустое значение поля — NULL в базе, а не пустая строка: карточка и метаданные различают «не задано»
// и «задано пусто», иначе seoOf() взял бы пустой Title вместо заголовка.
function orNull(string $value): ?string
{
    return $value === '' ? null : $value;
}

// Рубрику могли удалить из справочника, пока форма висела открытой: ответ обязан называть причину,
// а не падать на внешнем ключе.
function checkCategory(string $categoryId): string
{
    if ($categoryId === '') {
        return 'Выберите рубрику: без неё материал не попадёт ни в один раздел.';
    }

    if (!categoryExists($categoryId)) {
        return 'Рубрики, в которую вы кладёте материал, уже нет — выберите другую.';
    }

    return '';
}

// Абзацы бывают и списком (форма), и строкой JSON (колонка базы): проверка одна для обоих видов,
// потому что оба читает paragraphsOf() из feed.php.
function hasParagraphs(mixed $body): bool
{
    return paragraphsOf($body) !== [];
}

/** То же правило для обоих путей: '' — на печать можно, иначе текст отказа. */
function publishTextError(string $status, mixed $body): string
{
    if ($status !== NEWS_STATUS['PUBLISHED']) {
        return '';
    }

    return hasParagraphs($body) ? '' : NEWS_PUBLISH_NEEDS_TEXT;
}

// Абзацы пишутся строкой JSON: колонка body — JSON, а не текст. Недопустимый UTF-8 до сюда не
// доходит (preg_replace с флагом /u возвращает null раньше, и поле становится пустым), поэтому
// отказ здесь — признак порчи данных: пишем пустой список и событие в журнал, а не роняем сохранение.
function bodyJson(mixed $paragraphs): string
{
    try {
        return json_encode(paragraphsOf($paragraphs), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        logWarn('news.body_encode_failed', ['message' => $error->getMessage()]);

        return '[]';
    }
}

/**
 * @param mixed $raw
 *
 * @return list<string>
 */
function tagLabelsOf(mixed $raw): array
{
    $out = [];

    foreach (is_array($raw) ? $raw : [] as $label) {
        if (is_string($label) || is_int($label)) {
            $out[] = (string) $label;
        }
    }

    return $out;
}

/**
 * Колонки материала из разобранных полей формы. Снимок автора обновляет только выпуск на печать:
 * создателя черновика правка менять не должна, а карточка остаётся читаемой и после удаления аккаунта.
 *
 * @param array<string, mixed> $input
 * @param array{id: string, email: string} $editor
 *
 * @return array<string, mixed>
 */
function dataOf(array $input, ?DateTimeImmutable $publishedAt, array $editor, bool $publishedNow, DateTimeImmutable $now): array
{
    $row = [
        'title' => (string) ($input['title'] ?? ''),
        'lead_text' => orNull((string) ($input['lead'] ?? '')),
        'cover' => orNull((string) ($input['cover'] ?? '')),
        'category_id' => (string) ($input['categoryId'] ?? ''),
        'body' => bodyJson($input['body'] ?? []),
        'status' => (string) ($input['status'] ?? NEWS_STATUS['DRAFT']),
        'published_at' => $publishedAt === null ? null : utcStamp($publishedAt),
        'pinned' => ($input['pinned'] ?? false) === true ? 1 : 0,
        'seo_title' => orNull((string) ($input['seoTitle'] ?? '')),
        'seo_description' => orNull((string) ($input['seoDescription'] ?? '')),
        'og_image' => orNull((string) ($input['ogImage'] ?? '')),
        'noindex' => ($input['noindex'] ?? false) === true ? 1 : 0,
        'updated_at' => utcStamp($now),
    ];

    if ($publishedNow) {
        $row['author_id'] = (string) $editor['id'];
        $row['author_email'] = (string) $editor['email'];
    }

    return $row;
}

/**
 * SET для правки материала: имена колонок приходят из dataOf(), а не из запроса, поэтому подстановка
 * имён безопасна — значения всегда уходят плейсхолдерами. Проверка имени та же, что в insert().
 *
 * @param array<string, mixed> $row
 *
 * @return list<string>
 */
function updateSet(array $row): array
{
    $parts = [];

    foreach (array_keys($row) as $column) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $column) !== 1) {
            throw new InvalidArgumentException('Недопустимое имя колонки: ' . (string) $column);
        }

        $parts[] = $column . ' = ?';
    }

    return $parts;
}

// ── Запись ───────────────────────────────────────────────

/**
 * Занят ли адрес. При правке собственная строка адрес не занимает, иначе сохранение без смены адреса
 * упало бы на коллизии с самим собой.
 */
function slugTaken(string $slug, string $exceptId = ''): bool
{
    if ($slug === '') {
        return false;
    }

    if ($exceptId === '') {
        return (int) value('SELECT COUNT(*) FROM ' . t('news_item') . ' WHERE slug = ?', [$slug]) > 0;
    }

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('news_item') . ' WHERE slug = ? AND id <> ?',
        [$slug, $exceptId]
    ) > 0;
}

/**
 * Прежние адреса пишутся строками: tbl_news_old_slug заменяет JSON-массив из PostgreSQL, потому что
 * поиск по JSON без индекса сканировал бы таблицу на каждом открытии старого адреса. Список
 * перезаписывается целиком — он короткий (OLD_SLUGS_MAX), а резать его обязана rememberOldSlug(),
 * а не запись в базу.
 *
 * @param list<string> $slugs
 */
function saveOldSlugs(string $itemId, array $slugs): void
{
    run('DELETE FROM ' . t('news_old_slug') . ' WHERE item_id = ?', [$itemId]);

    foreach (array_slice($slugs, 0, OLD_SLUGS_MAX) as $slug) {
        if ($slug === '') {
            continue;
        }

        // Адрес мог раньше принадлежать другому материалу: PRIMARY KEY (slug) не даёт положить его
        // дважды, поэтому чужая строка убирается — перенаправление ведёт на свежий материал.
        run('DELETE FROM ' . t('news_old_slug') . ' WHERE slug = ?', [$slug]);
        insert('news_old_slug', ['slug' => $slug, 'item_id' => $itemId, 'created_at' => utcStamp(nowUtc())]);
    }
}

/**
 * Связи тегов перезаписываются целиком: список короткий (TAG_FIELDS['perItem']), а сравнение старого
 * и нового наборов стоило бы двух запросов вместо одного удаления.
 *
 * @param list<string> $tagIds
 */
function linkItemTags(string $itemId, array $tagIds): void
{
    run('DELETE FROM ' . t('news_item_tag') . ' WHERE item_id = ?', [$itemId]);

    foreach ($tagIds as $tagId) {
        insert('news_item_tag', ['item_id' => $itemId, 'tag_id' => $tagId]);
    }
}

/** @return array{ok: bool, notice: string, error: string, id: string, slug: string} */
function itemFailure(string $error): array
{
    return ['ok' => false, 'notice' => '', 'error' => $error, 'id' => '', 'slug' => ''];
}

/** @return array{ok: bool, notice: string, error: string, id: string, slug: string} */
function itemSuccess(string $notice, string $id, string $slug): array
{
    return ['ok' => true, 'notice' => $notice, 'error' => '', 'id' => $id, 'slug' => $slug];
}

/**
 * Строка материала для кнопок состояния: статус, срок, закрепление, рубрика и абзацы.
 *
 * @return array<string, mixed>|null
 */
function itemRow(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    return one(
        sprintf(
            'SELECT id, slug, status, published_at, pinned, category_id, body FROM %s WHERE id = ?',
            t('news_item')
        ),
        [$id]
    );
}

/** Момент публикации из разобранных полей формы: значение может прийти и не объектом. */
function publishedAtOf(mixed $raw): ?DateTimeImmutable
{
    return $raw instanceof DateTimeImmutable ? $raw : null;
}

/**
 * Создание материала.
 *
 * @param array<string, mixed> $input значение ключа `data` из parseNewsForm()
 * @param array{id: string, email: string} $editor
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function createItem(array $input, array $editor, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $status = (string) ($input['status'] ?? NEWS_STATUS['DRAFT']);

    $categoryError = checkCategory((string) ($input['categoryId'] ?? ''));

    if ($categoryError !== '') {
        return itemFailure($categoryError);
    }

    // Кнопка «На печать» в форме идёт этим путём, а не кнопкой списка: без отказа здесь форма
    // выкладывала бы пустую карточку, хотя список такой материал не печатает.
    $textError = publishTextError($status, $input['body'] ?? []);

    if ($textError !== '') {
        return itemFailure($textError);
    }

    $plan = newsSlugPlan(
        (string) ($input['slug'] ?? ''),
        (string) ($input['title'] ?? ''),
        static fn (string $candidate): bool => slugTaken($candidate)
    );

    if ($plan['error'] !== '') {
        return itemFailure($plan['error']);
    }

    $id = newId();
    $slug = (string) $plan['slug'];
    $publishedAt = publishDateOf($status, publishedAtOf($input['publishedAt'] ?? null), $moment);
    $tagIds = resolveTagIds(tagLabelsOf($input['tags'] ?? []));
    $publishedNow = $status === NEWS_STATUS['PUBLISHED'];

    tx(static function () use ($id, $slug, $input, $publishedAt, $editor, $publishedNow, $moment, $tagIds): void {
        insert('news_item', array_merge(
            dataOf($input, $publishedAt, $editor, $publishedNow, $moment),
            ['id' => $id, 'slug' => $slug, 'created_at' => utcStamp($moment)]
        ));
        linkItemTags($id, $tagIds);
    });

    logInfo('news.item_created', ['itemId' => $id, 'slug' => $slug, 'status' => $status]);

    return itemSuccess(publishNotice($status, $publishedAt, $moment, true), $id, $slug);
}

/**
 * Правка материала.
 *
 * @param array<string, mixed> $input значение ключа `data` из parseNewsForm()
 * @param array{id: string, email: string} $editor
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function updateItem(string $id, array $input, array $editor, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $row = itemRow($id);

    if ($row === null) {
        return itemFailure('Материала уже нет — обновите страницу.');
    }

    $status = (string) ($input['status'] ?? NEWS_STATUS['DRAFT']);
    $categoryError = checkCategory((string) ($input['categoryId'] ?? ''));

    if ($categoryError !== '') {
        return itemFailure($categoryError);
    }

    // Правило то же, что у создания и у кнопки списка: выпуск пустого текста не выходит на сайт —
    // здесь он возможен, когда черновик дописали не до конца и нажали «На печать».
    $textError = publishTextError($status, $input['body'] ?? []);

    if ($textError !== '') {
        return itemFailure($textError);
    }

    $plan = newsSlugPlan(
        (string) ($input['slug'] ?? ''),
        (string) ($input['title'] ?? ''),
        static fn (string $candidate): bool => slugTaken($candidate, $id)
    );

    if ($plan['error'] !== '') {
        return itemFailure($plan['error']);
    }

    $slug = (string) $plan['slug'];
    $previous = (string) $row['slug'];
    $slugChanged = $slug !== $previous;
    $publishedAt = publishDateOf($status, publishedAtOf($input['publishedAt'] ?? null), $moment);
    $becamePublished = $status === NEWS_STATUS['PUBLISHED'] && (string) $row['status'] !== NEWS_STATUS['PUBLISHED'];
    $tagIds = resolveTagIds(tagLabelsOf($input['tags'] ?? []));

    // Прежний адрес уходит в список перенаправлений: ссылки из закладок и чужих статей обязаны
    // доходить до карточки, а не в 404 (§8.3).
    $oldSlugs = rememberOldSlug(oldSlugsOf($id), $previous, $slug);

    tx(static function () use ($id, $slug, $input, $publishedAt, $editor, $becamePublished, $moment, $tagIds, $oldSlugs, $slugChanged): void {
        $data = array_merge(dataOf($input, $publishedAt, $editor, $becamePublished, $moment), ['slug' => $slug]);

        run(
            'UPDATE ' . t('news_item') . ' SET ' . implode(', ', updateSet($data)) . ' WHERE id = ?',
            array_merge(array_values($data), [$id])
        );
        linkItemTags($id, $tagIds);

        if ($slugChanged) {
            saveOldSlugs($id, $oldSlugs);
        }
    });

    logInfo('news.item_updated', [
        'itemId' => $id,
        'from' => (string) $row['status'],
        'to' => $status,
        'slugChanged' => $slugChanged,
    ]);

    // Переход состояния важнее смены адреса: человек нажал «На печать» и ждёт подтверждения
    // выпуска, а не сообщения про адрес. Если же статус не менялся, смена адреса — главное
    // событие правки: редактор обязан узнать, что прежняя ссылка ушла в перенаправления.
    $notice = $status === (string) $row['status']
        ? ($slugChanged ? 'slugChanged' : 'updated')
        : publishNotice($status, $publishedAt, $moment, false);

    return itemSuccess($notice, $id, $slug);
}

/**
 * Кнопки состояния в списке: «На печать» и «Снять с сайта». Право на переход проверяет сервер,
 * а не наличие кнопки на экране.
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function setItemStatus(string $id, string $to, ?DateTimeImmutable $now = null): array
{
    if (!in_array($to, NEWS_STATUS, true)) {
        return itemFailure('Незнакомое состояние материала.');
    }

    $moment = $now ?? nowUtc();
    $row = itemRow($id);

    if ($row === null) {
        return itemFailure('Материала уже нет — обновите страницу.');
    }

    if ((string) $row['status'] === $to) {
        return itemSuccess('unchanged', (string) $row['id'], (string) $row['slug']);
    }

    if ($to === NEWS_STATUS['PUBLISHED']) {
        // На печать материал выходит текстом и в видимой рубрике: без абзацев карточка пустая,
        // а в скрытой рубрике её негде показать. Тот же отказ отдаёт и форма — publishTextError().
        $textError = publishTextError($to, $row['body'] ?? null);

        if ($textError !== '') {
            return itemFailure($textError);
        }

        $category = one('SELECT title, visible FROM ' . t('news_category') . ' WHERE id = ?', [(string) $row['category_id']]);

        if ($category === null) {
            return itemFailure('Рубрики материала больше нет — выберите другую в форме.');
        }

        if ((int) $category['visible'] !== 1) {
            return itemFailure(sprintf(
                'Рубрика «%s» скрыта: сначала покажите её в справочнике.',
                (string) $category['title']
            ));
        }
    }

    // Снятый с сайта материал срок не теряет: статус возвращается в черновик, а дата остаётся —
    // редактор выпустит его той же кнопкой, не выбирая момент заново.
    $publishedAt = publishDateOf($to, fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null), $moment);

    run(
        sprintf('UPDATE %s SET status = ?, published_at = ?, updated_at = ? WHERE id = ?', t('news_item')),
        [$to, $publishedAt === null ? null : utcStamp($publishedAt), utcStamp($moment), (string) $row['id']]
    );

    logInfo('news.item_status', ['itemId' => (string) $row['id'], 'from' => (string) $row['status'], 'to' => $to]);

    return itemSuccess(publishNotice($to, $publishedAt, $moment, false), (string) $row['id'], (string) $row['slug']);
}

/**
 * Закрепление: первая страница ленты, страницы рубрики и «похожие» идут по orderNews(), где
 * закреплённые впереди. Черновику закрепление бессмысленно — его в ленте нет.
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function setItemPinned(string $id, bool $pinned, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $row = itemRow($id);

    if ($row === null) {
        return itemFailure('Материала уже нет — обновите страницу.');
    }

    if (((int) ($row['pinned'] ?? 0) === 1) === $pinned) {
        return itemSuccess('unchanged', (string) $row['id'], (string) $row['slug']);
    }

    if ($pinned && !isOpen($row, $moment)) {
        return itemFailure('Закреплять можно материал, который уже на сайте: в ленте он идёт первым, а черновика там нет.');
    }

    run(
        sprintf('UPDATE %s SET pinned = ?, updated_at = ? WHERE id = ?', t('news_item')),
        [$pinned ? 1 : 0, utcStamp($moment), (string) $row['id']]
    );

    logInfo('news.item_pinned', ['itemId' => (string) $row['id'], 'pinned' => $pinned]);

    return itemSuccess($pinned ? 'pinned' : 'unpinned', (string) $row['id'], (string) $row['slug']);
}
```

Изменить `apps/news/lib/boot.php` — добавить седьмую строку:

```php
require_once __DIR__ . '/items.php';
```

`items.php` подключается последним: он читает `paragraphsOf()` и `pageWindow()` из `feed.php`,
`resolveTagIds()` из `catalog.php` и `bodyToFormText()` из `validators.php`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/items.php"`
Expected: `No syntax errors detected`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpstan analyse --no-progress apps/news`
Expected: ошибок нет. Если PHPStan не принимает форму `array_merge()` в `adminItemPage()` или
`itemForForm()`, ключи выписываются литералом явно — объявленные в `@return` формы обязательны:
их читают шаблоны задач 17 и 18.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsPublishRulesTest`
Expected: PASS — 15 проверок.

- [ ] **Шаг 3: Написать падающий тест на базе**

Создать `tests/db/NewsItemsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Правила редактора проверяются на настоящей базе: вкладки списка, счётчики, поиск с LIKE,
// сохранение абзацев и тегов, прежние адреса и отказы кнопок состояния — свойства запросов
// и схемы, а не кода.
final class NewsItemsTest extends NewsDbTestCase
{
    /** Момент в прошлом: порядок списка проверяется на разных секундах, иначе его решила бы база. */
    private static function ago(int $minutes): string
    {
        return utcStamp(nowUtc()->sub(new DateInterval('PT' . $minutes . 'M')));
    }

    private static function ahead(int $minutes): string
    {
        return utcStamp(nowUtc()->add(new DateInterval('PT' . $minutes . 'M')));
    }

    /**
     * Поля в том виде, в котором их возвращает parseNewsForm(): createItem() и updateItem() читают
     * разобранные значения, а не сырой POST.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Вечер встречи',
            'slug' => '',
            'lead' => 'Короткий лид.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => ['Выпускник'],
            'status' => NEWS_STATUS['DRAFT'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    private function editor(): array
    {
        return ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private static function slugs(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = (string) $item['slug'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function page(string $filter, int $page = 1): array
    {
        return adminItemPage(parseAdminFilters(['status' => $filter, 'page' => (string) $page]), nowUtc())['items'];
    }

    public function testСписокАдминкиПоказываетИЧерновики(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        self::makeItem($category, 'chernovik', ['updated_at' => self::ago(120)]);
        self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(60),
            'updated_at' => self::ago(60),
        ]);

        $list = adminItemPage(parseAdminFilters([]), nowUtc());

        self::assertSame(2, $list['total']);
        self::assertSame(['na-sajte', 'chernovik'], self::slugs($list['items']), 'свежая правка впереди');
        self::assertTrue($list['items'][0]['open']);
        self::assertFalse($list['items'][1]['open']);
        self::assertSame('Жизнь школы', $list['items'][1]['categoryTitle']);
        self::assertSame(1, $list['pages']);
        self::assertSame([1], $list['window']);
    }

    public function testВкладкиРазличаютЧерновикОпубликованноеИЖдущееСрока(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        self::makeItem($category, 'zhdet', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ahead(120),
            'updated_at' => self::ago(30),
        ]);
        self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(60),
            'updated_at' => self::ago(60),
        ]);
        self::makeItem($category, 'chernovik', ['updated_at' => self::ago(90)]);

        self::assertSame(['zhdet'], self::slugs(self::page('scheduled')));
        self::assertSame(['na-sajte'], self::slugs(self::page('PUBLISHED')));
        self::assertSame(['chernovik'], self::slugs(self::page('DRAFT')));
        self::assertSame(['zhdet', 'na-sajte', 'chernovik'], self::slugs(self::page('all')));

        $counts = adminItemCounts(nowUtc());

        self::assertSame(['all' => 3, 'DRAFT' => 1, 'scheduled' => 1, 'PUBLISHED' => 1], $counts);
    }

    public function testПоискСпискаНаходитПоЗаголовкуЛидуИАдресу(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        self::makeItem($category, 'itogi-goda', ['title' => 'Итоги года', 'lead_text' => 'Что мы сделали']);
        self::makeItem($category, 'vecher', ['title' => 'Вечер встречи', 'lead_text' => 'Выпускники собрались']);

        $cases = [
            'заголовок' => ['итоги', ['itogi-goda']],
            'лид' => ['Выпускники', ['vecher']],
            'адрес' => ['vech', ['vecher']],
            'спецсимволы LIKE' => ['100%', []],
            'ничего не найдено' => ['такого-нет', []],
        ];

        foreach ($cases as $label => $case) {
            $found = adminItemPage(parseAdminFilters(['q' => (string) $case[0]]), nowUtc());
            self::assertSame($case[1], self::slugs($found['items']), $label);
        }
    }

    public function testФильтрПоРубрикеСужаетСписок(): void
    {
        self::makeItem(categoryIdOf('zhizn-shkoly'), 'pervyy');
        self::makeItem(categoryIdOf('metodika'), 'vtoroy');

        $list = adminItemPage(parseAdminFilters(['cat' => categoryIdOf('metodika')]), nowUtc());

        self::assertSame(['vtoroy'], self::slugs($list['items']));
        self::assertSame('Методика', $list['items'][0]['categoryTitle']);
        self::assertStringContainsString('cat=', $list['href'], 'ссылки пагинатора несут фильтр рубрики');
    }

    public function testСтраницаСпискаРежетсяПоПределу(): void
    {
        $category = categoryIdOf('zhizn-shkoly');

        for ($index = 1; $index <= ADMIN_NEWS_PER_PAGE + 5; $index++) {
            self::makeItem($category, 'm-' . $index, ['updated_at' => self::ago($index)]);
        }

        $first = adminItemPage(parseAdminFilters([]), nowUtc());

        self::assertSame(ADMIN_NEWS_PER_PAGE + 5, $first['total']);
        self::assertSame(ADMIN_NEWS_PER_PAGE, count($first['items']));
        self::assertSame(2, $first['pages']);
        self::assertSame('m-1', $first['items'][0]['slug'], 'свежая правка впереди');

        $second = adminItemPage(parseAdminFilters(['page' => '2']), nowUtc());

        self::assertSame(5, count($second['items']));
        self::assertSame('m-21', $second['items'][0]['slug']);
        self::assertSame(2, $second['current']);

        // Страница за концом ленты возвращается к последней: человек, пришедший по старой ссылке,
        // видит материалы, а не пустой список.
        self::assertSame(2, adminItemPage(parseAdminFilters(['page' => '99']), nowUtc())['current']);
    }

    public function testТегиСтрокЧитаютсяОднимЗапросом(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $first = self::makeItem($category, 'pervyy');
        $second = self::makeItem($category, 'vtoroy');
        $tag = resolveTagIds(['Выпускник', 'Олимпиада']);

        self::linkTag($first, $tag[0]);
        self::linkTag($first, $tag[1]);
        self::linkTag($second, $tag[1]);

        $tags = adminTagsOf([$first, $second]);

        self::assertSame(['Выпускник', 'Олимпиада'], $tags[$first]);
        self::assertSame(['Олимпиада'], $tags[$second]);
        self::assertSame([], adminTagsOf([]));
    }

    public function testФормаВозвращаетИЧерновик(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'vecher-vstrechi', [
            'title' => 'Вечер встречи',
            'lead_text' => 'Короткий лид.',
            'body' => json_encode([['title' => 'Подготовка', 'text' => 'Текст']], JSON_THROW_ON_ERROR),
            'status' => NEWS_STATUS['DRAFT'],
            'seo_title' => 'Вечер встречи выпускников',
            'noindex' => 1,
        ]);
        $tag = resolveTagIds(['Выпускник']);
        self::linkTag($id, $tag[0]);
        self::oldSlug($id, 'staryy-adres');

        $form = itemForForm($id);

        self::assertIsArray($form);
        self::assertSame('vecher-vstrechi', $form['slug']);
        self::assertSame("## Подготовка\nТекст", $form['bodyText']);
        self::assertSame('Выпускник', $form['tags']);
        self::assertSame('черновик', $form['statusLabel']);
        self::assertSame('', $form['publishedAt'], 'у черновика срока нет');
        self::assertSame('Короткий лид.', $form['lead']);
        self::assertSame('Вечер встречи выпускников', $form['seoTitle']);
        self::assertTrue($form['noindex']);
        self::assertSame(['staryy-adres'], $form['oldSlugs']);
        self::assertSame('Жизнь школы', $form['categoryTitle']);
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '/' . $id, $form['editHref']);
        self::assertNull(itemForForm('нет-такого-материала'));
        self::assertNull(itemForForm(''));
    }

    public function testСозданиеЧерновикаПишетАбзацыТегиИАдрес(): void
    {
        $result = createItem($this->input(), $this->editor());

        self::assertTrue($result['ok']);
        self::assertSame('created', $result['notice']);
        self::assertSame('vecher-vstrechi', $result['slug'], 'адрес собран из заголовка');

        $id = (string) value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['vecher-vstrechi']);

        self::assertSame($result['id'], $id);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('Короткий лид.', $this->column('SELECT lead FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'черновик снимок автора не ставит');
        // Абзацы читаются тем же разбором, которым их пишет страница: путь внутри JSON плейсхолдером
        // не передают — MySQL 5.7 путь параметром не принимает, а PHP-разбор проверяет и форму записи.
        $paragraphs = paragraphsOf(value('SELECT body FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('Текст абзаца.', (string) ($paragraphs[0]['text'] ?? ''));
        self::assertSame(
            ['Выпускник'],
            array_map(
                static fn (array $row): string => (string) $row['label'],
                all('SELECT tg.label FROM ' . t('news_item_tag') . ' link JOIN ' . t('news_tag') . ' tg ON tg.id = link.tag_id WHERE link.item_id = ?', [$id])
            )
        );
    }

    public function testВыпускБезТекстаНеПроходит(): void
    {
        $result = createItem($this->input(['status' => NEWS_STATUS['PUBLISHED'], 'body' => []]), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, $result['error']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_item')));
    }

    public function testВыпускСтавитСрокСнимокАвтораИОткрываетМатериал(): void
    {
        $result = createItem($this->input(['status' => NEWS_STATUS['PUBLISHED']]), $this->editor());

        self::assertTrue($result['ok']);
        self::assertSame('published', $result['notice']);

        $id = $result['id'];

        self::assertSame('redaktor@shkola.ru', $this->column('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('redaktor-1', $this->column('SELECT author_id FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNotSame('', $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'выпуск без срока — сейчас');
        self::assertSame(['vecher-vstrechi'], self::slugs(findFeed()['items']), 'материал сразу виден в ленте');
    }

    public function testВыпускПоСрокуЖдётСвоегоЧаса(): void
    {
        $later = nowUtc()->add(new DateInterval('PT3H'));
        $result = createItem($this->input(['status' => NEWS_STATUS['PUBLISHED'], 'publishedAt' => $later]), $this->editor());

        self::assertTrue($result['ok']);
        self::assertSame('scheduled', $result['notice']);
        self::assertSame(utcStamp($later), $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$result['id']]));
        self::assertSame([], self::slugs(findFeed()['items']), 'до срока материала в ленте нет');
        self::assertSame(['vecher-vstrechi'], self::slugs(self::page('scheduled')));
    }

    public function testЗанятыйАдресНеОтдаётсяВторомуМатериалу(): void
    {
        $first = createItem($this->input(), $this->editor());
        $second = createItem($this->input(), $this->editor());

        self::assertSame('vecher-vstrechi', $first['slug']);
        self::assertSame('vecher-vstrechi-2', $second['slug'], 'адрес из заголовка нумеруется');

        // Вписанный руками адрес не нумеруется: человек дал ссылку, по которой её и будут ждать.
        $typed = createItem($this->input(['slug' => 'vecher-vstrechi']), $this->editor());

        self::assertFalse($typed['ok']);
        self::assertSame('По такому адресу уже есть материал — придумайте другой.', $typed['error']);
    }

    public function testСлужебныйАдресРазделаЗанят(): void
    {
        $result = createItem($this->input(['slug' => 'admin']), $this->editor());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('занят служебной страницей раздела', $result['error']);
    }

    public function testРубрикиБольшеНетОтказНазываетПричину(): void
    {
        $result = createItem($this->input(['categoryId' => newId()]), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame('Рубрики, в которую вы кладёте материал, уже нет — выберите другую.', $result['error']);

        self::assertFalse(createItem($this->input(['categoryId' => '']), $this->editor())['ok']);
    }

    public function testСменаАдресаЗапоминаетПрежний(): void
    {
        $created = createItem($this->input(), $this->editor());
        $renamed = updateItem(
            $created['id'],
            $this->input(['slug' => 'novyy-adres', 'title' => 'Вечер встречи выпускников']),
            $this->editor()
        );

        self::assertTrue($renamed['ok']);
        self::assertSame('slugChanged', $renamed['notice']);
        self::assertSame('novyy-adres', $renamed['slug']);
        self::assertSame('novyy-adres', $this->column('SELECT slug FROM ' . t('news_item') . ' WHERE id = ?', [$created['id']]));
        self::assertSame(['vecher-vstrechi'], oldSlugsOf($created['id']));

        $form = itemForForm($created['id']);

        self::assertIsArray($form);
        self::assertSame(['vecher-vstrechi'], $form['oldSlugs']);

        // Правка без смены адреса не плодит прежние адреса.
        updateItem($created['id'], $this->input(['slug' => 'novyy-adres', 'lead' => 'Другой лид.']), $this->editor());

        self::assertSame(['vecher-vstrechi'], oldSlugsOf($created['id']));
    }

    public function testПравкаОбновляетПоляТегиИСостояние(): void
    {
        $created = createItem($this->input(), $this->editor());

        $updated = updateItem($created['id'], $this->input([
            'title' => 'Вечер встречи выпускников',
            'lead' => '',
            'tags' => ['Олимпиада'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'pinned' => true,
            'seoDescription' => 'Описание для поисковика.',
        ]), $this->editor());

        self::assertTrue($updated['ok']);
        self::assertSame('published', $updated['notice'], 'переход в опубликованные называет себя, а не «обновлено»');

        $id = $created['id'];

        self::assertSame('Вечер встречи выпускников', $this->column('SELECT title FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT lead FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'пустой лид стирается в NULL');
        self::assertSame('1', $this->column('SELECT pinned FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('Описание для поисковика.', $this->column('SELECT seo_description FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('redaktor@shkola.ru', $this->column('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'выпуск поставил снимок автора');
        self::assertSame(
            ['Олимпиада'],
            array_map(
                static fn (array $row): string => (string) $row['label'],
                all('SELECT tg.label FROM ' . t('news_item_tag') . ' link JOIN ' . t('news_tag') . ' tg ON tg.id = link.tag_id WHERE link.item_id = ?', [$id])
            ),
            'прежние теги заменены, а не добавлены'
        );

        // Правка черновика не меняет автора: снимок ставит только выпуск на печать.
        $draft = createItem($this->input(['slug' => 'chernovik']), $this->editor());
        updateItem($draft['id'], $this->input(['slug' => 'chernovik', 'lead' => 'Новый лид.']), ['id' => 'drugoy', 'email' => 'drugoy@shkola.ru']);

        self::assertNull(value('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$draft['id']]));
    }

    public function testПравкаМатериалаКоторогоНетОтвечаетТекстом(): void
    {
        $result = updateItem(newId(), $this->input(), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame('Материала уже нет — обновите страницу.', $result['error']);
    }

    public function testПравкаНеВыпускаетПустойТекст(): void
    {
        $created = createItem($this->input(), $this->editor());
        $result = updateItem($created['id'], $this->input(['status' => NEWS_STATUS['PUBLISHED'], 'body' => []]), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, $result['error']);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$created['id']]));
    }

    public function testКнопкаНаПечатьВыпускаетМатериал(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'chernovik');

        $result = setItemStatus($id, NEWS_STATUS['PUBLISHED']);

        self::assertTrue($result['ok']);
        self::assertSame('published', $result['notice']);
        self::assertSame('PUBLISHED', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNotSame('', $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame(['chernovik'], self::slugs(findFeed()['items']));
    }

    public function testКнопкаНаПечатьОтказываетБезТекстаИСкрытойРубрике(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $empty = self::makeItem($category, 'bez-teksta', ['body' => '[]']);
        $hidden = self::makeItem(categoryIdOf('metodika'), 'v-skrytoy');

        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, setItemStatus($empty, NEWS_STATUS['PUBLISHED'])['error']);

        toggleCategoryVisibility(categoryIdOf('metodika'));
        $refused = setItemStatus($hidden, NEWS_STATUS['PUBLISHED']);

        self::assertFalse($refused['ok']);
        self::assertSame('Рубрика «Методика» скрыта: сначала покажите её в справочнике.', $refused['error']);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$hidden]));
    }

    public function testСнятиеССайтаСохраняетСрок(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(60),
        ]);

        $result = setItemStatus($id, NEWS_STATUS['DRAFT']);

        self::assertTrue($result['ok']);
        self::assertSame('unpublished', $result['notice']);
        self::assertSame(self::ago(60), $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame([], self::slugs(findFeed()['items']));
    }

    public function testНезнакомоеСостояниеНеПишетсяВБазу(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'chernovik');

        $result = setItemStatus($id, 'ARCHIVED');

        self::assertFalse($result['ok']);
        self::assertSame('Незнакомое состояние материала.', $result['error']);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertFalse(setItemStatus(newId(), NEWS_STATUS['PUBLISHED'])['ok']);
    }

    public function testЗакрепитьМожноТолькоОткрытыйМатериал(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $draft = self::makeItem($category, 'chernovik');
        $open = self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(60),
            'updated_at' => self::ago(60),
        ]);

        $refused = setItemPinned($draft, true);

        self::assertFalse($refused['ok']);
        self::assertSame('Закреплять можно материал, который уже на сайте: в ленте он идёт первым, а черновика там нет.', $refused['error']);

        $pinned = setItemPinned($open, true);

        self::assertTrue($pinned['ok']);
        self::assertSame('pinned', $pinned['notice']);
        self::assertSame(1, pinnedVisibleCount(nowUtc()));

        // Закреплённый материал идёт первым, даже если правка старее.
        self::makeItem($category, 'svezhee', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(10),
            'updated_at' => self::ago(10),
        ]);

        self::assertSame(['na-sajte', 'svezhee'], array_column(findFeed()['items'], 'slug'));

        $unpinned = setItemPinned($open, false);

        self::assertSame('unpinned', $unpinned['notice']);
        self::assertSame(0, pinnedVisibleCount(nowUtc()));
        self::assertFalse(setItemPinned(newId(), true)['ok']);
    }

    public function testПовторноеДействиеНичегоНеМеняет(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $draft = self::makeItem($category, 'chernovik');

        self::assertSame('unchanged', setItemStatus($draft, NEWS_STATUS['DRAFT'])['notice']);
        self::assertSame('unchanged', setItemPinned($draft, false)['notice']);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsItemsTest`
Expected: FAIL — `Call to undefined function adminItemPage()`.

- [ ] **Шаг 4: Прогнать тесты правил редактора**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsItemsTest`
Expected: PASS — 24 проверки. Если падает проверка, правка делается в `items.php`, а не в тесте:
тест описывает правила из спецификации (§8.2, §8.3, §8.5), а не поведение кода.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — все четыре набора зелёные. `CatalogTest` и `NewsFeedTest` не зависят от `items.php`;
`NewsItemsTest` читает `findFeed()` из задачи 14, поэтому прогоняет и её запросы.

- [ ] **Шаг 5: Ворота и фиксация**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add apps/news/lib/items.php apps/news/lib/boot.php tests/unit/NewsPublishRulesTest.php tests/db/NewsItemsTest.php
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git commit -m "Правила редактора раздела «Новости»: вкладки списка, форма материала и сохранение"
```

---

### Задача 16: Публичные страницы раздела — маршруты, шаблоны и метаданные

**Файлы:**
- Создать: `apps/news/routes.php`
- Создать: `apps/news/lib/seo.php`
- Создать: `scripts/make-og-default.php`, `public/assets/og-default.png` (готовый файл, лежит в репозитории)
- Заменить: `apps/news/templates/feed.php` (заглушку задачи 4 — настоящей лентой)
- Создать: `apps/news/templates/single.php`, `apps/news/templates/category.php`, `apps/news/templates/tag.php`
- Создать: `apps/news/templates/partials/search.php`, `partials/categories.php`, `partials/list.php`,
  `partials/card.php`, `partials/pager.php`, `partials/body.php`
- Изменить: `apps/news/lib/boot.php` (восьмая строка — `require_once __DIR__ . '/seo.php';`)
- Изменить: `public/index.php` (`registerAppRoutes()` между маршрутами ядра и разбором запроса)
- Тест: `tests/unit/NewsSeoTest.php`, `tests/http/NewsPublicTest.php`

**Интерфейсы:**
- Потребляет: `render()`, `partial()`, `richText()`, `e()` из `core/template.php` и `core/escape.php`;
  `addAction()` из `core/hooks.php`; `absoluteUrl()` из `core/seo.php`; `excerpt()`, `plural()` из `core/text.php`;
  `moscowIso()`, `nowUtc()` из `core/date.php`; `addRoute()`, `redirect()`, `notFound()`, `queryString()` из
  `core/router.php`, `core/response.php` и `core/request.php`; `getEditorAccess()` из `core/auth.php`;
  `findFeed()`, `findPublished()`, `findAny()`, `findByOldSlug()`, `relatedLinks()`, `newsHref()`, `feedHref()`
  из `apps/news/lib/feed.php`; `findCategory()`, `findTag()`, `visibleCategories()` из `apps/news/lib/catalog.php`;
  `parseQuery()` из `apps/news/lib/validators.php`; `pageOf()` из `apps/news/lib/items.php`;
  `NEWS_STATUS`, `SEARCH_MAX_LENGTH` из `apps/news/lib/constants.php`; `Cms\Tests\http\Kernel`,
  `Cms\Tests\TestDb`, `installApp()`, `seedApp()`, `createItem()`, `saveOldSlugs()`.
- Производит:
  - константы `NEWS_SITE`, `NEWS_OG_SITE_NAME`, `NEWS_TITLE_TEMPLATE`, `NEWS_OG_IMAGE`, `NEWS_OG_WIDTH`,
    `NEWS_OG_HEIGHT`;
  - `newsTitle(string $title): string`, `seoOf(array $item, string $fallback): array{title: string, description: string}`,
    `ogImageOf(array $item): string`, `itemTagLabels(array $item): list<string>`,
    `metaTag(string $name, string $content, bool $property = false): string`, `canonicalTag(string $path): string`,
    `listHeadTags(string $title, string $description, string $path): string`,
    `articleHeadTags(array $item, string $path, bool $open): string`,
    `newsArticleLd(array $item, string $path, string $title, string $description, string $image): array<string, mixed>`,
    `jsonLdTag(array $ld): string`, `listHead(string $title, string $description, string $path): void`,
    `articleHead(array $item, string $path, bool $open): void`;
  - маршруты `GET /news` (`newsFeedPage`), `GET /news/c/{slug}` (`newsCategoryPage`),
    `GET /news/t/{slug}` (`newsTagPage`), `GET /news/{slug}` (`newsSinglePage`) — все с метой `['app' => 'news']`.

**Что сюда не входит:** экраны админки (задачи 17–19), участие раздела в `sitemap.xml` и `robots.txt` (задача 20),
приём картинки обложки (задача 18). Здесь только то, что видит читатель.

**Три правила этого файла:**

1. **Черновик и ненаступивший срок отвечают публике 404** — той же страницей, что и несуществующий материал, иначе
   адрес будущей публикации становится известен по ответу сервера (§8.2). Редактор открывает их по `?draft=1`, и
   такая страница несёт `noindex, nofollow` и пояснение «Так материал видят редакторы».
2. **Метаданные уходят через хук `page_head`.** `render()` собирает `$head` только из него, а layout ядра знает
   про `title`, `description` и `robots` — про приложения он не знает ничего (§5.6). Поэтому canonical, OG и
   JSON-LD печатает раздел, подписавшись на хук до вызова `render()`.
3. **Шаблоны печатают только через `e()`, `partial()` и `richText()`** — страж
   `tests/guard/OutputEscapingTest.php` читает и `apps/news/lib/*.php`, поэтому строки разметки собирают функции,
   а не `echo $переменная`.

**Три расхождения с порталом:**

- **Карточка ленты не печатает теги.** В Next.js теги приходили в карточку одним запросом (`include`), а здесь
  `toCard()` их не читает: запрос на каждую из 12 строк ленты был бы N+1. Теги видны на странице материала —
  там их читает `itemTags()` одним запросом.
- **Каноник печатает хук, а не метаданные фреймворка.** В портале `alternates.canonical` отдавал Next; здесь
  `canonicalTag()` добавляет строку в `page_head`.
- **`?cat=` у ленты остаётся рабочим** (как в портале), но каноник ведёт на `/news/c/…`: одна лента под двумя
  адресами — два одинаковых списка в индексе.

- [ ] **Шаг 1: Написать падающий тест метаданных**

Создать `tests/unit/NewsSeoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

// Метаданные раздела — чистые функции: материал на входе, строка разметки на выходе. База и HTTP
// им не нужны, а ошибка здесь видна только в чужом мессенджере, поэтому правила проверяются отдельно.
final class NewsSeoTest extends TestCase
{
    /** Моменты в UTC: moscowIso() прибавляет три часа, и «09:00» становится «12:00+03:00». */
    private const PUBLISHED = '2026-10-07 09:00:00';
    private const UPDATED = '2026-10-08 15:30:00';

    /**
     * Материал в том виде, в котором его отдаёт toFull(): метаданные читают готовые ключи,
     * а не строку базы.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'id' => 'id-1',
            'slug' => 'vecher-vstrechi',
            'title' => 'Вечер встречи',
            'lead' => 'Короткий лид про вечер.',
            'cover' => '',
            'status' => NEWS_STATUS['PUBLISHED'],
            'open' => true,
            'href' => '/news/vecher-vstrechi',
            'publishedAt' => new DateTimeImmutable(self::PUBLISHED, new DateTimeZone('UTC')),
            'updatedAt' => new DateTimeImmutable(self::UPDATED, new DateTimeZone('UTC')),
            'date' => '7 октября 2026',
            'updated' => '8 октября 2026, 18:30',
            'categorySlug' => 'zhizn-shkoly',
            'categoryTitle' => 'Жизнь школы',
            'paragraphs' => [['title' => '', 'text' => 'Текст абзаца.']],
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'noindex' => false,
            'tags' => [['slug' => 'vypusknik', 'label' => 'Выпускник', 'href' => '/news/t/vypusknik']],
        ], $overrides);
    }

    public function testЗаголовокВложеннойСтраницыНесётНазваниеРаздела(): void
    {
        self::assertSame('Жизнь школы · Новости школы', newsTitle('Жизнь школы'));
    }

    public function testПустоеПолеПоисковикаЗаменяетсяТекстомМатериала(): void
    {
        $seo = seoOf($this->item(), NEWS_SITE['description']);

        self::assertSame('Вечер встречи', $seo['title'], 'заголовок берётся из материала');
        self::assertSame('Короткий лид про вечер.', $seo['description'], 'описание — из лида');
    }

    public function testПолеПоисковикаВажнееТекстаМатериала(): void
    {
        $seo = seoOf($this->item([
            'seoTitle' => 'Вечер встречи выпускников',
            'seoDescription' => 'Своё описание для сниппета.',
        ]), NEWS_SITE['description']);

        self::assertSame('Вечер встречи выпускников', $seo['title']);
        self::assertSame('Своё описание для сниппета.', $seo['description']);
    }

    public function testБезЛидаОписаниеБерётсяИзНазначенияРаздела(): void
    {
        $seo = seoOf($this->item(['lead' => '   ']), NEWS_SITE['description']);

        self::assertSame(NEWS_SITE['description'], $seo['description']);
    }

    public function testДлинныйЛидОбрезаетсяДоДвухсотЗнаков(): void
    {
        $seo = seoOf($this->item(['lead' => str_repeat('а', 260)]), NEWS_SITE['description']);

        self::assertSame(201, mb_strlen($seo['description']), '200 знаков и многоточие');
    }

    public function testКартинкаПревьюБерётсяИзОбложкиЕслиСвоейНет(): void
    {
        /** @var list<array{0: array<string, mixed>, 1: string}> $cases */
        $cases = [
            'своя картинка важнее обложки' => [$this->item(['ogImage' => '/uploads/og.png', 'cover' => '/uploads/cover.png']), '/uploads/og.png'],
            'обложка вместо своей' => [$this->item(['cover' => '/uploads/cover.png']), '/uploads/cover.png'],
            'без картинок — фирменная' => [$this->item([]), NEWS_OG_IMAGE],
            'пробелы не считаются картинкой' => [$this->item(['ogImage' => '  ', 'cover' => ' ']), NEWS_OG_IMAGE],
        ];

        foreach ($cases as $label => [$item, $expected]) {
            self::assertSame($expected, ogImageOf($item), $label);
        }
    }

    public function testРазметкаСпискаЭтоПревьюСайта(): void
    {
        $html = listHeadTags('Новости школы', 'Описание раздела', '/news');

        foreach ([
            'тип — сайт' => '<meta property="og:type" content="website">',
            'адрес' => '<meta property="og:url" content="http://localhost:8080/news">',
            'каноник' => '<link rel="canonical" href="http://localhost:8080/news">',
            'картинка' => '<meta property="og:image" content="http://localhost:8080' . NEWS_OG_IMAGE . '">',
            'размер' => '<meta property="og:image:width" content="1200">',
            'карточка твиттера' => '<meta name="twitter:card" content="summary_large_image">',
        ] as $label => $needle) {
            self::assertStringContainsString($needle, $html, $label);
        }

        self::assertStringNotContainsString('article', $html, 'у списка нет разметки статьи');
    }

    public function testРазметкаСтатьиНесётМосковскоеВремяРубрикуИТеги(): void
    {
        $html = articleHeadTags($this->item(), '/news/vecher-vstrechi', true);

        foreach ([
            'тип — статья' => '<meta property="og:type" content="article">',
            'срок публикации' => '<meta property="article:published_time" content="2026-10-07T12:00:00+03:00">',
            'время правки' => '<meta property="article:modified_time" content="2026-10-08T18:30:00+03:00">',
            'рубрика' => '<meta property="article:section" content="Жизнь школы">',
            'тег' => '<meta property="article:tag" content="Выпускник">',
            'каноник' => '<link rel="canonical" href="http://localhost:8080/news/vecher-vstrechi">',
        ] as $label => $needle) {
            self::assertStringContainsString($needle, $html, $label);
        }
    }

    public function testУЧерновикаПревьюПоказываетПравкуВместоПубликации(): void
    {
        $html = articleHeadTags($this->item([
            'status' => NEWS_STATUS['DRAFT'],
            'open' => false,
            'publishedAt' => null,
            'date' => '—',
        ]), '/news/vecher-vstrechi', false);

        self::assertStringContainsString(
            '<meta property="article:published_time" content="2026-10-08T18:30:00+03:00">',
            $html,
            'черновик показываем по последней правке'
        );
        self::assertStringNotContainsString('1970', $html, 'пустого срока в разметке нет');
    }

    public function testЗначенияПревьюЭкранируются(): void
    {
        $html = articleHeadTags($this->item(['title' => 'Вечер "<кот>" & друзья']), '/news/vecher-vstrechi', true);

        self::assertStringContainsString('content="Вечер &quot;&lt;кот&gt;&quot; &amp; друзья"', $html);
        self::assertStringNotContainsString('<кот>', $html);
    }

    public function testОписаниеСтатьиДляПоисковикаСобираетсяИзМатериала(): void
    {
        $ld = newsArticleLd(
            $this->item(),
            '/news/vecher-vstrechi',
            'Вечер встречи',
            'Короткий лид про вечер.',
            'http://localhost:8080' . NEWS_OG_IMAGE
        );

        self::assertSame('NewsArticle', $ld['@type']);
        self::assertSame('https://schema.org', $ld['@context']);
        self::assertSame('http://localhost:8080/news/vecher-vstrechi', $ld['@id']);
        self::assertSame('2026-10-07T12:00:00+03:00', $ld['datePublished']);
        self::assertSame('2026-10-08T18:30:00+03:00', $ld['dateModified']);
        self::assertSame('Жизнь школы', $ld['articleSection']);
        self::assertSame('Выпускник', $ld['keywords']);
        self::assertSame('ru-RU', $ld['inLanguage']);
        self::assertTrue($ld['isAccessibleForFree']);
        self::assertSame('Organization', $ld['author']['@type'], 'автор — школа, а не почта редактора');
        self::assertStringNotContainsString('@', json_encode($ld['author'], JSON_THROW_ON_ERROR), 'почты в разметке нет');
    }

    public function testБлокРазметкиНеЗакрываетсяТекстомМатериала(): void
    {
        $html = jsonLdTag(['@type' => 'NewsArticle', 'headline' => 'Разбор </script> и <b>тегов</b>']);

        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertStringEndsWith('</script>', $html);
        self::assertSame(1, substr_count($html, '</script>'), 'один закрывающий тег на всю страницу');
        self::assertStringNotContainsString('</script> и', $html);
    }

    public function testБезТеговКлючевыхСловНет(): void
    {
        $ld = newsArticleLd($this->item(['tags' => []]), '/news/vecher-vstrechi', 'Вечер', 'Лид', 'x.png');

        self::assertArrayNotHasKey('keywords', $ld);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsSeoTest`
Expected: FAIL — `Call to undefined function newsTitle()`.

- [ ] **Шаг 2: Реализуем метаданные раздела**

`apps/news/lib/seo.php`:

```php
<?php

declare(strict_types=1);

// Заголовки и превью раздела: одно место, где решается, что увидит читатель в вкладке,
// а поисковик — в сниппете и в карточке ссылки. Страницы эти строки не собирают (§8.6).
const NEWS_SITE = [
    'name' => 'Новости школы',
    'tagline' => 'что происходит в школе',
    'title' => 'Новости школы',
    'description' => 'Открытые записи, события школы, результаты учеников и изменения в программах.',
];
const NEWS_OG_SITE_NAME = 'Новости школы — что происходит в школе';
const NEWS_TITLE_TEMPLATE = '%s · Новости школы';

// Фирменная картинка превью лежит в репозитории: у материала без обложки пустое превью
// показывать нельзя, а рисовать её на лету значило бы требовать от хостинга GD.
const NEWS_OG_IMAGE = '/assets/og-default.png';
const NEWS_OG_WIDTH = 1200;
const NEWS_OG_HEIGHT = 630;
const NEWS_SEO_DESCRIPTION_LIMIT = 200;

function newsTitle(string $title): string
{
    $clean = trim($title);

    return $clean === '' ? (string) NEWS_SITE['title'] : sprintf(NEWS_TITLE_TEMPLATE, $clean);
}

/**
 * @param array<string, mixed> $item
 *
 * @return array{title: string, description: string}
 */
function seoOf(array $item, string $fallback): array
{
    $title = trim((string) ($item['seoTitle'] ?? ''));
    if ($title === '') {
        $title = trim((string) ($item['title'] ?? ''));
    }

    $description = trim((string) ($item['seoDescription'] ?? ''));
    if ($description === '') {
        $description = trim((string) ($item['lead'] ?? ''));
    }
    if ($description === '') {
        $description = trim($fallback);
    }

    return [
        'title' => $title === '' ? (string) NEWS_SITE['title'] : $title,
        'description' => excerpt($description, NEWS_SEO_DESCRIPTION_LIMIT),
    ];
}

/**
 * @param array<string, mixed> $item
 */
function ogImageOf(array $item): string
{
    foreach (['ogImage', 'cover'] as $key) {
        $value = trim((string) ($item[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return NEWS_OG_IMAGE;
}

/**
 * @param array<string, mixed> $item
 *
 * @return list<string>
 */
function itemTagLabels(array $item): array
{
    $labels = [];

    foreach ((array) ($item['tags'] ?? []) as $tag) {
        $label = trim((string) (is_array($tag) ? ($tag['label'] ?? '') : ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return $labels;
}

// Значения превью приходят из материала, а материал пишет человек: экранируем каждый атрибут,
// иначе кавычка в заголовке закрывает тег и ломает разметку страницы.
function metaTag(string $name, string $content, bool $property = false): string
{
    return sprintf('<meta %s="%s" content="%s">', $property ? 'property' : 'name', e($name), e($content));
}

function canonicalTag(string $path): string
{
    return sprintf('<link rel="canonical" href="%s">', e(absoluteUrl($path)));
}

function listHeadTags(string $title, string $description, string $path): string
{
    $url = absoluteUrl($path);
    $image = absoluteUrl(NEWS_OG_IMAGE);

    return implode("\n", [
        canonicalTag($path),
        metaTag('og:site_name', NEWS_OG_SITE_NAME, true),
        metaTag('og:type', 'website', true),
        metaTag('og:title', $title, true),
        metaTag('og:description', $description, true),
        metaTag('og:url', $url, true),
        metaTag('og:image', $image, true),
        metaTag('og:image:width', (string) NEWS_OG_WIDTH, true),
        metaTag('og:image:height', (string) NEWS_OG_HEIGHT, true),
        metaTag('og:locale', 'ru_RU', true),
        metaTag('twitter:card', 'summary_large_image'),
        metaTag('twitter:title', $title),
        metaTag('twitter:description', $description),
        metaTag('twitter:image', $image),
    ]);
}

/**
 * @param array<string, mixed> $item
 */
function articleHeadTags(array $item, string $path, bool $open): string
{
    $seo = seoOf($item, (string) NEWS_SITE['description']);
    $image = absoluteUrl(ogImageOf($item));

    $tags = [
        canonicalTag($path),
        metaTag('og:site_name', NEWS_OG_SITE_NAME, true),
        metaTag('og:type', 'article', true),
        metaTag('og:title', $seo['title'], true),
        metaTag('og:description', $seo['description'], true),
        metaTag('og:url', absoluteUrl($path), true),
        metaTag('og:image', $image, true),
        metaTag('og:image:width', (string) NEWS_OG_WIDTH, true),
        metaTag('og:image:height', (string) NEWS_OG_HEIGHT, true),
        metaTag('og:locale', 'ru_RU', true),
        metaTag('twitter:card', 'summary_large_image'),
        metaTag('twitter:title', $seo['title']),
        metaTag('twitter:description', $seo['description']),
        metaTag('twitter:image', $image),
    ];

    $published = publishedTimeOf($item, $open);
    if ($published !== null) {
        $tags[] = metaTag('article:published_time', $published, true);
    }

    $modified = modifiedTimeOf($item);
    if ($modified !== null) {
        $tags[] = metaTag('article:modified_time', $modified, true);
    }

    $section = trim((string) ($item['categoryTitle'] ?? ''));
    if ($section !== '') {
        $tags[] = metaTag('article:section', $section, true);
    }

    foreach (itemTagLabels($item) as $label) {
        $tags[] = metaTag('article:tag', $label, true);
    }

    return implode("\n", $tags);
}

/**
 * @param array<string, mixed> $item
 *
 * @return array<string, mixed>
 */
function newsArticleLd(array $item, string $path, string $title, string $description, string $image): array
{
    // Автор — школа, а не почта редактора: адрес автора в открытой разметке собирать негде,
    // а публиковать его в выдаче нельзя (§7.4).
    $organization = [
        '@type' => 'Organization',
        '@id' => absoluteUrl('/') . '#organization',
        'name' => (string) NEWS_SITE['name'],
        'url' => absoluteUrl('/'),
    ];

    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        '@id' => absoluteUrl($path),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => absoluteUrl($path)],
        'headline' => $title,
        'description' => $description,
        'image' => [$image],
        'inLanguage' => 'ru-RU',
        'isAccessibleForFree' => true,
        'author' => $organization,
        'publisher' => $organization,
    ];

    $published = publishedTimeOf($item, (bool) ($item['open'] ?? false));
    if ($published !== null) {
        $ld['datePublished'] = $published;
    }

    $modified = modifiedTimeOf($item);
    if ($modified !== null) {
        $ld['dateModified'] = $modified;
    }

    $section = trim((string) ($item['categoryTitle'] ?? ''));
    if ($section !== '') {
        $ld['articleSection'] = $section;
    }

    $labels = itemTagLabels($item);
    if ($labels !== []) {
        $ld['keywords'] = implode(', ', $labels);
    }

    return $ld;
}

/**
 * @param array<string, mixed> $ld
 */
function jsonLdTag(array $ld): string
{
    try {
        // HEX_TAG обязателен: текст материала попадает внутрь <script>, и строка «</script>»
        // в заголовке закрыла бы блок посреди страницы.
        $json = json_encode(
            $ld,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        logWarn('news_ld_broken', ['reason' => $error->getMessage()]);

        return '';
    }

    return '<script type="application/ld+json">' . $json . '</script>';
}

// Хук page_head — единственный источник $head в render(): ядро знает про title, description
// и robots, а про каноник и превью раздела не знает ничего (§5.6).
function listHead(string $title, string $description, string $path): void
{
    addAction('page_head', static function () use ($title, $description, $path): void {
        echo listHeadTags($title, $description, $path);
    });
}

/**
 * @param array<string, mixed> $item
 */
function articleHead(array $item, string $path, bool $open): void
{
    addAction('page_head', static function () use ($item, $path, $open): void {
        echo articleHeadTags($item, $path, $open);

        if ($open) {
            $seo = seoOf($item, (string) NEWS_SITE['description']);

            echo jsonLdTag(newsArticleLd(
                $item,
                $path,
                $seo['title'],
                $seo['description'],
                absoluteUrl(ogImageOf($item))
            ));
        }
    });
}

/** Ниже — разбор моментов материала: его читают и метаданные, и страница материала. */

function momentOf(mixed $value): ?DateTimeImmutable
{
    return $value instanceof DateTimeImmutable ? $value : null;
}

/**
 * У черновика и у материала с ненаступившим сроком публиковать нечего, поэтому превью
 * показывает время последней правки: пустой срок превратился бы в 1970 год.
 *
 * @param array<string, mixed> $item
 */
function publishedTimeOf(array $item, bool $open): ?string
{
    $published = momentOf($item['publishedAt'] ?? null);
    $updated = momentOf($item['updatedAt'] ?? null);
    $moment = $open ? ($published ?? $updated) : ($updated ?? $published);

    return $moment === null ? null : moscowIso($moment);
}

/**
 * @param array<string, mixed> $item
 */
function modifiedTimeOf(array $item): ?string
{
    $moment = momentOf($item['updatedAt'] ?? null) ?? momentOf($item['publishedAt'] ?? null);

    return $moment === null ? null : moscowIso($moment);
}
```

Изменить `apps/news/lib/boot.php` — добавить восьмую строку:

```php
require_once __DIR__ . '/seo.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsSeoTest`
Expected: PASS — 13 проверок.

- [ ] **Шаг 3: Рисуем фирменное превью**

`scripts/make-og-default.php`:

```php
<?php

declare(strict_types=1);

// Заготовка фирменного превью 1200×630. Раздел обязан отдавать картинку даже у материала
// без обложки (§8.6), а требовать GD от виртуального хостинга нельзя: рисунок создаётся
// один раз здесь и попадает в репозиторий готовым файлом.
// Запуск: docker compose -f docker-compose.dev.yml run --rm -T php php scripts/make-og-default.php

$target = __DIR__ . '/../public/assets/og-default.png';

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, 'GD не установлен — перерисовать файл нечем.' . PHP_EOL);
    exit(1);
}

$image = imagecreatetruecolor(1200, 630);
if (!$image instanceof GdImage) {
    fwrite(STDERR, 'Холст не создан.' . PHP_EOL);
    exit(1);
}

// Три цвета темы из src-css/app.css: бумага, accent-полоса внизу и силуэт кота цветом ink.
$paper = imagecolorallocate($image, 0xfd, 0xf6, 0xec);
$accent = imagecolorallocate($image, 0xd9, 0x77, 0x06);
$ink = imagecolorallocate($image, 0x2b, 0x21, 0x18);

imagefilledrectangle($image, 0, 0, 1199, 629, $paper);
imagefilledrectangle($image, 0, 566, 1199, 629, $accent);

// Надписи нет намеренно: шрифт в контейнере не установлен, а название раздела несёт шаблон.
imagefilledellipse($image, 880, 470, 260, 200, $ink);
imagefilledellipse($image, 880, 320, 170, 160, $ink);
imagefilledpolygon($image, [815, 265, 830, 195, 875, 255], $ink);
imagefilledpolygon($image, [945, 265, 930, 195, 885, 255], $ink);
imagefilledellipse($image, 700, 500, 200, 40, $ink);

imagepng($image, $target);
imagedestroy($image);

echo 'Готово: ' . $target . PHP_EOL;
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php php scripts/make-og-default.php`
Expected: `Готово: …/public/assets/og-default.png`.

Проверить размер готового файла:

Run: `docker compose -f docker-compose.dev.yml run --rm -T php php -r 'echo implode(" ", getimagesize("public/assets/og-default.png")), PHP_EOL;'`
Expected: `1200 630 3 …` (ширина, высота, тип PNG).

Файл коммитится вместе с задачей — на хостинге его никто не перерисовывает.

- [ ] **Шаг 4: Написать падающий тест публичных страниц**

Создать `tests/http/NewsPublicTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
use Cms\Tests\TestDb;
use DateInterval;
use PHPUnit\Framework\TestCase;

// Публичные страницы проверяются настоящим запросом: статус у черновика, редирект прежнего адреса,
// noindex у поиска и метаданные в <head> видны только так — функция, которая их собирает,
// про ответ сервера ничего не знает.
final class NewsPublicTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');
        self::seedItems();
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
            'title' => 'Вечер встречи',
            'slug' => '',
            'lead' => 'Короткий лид про вечер.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => ['Выпускник'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    private static function seedItems(): void
    {
        $editor = ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];

        createItem(self::input([
            'slug' => 'vecher-vstrechi',
            'body' => [['title' => 'Программа', 'text' => 'Начинаем **в шесть** вечера.']],
        ]), $editor);

        createItem(self::input(['title' => 'Второй вечер', 'slug' => 'vtoroy-vecher']), $editor);

        createItem(self::input([
            'title' => 'Невидимая заметка',
            'slug' => 'chernovik',
            'status' => NEWS_STATUS['DRAFT'],
            'tags' => [],
        ]), $editor);

        createItem(self::input([
            'title' => 'Заметка про будущее',
            'slug' => 'skoro',
            'publishedAt' => nowUtc()->add(new DateInterval('PT3H')),
            'tags' => [],
        ]), $editor);

        $renamed = createItem(self::input([
            'title' => 'Приём в первый класс',
            'slug' => 'novoe-nazvanie',
            'categoryId' => categoryIdOf('postuplenie'),
            'tags' => [],
        ]), $editor);

        saveOldSlugs($renamed['id'], ['staroe-nazvanie']);
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $token);

        Kernel::post('/login', [
            '_token' => $token[1] ?? '',
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    public function testЛентаОткрытаАнонимуИПоказываетТолькоВыпущенное(): void
    {
        $page = Kernel::get('/news');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вечер встречи', $page['body']);
        self::assertStringContainsString('Второй вечер', $page['body']);
        self::assertStringNotContainsString('Невидимая заметка', $page['body'], 'черновика в ленте нет');
        self::assertStringNotContainsString('Заметка про будущее', $page['body'], 'материала с ненаступившим сроком нет');
    }

    public function testЛентаНесётПревьюРаздела(): void
    {
        $page = Kernel::get('/news');

        self::assertStringContainsString('<title>Новости школы</title>', $page['body']);
        self::assertStringContainsString('<meta property="og:type" content="website">', $page['body']);
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost:8080/news">',
            $page['body'],
            'каноник ленты печатает хук page_head'
        );
        self::assertStringNotContainsString('noindex', $page['body'], 'первая страница ленты индексируется');
    }

    public function testСтраницаМатериалаОткрываетсяВсем(): void
    {
        $page = Kernel::get('/news/vecher-vstrechi');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Вечер встречи</title>', $page['body']);
        self::assertStringContainsString('Начинаем <strong>в шесть</strong> вечера.', $page['body'], 'выделение звёздочками');
        self::assertStringContainsString('/news/t/vypusknik', $page['body'], 'тег ведёт на свою страницу');
        self::assertStringContainsString('Программа', $page['body'], 'заголовок абзаца напечатан');
    }

    public function testСтраницаМатериалаНесётРазметкуСтатьи(): void
    {
        $page = Kernel::get('/news/vecher-vstrechi');

        self::assertStringContainsString('<meta property="og:type" content="article">', $page['body']);
        self::assertStringContainsString('<script type="application/ld+json">', $page['body']);
        self::assertStringContainsString('NewsArticle', $page['body']);
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost:8080/news/vecher-vstrechi">',
            $page['body']
        );
    }

    public function testЧерновикОтвечаетТойЖеСтраницейЧтоИЧужойАдрес(): void
    {
        $absent = Kernel::get('/news/net-takogo-materiala');
        $draft = Kernel::get('/news/chernovik');

        self::assertSame(404, $draft['status']);
        self::assertSame($absent['body'], $draft['body'], 'по ответу нельзя узнать, что материал существует');
        self::assertStringNotContainsString('Невидимая заметка', $draft['body']);
    }

    public function testМатериалСНенаступившимСрокомОтвечаетТойЖеСтраницей(): void
    {
        $page = Kernel::get('/news/skoro');

        self::assertSame(404, $page['status']);
        self::assertStringContainsString('Страницы нет', $page['body']);
        self::assertStringNotContainsString('Заметка про будущее', $page['body']);
    }

    public function testПрежнийАдресПереноситНаНовый(): void
    {
        $page = Kernel::get('/news/staroe-nazvanie');

        self::assertSame(301, $page['status']);
        self::assertSame('/news/novoe-nazvanie', $page['headers']['location']);
    }

    public function testРедакторВидитЧерновикПоСсылкеПредпросмотра(): void
    {
        $this->login();
        $page = Kernel::get('/news/chernovik?draft=1');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Невидимая заметка', $page['body']);
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'предпросмотр не индексируется');
        self::assertStringContainsString('Так материал видят редакторы', $page['body']);
        self::assertStringNotContainsString('application/ld+json', $page['body'], 'разметки статьи у черновика нет');
    }

    public function testАнонимуПредпросмотрНеОтдаётся(): void
    {
        $page = Kernel::get('/news/chernovik?draft=1');

        self::assertSame(404, $page['status']);
    }

    public function testСтраницаРубрикиПоказываетЕёМатериалы(): void
    {
        $page = Kernel::get('/news/c/zhizn-shkoly');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Жизнь школы · Новости школы</title>', $page['body']);
        self::assertStringContainsString('Вечер встречи', $page['body']);
        self::assertStringNotContainsString('Приём в первый класс', $page['body'], 'чужая рубрика не показывается');
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost:8080/news/c/zhizn-shkoly">',
            $page['body']
        );
    }

    public function testСкрытаяРубрикаНеОткрывается(): void
    {
        $page = Kernel::get('/news/c/metodika');

        self::assertSame(404, $page['status']);
        self::assertStringNotContainsString('Методика', $page['body']);
    }

    public function testСтраницаТегаНеИндексируется(): void
    {
        $page = Kernel::get('/news/t/vypusknik');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'подборки по тегам в индекс не идут');
        self::assertStringContainsString('Материалы по тегу', $page['body']);
        self::assertStringContainsString('Вечер встречи', $page['body']);
    }

    public function testПоискНаходитИСнятСИндекса(): void
    {
        $page = Kernel::get('/news?q=' . rawurlencode('первый'));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Приём в первый класс', $page['body']);
        self::assertStringNotContainsString('Вечер встречи', $page['body'], 'чужой материал в результаты не попадает');
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'результаты поиска не индексируются');
    }

    public function testУМатериалаВидноПохожиеИзЕгоРубрики(): void
    {
        $page = Kernel::get('/news/vecher-vstrechi');

        self::assertStringContainsString('Читайте также', $page['body']);
        self::assertStringContainsString('Второй вечер', $page['body']);
        self::assertStringNotContainsString('Приём в первый класс', $page['body'], 'чужая рубрика в похожих не появляется');
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsPublicTest`
Expected: FAIL — `/news` отвечает 404: маршрутов раздела ещё нет.

**Две ловушки этого теста:**

- `assertStringNotContainsString('noindex', …)` у ленты проверяет, что раздел не снял с индекса первую страницу:
  слово `noindex` встречается в layout ровно один раз и только когда `$noindex` истинно.
- Сравнение тел `assertSame($absent['body'], $draft['body'])` держится на том, что `theme/404.php` не печатает
  `$message`. Поэтому все три контроллера вызывают `notFound()` **без аргумента**: свой текст у 404 снова сделает
  черновик узнаваемым.

- [ ] **Шаг 5: Маршруты и контроллеры публичных страниц**

Создать `apps/news/routes.php`:

```php
<?php

declare(strict_types=1);

// Правила раздела нужны контроллерам на каждом запросе, а в боевом процессе их не подключает никто:
// require_once здесь — единственная точка, где `findFeed()` и `parseQuery()` встречаются с маршрутами.
require_once __DIR__ . '/lib/boot.php';

// Порядок важен: /news/c/{slug} и /news/t/{slug} стоят до /news/{slug}. Сами адреса «c» и «t»
// заняты в NEWS_RESERVED_SLUGS, поэтому материал не может увести у рубрики её страницу.
addRoute('GET', '/news', 'newsFeedPage', ['app' => 'news']);
addRoute('GET', '/news/c/{slug}', 'newsCategoryPage', ['app' => 'news']);
addRoute('GET', '/news/t/{slug}', 'newsTagPage', ['app' => 'news']);
addRoute('GET', '/news/{slug}', 'newsSinglePage', ['app' => 'news']);

/** @param array<string, string> $params */
function newsFeedPage(array $params = []): void
{
    $requested = trim((string) queryString('cat', ''));
    $query = parseQuery((string) queryString('q', ''));
    $page = pageOf((string) queryString('page', '1'));

    $heading = (string) NEWS_SITE['title'];
    $intro = (string) NEWS_SITE['description'];

    if ($requested !== '') {
        // ?cat= достался от прежних ссылок: лента открывается, а каноник ведёт на адрес рубрики,
        // иначе один и тот же список лежит в индексе по двум адресам.
        $row = findCategory($requested);
        if ($row === null) {
            notFound();
        }

        $heading = newsTitle((string) $row['title']);
        $intro = categoryIntro($row);
    } elseif ($query !== '') {
        $heading = newsTitle('Поиск: ' . $query);
        $intro = sprintf('Материалы раздела по запросу «%s».', $query);
    }

    $feed = findFeed(['category' => $requested, 'q' => $query], $page);
    $path = $requested !== '' ? newsHref(['category' => $requested]) : newsHref();

    listHead($heading, $intro, $path);

    render('news/feed', [
        'feed' => $feed,
        'heading' => $heading,
        'intro' => $intro,
        'categories' => visibleCategories(),
        'current' => $requested,
        'empty' => $query !== '' ? 'По такому запросу материалов нет.' : 'В разделе пока нет материалов.',
    ], [
        'title' => $heading,
        'description' => $intro,
        'noindex' => $query !== '' || $feed['current'] > 1,
    ]);
}

/** @param array<string, string> $params */
function newsCategoryPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $row = findCategory($slug);

    // Скрытая рубрика отвечает как несуществующая: иначе адрес, который редактор снял с публикации,
    // продолжает открываться по прямой ссылке (§8.2).
    if ($row === null) {
        notFound();
    }

    $page = pageOf((string) queryString('page', '1'));
    $feed = findFeed(['category' => $slug], $page);
    $heading = newsTitle((string) $row['title']);
    $intro = categoryIntro($row);

    listHead($heading, $intro, newsHref(['category' => $slug]));

    render('news/category', [
        'feed' => $feed,
        'heading' => (string) $row['title'],
        'intro' => $intro,
        'categories' => visibleCategories(),
        'current' => $slug,
        'empty' => 'В этой рубрике пока нет материалов.',
    ], [
        'title' => $heading,
        'description' => $intro,
        'noindex' => $feed['current'] > 1,
    ]);
}

/** @param array<string, string> $params */
function newsTagPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $tag = findTag($slug);

    if ($tag === null) {
        notFound();
    }

    $page = pageOf((string) queryString('page', '1'));
    $feed = findFeed(['tag' => $slug], $page);
    $heading = sprintf('Материалы по тегу «%s»', (string) $tag['label']);
    $title = newsTitle($heading);
    $intro = sprintf('Подборка материалов раздела по тегу «%s».', (string) $tag['label']);

    listHead($title, $intro, newsHref(['tag' => $slug]));

    // Подборка по тегу не индексируется целиком: это бесконечный набор дублей ленты (§8.5).
    render('news/tag', [
        'feed' => $feed,
        'heading' => $heading,
        'intro' => $intro,
        'categories' => visibleCategories(),
        'current' => '',
        'empty' => 'По этому тегу материалов пока нет.',
    ], [
        'title' => $title,
        'description' => $intro,
        'noindex' => true,
    ]);
}

/** @param array<string, string> $params */
function newsSinglePage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $now = nowUtc();

    // Предпросмотр открывает только редактор и только по явной ссылке ?draft=1: без этого адрес
    // будущей публикации становится известным по ответу сервера (§8.2).
    $access = getEditorAccess();
    $editor = $access['kind'] === 'granted';
    $preview = $editor && queryString('draft') === '1';

    $item = $preview ? findAny($slug) : findPublished($slug, $now);

    if ($item === null) {
        $alias = findByOldSlug($slug);

        if ($alias === null) {
            notFound();
        }

        // Прежний адрес переносит на новый навсегда, а у закрытого материала сохраняет ?draft=1:
        // редактор, который держал ссылку на черновик, не должен увидеть 404 после смены адреса.
        $target = newsHref(['slug' => (string) $alias['slug']]);

        redirect($alias['open'] === true ? $target : $target . '?draft=1', 301);
    }

    $path = newsHref(['slug' => (string) $item['slug']]);
    $open = $item['open'] === true;
    $seo = seoOf($item, (string) NEWS_SITE['description']);
    $updated = momentOf($item['updatedAt']);

    articleHead($item, $path, $open);

    render('news/single', [
        'item' => $item,
        'open' => $open,
        'preview' => $preview && !$open,
        'scheduled' => $open === false && $item['status'] === NEWS_STATUS['PUBLISHED'],
        'showUpdated' => $updated !== null && formatRuDate($updated) !== formatRuDate(momentOf($item['publishedAt'])),
        'related' => $open ? relatedLinks((string) $item['categorySlug'], (string) $item['slug'], $now) : [],
    ], [
        'title' => $seo['title'],
        'description' => $seo['description'],
        'noindex' => !$open || $item['noindex'] === true,
    ]);
}

/**
 * Короткое описание рубрики: своё, если редактор его заполнил, и типовое, если нет.
 *
 * @param array{id: string, slug: string, title: string, description: ?string} $row
 */
function categoryIntro(array $row): string
{
    $text = trim((string) $row['description']);

    return $text !== '' ? $text : sprintf('%s: материалы рубрики «%s».', (string) NEWS_SITE['name'], (string) $row['title']);
}
```

**Обратное направление по-прежнему запрещено, и вот почему.** `registerAppRoutes()` из `core/apps.php`
читает `apps/news/routes.php` через `require_once`. Если бы `boot.php` подключил маршруты первым, то
после `routesReset()` повторный `require_once` не выполнил бы файл заново, и таблица маршрутов
осталась бы без раздела. Контроллеры — функции из того же файла, поэтому они определяются ровно там,
где определяется таблица.

**Почему `routes.php` обязан позвать `boot.php`.** Контроллеры вызывают правила раздела
(`findFeed()`, `parseQuery()`), а их в процессе нет, пока никто не подключил `apps/news/lib/boot.php`:
в боевом процессе это делают только `tests/bootstrap.php` и `apps/news/seed.php`. Без этой строки
набор `http` отвечает на `/news` пятеркой, а наборы `unit` и `db` просматривают её — там файл уже
подключён. `require_once` исполнит `boot.php` один раз за процесс, константы и правила останутся
определены, а таблица маршрутов на повторе после `routesReset()` восстановится снимком.

Изменить `public/index.php` — между `registerCoreRoutes()` и `dispatch()` добавить одну строку:

```php
registerAppRoutes();
```

Порядок фиксирован: сессия, маршруты ядра, маршруты разделов, разбор запроса. Без этой строки адресов
раздела в реестре нет вовсе, и весь набор `http` отвечает на `/news` четвёркой — при этом страж
`RouteHandlersTest` остаётся зелёным, потому что `registerAppRoutes()` он вызывает сам. Отсюда правило
для всех следующих разделов: регистрирует приложение ядро на запросе, а не «файл маршрутов сам
появляется».

- [ ] **Шаг 6: Шаблоны страниц**

Заменить `apps/news/templates/feed.php` (заглушку задачи 4) целиком:

```php
<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $feed
 * @var string $heading
 * @var string $intro
 * @var list<array{slug: string, title: string}> $categories
 * @var string $current
 * @var string $empty
 */
?>
<section class="space-y-8">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e($heading) ?></h1>
        <p class="text-ink/70"><?= e($intro) ?></p>
    </header>

    <?= partial('news/partials/search', ['q' => (string) $feed['q']]) /* @safe html */ ?>
    <?= partial('news/partials/categories', ['categories' => $categories, 'current' => $current]) /* @safe html */ ?>
    <?= partial('news/partials/list', ['feed' => $feed, 'empty' => $empty]) /* @safe html */ ?>
</section>
```

Создать `apps/news/templates/category.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $feed
 * @var string $heading
 * @var string $intro
 * @var list<array{slug: string, title: string}> $categories
 * @var string $current
 * @var string $empty
 */
?>
<section class="space-y-8">
    <nav aria-label="Путь до рубрики" class="flex flex-wrap gap-2 text-sm text-ink/70">
        <a class="hover:underline" href="/">Главная</a>
        <span aria-hidden="true">→</span>
        <a class="hover:underline" href="/news">Новости</a>
        <span aria-hidden="true">→</span>
        <span><?= e($heading) ?></span>
    </nav>

    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e($heading) ?></h1>
        <p class="text-ink/70"><?= e($intro) ?></p>
    </header>

    <?= partial('news/partials/search', ['q' => '']) /* @safe html */ ?>
    <?= partial('news/partials/categories', ['categories' => $categories, 'current' => $current]) /* @safe html */ ?>
    <?= partial('news/partials/list', ['feed' => $feed, 'empty' => $empty]) /* @safe html */ ?>
</section>
```

Создать `apps/news/templates/tag.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $feed
 * @var string $heading
 * @var string $intro
 * @var string $empty
 */
?>
<section class="space-y-8">
    <nav aria-label="Путь до подборки" class="flex flex-wrap gap-2 text-sm text-ink/70">
        <a class="hover:underline" href="/">Главная</a>
        <span aria-hidden="true">→</span>
        <a class="hover:underline" href="/news">Новости</a>
        <span aria-hidden="true">→</span>
        <span><?= e($heading) ?></span>
    </nav>

    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e($heading) ?></h1>
        <p class="text-ink/70"><?= e($intro) ?></p>
    </header>

    <?= partial('news/partials/list', ['feed' => $feed, 'empty' => $empty]) /* @safe html */ ?>
</section>
```

Создать `apps/news/templates/single.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $item
 * @var bool $open
 * @var bool $preview
 * @var bool $scheduled
 * @var bool $showUpdated
 * @var list<array<string, mixed>> $related
 */
?>
<article class="space-y-6">
    <?php if ($preview): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">
            <?= e($scheduled
                ? 'Так материал увидят в срок: публикация назначена, на сайте его ещё нет.'
                : 'Так материал видят редакторы: это черновик, на сайте его нет.') ?>
        </p>
    <?php endif; ?>

    <nav aria-label="Путь до материала" class="flex flex-wrap gap-2 text-sm text-ink/70">
        <a class="hover:underline" href="/">Главная</a>
        <span aria-hidden="true">→</span>
        <a class="hover:underline" href="/news">Новости</a>
        <?php if ((string) $item['categoryTitle'] !== ''): ?>
            <span aria-hidden="true">→</span>
            <a class="hover:underline" href="<?= e((string) $item['categoryHref']) ?>"><?= e((string) $item['categoryTitle']) ?></a>
        <?php endif; ?>
    </nav>

    <header class="space-y-3">
        <h1 class="text-3xl font-bold"><?= e((string) $item['title']) ?></h1>
        <p class="flex flex-wrap gap-2 text-sm text-ink/70">
            <span><?= e((string) $item['date']) ?></span>
            <?php if ($showUpdated): ?>
                <span>· обновлено <?= e((string) $item['updated']) ?></span>
            <?php endif; ?>
        </p>
    </header>

    <?php if ((string) $item['cover'] !== ''): ?>
        <img class="w-full rounded-xl object-cover" src="<?= e((string) $item['cover']) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <?php if ((string) $item['lead'] !== ''): ?>
        <p class="text-lg text-ink/70"><?= e((string) $item['lead']) ?></p>
    <?php endif; ?>

    <?= partial('news/partials/body', ['paragraphs' => $item['paragraphs']]) /* @safe html */ ?>

    <?php if ((array) $item['tags'] !== []): ?>
        <ul class="flex flex-wrap gap-2">
            <?php foreach ((array) $item['tags'] as $tag): ?>
                <li>
                    <a class="rounded-lg bg-accent/10 px-3 py-1 text-sm hover:opacity-80"
                       href="<?= e((string) $tag['href']) ?>">#<?= e((string) $tag['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($related !== []): ?>
        <section class="space-y-4 border-t border-ink/10 pt-6">
            <h2 class="text-xl font-semibold">Читайте также</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <?php foreach ($related as $card): ?>
                    <?= partial('news/partials/card', ['card' => $card]) /* @safe html */ ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</article>
```

Создать `apps/news/templates/partials/search.php`:

```php
<?php

declare(strict_types=1);

/** @var string $q */
?>
<form class="flex flex-col gap-2 sm:flex-row" action="/news" method="get" role="search">
    <label class="sr-only" for="news-q">Поиск по материалам</label>
    <input class="w-full rounded-lg border border-ink/10 bg-cream px-3 py-2"
           id="news-q"
           type="search"
           name="q"
           maxlength="<?= e((string) SEARCH_MAX_LENGTH) ?>"
           value="<?= e($q) ?>"
           placeholder="Что ищем в материалах?">
    <button class="rounded-lg bg-accent px-5 py-2 text-cream hover:opacity-90" type="submit">Найти</button>
</form>
```

Создать `apps/news/templates/partials/categories.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var list<array{slug: string, title: string}> $categories
 * @var string $current
 */
$base = 'rounded-lg border px-3 py-1 text-sm';
$on = ' border-accent bg-accent/10';
$off = ' border-ink/10 bg-cream hover:border-ink/20';
?>
<?php if ($categories !== []): ?>
    <nav aria-label="Рубрики новостей">
        <ul class="flex flex-wrap gap-2">
            <li>
                <a class="<?= e($current === '' ? $base . $on : $base . $off) ?>" href="/news">Все</a>
            </li>
            <?php foreach ($categories as $category): ?>
                <li>
                    <a class="<?= e($current === $category['slug'] ? $base . $on : $base . $off) ?>"
                       href="<?= e(newsHref(['category' => (string) $category['slug']])) ?>"><?= e((string) $category['title']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>
```

Создать `apps/news/templates/partials/list.php`:

```php
<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $feed
 * @var string $empty
 */
?>
<?php if ((array) $feed['items'] === []): ?>
    <p class="rounded-xl border border-ink/10 bg-cream p-8 text-center text-ink/70"><?= e($empty) ?></p>
<?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ((array) $feed['items'] as $card): ?>
            <?= partial('news/partials/card', ['card' => $card]) /* @safe html */ ?>
        <?php endforeach; ?>
    </div>

    <?= partial('news/partials/pager', ['feed' => $feed]) /* @safe html */ ?>
<?php endif; ?>
```

Создать `apps/news/templates/partials/card.php`:

```php
<?php

declare(strict_types=1);

/** @var array<string, mixed> $card */
?>
<article class="flex flex-col overflow-hidden rounded-xl border border-ink/10 bg-cream">
    <?php if ((string) $card['cover'] !== ''): ?>
        <img class="h-40 w-full object-cover" src="<?= e((string) $card['cover']) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <div class="flex flex-1 flex-col gap-2 p-4">
        <p class="flex flex-wrap items-center gap-2 text-xs text-ink/70">
            <span><?= e((string) $card['date']) ?></span>
            <?php if ((string) $card['categoryTitle'] !== ''): ?>
                <a class="rounded bg-accent/10 px-2 py-0.5 hover:opacity-80"
                   href="<?= e((string) $card['categoryHref']) ?>"><?= e((string) $card['categoryTitle']) ?></a>
            <?php endif; ?>
            <?php if ($card['pinned'] === true): ?>
                <span class="rounded bg-ink px-2 py-0.5 text-cream">Важное</span>
            <?php endif; ?>
        </p>

        <h3 class="text-lg font-semibold">
            <a class="hover:text-accent" href="<?= e((string) $card['href']) ?>"><?= e((string) $card['title']) ?></a>
        </h3>

        <p class="text-sm text-ink/70"><?= e(excerpt((string) $card['lead'], 140)) ?></p>
    </div>
</article>
```

Создать `apps/news/templates/partials/pager.php`:

```php
<?php

declare(strict_types=1);

/** @var array<string, mixed> $feed */
$pages = (int) $feed['pages'];
$current = (int) $feed['current'];
$query = (string) $feed['q'];
$base = 'inline-flex h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-sm';
?>
<?php if ($pages > 1): ?>
    <nav aria-label="Страницы материалов">
        <ul class="flex flex-wrap justify-center gap-2">
            <?php foreach ((array) $feed['window'] as $number): ?>
                <li>
                    <?php if ((int) $number === $current): ?>
                        <span aria-current="page" class="<?= e($base . ' border-accent bg-accent/10') ?>"><?= e((string) $number) ?></span>
                    <?php else: ?>
                        <a class="<?= e($base . ' border-ink/10 bg-cream hover:border-ink/20') ?>"
                           href="<?= e(feedHref((string) $feed['base'], ['q' => $query, 'page' => (int) $number])) ?>"><?= e((string) $number) ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>
```

Создать `apps/news/templates/partials/body.php`:

```php
<?php

declare(strict_types=1);

/** @var list<array{title: string, text: string}> $paragraphs */
?>
<div class="space-y-5">
    <?php foreach ((array) $paragraphs as $paragraph): ?>
        <?php if ((string) $paragraph['title'] !== ''): ?>
            <h2 class="text-xl font-semibold"><?= e((string) $paragraph['title']) ?></h2>
        <?php endif; ?>
        <p class="leading-relaxed"><?= richText((string) $paragraph['text']) /* @safe html */ ?></p>
    <?php endforeach; ?>
</div>
```

**Три замечания по шаблонам:**

- В `partials/categories.php` и `partials/pager.php` классы собираются в переменные до первого `?>`:
  страж экранирования читает строку с `<?=`, и `e($base . $on)` он принимает, а вот `<?= $base ?>` — нет.
- У карточки нет атрибута `datetime` у времени: `format()` в `apps/` запрещён стражем `DateUsageTest`,
  а русскую дату из `toCard()` в `datetime` не положить. Поэтому дата напечатана обычным `<span>`.
- `partials/body.php` печатает текст через `richText()`: сырого HTML в материале нет, а `**выделение**`
  превращается в `<strong>` внутри уже экранированного текста.

- [ ] **Шаг 7: Прогнать тесты**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsSeoTest`
Expected: PASS — 13 проверок.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsPublicTest`
Expected: PASS — 14 проверок.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard`
Expected: PASS. Здесь ловятся три вещи: `RouteHandlersTest` находит четыре новых маршрута с вызываемыми
обработчиками, `OutputEscapingTest` читает новые шаблоны вместе с `apps/news/lib/seo.php`, а `SqlSafetyTest`
не находит склеивания в `routes.php`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — все четыре набора. Набор `db` зелёный без изменений: эта задача не трогает слой данных.

- [ ] **Шаг 8: Ворота**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки. Если PHP-CS-Fixer показывает правку в `apps/news/lib/seo.php` —
это перенос длинной строки `json_encode(...)`; принять её и повторить ворота.

- [ ] **Шаг 9: Коммит**

```bash
git add apps/news/routes.php apps/news/lib/seo.php apps/news/lib/boot.php apps/news/templates/ \
        public/index.php \
        scripts/make-og-default.php public/assets/og-default.png \
        tests/unit/NewsSeoTest.php tests/http/NewsPublicTest.php
git status --short
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git commit -m "Новости: публичные страницы раздела, метаданные и фирменное превью"
```

`public/index.php` — не «файл ядра вообще-то»: без его строки раздел в реестре маршрутов не появляется
ни на одном живом запросе, поэтому разносить эту правку по другому коммиту нельзя.

`git status --short` перед коммитом обязателен: в индекс не должны попасть `config.php`, журналы и
`public/install.lock`.

**Что проверяется глазами после коммита** (не тестом — это критерии приёмки §12.6, их снимут в задаче 21):

- `/news` на ширине 360 показывает одну колонку карточек, на 1440 — три;
- `/news/vecher-vstrechi` печатает «Читайте также» только когда в рубрике есть второй материал;
- `/news/chernovik?draft=1` под редактором показывает жёлтую плашку предпросмотра.
