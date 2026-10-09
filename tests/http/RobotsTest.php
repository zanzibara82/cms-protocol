<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class RobotsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        Kernel::start(['CMS_EXTRA_APPS_DIR' => dirname(__DIR__, 2) . '/tests/fixtures/apps']);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    public function testRobotsОтдаётсяТекстом(): void
    {
        $page = Kernel::get('/robots.txt');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('text/plain', $page['headers']['content-type']);
        self::assertStringContainsString('User-agent: *', $page['body']);
    }

    public function testПубличныеСтраницыНеПопадаютПодЗапретКэширования(): void
    {
        // Пакет V, находка 3 аудита просмотра: правило «страница с `noindex` не кэшируется» живёт в
        // `render()`, и оно не имеет права задеть публичные страницы — иначе собственный заголовок
        // перекрыл бы кэширование всему сайту. Проверяется ровно это: ни главная страница, ни
        // /robots.txt не получают `no-store`.
        $home = Kernel::get('/');

        self::assertSame(200, $home['status']);
        self::assertNotSame('no-store', $home['headers']['cache-control'] ?? '', 'Главная не должна запрещать кэширование всему сайту');

        $robots = Kernel::get('/robots.txt');

        self::assertSame(200, $robots['status']);
        self::assertSame('public, max-age=3600', $robots['headers']['cache-control'], 'Политика robots.txt остаётся публичной');
    }

    public function testСлужебныеПутиЗакрыты(): void
    {
        $body = Kernel::get('/robots.txt')['body'];

        foreach (['/admin', '/login', '/logout', '/setup.php', '/config.php', '/core/', '/apps/', '/sql/', '/tests/'] as $path) {
            self::assertStringContainsString('Disallow: ' . $path, $body, 'Не закрыт путь ' . $path);
        }
    }

    public function testПриватныеПутиРазделовЗакрыты(): void
    {
        $body = Kernel::get('/robots.txt')['body'];

        self::assertStringContainsString('Disallow: /demo/admin', $body);
        self::assertStringContainsString('Disallow: /future/admin', $body);
    }

    public function testКартаСайтаУказанаАбсолютнымАдресом(): void
    {
        self::assertStringContainsString('Sitemap: http://localhost:8080/sitemap.xml', Kernel::get('/robots.txt')['body']);
    }

    public function testЗадержкаОбходаЗаданаПоУмолчанию(): void
    {
        self::assertContains('Crawl-delay: 1', robotsLines());
        self::assertSame('User-agent: *', robotsLines()[0]);
    }

    public function testФильтрДополняетСписокЗакрытыхПутей(): void
    {
        addFilter('robots_lines', static fn (array $lines): array => array_merge($lines, ['/sluzhebnoe/']), 10);

        self::assertContains('/sluzhebnoe/', robotsDisallowed());
        self::assertStringContainsString('Disallow: /sluzhebnoe/', robotsTxt());
        hooksReset();

        self::assertNotContains('/sluzhebnoe/', robotsDisallowed());
    }
}
