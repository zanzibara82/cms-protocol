### Задача 8: Реестр приложений, миграции, установщик и админка

**Files:**
- Create: `core/apps.php`, `core/migrations.php`, `core/MigrationPlan.php`, `core/options.php`
- Create: `public/setup.php`, `core/templates/setup.php`, `admin/layout.php`,
  `core/templates/admin/summary.php`, `core/templates/admin/apps.php`
- Create: `tests/fixtures/apps/demo/manifest.php`, `tests/fixtures/apps/demo/routes.php`,
  `tests/fixtures/apps/demo/seed.php`, `tests/fixtures/apps/demo/sql/20261006_0001_note.sql`,
  `tests/fixtures/apps/demo/sql/20261006_0002_tag.sql`
- Create: `tests/fixtures/apps/future/manifest.php`, `tests/fixtures/apps/future/routes.php`,
  каталог `tests/fixtures/apps/future/sql/` (пустой, с `.gitkeep`)
- Modify: `core/bootstrap.php` (подключение `apps.php`, `migrations.php`, `options.php`, `admin/layout.php`),
  `core/router.php` (гейт `permission => 'manage'`), `core/auth.php` (`grantsAppManage()`),
  `core/routes.php` (три маршрута админки и три контроллера), `public/.htaccess` (замок установщика),
  `tests/http/LoginTest.php` (два отложенных теста)
- Test: `tests/db/AppsRegistryTest.php`, `tests/db/MigrationsTest.php`, `tests/db/OptionsTest.php`,
  `tests/http/SetupTest.php`, `tests/http/AdminAppsTest.php`, `tests/guard/RouteHandlersTest.php`

**Interfaces:**
- Consumes: `configValue()`, `newId()`, `utcStamp()`, `nowUtc()`, `db()`, `t()`, `all()`, `one()`, `value()`, `run()`,
  `insert()`, `splitSql()`, `doAction()`, `applyFilters()`, `addRoute()`, `matchRoute()`, `routeTable()`, `routesReset()`,
  `respond()`, `redirect()`, `redirectWithNotice()`, `notFound()`, `forbidden()`, `render()`, `partial()`, `e()`,
  `startSession()`, `currentUser()`, `getEditorAccess()`, `roleGrantsManage()`, `hashPassword()`, `ROLES`,
  `csrfField()`, `csrfCheck()`, `noticeOfRequest()`, `CORE_NOTICE_TEXT`, `queryString()`, `postString()`, `isPost()`,
  `requestPath()`, `logInfo()`, `logWarn()`, `logError()`, `securityHeaders()`, `CORE_VERSION`,
  `Cms\Tests\TestDb`, `Cms\Tests\http\Kernel`.
- Produces:
  - `appDirs(): list<string>` — каталоги с приложениями: `apps/` и, если заполнено, `EXTRA_APPS_DIR`.
  - `appManifests(): array<string, array<string, mixed>>` — манифесты по возрастанию `id`, каждый дополнен ключом `dir`.
  - `&appManifestsRef(): ?array` — ссылка на кеш манифестов; `appsReset(): void` обнуляет его.
  - `appManifest(string $id): ?array<string, mixed>`
  - `appInstallations(): array<string, array<string, mixed>>`, `appInstallation(string $id): ?array<string, mixed>`
  - `isAppInstalled(string $id): bool`, `isAppActive(string $id): bool`
  - `coreVersionSatisfies(string $requires): bool`
  - `appState(string $id): array{state: string, dbVersion: string, manifestVersion: string, reason: string}` —
    `state` один из `'missing' | 'incompatible' | 'outdated' | 'disabled' | 'ready'`.
  - `appPrivatePaths(): list<string>` — приватные префиксы всех приложений из манифестов (их читает задача 9).
  - `registerAppRoutes(): void`
  - `sqlDirOf(string $app): string`, `sqlFilesOf(string $app): list<array{name: string, path: string}>`
  - `migrationChecksum(string $path): string`, `appliedMigrations(string $app): array<string, string>`
  - `planApp(string $app): Cms\Core\MigrationPlan`, `coreSqlPending(): list<string>`
  - `applyApp(Cms\Core\MigrationPlan $plan): array{ok: bool, error: string, applied: list<string>}`
  - `installApp(string $id): array{ok: bool, error: string, applied: list<string>}`
  - `updateApp(string $id): array{ok: bool, error: string, applied: list<string>}`
  - `setAppEnabled(string $id, bool $enabled): array{ok: bool, error: string}`
  - `seedApp(array $manifest): void`
  - `Cms\Core\MigrationPlan` — `__construct(string $app, array $pending, array $changed, string $targetVersion)`
    с публичными readonly-свойствами `app`, `pending`, `changed`, `targetVersion` и методами `isEmpty(): bool`,
    `isBlocked(): bool`, `blockMessage(): string`, `pendingNames(): list<string>`.
  - `getOption(string $name, string $default = ''): string`, `setOption(string $name, ?string $value): void`,
    `deleteOption(string $name): void`, `optionsAll(): array<string, string|null>`, `optionsReset(): void`.
  - `grantsAppManage(): bool` — только роль `admin`, не заблокирован.
  - `adminTabs(): list<array{href: string, label: string}>`,
    `adminShell(string $heading, string $content, ?string $notice = null): string`,
    `adminTabsHtml(string $current): string`, `adminNotice(?string $text): string`,
    константа `ADMIN_CORE_TABS`.
  - Маршруты `GET /admin` (`coreAdminSummary`), `GET /admin/apps` (`coreAdminApps`),
    `POST /admin/apps` (`coreAdminAppsAction`), помощник `finishAppAction(array $result, string $to, string $notice): void`.
  - `public/setup.php` и `public/install.lock`: после появления замка установщик отвечает 404.
  - Константы установщика: `SETUP_LOCK`, `SETUP_REQUIRED_EXTENSIONS`, `SETUP_MIN_PHP`, `SETUP_PASSWORD_MIN`.
  - Фикстуры: `demo` (версия `1.1.0`, два файла SQL, `seed.php`, приватный путь `/demo/admin`, вкладка `/demo/admin`)
    и `future` (`requiresCore 99.0.0`, пустой `sql/`, приватный путь `/future/admin`).

- [ ] **Шаг 1: Фикстуры приложений**

`tests/fixtures/apps/demo/manifest.php`:

```php
<?php

declare(strict_types=1);

// Фикстура для проверок установки и обновления: два релиза SQL и своя начальная закладка.
// id обязан совпадать с именем каталога — расхождение ловит AppsRegistryTest, а в работе оно
// дало бы молчаливый 404: resolveTemplate() ищет шаблоны по имени каталога, а не по id.
return [
    'id' => 'demo',
    'title' => 'Демонстрационный раздел',
    'version' => '1.1.0',
    'requiresCore' => '1.1.0',
    'privatePaths' => ['/demo/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [['href' => '/demo/admin', 'label' => 'Демо']],
    'seed' => __DIR__ . '/seed.php',
];
```

`tests/fixtures/apps/demo/sql/20261006_0001_note.sql`:

```sql
CREATE TABLE IF NOT EXISTS tbl_demo_note (
  id VARCHAR(26) NOT NULL,
  title VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`tests/fixtures/apps/demo/sql/20261006_0002_tag.sql`:

```sql
CREATE TABLE IF NOT EXISTS tbl_demo_tag (
  id VARCHAR(26) NOT NULL,
  note_id VARCHAR(26) NOT NULL,
  label VARCHAR(40) NOT NULL,
  PRIMARY KEY (id),
  KEY demo_tag_note (note_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`tests/fixtures/apps/demo/seed.php`:

```php
<?php

declare(strict_types=1);

// Начальная закладка обязана быть идемпотентной: seed.php запускается при первой установке,
// а проверка «повторная установка не дублирует строки» (критерий §12.4) считает строки после него.
$exists = value('SELECT COUNT(*) FROM ' . t('demo_note') . ' WHERE title = ?', ['Первая заметка']);

if ((int) $exists === 0) {
    insert('demo_note', [
        'id' => newId(),
        'title' => 'Первая заметка',
        'created_at' => utcStamp(nowUtc()),
    ]);
}
```

`tests/fixtures/apps/demo/routes.php`:

```php
<?php

declare(strict_types=1);

// Маршруты фикстуры регистрируются, но не открываются: раздел не установлен, поэтому гейт
// dispatch() отвечает 404. Обработчики нужны, чтобы страж RouteHandlersTest не нашёл пустых имён.
addRoute('GET', '/demo', 'demoFeed', ['app' => 'demo']);
addRoute('GET', '/demo/admin', 'demoAdmin', ['app' => 'demo', 'private' => true, 'permission' => 'login']);

/** @param array<string, string> $params */
function demoFeed(array $params = []): void
{
    respond(200, 'Демонстрационная лента', ['Content-Type' => 'text/plain; charset=utf-8']);
}

/** @param array<string, string> $params */
function demoAdmin(array $params = []): void
{
    respond(200, 'Демонстрационная админка', ['Content-Type' => 'text/plain; charset=utf-8']);
}
```

`tests/fixtures/apps/future/manifest.php`:

```php
<?php

declare(strict_types=1);

// Фикстура отказа: требует ядро, которого не существует. На ней проверяется критерий §12.3 —
// человеческий текст и ни одной созданной таблицы.
return [
    'id' => 'future',
    'title' => 'Раздел из будущего',
    'version' => '1.0.0',
    'requiresCore' => '99.0.0',
    'privatePaths' => ['/future/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [],
    'seed' => null,
];
```

`tests/fixtures/apps/future/routes.php`:

```php
<?php

declare(strict_types=1);

addRoute('GET', '/future', 'futureFeed', ['app' => 'future']);

/** @param array<string, string> $params */
function futureFeed(array $params = []): void
{
    respond(200, 'Лента раздела из будущего', ['Content-Type' => 'text/plain; charset=utf-8']);
}
```

`tests/fixtures/apps/future/sql/.gitkeep` — пустой файл: каталог обязан существовать, чтобы `planApp('future')`
проверял пустой план, а не падение на отсутствующем каталоге.

Run: `docker compose -f docker-compose.dev.yml run --rm php -l tests/fixtures/apps/demo/routes.php`
Expected: `No syntax errors detected`.

- [ ] **Шаг 2: Падающий тест реестра — он же красным, пока реестра нет**

`tests/db/AppsRegistryTest.php`:

```php
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
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter AppsRegistryTest`
Expected: FAIL — `appManifests()` не определена.

- [ ] **Шаг 3: Реестр приложений**

`core/apps.php`:

```php
<?php

declare(strict_types=1);

// Порядок регистрации маршрутов задан спецификацией §5.2: ядро, затем приложения по алфавиту id.
// Алфавитный порядок делает таблицу маршрутов одинаковой на любом хостинге: glob() возвращает
// файлы в порядке файловой системы, а он совпадать не обязан.
const APP_MANIFEST_REQUIRED_KEYS = ['id', 'title', 'version', 'requiresCore'];

/** @return list<string> */
function appDirs(): array
{
    $dirs = [dirname(__DIR__) . '/apps'];

    $extra = (string) configValue('EXTRA_APPS_DIR', '');
    if ($extra !== '' && is_dir($extra)) {
        $dirs[] = rtrim($extra, '/');
    }

    return $dirs;
}

// Кеш живёт в ссылке, а не в static-переменной внутри appManifests(): тестам нужно сбрасывать его
// между наборами, а static-переменная функции извне недоступна.
/** @return array<string, array<string, mixed>>|null */
function &appManifestsRef(): ?array
{
    static $manifests = null;

    return $manifests;
}

function appsReset(): void
{
    $manifests = &appManifestsRef();
    $manifests = null;
}

/** @return array<string, array<string, mixed>> */
function appManifests(): array
{
    $manifests = &appManifestsRef();

    if ($manifests !== null) {
        return $manifests;
    }

    $found = [];
    foreach (appDirs() as $dir) {
        foreach (glob($dir . '/*/manifest.php') ?: [] as $file) {
            $manifest = require $file;
            if (!is_array($manifest)) {
                logError('app.manifest_invalid', ['file' => $file]);

                continue;
            }

            $id = (string) ($manifest['id'] ?? '');
            $folder = basename(dirname((string) $file));
            if ($id === '' || $id !== $folder) {
                logError('app.manifest_id_mismatch', ['file' => $file, 'id' => $id, 'folder' => $folder]);

                continue;
            }

            foreach (APP_MANIFEST_REQUIRED_KEYS as $key) {
                if (($manifest[$key] ?? '') === '') {
                    logError('app.manifest_incomplete', ['file' => $file, 'key' => $key]);

                    continue 2;
                }
            }

            $manifest['dir'] = dirname((string) $file);
            $found[$id] = $manifest;
        }
    }

    ksort($found);
    $manifests = $found;

    return $manifests;
}

/** @return array<string, mixed>|null */
function appManifest(string $id): ?array
{
    $manifests = appManifests();

    return $manifests[$id] ?? null;
}

/** @return array<string, array<string, mixed>> */
function appInstallations(): array
{
    $out = [];
    foreach (all('SELECT id, enabled, installed_at, version FROM ' . t('app_installation')) as $row) {
        $out[(string) $row['id']] = $row;
    }

    return $out;
}

/** @return array<string, mixed>|null */
function appInstallation(string $id): ?array
{
    return one(
        'SELECT id, enabled, installed_at, version FROM ' . t('app_installation') . ' WHERE id = ?',
        [$id]
    );
}

function isAppInstalled(string $id): bool
{
    return appInstallation($id) !== null;
}

// Гейт маршрута спрашивает именно «активно ли»: выключенный раздел обязан отвечать 404, а не 403 —
// иначе статус выдаёт существование выключенного раздела.
function isAppActive(string $id): bool
{
    $row = appInstallation($id);

    return $row !== null && (int) $row['enabled'] === 1;
}

function coreVersionSatisfies(string $requires): bool
{
    return version_compare($requires, CORE_VERSION, '<=');
}

/**
 * Состояние раздела для экрана «Приложения» (спецификация §6.3).
 *
 * @return array{state: string, dbVersion: string, manifestVersion: string, reason: string}
 */
function appState(string $id): array
{
    $manifest = appManifest($id);
    if ($manifest === null) {
        return [
            'state' => 'missing',
            'dbVersion' => '',
            'manifestVersion' => '',
            'reason' => 'Файлы раздела не найдены в сборке.',
        ];
    }

    $manifestVersion = (string) $manifest['version'];
    $row = appInstallation($id);

    if ($row === null) {
        if (!coreVersionSatisfies((string) $manifest['requiresCore'])) {
            return [
                'state' => 'incompatible',
                'dbVersion' => '',
                'manifestVersion' => $manifestVersion,
                'reason' => 'Нужна версия ядра не ниже ' . (string) $manifest['requiresCore'] . ', в сборке ' . CORE_VERSION . '.',
            ];
        }

        return ['state' => 'missing', 'dbVersion' => '', 'manifestVersion' => $manifestVersion, 'reason' => 'Раздел не установлен.'];
    }

    $dbVersion = (string) $row['version'];

    if ((int) $row['enabled'] !== 1) {
        return ['state' => 'disabled', 'dbVersion' => $dbVersion, 'manifestVersion' => $manifestVersion, 'reason' => 'Раздел выключен.'];
    }

    if (version_compare($dbVersion, $manifestVersion, '<')) {
        return [
            'state' => 'outdated',
            'dbVersion' => $dbVersion,
            'manifestVersion' => $manifestVersion,
            'reason' => 'Есть обновление до ' . $manifestVersion . '.',
        ];
    }

    return ['state' => 'ready', 'dbVersion' => $dbVersion, 'manifestVersion' => $manifestVersion, 'reason' => ''];
}

// Приватные префиксы нужны не только гейту: задача 9 закрывает их в robots.txt,
// а страж сверяет этот список с тем, что действительно не индексируется.
/** @return list<string> */
function appPrivatePaths(): array
{
    $paths = [];
    foreach (appManifests() as $manifest) {
        foreach ((array) ($manifest['privatePaths'] ?? []) as $path) {
            $clean = '/' . trim((string) $path, '/');
            if ($clean !== '/' && !in_array($clean, $paths, true)) {
                $paths[] = $clean;
            }
        }
    }

    sort($paths);

    return $paths;
}

// Маршруты регистрируются для всех приложений из сборки, включая неустановленные: dispatch()
// отвечает 404 по метке app, а таблица маршрутов остаётся полной для стража.
function registerAppRoutes(): void
{
    foreach (appManifests() as $manifest) {
        $routes = rtrim((string) $manifest['dir'], '/') . '/routes.php';
        if (is_file($routes)) {
            require_once $routes;
        }
    }
}
```

`core/bootstrap.php` дополняется (после `core/routes.php`):

```php
require_once __DIR__ . '/apps.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/options.php';
require_once dirname(__DIR__) . '/admin/layout.php';
```

Файлы `migrations.php`, `options.php` и `admin/layout.php` создаются в шагах 5, 8 и 11 этой же задачи:
подключайте каждую строку тогда, когда файл появился, иначе `php -l` на воротах упадёт на первом же прогоне.

- [ ] **Шаг 4: Зелёный тест реестра**

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter AppsRegistryTest`
Expected: PASS (10/10).

- [ ] **Шаг 5: План миграций**

`core/MigrationPlan.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Core;

// План — значение, а не состояние: его можно построить, показать в админке и передать в applyApp()
// отдельными шагами. Так тест проверяет план, ничего не применяя.
final class MigrationPlan
{
    /**
     * @param list<array{name: string, path: string, checksum: string}> $pending ещё не применённые файлы
     * @param list<string> $changed имена применённых файлов, чей текст изменился
     */
    public function __construct(
        public readonly string $app,
        public readonly array $pending,
        public readonly array $changed,
        public readonly string $targetVersion,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->pending === [];
    }

    // Изменившийся применённый файл — отказ, а не тихая перезапись (спецификация §6.3):
    // журнал обязан описывать ту схему, которая действительно лежит в базе.
    public function isBlocked(): bool
    {
        return $this->changed !== [];
    }

    public function blockMessage(): string
    {
        return 'Файл миграции ' . implode(', ', $this->changed) . ' изменён после применения. Обновите его новой миграцией.';
    }

    /** @return list<string> */
    public function pendingNames(): array
    {
        return array_map(static fn (array $file): string => (string) $file['name'], $this->pending);
    }
}
```

`core/migrations.php`:

```php
<?php

declare(strict_types=1);

use Cms\Core\MigrationPlan;

// Порядок применения задаёт имя файла, поэтому миграции начинаются с даты: 20261006_0001 раньше
// 20261006_0002. sort() обязателен — glob() возвращает файлы в порядке файловой системы.
function sqlDirOf(string $app): string
{
    if ($app === 'core') {
        return dirname(__DIR__) . '/sql';
    }

    $manifest = appManifest($app);

    return $manifest === null ? '' : rtrim((string) ($manifest['sql'] ?? ''), '/');
}

/** @return list<array{name: string, path: string}> */
function sqlFilesOf(string $app): array
{
    $dir = sqlDirOf($app);
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.sql') ?: [];
    sort($files);

    return array_map(
        static fn (string $path): array => ['name' => basename($path), 'path' => $path],
        $files
    );
}

function migrationChecksum(string $path): string
{
    return hash('sha256', (string) file_get_contents($path));
}

/** @return array<string, string> имя файла => контрольная сумма */
function appliedMigrations(string $app): array
{
    $rows = all(
        'SELECT name, checksum FROM ' . t('app_migration') . ' WHERE app = ? ORDER BY applied_at, name',
        [$app]
    );

    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['name']] = (string) $row['checksum'];
    }

    return $out;
}

function planApp(string $app): MigrationPlan
{
    $applied = appliedMigrations($app);
    $pending = [];
    $changed = [];

    foreach (sqlFilesOf($app) as $file) {
        $name = (string) $file['name'];
        $checksum = migrationChecksum((string) $file['path']);

        if (!array_key_exists($name, $applied)) {
            $pending[] = ['name' => $name, 'path' => (string) $file['path'], 'checksum' => $checksum];

            continue;
        }

        if (!hash_equals($applied[$name], $checksum)) {
            $changed[] = $name;
        }
    }

    $manifest = appManifest($app);
    $target = $app === 'core' ? CORE_VERSION : (string) ($manifest['version'] ?? '0.0.0');

    return new MigrationPlan($app, $pending, $changed, $target);
}

/** @return list<string> */
function coreSqlPending(): array
{
    return planApp('core')->pendingNames();
}

/**
 * Применяет план. Строка журнала пишется сразу после каждого файла, а не одной транзакцией:
 * MySQL неявно коммитит DDL, поэтому откатить наполовину созданную схема транзакция не может.
 *
 * @return array{ok: bool, error: string, applied: list<string>}
 */
function applyApp(MigrationPlan $plan): array
{
    if ($plan->isBlocked()) {
        logWarn('migration.blocked', ['app' => $plan->app, 'changed' => $plan->changed]);

        return ['ok' => false, 'error' => $plan->blockMessage(), 'applied' => []];
    }

    $applied = [];
    foreach ($plan->pending as $file) {
        $name = (string) $file['name'];

        try {
            foreach (splitSql((string) file_get_contents((string) $file['path'])) as $statement) {
                run($statement);
            }
        } catch (Throwable $error) {
            logError('migration.failed', ['app' => $plan->app, 'file' => $name, 'message' => $error->getMessage()]);

            return [
                'ok' => false,
                'error' => 'Не удалось применить файл ' . $name . ': ' . $error->getMessage(),
                'applied' => $applied,
            ];
        }

        insert('app_migration', [
            'id' => newId(),
            'app' => $plan->app,
            'name' => $name,
            'checksum' => (string) $file['checksum'],
            'applied_at' => utcStamp(nowUtc()),
        ]);

        $applied[] = $name;
    }

    if ($plan->app !== 'core') {
        run('UPDATE ' . t('app_installation') . ' SET version = ? WHERE id = ?', [$plan->targetVersion, $plan->app]);
    }

    doAction('app_updated', $plan->app, $applied);
    logInfo('migration.applied', ['app' => $plan->app, 'files' => $applied]);

    return ['ok' => true, 'error' => '', 'applied' => $applied];
}

/** @return array{ok: bool, error: string, applied: list<string>} */
function installApp(string $id): array
{
    $manifest = appManifest($id);
    if ($manifest === null) {
        return ['ok' => false, 'error' => 'Раздел «' . $id . '» не найден в сборке.', 'applied' => []];
    }

    // Критерий §12.3: проверка версии ядра идёт до planApp(), чтобы отказ не зависел от того,
    // что лежит в sql/ раздела.
    if (!coreVersionSatisfies((string) $manifest['requiresCore'])) {
        logWarn('app.install_refused', ['app' => $id, 'reason' => 'requiresCore']);

        return [
            'ok' => false,
            'error' => 'Нужна версия ядра не ниже ' . (string) $manifest['requiresCore'] . ', в сборке ' . CORE_VERSION . '.',
            'applied' => [],
        ];
    }

    if (isAppInstalled($id)) {
        return ['ok' => false, 'error' => 'Раздел уже установлен.', 'applied' => []];
    }

    insert('app_installation', [
        'id' => $id,
        'enabled' => 1,
        'installed_at' => utcStamp(nowUtc()),
        'version' => '0.0.0',
    ]);

    $result = applyApp(planApp($id));
    if ($result['ok'] === false) {
        // Строку установки убираем: раздел без таблиц не должен числиться установленным,
        // иначе «Обновить» никогда не появится, а «Установить» будет отказывать.
        run('DELETE FROM ' . t('app_installation') . ' WHERE id = ?', [$id]);

        return $result;
    }

    seedApp($manifest);
    doAction('app_installed', $id);
    logInfo('app.installed', ['app' => $id, 'files' => $result['applied']]);

    return $result;
}

/** @return array{ok: bool, error: string, applied: list<string>} */
function updateApp(string $id): array
{
    if (appManifest($id) === null) {
        return ['ok' => false, 'error' => 'Раздел «' . $id . '» не найден в сборке.', 'applied' => []];
    }

    if (!isAppInstalled($id)) {
        return ['ok' => false, 'error' => 'Раздел не установлен — обновлять нечего.', 'applied' => []];
    }

    $plan = planApp($id);

    return $plan->isEmpty() ? ['ok' => true, 'error' => '', 'applied' => []] : applyApp($plan);
}

/** @return array{ok: bool, error: string} */
function setAppEnabled(string $id, bool $enabled): array
{
    if (!isAppInstalled($id)) {
        return ['ok' => false, 'error' => 'Раздел не установлен.'];
    }

    run('UPDATE ' . t('app_installation') . ' SET enabled = ? WHERE id = ?', [$enabled ? 1 : 0, $id]);
    logInfo($enabled ? 'app.enabled' : 'app.disabled', ['app' => $id]);

    return ['ok' => true, 'error' => ''];
}

// Начальная закладка идёт после миграций: она пишет в таблицы, которых до них не существует.
// Идемпотентность — обязанность seed.php, ядро лишь не запускает его при обновлении.
/** @param array<string, mixed> $manifest */
function seedApp(array $manifest): void
{
    $seed = $manifest['seed'] ?? null;
    if (!is_string($seed) || $seed === '' || !is_file($seed)) {
        return;
    }

    require $seed;
}
```

- [ ] **Шаг 6: Тесты миграций**

`tests/db/MigrationsTest.php`:

```php
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
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter MigrationsTest`
Expected: PASS (15/15).

- [ ] **Шаг 7: Право на установку разделов**

`core/auth.php` дополняется (после `roleGrantsManage()`):

```php
// Установка и обновление разделов меняют схему базы — это право только администратора.
// roleGrantsManage() шире: она пускает и редактора, поэтому для /admin/apps нужна отдельная проверка.
function grantsAppManage(): bool
{
    $user = currentUser();
    if ($user === null || $user['blocked_at'] !== null) {
        return false;
    }

    return (string) $user['role'] === ROLES['admin'];
}
```

`core/router.php` — гейт приватного пути в `dispatch()` заменяется целиком. Итоговый вид функции:

```php
function dispatch(): void
{
    $method = requestMethod();
    $match = matchRoute($method, requestPath());

    if ($match === null) {
        notFound();
    }

    $app = (string) ($match['meta']['app'] ?? '');
    if ($app !== '' && !isAppActive($app)) {
        notFound();
    }

    $permission = (string) ($match['meta']['permission'] ?? '');
    $private = ($match['meta']['private'] ?? false) === true || $permission !== '';

    if ($private) {
        $access = getEditorAccess();

        if ($access['kind'] !== 'granted') {
            // Анонима отправляем на вход, а не в 403: человек с истёкшей сессией должен попасть
            // на форму, а не в тупик. Для POST редирект теряет тело, поэтому остаётся 403.
            if ($method === 'GET' && in_array($permission, ['login', 'manage'], true)) {
                redirect('/login?next=' . rawurlencode(requestPath()));
            }

            forbidden();
        }

        if ($permission === 'manage' && !grantsAppManage()) {
            forbidden('Раздел доступен только администратору.');
        }
    }

    doAction('before_route', $match);

    $handler = $match['handler'];
    if (!is_callable($handler)) {
        logError('route.handler_missing', ['handler' => $handler, 'path' => requestPath()]);
        respond(500, partial('error'), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    $handler($match['params']);

    doAction('after_route');
}
```

- [ ] **Шаг 8: Настройки сайта**

`core/options.php`:

```php
<?php

declare(strict_types=1);

// Настройки читаются одним запросом и кешируются на запрос: на shared-хостинге процесс живёт
// ровно столько, сколько запрос, поэтому кеш не успевает устареть, а лишний запрос на каждой
// странице — расход. Пишущие функции сбрасывают кеш, чтобы чтение в том же запросе увидело новое.
/** @return array<string, string|null>|null */
function &optionsRef(): ?array
{
    static $options = null;

    return $options;
}

/** @return array<string, string|null> */
function optionsAll(): array
{
    $cache = &optionsRef();

    if ($cache !== null) {
        return $cache;
    }

    $loaded = [];
    foreach (all('SELECT option_name, option_value FROM ' . t('option') . ' WHERE autoload = 1') as $row) {
        $loaded[(string) $row['option_name']] = $row['option_value'] === null ? null : (string) $row['option_value'];
    }

    $cache = $loaded;

    return $cache;
}

function optionsReset(): void
{
    $cache = &optionsRef();
    $cache = null;
}

function getOption(string $name, string $default = ''): string
{
    $options = optionsAll();

    // Пустая строка — значение, а не отсутствие настройки: иначе поле нельзя очистить.
    if (!array_key_exists($name, $options) || $options[$name] === null) {
        return $default;
    }

    return (string) $options[$name];
}

function setOption(string $name, ?string $value): void
{
    if ($value === null) {
        deleteOption($name);

        return;
    }

    $exists = (int) value('SELECT COUNT(*) FROM ' . t('option') . ' WHERE option_name = ?', [$name]) === 1;

    if ($exists) {
        run('UPDATE ' . t('option') . ' SET option_value = ? WHERE option_name = ?', [$value, $name]);
    } else {
        insert('option', ['option_name' => $name, 'option_value' => $value, 'autoload' => 1]);
    }

    optionsReset();
}

function deleteOption(string $name): void
{
    run('DELETE FROM ' . t('option') . ' WHERE option_name = ?', [$name]);
    optionsReset();
}
```

`tests/db/OptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('option');
        optionsReset();
    }

    public function testОтсутствующаяНастройкаОтдаётЗначениеПоУмолчанию(): void
    {
        self::assertSame('запас', getOption('нету', 'запас'));
        self::assertSame('', getOption('нету'));
    }

    public function testЗаписьИЧтение(): void
    {
        setOption('site_name', 'Школа английского');

        self::assertSame('Школа английского', getOption('site_name'));
        self::assertSame('Школа английского', getOption('site_name', 'запас'));
    }

    public function testПовторнаяЗаписьОбновляетСтроку(): void
    {
        setOption('site_name', 'Первое');
        setOption('site_name', 'Второе');

        self::assertSame('Второе', getOption('site_name'));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('option')));
    }

    public function testПустоеЗначениеОтличаетсяОтОтсутствующего(): void
    {
        setOption('phone', '');

        self::assertSame('', getOption('phone', 'запас'));
    }

    public function testУдалениеВозвращаетЗначениеПоУмолчанию(): void
    {
        setOption('site_name', 'Школа');
        deleteOption('site_name');

        self::assertSame('запас', getOption('site_name', 'запас'));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('option')));
    }

    public function testЗаписьNullУдаляетСтроку(): void
    {
        setOption('site_name', 'Школа');
        setOption('site_name', null);

        self::assertSame('запас', getOption('site_name', 'запас'));
    }

    public function testКешСбрасываетсяПослеЗаписи(): void
    {
        self::assertSame('запас', getOption('site_name', 'запас'));
        setOption('site_name', 'Школа');

        self::assertSame('Школа', getOption('site_name', 'запас'));
    }

    public function testКешЧитаетсяОднимЗапросом(): void
    {
        setOption('site_name', 'Школа');
        $first = optionsAll();
        $second = optionsAll();

        self::assertSame($first, $second);
        self::assertArrayHasKey('site_name', $first);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter OptionsTest`
Expected: PASS (8/8).

- [ ] **Шаг 9: Одноразовый установщик**

`public/setup.php`:

```php
<?php

declare(strict_types=1);

// Установщик лежит в public/, потому что документ-корень хостинга — именно public/.
// Доступен ровно один раз: после успешной установки появляется install.lock, и любой следующий
// запрос отвечает 404 (критерий §12.1). Файл с хостинга легко удалить, но легко и забыть —
// поэтому замок обязателен, а не желателен.
require dirname(__DIR__) . '/core/bootstrap.php';

const SETUP_LOCK = __DIR__ . '/install.lock';
const SETUP_REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'json', 'gd'];
const SETUP_MIN_PHP = '8.1.0';
const SETUP_PASSWORD_MIN = 10;

securityHeaders();

function setupLocked(): bool
{
    return is_file(SETUP_LOCK);
}

/** @return list<string> */
function setupProblems(): array
{
    $problems = [];

    if (version_compare(PHP_VERSION, SETUP_MIN_PHP, '<')) {
        $problems[] = 'Нужен PHP не ниже ' . SETUP_MIN_PHP . ', сейчас ' . PHP_VERSION . '.';
    }

    foreach (SETUP_REQUIRED_EXTENSIONS as $extension) {
        if (!extension_loaded($extension)) {
            $problems[] = 'Не хватает расширения ' . $extension . '.';
        }
    }

    if (!is_writable(__DIR__)) {
        $problems[] = 'Каталог public/ недоступен для записи — не получится создать install.lock.';
    }

    return $problems;
}

/**
 * @param array<string, string> $values
 * @return array<string, string>
 */
function setupValidate(array $values): array
{
    $errors = [];

    if (mb_strlen(trim($values['site_name'] ?? '')) < 2) {
        $errors['site_name'] = 'Название сайта — не короче двух символов.';
    }

    $email = mb_strtolower(trim($values['admin_email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
        $errors['admin_email'] = 'Проверьте адрес почты.';
    }

    if (mb_strlen(trim($values['admin_name'] ?? '')) < 2) {
        $errors['admin_name'] = 'Укажите имя администратора.';
    }

    $password = (string) ($values['password'] ?? '');
    if (mb_strlen($password) < SETUP_PASSWORD_MIN) {
        $errors['password'] = 'Пароль — не короче ' . SETUP_PASSWORD_MIN . ' символов.';
    } elseif ($password !== (string) ($values['password_again'] ?? '')) {
        $errors['password_again'] = 'Пароли не совпадают.';
    }

    return $errors;
}

// Таблицы ядра создаёт установщик, а не planApp('core'): журнала tbl_app_migration до них
// не существует, поэтому строку журнала и установку ядра пишем вручную сразу после схемы.
// Замок ставится только в самом конце, поэтому между неудачной попыткой и повторным запуском
// этот шаг обязан быть повторяемым: схема — через IF NOT EXISTS (отклонение 9), записи — только
// если их ещё нет. Иначе второй прогон упирался бы в migration_app_name (app, name) и в
// PRIMARY KEY id = 'core', и установить сайт после сбоя было бы нельзя.
function setupApplyBaseline(): void
{
    $file = dirname(__DIR__) . '/sql/0001_core_baseline.sql';

    foreach (splitSql((string) file_get_contents($file)) as $statement) {
        run($statement);
    }

    $alreadyLogged = one('SELECT id FROM ' . t('app_migration') . ' WHERE app = ? AND name = ?', ['core', basename($file)]);
    if ($alreadyLogged === null) {
        insert('app_migration', [
            'id' => newId(),
            'app' => 'core',
            'name' => basename($file),
            'checksum' => migrationChecksum($file),
            'applied_at' => utcStamp(nowUtc()),
        ]);
    }

    $installed = one('SELECT id FROM ' . t('app_installation') . " WHERE id = ?", ['core']);
    if ($installed === null) {
        insert('app_installation', [
            'id' => 'core',
            'enabled' => 1,
            'installed_at' => utcStamp(nowUtc()),
            'version' => CORE_VERSION,
        ]);
    }
}

/** @param array<string, string> $values */
function setupCreateAdmin(array $values): void
{
    $email = mb_strtolower(trim($values['admin_email']));

    // Повторный прогон после сбоя (замок пишется только в самом конце) упирается в
    // user_email (email), поэтому существовавший адрес не вставляется второй раз, а обновляется:
    // человек входит паролем, который ввёл в последней попытке.
    $existing = one('SELECT id FROM ' . t('user') . ' WHERE email = ?', [$email]);
    if ($existing === null) {
        insert('user', [
            'id' => newId(),
            'email' => $email,
            'password_hash' => hashPassword($values['password']),
            'name' => trim($values['admin_name']),
            'role' => ROLES['admin'],
            'session_version' => 0,
            'created_at' => utcStamp(nowUtc()),
        ]);

        return;
    }

    run(
        'UPDATE ' . t('user') . ' SET password_hash = ?, name = ?, role = ? WHERE id = ?',
        [hashPassword($values['password']), trim($values['admin_name']), ROLES['admin'], (string) $existing['id']]
    );
}

function setupFinish(): void
{
    file_put_contents(SETUP_LOCK, utcStamp(nowUtc()) . "\n");
    logInfo('setup.finished', ['php' => PHP_VERSION]);
}

if (setupLocked()) {
    notFound('Сайт уже установлен.');
}

startSession();

$problems = setupProblems();
$errors = [];
$values = [
    'site_name' => (string) configValue('SITE_NAME', ''),
    'admin_email' => '',
    'admin_name' => '',
    'password' => '',
    'password_again' => '',
];

if (isPost()) {
    csrfCheck();

    $values = [
        'site_name' => postString('site_name'),
        'admin_email' => postString('admin_email'),
        'admin_name' => postString('admin_name'),
        'password' => postString('password'),
        'password_again' => postString('password_again'),
    ];
    $errors = setupValidate($values);

    if ($errors === [] && $problems === []) {
        setupApplyBaseline();
        setOption('site_name', trim($values['site_name']));
        setOption('admin_email', mb_strtolower(trim($values['admin_email'])));
        setOption('installed_at', utcStamp(nowUtc()));
        setupCreateAdmin($values);
        setupFinish();

        // Установщик не входит сам: человек вводит пароль ещё раз на /login, как обычно.
        // Ключ 'site-installed' уже есть в CORE_NOTICE_TEXT (задача 7); 'installed' занят
        // под установку приложения и читается на странице «Приложения».
        redirectWithNotice('/login', 'site-installed');
    }
}

render('setup', ['problems' => $problems, 'errors' => $errors, 'values' => $values], [
    'title' => 'Установка сайта',
    'noindex' => true,
]);
```

`core/templates/setup.php`:

```php
<?php

declare(strict_types=1);

/** @var list<string> $problems */
/** @var array<string, string> $errors */
/** @var array<string, string> $values */

$disabled = $problems === [] ? '' : ' disabled';
$fields = [
    'site_name' => ['label' => 'Название сайта', 'type' => 'text', 'max' => 120, 'auto' => ''],
    'admin_email' => ['label' => 'Почта администратора', 'type' => 'email', 'max' => 190, 'auto' => 'username'],
    'admin_name' => ['label' => 'Имя администратора', 'type' => 'text', 'max' => 120, 'auto' => ''],
];
?>
<section class="mx-auto max-w-xl py-10">
    <h1 class="text-2xl font-bold">Установка сайта</h1>
    <p class="mt-2 text-sm text-ink/70">Шаг первый из двух: здесь появляются таблицы ядра и первый администратор. Разделы устанавливаются потом, на странице «Приложения».</p>

    <?php if ($problems !== []): ?>
        <ul class="mt-4 space-y-1 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert">
            <?php foreach ($problems as $problem): ?>
                <li><?= e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="mt-6 space-y-4" method="post" action="/setup.php">
        <?= csrfField() /* @safe html */ ?>

        <?php foreach ($fields as $name => $field): ?>
            <label class="block text-sm">
                <span class="font-medium"><?= e($field['label']) ?></span>
                <input class="mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2"
                       type="<?= e($field['type']) ?>" name="<?= e((string) $name) ?>"
                       value="<?= e($values[(string) $name]) ?>" required
                       maxlength="<?= e((string) $field['max']) ?>"
                       <?php if ($field['auto'] !== ''): ?>autocomplete="<?= e($field['auto']) ?>"<?php endif; ?>>
                <?php if (isset($errors[(string) $name])): ?>
                    <span class="mt-1 block text-bad"><?= e($errors[(string) $name]) ?></span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>

        <label class="block text-sm">
            <span class="font-medium">Пароль</span>
            <input class="mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password"
                   name="password" required minlength="<?= e((string) SETUP_PASSWORD_MIN) ?>" autocomplete="new-password">
            <?php if (isset($errors['password'])): ?>
                <span class="mt-1 block text-bad"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </label>

        <label class="block text-sm">
            <span class="font-medium">Пароль ещё раз</span>
            <input class="mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password"
                   name="password_again" required autocomplete="new-password">
            <?php if (isset($errors['password_again'])): ?>
                <span class="mt-1 block text-bad"><?= e($errors['password_again']) ?></span>
            <?php endif; ?>
        </label>

        <button class="w-full rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90 disabled:opacity-50"
                type="submit"<?= e($disabled) ?>>Установить</button>
    </form>
</section>
```

Строка `/* @safe html */ <?= csrfField() ?>` в HTML-режиме напечаталась бы текстом — поставьте её внутри
PHP-тегов:

```php
<?php /* @safe html */ ?><?= csrfField() ?>
```

Страж `OutputEscapingTest` убирает из проверки строку с пометкой, поэтому `<?= csrfField() ?>` обязан стоять
на той же строке, что и пометка. Итоговая строка в шаблоне:

```php
        <?php /* @safe html */ ?><?= csrfField() ?>
```

`render('setup', …)` ищет файл по цепочке `theme/setup.php` → `core/templates/setup.php`; кладите его в
`core/templates/`, потому что установщик — служебный экран ядра, а не часть темы.

`public/.htaccess` дополняется (после блока `mod_headers`):

```apache
# Замок установщика не раздаётся: он обязан отвечать 404, а не скачиваться текстом.
<Files "install.lock">
    Require all denied
</Files>
```

Под `php -S` то же делает блок пропуска статики из задачи 5: он исключает расширение `.lock`.

- [ ] **Шаг 10: HTTP-тест установщика**

`tests/http/SetupTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::unlock();
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
        self::unlock();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    private static function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/public/install.lock';
    }

    // Замок убирается до и после набора: он живёт в рабочем каталоге, а тесты обязаны быть
    // повторяемыми. В git он не попадает — public/install.lock закрыт в .gitignore (задача 1).
    private static function unlock(): void
    {
        if (is_file(self::lockPath())) {
            unlink(self::lockPath());
        }
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $m);

        return $m[1] ?? '';
    }

    public function testФормаУстановкиОткрывается(): void
    {
        $page = Kernel::get('/setup.php');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Установка сайта', $page['body']);
        self::assertStringContainsString('method="post"', $page['body'], 'Форма с паролем обязана быть POST');
        self::assertStringContainsString('noindex, nofollow', $page['body']);
    }

    public function testКороткийПарольНеСоздаётАдминистратора(): void
    {
        $page = Kernel::get('/setup.php');
        $response = Kernel::post('/setup.php', [
            '_token' => $this->token($page['body']),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'короткий',
            'password_again' => 'короткий',
        ]);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Пароль — не короче 10 символов', $response['body']);
        self::assertFileDoesNotExist(self::lockPath());
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('user')));
    }

    public function testНесовпавшиеПаролиОтклоняются(): void
    {
        $page = Kernel::get('/setup.php');
        $response = Kernel::post('/setup.php', [
            '_token' => $this->token($page['body']),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'другой-длинный-пароль',
        ]);

        self::assertStringContainsString('Пароли не совпадают', $response['body']);
        self::assertFileDoesNotExist(self::lockPath());
    }

    public function testЧужойТокенОтклоняется(): void
    {
        $response = Kernel::post('/setup.php', [
            '_token' => str_repeat('0', 64),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(403, $response['status']);
        self::assertFileDoesNotExist(self::lockPath());
    }

    public function testУстановкаСоздаётЯдроАдминистратораИЗамок(): void
    {
        $page = Kernel::get('/setup.php');
        $response = Kernel::post('/setup.php', [
            '_token' => $this->token($page['body']),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('/login', $response['headers']['location']);
        self::assertStringContainsString('notice=site-installed', $response['headers']['location']);

        self::assertFileExists(self::lockPath());
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('user') . ' WHERE role = ?', ['admin']));
        self::assertSame(
            ['0001_core_baseline.sql'],
            array_map(
                static fn (array $row): string => (string) $row['name'],
                all('SELECT name FROM ' . t('app_migration') . ' WHERE app = ?', ['core'])
            )
        );
        self::assertSame(CORE_VERSION, (string) value('SELECT version FROM ' . t('app_installation') . ' WHERE id = ?', ['core']));
        self::assertSame('Школа английского', getOption('site_name'));
        self::assertSame('admin@school.dev', getOption('admin_email'));
    }

    public function testПослеЗамкаУстановщикОтвечает404(): void
    {
        $page = Kernel::get('/setup.php');

        self::assertSame(404, $page['status']);
        self::assertStringNotContainsString('Установка сайта', $page['body']);
    }

    public function testЗамокНеОтдаётсяФайлом(): void
    {
        $page = Kernel::get('/install.lock');

        self::assertContains($page['status'], [403, 404]);
        self::assertStringNotContainsString('install.lock', $page['body']);
    }

    public function testВходСозданнымАдминистраторомРаботает(): void
    {
        $page = Kernel::get('/login');
        $response = Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => 'admin@school.dev',
            'password' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location']);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter SetupTest`
Expected: PASS (8/8). Порядок тестов внутри файла значим: замок появляется в пятом, а следующие три
проверяют поведение после него.

Проверьте, что `public/install.lock` закрыт в `.gitignore` (задача 1 добавляла строку `public/install.lock`);
если её нет — добавьте и включите в коммит этой задачи.

- [ ] **Шаг 11: Каркас админки**

`admin/layout.php`:

```php
<?php

declare(strict_types=1);

// Каркас админки — библиотека функций, а не шаблон: resolveTemplate() ищет файлы в apps/*/templates,
// theme/ и core/templates, а каталога admin/ в этой цепочке нет. Экраны админки лежат в
// core/templates/admin/ и вызывают adminShell(), поэтому вкладки и уведомление собраны в одном месте.
const ADMIN_CORE_TABS = [
    ['href' => '/admin', 'label' => 'Сводка'],
    ['href' => '/admin/apps', 'label' => 'Приложения'],
];

/** @return list<array{href: string, label: string}> */
function adminTabs(): array
{
    $tabs = ADMIN_CORE_TABS;

    foreach (appManifests() as $manifest) {
        if (!isAppActive((string) $manifest['id'])) {
            continue;
        }

        foreach ((array) ($manifest['adminNav'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $href = (string) ($item['href'] ?? '');
            $label = (string) ($item['label'] ?? '');
            if ($href === '' || $label === '') {
                continue;
            }

            $tabs[] = ['href' => $href, 'label' => $label];
        }
    }

    // Фильтр admin_nav (спецификация §5.4): раздел добавляет вкладку, не правя ядро.
    $filtered = applyFilters('admin_nav', $tabs);

    return is_array($filtered) ? array_values($filtered) : array_values($tabs);
}

function adminNotice(?string $text): string
{
    if ($text === null || $text === '') {
        return '';
    }

    return '<p class="mb-4 rounded-lg border border-good/30 bg-good/10 px-4 py-3 text-sm text-good" role="status">'
        . e($text) . '</p>';
}

function adminTabsHtml(string $current): string
{
    $items = '';
    foreach (adminTabs() as $tab) {
        $href = (string) $tab['href'];
        $active = $href === $current;
        $class = $active
            ? 'rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream'
            : 'rounded-lg px-3 py-2 text-sm font-medium text-ink/70 hover:bg-ink/5';
        $aria = $active ? ' aria-current="page"' : '';

        $items .= '<li><a class="' . $class . '" href="' . e($href) . '"' . $aria . '>'
            . e((string) $tab['label']) . '</a></li>';
    }

    return '<nav aria-label="Разделы админки"><ul class="flex flex-wrap gap-2">' . $items . '</ul></nav>';
}

// Экраны админки печатаются внутри публичного layout темы: своего HTML-каркаса у ядра нет,
// а шапка, футер и кот остаются общими.
function adminShell(string $heading, string $content, ?string $notice = null): string
{
    return '<div class="space-y-6">'
        . adminNotice($notice)
        . '<header class="flex flex-wrap items-center justify-between gap-3">'
        . '<h1 class="text-2xl font-bold">' . e($heading) . '</h1>'
        . '<form method="post" action="/logout">' . csrfField()
        . '<button class="rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Выйти</button>'
        . '</form></header>'
        . adminTabsHtml(requestPath())
        . '<div class="space-y-4">' . $content . '</div>'
        . '</div>';
}
```

`core/templates/admin/summary.php`:

```php
<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $siteName */
/** @var string $adminEmail */
/** @var int $users */
/** @var list<array{title: string, href: string, state: string}> $apps */

$rows = '';
foreach ($apps as $app) {
    $rows .= '<li class="flex flex-wrap items-center justify-between gap-2 border-b border-ink/10 py-2">'
        . '<a class="font-medium hover:underline" href="' . e($app['href']) . '">' . e($app['title']) . '</a>'
        . '<span class="text-sm text-ink/60">' . e($app['state']) . '</span></li>';
}

if ($rows === '') {
    $rows = '<li class="py-2 text-sm text-ink/60">Разделов пока нет — установите их на странице «Приложения».</li>';
}

$cards = '';
foreach ([
    ['label' => 'Сайт', 'value' => $siteName],
    ['label' => 'Администратор', 'value' => $adminEmail],
    ['label' => 'Пользователей', 'value' => (string) (int) $users],
] as $card) {
    $cards .= '<div class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<dt class="text-sm text-ink/60">' . e($card['label']) . '</dt>'
        . '<dd class="mt-1 font-medium">' . e($card['value']) . '</dd></div>';
}

$content = '<dl class="grid gap-3 sm:grid-cols-3">' . $cards . '</dl>'
    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Установленные разделы</h2><ul class="mt-2">' . $rows . '</ul></section>';

/* @safe html */ echo adminShell('Сводка', $content, $notice);
```

`core/templates/admin/apps.php`:

```php
<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $error */
/** @var string $coreVersion */
/** @var list<string> $corePending */
/** @var list<array{id: string, title: string, state: string, dbVersion: string, manifestVersion: string, reason: string}> $apps */

const APP_STATE_LABELS = [
    'missing' => 'Не установлен',
    'incompatible' => 'Нужно более новое ядро',
    'outdated' => 'Требует обновления',
    'disabled' => 'Выключен',
    'ready' => 'Установлен',
];

// Кнопки — отдельные формы, а намерение приходит полем op, а не именем кнопки: имя кнопки
// не попадает в POST, если человек отправил форму клавишей Enter.
$appButtons = static function (array $app): string {
    $form = static function (string $op, string $label, string $kind) use ($app): string {
        return '<form class="inline" method="post" action="/admin/apps">' . csrfField()
            . '<input type="hidden" name="op" value="' . e($op) . '">'
            . '<input type="hidden" name="app" value="' . e((string) $app['id']) . '">'
            . '<button class="rounded-lg px-3 py-1.5 text-sm ' . $kind . '" type="submit">' . e($label) . '</button></form>';
    };

    $out = [];
    if ($app['state'] === 'missing') {
        $out[] = $form('install', 'Установить', 'bg-ink text-cream hover:opacity-90');
    }
    if ($app['state'] === 'outdated') {
        $out[] = $form('update', 'Обновить', 'bg-ink text-cream hover:opacity-90');
    }
    if ($app['state'] === 'ready' || $app['state'] === 'outdated') {
        $out[] = $form('disable', 'Выключить', 'border border-ink/20 hover:bg-ink/5');
    }
    if ($app['state'] === 'disabled') {
        $out[] = $form('enable', 'Включить', 'bg-ink text-cream hover:opacity-90');
    }

    return $out === [] ? '<span class="text-sm text-ink/50">Действий нет</span>' : implode(' ', $out);
};

$rows = '';
foreach ($apps as $app) {
    $rows .= '<tr class="border-b border-ink/10">'
        . '<th class="py-3 pr-3 pl-4 text-left font-medium">' . e($app['title']) . '</th>'
        . '<td class="py-3 pr-3 text-sm text-ink/70">' . e(APP_STATE_LABELS[$app['state']] ?? $app['state']) . '</td>'
        . '<td class="py-3 pr-3 text-sm text-ink/70">' . e($app['dbVersion'] === '' ? '—' : $app['dbVersion'])
        . ' / ' . e($app['manifestVersion']) . '</td>'
        . '<td class="py-3 pr-3 text-sm text-ink/60">' . e($app['reason']) . '</td>'
        . '<td class="py-3 pr-4 text-right"><div class="flex flex-wrap justify-end gap-2">' . $appButtons($app) . '</div></td>'
        . '</tr>';
}

$core = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Ядро</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Версия в сборке: ' . e($coreVersion) . '.</p>';

if ($corePending === []) {
    $core .= '<p class="mt-2 text-sm text-ink/60">Обновлений ядра нет.</p>';
} else {
    $core .= '<p class="mt-2 text-sm text-ink/70">Ждут применения: ' . e(implode(', ', $corePending)) . '.</p>'
        . '<form class="mt-3" method="post" action="/admin/apps">' . csrfField()
        . '<input type="hidden" name="op" value="update-core">'
        . '<button class="rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Обновить ядро</button></form>';
}

$core .= '</section>';

$table = '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
    . '<table class="w-full min-w-[40rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
    . '<th class="py-2 pr-3 pl-4 font-medium">Раздел</th><th class="py-2 pr-3 font-medium">Состояние</th>'
    . '<th class="py-2 pr-3 font-medium">Версия в базе / в сборке</th><th class="py-2 pr-3 font-medium">Пояснение</th>'
    . '<th class="py-2 pr-4 text-right font-medium">Действия</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

$alert = $error === ''
    ? ''
    : '<p class="mb-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert">' . e($error) . '</p>';

/* @safe html */ echo $alert . adminShell('Приложения', $core . $table, $notice);
```

- [ ] **Шаг 12: Экраны и действия админки**

`core/routes.php` — внутри `registerCoreRoutes()` добавьте три маршрута:

```php
    addRoute('GET', '/admin', 'coreAdminSummary', ['private' => true, 'permission' => 'login']);
    addRoute('GET', '/admin/apps', 'coreAdminApps', ['private' => true, 'permission' => 'manage']);
    addRoute('POST', '/admin/apps', 'coreAdminAppsAction', ['private' => true, 'permission' => 'manage']);
```

и четыре функции после `coreLogout()`:

```php
/** @param array<string, string> $params */
function coreAdminSummary(array $params = []): void
{
    $apps = [];
    foreach (appInstallations() as $id => $row) {
        // Ядро — не раздел: у него нет ни манифеста, ни вкладки, ни кнопки «Выключить».
        if ($id === 'core') {
            continue;
        }

        $manifest = appManifest((string) $id);
        $nav = (array) ($manifest['adminNav'] ?? []);
        $first = is_array($nav[0] ?? null) ? (string) ($nav[0]['href'] ?? '/admin/apps') : '/admin/apps';

        $apps[] = [
            'title' => (string) ($manifest['title'] ?? $id),
            'href' => $first,
            // enabled приходит то числом, то строкой — зависит от драйвера и режима подготовки,
            // поэтому сравниваем после приведения к int.
            'state' => (int) $row['enabled'] === 1 ? 'Включён, версия ' . (string) $row['version'] : 'Выключен',
        ];
    }

    render('admin/summary', [
        'notice' => (string) (noticeOfRequest(CORE_NOTICE_TEXT) ?? ''),
        'siteName' => getOption('site_name', (string) configValue('SITE_NAME', '')),
        'adminEmail' => getOption('admin_email'),
        'users' => (int) value('SELECT COUNT(*) FROM ' . t('user')),
        'apps' => $apps,
    ], ['title' => 'Сводка — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function coreAdminApps(array $params = []): void
{
    $rows = [];
    foreach (appManifests() as $id => $manifest) {
        $state = appState((string) $id);
        $rows[] = [
            'id' => (string) $id,
            'title' => (string) $manifest['title'],
            'state' => (string) $state['state'],
            'dbVersion' => (string) $state['dbVersion'],
            'manifestVersion' => (string) $state['manifestVersion'],
            'reason' => (string) $state['reason'],
        ];
    }

    render('admin/apps', [
        'notice' => (string) (noticeOfRequest(CORE_NOTICE_TEXT) ?? ''),
        'error' => (string) queryString('error', ''),
        'coreVersion' => CORE_VERSION,
        'corePending' => coreSqlPending(),
        'apps' => $rows,
    ], ['title' => 'Приложения — админка', 'noindex' => true]);
}

// Все действия — один обработчик: намерение приходит полем op, а не адресом. Так страж
// CsrfCoverageTest видит одну точку проверки, а журнал — одно событие на действие.
/** @param array<string, string> $params */
function coreAdminAppsAction(array $params = []): void
{
    csrfCheck();

    // Право проверяется и в гейте маршрута, и здесь: обработчик обязан оставаться закрытым,
    // даже если маршрут зарегистрируют без метки permission.
    if (!grantsAppManage()) {
        forbidden('Раздел доступен только администратору.');
    }

    $op = postString('op');
    $app = postString('app');

    if ($op === 'update-core') {
        $plan = planApp('core');
        $result = $plan->isEmpty() ? ['ok' => true, 'error' => ''] : applyApp($plan);
        finishAppAction($result, '/admin/apps', $plan->isEmpty() ? '' : 'updated');

        return;
    }

    if ($app === '' || appManifest($app) === null) {
        redirect('/admin/apps?error=' . rawurlencode('Раздел не найден в сборке.'));
    }

    $result = match ($op) {
        'install' => installApp($app),
        'update' => updateApp($app),
        'disable' => setAppEnabled($app, false),
        'enable' => setAppEnabled($app, true),
        default => ['ok' => false, 'error' => 'Неизвестное действие.'],
    };

    $notice = match ($op) {
        'install' => 'installed',
        'update' => 'updated',
        'disable' => 'disabled',
        'enable' => 'enabled',
        default => '',
    };

    finishAppAction($result, '/admin/apps', $notice);
}

/**
 * Отказ показывается текстом на той же странице, а не исключением: человек читает причину
 * и решает, что делать. Успех — PRG-редирект с ключом уведомления.
 *
 * @param array{ok: bool, error?: string} $result
 */
function finishAppAction(array $result, string $to, string $notice): void
{
    if (($result['ok'] ?? false) === false) {
        redirect($to . '?error=' . rawurlencode((string) ($result['error'] ?? 'Действие не удалось.')));
    }

    if ($notice === '') {
        redirect($to);
    }

    redirectWithNotice($to, $notice);
}
```

- [ ] **Шаг 13: HTTP-тесты админки и два отложенных теста входа**

`tests/http/AdminAppsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
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

        self::assertStringContainsString('error=', $second['headers']['location']);
        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('app_migration') . ' WHERE app = ?', ['demo']));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));

        $page = Kernel::get('/admin/apps?' . (string) parse_url($second['headers']['location'], PHP_URL_QUERY));
        self::assertStringContainsString('Раздел уже установлен.', $page['body']);
    }

    public function testРазделИзБудущегоНеУстанавливается(): void
    {
        $this->enter('admin@school.dev');
        $response = $this->press('install', 'future');

        self::assertStringContainsString('error=', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_installation') . ' WHERE id = ?', ['future']));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_migration') . ' WHERE app = ?', ['future']));

        $page = Kernel::get('/admin/apps?' . (string) parse_url($response['headers']['location'], PHP_URL_QUERY));
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

        self::assertStringContainsString('error=', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_installation')));
    }

    public function testНеизвестноеДействиеОтказывается(): void
    {
        $this->enter('admin@school.dev');
        $this->press('install', 'demo');
        $response = $this->press('сломать', 'demo');

        self::assertStringContainsString('error=', $response['headers']['location']);
        self::assertSame(1, (int) value('SELECT enabled FROM ' . t('app_installation') . ' WHERE id = ?', ['demo']));
    }

    public function testВкладкаРазделаПоявляетсяПослеУстановки(): void
    {
        $this->enter('admin@school.dev');
        self::assertStringNotContainsString('/demo/admin', Kernel::get('/admin')['body']);

        $this->press('install', 'demo');

        self::assertStringContainsString('href="/demo/admin"', Kernel::get('/admin')['body']);
    }
}
```

`tests/http/LoginTest.php` — здесь появляются оба отложенных задачей 6 теста. Первый из них там уже есть
(в задаче 6 он проверял только редирект), поэтому его тело заменяется целиком, а не добавляется вторым методом
с тем же именем; второй — новый:

```php
    public function testВерныйПарольПереноситВАдминку(): void
    {
        TestDb::clearTable('login_attempt');
        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location']);

        $page = Kernel::get('/admin');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Сводка', $page['body']);
    }

    public function testВыходГаситСессию(): void
    {
        TestDb::clearTable('login_attempt');
        Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        $page = Kernel::get('/admin');
        $logout = Kernel::post('/logout', ['_token' => $this->tokenFrom($page['body'])]);

        self::assertSame(302, $logout['status']);
        self::assertStringContainsString('notice=signed-out', $logout['headers']['location']);
        self::assertSame(302, Kernel::get('/admin')['status'], 'После выхода сводка снова закрыта');
    }
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite http`
Expected: PASS (`NotFoundTest`, `LoginTest`, `SetupTest`, `AdminAppsTest`).

- [ ] **Шаг 14: Страж обработчиков маршрутов**

`tests/guard/RouteHandlersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Спецификация §10.2: «каждый маршрут манифеста имеет обработчик». Маршрут хранит имя функции
// строкой, поэтому опечатка в routes.php всплывает только на запросе человека — страж ловит её
// до коммита. База не нужна: addRoute() и require routes.php в неё не ходят.
final class RouteHandlersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        routesReset();
        registerCoreRoutes();
        registerAppRoutes();
    }

    public function testКаждыйОбработчикСуществует(): void
    {
        $missing = [];

        foreach (routeTable() as $route) {
            if (!is_callable($route['handler'])) {
                $missing[] = $route['method'] . ' ' . $route['pattern'] . ' → ' . $route['handler'];
            }
        }

        self::assertSame([], $missing, 'Маршруты без обработчика');
    }

    public function testПриватныйМаршрутСсылаетсяНаИзвестныйРаздел(): void
    {
        foreach (routeTable() as $route) {
            if (($route['meta']['private'] ?? false) !== true) {
                continue;
            }

            $app = (string) ($route['meta']['app'] ?? '');
            self::assertTrue(
                $app === '' || array_key_exists($app, appManifests()),
                'Приватный маршрут ' . $route['pattern'] . ' ссылается на неизвестный раздел «' . $app . '»'
            );
        }
    }

    public function testКаждыйРазделИзМанифестаЗакрытВRobots(): void
    {
        // Критерий §12.5 дублируется стражом задачи 9, но здесь проверка идёт по таблице маршрутов:
        // приватный маршрут без приватного пути в манифесте — расхождение, которое иначе не видно.
        $closed = robotsDisallowed();

        foreach (routeTable() as $route) {
            if (($route['meta']['private'] ?? false) !== true) {
                continue;
            }

            $covered = false;
            foreach ($closed as $prefix) {
                if (str_starts_with($route['pattern'], (string) $prefix)) {
                    $covered = true;
                    break;
                }
            }

            self::assertTrue($covered, 'Приватный маршрут ' . $route['pattern'] . ' не закрыт в robots.txt');
        }
    }

    public function testЗначенияПраваИзвестныГейту(): void
    {
        $known = ['', 'login', 'manage'];

        foreach (routeTable() as $route) {
            self::assertContains(
                (string) ($route['meta']['permission'] ?? ''),
                $known,
                'Неизвестное значение permission у маршрута ' . $route['pattern']
            );
        }
    }

    public function testТаблицаМаршрутовНеПуста(): void
    {
        self::assertNotSame([], routeTable());
    }
}
```

Тест `testКаждыйРазделИзМанифестаЗакрытВRobots` вызывает `robotsDisallowed()` из `core/seo.php` — файла
задачи 9. Чтобы ворота не были красными между задачами, создайте этот тест в задаче 9 (шаг 5), а в задаче 8
держите в файле только первые два теста и `testТаблицаМаршрутовНеПуста`.

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite guard`
Expected: PASS.

- [ ] **Шаг 15: Ворота и коммит**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add core/ admin/ public/ tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Реестр разделов, журнал миграций, одноразовый установщик и админка «Приложения»"
```

---

### Задача 9: Карта сайта и robots.txt

**Files:**
- Create: `core/seo.php`
- Modify: `core/bootstrap.php` (подключение `seo.php`), `core/routes.php` (два маршрута и два контроллера),
  `tests/guard/RouteHandlersTest.php` (тест приватных маршрутов в robots.txt)
- Test: `tests/http/SitemapTest.php`, `tests/http/RobotsTest.php`, `tests/guard/PrivatePathsRobotsTest.php`

**Interfaces:**
- Consumes: `configValue()`, `e()`, `addRoute()`, `respond()`, `applyFilters()`, `appPrivatePaths()`,
  `Cms\Tests\http\Kernel`, `Cms\Tests\TestDb`.
- Produces:
  - `absoluteUrl(string $path): string`
  - `coreSitemapUrls(): list<array{loc: string}>`
  - `sitemapUrls(): list<array{loc: string, lastmod?: string}>`
  - `isRobotsDisallowed(string $path): bool`
  - `robotsDisallowed(): list<string>`
  - `sitemapXml(): string`
  - `robotsLines(): list<string>`
  - `robotsTxt(): string`
  - константа `ROBOTS_CORE_DISALLOW`
  - маршруты `GET /sitemap.xml` (`coreSitemap`) и `GET /robots.txt` (`coreRobots`)
  - фильтры `sitemap_urls` (принимает и возвращает `list<array{loc: string, lastmod?: string}>`) и
    `robots_lines` (принимает и возвращает `list<string>` закрытых префиксов).

- [ ] **Шаг 1: Падающие тесты карты сайта и robots.txt**

`tests/http/SitemapTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
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
        // Раздел «Новости» добавит свои адреса фильтром sitemap_urls в задаче 20; до неё
        // в карте сайта ровно один адрес ядра. Фильтр обязан начинаться с isAppActive('news'):
        // таблицы раздела есть не в каждой сборке, а запрос к несуществующей таблице — ошибка.
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
```

`tests/http/RobotsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
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
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter 'SitemapTest|RobotsTest'`
Expected: FAIL — маршрутов `/sitemap.xml` и `/robots.txt` ещё нет.

- [ ] **Шаг 2: Карта сайта и robots.txt**

`core/seo.php`:

```php
<?php

declare(strict_types=1);

// Адрес сайта берётся из настроек, а не из заголовка Host: Host подставляется любой,
// а абсолютные ссылки OG и карты сайта обязаны вести на настоящий домен.
function absoluteUrl(string $path): string
{
    $base = rtrim((string) configValue('SITE_URL', ''), '/');
    $clean = '/' . ltrim($path, '/');

    return $base . $clean;
}

/** @return list<array{loc: string}> */
function coreSitemapUrls(): array
{
    return [['loc' => '/']];
}

// Служебные пути ядра не индексируются: robots.txt и noindex в шаблоне обязаны совпадать,
// иначе поисковик увидит страницу входа в списке адресов сайта (критерий §12.5).
const ROBOTS_CORE_DISALLOW = [
    '/admin',
    '/login',
    '/logout',
    '/setup.php',
    '/install.lock',
    '/config.php',
    '/core/',
    '/apps/',
    '/sql/',
    '/tests/',
    '/scripts/',
    '/vendor/',
    '/composer.json',
    '/composer.lock',
    '/docs/',
];

/**
 * Адреса карты сайта. Фильтр sitemap_urls (спецификация §5.4) позволяет разделу добавить свои,
 * не правя ядро; ядро при этом отбраковывает то, что раздел добавить не вправе.
 *
 * @return list<array{loc: string, lastmod?: string}>
 */
function sitemapUrls(): array
{
    $filtered = applyFilters('sitemap_urls', coreSitemapUrls());
    if (!is_array($filtered)) {
        return coreSitemapUrls();
    }

    $clean = [];
    $seen = [];

    foreach ($filtered as $url) {
        if (!is_array($url)) {
            continue;
        }

        $loc = (string) ($url['loc'] ?? '');

        // В карту попадают только относительные пути нашего сайта: раздел мог бы вернуть чужой
        // домен, а «//host» браузер раскрыл бы как внешний адрес.
        if ($loc === '' || !str_starts_with($loc, '/') || str_starts_with($loc, '//') || in_array($loc, $seen, true)) {
            continue;
        }

        if (isRobotsDisallowed($loc)) {
            continue;
        }

        $seen[] = $loc;
        $entry = ['loc' => $loc];

        $lastmod = $url['lastmod'] ?? null;
        if (is_string($lastmod) && $lastmod !== '') {
            $entry['lastmod'] = $lastmod;
        }

        $clean[] = $entry;
    }

    return $clean;
}

function isRobotsDisallowed(string $path): bool
{
    foreach (robotsDisallowed() as $prefix) {
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function robotsDisallowed(): array
{
    $paths = ROBOTS_CORE_DISALLOW;

    foreach (appPrivatePaths() as $private) {
        if (!in_array($private, $paths, true)) {
            $paths[] = $private;
        }
    }

    $filtered = applyFilters('robots_lines', $paths);

    return is_array($filtered)
        ? array_values(array_filter(array_map('strval', $filtered), static fn (string $p): bool => $p !== ''))
        : $paths;
}

function sitemapXml(): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach (sitemapUrls() as $url) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . e(absoluteUrl((string) $url['loc'])) . '</loc>' . "\n";
        if (isset($url['lastmod'])) {
            $xml .= '    <lastmod>' . e((string) $url['lastmod']) . '</lastmod>' . "\n";
        }
        $xml .= "  </url>\n";
    }

    return $xml . '</urlset>' . "\n";
}

/** @return list<string> */
function robotsLines(): array
{
    $lines = ['User-agent: *', 'Crawl-delay: 1'];

    foreach (robotsDisallowed() as $path) {
        $lines[] = 'Disallow: ' . $path;
    }

    $lines[] = '';
    $lines[] = 'Sitemap: ' . absoluteUrl('/sitemap.xml');

    return $lines;
}

function robotsTxt(): string
{
    return implode("\n", robotsLines()) . "\n";
}
```

`core/routes.php` — внутри `registerCoreRoutes()` добавьте:

```php
    addRoute('GET', '/sitemap.xml', 'coreSitemap');
    addRoute('GET', '/robots.txt', 'coreRobots');
```

и два контроллера после `coreRobots`-соседей:

```php
/** @param array<string, string> $params */
function coreSitemap(array $params = []): void
{
    respond(200, sitemapXml(), [
        'Content-Type' => 'application/xml; charset=utf-8',
        // Карта сайта меняется редко, а пересчёт на каждый запрос бьёт по базе.
        'Cache-Control' => 'public, max-age=3600',
    ]);
}

/** @param array<string, string> $params */
function coreRobots(array $params = []): void
{
    respond(200, robotsTxt(), [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
}
```

`core/bootstrap.php` дополняется (после `core/apps.php`):

```php
require_once __DIR__ . '/seo.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter 'SitemapTest|RobotsTest'`
Expected: PASS (7/7 и 6/6).

- [ ] **Шаг 3: Страж приватных путей**

`tests/guard/PrivatePathsRobotsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Критерий §12.5: robots.txt обязан закрывать приватные пути. Проверка идёт по списку,
// а не по тексту ответа — HTTP-тест видит только то, что сервер отдал в этот момент.
final class PrivatePathsRobotsTest extends TestCase
{
    public function testКаждыйПриватныйПутьЗакрыт(): void
    {
        $closed = robotsDisallowed();

        foreach (appPrivatePaths() as $path) {
            self::assertContains($path, $closed, 'Приватный путь ' . $path . ' не закрыт в robots.txt');
            self::assertTrue(isRobotsDisallowed($path));
        }
    }

    public function testПриватныеПутиНеПопадаютВКартуСайта(): void
    {
        foreach (sitemapUrls() as $url) {
            self::assertFalse(isRobotsDisallowed((string) $url['loc']), 'Закрытый путь ' . (string) $url['loc'] . ' попал в карту сайта');
        }
    }

    public function testСлужебныеКаталогиЗакрыты(): void
    {
        $closed = robotsDisallowed();

        foreach (['/core/', '/apps/', '/sql/', '/tests/', '/config.php', '/setup.php', '/install.lock'] as $path) {
            self::assertContains($path, $closed);
        }
    }

    public function testПриватныеПутиНачинаютсяСоСлэша(): void
    {
        foreach (appPrivatePaths() as $path) {
            self::assertStringStartsWith('/', $path);
            self::assertStringNotContainsString('//', $path);
            self::assertStringNotContainsString('\\', $path);
        }
    }

    public function testАбсолютныеАдресаСтроятсяИзНастроек(): void
    {
        $base = rtrim((string) configValue('SITE_URL', ''), '/');

        foreach (sitemapUrls() as $url) {
            self::assertStringStartsWith($base, absoluteUrl((string) $url['loc']));
        }
    }
}
```

`tests/guard/RouteHandlersTest.php` — добавьте тест, отложенный задачей 8 (шаг 14):

```php
    public function testКаждыйПриватныйМаршрутЗакрытВRobots(): void
    {
        // Приватный маршрут без приватного пути в манифесте — расхождение, которое иначе не видно:
        // гейт закрывает доступ, но поисковик продолжает видеть адрес в robots.txt открытым.
        $closed = robotsDisallowed();

        foreach (routeTable() as $route) {
            if (($route['meta']['private'] ?? false) !== true) {
                continue;
            }

            $covered = false;
            foreach ($closed as $prefix) {
                if (str_starts_with($route['pattern'], (string) $prefix)) {
                    $covered = true;
                    break;
                }
            }

            self::assertTrue($covered, 'Приватный маршрут ' . $route['pattern'] . ' не закрыт в robots.txt');
        }
    }
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite guard`
Expected: PASS.

- [ ] **Шаг 4: Ворота и коммит**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add core/ tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Карта сайта и robots.txt из хуков разделов, закрытые служебные пути"
```

---
