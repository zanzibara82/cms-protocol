<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class NewsRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropNewsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'news'");
    }

    public static function tearDownAfterClass(): void
    {
        self::dropNewsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'news'");
    }

    public function testМанифестРазделаЧитаетсяИзСборки(): void
    {
        $manifest = appManifest('news');

        self::assertIsArray($manifest);
        self::assertSame('Новости', (string) $manifest['title']);
        self::assertSame('1.0.0', (string) $manifest['version']);
        self::assertSame('1.1.0', (string) $manifest['requiresCore']);
        self::assertStringEndsWith('apps/news', str_replace('\\', '/', (string) $manifest['dir']));
        self::assertSame([['href' => '/news/admin', 'label' => 'Новости']], $manifest['adminNav']);
    }

    public function testДоУстановкиРазделОтмеченОтсутствующим(): void
    {
        $state = appState('news');

        self::assertSame('missing', $state['state']);
        self::assertSame('1.0.0', $state['manifestVersion']);
        self::assertSame('', $state['dbVersion']);
        self::assertSame('Раздел не установлен.', $state['reason']);
    }

    public function testВерсияЯдраПодходитРазделу(): void
    {
        $manifest = appManifest('news');
        self::assertIsArray($manifest);

        // Контракт манифеста: с CORE_VERSION сверяется requiresCore из `apps/news/manifest.php`,
        // а не литерал из теста.
        self::assertTrue(
            coreVersionSatisfies((string) $manifest['requiresCore']),
            'Ядру ' . CORE_VERSION . ' не удовлетворяет requiresCore ' . (string) $manifest['requiresCore'] . '.'
        );

        // При текущей версии ядра установка раздела разрешена: appState() прогоняет ровно тот
        // гейт версии, который смотрит installApp(), а состояние «incompatible» означало бы отказ.
        self::assertNotSame('incompatible', appState('news')['state']);
    }

    public function testПланУстановкиСодержитОбаРелиза(): void
    {
        $plan = planApp('news');

        self::assertFalse($plan->isEmpty());
        self::assertFalse($plan->isBlocked());
        self::assertSame(['20261006_0001_catalog.sql', '20261006_0002_items.sql'], $plan->pendingNames());
    }

    public function testПриватныйПутьРазделаОбъявленВМанифесте(): void
    {
        self::assertContains('/news/admin', appPrivatePaths());
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
