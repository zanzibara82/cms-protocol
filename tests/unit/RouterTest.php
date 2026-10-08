<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/router.php';

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        routesReset();
    }

    public function testТочныйПутьСовпадает(): void
    {
        addRoute('GET', '/news', 'newsFeed');

        $match = matchRoute('GET', '/news');

        self::assertNotNull($match);
        self::assertSame('newsFeed', $match['handler']);
        self::assertSame([], $match['params']);
    }

    public function testПараметрИзвлекаетсяИзСегмента(): void
    {
        addRoute('GET', '/news/{slug}', 'newsSingle');

        $match = matchRoute('GET', '/news/kak-my-uchim');

        self::assertSame('kak-my-uchim', $match['params']['slug']);
    }

    public function testПараметрНеЗахватываетСледующийСегмент(): void
    {
        addRoute('GET', '/news/{slug}', 'newsSingle');

        self::assertNull(matchRoute('GET', '/news/rubrika/statya'));
    }

    public function testСлужебныйСегментИдетРаньшеМатериала(): void
    {
        addRoute('GET', '/news/{slug}', 'newsSingle');
        addRoute('GET', '/news/c/{slug}', 'newsCategory');
        addRoute('GET', '/news/admin/items', 'newsAdminItems');

        self::assertSame('newsCategory', matchRoute('GET', '/news/c/sobytiya')['handler']);
        self::assertSame('newsAdminItems', matchRoute('GET', '/news/admin/items')['handler']);
        self::assertSame('newsSingle', matchRoute('GET', '/news/sobytiya')['handler']);
    }

    public function testМетодУчаствуетВСопоставлении(): void
    {
        addRoute('POST', '/news/admin/items', 'newsSave');

        self::assertNull(matchRoute('GET', '/news/admin/items'));
    }

    public function testСлэшВКонцеНеМешает(): void
    {
        addRoute('GET', '/news', 'newsFeed');

        self::assertSame('newsFeed', matchRoute('GET', '/news/')['handler']);
    }

    public function testНесовпадениеДаётПусто(): void
    {
        addRoute('GET', '/news', 'newsFeed');

        self::assertNull(matchRoute('GET', '/magazin'));
    }

    public function testМетаданныеМаршрутаДоходятДоГейта(): void
    {
        addRoute('GET', '/news/admin', 'newsAdmin', ['app' => 'news', 'private' => true]);

        $match = matchRoute('GET', '/news/admin');

        self::assertTrue($match['meta']['private']);
        self::assertSame('news', $match['meta']['app']);
    }

    public function testСтрокаЗапросаНеПопадаетВПуть(): void
    {
        addRoute('GET', '/news', 'newsFeed');

        self::assertSame('newsFeed', matchRoute('GET', '/news?page=2')['handler']);
    }
}
