<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class AdminAppsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::clearTable('login_attempt');
        TestDb::seedUser('admin@school.dev', 'длинный-тестовый-пароль', 'admin');
        TestDb::seedUser('editor@school.dev', 'длинный-тестовый-пароль', 'editor');

        // Фикстуры подставляются только этому набору: в остальных HTTP-тестах чужие разделы
        // в реестре лишь мешали бы проверкам ленты и входа.
        Kernel::start(['CMS_EXTRA_APPS_DIR' => dirname(__DIR__, 2) . '/tests/fixtures/apps']);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        TestDb::clearTable('login_attempt');
        TestDb::clearTable('app_installation');
        TestDb::clearTable('app_migration');
        run('DROP TABLE IF EXISTS ' . t('demo_tag'));
        run('DROP TABLE IF EXISTS ' . t('demo_note'));
    }

    private function enter(string $email): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => $email,
            'password' => 'длинный-тестовый-пароль',
        ]);
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $m);

        return $m[1] ?? '';
    }

    /** @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>} */
    private function press(string $op, string $app = ''): array
    {
        $page = Kernel::get('/admin/apps');
        $fields = ['_token' => $this->token($page['body']), 'op' => $op];
        if ($app !== '') {
            $fields['app'] = $app;
        }

        return Kernel::post('/admin/apps', $fields);
    }

    public function testАнонимНаСводкеПолучаетРедиректНаВход(): void
    {
        $page = Kernel::get('/admin');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fadmin', $page['headers']['location']);
    }

    public function testАнонимНаПриложенияхПолучаетРедиректНаВход(): void
    {
        self::assertSame(302, Kernel::get('/admin/apps')['status']);
    }

    public function testСводкаОткрываетсяАдминистратору(): void
    {
        $this->enter('admin@school.dev');
        $page = Kernel::get('/admin');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Сводка', $page['body']);
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        self::assertStringContainsString('Приложения', $page['body'], 'Вкладки админки обязаны быть на каждом экране');
        self::assertStringContainsString('Выйти', $page['body']);
    }

    public function testСписокПоказываетНеустановленныйРазделБезКнопкиОбновить(): void
    {
        $this->enter('admin@school.dev');
        $page = Kernel::get('/admin/apps');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Демонстрационный раздел', $page['body']);
        self::assertStringContainsString('Не установлен', $page['body']);
        self::assertStringContainsString('>Установить<', $page['body']);
        // Критерий §12.4: «Обновить» появляется только когда версия в базе ниже версии манифеста.
        self::assertStringNotContainsString('>Обновить<', $page['body']);
        self::assertStringContainsString('Раздел из будущего', $page['body']);
        self::assertStringContainsString('Нужно более новое ядро', $page['body']);
    }

    public function testКнопкаУстановитьСоздаётТаблицыДвеСтрокиЖурналаИЗакладку(): void
    {
        $this->enter('admin@school.dev');
        $response = $this->press('install', 'demo');

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=installed', $response['headers']['location']);
        self::assertSame(
            ['20261006_0001_note.sql', '20261006_0002_tag.sql'],
            array_map(
                static fn (array $row): string => (string) $row['name'],
                all('SELECT name FROM ' . t('app_migration') . ' WHERE app = ? ORDER BY name', ['demo'])
            )
        );
        self::assertSame('1.1.0', (string) value('SELECT version FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('demo_note')), 'Начальная закладка обязана сработать');

        $page = Kernel::get('/admin/apps');
        self::assertStringContainsString('Установлен', $page['body']);
        self::assertStringContainsString('>Выключить<', $page['body']);
    }

    public function testПовторнаяУстановкаПоказываетПонятныйОтказ(): void
    {
        $this->enter('admin@school.dev');
        $this->press('install', 'demo');
        $second = $this->press('install', 'demo');

        // Причина сбоя уезжает в сессионный flash, в адресную строку — только ключ словаря.
        self::assertStringContainsString('notice=migration-failed', $second['headers']['location']);
        self::assertStringNotContainsString('error=', $second['headers']['location']);
        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('app_migration') . ' WHERE app = ?', ['demo']));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));

        $page = Kernel::get('/admin/apps?notice=migration-failed');
        self::assertStringContainsString('Не удалось завершить действие с разделом.', $page['body'], 'Заголовок отказа печатается из словаря');
        self::assertStringContainsString('Раздел уже установлен.', $page['body'], 'Подробная причина приезжает из сессионного flash');
        self::assertStringContainsString('role="alert"', $page['body']);
    }

    public function testПричинаСбояИсчезаетПослеОдногоПоказа(): void
    {
        $this->enter('admin@school.dev');
        $this->press('install', 'demo');
        $second = $this->press('install', 'demo');

        $first = Kernel::get($second['headers']['location']);
        self::assertStringContainsString('Раздел уже установлен.', $first['body']);

        // Flash убирается сразу после показа: повторный GET печатает только заголовок из словаря.
        $again = Kernel::get($second['headers']['location']);
        self::assertStringNotContainsString('Раздел уже установлен.', $again['body'], 'Причина сбоя показывается ровно один раз');
        self::assertStringContainsString('Не удалось завершить действие с разделом.', $again['body']);
    }

    public function testРазделИзБудущегоНеУстанавливается(): void
    {
        $this->enter('admin@school.dev');
        $response = $this->press('install', 'future');

        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringNotContainsString('error=', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_installation') . ' WHERE id = ?', ['future']));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_migration') . ' WHERE app = ?', ['future']));

        $page = Kernel::get('/admin/apps?notice=migration-failed');
        self::assertStringContainsString('Не удалось завершить действие с разделом.', $page['body']);
        self::assertStringContainsString('Нужна версия ядра не ниже 99.0.0, в сборке ' . CORE_VERSION . '.', $page['body']);
    }

    public function testКнопкаОбновитьПоявляетсяТолькоПриОтставанииВерсии(): void
    {
        $this->enter('admin@school.dev');
        $this->press('install', 'demo');
        self::assertStringNotContainsString('>Обновить<', Kernel::get('/admin/apps')['body']);

        run('DELETE FROM ' . t('app_migration') . ' WHERE app = ? AND name = ?', ['demo', '20261006_0002_tag.sql']);
        run('UPDATE ' . t('app_installation') . ' SET version = ? WHERE id = ?', ['1.0.0', 'demo']);
        run('DROP TABLE IF EXISTS ' . t('demo_tag'));

        self::assertStringContainsString('>Обновить<', Kernel::get('/admin/apps')['body']);
        self::assertStringContainsString('Требует обновления', Kernel::get('/admin/apps')['body']);

        $response = $this->press('update', 'demo');

        self::assertStringContainsString('notice=updated', $response['headers']['location']);
        self::assertSame('1.1.0', (string) value('SELECT version FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [t('demo_tag')]
        ));
    }

    public function testВыключениеПрячетРазделАВключениеВозвращает(): void
    {
        $this->enter('admin@school.dev');
        $this->press('install', 'demo');

        $off = $this->press('disable', 'demo');
        self::assertStringContainsString('notice=disabled', $off['headers']['location']);
        // Выключенный раздел отвечает 404, а не 403: статус не должен выдавать его существование.
        self::assertSame(404, Kernel::get('/demo')['status']);
        self::assertStringContainsString('Выключен', Kernel::get('/admin/apps')['body']);

        $on = $this->press('enable', 'demo');
        self::assertStringContainsString('notice=enabled', $on['headers']['location']);
        self::assertSame(200, Kernel::get('/demo')['status']);
    }

    public function testРедакторВидитСводкуНоНеУправляетРазделами(): void
    {
        $this->enter('editor@school.dev');

        self::assertSame(200, Kernel::get('/admin')['status'], 'Сводка доступна редактору');
        self::assertSame(403, Kernel::get('/admin/apps')['status']);

        $page = Kernel::get('/admin');
        $response = Kernel::post('/admin/apps', ['_token' => $this->token($page['body']), 'op' => 'install', 'app' => 'demo']);

        self::assertSame(403, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));
    }

    public function testЧужойТокенНеУстанавливаетРаздел(): void
    {
        $this->enter('admin@school.dev');
        $response = Kernel::post('/admin/apps', ['_token' => str_repeat('0', 64), 'op' => 'install', 'app' => 'demo']);

        self::assertSame(403, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));
    }

    public function testНеизвестныйРазделОтказывается(): void
    {
        $this->enter('admin@school.dev');
        $response = $this->press('install', 'нету');

        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringNotContainsString('error=', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_installation')));

        $page = Kernel::get('/admin/apps?notice=migration-failed');
        self::assertStringContainsString('Раздел не найден в сборке.', $page['body'], 'Причину отказа печатает flash, а не адресная строка');
    }

    public function testНеизвестноеДействиеОтказывается(): void
    {
        $this->enter('admin@school.dev');
        $this->press('install', 'demo');
        $response = $this->press('сломать', 'demo');

        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringNotContainsString('error=', $response['headers']['location']);
        self::assertSame(1, (int) value('SELECT enabled FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));

        $page = Kernel::get('/admin/apps?notice=migration-failed');
        self::assertStringContainsString('Неизвестное действие.', $page['body']);
    }

    public function testОбновлениеЯдраПриПустомПланеВозвращаетБезОшибки(): void
    {
        // TestDb::reset() раскладывает схему ядра из sql/, но строку журнала пишет установщик.
        // Без этой строки план ядра не пуст, и ветка пустого плана осталась бы непроверенной.
        insert('app_migration', [
            'id' => newId(),
            'app' => 'core',
            'name' => '0001_core_baseline.sql',
            'checksum' => migrationChecksum(dirname(__DIR__, 2) . '/sql/0001_core_baseline.sql'),
            'applied_at' => utcStamp(nowUtc()),
        ]);

        $this->enter('admin@school.dev');
        $response = $this->press('update-core');

        // Пустой план — ничего применять не нужно: обычный PRG-возврат без текста ошибки.
        self::assertSame(302, $response['status']);
        self::assertSame('/admin/apps', $response['headers']['location']);
        self::assertStringNotContainsString('error=', $response['headers']['location']);

        $page = Kernel::get('/admin/apps');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('role="alert"', $page['body']);
        self::assertSame([], coreSqlPending(), 'План ядра обязан остаться пустым');
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('app_migration') . ' WHERE app = ?', ['core']), 'Повторного применения не было');
    }

    public function testВкладкаРазделаПоявляетсяПослеУстановки(): void
    {
        $this->enter('admin@school.dev');
        self::assertStringNotContainsString('/demo/admin', Kernel::get('/admin')['body']);

        $this->press('install', 'demo');

        self::assertStringContainsString('href="/demo/admin"', Kernel::get('/admin')['body']);
    }
}
