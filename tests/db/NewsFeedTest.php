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

    // Запрос поиска объявляет `ESCAPE` явно и передаёт знак снятия параметром. Без объявления
    // снятие обратным слэшем работает, пока в режиме СУБД нет `NO_BACKSLASH_ESCAPES`; при этом
    // режиме слэш перестаёт быть снимающим и поиск по «100%» начинает искать уже не то.
    // Проверка идёт на обоих путях поиска: лента (`findFeed` → `feed.php`) и редакторский список
    // (`adminItemPage` → `items.php`), поэтому снятие `ESCAPE` в любом из них краснит тест.
    public function testПоискПроцентаНеЛомаетсяПриNoBackslashEscapes(): void
    {
        $this->published('garantii-100', ['title' => '100% гарантии']);
        $this->published('sto-procentov', ['title' => '100 процентов']);

        run("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')");

        try {
            self::assertStringContainsString(
                'NO_BACKSLASH_ESCAPES',
                (string) value('SELECT @@sql_mode'),
                'Замер пошёл не в том режиме: без NO_BACKSLASH_ESCAPES проверка ничего не доказывает'
            );

            self::assertSame(
                ['garantii-100'],
                $this->feedSlugs(findFeed(['q' => '100%'])),
                'Лента при NO_BACKSLASH_ESCAPES нашла не тот материал: поиск без явного ESCAPE'
            );
            self::assertSame(
                ['garantii-100'],
                $this->slugs(adminItemPage(['query' => '100%'], nowUtc())['items']),
                'Список редактора при NO_BACKSLASH_ESCAPES нашёл не тот материал: поиск без явного ESCAPE'
            );
        } finally {
            run("SET SESSION sql_mode = REPLACE(REPLACE(@@sql_mode, ',NO_BACKSLASH_ESCAPES', ''), 'NO_BACKSLASH_ESCAPES,', '')");
        }
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
        // Рубрика «Жизнь школы» заранее видима: признак на материале видимой рубрики обязан быть
        // истинным, иначе карточка потеряла бы ссылку на живой раздел.
        self::assertTrue((bool) $item['categoryVisible']);
        self::assertTrue((bool) $item['open']);
        self::assertNotSame('—', (string) $item['date']);
        self::assertSame([['title' => 'Итоги', 'text' => 'Текст про **итоги**.']], $item['paragraphs']);
        self::assertSame(['olimpiada'], array_column((array) $item['tags'], 'slug'));
        self::assertSame(['/news/t/olimpiada'], array_column((array) $item['tags'], 'href'));
    }

    public function testСкрытаяРубрикаНеДаётСсылкуНоМатериалОткрывается(): void
    {
        // Материал лежит в «Жизни школы», а рубрика скрыта после публикации: страница материала
        // продолжает открываться (§8.4), название рубрики остаётся, но признак видимости гаснет —
        // шаблон печатает чип рубрики без ссылки (§8.6).
        $this->published('v-skrytoy-rubrike');
        toggleCategoryVisibility(categoryIdOf('zhizn-shkoly'));

        $item = findPublished('v-skrytoy-rubrike', nowUtc());

        self::assertIsArray($item);
        self::assertSame('Жизнь школы', (string) $item['categoryTitle']);
        self::assertFalse((bool) $item['categoryVisible']);
        self::assertTrue((bool) $item['open']);
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

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private function slugs(array $items): array
    {
        return array_map(static fn (array $item): string => (string) $item['slug'], $items);
    }

    /**
     * @param array{items: list<array<string, mixed>>} $feed
     * @return list<string>
     */
    private function feedSlugs(array $feed): array
    {
        return $this->slugs($feed['items']);
    }
}
