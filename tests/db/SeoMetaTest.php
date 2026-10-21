<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Метаданные страниц раздела «SEO и статистика»: хранение по адресу, проверка полей,
 * влияние на собранную страницу (фильтр page_meta) и на карту сайта (фильтр sitemap_urls).
 */
final class SeoMetaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropSeoTables();

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        // Подписки раздела живут в boot.php, а hooksReset() в других наборах снимает все
        // подписки процесса. Набор подписывается заново, когда подписок нет: иначе правки
        // страницы молча не применялись бы.
        if (hookCount('page_meta') === 0) {
            addFilter('page_meta', 'seoMetaPageMeta');
            addFilter('sitemap_urls', 'seoMetaSitemapUrls');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropSeoTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        run('DELETE FROM ' . t('seo_meta'));
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
    }

    private static function dropSeoTables(): void
    {
        run('DROP TABLE IF EXISTS ' . t('seo_meta'));
    }

    /**
     * Набор полей формы: значения по умолчанию — валидные, чтобы проверка касалась
     * только того поля, которое сломалось.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function fields(array $overrides = []): array
    {
        return array_merge([
            'path' => '/news',
            'title' => 'Заголовок из раздела',
            'description' => 'Описание из раздела.',
            'canonical' => '',
            'robots' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image' => '',
            'noindex' => '',
            'in_sitemap' => '',
        ], $overrides);
    }

    /**
     * Собранная страница глазами ядра: фильтр page_meta получает ровно то, что передаёт
     * render(), и отдаёт то, что уйдёт в тему.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private static function pageMeta(array $page = []): array
    {
        return (array) applyFilters('page_meta', array_merge([
            'title' => 'Заголовок страницы',
            'description' => 'Описание страницы',
            'noindex' => false,
            'robots' => '',
            'head' => '',
        ], $page), 'news/item', []);
    }

    public function testМетаданныеЛежатПоАдресуСтроки(): void
    {
        $check = seoMetaValidate(self::fields(['path' => '/news/']));
        self::assertSame([], $check['errors']);
        self::assertSame('/news', $check['values']['path'], 'Адрес нормализуется: хвостовой слэш — тот же адрес');

        $saved = seoMetaSave($check['values']);
        self::assertTrue($saved['ok'], (string) $saved['error']);

        $row = seoMetaRow('/news');
        self::assertIsArray($row, 'Строка метаданных обязана находиться по адресу');
        self::assertSame('Заголовок из раздела', (string) $row['title']);
        self::assertSame('Описание из раздела.', (string) $row['description']);
    }

    public function testВторойРазНаТотЖеАдресНеПроходит(): void
    {
        $check = seoMetaValidate(self::fields());
        seoMetaSave($check['values']);

        $again = seoMetaSave($check['values']);
        self::assertFalse($again['ok'], 'Второй адрес под тот же путь занять нельзя');
        self::assertStringContainsString('уже есть', (string) $again['error']);

        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_meta'), []));
    }

    public function testПроверкаОтвергаетЧужойКаноникИПустойАдрес(): void
    {
        $empty = seoMetaValidate(self::fields(['path' => '  ']));
        self::assertArrayHasKey('path', $empty['errors'], 'Без адреса метаданные положить некуда');

        $foreign = seoMetaValidate(self::fields(['canonical' => 'https://chuzhoy.site/page']));
        self::assertArrayHasKey('canonical', $foreign['errors'], 'Каноник на чужой домен означает, что страница объявляет себя чужой');

        $own = seoMetaValidate(self::fields(['canonical' => '/news']));
        self::assertArrayNotHasKey('canonical', $own['errors'], 'Путь от корня — наш адрес');

        $query = seoMetaValidate(self::fields(['path' => '/news?page=2']));
        self::assertArrayHasKey('path', $query['errors'], 'Адрес с параметрами — другой адрес, метаданные к нему не подходят');

        $robots = seoMetaValidate(self::fields(['robots' => 'index, всё']));
        self::assertArrayHasKey('robots', $robots['errors'], 'Своя строка в директиве молча сделала бы страницу открытой');
    }

    public function testЗаголовокСтраницыБерётсяИзМетаданных(): void
    {
        $check = seoMetaValidate(self::fields());
        seoMetaSave($check['values']);

        $_SERVER['REQUEST_URI'] = '/news';
        $meta = self::pageMeta();

        self::assertSame('Заголовок из раздела', (string) $meta['title']);
        self::assertSame('Описание из раздела.', (string) $meta['description']);
    }

    public function testЧужойАдресСтраницуНеМеняет(): void
    {
        $check = seoMetaValidate(self::fields());
        seoMetaSave($check['values']);

        $_SERVER['REQUEST_URI'] = '/shop';
        $meta = self::pageMeta();

        self::assertSame('Заголовок страницы', (string) $meta['title'], 'Страница без своих метаданных обязана остаться прежней');
        self::assertSame('', (string) $meta['head']);
    }

    public function testЗакрытаяЯдромСтраницаНеОткрываетсяРазделом(): void
    {
        $check = seoMetaValidate(self::fields(['robots' => 'index, follow']));
        seoMetaSave($check['values']);

        $_SERVER['REQUEST_URI'] = '/news';
        $meta = self::pageMeta(['noindex' => true]);

        self::assertTrue((bool) $meta['noindex'], 'Закрытая ядром страница остаётся закрытой');
        self::assertSame('', (string) $meta['robots'], 'Своя директива открыла бы закрытую страницу');
    }

    public function testДирективаNoindexЗакрываетСтраницу(): void
    {
        $check = seoMetaValidate(self::fields(['robots' => 'noindex, follow']));
        seoMetaSave($check['values']);

        $_SERVER['REQUEST_URI'] = '/news';
        $meta = self::pageMeta();

        self::assertTrue((bool) $meta['noindex'], 'Директива с noindex обязана закрыть страницу и для запрета хранения');
        self::assertSame('noindex, follow', (string) $meta['robots']);
    }

    public function testФлагЗакрытияРаботаетБезДирективы(): void
    {
        $check = seoMetaValidate(self::fields(['noindex' => '1']));
        seoMetaSave($check['values']);

        $_SERVER['REQUEST_URI'] = '/news';
        $meta = self::pageMeta();

        self::assertTrue((bool) $meta['noindex']);
        self::assertSame('', (string) $meta['robots'], 'Директивы не было — печатать нечего, метку ставит ядро');
    }

    public function testКаноникИКарточкаСоцсетиПечатаютсяВГолову(): void
    {
        $check = seoMetaValidate(self::fields([
            'canonical' => '/news/glavnaya',
            'og_title' => 'Заголовок карточки',
            'og_image' => '/assets/img/brand/og-default.png',
        ]));
        seoMetaSave($check['values']);

        $_SERVER['REQUEST_URI'] = '/news';
        $meta = self::pageMeta();
        $head = (string) $meta['head'];

        self::assertStringContainsString('<link rel="canonical" href="', $head);
        self::assertStringContainsString('/news/glavnaya', $head);
        self::assertStringContainsString('property="og:title" content="Заголовок карточки"', $head);
        self::assertStringContainsString('name="twitter:card" content="summary_large_image"', $head);
    }

    public function testКартаСайтаПолучаетТолькоОткрытыеАдреса(): void
    {
        $open = seoMetaValidate(self::fields(['path' => '/news', 'in_sitemap' => '1']));
        seoMetaSave($open['values']);

        $closed = seoMetaValidate(self::fields(['path' => '/news/secret', 'noindex' => '1', 'in_sitemap' => '1']));
        seoMetaSave($closed['values']);

        $hidden = seoMetaValidate(self::fields(['path' => '/news/hidden', 'robots' => 'noindex', 'in_sitemap' => '1']));
        seoMetaSave($hidden['values']);

        $urls = sitemapUrls();
        $locations = array_map(static fn (array $url): string => (string) $url['loc'], $urls);

        self::assertContains('/news', $locations, 'Открытый адрес обязан попасть в карту');
        self::assertNotContains('/news/secret', $locations, 'Закрытый флагом адрес в карту не попадает');
        self::assertNotContains('/news/hidden', $locations, 'Закрытый директивой адрес в карту не попадает');
    }

    public function testУдалениеВозвращаетСтраницуКСвоемуЗаголовку(): void
    {
        $check = seoMetaValidate(self::fields());
        seoMetaSave($check['values']);

        $row = seoMetaRow('/news');
        self::assertIsArray($row);

        $deleted = seoMetaDelete((string) $row['id']);
        self::assertTrue($deleted['ok'], (string) $deleted['error']);

        $_SERVER['REQUEST_URI'] = '/news';
        $meta = self::pageMeta();

        self::assertSame('Заголовок страницы', (string) $meta['title'], 'После удаления правок страница возвращается к своему заголовку');
        self::assertNull(seoMetaRow('/news'));
    }

    public function testУдалениеНесуществующейСтрокиОтказывает(): void
    {
        $deleted = seoMetaDelete('net-takoy-stroki');

        self::assertFalse($deleted['ok']);
        self::assertStringContainsString('уже нет', (string) $deleted['error']);
    }
}
