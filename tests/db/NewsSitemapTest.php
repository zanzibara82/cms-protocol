<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Функции ядра глобальны: `core/*.php` не объявляют пространство имён, поэтому вызовы идут без
// `use function` — импорт в Cms\Core указывал бы на несуществующие функции.

// Карта сайта собирается фильтром, поэтому проверяются обе половины: что раздел умеет назвать
// свои адреса и что ядро их принимает, а не любой список из фильтра.
//
// Подстановка NewsDbTestCase обязательна: рубрики закладки и чистка перед каждой проверкой дают
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
