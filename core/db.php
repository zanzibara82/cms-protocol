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
