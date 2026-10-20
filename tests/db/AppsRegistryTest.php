<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Closure;
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

    // F9: база новее сборки — это не «готово»: откатывать схему ядро не умеет, и человек
    // обязан видеть отдельное состояние, а не зелёный свет обновлению.
    public function testВерсияВБазеНовееСборкиДаётСостояниеОпережение(): void
    {
        insert('app_installation', ['id' => 'demo', 'enabled' => 1, 'installed_at' => utcStamp(nowUtc()), 'version' => '9.9.9']);

        $state = appState('demo');

        self::assertSame('ahead', $state['state']);
        self::assertSame('9.9.9', $state['dbVersion']);
        self::assertSame('1.1.0', $state['manifestVersion']);
    }

    // Minor №10: словарь меток живёт в ядре (appStateLabels()), а не в подключаемом шаблоне,
    // и латиница в интерфейсе запрещена — каждое состояние, которое возвращает appState(),
    // обязано иметь русскую метку.
    public function testКаждоеСостояниеРазделаИмеетРусскуюМетку(): void
    {
        $states = [appState('нету')['state'], appState('future')['state']];

        $scenarios = [
            ['enabled' => 0, 'version' => '1.1.0'],
            ['enabled' => 1, 'version' => '1.1.0'],
            ['enabled' => 1, 'version' => '1.0.0'],
            ['enabled' => 1, 'version' => '9.9.9'],
        ];

        foreach ($scenarios as $row) {
            TestDb::clearTable('app_installation');
            insert('app_installation', [
                'id' => 'demo',
                'enabled' => $row['enabled'],
                'installed_at' => utcStamp(nowUtc()),
                'version' => $row['version'],
            ]);
            $states[] = appState('demo')['state'];
        }

        sort($states);
        self::assertSame(['ahead', 'disabled', 'incompatible', 'missing', 'outdated', 'ready'], $states);

        $labels = appStateLabels();

        foreach ($states as $state) {
            self::assertArrayHasKey($state, $labels, 'Состояние ' . $state . ' осталось без метки');
            // Метка пишется кириллицей с заглавной буквы: строчная первая буква в классе
            // [а-яё] отвергала бы любую грамотную метку и тест краснел бы изначально.
            self::assertMatchesRegularExpression('/^[А-ЯЁ][а-яё ]*$/u', $labels[$state], 'Метка состояния обязана быть по-русски');
        }
    }

    public function testНесовместимоеЯдроДаётЧеловеческийТекст(): void
    {
        $state = appState('future');

        self::assertSame('incompatible', $state['state']);
        self::assertSame('Нужна версия ядра не ниже 99.0.0, в сборке ' . CORE_VERSION . '.', $state['reason']);
    }

    public function testПриватныеПутиСобираютсяИзВсехМанифестов(): void
    {
        self::assertSame(['/classifieds/admin', '/commerce/admin', '/demo/admin', '/future/admin', '/news/admin'], appPrivatePaths());
    }

    public function testМаршрутыПриложенийРегистрируютсяСМеткойПриложения(): void
    {
        registerAppRoutes();
        $match = matchRoute('GET', '/demo');

        self::assertNotNull($match);
        self::assertSame('demoFeed', $match['handler']);
        self::assertSame('demo', $match['meta']['app']);
    }

    // F5: повторная регистрация без routesReset() идёт по снимку маршрутов. Кастинг обработчика к
    // строке убивал замыкание («Object of class Closure could not be converted to string»), поэтому
    // снимок обязан хранить обработчик как есть и диспатчить замыкание без фатала.
    public function testСнимокСохраняетОбработчикЗамыкание(): void
    {
        routesReset();
        registerAppRoutes();
        registerAppRoutes();

        $match = matchRoute('GET', '/demo/closure');

        self::assertNotNull($match, 'Маршрут-замыкание пропал после повторной регистрации');
        self::assertTrue(is_callable($match['handler']), 'Обработчик-замыкание не пережил снимок');

        $finished = false;
        ob_start();
        try {
            ($match['handler'])([]);
        } catch (\Cms\Core\ResponseFinished) {
            $finished = true;
        }
        $body = (string) ob_get_clean();

        self::assertTrue($finished, 'Замыкание отработало и завершило ответ');
        self::assertStringContainsString('Лента из замыкания', $body, 'Замыкание-обработчик отдал свой ответ');
    }
}
