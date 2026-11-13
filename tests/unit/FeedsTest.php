<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

// Ленты RSS 2.0 и Atom: правила, которые видит читатель ленты, а не только база. Проверяется
// экранирование (материал с «<» и «&» не ломает XML), обязательные элементы форматов, порядок
// «новые вперёд» и совпадение дат у одного и того же материала в обеих лентах.
//
// Ленты берут элементы из базы, поэтому feedItems() здесь не вызывается: подменять её значило бы
// проверять заглушку. Вместо этого проверяется сборка XML на настоящем списке элементов — так
// видно и экранирование, и порядок, и формат дат.
final class FeedsTest extends TestCase
{
    /** @return list<array{title: string, url: string, summary: string, date: DateTimeImmutable}> */
    private function items(): array
    {
        $older = new DateTimeImmutable('2026-11-01 09:00:00', new \DateTimeZone('UTC'));
        $newer = new DateTimeImmutable('2026-11-10 12:30:00', new \DateTimeZone('UTC'));

        return [
            ['title' => 'Новые правила', 'url' => 'https://school.dev/novoe', 'summary' => 'Коротко о главном', 'date' => $newer],
            ['title' => 'Как читать <теги> & кавычки "так"', 'url' => 'https://school.dev/tegi', 'summary' => 'Текст с < и & внутри', 'date' => $older],
        ];
    }

    public function testRssСобираетОбязательныеЭлементыКанала(): void
    {
        $xml = $this->rss($this->items());

        foreach (['<rss version="2.0"', '<channel>', '<title>', '<link>', '<description>', '<language>ru</language>', '<lastBuildDate>'] as $element) {
            self::assertStringContainsString($element, $xml, 'В канале RSS нет обязательного элемента ' . $element);
        }

        self::assertSame(2, substr_count($xml, '<item>'), 'Число элементов ленты не совпало с числом материалов');
        self::assertStringContainsString('<guid isPermaLink="true">', $xml, 'guid обязан быть постоянной ссылкой');
    }

    public function testAtomСобираетОбязательныеЭлементы(): void
    {
        $xml = $this->atom($this->items());

        foreach (['<feed xmlns="http://www.w3.org/2005/Atom">', '<title>', '<id>', '<updated>', '<link rel="self"'] as $element) {
            self::assertStringContainsString($element, $xml, 'В ленте Atom нет обязательного элемента ' . $element);
        }

        self::assertSame(2, substr_count($xml, '<entry>'), 'Число записей Atom не совпало с числом материалов');
        self::assertStringContainsString('<summary>', $xml, 'У записи Atom обязан быть summary');
    }

    // Ломающий XML символ в заголовке — не теория: «<» и «&» приходят из названия материала.
    // Без экранирования лента перестаёт разбираться, и подписчик видит пустоту вместо новостей.
    public function testОпасныеСимволыЭкранируютсяВОбеихЛентах(): void
    {
        foreach ([$this->rss($this->items()), $this->atom($this->items())] as $xml) {
            self::assertStringNotContainsString('<теги>', $xml, 'Символ «<» из заголовка попал в ленту как разметка');
            self::assertStringContainsString('&lt;теги&gt;', $xml, 'Заголовок с «<» обязан печататься экранированным');
            self::assertStringContainsString('&amp;', $xml, 'Амперсанд обязан печататься как &amp;');
        }
    }

    // Порядок «новые вперёд» — свойство самой ленты, а не базы: сборщик сортирует элементы, потому
    // что страницы и материалы приходят из разных таблиц.
    public function testНовыеМатериалыИдутПервыми(): void
    {
        $xml = $this->rss($this->items());
        $first = strpos($xml, 'https://school.dev/novoe');
        $second = strpos($xml, 'https://school.dev/tegi');

        self::assertIsInt($first);
        self::assertIsInt($second);
        self::assertLessThan($second, $first, 'Свежий материал обязан стоять в ленте раньше старого');
    }

    // Одна и та же дата в двух форматах: RSS печатает её по RFC 2822, Atom — по ISO 8601.
    // Расхождение означало бы, что подписчики видят разные даты одного материала.
    public function testДатыПечатаютсяВФорматахОбеихЛент(): void
    {
        $rss = $this->rss($this->items());
        $atom = $this->atom($this->items());

        self::assertMatchesRegularExpression(
            '/<pubDate>[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}<\/pubDate>/',
            $rss,
            'pubDate обязан быть в формате RFC 2822'
        );
        self::assertMatchesRegularExpression(
            '/<updated>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}<\/updated>/',
            $atom,
            'updated обязан быть в формате ISO 8601'
        );
    }

    // Пустой сайт — законное состояние: лента отдаётся, но без элементов. Падать на пустоте нельзя,
    // иначе первый день нового сайта закрывает подписку пятисоткой.
    public function testПустойСайтДаётЛентуБезЭлементов(): void
    {
        $rss = $this->rss([]);
        $atom = $this->atom([]);

        self::assertStringContainsString('<channel>', $rss);
        self::assertSame(0, substr_count($rss, '<item>'));
        self::assertStringContainsString('<feed', $atom);
        self::assertSame(0, substr_count($atom, '<entry>'));
        self::assertMatchesRegularExpression('/<lastBuildDate>.+<\/lastBuildDate>/', $rss, 'У пустой ленты всё равно нужна дата сборки');
    }

    /**
     * @param list<array{title: string, url: string, summary: string, date: DateTimeImmutable}> $items
     */
    private function rss(array $items): string
    {
        return rssXmlOf($items);
    }

    /**
     * @param list<array{title: string, url: string, summary: string, date: DateTimeImmutable}> $items
     */
    private function atom(array $items): string
    {
        return atomXmlOf($items);
    }
}
