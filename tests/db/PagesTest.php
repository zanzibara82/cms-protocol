<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Операции записи слоя страниц на живой базе: создание и правка страницы, переселение адреса,
 * гонки уникальных ключей (слаг страницы, ключ секции), предел секций и читатели.
 * Таблицы — ядро, их не дропают и приложение не ставят: TestDb::reset() применяет все релизы sql/.
 */
final class PagesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        foreach (['page_old_slug', 'page_section', 'page'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    public function testСозданиеСтраницыПишетСтрокуСАдресомИСтатусом(): void
    {
        $now = nowUtc();

        $created = createPage([
            'title' => 'О нас',
            'slug' => 'o-nas',
            'description' => 'Кто мы такие',
            'status' => 'DRAFT',
            'noindex' => '0',
        ], $now);

        self::assertTrue($created['ok'], $created['error']);
        self::assertNotSame('', $created['id']);
        self::assertSame('o-nas', $created['slug']);

        $row = one(
            'SELECT slug, title, description, status, noindex, created_at, updated_at FROM ' . t('page') . ' WHERE id = ?',
            [$created['id']]
        );

        self::assertNotNull($row, 'Страница не записалась');
        self::assertSame('О нас', (string) $row['title']);
        self::assertSame('o-nas', (string) $row['slug']);
        self::assertSame('Кто мы такие', (string) $row['description']);
        self::assertSame('DRAFT', (string) $row['status']);
        self::assertSame(0, (int) $row['noindex']);
        self::assertSame(utcStamp($now), (string) $row['created_at']);
        self::assertSame(utcStamp($now), (string) $row['updated_at']);

        // Опубликованная страница с noindex=1: строковые «0»/«1» из формы приводятся к числу.
        $published = createPage([
            'title' => 'Контакты',
            'slug' => 'kontakty',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '1',
        ], $now);

        self::assertTrue($published['ok'], $published['error']);

        $second = one('SELECT status, noindex FROM ' . t('page') . ' WHERE id = ?', [$published['id']]);

        self::assertNotNull($second);
        self::assertSame('PUBLISHED', (string) $second['status']);
        self::assertSame(1, (int) $second['noindex']);

        // Адрес не собирается в операции из ничего: пустой слаг — отказ, а не молчаливая страница на «/».
        $empty = createPage([
            'title' => 'Без адреса',
            'slug' => '   ',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ], $now);

        self::assertFalse($empty['ok']);
        self::assertSame('Адрес страницы не собран — впишите адрес сами.', $empty['error']);
    }

    public function testГонкаАдресаСтраницыОтказываетПоЧеловечески(): void
    {
        // Гонка записи: обе формы прошли проверку «свободно», первое сохранение успело. Адрес
        // держится уникальным ключом, и проигравший обязан получить человеческую причину —
        // не 500 сырого PDOException (механика CommerceCatalogTest).
        self::makePage(['slug' => 'kolonka', 'title' => 'Колонка']);

        $lost = createPage([
            'title' => 'Гонка',
            'slug' => 'kolonka',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ], nowUtc());

        self::assertFalse($lost['ok']);
        self::assertSame('По такому адресу уже есть страница — придумайте другой.', $lost['error']);
        self::assertSame(
            1,
            (int) value('SELECT COUNT(*) FROM ' . t('page') . ' WHERE slug = ?', ['kolonka']),
            'Отказ вставки не создал вторую страницу'
        );

        // Правка с чужим адресом тоже кончается человеческой причиной, и адрес правимого
        // не переписывается.
        $victim = self::makePage(['slug' => 'pravyj', 'title' => 'Правим']);

        $edited = updatePage($victim, [
            'title' => 'Правим',
            'slug' => 'kolonka',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ], nowUtc());

        self::assertFalse($edited['ok']);
        self::assertSame('По такому адресу уже есть страница — придумайте другой.', $edited['error']);
        self::assertSame(
            'pravyj',
            (string) value('SELECT slug FROM ' . t('page') . ' WHERE id = ?', [$victim]),
            'Отказ правки не переписал адрес'
        );
    }

    public function testГонкаКлючаСекцииОтказываетПоЧеловечески(): void
    {
        $pageId = self::makePage(['slug' => 'stranica', 'title' => 'Страница']);
        self::makeSection($pageId, ['kind' => 'prose', 'sec_key' => 'osnovnoe', 'anchor' => 'osnovnoe', 'title' => 'Первое']);

        // Человек попросил тот же якорь: проверка формы могла пройти до появления соперника,
        // поэтому отказ ловится на вставке и называет занятый якорь.
        $lost = createSection($pageId, [
            'kind' => 'prose',
            'anchor' => 'osnovnoe',
            'badge' => '',
            'title' => 'Второе',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        self::assertFalse($lost['ok']);
        self::assertSame('Якорь «osnovnoe» уже занят другой секцией — выберите другой.', $lost['error']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]));

        // Авто-ключ собирается из заголовка и при коллизии нумеруется — молчаливая нумерация
        // разрешена только там, где человек ничего не просил руками.
        $values = static fn (): array => [
            'kind' => 'steps',
            'anchor' => '',
            'badge' => '',
            'title' => 'Шаги',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [['title' => 'Раз', 'text' => 'Один']],
            'settings' => [],
            'visible' => true,
        ];

        $first = createSection($pageId, $values(), nowUtc());

        self::assertTrue($first['ok'], $first['error']);
        self::assertSame('shagi', (string) value('SELECT sec_key FROM ' . t('page_section') . ' WHERE id = ?', [$first['id']]));

        $second = createSection($pageId, $values(), nowUtc());

        self::assertTrue($second['ok'], $second['error']);
        self::assertSame('shagi-2', (string) value('SELECT sec_key FROM ' . t('page_section') . ' WHERE id = ?', [$second['id']]));

        // JSON-колонки пишутся с юникодом, пустые списки — «[]», настройки — «{}», не «null»:
        // шаблон не ветвится на NULL.
        $row = one('SELECT items, buttons, settings, sort FROM ' . t('page_section') . ' WHERE id = ?', [$first['id']]);

        self::assertNotNull($row);
        // MySQL 8 сортирует ключи JSON-объектов при записи (короткие раньше длинных), поэтому
        // списки сравниваются по содержимому, а не по порядку ключей.
        self::assertEquals([['title' => 'Раз', 'text' => 'Один']], json_decode((string) $row['items'], true));
        self::assertSame('[]', (string) $row['buttons']);
        self::assertSame('{}', (string) $row['settings']);
        self::assertSame(1, (int) $row['sort'], 'Секция встаёт в конец: max(sort)+1 после занявшей 0');
    }

    public function testПределСекцийДержитсяНаСорок(): void
    {
        $pageId = self::makePage(['slug' => 'limit', 'title' => 'Предел']);

        for ($i = 0; $i < PAGES_SECTIONS_LIMIT - 1; $i++) {
            self::makeSection($pageId, ['kind' => 'prose', 'sec_key' => 'sec-' . $i, 'title' => 'Секция ' . $i], $i);
        }

        $fill = createSection($pageId, [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Сороковая',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        self::assertTrue($fill['ok'], $fill['error']);

        $extra = createSection($pageId, [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Лишняя',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        self::assertFalse($extra['ok']);
        self::assertStringContainsString((string) PAGES_SECTIONS_LIMIT, $extra['error']);
        self::assertSame(
            PAGES_SECTIONS_LIMIT,
            (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]),
            'Отказ по пределу не дописал лишнюю секцию'
        );
    }

    public function testПереселениеСлагаПишетСтарыйАдресИОсвобождаетНовый(): void
    {
        $first = createPage([
            'title' => 'Первая',
            'slug' => 'staryj',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        self::assertTrue($first['ok'], $first['error']);

        $moved = updatePage($first['id'], [
            'title' => 'Первая',
            'slug' => 'novyj',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        self::assertTrue($moved['ok'], $moved['error']);
        self::assertSame('novyj', $moved['slug']);
        self::assertSame(
            $first['id'],
            (string) value('SELECT page_id FROM ' . t('page_old_slug') . ' WHERE slug = ?', ['staryj']),
            'Прежний адрес ведёт на переезжающую страницу'
        );

        // Правка без смены адреса историю не переписывает.
        $again = updatePage($first['id'], [
            'title' => 'Первая',
            'slug' => 'novyj',
            'description' => 'Свежее описание',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        self::assertTrue($again['ok'], $again['error']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('page_old_slug')));

        // Освободившийся адрес занимает новая страница...
        $second = createPage([
            'title' => 'Вторая',
            'slug' => 'staryj',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ], nowUtc());

        self::assertTrue($second['ok'], $second['error']);

        // ...и её переселение переписывает запись старого адреса: адрес один, история — у нового владельца.
        $secondMove = updatePage($second['id'], [
            'title' => 'Вторая',
            'slug' => 'eshe-odin',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ], nowUtc());

        self::assertTrue($secondMove['ok'], $secondMove['error']);
        self::assertSame(
            $second['id'],
            (string) value('SELECT page_id FROM ' . t('page_old_slug') . ' WHERE slug = ?', ['staryj']),
            'Запись старого адреса переписалась на нового владельца'
        );
    }

    public function testЧитателиОтдаютСтраницуСекцииИОпубликованныеАдреса(): void
    {
        $published = self::makePage(['slug' => 'opublikovana', 'title' => 'Открытая', 'status' => 'PUBLISHED']);
        $draft = self::makePage(['slug' => 'chernovik', 'title' => 'Черновик', 'status' => 'DRAFT']);

        self::makeSection($published, ['kind' => 'hero', 'sec_key' => 'verh', 'title' => 'Верх', 'sort' => 1]);
        self::makeSection($published, ['kind' => 'prose', 'sec_key' => 'niz', 'title' => 'Низ', 'sort' => 0]);
        self::makeSection($draft, ['kind' => 'prose', 'sec_key' => 'skrytaya', 'title' => 'Скрытая', 'sort' => 0, 'visible' => 0]);
        self::makeSection('', ['kind' => 'hero', 'sec_key' => 'glavnaya', 'title' => 'Главная секция', 'sort' => 0]);

        $page = findPage('opublikovana');

        self::assertNotNull($page, 'Опубликованная страница не нашлась по адресу');
        self::assertSame('Открытая', (string) $page['title']);
        self::assertNull(findPage('net-takoj'), 'Чужой адрес не должен находить страницу');

        $byId = pageById($published);

        self::assertNotNull($byId);
        self::assertSame('opublikovana', (string) $byId['slug']);
        self::assertNull(pageById('net-takoj'));

        $titles = static fn (string $pageId): array => array_map(
            static fn (array $row): string => (string) $row['title'],
            pageSections($pageId)
        );

        self::assertSame(['Низ', 'Верх'], $titles($published), 'Секции идут по sort, а не по времени вставки');
        self::assertSame(['Главная секция'], $titles(PAGES_HOME_ID), 'Секции главной живут с page_id = \'\'');

        // JSON-колонки читатели отдают массивами: шаблон и конструктор не занимаются разбором.
        $sections = pageSections($published);

        self::assertSame([], $sections[0]['buttons']);
        self::assertSame([], $sections[0]['items']);

        // Скрытые секции читаются только по прямому запросу: публичный рендер просит видимые.
        self::assertSame([], pageSections($draft, true));

        $secId = self::makeSection($published, ['kind' => 'cta', 'sec_key' => 'podval', 'title' => 'Подвал', 'sort' => 2]);
        $section = sectionById($secId);

        self::assertNotNull($section);
        self::assertSame('Подвал', (string) $section['title']);
        self::assertNull(sectionById('net-takoj'));

        self::assertSame(['opublikovana'], publishedPageSlugs(), 'Карта сайта берёт только опубликованные адреса');
    }

    public function testСекцииПереставляютсяСкрываютсяИУдаляются(): void
    {
        $pageId = self::makePage(['slug' => 'porjadok', 'title' => 'Порядок']);

        $values = static fn (string $title): array => [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => $title,
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ];

        $first = createSection($pageId, $values('Первая'), nowUtc());
        $second = createSection($pageId, $values('Вторая'), nowUtc());

        self::assertTrue($first['ok'], $first['error']);
        self::assertTrue($second['ok'], $second['error']);

        $bad = sectionMove($first['id'], 'sideways');

        self::assertFalse($bad['ok']);
        self::assertSame('Направление перестановки — «вверх» или «вниз».', $bad['error']);

        $up = sectionMove($second['id'], 'up');

        self::assertTrue($up['ok'], $up['error']);
        self::assertSame(['Вторая', 'Первая'], array_map(
            static fn (array $row): string => (string) $row['title'],
            pageSections($pageId)
        ));

        $edge = sectionMove($second['id'], 'up');

        self::assertFalse($edge['ok']);
        self::assertSame('Секция уже крайняя.', $edge['error']);

        // Правка полей секции: заголовок, лид и повторные списки перечитываются массивами.
        $saved = updateSection($first['id'], [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => 'Новое',
            'title' => 'Переименованная',
            'lead_text' => 'Лид секции',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [['label' => 'Подробнее', 'href' => '/news']],
            'items' => [['title' => 'Абзац', 'text' => 'Текст']],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        self::assertTrue($saved['ok'], $saved['error']);

        $savedRow = sectionById($first['id']);

        self::assertNotNull($savedRow);
        self::assertSame('Переименованная', (string) $savedRow['title']);
        self::assertSame('Лид секции', (string) $savedRow['lead_text']);
        // MySQL 8 сортирует ключи JSON-объектов при записи — сравниваем по содержимому.
        self::assertEquals([['label' => 'Подробнее', 'href' => '/news']], $savedRow['buttons']);
        self::assertSame(1, (int) $savedRow['visible'], 'Правка полей не меняет видимость сама');

        // Секция по несуществующему id — человеческий отказ, а не молчаливая пустая правка.
        $vanished = updateSection('net-takoj', []);

        self::assertFalse($vanished['ok']);
        self::assertSame('Секция исчезла — обновите список.', $vanished['error']);

        $hidden = sectionToggle($first['id'], false);

        self::assertTrue($hidden['ok'], $hidden['error']);
        self::assertSame(0, (int) value('SELECT visible FROM ' . t('page_section') . ' WHERE id = ?', [$first['id']]));

        $gone = sectionToggle('net-takoj', true);

        self::assertFalse($gone['ok']);
        self::assertSame('Секция исчезла — обновите список.', $gone['error']);

        $deleted = sectionDelete($second['id']);

        self::assertTrue($deleted['ok'], $deleted['error']);
        self::assertSame(
            1,
            (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]),
            'Удалённая секция ушла из базы'
        );

        // Удаление страницы уносит её секции и историю адресов — одной транзакцией.
        insert('page_old_slug', ['slug' => 'byvshij', 'page_id' => $pageId, 'created_at' => utcStamp(nowUtc())]);

        $pageGone = deletePage($pageId);

        self::assertTrue($pageGone['ok'], $pageGone['error']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('page') . ' WHERE id = ?', [$pageId]));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('page_old_slug') . ' WHERE page_id = ?', [$pageId]));

        $missing = deletePage('net-takoj');

        self::assertFalse($missing['ok']);
        self::assertSame('Страница исчезла — обновите список.', $missing['error']);
    }

    /**
     * Страница вставляется строкой: тест обязан класть ровно то состояние, которое проверяет,
     * а не то, что собрал бы createPage().
     *
     * @param array<string, mixed> $overrides
     */
    private static function makePage(array $overrides = []): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('page', array_merge([
            'id' => $id,
            'slug' => (string) $overrides['slug'],
            'title' => 'Страница',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => 0,
            'created_at' => $moment,
            'updated_at' => $moment,
        ], $overrides));

        return $id;
    }

    /**
     * Секция строки в базу: sort — явный, sec_key по умолчанию уникален в пределах страницы.
     *
     * @param array<string, mixed> $overrides
     */
    private static function makeSection(string $pageId, array $overrides = [], int $sort = 0): string
    {
        $id = newId();

        insert('page_section', array_merge([
            'id' => $id,
            'page_id' => $pageId,
            'sec_key' => 'sec-' . $sort . '-' . bin2hex(random_bytes(4)),
            'kind' => 'prose',
            'layout' => '',
            'anchor' => '',
            'badge' => '',
            'title' => 'Секция',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => '[]',
            'items' => '[]',
            'settings' => '{}',
            'sort' => $sort,
            'visible' => 1,
            'updated_at' => utcStamp(nowUtc()),
        ], $overrides));

        return $id;
    }
}
