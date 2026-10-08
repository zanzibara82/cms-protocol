<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class AppsRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        routesReset();
        TestDb::clearTable('app_installation');
    }

    public function testФикстурыВидныВРеестре(): void
    {
        $ids = array_keys(appManifests());

        self::assertContains('demo', $ids);
        self::assertContains('future', $ids);
    }

    public function testРеестрОтсортированПоАлфавиту(): void
    {
        $ids = array_keys(appManifests());
        $sorted = $ids;
        sort($sorted);

        self::assertSame($sorted, $ids);
    }

    public function testКаталогПриложенияДополняетМанифест(): void
    {
        $manifest = appManifest('demo');

        self::assertNotNull($manifest);
        self::assertStringEndsWith('tests/fixtures/apps/demo', str_replace('\\', '/', (string) $manifest['dir']));
        self::assertNull(appManifest('нету'));
    }

    public function testНеустановленноеПриложениеНеАктивно(): void
    {
        self::assertFalse(isAppInstalled('demo'));
        self::assertFalse(isAppActive('demo'));
        self::assertSame('missing', appState('demo')['state']);
    }

    public function testВыключенноеПриложениеУстановленоНоНеАктивно(): void
    {
        insert('app_installation', ['id' => 'demo', 'enabled' => 0, 'installed_at' => utcStamp(nowUtc()), 'version' => '1.1.0']);

        self::assertTrue(isAppInstalled('demo'));
        self::assertFalse(isAppActive('demo'));
        self::assertSame('disabled', appState('demo')['state']);
    }

    public function testСтараяВерсияВБазеДаётСостояниеТребуетОбновления(): void
    {
        insert('app_installation', ['id' => 'demo', 'enabled' => 1, 'installed_at' => utcStamp(nowUtc()), 'version' => '1.0.0']);

        $state = appState('demo');

        self::assertSame('outdated', $state['state']);
        self::assertSame('1.0.0', $state['dbVersion']);
        self::assertSame('1.1.0', $state['manifestVersion']);
    }

    public function testСвежаяВерсияДаётСостояниеГотово(): void
    {
        insert('app_installation', ['id' => 'demo', 'enabled' => 1, 'installed_at' => utcStamp(nowUtc()), 'version' => '1.1.0']);

        self::assertSame('ready', appState('demo')['state']);
        self::assertSame('', appState('demo')['reason']);
        self::assertTrue(isAppActive('demo'));
    }

    public function testНесовместимоеЯдроДаётЧеловеческийТекст(): void
    {
        $state = appState('future');

        self::assertSame('incompatible', $state['state']);
        self::assertSame('Нужна версия ядра не ниже 99.0.0, в сборке ' . CORE_VERSION . '.', $state['reason']);
    }

    public function testПриватныеПутиСобираютсяИзВсехМанифестов(): void
    {
        self::assertSame(['/demo/admin', '/future/admin'], appPrivatePaths());
    }

    public function testМаршрутыПриложенийРегистрируютсяСМеткойПриложения(): void
    {
        registerAppRoutes();
        $match = matchRoute('GET', '/demo');

        self::assertNotNull($match);
        self::assertSame('demoFeed', $match['handler']);
        self::assertSame('demo', $match['meta']['app']);
    }
}
