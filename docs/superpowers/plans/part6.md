### Задача 13: Справочники раздела — рубрики и теги

**Файлы:**
- Создать: `apps/news/lib/catalog.php`
- Изменить: `apps/news/lib/boot.php` (подключить `catalog.php`)
- Тест: `tests/db/NewsDbTestCase.php` (общая подставка тестов раздела), `tests/db/CatalogTest.php`

**Интерфейсы:**
- Потребляет: `all()`, `one()`, `value()`, `run()`, `tx()`, `t()` из `core/db.php`; `newId()` из `core/id.php`;
  `nowUtc()`, `utcStamp()` из `core/date.php`; `plural()` из `core/text.php`; `logInfo()` из `core/log.php`;
  `slugify()`, `ensureUniqueSlug()` из `apps/news/lib/slug.php`; `fieldLimit()`, `CATEGORY_FIELDS`, `TAG_FIELDS`,
  `NEWS_STATUS` из `apps/news/lib/constants.php`; `installApp()`, `seedApp()`, `appManifest()` из `core/apps.php`;
  `Cms\Tests\TestDb`.
- Производит: `findCategory(string $slug): ?array`, `categoryIdOf(string $slug): string`, `visibleCategories(): array`,
  `categoryOptions(): array`, `checkCategoryTitle(string $rawTitle, string $rawDescription = ''): array`,
  `categoryExists(string $id): bool`, `categoryTitleTaken(string $title, string $exceptId = ''): bool`,
  `adminCategoryRows(): array`, `createCategory(string $rawTitle, string $rawDescription = ''): array`,
  `renameCategory(string $id, string $rawTitle): array`, `updateCategoryDescription(string $id, string $rawTitle, string $rawDescription): array`,
  `toggleCategoryVisibility(string $id): array`, `deleteCategory(string $id): array`, `moveCategory(string $id, string $direction): array`,
  `tagSlugBase(string $label): string`, `findTag(string $slug): ?array`, `visibleTags(): array`, `tagOptions(): array`,
  `tagCountOf(string $id): int`, `adminTagRows(): array`, `resolveTagIds(array $labels): array`,
  `renameTag(string $id, string $rawLabel): array`, `deleteTag(string $id): array`.
  Тестам — `abstract class Cms\Tests\db\NewsDbTestCase` с `clearItems()`, `clearNews()`,
  `makeItem(string $categoryId, string $slug, array $overrides = []): string`, `linkTag()`, `oldSlug()`,
  `column(string $sql, array $args = []): string`, `dropNewsTables()` (задачи 14–16 наследуют её).

**Что сюда не входит:** функции настройки блоков конструктора (`categoryBlockOptions`, `tagBlockOptions`) не портируются —
конструктора страниц в пилоте нет (отклонение 6). Экраны справочников и их POST-обработчики приходят в задаче 19:
здесь только правила и запросы, которые экраны вызывают.

**Форма результата — плоский массив, а не объединение.** Каждое действие справочника возвращает
`array{ok: bool, notice: string, error: string}`: при успехе `error` пуст, при отказе `notice` пуст. Так же устроены
`newsSlugPlan()` и `parsePublishedAt()` из задачи 12 — код и тесты читают поля напрямую, без сужения типа.

**Правила, переносимые из спецификации §8.4:**
- Название рубрики уникально; адрес складывается из названия транслитерацией один раз и при переименовании не меняется.
- Описание читается под заголовком страницы рубрики; пустое описание в базу не ложится — там `NULL`.
- Порядок задают кнопки «вверх»/«вниз»; список пересобирается целиком, поэтому старые дубликаты `order_index` чинятся сами.
- Скрытая рубрика не открывается (`findCategory()` возвращает `null`), а её материалы уходят из общей ленты: условие
  ленты в задаче 14 требует `visible = 1` у рубрики.
- Удалить можно только пустую рубрику: материалы при этом не удаляются, и отказ говорит об этом человеку.
- Теги вручную не заводятся: их рождает `resolveTagIds()` из списка надписей формы материала. В справочнике тегов
  только переименование (адрес тега не меняется) и удаление пустого тега.

**Почему в запросах нет `LOWER()`:** схема раздела собрана на `utf8mb4_unicode_ci`, где «Методика» и «МЕТОДИКА» — одно
значение. Портал на PostgreSQL добавлял `mode: 'insensitive'` к каждому сравнению; в MySQL это свойство сопоставления,
поэтому отдельное приведение регистра стало бы вторым местом, где живёт правило.

- [ ] **Шаг 1: Написать подставку тестов раздела и падающий тест справочников**

Создать `tests/db/NewsDbTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Общая подставка тестов, которым нужны таблицы раздела: установка «Новостей», три рубрики
 * закладки и чистка между проверками.
 *
 * Почему она отдельным классом, а не копией в каждом тесте: раздел проверяют четыре набора
 * (`CatalogTest`, `NewsFeedTest`, `NewsItemsTest`, `NewsPublicTest`), и копия `setUpBeforeClass`
 * разошлась бы с оригиналом молча — тесты продолжали бы проходить на устаревшей подготовке.
 * Каждый тест ставит раздел сам, поэтому критерий «раздел ставится кнопкой» (§12.2) проверяется
 * на каждом прогоне, а не один раз.
 */
abstract class NewsDbTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropNewsTables();

        $result = installApp('news');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Новости» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropNewsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'news'");
    }

    // Каждый тест начинает с трёх рубрик закладки и пустых справочников: порядок и счётчики иначе
    // зависели бы от того, что оставил предыдущий тест.
    protected function setUp(): void
    {
        $manifest = appManifest('news');

        if ($manifest === null) {
            self::fail('Манифест раздела «Новости» не найден.');
        }

        self::clearNews();
        seedApp($manifest);
    }

    // Стирает материалы, оставляя справочники: проверка «рубрику с материалами удалить нельзя»
    // гоняет один и тот же набор рубрик на разном числе материалов.
    protected static function clearItems(): void
    {
        foreach (['news_item_tag', 'news_old_slug', 'news_item'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    protected static function clearNews(): void
    {
        self::clearItems();

        foreach (['news_tag', 'news_category'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    /**
     * Материал вставляется строкой, а не через `createItem()`: тест чтения обязан класть в базу
     * ровно то состояние, которое проверяет, — срок публикации в будущем, закрепление, `noindex`.
     *
     * @param array<string, mixed> $overrides
     */
    protected static function makeItem(string $categoryId, string $slug, array $overrides = []): string
    {
        $id = newId();
        $stamp = utcStamp(nowUtc());

        $row = array_merge([
            'id' => $id,
            'slug' => $slug,
            'title' => 'Материал ' . $slug,
            'lead_text' => null,
            'cover' => null,
            'category_id' => $categoryId,
            'body' => json_encode([['title' => '', 'text' => 'Текст']], JSON_THROW_ON_ERROR),
            'status' => NEWS_STATUS['DRAFT'],
            'published_at' => null,
            'pinned' => 0,
            'seo_title' => null,
            'seo_description' => null,
            'og_image' => null,
            'noindex' => 0,
            'author_id' => null,
            'author_email' => null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ], $overrides);

        insert('news_item', $row);

        return $id;
    }

    protected static function linkTag(string $itemId, string $tagId): void
    {
        run('INSERT INTO ' . t('news_item_tag') . ' (item_id, tag_id) VALUES (?, ?)', [$itemId, $tagId]);
    }

    protected static function oldSlug(string $itemId, string $slug): void
    {
        insert('news_old_slug', ['slug' => $slug, 'item_id' => $itemId, 'created_at' => utcStamp(nowUtc())]);
    }

    /** Значение первой колонки первой строки; пустая строка — если запрос ничего не нашёл. */
    protected function column(string $sql, array $args = []): string
    {
        $found = value($sql, $args);

        return $found === null ? '' : (string) $found;
    }

    protected static function dropNewsTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

Создать `tests/db/CatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Справочники проверяются на настоящей базе: уникальность названия, внешний ключ материала
// и каскадное удаление связей тега — свойства схемы, а не кода.
final class CatalogTest extends NewsDbTestCase
{
    public function testНоваяРубрикаВстаётВКонецСписка(): void
    {
        $result = createCategory('Олимпиады');

        self::assertTrue($result['ok']);
        self::assertSame('created', $result['notice']);
        self::assertSame('olimpiady', $this->column('SELECT slug FROM ' . t('news_category') . " WHERE title = 'Олимпиады'"));
        self::assertSame(
            ['Жизнь школы', 'Поступление', 'Методика', 'Олимпиады'],
            array_map(static fn (array $row): string => (string) $row['title'], adminCategoryRows())
        );
    }

    public function testНазваниеРубрикиНеПовторяетсяБезУчётаРегистра(): void
    {
        $cases = [
            'то же название' => 'Методика',
            'другой регистр' => 'МЕТОДИКА',
            'лишние пробелы и перенос' => "  Методика \n",
        ];

        foreach ($cases as $label => $title) {
            $result = createCategory($title);

            self::assertFalse($result['ok'], $label);
            self::assertSame('Рубрика с таким названием уже есть.', $result['error'], $label);
        }
    }

    public function testКороткоеНазваниеРубрикиОтклоняется(): void
    {
        $result = createCategory('А');

        self::assertFalse($result['ok']);
        self::assertSame('Название короче 2 символов — напишите его целиком.', $result['error']);
    }

    public function testНазваниеИзЗнаковПрепинанияНеДаётАдреса(): void
    {
        $result = createCategory('!!!');

        self::assertFalse($result['ok']);
        self::assertSame('Название должно содержать буквы или цифры: из него строится адрес рубрики.', $result['error']);
    }

    public function testСлишкомДлинноеНазваниеОбрезаетсяПоПределуПоля(): void
    {
        $result = createCategory(str_repeat('а', 80));
        $slug = str_repeat('a', 60);

        self::assertTrue($result['ok']);
        self::assertSame(60, mb_strlen($this->column('SELECT title FROM ' . t('news_category') . ' WHERE slug = ?', [$slug])));
    }

    public function testПереименованиеСохраняетАдресРубрики(): void
    {
        $id = categoryIdOf('metodika');

        $result = renameCategory($id, 'Методика преподавания');

        self::assertTrue($result['ok']);
        self::assertSame('renamed', $result['notice']);
        self::assertSame('metodika', $this->column('SELECT slug FROM ' . t('news_category') . ' WHERE id = ?', [$id]));
        self::assertSame('Методика преподавания', $this->column('SELECT title FROM ' . t('news_category') . ' WHERE id = ?', [$id]));
    }

    public function testПереименованиеНесуществующейРубрикиОтклоняется(): void
    {
        $result = renameCategory('нет-такой-рубрики', 'Другое название');

        self::assertFalse($result['ok']);
        self::assertSame('Рубрики уже нет — обновите страницу.', $result['error']);
    }

    public function testОписаниеРубрикиСохраняетсяИОбнуляется(): void
    {
        $id = categoryIdOf('postuplenie');

        $saved = updateCategoryDescription($id, 'Поступление', 'Как школа готовит к экзаменам.');
        self::assertTrue($saved['ok']);
        self::assertSame('described', $saved['notice']);

        $category = findCategory('postuplenie');
        self::assertIsArray($category);
        self::assertSame('Как школа готовит к экзаменам.', $category['description']);

        // Пустое описание — снятое, а не «не задано»: в базе остаётся NULL, чтобы страница
        // рубрики не печатала пустой абзац.
        $cleared = updateCategoryDescription($id, 'Поступление', '   ');
        self::assertTrue($cleared['ok']);

        $after = findCategory('postuplenie');
        self::assertIsArray($after);
        self::assertNull($after['description']);
    }

    public function testСкрытаяРубрикаНеОткрываетсяИВозвращаетсяОбратно(): void
    {
        $id = categoryIdOf('metodika');

        $hidden = toggleCategoryVisibility($id);
        self::assertTrue($hidden['ok']);
        self::assertSame('hidden', $hidden['notice']);
        self::assertNull(findCategory('metodika'));
        self::assertNotContains('Методика', self::titles(visibleCategories()));

        $shown = toggleCategoryVisibility($id);
        self::assertSame('shown', $shown['notice']);

        $again = findCategory('metodika');
        self::assertIsArray($again);
        self::assertSame('metodika', $again['slug']);
    }

    public function testПустаяРубрикаУдаляется(): void
    {
        createCategory('Олимпиады');
        $id = categoryIdOf('olimpiady');

        $result = deleteCategory($id);

        self::assertTrue($result['ok']);
        self::assertSame('deleted', $result['notice']);
        self::assertSame('', categoryIdOf('olimpiady'));
    }

    public function testРубрикуСМатериаламиУдалитьНельзя(): void
    {
        createCategory('Олимпиады');
        $id = categoryIdOf('olimpiady');

        $cases = [
            1 => 'В рубрике 1 материал.',
            2 => 'В рубрике 2 материала.',
            5 => 'В рубрике 5 материалов.',
        ];

        foreach ($cases as $count => $expected) {
            self::clearItems();
            for ($n = 1; $n <= $count; $n++) {
                self::makeItem($id, 'material-' . $n, ['status' => NEWS_STATUS['DRAFT']]);
            }

            $result = deleteCategory($id);
            $label = (string) $count;

            self::assertFalse($result['ok'], $label);
            self::assertStringStartsWith($expected, $result['error'], $label);
            self::assertStringContainsString('переведите новости в другую рубрику или скройте', $result['error'], $label);
        }
    }

    public function testПорядокРубрикМеняетсяКнопками(): void
    {
        $result = moveCategory(categoryIdOf('postuplenie'), 'up');

        self::assertTrue($result['ok']);
        self::assertSame('moved', $result['notice']);
        self::assertSame(
            ['Поступление', 'Жизнь школы', 'Методика'],
            array_map(static fn (array $row): string => (string) $row['title'], adminCategoryRows())
        );

        // Порядок пересобирается целиком: после перестановки номера идут 1, 2, 3 без дубликатов.
        self::assertSame([1, 2, 3], array_map(static fn (array $row): int => (int) $row['order'], adminCategoryRows()));
    }

    public function testКрайниеРубрикиНеВыходятЗаПределыСписка(): void
    {
        $topped = moveCategory(categoryIdOf('zhizn-shkoly'), 'up');
        self::assertTrue($topped['ok']);
        self::assertSame('topped', $topped['notice']);

        $bottomed = moveCategory(categoryIdOf('metodika'), 'down');
        self::assertTrue($bottomed['ok']);
        self::assertSame('bottomed', $bottomed['notice']);

        $absent = moveCategory('нет-такой-рубрики', 'up');
        self::assertFalse($absent['ok']);
        self::assertSame('Рубрики уже нет — обновите страницу.', $absent['error']);
    }

    public function testСписокАдминкиПоказываетИЧерновикиРубрики(): void
    {
        $id = categoryIdOf('zhizn-shkoly');
        self::makeItem($id, 'chernovik', ['status' => NEWS_STATUS['DRAFT']]);
        self::makeItem($id, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT1H'))),
        ]);

        $row = adminCategoryRows()[0];

        self::assertSame('Жизнь школы', $row['title']);
        self::assertSame(2, $row['items']['total']);
        self::assertSame(1, $row['items']['visible']);
    }

    public function testФормаМатериалаВидитИскрытыеРубрики(): void
    {
        toggleCategoryVisibility(categoryIdOf('metodika'));

        self::assertContains('Методика', self::titles(categoryOptions()));
        self::assertNotContains('Методика', self::titles(visibleCategories()));
    }

    public function testТегиЗаводятсяИзСпискаНадписей(): void
    {
        $ids = resolveTagIds(['Выпускник', 'олимпиада', 'Выпускник', '  ВЫПУСКНИК  ']);

        self::assertCount(2, $ids);
        self::assertSame(array_values($ids), $ids);
        self::assertSame(['Выпускник', 'олимпиада'], self::labels(visibleTags()));
        self::assertSame(['vypusknik', 'olimpiada'], self::slugs(visibleTags()));
    }

    public function testКороткаяИПустаяНадписьНеСоздаютТег(): void
    {
        self::assertSame([], resolveTagIds(['', '   ', 'а']));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_tag')));
    }

    public function testСовпавшийАдресТегаПолучаетСуффикс(): void
    {
        // «Мастер-класс» и «Мастер класс» — разные надписи, но один адрес: транслит приводит
        // пробел и дефис к «-». Второй тег обязан получить суффикс, а не занять чужой адрес.
        $first = resolveTagIds(['Мастер-класс']);
        $second = resolveTagIds(['Мастер класс']);

        self::assertSame('master-klass', $this->column('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$first[0]]));
        self::assertSame('master-klass-2', $this->column('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$second[0]]));
    }

    public function testДлиннаяНадписьТегаОбрезаетсяПоПределу(): void
    {
        $ids = resolveTagIds([str_repeat('б', 60)]);

        self::assertSame(40, mb_strlen($this->column('SELECT label FROM ' . t('news_tag') . ' WHERE id = ?', [$ids[0]])));
    }

    public function testПереименованиеТегаСохраняетАдрес(): void
    {
        $ids = resolveTagIds(['олимпиада']);

        $result = renameTag($ids[0], 'Городская олимпиада');

        self::assertTrue($result['ok']);
        self::assertSame('tagRenamed', $result['notice']);
        self::assertSame('olimpiada', $this->column('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$ids[0]]));
        self::assertSame('Городская олимпиада', $this->column('SELECT label FROM ' . t('news_tag') . ' WHERE id = ?', [$ids[0]]));
    }

    public function testПереименованиеТегаПроверяетНадпись(): void
    {
        $ids = resolveTagIds(['олимпиада', 'конкурс']);

        $short = renameTag($ids[0], 'О');
        self::assertFalse($short['ok']);
        self::assertSame('Надпись короче 2 символов.', $short['error']);

        $taken = renameTag($ids[0], 'Конкурс');
        self::assertFalse($taken['ok']);
        self::assertSame('Тег с такой надписью уже есть.', $taken['error']);

        $absent = renameTag('нет-такого-тега', 'Другое');
        self::assertFalse($absent['ok']);
        self::assertSame('Тега уже нет — обновите страницу.', $absent['error']);
    }

    public function testПустойТегУдаляется(): void
    {
        $ids = resolveTagIds(['олимпиада']);

        $result = deleteTag($ids[0]);

        self::assertTrue($result['ok']);
        self::assertSame('tagDeleted', $result['notice']);
        self::assertNull(findTag('olimpiada'));
    }

    public function testТегСМатериаламиУдалитьНельзя(): void
    {
        $ids = resolveTagIds(['олимпиада']);
        $itemId = self::makeItem(categoryIdOf('zhizn-shkoly'), 'material', ['status' => NEWS_STATUS['DRAFT']]);
        self::linkTag($itemId, $ids[0]);

        $result = deleteTag($ids[0]);

        self::assertFalse($result['ok']);
        self::assertSame('Тег стоит на 1 материале — сначала снимите его в форме новости.', $result['error']);
        self::assertSame(1, tagCountOf($ids[0]));
    }

    public function testПодборкаПоТегуПроверяетСамТегАНеНаличиеМатериалов(): void
    {
        resolveTagIds(['олимпиада']);

        $tag = findTag('olimpiada');

        self::assertIsArray($tag);
        self::assertSame('олимпиада', $tag['label']);
        self::assertSame(0, tagCountOf($tag['id']));
        self::assertNull(findTag('takogo-tega-net'));
    }

    public function testУдалениеМатериалаСнимаетЕгоТегиНоОставляетСправочник(): void
    {
        $ids = resolveTagIds(['олимпиада']);
        $itemId = self::makeItem(categoryIdOf('zhizn-shkoly'), 'material', ['status' => NEWS_STATUS['DRAFT']]);
        self::linkTag($itemId, $ids[0]);

        self::assertSame(1, tagCountOf($ids[0]));

        run('DELETE FROM ' . t('news_item') . ' WHERE id = ?', [$itemId]);

        self::assertSame(0, tagCountOf($ids[0]));
        self::assertNotNull(findTag('olimpiada'));
    }

    // ── Помощники ────────────────────────────────────────
    // Подготовка базы (установка раздела, закладка, чистка, вставка материала) — в
    // `NewsDbTestCase`; здесь только то, что читает список рубрик или тегов.

    /** @param list<array<string, mixed>> $rows */
    private static function titles(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['title'], $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function labels(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['label'], $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function slugs(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['slug'], $rows);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter CatalogTest`
Expected: FAIL — `Call to undefined function createCategory()`.

- [ ] **Шаг 2: Написать справочники раздела**

Создать `apps/news/lib/catalog.php`:

```php
<?php

declare(strict_types=1);

// Справочники раздела «Новости». Рубрики правит контент-менеджер, теги рождаются из формы
// материала. Скрытая рубрика не открывается вовсе, а не отвечает пустым списком: пустая
// страница выглядела бы как рубрика, которую стоит искать.
//
// Совпадения строк ищутся без приведения регистра: схема раздела собрана на utf8mb4_unicode_ci,
// где «Методика» и «МЕТОДИКА» — одно значение.

// ── Рубрики ──────────────────────────────────────────────

/** @return array{id: string, slug: string, title: string, description: ?string}|null */
function findCategory(string $slug): ?array
{
    $row = one(
        'SELECT id, slug, title, description FROM ' . t('news_category') . ' WHERE slug = ? AND visible = 1',
        [$slug]
    );

    if ($row === null) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'description' => $row['description'] === null ? null : (string) $row['description'],
    ];
}

/** Идентификатор рубрики по адресу; пустая строка — рубрики нет. */
function categoryIdOf(string $slug): string
{
    $id = value('SELECT id FROM ' . t('news_category') . ' WHERE slug = ?', [$slug]);

    return $id === null ? '' : (string) $id;
}

/** @return list<array{slug: string, title: string}> */
function visibleCategories(): array
{
    $rows = all('SELECT slug, title FROM ' . t('news_category') . ' WHERE visible = 1 ORDER BY order_index, title');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['slug' => (string) $row['slug'], 'title' => (string) $row['title']];
    }

    return $out;
}

// Форма материала предлагает и скрытые рубрики: материал в них лежит, и редактор обязан видеть,
// куда он положен, иначе правка молча переложила бы материал в первую видимую.
/** @return list<array{id: string, title: string, visible: bool}> */
function categoryOptions(): array
{
    $rows = all('SELECT id, title, visible FROM ' . t('news_category') . ' ORDER BY order_index, title');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['id' => (string) $row['id'], 'title' => (string) $row['title'], 'visible' => (bool) $row['visible']];
    }

    return $out;
}

// Название — единственное, из чего складывается адрес рубрики, поэтому проверяем и его, и сам
// адрес: «!!!» превратилось бы в пустой slug, а пустое описание не должно ложиться в базу.
/** @return array{title: string, slugBase: string, description: ?string, error: string} */
function checkCategoryTitle(string $rawTitle, string $rawDescription = ''): array
{
    $min = fieldLimit(CATEGORY_FIELDS, 'title', 'min');
    $title = mb_substr(collapseSpace($rawTitle), 0, fieldLimit(CATEGORY_FIELDS, 'title', 'max'));

    if (mb_strlen($title) < $min) {
        return ['title' => '', 'slugBase' => '', 'description' => null, 'error' => 'Название короче ' . $min . ' символов — напишите его целиком.'];
    }

    $slugBase = slugify($title);
    if ($slugBase === '') {
        return [
            'title' => $title,
            'slugBase' => '',
            'description' => null,
            'error' => 'Название должно содержать буквы или цифры: из него строится адрес рубрики.',
        ];
    }

    $limit = fieldLimit(CATEGORY_FIELDS, 'description', 'max');
    $text = mb_substr(collapseSpace($rawDescription), 0, $limit);

    return ['title' => $title, 'slugBase' => $slugBase, 'description' => $text === '' ? null : $text, 'error' => ''];
}

// Пробелы и переносы в названии и описании схлопываются: человек вставляет текст из письма,
// а рубрика в списке и на странице читается одной строкой.
function collapseSpace(string $raw): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $raw));
}

function categoryExists(string $id): bool
{
    if ($id === '') {
        return false;
    }

    return (int) value('SELECT COUNT(*) FROM ' . t('news_category') . ' WHERE id = ?', [$id]) > 0;
}

// Название рубрики занято, если такая строка уже есть и это не та же самая рубрика: сравнение ведёт
// коллация, поэтому «Методика» и «МЕТОДИКА» — одно название.
function categoryTitleTaken(string $title, string $exceptId = ''): bool
{
    $row = one('SELECT id FROM ' . t('news_category') . ' WHERE title = ?', [$title]);

    return $row !== null && (string) $row['id'] !== $exceptId;
}

// Список показывает и невидимые материалы: рубрика с двадцатью черновиками выглядит живой
// в админке и пустой на сайте, и расхождение между счётчиками человек читал бы как поломку.
/**
 * @return list<array{
 *     id: string, slug: string, title: string, description: ?string, order: int, visible: bool,
 *     items: array{total: int, visible: int}
 * }>
 */
function adminCategoryRows(): array
{
    $sql = sprintf(
        'SELECT c.id, c.slug, c.title, c.description, c.order_index, c.visible,'
            . ' COUNT(i.id) AS total,'
            . " SUM(CASE WHEN i.status = '%s' AND i.published_at IS NOT NULL AND i.published_at <= ? THEN 1 ELSE 0 END) AS opened"
            . ' FROM %s c LEFT JOIN %s i ON i.category_id = c.id'
            . ' GROUP BY c.id, c.slug, c.title, c.description, c.order_index, c.visible'
            . ' ORDER BY c.order_index, c.title',
        NEWS_STATUS['PUBLISHED'],
        t('news_category'),
        t('news_item')
    );

    $rows = all($sql, [utcStamp(nowUtc())]);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'order' => (int) $row['order_index'],
            'visible' => (bool) $row['visible'],
            'items' => ['total' => (int) $row['total'], 'visible' => (int) $row['opened']],
        ];
    }

    return $out;
}

/** @return array{ok: bool, notice: string, error: string} */
function createCategory(string $rawTitle, string $rawDescription = ''): array
{
    $checked = checkCategoryTitle($rawTitle, $rawDescription);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }
    if (categoryTitleTaken($checked['title'])) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрика с таким названием уже есть.'];
    }

    $slug = ensureUniqueSlug(
        $checked['slugBase'],
        static fn (string $candidate): bool => (int) value('SELECT COUNT(*) FROM ' . t('news_category') . ' WHERE slug = ?', [$candidate]) > 0,
        'rubrika'
    );

    $last = (int) value('SELECT COALESCE(MAX(order_index), 0) FROM ' . t('news_category'));
    $id = newId();
    $stamp = utcStamp(nowUtc());

    run(
        'INSERT INTO ' . t('news_category')
            . ' (id, slug, title, description, order_index, visible, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
        [$id, $slug, $checked['title'], $checked['description'], $last + 1, $stamp, $stamp]
    );

    logInfo('news.category_created', ['categoryId' => $id, 'slug' => $slug]);

    return ['ok' => true, 'notice' => 'created', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function renameCategory(string $id, string $rawTitle): array
{
    $row = one('SELECT id, title FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $checked = checkCategoryTitle($rawTitle);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }
    if (categoryTitleTaken($checked['title'], (string) $row['id'])) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрика с таким названием уже есть.'];
    }

    run(
        'UPDATE ' . t('news_category') . ' SET title = ?, updated_at = ? WHERE id = ?',
        [$checked['title'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.category_renamed', [
        'categoryId' => (string) $row['id'],
        'from' => (string) $row['title'],
        'to' => $checked['title'],
    ]);

    // Адрес остаётся прежним: он один раз попал в ссылки, карточки и карту сайтов.
    return ['ok' => true, 'notice' => 'renamed', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function updateCategoryDescription(string $id, string $rawTitle, string $rawDescription): array
{
    $row = one('SELECT id FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $checked = checkCategoryTitle($rawTitle, $rawDescription);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }
    if (categoryTitleTaken($checked['title'], (string) $row['id'])) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрика с таким названием уже есть.'];
    }

    run(
        'UPDATE ' . t('news_category') . ' SET title = ?, description = ?, updated_at = ? WHERE id = ?',
        [$checked['title'], $checked['description'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.category_described', ['categoryId' => (string) $row['id']]);

    return ['ok' => true, 'notice' => 'described', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function toggleCategoryVisibility(string $id): array
{
    $row = one('SELECT id, visible FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $visible = !((bool) $row['visible']);
    run(
        'UPDATE ' . t('news_category') . ' SET visible = ?, updated_at = ? WHERE id = ?',
        [$visible ? 1 : 0, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.category_visibility', ['categoryId' => (string) $row['id'], 'visible' => $visible]);

    return ['ok' => true, 'notice' => $visible ? 'shown' : 'hidden', 'error' => ''];
}

// Рубрика с материалами не удаляется, а отказ называет причину: внешний ключ RESTRICT всё равно
// не дал бы удалить строку, но человек получил бы вместо текста ошибку базы.
/** @return array{ok: bool, notice: string, error: string} */
function deleteCategory(string $id): array
{
    $row = one('SELECT id, title FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $count = (int) value('SELECT COUNT(*) FROM ' . t('news_item') . ' WHERE category_id = ?', [$id]);
    if ($count > 0) {
        return [
            'ok' => false,
            'notice' => '',
            'error' => 'В рубрике ' . $count . ' ' . plural($count, ['материал', 'материала', 'материалов'])
                . '. Удалить её нельзя — переведите новости в другую рубрику или скройте: материалы останутся у сайта,'
                . ' а с ленты уйдут.',
        ];
    }

    run('DELETE FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    logInfo('news.category_deleted', ['categoryId' => $id, 'title' => (string) $row['title']]);

    return ['ok' => true, 'notice' => 'deleted', 'error' => ''];
}

// Порядок пересобирается целиком: так исправляются и старые дубликаты order_index, которые
// могли остаться от ручной правки базы.
/** @return array{ok: bool, notice: string, error: string} */
function moveCategory(string $id, string $direction): array
{
    $rows = all('SELECT id FROM ' . t('news_category') . ' ORDER BY order_index, title');
    $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
    $index = array_search($id, $ids, true);

    if ($index === false) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $target = $direction === 'up' ? $index - 1 : $index + 1;
    if ($target < 0 || $target >= count($ids)) {
        return ['ok' => true, 'notice' => $direction === 'up' ? 'topped' : 'bottomed', 'error' => ''];
    }

    $next = $ids;
    $next[$index] = $ids[$target];
    $next[$target] = $ids[$index];

    $stamp = utcStamp(nowUtc());
    tx(static function () use ($next, $stamp): void {
        foreach ($next as $position => $categoryId) {
            run(
                'UPDATE ' . t('news_category') . ' SET order_index = ?, updated_at = ? WHERE id = ?',
                [$position + 1, $stamp, $categoryId]
            );
        }
    });

    logInfo('news.category_moved', ['categoryId' => $id, 'direction' => $direction]);

    return ['ok' => true, 'notice' => 'moved', 'error' => ''];
}

// ── Теги ─────────────────────────────────────────────────

// Тегу адрес транслитерируется, русская надпись остаётся в `label`.
function tagSlugBase(string $label): string
{
    return slugify(mb_substr(collapseSpace($label), 0, fieldLimit(TAG_FIELDS, 'label', 'max')));
}

/** @return array{id: string, slug: string, label: string}|null */
function findTag(string $slug): ?array
{
    $row = one('SELECT id, slug, label FROM ' . t('news_tag') . ' WHERE slug = ?', [$slug]);

    if ($row === null) {
        return null;
    }

    return ['id' => (string) $row['id'], 'slug' => (string) $row['slug'], 'label' => (string) $row['label']];
}

/** @return list<array{id: string, slug: string, label: string}> */
function visibleTags(): array
{
    $rows = all('SELECT id, slug, label FROM ' . t('news_tag') . ' ORDER BY label');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['id' => (string) $row['id'], 'slug' => (string) $row['slug'], 'label' => (string) $row['label']];
    }

    return $out;
}

/** @return list<array{slug: string, label: string}> */
function tagOptions(): array
{
    $rows = all('SELECT slug, label FROM ' . t('news_tag') . ' ORDER BY label');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['slug' => (string) $row['slug'], 'label' => (string) $row['label']];
    }

    return $out;
}

// Счётчик материалов тега читает все строки, а не только видимые: снятый с публикации материал
// всё ещё держит тег, и удалить его справочник не даст — иначе удаление упало бы на внешнем ключе.
function tagCountOf(string $id): int
{
    if ($id === '') {
        return 0;
    }

    return (int) value('SELECT COUNT(*) FROM ' . t('news_item_tag') . ' WHERE tag_id = ?', [$id]);
}

/** @return list<array{id: string, slug: string, label: string, items: int}> */
function adminTagRows(): array
{
    $sql = sprintf(
        'SELECT tg.id, tg.slug, tg.label, COUNT(link.tag_id) AS items'
            . ' FROM %s tg LEFT JOIN %s link ON link.tag_id = tg.id'
            . ' GROUP BY tg.id, tg.slug, tg.label'
            . ' ORDER BY tg.label',
        t('news_tag'),
        t('news_item_tag')
    );

    $rows = all($sql);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'slug' => (string) $row['slug'],
            'label' => (string) $row['label'],
            'items' => (int) $row['items'],
        ];
    }

    return $out;
}

// Теги заводит форма материала: новый тег не появляется, если надпись пустая или её уже выбрали
// в этом же списке. Возвращаются идентификаторы — их пишет связь news_item_tag.
/**
 * @param list<string> $labels
 *
 * @return list<string>
 */
function resolveTagIds(array $labels): array
{
    $min = fieldLimit(TAG_FIELDS, 'label', 'min');
    $max = fieldLimit(TAG_FIELDS, 'label', 'max');
    $ids = [];

    foreach ($labels as $label) {
        // Надпись короче предела не становится тегом: «а» собрало бы вокруг себя все опечатки.
        $clean = mb_substr(collapseSpace((string) $label), 0, $max);
        if (mb_strlen($clean) < $min) {
            continue;
        }

        $existing = one('SELECT id FROM ' . t('news_tag') . ' WHERE label = ?', [$clean]);
        if ($existing !== null) {
            $ids[] = (string) $existing['id'];

            continue;
        }

        $slug = ensureUniqueSlug(
            tagSlugBase($clean),
            static fn (string $candidate): bool => (int) value('SELECT COUNT(*) FROM ' . t('news_tag') . ' WHERE slug = ?', [$candidate]) > 0,
            'tag'
        );

        $id = newId();
        run('INSERT INTO ' . t('news_tag') . ' (id, slug, label) VALUES (?, ?, ?)', [$id, $slug, $clean]);
        logInfo('news.tag_created', ['tagId' => $id, 'slug' => $slug, 'label' => $clean]);
        $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

/** @return array{ok: bool, notice: string, error: string} */
function renameTag(string $id, string $rawLabel): array
{
    $row = one('SELECT id, label FROM ' . t('news_tag') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Тега уже нет — обновите страницу.'];
    }

    $min = fieldLimit(TAG_FIELDS, 'label', 'min');
    $label = mb_substr(collapseSpace($rawLabel), 0, fieldLimit(TAG_FIELDS, 'label', 'max'));
    if (mb_strlen($label) < $min) {
        return ['ok' => false, 'notice' => '', 'error' => 'Надпись короче ' . $min . ' символов.'];
    }

    $taken = one('SELECT id FROM ' . t('news_tag') . ' WHERE label = ?', [$label]);
    if ($taken !== null && (string) $taken['id'] !== (string) $row['id']) {
        return ['ok' => false, 'notice' => '', 'error' => 'Тег с такой надписью уже есть.'];
    }

    run('UPDATE ' . t('news_tag') . ' SET label = ? WHERE id = ?', [$label, (string) $row['id']]);
    logInfo('news.tag_renamed', ['tagId' => (string) $row['id'], 'from' => (string) $row['label'], 'to' => $label]);

    // Адрес тега остаётся прежним: подборка /news/t/… уже попала в ссылки карточек.
    return ['ok' => true, 'notice' => 'tagRenamed', 'error' => ''];
}

// Тег без материалов удаляется: висящая ссылка /news/t/… на пустую подборку бесполезна.
/** @return array{ok: bool, notice: string, error: string} */
function deleteTag(string $id): array
{
    $row = one('SELECT id, label FROM ' . t('news_tag') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Тега уже нет — обновите страницу.'];
    }

    $count = tagCountOf($id);
    if ($count > 0) {
        return [
            'ok' => false,
            'notice' => '',
            'error' => 'Тег стоит на ' . $count . ' ' . plural($count, ['материале', 'материалах', 'материалах'])
                . ' — сначала снимите его в форме новости.',
        ];
    }

    run('DELETE FROM ' . t('news_tag') . ' WHERE id = ?', [$id]);
    logInfo('news.tag_deleted', ['tagId' => $id, 'label' => (string) $row['label']]);

    return ['ok' => true, 'notice' => 'tagDeleted', 'error' => ''];
}
```

Изменить `apps/news/lib/boot.php` — добавить пятую строку:

```php
require_once __DIR__ . '/catalog.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/catalog.php"`
Expected: `No syntax errors detected`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpstan analyse --no-progress apps/news`
Expected: ошибок нет.

- [ ] **Шаг 3: Прогнать тест справочников**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter CatalogTest`
Expected: PASS — 25 проверок.

- [ ] **Шаг 4: Прогнать все наборы**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — все четыре набора зелёные. `NewsSeedTest` проходит: `setUp()` теста справочников очищает таблицы
раздела и возвращает рубрики закладки, а `tearDownAfterClass()` убирает таблицы и строку установки.

- [ ] **Шаг 5: Ворота и фиксация**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add apps/news/lib/catalog.php apps/news/lib/boot.php tests/db/NewsDbTestCase.php tests/db/CatalogTest.php
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git commit -m "Справочники раздела «Новости»: рубрики с порядком и теги из формы материала"
```

---

### Задача 14: Публичное чтение раздела — лента, карточка, прежние адреса

**Файлы:**
- Создать: `apps/news/lib/feed.php`
- Изменить: `apps/news/lib/boot.php` (подключить `feed.php`)
- Тест: `tests/unit/NewsFeedRulesTest.php`, `tests/db/NewsFeedTest.php`

**Интерфейсы:**
- Потребляет: `all()`, `one()`, `value()`, `t()`, `escapeLike()` из `core/db.php`; `nowUtc()`, `utcStamp()`,
  `fromDb()`, `moscowIso()`, `formatRuDate()`, `formatRuDateTime()` из `core/date.php`;
  `visibleWhere()`, `visibleArgs()`, `orderNews()`, `isOpen()` из `apps/news/lib/visibility.php`;
  `parseQuery()` из `apps/news/lib/validators.php`; `NEWS_PER_PAGE`, `NEWS_STATUS`, `SEARCH_MAX_LENGTH`
  из `apps/news/lib/constants.php`; `resolveTagIds()`, `toggleCategoryVisibility()`, `categoryIdOf()`
  из `apps/news/lib/catalog.php` (задача 13); `Cms\Tests\db\NewsDbTestCase` (задача 13).
- Производит:
  - `pageCount(int $total, int $perPage = NEWS_PER_PAGE): int`, `clampPage(int $page, int $pages): int`,
    `pageWindow(int $current, int $pages): list<int>`, `takeLimit(int $take): int` — чистые правила пагинации.
  - `newsHref(array $target = []): string` — `/news/{slug}`, `/news/c/{slug}`, `/news/t/{slug}`, `/news`.
  - `feedHref(string $path, array $query = []): string` — тот же адрес с `?q=` и `?page=`, страница только со второй.
  - `feedBase(string $category, string $tag): string` — адрес страницы, на которой живёт эта лента.
  - `feedScope(array $filter): array` — `FROM`, `WHERE` и аргументы публичной выборки.
  - `findFeed(array $filter = [], int $page = 1): array` — карточки страницы, общее число, страницы, окно номеров.
  - `toCard(array $row, DateTimeImmutable $now): array`, `toFull(array $row, DateTimeImmutable $now): array`,
    `paragraphsOf(mixed $body): list<array{title: string, text: string}>`, `itemTags(string $itemId): array`.
  - `findPublished(string $slug, DateTimeImmutable $now): ?array`, `findAny(string $slug): ?array`,
    `findByOldSlug(string $slug): ?array`, `inCategory(string $categorySlug, DateTimeImmutable $now, int $take = 5): array`,
    `relatedLinks(string $categorySlug, string $excludeSlug, DateTimeImmutable $now, int $take = 5): array`,
    `publishedForSitemap(int $limit = 2000): array`.
  - `categoryFeedRows(string $categorySlug, string $excludeSlug, DateTimeImmutable $now, int $take): array` —
    служебная функция этого файла: общее тело `inCategory()` и `relatedLinks()`, чтобы скелет публичной выборки
    рубрики печатался один раз. Соседи по плану её не вызывают.

**Что сюда не входит:** функции живых блоков (`blockNews`, `blockSingle`, `publishedBlockOptions`, `toBlock`) не
портируются — конструктора страниц в пилоте нет (отклонение 6). Маршруты и шаблоны, которые вызывают эти функции,
приходят в задаче 16; здесь только чтение.

**Два правила, которые держит этот файл:**
1. Ни одна публичная выборка не обходит `visibleWhere()` + `visibleArgs()`: черновик и материал с ненаступившим
   сроком не видны на сайте ни одним путём (спецификация §8.2).
2. Динамический SQL собирается `sprintf()`, а не склеиванием строки запроса с переменной — склеивание запрещает
   страж `tests/guard/SqlSafetyTest.php`. Склеивание с **вызовом функции** (`t('news_item')`, `visibleWhere()`)
   безопасно и в плане используется.

**Почему `visibleWhere()` пишется без префикса таблицы:** условие собирается один раз на весь раздел, а вызывающий
код сам выбирает, с соединением читать или без. В таблицах `news_category`, `news_tag` и `news_old_slug` колонок
`status` и `published_at` нет, поэтому неопределённое имя читается однозначно в любом из соединений этого файла.
Все спорные имена (`slug`, `title`) здесь явно с префиксом.

**Скрытая рубрика:** её материалы уходят из ленты (`feedScope()` требует `c.visible = 1`), но страница материала по
прямому адресу открывается — скрыта рубрика, а не материал (§8.4). В карту сайта материал тоже попадает (§8.6
говорит «видимые материалы … скрытые рубрики … не попадают», то есть из карты уходят страницы рубрик).

- [ ] **Шаг 1: Написать падающие тесты чтения**

Создать `tests/unit/NewsFeedRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Чистые правила ленты: страницы, адреса, размер выборки и разбор тела. База им не нужна,
// поэтому они живут в наборе unit и проверяются за миллисекунды.
final class NewsFeedRulesTest extends TestCase
{
    public function testЧислоСтраницНикогдаНеМеньшеЕдиницы(): void
    {
        $cases = [
            'пустая лента' => [0, 1],
            'один материал' => [1, 1],
            'ровно страница' => [NEWS_PER_PAGE, 1],
            'страница и один' => [NEWS_PER_PAGE + 1, 2],
            'две страницы' => [NEWS_PER_PAGE * 2, 2],
            'две и хвост' => [NEWS_PER_PAGE * 2 + 5, 3],
        ];

        foreach ($cases as $label => [$total, $expected]) {
            self::assertSame($expected, pageCount((int) $total), (string) $label);
        }
    }

    public function testНомерСтраницыНеВыходитЗаПределыЛенты(): void
    {
        $cases = [
            'ноль' => [0, 5, 1],
            'отрицательный' => [-3, 5, 1],
            'дальше конца' => [99, 5, 5],
            'внутри ленты' => [3, 5, 3],
            'первая' => [1, 5, 1],
        ];

        foreach ($cases as $label => [$page, $pages, $expected]) {
            self::assertSame($expected, clampPage((int) $page, (int) $pages), (string) $label);
        }
    }

    public function testОкноНомеровДержитПервуюИПоследнююСтраницу(): void
    {
        $cases = [
            'одна страница' => [1, 1, [1]],
            'окно короче предела' => [3, 5, [1, 2, 3, 4, 5]],
            'ровно предел' => [4, NEWS_PAGER_WINDOW, [1, 2, 3, 4, 5, 6, 7]],
            'начало длинной ленты' => [1, 20, [1, 2, 3, 4, 5, 6, 20]],
            'середина длинной ленты' => [10, 20, [1, 8, 9, 10, 11, 12, 20]],
            'конец длинной ленты' => [20, 20, [1, 15, 16, 17, 18, 19, 20]],
        ];

        foreach ($cases as $label => [$current, $pages, $expected]) {
            self::assertSame($expected, pageWindow((int) $current, (int) $pages), (string) $label);
        }
    }

    public function testРазмерВыборкиОграниченСтраницейЛенты(): void
    {
        self::assertSame(1, takeLimit(0));
        self::assertSame(1, takeLimit(-5));
        self::assertSame(5, takeLimit(5));
        self::assertSame(NEWS_PER_PAGE, takeLimit(1000));
    }

    public function testАдресРазделаСкладываетсяИзОдногоСегмента(): void
    {
        $cases = [
            'лента' => [[], '/news'],
            'материал' => [['slug' => 'itogi-goda'], '/news/itogi-goda'],
            'рубрика' => [['category' => 'zhizn-shkoly'], '/news/c/zhizn-shkoly'],
            'тег' => [['tag' => 'olimpiada'], '/news/t/olimpiada'],
            'материал важнее рубрики' => [['category' => 'zhizn-shkoly', 'slug' => 'itogi'], '/news/itogi'],
        ];

        foreach ($cases as $label => [$target, $expected]) {
            self::assertSame($expected, newsHref((array) $target), (string) $label);
        }
    }

    public function testАдресСтраницыЛентыДержитПоискИНомерСтраницы(): void
    {
        self::assertSame('/news', feedHref('/news'));
        self::assertSame('/news?q=%D0%BA%D0%BE%D1%82', feedHref('/news', ['q' => 'кот']));
        self::assertSame('/news/c/zhizn-shkoly?page=3', feedHref('/news/c/zhizn-shkoly', ['page' => 3]));
        self::assertSame('/news?q=%D0%BA%D0%BE%D1%82&page=2', feedHref('/news', ['q' => 'кот', 'page' => 2]));

        // Первая страница в адрес не попадает: ?page=1 дублировал бы чистую ссылку ленты,
        // а поисковик увидел бы два одинаковых адреса.
        self::assertSame('/news', feedHref('/news', ['page' => 1]));
        self::assertSame('/news', feedHref('/news', ['q' => '   ']));
    }

    public function testСтрокаПоискаОбрезаетсяДоПредела(): void
    {
        $long = str_repeat('а', SEARCH_MAX_LENGTH + 50);

        self::assertSame(
            '/news?q=' . rawurlencode(str_repeat('а', SEARCH_MAX_LENGTH)),
            feedHref('/news', ['q' => $long])
        );
    }

    public function testТелоМатериалаЧитаетсяИзКолонкиJson(): void
    {
        $paragraphs = paragraphsOf('[{"title":"Итоги","text":"Текст"},{"title":"","text":"Второй"}]');

        self::assertCount(2, $paragraphs);
        self::assertSame('Итоги', $paragraphs[0]['title']);
        self::assertSame('Текст', $paragraphs[0]['text']);
        self::assertSame('Второй', $paragraphs[1]['text']);
    }

    public function testПустоеИПовреждённоеТелоНеЛомаютСтраницу(): void
    {
        // Колонка JSON допускает NULL, а строка в ней может прийти обрезанной: карточка обязана
        // напечататься без абзацев, а не ответить страницей 500.
        self::assertSame([], paragraphsOf(null));
        self::assertSame([], paragraphsOf(''));
        self::assertSame([], paragraphsOf('[{"title":"Ито'));
        self::assertSame([], paragraphsOf('{"title":"Итоги","text":"Текст"}'));
        self::assertCount(1, paragraphsOf([['title' => 'Итоги', 'text' => 'Текст']]));
    }
}
```

Создать `tests/db/NewsFeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Лента проверяется на настоящей базе: условие видимости, соединения с рубрикой и тегом,
// экранирование LIKE и порядок «закреплённые вперёд» — свойства запросов, а не кода.
final class NewsFeedTest extends NewsDbTestCase
{
    public function testЧерновикИМатериалДоСрокаНеПопадаютВЛенту(): void
    {
        $this->published('na-sajte');
        $this->published('zhdyot-sroka', ['published_at' => utcStamp(nowUtc()->add(new DateInterval('PT2H')))]);
        self::makeItem(categoryIdOf('zhizn-shkoly'), 'chernovik', [
            'status' => NEWS_STATUS['DRAFT'],
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT1H'))),
        ]);

        $feed = findFeed();

        self::assertSame(1, $feed['total']);
        self::assertSame(['na-sajte'], $this->feedSlugs($feed));
        self::assertSame('/news', $feed['base']);
    }

    public function testЛентаИдётНовымиВперёдАЗакреплённыйМатериалПервым(): void
    {
        $this->published('staryy', ['published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT48H')))]);
        $this->published('novyy', ['published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT1H')))]);
        $this->published('zakreplennyy', [
            'pinned' => 1,
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT72H'))),
        ]);

        self::assertSame(['zakreplennyy', 'novyy', 'staryy'], $this->feedSlugs(findFeed()));
    }

    public function testСоВторойСтраницыЗакреплениеНеПоднимается(): void
    {
        $total = NEWS_PER_PAGE + 3;

        for ($n = 1; $n <= $total; $n++) {
            $this->published(sprintf('m-%02d', $n), [
                'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT' . $n . 'H'))),
            ]);
        }

        // Закрепляем самый старый материал: на первой странице он поднимется первым, а на второй
        // его уже не будет — один и тот же материал не печатается на двух страницах, и m-12
        // не теряется на стыке.
        run('UPDATE ' . t('news_item') . ' SET pinned = 1 WHERE slug = ?', [sprintf('m-%02d', $total)]);

        $first = findFeed([], 1);
        self::assertSame(2, $first['pages']);
        self::assertSame(sprintf('m-%02d', $total), (string) $first['items'][0]['slug']);
        self::assertSame([1, 2], $first['window']);

        $second = findFeed([], 2);
        self::assertSame(2, $second['current']);
        self::assertSame(['m-12', 'm-13', 'm-14'], $this->feedSlugs($second));
    }

    public function testПоискЧитаетСпецсимволыLikeКакТекст(): void
    {
        $this->published('skidka-100', ['title' => 'Скидка 100%']);
        $this->published('skidka-1000', ['title' => 'Скидка 1000 рублей']);

        // Без escapeLike() знак процента означал бы «что угодно» и поиск отдал бы оба материала.
        self::assertSame(['skidka-100'], $this->feedSlugs(findFeed(['q' => '100%'])));
        self::assertSame(['skidka-1000'], $this->feedSlugs(findFeed(['q' => '1000'])));
        self::assertSame('100%', findFeed(['q' => '100%'])['q']);
    }

    public function testРубрикаФильтруетЛенту(): void
    {
        $this->published('zhizn-shkoly-material');
        $this->published('metodika-material', ['category_id' => categoryIdOf('metodika')]);

        $feed = findFeed(['category' => 'metodika']);

        self::assertSame(['metodika-material'], $this->feedSlugs($feed));
        self::assertSame('/news/c/metodika', $feed['base']);
        self::assertSame('metodika', $feed['category']);
    }

    public function testМатериалыСкрытойРубрикиУходятИзЛенты(): void
    {
        $this->published('skrytyy', ['category_id' => categoryIdOf('metodika')]);
        toggleCategoryVisibility(categoryIdOf('metodika'));

        self::assertSame([], $this->feedSlugs(findFeed()));
        self::assertSame(0, findFeed(['category' => 'metodika'])['total']);

        // Скрыта рубрика, а не материал: по прямому адресу страница открывается (§8.4).
        self::assertNotNull(findPublished('skrytyy', nowUtc()));

        $any = findAny('skrytyy');
        self::assertIsArray($any);
        self::assertSame('skrytyy', (string) $any['slug']);
    }

    public function testТегФильтруетЛентуБезПовторов(): void
    {
        $itemId = $this->published('olimpiada');
        $this->published('bez-tega');

        $ids = resolveTagIds(['Олимпиада', 'Школа']);
        self::linkTag($itemId, $ids[0]);
        self::linkTag($itemId, $ids[1]);

        $feed = findFeed(['tag' => 'olimpiada']);

        // Соединение с тегами добавляется только под фильтр: материал с двумя тегами
        // обязан остаться одной строкой, а не двумя.
        self::assertSame(1, $feed['total']);
        self::assertSame(['olimpiada'], $this->feedSlugs($feed));
        self::assertSame('/news/t/olimpiada', $feed['base']);
        self::assertSame([], $this->feedSlugs(findFeed(['tag' => 'net-takogo-tega'])));
    }

    public function testКарточкаМатериалаОтдаётАбзацыТегиИРубрику(): void
    {
        $itemId = $this->published('itogi-goda', [
            'title' => 'Итоги года',
            'lead_text' => 'Короткий лид',
            'cover' => '/uploads/images/itogi.jpg',
            'body' => json_encode([['title' => 'Итоги', 'text' => 'Текст про **итоги**.']], JSON_THROW_ON_ERROR),
        ]);
        self::linkTag($itemId, resolveTagIds(['Олимпиада'])[0]);

        $item = findPublished('itogi-goda', nowUtc());

        self::assertIsArray($item);
        self::assertSame('Итоги года', (string) $item['title']);
        self::assertSame('Короткий лид', (string) $item['lead']);
        self::assertSame('/uploads/images/itogi.jpg', (string) $item['cover']);
        self::assertSame('/news/itogi-goda', (string) $item['href']);
        self::assertSame('Жизнь школы', (string) $item['categoryTitle']);
        self::assertSame('/news/c/zhizn-shkoly', (string) $item['categoryHref']);
        self::assertTrue((bool) $item['open']);
        self::assertNotSame('—', (string) $item['date']);
        self::assertSame([['title' => 'Итоги', 'text' => 'Текст про **итоги**.']], $item['paragraphs']);
        self::assertSame(['olimpiada'], array_column((array) $item['tags'], 'slug'));
        self::assertSame(['/news/t/olimpiada'], array_column((array) $item['tags'], 'href'));
    }

    public function testЧерновикНеОткрываетсяПоАдресуНоВиденЧерезПоискРедактора(): void
    {
        $this->published('na-sajte');
        self::makeItem(categoryIdOf('zhizn-shkoly'), 'chernovik', ['status' => NEWS_STATUS['DRAFT']]);

        self::assertNotNull(findPublished('na-sajte', nowUtc()));
        self::assertNull(findPublished('chernovik', nowUtc()));

        // findAny() читает форма материала и предпросмотр ?draft=1 — поэтому черновик в нём есть.
        $draft = findAny('chernovik');
        self::assertIsArray($draft);
        self::assertFalse((bool) $draft['open']);
        self::assertSame(NEWS_STATUS['DRAFT'], $draft['status']);
    }

    public function testПрежнийАдресНаходитМатериал(): void
    {
        $itemId = $this->published('novyy-adres');
        self::oldSlug($itemId, 'staryy-adres');

        $found = findByOldSlug('staryy-adres');

        self::assertIsArray($found);
        self::assertSame('novyy-adres', (string) $found['slug']);
        self::assertSame('/news/novyy-adres', (string) $found['href']);
        self::assertNull(findByOldSlug('net-takogo-adresa'));
    }

    public function testПохожиеМатериалыРубрикиБезСамогоМатериала(): void
    {
        // Срок публикации у всех разный: при равных моментах порядок «похожих» решает база,
        // и проверка зависела бы от того, как MySQL разложил строки.
        $this->published('pervyy', ['published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT3H')))]);
        $this->published('vtoroy', ['published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT2H')))]);
        $this->published('tretiy', ['published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT1H')))]);
        $this->published('chuzhoy', ['category_id' => categoryIdOf('metodika')]);

        $related = relatedLinks('zhizn-shkoly', 'pervyy', nowUtc(), 5);

        self::assertSame(['tretiy', 'vtoroy'], $this->slugs($related));
        self::assertSame(['tretiy', 'vtoroy', 'pervyy'], $this->slugs(inCategory('zhizn-shkoly', nowUtc(), 5)));
        self::assertSame([], inCategory('net-takoy-rubriki', nowUtc()));
    }

    public function testВыборкаПохожихМатериаловНеБольшеЗапрошенной(): void
    {
        for ($n = 1; $n <= 8; $n++) {
            $this->published(sprintf('m-%02d', $n), [
                'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT' . $n . 'H'))),
            ]);
        }

        self::assertCount(3, inCategory('zhizn-shkoly', nowUtc(), 3));
        self::assertCount(3, relatedLinks('zhizn-shkoly', 'm-01', nowUtc(), 3));

        // Просьба «отдай тысячу» упирается в предел takeLimit(), а не в число строк таблицы.
        self::assertCount(8, inCategory('zhizn-shkoly', nowUtc(), 1000));
    }

    public function testКартаСайтаБерётОткрытыеМатериалыБезЗапретаИндексации(): void
    {
        $this->published('v-karte');
        $this->published('bez-indeksa', ['noindex' => 1]);
        $this->published('zhdyot-sroka', ['published_at' => utcStamp(nowUtc()->add(new DateInterval('PT2H')))]);
        self::makeItem(categoryIdOf('zhizn-shkoly'), 'chernovik', ['status' => NEWS_STATUS['DRAFT']]);

        $urls = publishedForSitemap();

        self::assertSame(['/news/v-karte'], array_column($urls, 'loc'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $urls[0]['lastmod']);

        // Предел числа адресов держит сама функция: карта сайта не обязана отдавать всю таблицу
        // одним запросом, а вызывающий код не должен помнить про это ограничение.
        self::assertCount(1, publishedForSitemap(1));
    }

    // ── Помощники ────────────────────────────────────────

    /**
     * Материал, который открыт на сайте: напечатан, срок наступил час назад, лежит в «Жизни школы».
     *
     * @param array<string, mixed> $overrides
     */
    private function published(string $slug, array $overrides = []): string
    {
        $category = (string) ($overrides['category_id'] ?? categoryIdOf('zhizn-shkoly'));
        unset($overrides['category_id']);

        return self::makeItem($category, $slug, array_merge([
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT1H'))),
        ], $overrides));
    }

    /** @param list<array<string, mixed>> $items */
    private function slugs(array $items): array
    {
        return array_map(static fn (array $item): string => (string) $item['slug'], $items);
    }

    /** @param array{items: list<array<string, mixed>>} $feed */
    private function feedSlugs(array $feed): array
    {
        return $this->slugs($feed['items']);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsFeedRulesTest`
Expected: FAIL — `Call to undefined function pageCount()`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsFeedTest`
Expected: FAIL — `Call to undefined function findFeed()`.

- [ ] **Шаг 2: Написать чтение раздела**

Создать `apps/news/lib/feed.php`:

```php
<?php

declare(strict_types=1);

// Публичное чтение раздела: лента, карточка, страницы рубрики и тега, «похожие» материалы,
// прежние адреса и набор адресов для карты сайта. Порт english-portal/src/apps/news/lib/feed.ts
// без блочных функций (отклонение 6).

// Сколько номеров страниц печатает пагинатор: первая, последняя и соседние текущей.
// Число живёт здесь, а не в constants.php: это правило вывода ленты, а не предел поля формы.
const NEWS_PAGER_WINDOW = 7;

// Колонки карточки — явный список. SELECT * притащил бы в ленту JSON каждого материала:
// страница из 12 карточек читала бы 12 полных текстов, которые на ней не печатаются.
const NEWS_CARD_COLUMNS = 'i.id, i.slug, i.title, i.lead_text, i.cover, i.pinned, i.status, i.published_at,'
    . ' c.slug AS category_slug, c.title AS category_title';

// К колонкам карточки добавляется то, что читает страница материала: абзацы, метаданные для
// поисковика, снимок автора и момент последней правки. Теги — отдельным запросом (itemTags()).
const NEWS_FULL_COLUMNS = NEWS_CARD_COLUMNS
    . ', i.body, i.seo_title, i.seo_description, i.og_image, i.noindex,'
    . ' i.author_email, i.category_id, i.updated_at';

/**
 * Число страниц ленты. Пустая лента — одна страница, а не ноль: пагинатор обязан печатать
 * «страница 1 из 1», иначе человек увидел бы пустой список без объяснения.
 */
function pageCount(int $total, int $perPage = NEWS_PER_PAGE): int
{
    if ($perPage <= 0) {
        return 1;
    }

    return max(1, (int) ceil($total / $perPage));
}

function clampPage(int $page, int $pages): int
{
    return $page < 1 ? 1 : min($page, max(1, $pages));
}

/**
 * Номера страниц для пагинатора: не больше NEWS_PAGER_WINDOW штук, всегда с первой и последней.
 * У края ленты окно растёт в середину — иначе на первой странице человек увидел бы три номера.
 *
 * @return list<int>
 */
function pageWindow(int $current, int $pages): array
{
    $total = max(1, $pages);

    if ($total <= NEWS_PAGER_WINDOW) {
        return range(1, $total);
    }

    $radius = intdiv(NEWS_PAGER_WINDOW - 2, 2);
    $start = max(2, min($current, $total) - $radius);
    $end = min($total - 1, max(1, $current) + $radius);

    if ($start === 2) {
        $end = min($total - 1, NEWS_PAGER_WINDOW - 1);
    }

    if ($end === $total - 1) {
        $start = max(2, $total - (NEWS_PAGER_WINDOW - 2));
    }

    return array_merge([1], range($start, $end), [$total]);
}

/** Сколько материалов просить у «похожих» и блоков вывода: не меньше одного, не больше страницы. */
function takeLimit(int $take): int
{
    return max(1, min($take, NEWS_PER_PAGE));
}

/**
 * Адрес страницы раздела. Один сегмент решает, что это: материал, рубрика, тег или сама лента.
 * Значения кодируются — адрес приходит из базы, а не из кода.
 *
 * @param array{slug?: string, category?: string, tag?: string} $target
 */
function newsHref(array $target = []): string
{
    $slug = trim((string) ($target['slug'] ?? ''));

    if ($slug !== '') {
        return '/news/' . rawurlencode($slug);
    }

    $category = trim((string) ($target['category'] ?? ''));

    if ($category !== '') {
        return '/news/c/' . rawurlencode($category);
    }

    $tag = trim((string) ($target['tag'] ?? ''));

    if ($tag !== '') {
        return '/news/t/' . rawurlencode($tag);
    }

    return '/news';
}

/**
 * Адрес ленты с поиском и номером страницы. Первая страница в адрес не попадает: `?page=1`
 * дублировал бы чистую ссылку, и поисковик увидел бы два одинаковых адреса.
 *
 * @param array{q?: string, page?: int} $query
 */
function feedHref(string $path, array $query = []): string
{
    $parts = [];
    $text = parseQuery($query['q'] ?? null);

    if ($text !== '') {
        $parts[] = 'q=' . rawurlencode($text);
    }

    $page = (int) ($query['page'] ?? 1);

    if ($page > 1) {
        $parts[] = 'page=' . $page;
    }

    return $parts === [] ? $path : $path . '?' . implode('&', $parts);
}

/** Страница, на которой живёт лента: рубрика, тег или общая лента. */
function feedBase(string $category, string $tag): string
{
    if ($tag !== '') {
        return newsHref(['tag' => $tag]);
    }

    if ($category !== '') {
        return newsHref(['category' => $category]);
    }

    return newsHref();
}

/** FROM для одного материала: рубрика нужна и в карточке, и в «похожих». */
function newsItemFrom(): string
{
    return sprintf('%s i JOIN %s c ON c.id = i.category_id', t('news_item'), t('news_category'));
}

/**
 * FROM, WHERE и аргументы публичной выборки.
 *
 * Соединение с тегами добавляется только под фильтр по тегу: постоянное соединение повторило бы
 * материал по числу его тегов в общей ленте.
 *
 * @param array{q?: string, category?: string, tag?: string, now?: DateTimeImmutable} $filter
 * @return array{
 *     from: string, where: string, args: list<string>, now: DateTimeImmutable,
 *     q: string, category: string, tag: string
 * }
 */
function feedScope(array $filter = []): array
{
    $now = $filter['now'] ?? nowUtc();
    $category = trim((string) ($filter['category'] ?? ''));
    $tag = trim((string) ($filter['tag'] ?? ''));
    $query = parseQuery($filter['q'] ?? null);

    $from = $tag === ''
        ? newsItemFrom()
        : sprintf(
            '%s JOIN %s link ON link.item_id = i.id JOIN %s tg ON tg.id = link.tag_id',
            newsItemFrom(),
            t('news_item_tag'),
            t('news_tag')
        );

    // Рубрика скрыта — её нет в ленте целиком, поэтому условие стоит вне ветки фильтра:
    // иначе общая лента («все рубрики») показывала бы материалы скрытой рубрики.
    $parts = [visibleWhere(), 'c.visible = 1'];
    $args = visibleArgs($now);

    if ($category !== '') {
        $parts[] = 'c.slug = ?';
        $args[] = $category;
    }

    if ($tag !== '') {
        $parts[] = 'tg.slug = ?';
        $args[] = $tag;
    }

    if ($query !== '') {
        $like = '%' . escapeLike($query) . '%';
        $parts[] = '(i.title LIKE ? OR i.lead_text LIKE ?)';
        $args[] = $like;
        $args[] = $like;
    }

    return [
        'from' => $from,
        'where' => implode(' AND ', $parts),
        'args' => $args,
        'now' => $now,
        'q' => $query,
        'category' => $category,
        'tag' => $tag,
    ];
}

/**
 * Лента раздела: карточки страницы, общее число, страницы и окно номеров.
 *
 * @param array{q?: string, category?: string, tag?: string, now?: DateTimeImmutable} $filter
 * @return array{
 *     items: list<array<string, mixed>>, total: int, pages: int, current: int, window: list<int>,
 *     q: string, category: string, tag: string, base: string, perPage: int
 * }
 */
function findFeed(array $filter = [], int $page = 1): array
{
    $scope = feedScope($filter);

    $total = (int) value(
        sprintf('SELECT COUNT(*) FROM %s WHERE %s', $scope['from'], $scope['where']),
        $scope['args']
    );

    $pages = pageCount($total);
    $current = clampPage($page, $pages);

    // LIMIT и OFFSET подставляются числами через %d: оба пришли из кода, а не из запроса.
    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
        NEWS_CARD_COLUMNS,
        $scope['from'],
        $scope['where'],
        orderNews(),
        NEWS_PER_PAGE,
        ($current - 1) * NEWS_PER_PAGE
    );

    $items = [];

    foreach (all($sql, $scope['args']) as $row) {
        $items[] = toCard($row, $scope['now']);
    }

    return [
        'items' => $items,
        'total' => $total,
        'pages' => $pages,
        'current' => $current,
        'window' => pageWindow($current, $pages),
        'q' => $scope['q'],
        'category' => $scope['category'],
        'tag' => $scope['tag'],
        'base' => feedBase($scope['category'], $scope['tag']),
        'perPage' => NEWS_PER_PAGE,
    ];
}

/**
 * Карточка ленты. Момент публикации приводится к московскому времени сразу: шаблон не имеет
 * права сам решать, в каком времени печатать (страж DateUsageTest не пускает туда date() и format()).
 *
 * @param array<string, mixed> $row
 * @return array{
 *     id: string, slug: string, title: string, lead: string, cover: string, pinned: bool,
 *     open: bool, status: string, href: string, publishedAt: DateTimeImmutable|null, date: string,
 *     categorySlug: string, categoryTitle: string, categoryHref: string
 * }
 */
function toCard(array $row, DateTimeImmutable $now): array
{
    $published = fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null);
    $slug = (string) $row['slug'];
    $categorySlug = (string) ($row['category_slug'] ?? '');

    return [
        'id' => (string) $row['id'],
        'slug' => $slug,
        'title' => (string) $row['title'],
        'lead' => (string) ($row['lead_text'] ?? ''),
        'cover' => (string) ($row['cover'] ?? ''),
        'pinned' => (int) ($row['pinned'] ?? 0) === 1,
        'open' => isOpen($row, $now),
        'status' => (string) ($row['status'] ?? NEWS_STATUS['DRAFT']),
        'href' => newsHref(['slug' => $slug]),
        'publishedAt' => $published,
        'date' => formatRuDate($published),
        'categorySlug' => $categorySlug,
        'categoryTitle' => (string) ($row['category_title'] ?? ''),
        'categoryHref' => newsHref(['category' => $categorySlug]),
    ];
}

/**
 * Материал целиком — для страницы материала и её метаданных.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function toFull(array $row, DateTimeImmutable $now): array
{
    $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

    return array_merge(toCard($row, $now), [
        'paragraphs' => paragraphsOf($row['body'] ?? null),
        'seoTitle' => (string) ($row['seo_title'] ?? ''),
        'seoDescription' => (string) ($row['seo_description'] ?? ''),
        'ogImage' => (string) ($row['og_image'] ?? ''),
        'noindex' => (int) ($row['noindex'] ?? 0) === 1,
        'authorEmail' => (string) ($row['author_email'] ?? ''),
        'categoryId' => (string) ($row['category_id'] ?? ''),
        'updatedAt' => $updated,
        'updated' => formatRuDateTime($updated),
        'tags' => itemTags((string) $row['id']),
    ]);
}

/**
 * Абзацы материала. Колонка JSON приходит строкой — MySQL отдаёт JSON текстом, а не массивом.
 *
 * Нечитаемое тело возвращается пустым списком: страница материала обязана напечататься без
 * абзацев, а не ответить ошибкой 500 из-за одной испорченной строки.
 *
 * @return list<array{title: string, text: string}>
 */
function paragraphsOf(mixed $body): array
{
    if (!is_array($body)) {
        $raw = is_string($body) ? trim($body) : '';

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
    } else {
        $decoded = $body;
    }

    $out = [];

    foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : [] as $paragraph) {
        if (!is_array($paragraph)) {
            continue;
        }

        $title = trim((string) ($paragraph['title'] ?? ''));
        $text = trim((string) ($paragraph['text'] ?? ''));

        if ($title === '' && $text === '') {
            continue;
        }

        $out[] = ['title' => $title, 'text' => $text];
    }

    return $out;
}

/**
 * Теги материала — отдельным запросом: в ленте они не нужны, а на странице материала читаются.
 *
 * @return list<array{slug: string, label: string, href: string}>
 */
function itemTags(string $itemId): array
{
    $sql = sprintf(
        'SELECT tg.slug, tg.label FROM %s link JOIN %s tg ON tg.id = link.tag_id'
            . ' WHERE link.item_id = ? ORDER BY tg.label',
        t('news_item_tag'),
        t('news_tag')
    );

    $out = [];

    foreach (all($sql, [$itemId]) as $row) {
        $slug = (string) $row['slug'];
        $out[] = ['slug' => $slug, 'label' => (string) $row['label'], 'href' => newsHref(['tag' => $slug])];
    }

    return $out;
}

/**
 * Открытый материал по адресу — для страницы сайта.
 *
 * @return array<string, mixed>|null
 */
function findPublished(string $slug, DateTimeImmutable $now): ?array
{
    $sql = sprintf(
        'SELECT %s FROM %s WHERE i.slug = ? AND %s',
        NEWS_FULL_COLUMNS,
        newsItemFrom(),
        visibleWhere()
    );

    $row = one($sql, array_merge([$slug], visibleArgs($now)));

    return $row === null ? null : toFull($row, $now);
}

/**
 * Материал по адресу без проверки видимости — для формы редактора и предпросмотра `?draft=1`.
 * Публичная страница его не вызывает: там работает findPublished().
 *
 * @return array<string, mixed>|null
 */
function findAny(string $slug): ?array
{
    $sql = sprintf('SELECT %s FROM %s WHERE i.slug = ?', NEWS_FULL_COLUMNS, newsItemFrom());
    $row = one($sql, [$slug]);

    return $row === null ? null : toFull($row, nowUtc());
}

/**
 * Материал по прежнему адресу — для постоянного перенаправления (§8.3). Возвращает материал
 * целиком: маршрут сам решает, открыт ли он сейчас, и добавляет `?draft=1`, если нет.
 *
 * @return array<string, mixed>|null
 */
function findByOldSlug(string $slug): ?array
{
    $sql = sprintf(
        'SELECT %s FROM %s JOIN %s prev ON prev.item_id = i.id WHERE prev.slug = ?',
        NEWS_FULL_COLUMNS,
        newsItemFrom(),
        t('news_old_slug')
    );

    $row = one($sql, [$slug]);

    return $row === null ? null : toFull($row, nowUtc());
}

/**
 * Карточки последней выборки рубрики — общее тело `inCategory()` и `relatedLinks()`.
 *
 * Скелет запроса печатается один раз: `visibleWhere()` и `orderNews()` попадают в обе публичные
 * выборки из одного места, поэтому правка условия видимости не может оставить одну выборку
 * обновлённой, а вторую — нет (§8.2). Исключение материала — отдельный кусок `WHERE`, который
 * собирается из литерала и добавляется плейсхолдером, а не второй копией запроса.
 *
 * @return list<array<string, mixed>>
 */
function categoryFeedRows(string $categorySlug, string $excludeSlug, DateTimeImmutable $now, int $take): array
{
    $exclude = $excludeSlug === '' ? '' : ' AND i.slug <> ?';

    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s AND c.visible = 1 AND c.slug = ?%s ORDER BY %s LIMIT %d',
        NEWS_CARD_COLUMNS,
        newsItemFrom(),
        visibleWhere(),
        $exclude,
        orderNews(),
        takeLimit($take)
    );

    $args = array_merge(visibleArgs($now), [$categorySlug]);

    if ($excludeSlug !== '') {
        $args[] = $excludeSlug;
    }

    $items = [];

    foreach (all($sql, $args) as $row) {
        $items[] = toCard($row, $now);
    }

    return $items;
}

/**
 * Последние материалы рубрики — блоки вывода и «похожие» под карточкой.
 *
 * @return list<array<string, mixed>>
 */
function inCategory(string $categorySlug, DateTimeImmutable $now, int $take = 5): array
{
    return categoryFeedRows($categorySlug, '', $now, $take);
}

/**
 * То же, что inCategory(), но без самого материала: под карточкой «Итоги года» ссылка на себя
 * не нужна.
 *
 * @return list<array<string, mixed>>
 */
function relatedLinks(string $categorySlug, string $excludeSlug, DateTimeImmutable $now, int $take = 5): array
{
    return categoryFeedRows($categorySlug, $excludeSlug, $now, $take);
}

/**
 * Адреса материалов для карты сайта (§8.6): открытые, без запрета индексации, свежие первыми.
 * `lastmod` — московская дата последней правки, как её видит читатель.
 *
 * Три числа в теле — пределы одного вызова, а не правила ленты: 2000 — сколько адресов раздел
 * отдаёт по умолчанию, 5000 — жёсткий предел, больший заказ режется, чтобы один вызов не вытаскивал
 * всю таблицу, и 1 — нижняя граница, ноль не должен означать «все».
 *
 * @return list<array{loc: string, lastmod?: string}>
 */
function publishedForSitemap(int $limit = 2000): array
{
    $sql = sprintf(
        'SELECT i.slug, i.updated_at FROM %s i WHERE %s AND i.noindex = 0'
            . ' ORDER BY i.updated_at DESC LIMIT %d',
        t('news_item'),
        visibleWhere(),
        max(1, min($limit, 5000))
    );

    $out = [];

    foreach (all($sql, visibleArgs(nowUtc())) as $row) {
        /** @var array{loc: string, lastmod?: string} $entry */
        $entry = ['loc' => newsHref(['slug' => (string) $row['slug']])];
        $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

        if ($updated !== null) {
            $entry['lastmod'] = substr(moscowIso($updated), 0, 10);
        }

        $out[] = $entry;
    }

    return $out;
}
```

Изменить `apps/news/lib/boot.php` — добавить шестую строку:

```php
require_once __DIR__ . '/feed.php';
```

`feed.php` подключается последним: он читает `visibleWhere()` из `visibility.php`, `parseQuery()` из
`validators.php` и `NEWS_PER_PAGE` из `constants.php`. Порядок в `boot.php` — единственное место, где эта
зависимость видна; функции вызываются после подключения всех файлов, поэтому внутри самого `feed.php`
порядок не важен.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/feed.php"`
Expected: `No syntax errors detected`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpstan analyse --no-progress apps/news`
Expected: ошибок нет. Если PHPStan не принимает форму `array_merge()` в `toFull()` против объявленного
`@return`, ключи карточки выписываются в литерал явно — форма, объявленная в комментарии, обязательна:
её читают шаблоны задачи 16.

- [ ] **Шаг 3: Прогнать тесты чтения**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsFeedRulesTest`
Expected: PASS — 9 проверок.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsFeedTest`
Expected: PASS — 13 проверок. Если падает проверка, правка делается в `feed.php`, а не в тесте: тест
описывает правила из спецификации (§8.2, §8.4, §8.6), а не поведение кода.

- [ ] **Шаг 4: Прогнать все наборы**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — все четыре набора зелёные. `NewsSeedTest` и `CatalogTest` не зависят от `feed.php`.

- [ ] **Шаг 5: Ворота и фиксация**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add apps/news/lib/feed.php apps/news/lib/boot.php tests/unit/NewsFeedRulesTest.php tests/db/NewsFeedTest.php
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git commit -m "Публичное чтение раздела «Новости»: лента, карточка, прежние адреса и карта сайта"
```

---
