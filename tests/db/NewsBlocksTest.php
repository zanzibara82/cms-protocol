<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Блоки вывода проверяются на живой базе: порядок зоны, предел выборки, правило видимости ленты,
// поведение «цели больше нет» и «раздел выключен» — свойства запросов, а не кода читателя.
final class NewsBlocksTest extends NewsDbTestCase
{
    public function testБлокиЗоныЧитятсяПоПорядкуИБезСкрытых(): void
    {
        self::makeBlock('home', 'list', 'Третий', ['order_index' => 30]);
        self::makeBlock('home', 'list', 'Первый', ['order_index' => 10]);
        self::makeBlock('home', 'list', 'Скрытый', ['order_index' => 20, 'visible' => 0]);
        self::makeBlock('category', 'list', 'Чужая зона', ['order_index' => 5]);

        self::assertSame(['Первый', 'Третий'], $this->titles(newsBlockRows('home')));
    }

    public function testВыборкаБлоковРежетсяПРЕДЕЛОМОМЗоны(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE + 2; $n++) {
            self::makeBlock('home', 'list', sprintf('Блок %02d', $n), ['order_index' => $n]);
        }

        $rows = newsBlockRows('home');

        self::assertCount(NEWS_BLOCK_PER_ZONE, $rows);
        self::assertSame('Блок 06', (string) $rows[NEWS_BLOCK_PER_ZONE - 1]['title']);
    }

    public function testBlockFeedRowsЧититПравилаВидимостиЛенты(): void
    {
        $this->dated('na-sajte', 1);
        $this->dated('chernovik', 2, ['status' => NEWS_STATUS['DRAFT']]);
        $this->dated('zhdyot-sroka', 3, ['published_at' => utcStamp(nowUtc()->add(new DateInterval('PT2H')))]);

        self::assertSame(['na-sajte'], $this->slugs(blockFeedRows([], '', 5)));
    }

    public function testBlockFeedRowsИсключаетМатериалИРежетЧисло(): void
    {
        $this->dated('m-1', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('m-2', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('m-3', 3, ['category_id' => categoryIdOf('metodika')]);

        // Порядок ленты — `published_at DESC`, значит из трёх материалов свежий m-1, затем m-2.
        self::assertSame(['m-2', 'm-3'], $this->slugs(blockFeedRows(['category' => 'metodika'], 'm-1', 2)));
    }

    public function testBlockFeedRowsОбщуюЛентуРежетПРЕДЕЛОМОМСтраницы(): void
    {
        for ($n = 1; $n <= NEWS_PER_PAGE + 2; $n++) {
            $this->dated(sprintf('m-%02d', $n), $n);
        }

        // Предел стоит в `takeLimit()`, а не в проверке формы: строка с завышенным числом,
        // попавшая в базу мимо редактора, не должна вытаскивать больше материалов, чем страница.
        self::assertCount(NEWS_PER_PAGE, blockFeedRows([], '', NEWS_PER_PAGE + 2));
    }

    public function testBlockFeedRowsМеткиСоединяетсяСТаблицейСвязей(): void
    {
        $tagId = resolveTagIds(['Урок чтения'])[0];

        $linked = $this->dated('s-urokom', 1);
        self::linkTag($linked, $tagId);
        $this->dated('bez-uroka', 2);

        self::assertSame(['s-urokom'], $this->slugs(blockFeedRows(['tag' => blockTargetSlug('tag', $tagId)], '', 5)));
    }

    public function testBlockFeedRowsФильтрРубрикиНеТрогаетСоседей(): void
    {
        $this->dated('v-metodike', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('v-zhizni', 2);

        self::assertSame(['v-metodike'], $this->slugs(blockFeedRows(['category' => 'metodika'], '', 5)));
    }

    public function testBlockFeedRowsСкрытойРубрикиДаётПустойСписок(): void
    {
        $this->dated('iz-postupleniya', 1, ['category_id' => categoryIdOf('postuplenie')]);
        $this->dated('v-zhizni', 2);
        run('UPDATE ' . t('news_category') . ' SET visible = 0 WHERE slug = ?', ['postuplenie']);

        self::assertSame([], $this->slugs(blockFeedRows(['category' => 'postuplenie'], '', 5)));
        self::assertSame(['v-zhizni'], $this->slugs(blockFeedRows([], '', 5)));
    }

    public function testNewsRegionHtmlПечатаетЗаголовокИКарточки(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');

        $html = newsRegionHtml('', 'home');

        self::assertStringContainsString('Свежие материалы', $html);
        self::assertStringContainsString('/news/novyy', $html);
    }

    public function testNewsRegionHtmlСохраняетВыводДругихПодписчиков(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');

        $html = newsRegionHtml('чужой вывод', 'home');

        self::assertStringStartsWith('чужой вывод', $html);
        self::assertStringContainsString('Свежие материалы', $html);
    }

    public function testNewsRegionHtmlБезБлоковВозвращаетВход(): void
    {
        $this->dated('novyy', 1);

        self::assertSame('до', newsRegionHtml('до', 'home'));
    }

    public function testNewsRegionHtmlНеПечатаетПустойБлок(): void
    {
        self::makeBlock('home', 'list', 'Свежие материалы');

        self::assertSame('', newsRegionHtml('', 'home'));
    }

    public function testДваБлокаЗоныПечатаютсяВПорядкеВыборки(): void
    {
        $this->dated('odinnadtsatyy', 1);
        self::makeBlock('home', 'list', 'Второй блок', ['order_index' => 20]);
        self::makeBlock('home', 'list', 'Первый блок', ['order_index' => 10]);

        $html = newsRegionHtml('', 'home');

        self::assertLessThan(
            (int) strpos($html, 'Второй блок'),
            (int) strpos($html, 'Первый блок'),
            'заголовки блоков идут в порядке выборки'
        );
    }

    public function testRelatedБерётРубрикуСтраницыИИсключаетЕёМатериал(): void
    {
        $this->dated('sam-material', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('sosed', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('iz-drugoy', 3);
        self::makeBlock('article', 'related', 'Похожие материалы');

        $html = newsRegionHtml('', 'article', ['slug' => 'sam-material', 'category' => 'metodika']);

        self::assertStringContainsString('/news/sosed', $html);
        self::assertStringNotContainsString('/news/sam-material', $html);
        self::assertStringNotContainsString('/news/iz-drugoy', $html);
    }

    public function testRelatedБезРубрикиСтраницыМолчит(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('article', 'related', 'Похожие материалы');

        self::assertSame('', newsRegionHtml('', 'article', []));
    }

    public function testБлокСЦельюКоторойНетНеПечатаетОбщуюЛенту(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Методика', ['target_kind' => 'category', 'target_id' => newId()]);

        self::assertSame('', newsRegionHtml('', 'home'));

        // Тот же исход у пропавшей метки: у цели два вида, и молчать обязан любой из них.
        self::makeBlock('home', 'list', 'Урок чтения', ['target_kind' => 'tag', 'target_id' => newId(), 'order_index' => 20]);

        self::assertSame('', newsRegionHtml('', 'home'));
    }

    public function testБлокМеткиЧитаетТолькоМетку(): void
    {
        $tagId = resolveTagIds(['Урок чтения'])[0];

        $linked = $this->dated('s-urokom', 1);
        self::linkTag($linked, $tagId);
        $this->dated('bez-uroka', 2);

        self::makeBlock('category', 'list', 'Читаем вслух', ['target_kind' => 'tag', 'target_id' => $tagId]);

        $html = newsRegionHtml('', 'category');

        self::assertStringContainsString('/news/s-urokom', $html);
        self::assertStringNotContainsString('/news/bez-uroka', $html);
    }

    public function testБлокПохожихИгнорируетЦельЕслиОнаЗатесалась(): void
    {
        // Редактор такую строку не создаст (правило Р9), но строка могла попасть в базу мимо него:
        // вид `related` определён как рубрика материала, и цель не имеет права менять его смысл.
        $this->dated('sam-material', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('sosed', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('iz-drugoy', 3);
        self::makeBlock('article', 'related', 'Похожие материалы', [
            'target_kind' => 'category',
            'target_id' => categoryIdOf('postuplenie'),
        ]);

        $html = newsRegionHtml('', 'article', ['slug' => 'sam-material', 'category' => 'metodika']);

        self::assertStringContainsString('/news/sosed', $html);
        self::assertStringNotContainsString('/news/iz-drugoy', $html);
    }

    public function testБлокРубрикиЧитаетТолькоСвоюРубрику(): void
    {
        $this->dated('v-metodike', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('v-zhizni', 2);
        self::makeBlock('category', 'list', 'Только методика', [
            'target_kind' => 'category',
            'target_id' => categoryIdOf('metodika'),
        ]);

        $html = newsRegionHtml('', 'category');

        self::assertStringContainsString('/news/v-metodike', $html);
        self::assertStringNotContainsString('/news/v-zhizni', $html);
    }

    public function testNewsRegionHtmlМолчитКогдаРазделВыключен(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');
        run('UPDATE ' . t('app_installation') . " SET enabled = 0 WHERE id = 'news'");

        try {
            self::assertSame('', newsRegionHtml('', 'home'));
        } finally {
            run('UPDATE ' . t('app_installation') . " SET enabled = 1 WHERE id = 'news'");
        }
    }

    /**
     * Опубликованный материал с заданным возрастом: порядок ленты задаётся моментом публикации,
     * поэтому он подставляется в каждой вставке, а не остаётся NULL.
     *
     * @param array<string, mixed> $overrides
     */
    private function dated(string $slug, int $hoursAgo, array $overrides = []): string
    {
        return self::makeItem(categoryIdOf('zhizn-shkoly'), $slug, array_merge([
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT' . $hoursAgo . 'H'))),
        ], $overrides));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['title'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<string>
     */
    private function slugs(array $cards): array
    {
        return array_map(static fn (array $card): string => (string) $card['slug'], $cards);
    }
}
