### Задача 3: Слой данных и базовая схема ядра

**Files:**
- Create: `core/db.php`, `sql/0001_core_baseline.sql`
- Create: `tests/TestDb.php`, `tests/unit/EscapeLikeTest.php`, `tests/db/DbLayerTest.php`,
  `tests/db/CoreSchemaTest.php`, `tests/guard/SqlSafetyTest.php`
- Modify: `tests/bootstrap.php`

**Interfaces:**
- Consumes: `config()` из задачи 2, `nowUtc()`/`utcStamp()` из `core/date.php`, `newId()` из `core/id.php`.
- Produces:
  - `db(): PDO`, `t(string $name): string`, `all(string $sql, array $args = []): array`,
    `one(string $sql, array $args = []): ?array`, `value(string $sql, array $args = []): mixed`,
    `run(string $sql, array $args = []): int`, `insert(string $table, array $row): void`,
    `tx(callable $fn): mixed`, `escapeLike(string $value): string`, `inPlaceholders(int $count): string`,
    `splitSql(string $sql): array`;
  - таблицы ядра: `tbl_user`, `tbl_login_attempt`, `tbl_option`, `tbl_app_installation`, `tbl_app_migration`;
  - `Cms\Tests\TestDb::reset()` — пересоздаёт схему **только** в `cms_test`;
    `Cms\Tests\TestDb::seedUser(string $email, string $password, string $role): string`.

- [ ] **Шаг 1: Пишем падающий тест экранирования поиска**

`tests/unit/EscapeLikeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/db.php';

final class EscapeLikeTest extends TestCase
{
    public function testСпецсимволыПоискаСтановятсяТекстом(): void
    {
        self::assertSame('100\\%', escapeLike('100%'));
        self::assertSame('новости\\_2024', escapeLike('новости_2024'));
        self::assertSame('a\\\\b', escapeLike('a\\b'));
        self::assertSame('обычный запрос', escapeLike('обычный запрос'));
    }

    public function testПлейсхолдерыДляСписка(): void
    {
        self::assertSame('?, ?, ?', inPlaceholders(3));
        self::assertSame('?', inPlaceholders(1));
        self::assertSame('', inPlaceholders(0));
    }

    public function testПрефиксТаблицыПодставляетсяИзНастроек(): void
    {
        self::assertSame('tbl_news_item', t('news_item'));
    }

    public function testИмяТаблицыНеМожетПринестиПостороннее(): void
    {
        $this->expectException(InvalidArgumentException::class);
        t('news_item; DROP TABLE tbl_user');
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter EscapeLikeTest`
Expected: FAIL — `escapeLike()` не определена.

- [ ] **Шаг 2: Реализуем слой данных**

`core/db.php`:

```php
<?php

declare(strict_types=1);

// Одно соединение на запрос. На shared-хостинге процесс живёт ровно столько, сколько запрос,
// поэтому ни постоянного соединения, ни кеша схемы здесь нет и быть не может.
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string) configValue('DB_HOST'),
        (string) configValue('DB_PORT'),
        (string) configValue('DB_NAME')
    );

    try {
        $pdo = new PDO($dsn, (string) configValue('DB_USER'), (string) configValue('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    } catch (PDOException $error) {
        logError('db.connect_failed', ['message' => $error->getMessage()]);
        fail('Не удалось подключиться к базе данных.');
    }

    return $pdo;
}

// Префикс таблиц живёт в настройках: один сервер может держать несколько сайтов в одной базе.
// Имя таблицы проверяется — иначе t() стал бы дырой для подстановки.
function t(string $name): string
{
    if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        throw new InvalidArgumentException('Недопустимое имя таблицы: ' . $name);
    }

    return (string) configValue('TABLE_PREFIX', 'tbl_') . $name;
}

/** @return list<array<string, mixed>> */
function all(string $sql, array $args = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($args);

    return $statement->fetchAll();
}

/** @return array<string, mixed>|null */
function one(string $sql, array $args = []): ?array
{
    $statement = db()->prepare($sql);
    $statement->execute($args);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function value(string $sql, array $args = []): mixed
{
    $statement = db()->prepare($sql);
    $statement->execute($args);
    $result = $statement->fetchColumn();

    return $result === false ? null : $result;
}

function run(string $sql, array $args = []): int
{
    $statement = db()->prepare($sql);
    $statement->execute($args);

    return $statement->rowCount();
}

// Вставка собирается из имён колонок: значения всегда уходят плейсхолдерами.
function insert(string $table, array $row): void
{
    $columns = array_keys($row);
    foreach ($columns as $column) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $column) !== 1) {
            throw new InvalidArgumentException('Недопустимое имя колонки: ' . (string) $column);
        }
    }

    $sql = 'INSERT INTO ' . t($table) . ' (' . implode(', ', $columns) . ') VALUES (' . inPlaceholders(count($columns)) . ')';
    run($sql, array_values($row));
}

// Транзакция с явным откатом: частичная установка приложения оставила бы таблицы без журнала.
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }

    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

// Знак процента и подчёркивание в LIKE означают «что угодно» и «любой символ»: поиск по «100%»
// выдал бы всю таблицу. Снимаем их обратным слэшем — тот же приём, что literalLike() в портале.
function escapeLike(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function inPlaceholders(int $count): string
{
    return $count <= 0 ? '' : implode(', ', array_fill(0, $count, '?'));
}

// Файл миграции — набор самостоятельных предложений, разделённых «;» в конце строки.
// Процедуры и триггеры пилот не использует, поэтому более хитрый разбор не нужен.
/** @return list<string> */
function splitSql(string $sql): array
{
    $clean = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = preg_split('/;\s*$/m', $clean) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter EscapeLikeTest`
Expected: PASS (4/4).

- [ ] **Шаг 3: Пишем базовую схему ядра**

`sql/0001_core_baseline.sql`:

```sql
-- Базовая схема ядра. Все моменты — DATETIME в UTC; перевод в московское время делает core/date.php.
-- Перечисления — VARCHAR: CHECK в MySQL 5.7 молча игнорируется, поэтому список ролей живёт в PHP.
-- IF NOT EXISTS: MySQL фиксирует DDL сразу, поэтому повторное применение файла обязано проходить молча
-- (отклонение 9). Страж AppSqlTest проверяет правило по файлам приложений, здесь оно то же.
-- Имена таблиц пишутся с префиксом по умолчанию tbl_. Настоящий префикс берётся из настроек, поэтому
-- splitSql() — единственный канал, который превращает файл раздела в утверждения, — заменяет tbl_ на
-- TABLE_PREFIX вне строковых литералов: при другом префиксе схема обязана встать под него, иначе t()
-- будет читать таблицы, которых никто не создал.

CREATE TABLE IF NOT EXISTS tbl_user (
  id VARCHAR(26) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'editor',
  session_version INT NOT NULL DEFAULT 0,
  blocked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_login_attempt (
  id BIGINT NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY attempt_email (email, attempted_at),
  KEY attempt_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_option (
  option_name VARCHAR(64) NOT NULL,
  option_value TEXT NULL,
  autoload TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (option_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_app_installation (
  id VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  installed_at DATETIME NOT NULL,
  version VARCHAR(16) NOT NULL DEFAULT '0.0.0',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_app_migration (
  id VARCHAR(26) NOT NULL,
  app VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY migration_app_name (app, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Шаг 4: Помощник тестовой базы**

`tests/TestDb.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests;

// Тестовая база пересоздаётся перед каждым набором. Защита от ошибки в имени базы обязательна:
// сброс боевой — необратимая операция, поэтому при любом другом имени помощник падает сразу.
final class TestDb
{
    public static function reset(): void
    {
        self::assertTestDatabase();

        foreach (self::tables() as $table) {
            run('SET FOREIGN_KEY_CHECKS = 0');
            run('DROP TABLE IF EXISTS ' . $table);
            run('SET FOREIGN_KEY_CHECKS = 1');
        }

        foreach (self::migrationFiles() as $file) {
            foreach (splitSql((string) file_get_contents($file)) as $statement) {
                run($statement);
            }
        }
    }

    /** Создаёт редактора и возвращает его id. Пароль — только тестовый, в коде проекта не живёт. */
    public static function seedUser(string $email, string $password, string $role = 'editor'): string
    {
        $id = newId();
        insert('user', [
            'id' => $id,
            'email' => $email,
            'password_hash' => hashPassword($password),
            'name' => 'Тестовый редактор',
            'role' => $role,
            'session_version' => 0,
            'created_at' => utcStamp(nowUtc()),
        ]);

        return $id;
    }

    public static function clearTable(string $name): void
    {
        self::assertTestDatabase();
        run('DELETE FROM ' . t($name));
    }

    private static function assertTestDatabase(): void
    {
        if ((string) configValue('DB_NAME') !== 'cms_test') {
            throw new \RuntimeException('Сброс разрешён только для cms_test, текущая база: ' . (string) configValue('DB_NAME'));
        }
    }

    /** @return list<string> */
    private static function tables(): array
    {
        $rows = all('SHOW TABLES');
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) array_values($row)[0];
        }

        return $out;
    }

    /** @return list<string> */
    private static function migrationFiles(): array
    {
        // Только базовая схема ядра. Таблицы приложений создаёт `installApp()` — если бы помощник
        // раскладывал их сам, тест установки не увидел бы, что кнопка «Установить» действительно
        // создаёт таблицы (критерий §12.2), а журнал остался бы пустым.
        $files = glob(dirname(__DIR__) . '/sql/*.sql') ?: [];
        sort($files);

        return $files;
    }
}
```

`tests/bootstrap.php` после этого шага:

```php
<?php

declare(strict_types=1);

putenv('CMS_DB_NAME=cms_test');
$_ENV['CMS_DB_NAME'] = 'cms_test';
putenv('CMS_CONFIG_FILE=' . dirname(__DIR__) . '/config.sample.php');
putenv('CMS_DB_HOST=' . (getenv('CMS_DB_HOST') ?: 'db'));

// Каталоги фикстур выставляются до подключения ядра: config() кеширует результат в static-переменной
// и применяет переменные CMS_* один раз, поэтому putenv после require уже ничего не изменит.
putenv('CMS_EXTRA_APPS_DIR=' . dirname(__DIR__) . '/tests/fixtures/apps');
putenv('CMS_EXTRA_THEME_DIR=' . dirname(__DIR__) . '/tests/fixtures/theme');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/core/db.php';
require dirname(__DIR__) . '/tests/TestDb.php';
```

Здесь `core/db.php` подключен явно, хотя ядру он понадобится только в задаче 6: `require_once` в списке ниже
повтор не ломает, а тестовому слою `TestDb` функции слоя данных нужны уже сейчас. Строку
`require core/auth.php` не добавляйте — `core/auth.php` появится в задаче 6, а `require_once` отсутствующего
файла это фатальная ошибка, и весь набор тестов падёт ещё до первого запуска PHPUnit.

- [ ] **Шаг 5: Пишем падающие тесты слоя данных**

`tests/db/DbLayerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class DbLayerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function tearDown(): void
    {
        TestDb::clearTable('option');
    }

    public function testВставкаИЧтениеСтроки(): void
    {
        insert('option', ['option_name' => 'site_name', 'option_value' => 'Школа', 'autoload' => 1]);

        self::assertSame('Школа', value('SELECT option_value FROM ' . t('option') . ' WHERE option_name = ?', ['site_name']));
    }

    public function testOneВозвращаетСтрокуИлиПусто(): void
    {
        insert('option', ['option_name' => 'a', 'option_value' => '1', 'autoload' => 1]);

        self::assertSame(['option_name' => 'a', 'option_value' => '1', 'autoload' => 1], one('SELECT * FROM ' . t('option') . ' WHERE option_name = ?', ['a']));
        self::assertNull(one('SELECT * FROM ' . t('option') . ' WHERE option_name = ?', ['нет']));
    }

    public function testAllВозвращаетСписок(): void
    {
        insert('option', ['option_name' => 'a', 'option_value' => '1', 'autoload' => 1]);
        insert('option', ['option_name' => 'b', 'option_value' => '2', 'autoload' => 1]);

        self::assertCount(2, all('SELECT option_name FROM ' . t('option') . ' ORDER BY option_name'));
    }

    public function testRunВозвращаетЧислоЗатронутыхСтрок(): void
    {
        insert('option', ['option_name' => 'a', 'option_value' => '1', 'autoload' => 1]);

        self::assertSame(1, run('UPDATE ' . t('option') . ' SET option_value = ? WHERE option_name = ?', ['2', 'a']));
        self::assertSame(0, run('UPDATE ' . t('option') . ' SET option_value = ? WHERE option_name = ?', ['3', 'нет']));
    }

    public function testТранзакцияОткатываетсяПриОшибке(): void
    {
        try {
            tx(static function (): void {
                insert('option', ['option_name' => 'rollback', 'option_value' => '1', 'autoload' => 1]);
                throw new \RuntimeException('проверяем откат');
            });
        } catch (\RuntimeException) {
            // ожидаемо
        }

        self::assertNull(value('SELECT option_value FROM ' . t('option') . ' WHERE option_name = ?', ['rollback']));
    }

    public function testПоискСоСпецсимволамиНеПревращаетсяВШаблон(): void
    {
        insert('option', ['option_name' => 'news_2026', 'option_value' => '1', 'autoload' => 1]);
        insert('option', ['option_name' => 'news 2026', 'option_value' => '2', 'autoload' => 1]);

        $rows = all(
            'SELECT option_name FROM ' . t('option') . ' WHERE option_name LIKE ? ESCAPE \'\\\\\'',
            ['%' . escapeLike('news_2026') . '%']
        );

        self::assertCount(1, $rows);
        self::assertSame('news_2026', $rows[0]['option_name']);
    }

    public function testИмяТаблицыСПостороннимСимволомОтвергается(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        all('SELECT 1 FROM ' . t('user WHERE 1=1 --'));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite db`
Expected: FAIL — таблицы не созданы (`Base table or view not found`).

- [ ] **Шаг 6: Доводим до зелёного и проверяем схему**

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite db`
Expected: PASS (7/7) — `TestDb::reset()` создаёт таблицы из `sql/`.

`tests/db/CoreSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class CoreSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    public function testВсеТаблицыЯдраНаМесте(): void
    {
        $expected = ['user', 'login_attempt', 'option', 'app_installation', 'app_migration'];

        foreach ($expected as $name) {
            self::assertSame(1, (int) value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [t($name)]), 'Нет таблицы ' . t($name));
        }
    }

    public function testПочтаПользователяУникальна(): void
    {
        TestDb::clearTable('user');
        $first = ['id' => newId(), 'email' => 'redaktor@school.dev', 'password_hash' => 'x', 'name' => 'Первый', 'role' => 'editor', 'session_version' => 0, 'created_at' => utcStamp(nowUtc())];
        insert('user', $first);

        $this->expectException(\PDOException::class);
        insert('user', [...$first, 'id' => newId(), 'name' => 'Второй']);
    }

    public function testЖурналМиграцийНеПринимаетДваОдинаковыхФайла(): void
    {
        TestDb::clearTable('app_migration');
        $row = ['id' => newId(), 'app' => 'core', 'name' => '0001_core_baseline.sql', 'checksum' => str_repeat('a', 64), 'applied_at' => utcStamp(nowUtc())];
        insert('app_migration', $row);

        $this->expectException(\PDOException::class);
        insert('app_migration', [...$row, 'id' => newId()]);
    }

    public function testДвижокИКодировкаТаблиц(): void
    {
        $row = one('SELECT ENGINE, TABLE_COLLATION FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [t('user')]);

        self::assertSame('InnoDB', $row['ENGINE']);
        self::assertSame('utf8mb4_unicode_ci', $row['TABLE_COLLATION']);
    }

    public function testДатаСозданияХранитсяВUtc(): void
    {
        TestDb::clearTable('option');
        insert('option', ['option_name' => 'moment', 'option_value' => utcStamp(nowUtc()), 'autoload' => 1]);

        $stored = (string) value('SELECT option_value FROM ' . t('option') . ' WHERE option_name = ?', ['moment']);
        self::assertSame(utcStamp(nowUtc()), $stored);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stored);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite db`
Expected: PASS.

- [ ] **Шаг 7: Страж SQL**

`tests/guard/SqlSafetyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Два правила из спецификации §6.1 и §10.2, которые легко нарушить по незнанию:
// в запрос не подставляется строка, а миграция ничего не удаляет.
final class SqlSafetyTest extends TestCase
{
    private const FORBIDDEN_CALLS = [
        '/->\s*query\s*\(/' => 'PDO::query — запрос без плейсхолдеров',
        '/->\s*exec\s*\(/' => 'PDO::exec — запрос без плейсхолдеров',
        '/\bmysqli_/' => 'mysqli_* — второй способ ходить в базу',
        '/\$(sql|query)\s*\.=/' => 'склеивание строки запроса',
        '/"\s*SELECT[^"]*\$/' => 'переменная внутри текста запроса',
        '/\'\s*SELECT[^;]*\'\s*\.\s*\$/' => 'конкатенация запроса со строкой',
    ];

    public function testКодНеСклеиваетЗапросы(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            foreach (self::FORBIDDEN_CALLS as $pattern => $reason) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $this->relative($file) . ': ' . $reason;
                }
            }
        }

        self::assertSame([], $violations, 'Найдены места, где SQL собирается склеиванием строк');
    }

    public function testМиграцииТолькоДополняющие(): void
    {
        $violations = [];
        $forbidden = ['/\bDROP\b/i', '/\bTRUNCATE\b/i', '/\bDELETE\s+FROM\b/i', '/\bRENAME\s+TABLE\b/i', '/\bDROP\s+COLUMN\b/i'];

        foreach ($this->sqlFiles() as $file) {
            $code = (string) file_get_contents($file);
            foreach ($forbidden as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $this->relative($file) . ': ' . $pattern;
                }
            }
        }

        self::assertSame([], $violations, 'Миграция удаляет данные — правки схемы обязаны быть дополняющими');
    }

    public function testКаждаяТаблицаНаInnoDbИUtf8mb4(): void
    {
        foreach ($this->sqlFiles() as $file) {
            $code = (string) file_get_contents($file);
            $creates = preg_match_all('/CREATE TABLE/i', $code);
            $engines = preg_match_all('/ENGINE=InnoDB/i', $code);
            $charsets = preg_match_all('/CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/i', $code);

            self::assertSame($creates, $engines, $this->relative($file) . ': таблица без ENGINE=InnoDB');
            self::assertSame($creates, $charsets, $this->relative($file) . ': таблица без utf8mb4_unicode_ci');
        }
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        return $this->files(['core', 'admin', 'theme', 'apps', 'public'], 'php');
    }

    /** @return list<string> */
    private function sqlFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = glob($root . '/sql/*.sql') ?: [];
        foreach (glob($root . '/apps/*/sql/*.sql') ?: [] as $file) {
            $out[] = $file;
        }
        sort($out);

        return $out;
    }

    /** @param list<string> $dirs */
    private function files(array $dirs, string $extension): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach ($dirs as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === $extension) {
                    $out[] = $item->getPathname();
                }
            }
        }
        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }

    private function withoutComments(string $code): string
    {
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

        return (string) preg_replace('#//[^\n]*#', '', $code);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite guard`
Expected: PASS. Если `tests/TestDb.php` попадает под страж — он и не должен: страж сканирует `core/ admin/ theme/
apps/ public/`, а `DROP TABLE` живёт в `tests/`.

- [ ] **Шаг 8: Ворота и коммит**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add core/db.php sql/ tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Слой данных CMS: PDO с плейсхолдерами, префикс таблиц, базовая схема ядра и стражи SQL"
```

---

### Задача 4: Хуки, шаблоны и тема

**Files:**
- Create: `core/hooks.php`, `core/template.php`, `theme/layout.php`, `theme/home.php`, `theme/404.php`,
  `theme/partials/header.php`, `theme/partials/footer.php`, `theme/partials/cat.php`
- Create: `tailwind.config.js`, `postcss.config.js`, `package.json`, `src-css/app.css`, `scripts/css`,
  `public/assets/app.css` (`package-lock.json` появляется из `npm install` и тоже коммитится)
- Copy (побайтово, без перемасштабирования и без пересохранения): `public/assets/cat-happy.png` и
  `public/assets/cat-sad.png` — из `../english-portal/public/cats/`; `public/assets/favicon.png` —
  из `../english-portal/public/brand/`. Векторных версий талисмана в `english-portal` нет (файлов
  `cat-happy.svg` и `cat-sad.svg` не существует), поэтому переносятся растровые PNG, а шаблон кота
  и `<link rel="icon">` ссылаются на `.png`.
- Create: `tests/unit/HooksTest.php`, `tests/unit/TemplateTest.php`, `tests/guard/OutputEscapingTest.php`
- Modify: `core/bootstrap.php` (подключение `core/hooks.php` и `core/template.php` — шаг 4)

**Interfaces:**
- Consumes: `e()` из `core/escape.php`, `configValue()`.
- Produces:
  - `addAction(string $hook, callable $fn, int $priority = 10): void`,
    `addFilter(string $hook, callable $fn, int $priority = 10): void`,
    `doAction(string $hook, mixed ...$args): void`, `applyFilters(string $hook, mixed $value, mixed ...$args): mixed`,
    `hookCount(string $hook): int`, `hooksReset(): void` (нужен тестам);
  - `render(string $template, array $data = [], array $page = []): void`,
    `partial(string $template, array $data = []): string`, `resolveTemplate(string $template): string`,
    `richText(string $text): string`;
  - `$page` — `array{title?: string, description?: string, noindex?: bool, head?: string}`;
    `head` — сырой HTML для `<head>`, его передаёт только первичный код (ядро и приложения);
    в `$head` он оказывается после вывода хука `page_head`;
  - тема отдаёт `theme/layout.php` с переменными `$title`, `$description`, `$content`, `$head`, `$noindex`.

- [ ] **Шаг 1: Пишем падающие тесты хуков**

`tests/unit/HooksTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/hooks.php';

final class HooksTest extends TestCase
{
    protected function setUp(): void
    {
        hooksReset();
    }

    public function testДействиеВызываетВсеОбработчикиПоПорядкуПриоритета(): void
    {
        $log = [];
        addAction('probe', static function () use (&$log): void { $log[] = 'второй'; }, 20);
        addAction('probe', static function () use (&$log): void { $log[] = 'первый'; }, 10);
        addAction('probe', static function () use (&$log): void { $log[] = 'третий'; }, 30);

        doAction('probe');

        self::assertSame(['первый', 'второй', 'третий'], $log);
    }

    public function testОдинаковыйПриоритетСохраняетПорядокРегистрации(): void
    {
        $log = [];
        addAction('probe', static function () use (&$log): void { $log[] = 'a'; });
        addAction('probe', static function () use (&$log): void { $log[] = 'b'; });

        doAction('probe');

        self::assertSame(['a', 'b'], $log);
    }

    public function testАргументыДоходятДоОбработчика(): void
    {
        $seen = [];
        addAction('probe', static function (string $first, int $second) use (&$seen): void { $seen = [$first, $second]; });

        doAction('probe', 'значение', 7);

        self::assertSame(['значение', 7], $seen);
    }

    public function testФильтрОбязанВернутьЗначение(): void
    {
        addFilter('title', static fn (string $value): string => $value . ' — школа');
        addFilter('title', static fn (string $value): string => mb_strtoupper($value));

        self::assertSame('НОВОСТИ — ШКОЛА', applyFilters('title', 'Новости'));
    }

    public function testФильтрБезОбработчиковВозвращаетИсходное(): void
    {
        self::assertSame('как есть', applyFilters('пустой_хук', 'как есть'));
    }

    public function testОдинХукМожноЗарегистрироватьНесколькоРаз(): void
    {
        $count = 0;
        $bump = static function () use (&$count): void { $count++; };
        addAction('probe', $bump);
        addAction('probe', $bump);

        doAction('probe');

        self::assertSame(2, $count);
        self::assertSame(2, hookCount('probe'));
    }

    public function testНезарегистрированноеДействиеНеПадает(): void
    {
        doAction('такого_хука_нет');
        self::assertSame(0, hookCount('такого_хука_нет'));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter HooksTest`
Expected: FAIL — `addAction()` не определена.

- [ ] **Шаг 2: Реализуем хуки**

`core/hooks.php`:

```php
<?php

declare(strict_types=1);

// Точки расширения ядра. Приложение не правит core/: оно подписывается на хук. Порядок — по
// приоритету, внутри приоритета — по порядку регистрации (PHP не гарантирует устойчивость
// sort(), поэтому сортируем парой «приоритет + счётчик»).
function &hookRegistry(): array
{
    static $hooks = [];

    return $hooks;
}

function hookCounter(): int
{
    static $counter = 0;

    return ++$counter;
}

function addHook(string $hook, callable $fn, int $priority, string $kind): void
{
    $registry = &hookRegistry();
    $registry[$hook][] = ['fn' => $fn, 'priority' => $priority, 'kind' => $kind, 'order' => hookCounter()];
    usort($registry[$hook], static fn (array $a, array $b): int => [$a['priority'], $a['order']] <=> [$b['priority'], $b['order']]);
}

function addAction(string $hook, callable $fn, int $priority = 10): void
{
    addHook($hook, $fn, $priority, 'action');
}

function addFilter(string $hook, callable $fn, int $priority = 10): void
{
    addHook($hook, $fn, $priority, 'filter');
}

function doAction(string $hook, mixed ...$args): void
{
    $registry = &hookRegistry();
    foreach ($registry[$hook] ?? [] as $entry) {
        if ($entry['kind'] !== 'action') {
            continue;
        }
        ($entry['fn'])(...$args);
    }
}

function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
{
    $registry = &hookRegistry();
    foreach ($registry[$hook] ?? [] as $entry) {
        if ($entry['kind'] !== 'filter') {
            continue;
        }
        $value = ($entry['fn'])($value, ...$args);
    }

    return $value;
}

function hookCount(string $hook): int
{
    $registry = &hookRegistry();

    return count($registry[$hook] ?? []);
}

// Тесты подписываются на один и тот же хук десятки раз: без сброса они вызывали бы друг друга.
function hooksReset(): void
{
    $registry = &hookRegistry();
    $registry = [];
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter HooksTest`
Expected: PASS (7/7).

- [ ] **Шаг 3: Пишем падающие тесты шаблонов**

`tests/unit/TemplateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/template.php';

final class TemplateTest extends TestCase
{
    protected function setUp(): void
    {
        // Хук page_head общий для всего прогона: без сброса подписка этого теста утекла бы в другие.
        hooksReset();
    }

    public function testИерархияШаблоновБерётФайлПриложенияПервым(): void
    {
        // Цепочка из спецификации §5.6: приложение → тема → ядро → 404.
        self::assertStringEndsWith('apps/news/templates/feed.php', str_replace('\\', '/', resolveTemplate('news/feed')));
    }

    public function testТемаПодхватываетсяКогдаШаблонаПриложенияНет(): void
    {
        self::assertStringEndsWith('tests/fixtures/theme/news/probe-theme.php', str_replace('\\', '/', resolveTemplate('news/probe-theme')));
    }

    public function testНесуществующийШаблонУходитВЧетырестаЧетыре(): void
    {
        self::assertStringEndsWith('theme/404.php', str_replace('\\', '/', resolveTemplate('news/несуществующий')));
    }

    public function testPartialОтдаётСтрокуАНеПечатаетЕё(): void
    {
        $html = partial('parts/probe', ['name' => '<кот>']);

        self::assertStringContainsString('Привет, &lt;кот&gt;', $html);
        self::assertStringNotContainsString('<кот>', $html);
    }

    public function testВыделениеЗвёздочкамиСтановитсяЖирным(): void
    {
        self::assertSame('обычный <strong>важный</strong> текст', richText('обычный **важный** текст'));
    }

    public function testВыделениеЭкранируетРазметкуВокруг(): void
    {
        self::assertSame('&lt;b&gt;и <strong>жирное</strong>&lt;/b&gt;', richText('<b>и **жирное**</b>'));
    }

    public function testНезакрытыеЗвёздочкиОстаютсяТекстом(): void
    {
        self::assertSame('половина ** не выделение', richText('половина ** не выделение'));
    }

    public function testПереносыСтрокСтановятсяАбзацами(): void
    {
        self::assertSame('первая<br>вторая', richText("первая\nвторая"));
    }

    public function testHeadСтраницыДополняетВыводХукаВРазметкеШапки(): void
    {
        // Разметка из хука page_head и значение $page['head'] обязаны попасть в <head> сырьём;
        // порядок фиксирован: сначала хук, затем значение страницы (каскад читает их в этом порядке).
        addAction('page_head', static function (): void {
            echo '<meta name="generator" content="cms">';
        });

        ob_start();
        render('home', [], [
            'title' => 'Проверка',
            'head' => '<meta property="og:type" content="website">',
        ]);
        $html = (string) ob_get_clean();

        $hook = strpos($html, '<meta name="generator" content="cms">');
        $page = strpos($html, '<meta property="og:type" content="website">');

        self::assertNotFalse($page, 'Значение $page[head] обязано появиться в разметке');
        self::assertNotFalse($hook, 'Вывод хука page_head обязан появиться в разметке');
        self::assertLessThan((int) $page, (int) $hook, 'Хук обязан идти раньше значения $page[head]');
    }
}
```

Для теста нужны два probe-файла (создаются в этом же шаге и остаются в репозитории как фикстуры темы):

`tests/fixtures/theme/news/probe-theme.php`:

```php
<?php

declare(strict_types=1);

/* @safe html */
echo '<article>переопределение темы</article>';
```

`tests/fixtures/theme/parts/probe.php`:

```php
<?php

declare(strict_types=1);

echo 'Привет, ' . e($name);
```

`apps/news/templates/feed.php` появится в задаче 16; чтобы тест не зависел от будущего файла, создайте
заглушку сейчас:

```php
<?php

declare(strict_types=1);

// Лента новостей. Вёрстка придёт в задаче 16; файл создан, чтобы иерархия шаблонов проверялась.
echo '<section class="news-feed"></section>';
```

`resolveTemplate()` ищет тему в двух корнях: обычный `theme/` и каталог из `EXTRA_THEME_DIR` (его подставляют
тесты, чтобы проверить цепочку, не создавая файлов в настоящей теме).

- [ ] **Шаг 4: Реализуем шаблоны**

`core/template.php`:

```php
<?php

declare(strict_types=1);

// Иерархия шаблонов из спецификации §5.6: приложение → тема → ядро → 404. Тема добавляет вёрстку,
// не трогая код приложения; каталог темы можно перекрыть настройкой EXTRA_THEME_DIR (её
// используют тесты, чтобы не создавать файлы в настоящей теме).
function themeRoots(): array
{
    $root = dirname(__DIR__);
    $extra = (string) configValue('EXTRA_THEME_DIR', '');

    return $extra !== '' ? [$extra, $root . '/theme'] : [$root . '/theme'];
}

function resolveTemplate(string $template): string
{
    // «..» и обратный слэш вырезаются: имя шаблона приходит из кода, но лишний барьер не мешает.
    $name = str_replace(['..', '\\'], '', $template);
    $parts = explode('/', $name);
    $root = dirname(__DIR__);

    $candidates = [];
    if (count($parts) >= 2) {
        $candidates[] = $root . '/apps/' . $parts[0] . '/templates/' . implode('/', array_slice($parts, 1)) . '.php';
    }
    foreach (themeRoots() as $theme) {
        $candidates[] = rtrim($theme, '/') . '/' . $name . '.php';
    }
    $candidates[] = $root . '/core/templates/' . $name . '.php';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $root . '/theme/404.php';
}

// partial() возвращает строку: её можно собрать в переменную и передать в layout, не смешивая
// вывод с данными.
function partial(string $template, array $data = []): string
{
    $file = resolveTemplate($template);

    ob_start();
    (static function (string $__file, array $__scope): void {
        /** @var array<string, mixed> $__scope */
        extract($__scope, EXTR_SKIP);
        require $__file;
    })($file, $data);

    return (string) ob_get_clean();
}

// render() печатает страницу целиком: шаблон внутри layout, метаданные из $page.
function render(string $template, array $data = [], array $page = []): void
{
    $content = partial($template, $data);

    $title = (string) ($page['title'] ?? (string) configValue('SITE_NAME', 'Сайт'));
    $description = (string) ($page['description'] ?? '');
    $noindex = (bool) ($page['noindex'] ?? false);

    ob_start();
    doAction('page_head');
    // Порядок: сначала вывод хука page_head, затем значение $page['head'] — читающий каскад видит их в этом порядке.
    // $page['head'] — сырой HTML по тому же правилу, что и вывод хука: его передаёт только первичный код
    // (ядро и приложения), никогда пользовательский ввод.
    $head = (string) ob_get_clean() . (string) ($page['head'] ?? '');

    ob_start();
    doAction('page_footer');
    $footer = (string) ob_get_clean();

    require dirname(__DIR__) . '/theme/layout.php';
}

// Разметка абзаца: сырого HTML в материале нет, выделение задаётся **звёздочками**.
// Возвращает строку, которую шаблон печатает с пометкой /* @safe html */.
function richText(string $text): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $out = [];

    foreach (explode("\n", $normalized) as $line) {
        $out[] = (string) preg_replace_callback(
            '/\*\*(.+?)\*\*/u',
            static fn (array $m): string => '<strong>' . $m[1] . '</strong>',
            e($line)
        );
    }

    return implode('<br>', $out);
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter TemplateTest`
Expected: PASS (8/8). Каталоги фикстур подхватываются потому, что `tests/bootstrap.php` (задача 3) выставляет
`CMS_EXTRA_THEME_DIR` до подключения ядра.

**Подключение ядром — в этом же шаге.** `core/bootstrap.php` дополняется ровно двумя строками после
`require_once __DIR__ . '/id.php';`:

```php
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/template.php';
```

Ни раньше, ни позже: `render()` нужен уже `core/response.php` из задачи 5, а до этой задачи этих файлов в
списке быть не могло — `require_once` несуществующего файла означает фатальную ошибку на каждом запросе.
После правки `securityHeaders()` и `render()` доступны коду, который подключает `core/bootstrap.php`, а не
только тестам с их собственными `require_once`.

- [ ] **Шаг 5: Тема — layout, шапка, футер, кот, 404, главная**

Сначала — побайтовый перенос картинок. Копировать нужно на хосте: контейнер `php` видит только каталог
`cms/`, путь `../english-portal` из него недоступен. Проверка совпадения — по хешу, а не по размеру:

```bash
cp ../english-portal/public/cats/cat-happy.png ../english-portal/public/cats/cat-sad.png public/assets/
cp ../english-portal/public/brand/favicon.png public/assets/
md5sum ../english-portal/public/cats/cat-happy.png public/assets/cat-happy.png
md5sum ../english-portal/public/cats/cat-sad.png public/assets/cat-sad.png
md5sum ../english-portal/public/brand/favicon.png public/assets/favicon.png
```

Expected: в каждой паре хеши совпали. Из `english-portal` берётся только то, что лежит в открытом `public/`;
боевая база, `.env` и `config.php` не читаются и не копируются.

`theme/layout.php`:

```php
<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $description */
/** @var string $content */
/** @var string $head */
/** @var string $footer */
/** @var bool $noindex */
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php if ($description !== ''): ?>
        <meta name="description" content="<?= e($description) ?>">
    <?php endif; ?>
    <?php if ($noindex): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="/assets/favicon.png" type="image/png">
    <?= $head /* @safe html */ ?>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
<a class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:px-4 focus:py-2" href="#main">К содержимому</a>
<?= partial('partials/header') /* @safe html */ ?>
<main id="main" class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">
    <?= $content /* @safe html */ ?>
</main>
<?= partial('partials/footer') /* @safe html */ ?>
<?= $footer /* @safe html */ ?>
</body>
</html>
```

`theme/partials/header.php` — ссылки навигации и логотип обязаны иметь зону нажатия не меньше 44 px
(глобальное требование адаптивности 360…1440), поэтому каждая ссылка — `inline-flex min-h-[44px] items-center`:

```php
<?php

declare(strict_types=1);

$user = function_exists('currentUser') ? currentUser() : null;
?>
<header class="border-b border-ink/10 bg-cream">
    <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
        <a class="flex min-h-[44px] items-center gap-2 text-lg font-bold" href="/">
            <?= partial('partials/cat', ['mood' => 'happy', 'size' => 32]) /* @safe html */ ?>
            <span><?= e((string) configValue('SITE_NAME', 'Школа')) ?></span>
        </a>
        <nav class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <a class="inline-flex min-h-[44px] items-center hover:underline" href="/news">Новости</a>
            <?php if ($user !== null): ?>
                <a class="inline-flex min-h-[44px] items-center hover:underline" href="/admin">Админка</a>
            <?php else: ?>
                <a class="inline-flex min-h-[44px] items-center hover:underline" href="/login">Вход</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
```

`theme/partials/footer.php`:

```php
<?php

declare(strict_types=1);
?>
<footer class="mt-12 border-t border-ink/10 bg-cream">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-8 text-sm sm:flex-row sm:items-center sm:px-6">
        <p><?= e((string) configValue('SITE_NAME', 'Школа')) ?> — новости, курсы и объявления школы.</p>
        <nav class="flex flex-wrap gap-x-4 gap-y-2 sm:ml-auto">
            <a class="hover:underline" href="/news">Новости</a>
            <a class="hover:underline" href="/sitemap.xml">Карта сайта</a>
        </nav>
    </div>
    <div class="pb-6 text-center text-xs text-ink/60">Сделано с котом и заботой о языке.</div>
</footer>
```

`theme/partials/cat.php` — талисман; PNG копируются побайтово из `english-portal` (файлы
`cat-happy.png` и `cat-sad.png` лежат в `../english-portal/public/cats/`). Векторных версий нет, поэтому
файлы растровые. Атрибута `loading="lazy"` на `<img>` нет: талисман стоит в шапке над основным содержимым
и попадает в LCP, а ленивая загрузка откладывает именно его. Шаблон только выбирает картинку:

```php
<?php

declare(strict_types=1);

/** @var string $mood */
/** @var int $size */
$file = ($mood ?? 'happy') === 'sad' ? 'cat-sad.png' : 'cat-happy.png';
$size = (int) ($size ?? 40);
?>
<img src="/assets/<?= e($file) ?>" width="<?= e((string) $size) ?>" height="<?= e((string) $size) ?>" alt="Кот-талисман школы">
```

`theme/404.php`:

```php
<?php

declare(strict_types=1);
?>
<section class="py-12 text-center">
    <?= partial('partials/cat', ['mood' => 'sad', 'size' => 96]) /* @safe html */ ?>
    <h1 class="mt-6 text-3xl font-bold">Страницы нет</h1>
    <p class="mt-3 text-ink/70">Адрес мог измениться или в нём опечатка. Загляните в ленту новостей — возможно, материал переехал.</p>
    <p class="mt-6 flex flex-wrap justify-center gap-3">
        <a class="rounded-lg bg-ink px-4 py-3 text-cream hover:opacity-90" href="/">На главную</a>
        <a class="rounded-lg border border-ink/20 px-4 py-3 hover:bg-cream" href="/news">К новостям</a>
    </p>
</section>
```

`theme/home.php`:

```php
<?php

declare(strict_types=1);
?>
<section class="py-8">
    <h1 class="text-3xl font-bold"><?= e((string) configValue('SITE_NAME', 'Школа')) ?></h1>
    <p class="mt-3 max-w-2xl text-ink/70">
        Сайт собирается на собственной CMS: ядро отвечает за маршруты, доступ и обновления, а разделы живут
        в приложениях.
    </p>
    <p class="mt-6"><a class="rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="/news">Читать новости</a></p>
</section>
```

- [ ] **Шаг 6: Стили — Tailwind собирается локально**

`package.json`:

```json
{
  "name": "cms-css",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "tailwindcss -i ./src-css/app.css -o ./public/assets/app.css --minify",
    "watch": "tailwindcss -i ./src-css/app.css -o ./public/assets/app.css --watch"
  },
  "devDependencies": {
    "@tailwindcss/cli": "^4.1.0",
    "tailwindcss": "^4.1.0"
  }
}
```

`src-css/app.css` — переносится из `english-portal/src/app/globals.css` (173 строки) с переводом дизайн-токенов в
Tailwind v4 `@theme`:

```css
@import 'tailwindcss';

@theme {
  --color-paper: #fdf6ec;
  --color-cream: #fff9ef;
  --color-ink: #2b2118;
  --color-accent: #d97706;
  --color-good: #16a34a;
  --color-bad: #dc2626;
}

/* Дальше — правила из english-portal/src/app/globals.css без изменений: шрифты, фокус,
   типографика лонгрида, стили таблиц админки. */
```

`scripts/css`:

```bash
#!/usr/bin/env bash
# Сборка стилей. Node нужен только разработчику: на сервер уходит готовый public/assets/app.css.
set -euo pipefail
npm install --no-audit --no-fund
npm run build
echo 'Стили собраны в public/assets/app.css — файл лежит в репозитории.'
```

Run: `bash scripts/css`
Expected: появился `public/assets/app.css`, `git status` показывает его как новый файл (он обязан быть в репозитории).

- [ ] **Шаг 7: Страж вывода**

`tests/guard/OutputEscapingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// XSS в шаблоне — самая частая находка в самописных CMS. Правило: любая переменная печатается
// через e(); исключение — блок, помеченный /* @safe html */, где разметка собрана самим кодом.
final class OutputEscapingTest extends TestCase
{
    public function testШаблоныПечатаютПеременныеТолькоЧерезE(): void
    {
        $violations = [];

        foreach ($this->templates() as $file) {
            $code = $this->withoutSafeBlocks((string) file_get_contents($file));

            /* `\s*+` — притяжательный квантификатор, и это не украшение. Обычный `\s*` отдаёт пробел
            по одному символу, отрицательный lookahead срабатывает на позиции пробела, и законное
            `<?= e($title) ?>` попадало в находки: страж краснел на правильно написанных шаблонах
            и оставался зелёным только на сломанных. У второго условия той же ошибки нет — там
            группа начинается с `\$`, поэтому откат на пробел не приводит к ложному совпадению.
            Комментарий многострочный: `?>` внутри однострочного комментария закрыл бы PHP-блок. */
            if (preg_match_all('/<\?=\s*+(?!e\(|partial\(|richText\()([^;]*?);?\s*+\?>/s', $code, $matches) > 0) {
                foreach ($matches[1] as $expression) {
                    $violations[] = $this->relative($file) . ': <?= ' . trim($expression) . ' ?>';
                }
            }

            if (preg_match_all('/echo\s+(?!e\(|partial\(|richText\()(\$[a-zA-Z_][^\n;]*)/', $code, $matches) > 0) {
                foreach ($matches[1] as $expression) {
                    $violations[] = $this->relative($file) . ': echo ' . trim($expression);
                }
            }
        }

        self::assertSame([], $violations, 'Вывод без e(): строка обязана проходить экранирование либо блок помечается /* @safe html */');
    }

    public function testПометкаБезопасногоБлокаЕстьТолькоТамГдеСобираетсяРазметка(): void
    {
        // Помеченный блок печатает строку, собранную нашим кодом: переменную, в которую
        // шаблон положил разметку, либо вызов помощника, который сам экранирует каждое значение.
        // Проверка идёт по строке с пометкой, а не по тексту после неё: иначе результат зависел бы
        // от того, что случайно оказалось в следующих 200 символах.
        $allowed = '/(\$content|\$head|\$footer|partial\(|richText\(|csrfField\(|adminShell\()/';

        foreach ($this->templates() as $file) {
            foreach ($this->safeBlocks((string) file_get_contents($file)) as $line) {
                self::assertMatchesRegularExpression(
                    $allowed,
                    $line,
                    $this->relative($file) . ': помеченная строка не собирает разметку — ' . trim($line)
                );
            }
        }
    }

    /** @return list<string> */
    private function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['theme', 'admin', 'apps', 'core/templates'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private function safeBlocks(string $code): array
    {
        $out = [];
        foreach (explode("\n", $code) as $line) {
            if (str_contains($line, '@safe html')) {
                $out[] = $line;
            }
        }

        return $out;
    }

    // Строка с пометкой убирается целиком. Многострочный `echo` при этом теряет только первую
    // строку — ту, на которой стоит `echo`, поэтому continuation-строки под проверку не попадают.
    private function withoutSafeBlocks(string $code): string
    {
        $kept = [];
        foreach (explode("\n", $code) as $line) {
            if (!str_contains($line, '@safe html')) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite guard`
Expected: PASS. Если страж указывает на `<?= $head ?>` — пометьте строку комментарием `/* @safe html */`
(это разметка, собранная хуками, а не данные пользователя).

- [ ] **Шаг 8: Ворота и коммит**

Run: `bash scripts/gate.sh`
Expected: всё зелёное; PHPStan не ругается на `extract()` в `partial()` (для него добавьте над строкой
`/** @var array<string, mixed> $data */`).

```bash
git add core/ theme/ apps/ tests/ tailwind.config.js postcss.config.js package.json package-lock.json \
        src-css/ scripts/css public/assets/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Хуки, иерархия шаблонов и тема: layout, 404, кот и страж экранирования вывода"
```

---

### Задача 5: Запрос, ответ и маршрутизатор

**Files:**
- Create: `core/request.php`, `core/response.php`, `core/router.php`
- Modify: `public/index.php`, `core/bootstrap.php` (три строки `require_once` — шаг 3)
- Create: `tests/unit/RouterTest.php`, `tests/unit/RequestTest.php`, `tests/http/Kernel.php`,
  `tests/http/NotFoundTest.php`

**Interfaces:**
- Consumes: `render()`, `partial()` из задачи 4; `logError()` из задачи 2.
- Produces:
  - `requestMethod(): string`, `requestPath(): string`, `queryString(string $key, ?string $default = null): ?string`,
    `postString(string $key): string`, `postAll(): array`, `uploadedFile(string $key): ?array`,
    `clientIp(): string`, `isPost(): bool`;
  - `respond(int $status, string $body, array $headers = []): never`, `redirect(string $to, int $status = 302): never`,
    `notFound(string $message = ''): never`, `forbidden(string $message = ''): never`,
    `redirectWithNotice(string $to, string $notice): never`;
  - `addRoute(string $method, string $pattern, string|Closure $handler, array $meta = []): void`,
    `matchRoute(string $method, string $path): ?array`, `routeTable(): array`, `routesReset(): void`,
    `dispatch(): void`;
  - `$meta` — `array{app?: string, private?: bool, permission?: string}`;
  - `Cms\Tests\http\Kernel::start()`, `::stop()`, `::get(string $path, array $options = [])`,
    `::post(string $path, array $fields, array $options = [])` — каждый возвращает
    `array{status: int, body: string, headers: array<string, string>, redirects: list<string>}`.

- [ ] **Шаг 1: Пишем падающие тесты маршрутизатора**

`tests/unit/RouterTest.php`:

```php
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

    public function testНекорректныйШаблонПадаетПриРегистрации(): void
    {
        // «#» — наш разделитель регулярки: в шаблоне он рвёт паттерн, и без проверки preg_match
        // молча вернул бы false — маршрут исчез бы без ошибки. Регистрация обязана упасть громко.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('/news#blog');

        addRoute('GET', '/news#blog', 'newsFeed');
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter RouterTest`
Expected: FAIL — `addRoute()` не определена.

- [ ] **Шаг 2: Реализуем маршрутизатор**

`core/router.php`:

```php
<?php

declare(strict_types=1);

// Таблица маршрутов собирается каждый запрос: ядро регистрирует свои, затем приложения по
// алфавиту id. Статические сегменты (/news/admin/items) обязаны идти раньше параметров
// (/news/{slug}) — иначе новость с адресом «admin» спрятала бы админку.
function &routeRegistry(): array
{
    static $routes = [];

    return $routes;
}

/**
 * Собирает регулярку маршрута из нормализованного шаблона: сегменты {имя} становятся именованными
 * группами «[^/]+», прочие символы уходят в паттерн как есть. Общий код для регистрации (где проверка
 * на валидность) и сопоставления — иначе два места могли бы расходиться и собирать разные регулярки.
 */
function routeRegex(string $pattern): string
{
    return '#^' . preg_replace_callback(
        '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
        static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
        $pattern
    ) . '$#u';
}

/** @param array<string, mixed> $meta */
function addRoute(string $method, string $pattern, string|Closure $handler, array $meta = []): void
{
    $normalized = '/' . trim($pattern, '/');

    // Шаблон пишет разработчик, но опечатка в спецсимволе — «#» у нас разделитель — рвёт паттерн:
    // preg_match тогда молча вернул бы false, и маршрут исчез бы без единой ошибки. Собираем регулярку
    // и проверяем её на регистрации, а не на сопоставлении, чтобы упасть громко и назвать виновника.
    // Предупреждение PCRE гасим: о невалидности сигналят false и preg_last_error(), а не шум в выводе.
    if (@preg_match(routeRegex($normalized), '') === false && preg_last_error() !== PREG_NO_ERROR) {
        throw new \LogicException(sprintf('Некорректный шаблон маршрута «%s»: регулярка не собралась.', $normalized));
    }

    $registry = &routeRegistry();
    $registry[] = [
        'method' => strtoupper($method),
        'pattern' => $normalized,
        'handler' => $handler,
        'meta' => $meta,
        'static' => !str_contains($pattern, '{'),
    ];
}

/** @return list<array{method: string, pattern: string, handler: string|Closure, meta: array<string, mixed>, static: bool}> */
function routeTable(): array
{
    $registry = &routeRegistry();

    return $registry;
}

function routesReset(): void
{
    $registry = &routeRegistry();
    $registry = [];
}

/** @return array{handler: string|Closure, params: array<string, string>, meta: array<string, mixed>}|null */
function matchRoute(string $method, string $path): ?array
{
    $clean = '/' . trim(strtok($path, '?') ?: '', '/');
    $method = strtoupper($method);

    // Точные пути идут раньше параметрических: иначе /news/admin/items попал бы в /news/{slug}.
    $registry = &routeRegistry();
    usort($registry, static fn (array $a, array $b): int => ($b['static'] ? 1 : 0) <=> ($a['static'] ? 1 : 0));

    foreach ($registry as $route) {
        if ($route['method'] !== $method) {
            continue;
        }

        // Шаблон пишется в коде ядра или приложения, а не приходит из запроса, поэтому подстановка
        // сегментов в регулярку безопасна: значение {slug} ограничено «[^/]+». Регулярку собирает тот
        // же routeRegex, что и проверка на регистрации, — сопоставление видит ровно тот паттерн, что одобрен.
        $regex = routeRegex($route['pattern']);

        if (preg_match($regex, $clean, $matches) === 1) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = (string) $value;
                }
            }

            return ['handler' => $route['handler'], 'params' => $params, 'meta' => $route['meta']];
        }
    }

    return null;
}

// Единственное место, где маршрут превращается в вызов. Гейты идут до действия, а не внутри него:
// приватный путь обязан отвечать 403, даже если обработчик забыл проверить право.
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

    if (($match['meta']['private'] ?? false) === true) {
        $access = getEditorAccess();
        if ($access['kind'] !== 'granted') {
            if ($method === 'GET' && ($match['meta']['permission'] ?? 'editor') === 'login') {
                redirect('/login?next=' . rawurlencode(requestPath()));
            }
            forbidden();
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

`isAppActive()` и `getEditorAccess()` появятся в задачах 8 и 6; до них `dispatch()` вызывается только с
маршрутами без метки `private` и без `app`. Тесты этой задачи проверяют `addRoute`/`matchRoute` напрямую,
поэтому им эти функции не нужны.

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter RouterTest`
Expected: PASS (9/9).

- [ ] **Шаг 3: Запрос и ответ**

`core/request.php`:

Одно обязательное ограничение к тексту этого файла: название `REMOTE_ADDR` встречается в нём ровно один раз —
в коде. Комментарий, повторяющий его, сломает страж `ClientIpTest` из задачи 6, который считает вхождения
по всему исходнику; поэтому в комментарии оно написано словами («адрес клиента из серверного массива»). По той
же причине в файле нет ни одного названия подставляемых заголовков — тот же страж считывает их как находки.

```php
<?php

declare(strict_types=1);

// Адрес клиента берём только из серверного: его подделать запросом нельзя. Заголовки вида «кто меня
// проксировал» подставляет кто угодно, и лимит входа, прочитанный из них, обходится одной строкой в
// curl. Названий этих заголовков здесь нет намеренно: страж ClientIpTest читает исходники как текст и
// считает их находками, а адрес клиента из серверного массива обязан встречаться в этом файле ровно один раз.
function clientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

function requestMethod(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function isPost(): bool
{
    return requestMethod() === 'POST';
}

function requestPath(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

    return '/' . trim(strtok($uri, '?') ?: '/', '/');
}

function queryString(?string $key = null, ?string $default = null): mixed
{
    if ($key === null) {
        return $_GET;
    }

    $value = $_GET[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

/** @return array<string, mixed> */
function postAll(): array
{
    return $_POST;
}

function uploadedFile(string $key): ?array
{
    $file = $_FILES[$key] ?? null;

    return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
}

function isHttps(): bool
{
    return (($_SERVER['HTTPS'] ?? '') === 'on') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}
```

`core/response.php`:

```php
<?php

declare(strict_types=1);

function respond(int $status, string $body, array $headers = []): never
{
    if (!headers_sent()) {
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    echo $body;
    exit;
}

function redirect(string $to, int $status = 302): never
{
    respond($status, '', ['Location' => $to]);
}

// PRG: после действия человек видит GET-страницу, а не повторную отправку формы при обновлении.
function redirectWithNotice(string $to, string $notice): never
{
    $separator = str_contains($to, '?') ? '&' : '?';
    redirect($to . $separator . 'notice=' . rawurlencode($notice));
}

function notFound(string $message = ''): never
{
    if (!headers_sent()) {
        http_response_code(404);
    }
    render('404', ['message' => $message], ['title' => 'Страницы нет', 'noindex' => true]);
    exit;
}

function forbidden(string $message = ''): never
{
    logWarn('access.forbidden', ['path' => requestPath(), 'ip' => clientIp()]);
    respond(
        403,
        partial('403', ['message' => $message]),
        ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow']
    );
}
```

`theme/403.php`:

```php
<?php

declare(strict_types=1);

/** @var string $message */
?>
<section class="py-12 text-center">
    <?= partial('partials/cat', ['mood' => 'sad', 'size' => 96]) /* @safe html */ ?>
    <h1 class="mt-6 text-3xl font-bold">Сюда нельзя</h1>
    <p class="mt-3 text-ink/70">
        <?= e($message !== '' ? $message : 'Раздел открыт только редактору и администратору.') ?>
    </p>
    <p class="mt-6"><a class="rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="/login">Войти</a></p>
</section>
```

`core/bootstrap.php` дополняется тремя строками сразу после `require_once __DIR__ . '/template.php';`. Без них
`public/index.php` не знает ни `addRoute()`, ни `dispatch()`, и HTTP-проверки этого шага падали бы с
«Call to undefined function» — юнит-тесты этого не заметят, у каждого из них свой `require_once`:

```php
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/router.php';
```

`public/index.php` — вариант этой задачи. Пропуск статики под `php -S` нужен уже здесь, а вызовы `startSession()`
и `registerCoreRoutes()` появятся в задаче 6, `registerAppRoutes()` — в задаче 8:

```php
<?php

declare(strict_types=1);

// Встроенный сервер PHP отдаёт существующие файлы сам, только если сценарий-маршрутизатор
// вернул false. Без этой строки под `php -S` ни `/assets/app.css`, ни `/setup.php` не открылись бы:
// каждый запрос уходил бы в маршрутизатор и заканчивался 404. На Apache то же делает правило
// `RewriteCond %{REQUEST_FILENAME} !-f` в `public/.htaccess`.
if (PHP_SAPI === 'cli-server') {
    $requested = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $file = __DIR__ . $requested;
    // Замок установщика — не раздача: он обязан отвечать 404, а не скачиваться.
    if ($requested !== '/' && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'lock') {
        return false;
    }
}

require dirname(__DIR__) . '/core/bootstrap.php';

securityHeaders();

addRoute('GET', '/', static function (): void {
    render('home', [], ['title' => (string) configValue('SITE_NAME', 'Школа'), 'description' => 'Сайт школы английского языка']);
});

dispatch();
```

К концу задачи 6 файл обязан выглядеть так (это тот же блок минус временная домашняя маршрут-строка, плюс сессия
и маршруты ядра; порядок вызовов фиксирован — сессия до маршрутов, иначе обработчик `/login` не увидит `$_SESSION`):

```php
require dirname(__DIR__) . '/core/bootstrap.php';

securityHeaders();
startSession();
registerCoreRoutes();
dispatch();
```

Закрытие «/» в этой задаче — временное: с задачи 6 домашнюю страницу регистрирует `registerCoreRoutes()`
(`coreHome()` из задачи 6). Оставить обе строки нельзя: `addRoute()` дописывает маршрут в конец списка, а
`matchRoute()` берёт первое совпадение, поэтому `coreHome()` никогда не вызывался бы, а его тесты молчали бы
о незанесённом в реестр обработчике.

Run: `curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/` и `curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/нет-такой`
Expected: `200` и `404`.

- [ ] **Шаг 4: Помощник HTTP-тестов**

`tests/http/Kernel.php`:

Каталог и неймспейс — со строчной: `Cms\Tests\http` отображается в `tests/http` по тому же PSR-4, что и
остальные наборы. `tests/Http` работал бы на Windows, где регистр не различается, а на Linux-контейнере
и боевом хостинге автозагрузчик искал бы `tests/Http/Kernel.php` и не находил бы — все HTTP-тесты падали
бы с «Class not found». Файл не кончается на `Test.php`, поэтому PHPUnit не принимает его за тест; класс
подхватывает автозагрузка из `autoload-dev`.

`stop()` гасит сервер по `pkill` — этот пакет в образ `php:8.3-cli` добавлен заранее (задача 1, `procps`), потому
что `proc_terminate()` убивает только оболочку, а `php -S` остаётся висеть на порту 8123, и каждый следующий
набор тестов падает на «Address already in use».

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

// Поднимаем настоящий php -S и ходим в него curl-ом: так проверяются статус, редиректы,
// заголовки и куки сессии — всё, что юнит-тест не видит.
final class Kernel
{
    private const HOST = '127.0.0.1';
    private const PORT = 8123;

    // Сколько раз и с каким шагом пробуем порт, прежде чем признать его чужим: освобождение после
    // stop() прошлого класса наступает не в тот же миг, а 10 проб по 0,1 с закрывают эту паузу.
    private const PORT_FREE_PROBES = 10;
    private const PORT_FREE_RETRY_MICROSECONDS = 100000;

    private static ?string $cookieJar = null;

    /** @var resource|null ресурс php -S, который подняли именно мы: им гасит stop() */
    private static $process = null;

    /**
     * @param array<string, string> $env переменные окружения сервера сверх обязательных.
     *        Так тест подставляет `CMS_EXTRA_APPS_DIR` с фикстурами приложений: каталог нужен
     *        только проверкам установки и обновления, а в остальных наборах он лишь добавлял бы
     *        в реестр чужие приложения.
     */
    public static function start(array $env = []): void
    {
        // Порт обязан быть свободен до запуска. Иначе wait() подключится к зависшему серверу прошлого
        // прогона (возможно, с другим окружением), а наш процесс упадёт с «Address already in use» в
        // /dev/null — и проверки молча пойдут не в тот сервер. Освобождение после stop() приходит с
        // задержкой, поэтому порт сначала опрашивается до ~1 секунды, а уже потом падаем громко и
        // называем виновника: чужой слушатель, который не уходит, обязан остаться находкой.
        self::waitUntilPortFree();

        if (self::portBusy()) {
            throw new \RuntimeException(sprintf(
                'Порт %d занят чужим процессом (%s:%d): остановите висящий php -S перед запуском http-набора.',
                self::PORT,
                self::HOST,
                self::PORT
            ));
        }

        $root = dirname(__DIR__, 2);
        self::$cookieJar = tempnam(sys_get_temp_dir(), 'cms-cookies-') ?: '/tmp/cms-cookies';

        $prefix = 'CMS_DB_NAME=cms_test CMS_CONFIG_FILE=' . escapeshellarg($root . '/config.sample.php');
        foreach ($env as $name => $value) {
            $prefix .= ' ' . $name . '=' . escapeshellarg($value);
        }

        $command = sprintf(
            '%s php -S %s:%d -t %s/public %s/public/index.php',
            $prefix,
            self::HOST,
            self::PORT,
            escapeshellarg($root),
            escapeshellarg($root)
        );

        // Дескрипторы задаём позиционной формой ['file', путь, режим]: ассоциативный массив вида
        // ['file' => путь, 'w'] PHP читает как тип дескриптора «w» и падает «not a valid descriptor».
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить php -S');
        }
        self::$process = $process;

        self::wait();
    }

    public static function stop(): void
    {
        // Гасим прежде всего собственный процесс: proc_terminate закрывает того ребёнка, что мы
        // породили, даже если набор упал до готовности сервера. pkill остаётся сверху — он снимает
        // самого php -S (внук оболочки proc_open), которого proc_terminate не всегда добирает.
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }

        exec('pkill -f "php -S ' . self::HOST . ':' . self::PORT . '" 2>/dev/null || true');
        if (self::$cookieJar !== null && is_file(self::$cookieJar)) {
            unlink(self::$cookieJar);
        }
    }

    public static function freshCookies(): void
    {
        if (self::$cookieJar !== null && is_file(self::$cookieJar)) {
            file_put_contents(self::$cookieJar, '');
        }
    }

    /** @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>} */
    public static function get(string $path, array $options = []): array
    {
        return self::request('GET', $path, [], $options);
    }

    /** @param array<string, string> $fields */
    public static function post(string $path, array $fields = [], array $options = []): array
    {
        return self::request('POST', $path, $fields, $options);
    }

    /** @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>} */
    private static function request(string $method, string $path, array $fields, array $options): array
    {
        $url = 'http://' . self::HOST . ':' . self::PORT . $path;
        $handle = curl_init($url);
        $headers = [];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
            CURLOPT_COOKIEJAR => self::$cookieJar,
            CURLOPT_COOKIEFILE => self::$cookieJar,
            CURLOPT_FOLLOWLOCATION => (bool) ($options['follow'] ?? false),
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $fields);
        }

        // Свои заголовки нужны тесту CSRF и тесту «x-forwarded-for не читается».
        $extra = [];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $extra[] = $name . ': ' . $value;
        }
        if ($extra !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $extra);
        }

        $body = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $body, 'headers' => $headers, 'redirects' => []];
    }

    private static function wait(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(200000);
        }

        throw new \RuntimeException('Сервер php -S не поднялся за 10 секунд');
    }

    /** Проба порта до спавна: если на нём уже кто-то слушает, это чужой сервер, а не наш. */
    private static function portBusy(): bool
    {
        $socket = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        return false;
    }

    /**
     * Ждём освобождения порта (до ~1 секунды): между stop() одного http-класса и start() другого
     * слушающий процесс исчезает асинхронно, и одиночная проба ловила собственную гонку.
     * Чужой процесс, который не уходит, цикл не маскирует — порог проб всё равно кончается падением.
     */
    private static function waitUntilPortFree(): void
    {
        for ($attempt = 0; $attempt < self::PORT_FREE_PROBES && self::portBusy(); $attempt++) {
            usleep(self::PORT_FREE_RETRY_MICROSECONDS);
        }
    }
}
```

`tests/http/NotFoundTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\http\Kernel;
use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class NotFoundTest extends TestCase
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

    public function testГлавнаяОткрывается(): void
    {
        $response = Kernel::get('/');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Читать новости', $response['body']);
    }

    public function testНесуществующийАдресОтдаётЧетырестаЧетыре(): void
    {
        $response = Kernel::get('/такой-страницы-нет');

        self::assertSame(404, $response['status']);
        self::assertStringContainsString('Страницы нет', $response['body']);
    }

    public function testЗаголовкиБезопасностиОтдаютсяНаКаждомЗапросе(): void
    {
        $response = Kernel::get('/');

        self::assertSame('nosniff', $response['headers']['x-content-type-options']);
        self::assertSame('SAMEORIGIN', $response['headers']['x-frame-options']);
        self::assertStringContainsString("default-src 'self'", $response['headers']['content-security-policy']);
    }

    public function testСлужебныеКаталогиНедоступныПоHttp(): void
    {
        foreach (['/config.sample.php', '/composer.json', '/core/bootstrap.php', '/sql/0001_core_baseline.sql'] as $path) {
            $response = Kernel::get($path);
            self::assertContains($response['status'], [403, 404], $path . ' обязан быть закрыт');
        }
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite http`
Expected: FAIL на последнем тесте — `php -S` отдаёт файлы из корня проекта, если запускать его с `-t public`,
только из `public/`; проверьте, что `config.sample.php` лежит вне `public/` (он в корне `cms/`, значит недоступен —
и тест зелёный). Если `php -S` отвечает 200 на `/config.sample.php`, каталог указан неверно.

Run: `bash scripts/gate.sh`
Expected: всё зелёное.

- [ ] **Шаг 5: Коммит**

```bash
git add core/ public/ theme/ tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Запрос, ответ и маршрутизатор ядра: гейты, 404, 403 и HTTP-проверки на php -S"
```

---
