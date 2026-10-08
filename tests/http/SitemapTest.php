<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    public function testКартаСайтаОтдаётсяXml(): void
    {
        $page = Kernel::get('/sitemap.xml');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('application/xml', $page['headers']['content-type']);
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $page['body']);
        self::assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $page['body']);
        self::assertStringContainsString('</urlset>', $page['body']);
    }

    public function testГлавнаяВКартеСайтаЕсть(): void
    {
        $page = Kernel::get('/sitemap.xml');

        self::assertStringContainsString('<loc>http://localhost:8080/</loc>', $page['body']);
    }

    public function testСлужебныхПутейВКартеНет(): void
    {
        $body = Kernel::get('/sitemap.xml')['body'];

        foreach (['/admin', '/login', '/logout', '/setup.php'] as $path) {
            self::assertStringNotContainsString($path, $body);
        }
    }

    public function testЯдроОтдаётТолькоГлавнуюПокаРазделовНет(): void
    {
        // Харнель этого класса поднят без фикстурных каталогов (изоляция окружения
        // подпроцесса, F6), поэтому «разделов нет» — свойство проверки, а не удачное
        // стечение: в ответе ровно один адрес ядра, а сломанный фронт-контроллёр даёт
        // ноль адресов. Прямой вызов sitemapUrls() чувствителен к фикстурам процесса
        // PHPUnit: повесь фикстура фильтр sitemap_urls — и набор покраснеет без всякого
        // дефекта ядра. Раздел «Новости» добавит свои адреса фильтром в задаче 20;
        // фильтр обязан начинаться с isAppActive('news'): таблицы раздела есть не в
        // каждой сборке, а запрос к несуществующей таблице — ошибка.
        $body = Kernel::get('/sitemap.xml')['body'];

        self::assertSame(1, substr_count($body, '<url>'), 'В карте сайта ядра ровно один адрес');
        self::assertSame([['loc' => '/']], sitemapUrls());
    }

    public function testАдресаСтроятсяИзНастроекАНеИзЗаголовка(): void
    {
        self::assertSame('http://localhost:8080/', absoluteUrl('/'));
        self::assertSame('http://localhost:8080/news/privet', absoluteUrl('/news/privet'));
        self::assertSame('http://localhost:8080/news', absoluteUrl('news'));
    }

    public function testАдресИзФильтраПроверяетсяНаОтносительность(): void
    {
        // Фильтр регистрируется в процессе PHPUnit, а не в сервере: проверяем саму функцию.
        addFilter('sitemap_urls', static fn (array $urls): array => array_merge($urls, [
            ['loc' => '/dobavleno'],
            ['loc' => '//chuzhoj.example.com'],
            ['loc' => 'https://chuzhoj.example.com'],
            ['loc' => '/dobavleno'],
            ['loc' => '/admin/sekretno'],
            'не-массив',
        ]), 10);

        $locs = array_map(static fn (array $url): string => (string) $url['loc'], sitemapUrls());
        hooksReset();

        self::assertSame(['/', '/dobavleno'], $locs, 'Чужой домен, дубликат и приватный путь в карту не попадают');
    }

    public function testДатаИзмененияПроходитВКарту(): void
    {
        addFilter('sitemap_urls', static fn (array $urls): array => array_merge($urls, [
            ['loc' => '/s-datoy', 'lastmod' => '2026-10-06T10:00:00+03:00'],
        ]), 10);

        $urls = sitemapUrls();

        self::assertSame('2026-10-06T10:00:00+03:00', $urls[1]['lastmod']);
        self::assertStringContainsString('<lastmod>2026-10-06T10:00:00+03:00</lastmod>', sitemapXml());
    }

    public function testКартаНеПереиспользуетСписокПрошлогоРасчёта(): void
    {
        // Карта сайта считается заново на каждый вызов. Кеш в статике здесь бесполезен: в целевых
        // SAPI (FPM, mod_php, php -S) состояние пользовательского кода не переживает запрос, а
        // внутри одного запроса список обязан обновиться, когда хуки перерегистрированы, — ровно
        // это и проверяет hooksReset() ниже.
        addFilter('sitemap_urls', static fn (array $urls): array => array_merge($urls, [
            ['loc' => '/s-kesch', 'lastmod' => '2026-10-06T10:00:00+03:00'],
        ]), 10);
        self::assertStringContainsString('/s-kesch', sitemapXml());

        hooksReset();

        self::assertStringNotContainsString('/s-kesch', sitemapXml());
    }
}
