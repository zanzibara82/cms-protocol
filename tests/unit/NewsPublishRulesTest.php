<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

// Правила редактора, которым база не нужна: разбор адресной строки списка, условие вкладки,
// срок публикации, текст сообщения и строка материала. Всё, что читает и пишет tbl_news_item,
// проверяет NewsItemsTest на настоящей базе.
final class NewsPublishRulesTest extends TestCase
{
    private const NOW = '2026-10-07 09:00:00';

    public function testНезнакомаяВкладкаСтановитсяОбщей(): void
    {
        $cases = [
            'пусто' => '',
            'опечатка' => 'published',
            'чужой текст' => '<script>',
            'регистр' => 'draft',
        ];

        foreach ($cases as $label => $value) {
            self::assertSame('all', parseAdminFilters(['status' => $value])['status'], $label);
        }

        foreach (NEWS_FILTERS as $filter) {
            self::assertSame($filter, parseAdminFilters(['status' => $filter])['status'], $filter);
        }
    }

    public function testУсловияСпискаОбрезаютсяПоПределам(): void
    {
        $filters = parseAdminFilters([
            'cat' => str_repeat('a', 40),
            'q' => str_repeat('б', SEARCH_MAX_LENGTH + 30),
            'page' => '3',
        ]);

        self::assertSame(str_repeat('a', NEWS_ID_MAX), $filters['categoryId']);
        self::assertSame(SEARCH_MAX_LENGTH, mb_strlen($filters['query']));
        self::assertSame(3, $filters['page']);
    }

    public function testПределИдентификатораРавенЕгоДлине(): void
    {
        // Если newId() когда-нибудь изменится, фильтр рубрики начнёт обрезать идентификатор:
        // проверка ловит это здесь, а не пустым списком в админке.
        self::assertSame(mb_strlen(newId()), NEWS_ID_MAX);
    }

    public function testНомерСтраницыЧитаетсяТолькоЦелымБольшеЕдиницы(): void
    {
        $cases = [['', 1], ['0', 1], ['1', 1], ['-3', 1], ['abc', 1], ['2', 2], ['17', 17], [' 4 ', 4], ['2.7', 1]];

        foreach ($cases as $case) {
            $raw = (string) $case[0];
            self::assertSame((int) $case[1], parseAdminFilters(['page' => $raw])['page'], sprintf('«%s»', $raw));
        }
    }

    public function testСсылкиСпискаНесутТекущиеУсловия(): void
    {
        self::assertSame(NEWS_ADMIN_ITEMS_PATH, adminItemsHref([]));
        self::assertSame(NEWS_ADMIN_ITEMS_PATH, adminItemsHref(['status' => 'all', 'page' => 1]));
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT', adminItemsHref(['status' => 'DRAFT']));
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '?page=5', adminItemsHref(['page' => 5]));
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?status=scheduled&cat=id-1&q=%D0%BA%D0%BE%D1%82&page=2',
            adminItemsHref(['status' => 'scheduled', 'categoryId' => 'id-1', 'query' => 'кот', 'page' => 2])
        );
    }

    public function testУсловиеВкладкиЖдутСрокаОтличаетПубликациюПоСроку(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $scheduled = adminWhere(parseAdminFilters(['status' => 'scheduled']), $now);
        self::assertStringContainsString("status = '" . NEWS_STATUS['PUBLISHED'] . "'", $scheduled['where']);
        self::assertStringContainsString('published_at IS NULL OR published_at > ?', $scheduled['where']);
        self::assertSame([utcStamp($now)], $scheduled['args']);

        $opened = adminWhere(parseAdminFilters(['status' => 'PUBLISHED']), $now);
        self::assertSame(visibleWhere(), $opened['where'], 'вкладка «Опубликованные» читает то же условие, что и лента');
        self::assertSame(visibleArgs($now), $opened['args']);

        $draft = adminWhere(parseAdminFilters(['status' => 'DRAFT']), $now);
        self::assertSame("status = '" . NEWS_STATUS['DRAFT'] . "'", $draft['where']);
        self::assertSame([], $draft['args']);

        $all = adminWhere(parseAdminFilters([]), $now);
        self::assertSame('1', $all['where'], 'у общей вкладки условия нет');
        self::assertSame([], $all['args']);
    }

    public function testПоискСпискаЭкранируетСпецсимволыLike(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $escaped = adminWhere(parseAdminFilters(['q' => '100%_']), $now);
        self::assertSame(['%100\\%\\_%', '%100\\%\\_%', '%100\\%\\_%'], $escaped['args']);

        $scope = adminWhere(parseAdminFilters(['cat' => 'id-1', 'q' => 'вечер']), $now);
        // Единица появляется только когда условия нет вовсе (общая вкладка): здесь они есть.
        self::assertSame(
            'i.category_id = ? AND (i.title LIKE ? OR i.lead_text LIKE ? OR i.slug LIKE ?)',
            $scope['where']
        );
        self::assertSame(['id-1', '%вечер%', '%вечер%', '%вечер%'], $scope['args']);
    }

    public function testСрокПубликацииПодставляетсяПриВыпуске(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $later = new DateTimeImmutable('2026-10-08 09:00:00');

        self::assertSame($now, publishDateOf(NEWS_STATUS['PUBLISHED'], null, $now), 'выпуск без срока — сейчас');
        self::assertSame($later, publishDateOf(NEWS_STATUS['PUBLISHED'], $later, $now));
        self::assertNull(publishDateOf(NEWS_STATUS['DRAFT'], null, $now));
        self::assertSame($later, publishDateOf(NEWS_STATUS['DRAFT'], $later, $now), 'снятие с сайта срок сохраняет');
    }

    public function testСообщениеПослеСохраненияЗависитОтСтатусаИСрока(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $later = new DateTimeImmutable('2026-10-07 18:00:00');

        /** @var list<array{0: string, 1: DateTimeImmutable|null, 2: bool, 3: string}> $cases */
        $cases = [
            [NEWS_STATUS['DRAFT'], null, true, 'created'],
            [NEWS_STATUS['DRAFT'], $later, true, 'created'],
            [NEWS_STATUS['DRAFT'], null, false, 'unpublished'],
            [NEWS_STATUS['PUBLISHED'], $later, false, 'scheduled'],
            [NEWS_STATUS['PUBLISHED'], $now, false, 'published'],
            [NEWS_STATUS['PUBLISHED'], null, false, 'published'],
        ];

        foreach ($cases as $case) {
            self::assertSame(
                $case[3],
                publishNotice($case[0], $case[1], $now, $case[2]),
                $case[0] . ' / ' . ($case[2] ? 'создание' : 'правка')
            );
        }
    }

    public function testКаждыйКлючСообщенияЕстьВСловаре(): void
    {
        $keys = ['created', 'published', 'scheduled', 'updated', 'unpublished', 'pinned', 'unpinned', 'unchanged', 'slugChanged'];

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, NEWS_NOTICE_TEXT, $key);
            self::assertNotSame('', NEWS_NOTICE_TEXT[$key], $key);
        }
    }

    public function testПустоеПолеСтановитсяНулевымЗначением(): void
    {
        self::assertNull(orNull(''));
        self::assertSame('Описание', orNull('Описание'));
    }

    public function testНаПечатьНужныАбзацы(): void
    {
        self::assertTrue(hasParagraphs([['title' => '', 'text' => 'Текст']]));
        self::assertTrue(hasParagraphs('[{"title":"","text":"Текст"}]'), 'колонка базы приходит строкой JSON');
        self::assertFalse(hasParagraphs([]));
        self::assertFalse(hasParagraphs(''));
        self::assertFalse(hasParagraphs('{не json'));
        self::assertFalse(hasParagraphs([['title' => '', 'text' => '   ']]), 'абзац из пробелов текстом не считается');

        self::assertSame('', publishTextError(NEWS_STATUS['DRAFT'], []));
        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, publishTextError(NEWS_STATUS['PUBLISHED'], []));
        self::assertSame('', publishTextError(NEWS_STATUS['PUBLISHED'], [['title' => '', 'text' => 'Текст']]));
    }

    public function testСтрокаМатериалаСобираетсяИзПроверенныхПолей(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $editor = ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];

        $input = [
            'title' => 'Вечер встречи',
            'lead' => '',
            'cover' => null,
            'categoryId' => 'kategoriya-1',
            'body' => [['title' => 'Заголовок', 'text' => 'Текст']],
            'status' => NEWS_STATUS['DRAFT'],
            'pinned' => true,
            'seoTitle' => '',
            'seoDescription' => 'Описание',
            'ogImage' => '/uploads/images/og.jpg',
            'noindex' => false,
        ];

        $draft = dataOf($input, null, $editor, false, $now);

        self::assertNull($draft['lead_text'], 'пустой лид — NULL, а не пустая строка');
        self::assertNull($draft['cover']);
        self::assertNull($draft['seo_title']);
        self::assertNull($draft['published_at']);
        self::assertSame(1, $draft['pinned']);
        self::assertSame(0, $draft['noindex']);
        self::assertSame('Описание', $draft['seo_description']);
        self::assertSame('/uploads/images/og.jpg', $draft['og_image']);
        self::assertSame(utcStamp($now), $draft['updated_at']);
        self::assertArrayNotHasKey('author_id', $draft, 'черновик снимок автора не ставит');
        self::assertArrayNotHasKey('author_email', $draft);
        self::assertSame(
            [['title' => 'Заголовок', 'text' => 'Текст']],
            json_decode((string) $draft['body'], true, 3, JSON_THROW_ON_ERROR)
        );

        $published = dataOf($input, $now, $editor, true, $now);

        self::assertSame('redaktor-1', $published['author_id']);
        self::assertSame('redaktor@shkola.ru', $published['author_email']);
        self::assertSame(utcStamp($now), $published['published_at']);
    }

    public function testСтрокаСпискаЧитаетСловарьСтатусов(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $row = [
            'id' => 'id-1',
            'slug' => 'vecher',
            'title' => 'Вечер встречи',
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp($now->sub(new DateInterval('PT1H'))),
            'pinned' => 1,
            'updated_at' => utcStamp($now),
            'author_email' => null,
            'category_title' => 'Жизнь школы',
            'category_visible' => 0,
        ];

        $item = adminItemRow($row, ['Выпускник'], $now);

        self::assertSame('опубликована', $item['statusLabel']);
        self::assertTrue($item['open']);
        self::assertFalse($item['scheduled']);
        self::assertTrue($item['pinned']);
        self::assertSame('Жизнь школы', $item['categoryTitle']);
        self::assertFalse($item['categoryVisible'], 'скрытая рубрика видна в списке, но помечена');
        self::assertSame('/news/vecher', $item['href']);
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '/id-1', $item['editHref']);
        self::assertSame(['Выпускник'], $item['tags']);
        self::assertSame('', $item['authorEmail']);
        self::assertNotSame('—', $item['published']);

        $waiting = adminItemRow(
            array_merge($row, ['published_at' => utcStamp($now->add(new DateInterval('PT1H')))]),
            [],
            $now
        );

        self::assertFalse($waiting['open']);
        self::assertTrue($waiting['scheduled']);

        $draft = adminItemRow(array_merge($row, ['status' => NEWS_STATUS['DRAFT'], 'published_at' => null]), [], $now);

        self::assertSame('черновик', $draft['statusLabel']);
        self::assertFalse($draft['open']);
        self::assertFalse($draft['scheduled']);
        self::assertSame('—', $draft['published'], 'у черновика срока нет');
    }

    public function testИмяКолонкиВОбновленииПроверяется(): void
    {
        self::assertSame(['title = ?', 'pinned = ?'], updateSet(['title' => 'Вечер', 'pinned' => 1]));

        $this->expectException(InvalidArgumentException::class);
        updateSet(['title; DROP TABLE tbl_user' => 'x']);
    }
}
