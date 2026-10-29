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

/**
 * @param list<mixed> $args
 * @return list<array<string, mixed>>
 */
function all(string $sql, array $args = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($args);

    return $statement->fetchAll();
}

/**
 * @param list<mixed> $args
 * @return array<string, mixed>|null
 */
function one(string $sql, array $args = []): ?array
{
    $statement = db()->prepare($sql);
    $statement->execute($args);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/** @param list<mixed> $args */
function value(string $sql, array $args = []): mixed
{
    $statement = db()->prepare($sql);
    $statement->execute($args);
    $result = $statement->fetchColumn();

    return $result === false ? null : $result;
}

/** @param list<mixed> $args */
function run(string $sql, array $args = []): int
{
    $statement = db()->prepare($sql);
    $statement->execute($args);

    return $statement->rowCount();
}

/**
 * Нарушение уникального ключа записи: два одновременных запроса прошли одну и ту же проверку
 * «свободно, ещё нет строки», и проигравший получил отказ базы — на INSERT (классическая гонка
 * вставки) или на UPDATE (правка заняла чужой адрес). Вызывающий отвечает человеку человеческой
 * причиной, а не HTTP 500 сырого исключения. Имя без «insert» намеренно: помощник применяется
 * и к правкам, и искать его вызовы только у вставок — неверный маршрут чтения.
 */
function duplicateKeyException(Throwable $error): bool
{
    // Только принятие уникального ключа: SQLSTATE 23000 ставит и отказ внешнего ключа
    // (MySQL-код 1452), и он — чужая причина; «адрес занят» на неё не переписывается.
    return $error->getCode() === '23000' && preg_match('/\b1062\b/', $error->getMessage()) === 1;
}

// Вставка собирается из имён колонок: значения всегда уходят плейсхолдерами.
/** @param array<string, mixed> $row */
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
// Возвращаемое значение годится только в запрос, где после `LIKE ?` объявлено снятие `ESCAPE`: без
// объявления при режиме `NO_BACKSLASH_ESCAPES` обратный слэш становится обычным символом шаблона.
function escapeLike(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function inPlaceholders(int $count): string
{
    return $count <= 0 ? '' : implode(', ', array_fill(0, $count, '?'));
}

// Единственный список таблиц базовой схемы ядра. Его читают и признака «сайт установлен»,
// и установщик, поэтому второй перечень условий в коде запрещён.
const CORE_BASELINE_TABLES = ['user', 'login_attempt', 'option', 'app_installation', 'app_migration'];

// Признак «схема ядра на месте»: таблицы проверяются через information_schema, чтобы запрос к
// отсутствующей таблице не превращался в 500 — сам этот запрос обязан работать на пустой базе.
function isSiteInstalled(): bool
{
    /** @var list<string> $names */
    $names = array_map(static fn (string $table): string => t($table), CORE_BASELINE_TABLES);
    $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (' . inPlaceholders(count($names)) . ')';

    return (int) value($sql, $names) === count($names);
}

// Файл миграции — набор самостоятельных предложений, разделённых «;» в конце строки.
// Процедуры и триггеры пилот не использует, поэтому более хитрый разбор не нужен.
// Подстановка префикса таблиц — здесь, в единственном канале «файл → утверждения»: оба
// потребителя (applyApp() и setupApplyBaseline()) читают SQL-файлы только через splitSql().
/** @return list<string> */
function splitSql(string $sql): array
{
    $clean = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = preg_split('/;\s*$/m', $clean) ?: [];
    $prefix = (string) configValue('TABLE_PREFIX', 'tbl_');

    $statements = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));

    return array_map(static fn (string $statement): string => sqlWithTablePrefix($statement, $prefix), $statements);
}

// Литерал «tbl_» в SQL-файлах — единственный способ задать имя таблицы: подстановка обязана его увидеть.
// Заменяем «tbl_» на настроенный префикс только вне одинарных строковых литералов. При префиксе «tbl_»
// это точный no-op. Строковый литерал узнаётся по одинарным кавычкам с учётом «»-экранирования;
// «»-кавычки и бэктики в этом канале идентификаторами не являются (пилот их не использует).
function sqlWithTablePrefix(string $statement, string $prefix): string
{
    if ($prefix === 'tbl_') {
        return $statement;
    }

    $marker = 'tbl_';
    $length = strlen($statement);
    $out = '';
    $i = 0;

    while ($i < $length) {
        $char = $statement[$i];

        // Одинарный кавычный литерал переносим целиком, не трогая «tbl_» внутри него.
        if ($char === "'") {
            $out .= $char;
            $i++;
            while ($i < $length) {
                if ($statement[$i] === "'") {
                    $out .= "'";
                    $i++;
                    if ($i < $length && $statement[$i] === "'") {
                        $out .= "'";
                        $i++;

                        continue;
                    }

                    break;
                }
                $out .= $statement[$i];
                $i++;
            }

            continue;
        }

        if (substr($statement, $i, strlen($marker)) === $marker) {
            $out .= $prefix;
            $i += strlen($marker);

            continue;
        }

        $out .= $char;
        $i++;
    }

    return $out;
}
