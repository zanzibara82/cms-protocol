<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Core\MigrationPlan;
use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('app_installation');
        TestDb::clearTable('app_migration');
        $this->dropDemoTables();
    }

    protected function tearDown(): void
    {
        $this->dropDemoTables();
    }

    // Таблицы фикстуры создаёт и убирает сам тест: TestDb::reset() раскладывает только sql/ ядра,
    // иначе проверка «кнопка «Установить» действительно создаёт таблицы» (критерий §12.2) ничего не значила бы.
    private function dropDemoTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['demo_note', 'demo_tag'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
        run('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    private function journal(string $app): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            all('SELECT name FROM ' . t('app_migration') . ' WHERE app = ? ORDER BY name', [$app])
        );
    }

    private function tableExists(string $name): bool
    {
        return (int) value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [t($name)]
        ) === 1;
    }

    public function testПланПервойУстановкиСодержитОбаФайлаПоПорядку(): void
    {
        $plan = planApp('demo');

        self::assertSame(['20261006_0001_note.sql', '20261006_0002_tag.sql'], $plan->pendingNames());
        self::assertFalse($plan->isBlocked());
        self::assertFalse($plan->isEmpty());
        self::assertSame('1.1.0', $plan->targetVersion);
        self::assertInstanceOf(MigrationPlan::class, $plan);
    }

    public function testУстановкаСоздаётТаблицыИДвеСтрокиЖурнала(): void
    {
        $result = installApp('demo');

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(['20261006_0001_note.sql', '20261006_0002_tag.sql'], $this->journal('demo'));
        self::assertTrue($this->tableExists('demo_note'));
        self::assertTrue($this->tableExists('demo_tag'));
        self::assertSame('1.1.0', (string) appInstallation('demo')['version']);
    }

    public function testНачальнаяЗакладкаСоздаётОднуСтроку(): void
    {
        installApp('demo');

        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('demo_note')));
    }

    public function testПовторнаяУстановкаОтказываетсяИНеДублируетЖурнал(): void
    {
        installApp('demo');
        $second = installApp('demo');

        self::assertFalse($second['ok']);
        self::assertSame('Раздел уже установлен.', $second['error']);
        self::assertCount(2, $this->journal('demo'));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('demo_note')), 'Закладка не должна дублироваться');
    }

    public function testПослеУстановкиПланПуст(): void
    {
        installApp('demo');

        self::assertTrue(planApp('demo')->isEmpty());
        self::assertSame([], updateApp('demo')['applied']);
    }

    public function testОбновлениеПрименяетТолькоНовыйФайл(): void
    {
        installApp('demo');

        // Воспроизводим «пришла новая сборка»: журнал помнит только первый релиз, версия старая.
        run('DELETE FROM ' . t('app_migration') . ' WHERE app = ? AND name = ?', ['demo', '20261006_0002_tag.sql']);
        run('UPDATE ' . t('app_installation') . ' SET version = ? WHERE id = ?', ['1.0.0', 'demo']);
        run('DROP TABLE IF EXISTS ' . t('demo_tag'));

        self::assertSame(['20261006_0002_tag.sql'], planApp('demo')->pendingNames());

        $result = updateApp('demo');

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(['20261006_0002_tag.sql'], $result['applied']);
        self::assertTrue($this->tableExists('demo_tag'));
        self::assertSame('1.1.0', (string) appInstallation('demo')['version']);
        self::assertCount(2, $this->journal('demo'));
    }

    public function testИзменённыйПрименённыйФайлБлокируетОбновление(): void
    {
        installApp('demo');
        run(
            'UPDATE ' . t('app_migration') . ' SET checksum = ? WHERE app = ? AND name = ?',
            [str_repeat('b', 64), 'demo', '20261006_0001_note.sql']
        );

        $plan = planApp('demo');

        self::assertTrue($plan->isBlocked());
        self::assertSame(['20261006_0001_note.sql'], $plan->changed);
        self::assertSame(
            'Файл миграции 20261006_0001_note.sql изменён после применения. Обновите его новой миграцией.',
            $plan->blockMessage()
        );

        $result = applyApp($plan);
        self::assertFalse($result['ok']);
        self::assertSame([], $result['applied']);
    }

    public function testУстановкаРазделаИзБудущегоОтказываетсяБезТаблиц(): void
    {
        $result = installApp('future');

        self::assertFalse($result['ok']);
        self::assertSame('Нужна версия ядра не ниже 99.0.0, в сборке ' . CORE_VERSION . '.', $result['error']);
        self::assertFalse(isAppInstalled('future'));
        self::assertSame([], $this->journal('future'));
    }

    public function testУстановкаНеизвестногоРазделаОтказывается(): void
    {
        self::assertSame('Раздел «нету» не найден в сборке.', installApp('нету')['error']);
    }

    public function testОбновлениеНеустановленногоРазделаОтказывается(): void
    {
        self::assertSame('Раздел не установлен — обновлять нечего.', updateApp('demo')['error']);
    }

    public function testПустойКаталогSqlДаётПустойПлан(): void
    {
        self::assertTrue(planApp('future')->isEmpty());
        self::assertSame([], sqlFilesOf('future'));
    }

    public function testНеустановленныйРазделДаётПланИзВсехФайлов(): void
    {
        self::assertCount(2, planApp('demo')->pending);
    }

    public function testЯдроУжеПримененоПоэтомуПланПуст(): void
    {
        // Базовую схему ядра раскладывает setup.php, он же пишет строку журнала (шаг 9).
        insert('app_migration', [
            'id' => newId(),
            'app' => 'core',
            'name' => '0001_core_baseline.sql',
            'checksum' => migrationChecksum(dirname(__DIR__, 2) . '/sql/0001_core_baseline.sql'),
            'applied_at' => utcStamp(nowUtc()),
        ]);

        self::assertSame([], coreSqlPending());
        self::assertTrue(planApp('core')->isEmpty());
        self::assertSame(CORE_VERSION, planApp('core')->targetVersion);
    }

    public function testВыключениеИВключениеМеняетФлаг(): void
    {
        installApp('demo');

        self::assertTrue(setAppEnabled('demo', false)['ok']);
        self::assertFalse(isAppActive('demo'));
        self::assertSame('disabled', appState('demo')['state']);

        self::assertTrue(setAppEnabled('demo', true)['ok']);
        self::assertTrue(isAppActive('demo'));
        self::assertSame('ready', appState('demo')['state']);
    }

    public function testВыключениеНеустановленногоРазделаОтказывается(): void
    {
        self::assertSame('Раздел не установлен.', setAppEnabled('demo', false)['error']);
    }
}
