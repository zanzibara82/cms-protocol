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

    // Закладка блоков из релиза схемы после первой очистки не возвращается: `seedApp()` сеет
    // только рубрики, поэтому задачи 4 и 6 заводят нужные блоки сами.
    protected static function clearNews(): void
    {
        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category', 'news_block'] as $table) {
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

    /**
     * Блок выводится строкой напрямую: тест читателя обязан класть в базу ровно проверяемое
     * состояние — зону, вид, цель, предел и порядок, — а не получать его через правила формы.
     *
     * @param array<string, mixed> $overrides
     */
    protected static function makeBlock(string $zone, string $kind, string $title, array $overrides = []): string
    {
        $id = newId();
        $stamp = utcStamp(nowUtc());

        insert('news_block', array_merge([
            'id' => $id,
            'zone' => $zone,
            'kind' => $kind,
            'title' => $title,
            'target_kind' => null,
            'target_id' => null,
            'take_limit' => 5,
            'visible' => 1,
            'order_index' => 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ], $overrides));

        return $id;
    }

    /**
     * Значение первой колонки первой строки; пустая строка — если запрос ничего не нашёл.
     *
     * @param list<mixed> $args
     */
    protected function column(string $sql, array $args = []): string
    {
        $found = value($sql, $args);

        return $found === null ? '' : (string) $found;
    }

    protected static function dropNewsTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['news_block', 'news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
