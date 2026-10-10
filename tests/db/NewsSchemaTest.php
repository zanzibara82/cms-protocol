<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PDOException;
use PHPUnit\Framework\TestCase;

final class NewsSchemaTest extends TestCase
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

    public function testВсеПятьТаблицРазделаСозданы(): void
    {
        self::assertSame(self::tables(), $this->existingTables());
    }

    public function testЖурналПомнитОбаРелизаВПорядкеОчереди(): void
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['name'],
            all('SELECT name FROM ' . t('app_migration') . " WHERE app = 'news' ORDER BY applied_at, name")
        );

        self::assertSame(['20261006_0001_catalog.sql', '20261006_0002_items.sql'], $names);
    }

    // MySQL фиксирует DDL сразу, а журнал установщика пишется после каждого файла: если релиз
    // оборвётся на полпути, следующий прогон повторит уже применённые операторы по живым таблицам.
    // Поэтому каждый оператор всех найденных релизов пропускается второй раз по тому же соединению:
    // отказа быть не должно, и число строк в таблицах раздела обязано остаться прежним. Точный
    // состав релизов и порядок очереди закреплены за testЖурналПомнитОбаРелизаВПорядкеОчереди.
    public function testПовторныйПрогонОбоихРелизовНеОтказывает(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/apps/news/sql/*.sql') ?: [];
        sort($files);
        self::assertNotEmpty($files);

        $before = self::rowCounts();

        foreach ($files as $file) {
            foreach (splitSql((string) file_get_contents($file)) as $statement) {
                run($statement);
            }
        }

        self::assertSame($before, self::rowCounts());
    }

    public function testРазделЧислитсяУстановленнымИГотовымКРаботе(): void
    {
        $installation = appInstallation('news');

        self::assertIsArray($installation);
        self::assertSame('1.0.0', (string) $installation['version']);
        self::assertSame(1, (int) $installation['enabled']);
        self::assertSame('ready', appState('news')['state']);
    }

    public function testНеприменённыхРелизовНеОсталось(): void
    {
        self::assertTrue(planApp('news')->isEmpty());
    }

    public function testАдресМатериалаУникален(): void
    {
        $category = self::makeCategory('Рубрика для адреса');

        self::makeItem($category, 'odinnakovyj-adres');

        $this->expectException(PDOException::class);
        self::makeItem($category, 'odinnakovyj-adres');
    }

    public function testНазваниеРубрикиУникально(): void
    {
        self::makeCategory('Одинаковое название');

        $this->expectException(PDOException::class);
        self::makeCategory('Одинаковое название');
    }

    public function testРубрикуМатериаловНеУдалить(): void
    {
        $category = self::makeCategory('Рубрика с материалом');
        self::makeItem($category, 'material-v-rubrike');

        $this->expectException(PDOException::class);
        run('DELETE FROM ' . t('news_category') . ' WHERE id = ?', [$category]);
    }

    public function testУдалениеМатериалаЧиститСвязкиИСтарыеАдреса(): void
    {
        $category = self::makeCategory('Рубрика для каскада');
        $item = self::makeItem($category, 'kasladnyj-material');
        $tag = newId();

        run('INSERT INTO ' . t('news_tag') . ' (id, slug, label) VALUES (?, ?, ?)', [$tag, 'vypusknik', 'Выпускник']);
        run('INSERT INTO ' . t('news_item_tag') . ' (item_id, tag_id) VALUES (?, ?)', [$item, $tag]);
        run(
            'INSERT INTO ' . t('news_old_slug') . ' (slug, item_id, created_at) VALUES (?, ?, ?)',
            ['prezhnij-adres', $item, utcStamp(nowUtc())]
        );

        run('DELETE FROM ' . t('news_item') . ' WHERE id = ?', [$item]);

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_item_tag') . ' WHERE item_id = ?', [$item]));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_old_slug') . ' WHERE item_id = ?', [$item]));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('news_tag') . ' WHERE id = ?', [$tag]));
    }

    public function testТелоМатериалаХранитсяКакJsonИЧитаетсяОбратно(): void
    {
        $item = self::makeItem(self::makeCategory('Рубрика для тела'), 'material-s-telom');
        $paragraphs = [['title' => 'Вступление', 'text' => 'Первый абзац **важное**']];

        run(
            'UPDATE ' . t('news_item') . ' SET body = ? WHERE id = ?',
            [(string) json_encode($paragraphs, JSON_UNESCAPED_UNICODE), $item]
        );

        $stored = value('SELECT body FROM ' . t('news_item') . ' WHERE id = ?', [$item]);
        $decoded = json_decode((string) $stored, true);

        // Колонка body — JSON, а MySQL нормализует его при записи и сортирует ключи внутри
        // объектов. Поэтому порядок ключей обратно не читается: сверяем значения по ключам,
        // а не массив целиком через assertSame.
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('Вступление', $decoded[0]['title']);
        self::assertSame('Первый абзац **важное**', $decoded[0]['text']);
    }

    public function testНовыйМатериалЭтоЧерновикБезСрокаИБезЗакрепления(): void
    {
        $item = self::makeItem(self::makeCategory('Рубрика для умолчаний'), 'material-s-umolchaniyami');
        $row = one('SELECT status, published_at, pinned, noindex, lead_text FROM ' . t('news_item') . ' WHERE id = ?', [$item]);

        self::assertIsArray($row);
        self::assertSame(NEWS_STATUS['DRAFT'], (string) $row['status']);
        self::assertNull($row['published_at']);
        self::assertSame(0, (int) $row['pinned']);
        self::assertSame(0, (int) $row['noindex']);
        self::assertNull($row['lead_text']);
    }

    public function testТаблицыРазделаНаИннодбИВЮникоде(): void
    {
        $tables = self::tables();
        $rows = all(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (' . inPlaceholders(count($tables)) . ')',
            array_merge([(string) configValue('DB_NAME')], $tables)
        );

        self::assertCount(count($tables), $rows);

        foreach ($rows as $row) {
            self::assertSame('InnoDB', (string) $row['ENGINE']);
            self::assertSame('utf8mb4_unicode_ci', (string) $row['TABLE_COLLATION']);
        }
    }

    public function testИндексыЛентыНаМесте(): void
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['INDEX_NAME'],
            all(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [(string) configValue('DB_NAME'), t('news_item')]
            )
        );

        self::assertContains('news_item_status_published', $names);
        self::assertContains('news_item_category_status', $names);
        self::assertContains('news_item_pinned', $names);
        self::assertContains('news_item_slug', $names);
    }

    /** @return list<string> */
    private static function tables(): array
    {
        return [t('news_category'), t('news_item'), t('news_item_tag'), t('news_old_slug'), t('news_tag')];
    }

    /** @return array<string, int> */
    private static function rowCounts(): array
    {
        $counts = [];

        foreach (self::tables() as $table) {
            $counts[$table] = (int) value('SELECT COUNT(*) FROM ' . $table);
        }

        return $counts;
    }

    /** @return list<string> */
    private function existingTables(): array
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['TABLE_NAME'],
            all(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?',
                [(string) configValue('DB_NAME'), t('news') . '_%']
            )
        );

        sort($names);

        return $names;
    }

    private static function dropNewsTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function makeCategory(string $title): string
    {
        $id = newId();

        // Хвост id, а не начало: первые 10 символов — метка времени, и у всех идентификаторов
        // одной секунды начало одинаковое, а рубрика обязана иметь уникальный адрес.
        run(
            'INSERT INTO ' . t('news_category')
                . ' (id, slug, title, order_index, visible, created_at, updated_at)'
                . ' VALUES (?, ?, ?, 0, 1, ?, ?)',
            [$id, 'rubrika-' . substr($id, -8), $title, utcStamp(nowUtc()), utcStamp(nowUtc())]
        );

        return $id;
    }

    private static function makeItem(string $categoryId, string $slug): string
    {
        $id = newId();

        run(
            'INSERT INTO ' . t('news_item')
                . ' (id, slug, title, category_id, status, created_at, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $slug, 'Материал ' . $slug, $categoryId, NEWS_STATUS['DRAFT'], utcStamp(nowUtc()), utcStamp(nowUtc())]
        );

        return $id;
    }
}
