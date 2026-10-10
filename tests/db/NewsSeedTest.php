<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class NewsSeedTest extends TestCase
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

    public function testСтартовыеРубрикиЗакладываютсяПоУмолчанию(): void
    {
        // Сортировка по title, а не по order_index: порядок закладки проверяет соседний тест,
        // а здесь важен только состав стартовых рубрик.
        $titles = array_map(
            static fn (array $row): string => (string) $row['title'],
            all('SELECT title FROM ' . t('news_category') . ' ORDER BY title')
        );

        self::assertSame(['Жизнь школы', 'Методика', 'Поступление'], $titles);
    }

    public function testРубрикиВидныИСтоятВЗаданномПорядке(): void
    {
        $rows = all(
            'SELECT slug, order_index, visible FROM ' . t('news_category') . ' ORDER BY order_index'
        );

        self::assertSame(['zhizn-shkoly', 'postuplenie', 'metodika'], array_column($rows, 'slug'));
        self::assertSame([0, 1, 2], array_map(static fn (array $row): int => (int) $row['order_index'], $rows));

        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['visible']);
        }
    }

    public function testПовторнаяЗакладкаНеДублируетРубрики(): void
    {
        $manifest = (array) require dirname(__DIR__, 2) . '/apps/news/manifest.php';
        $before = (int) value('SELECT COUNT(*) FROM ' . t('news_category'));

        seedApp($manifest);
        seedApp($manifest);

        self::assertSame($before, (int) value('SELECT COUNT(*) FROM ' . t('news_category')));
    }

    public function testДобавленнаяВручнуюРубрикаПереживаетЗакладку(): void
    {
        run(
            'INSERT INTO ' . t('news_category')
                . ' (id, slug, title, order_index, visible, created_at, updated_at)'
                . ' VALUES (?, ?, ?, 9, 1, ?, ?)',
            [newId(), 'konkursy', 'Конкурсы', utcStamp(nowUtc()), utcStamp(nowUtc())]
        );

        $manifest = (array) require dirname(__DIR__, 2) . '/apps/news/manifest.php';
        seedApp($manifest);

        $slugs = array_column(
            all('SELECT slug FROM ' . t('news_category') . ' ORDER BY order_index, title'),
            'slug'
        );

        self::assertSame(['zhizn-shkoly', 'postuplenie', 'metodika', 'konkursy'], $slugs);
    }

    private static function dropNewsTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
