<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Чистые правила ленты: страницы, адреса, размер выборки и разбор тела. База им не нужна,
// поэтому они живут в наборе unit и проверяются за миллисекунды.
final class NewsFeedRulesTest extends TestCase
{
    public function testЧислоСтраницНикогдаНеМеньшеЕдиницы(): void
    {
        $cases = [
            'пустая лента' => [0, 1],
            'один материал' => [1, 1],
            'ровно страница' => [NEWS_PER_PAGE, 1],
            'страница и один' => [NEWS_PER_PAGE + 1, 2],
            'две страницы' => [NEWS_PER_PAGE * 2, 2],
            'две и хвост' => [NEWS_PER_PAGE * 2 + 5, 3],
        ];

        foreach ($cases as $label => [$total, $expected]) {
            self::assertSame($expected, pageCount((int) $total), (string) $label);
        }
    }

    public function testНомерСтраницыНеВыходитЗаПределыЛенты(): void
    {
        $cases = [
            'ноль' => [0, 5, 1],
            'отрицательный' => [-3, 5, 1],
            'дальше конца' => [99, 5, 5],
            'внутри ленты' => [3, 5, 3],
            'первая' => [1, 5, 1],
        ];

        foreach ($cases as $label => [$page, $pages, $expected]) {
            self::assertSame($expected, clampPage((int) $page, (int) $pages), (string) $label);
        }
    }

    public function testОкноНомеровДержитПервуюИПоследнююСтраницу(): void
    {
        $cases = [
            'одна страница' => [1, 1, [1]],
            'окно короче предела' => [3, 5, [1, 2, 3, 4, 5]],
            'ровно предел' => [4, NEWS_PAGER_WINDOW, [1, 2, 3, 4, 5, 6, 7]],
            'начало длинной ленты' => [1, 20, [1, 2, 3, 4, 5, 6, 20]],
            'середина длинной ленты' => [10, 20, [1, 8, 9, 10, 11, 12, 20]],
            'конец длинной ленты' => [20, 20, [1, 15, 16, 17, 18, 19, 20]],
        ];

        foreach ($cases as $label => [$current, $pages, $expected]) {
            self::assertSame($expected, pageWindow((int) $current, (int) $pages), (string) $label);
        }
    }

    public function testРазмерВыборкиОграниченСтраницейЛенты(): void
    {
        self::assertSame(1, takeLimit(0));
        self::assertSame(1, takeLimit(-5));
        self::assertSame(5, takeLimit(5));
        self::assertSame(NEWS_PER_PAGE, takeLimit(1000));
    }

    public function testАдресРазделаСкладываетсяИзОдногоСегмента(): void
    {
        $cases = [
            'лента' => [[], '/news'],
            'материал' => [['slug' => 'itogi-goda'], '/news/itogi-goda'],
            'рубрика' => [['category' => 'zhizn-shkoly'], '/news/c/zhizn-shkoly'],
            'тег' => [['tag' => 'olimpiada'], '/news/t/olimpiada'],
            'материал важнее рубрики' => [['category' => 'zhizn-shkoly', 'slug' => 'itogi'], '/news/itogi'],
        ];

        foreach ($cases as $label => [$target, $expected]) {
            self::assertSame($expected, newsHref((array) $target), (string) $label);
        }
    }

    public function testАдресСтраницыЛентыДержитПоискИНомерСтраницы(): void
    {
        self::assertSame('/news', feedHref('/news'));
        self::assertSame('/news?q=%D0%BA%D0%BE%D1%82', feedHref('/news', ['q' => 'кот']));
        self::assertSame('/news/c/zhizn-shkoly?page=3', feedHref('/news/c/zhizn-shkoly', ['page' => 3]));
        self::assertSame('/news?q=%D0%BA%D0%BE%D1%82&page=2', feedHref('/news', ['q' => 'кот', 'page' => 2]));

        // Первая страница в адрес не попадает: ?page=1 дублировал бы чистую ссылку ленты,
        // а поисковик увидел бы два одинаковых адреса.
        self::assertSame('/news', feedHref('/news', ['page' => 1]));
        self::assertSame('/news', feedHref('/news', ['q' => '   ']));
    }

    public function testСтрокаПоискаОбрезаетсяДоПредела(): void
    {
        $long = str_repeat('а', SEARCH_MAX_LENGTH + 50);

        self::assertSame(
            '/news?q=' . rawurlencode(str_repeat('а', SEARCH_MAX_LENGTH)),
            feedHref('/news', ['q' => $long])
        );
    }

    public function testТелоМатериалаЧитаетсяИзКолонкиJson(): void
    {
        $paragraphs = paragraphsOf('[{"title":"Итоги","text":"Текст"},{"title":"","text":"Второй"}]');

        self::assertCount(2, $paragraphs);
        self::assertSame('Итоги', $paragraphs[0]['title']);
        self::assertSame('Текст', $paragraphs[0]['text']);
        self::assertSame('Второй', $paragraphs[1]['text']);
    }

    public function testПустоеИПовреждённоеТелоНеЛомаютСтраницу(): void
    {
        // Колонка JSON допускает NULL, а строка в ней может прийти обрезанной: карточка обязана
        // напечататься без абзацев, а не ответить страницей 500.
        self::assertSame([], paragraphsOf(null));
        self::assertSame([], paragraphsOf(''));
        self::assertSame([], paragraphsOf('[{"title":"Ито'));
        self::assertSame([], paragraphsOf('{"title":"Итоги","text":"Текст"}'));
        self::assertCount(1, paragraphsOf([['title' => 'Итоги', 'text' => 'Текст']]));
    }
}
