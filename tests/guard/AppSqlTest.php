<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use function basename;
use function glob;

use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;
use function splitSql;

final class AppSqlTest extends TestCase
{
    // Список короткий намеренно: в нём только оконные и служебные слова MySQL 8.0, а не весь перечень ключевых слов документации — ядро собирает имена колонок без кавычек, и именно эти слова сервер в них отвергает.
    private const MYSQL_WINDOW_AND_INTERNAL_NAMES = [
        'LEAD', 'LAG', 'RANK', 'DENSE_RANK', 'ROW_NUMBER', 'PERCENT_RANK', 'CUME_DIST', 'NTILE',
        'FIRST_VALUE', 'LAST_VALUE', 'NTH_VALUE', 'GROUPS', 'ROWS', 'RECURSIVE', 'LATERAL',
        'WINDOW', 'OVER', 'SYSTEM', 'ARCHIVE', 'GROUPING',
    ];

    public function testКаждоеСозданиеТаблицыДопускаетПовторныйПрогон(): void
    {
        $violations = [];

        foreach ($this->sqlFiles() as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all('/CREATE\s+TABLE(\s+IF\s+NOT\s+EXISTS)?/i', $sql, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (trim((string) ($match[1] ?? '')) === '') {
                    $violations[] = $file . ' — CREATE TABLE без IF NOT EXISTS (отклонение 9)';
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testИменаРелизовЧитаютсяЖурналом(): void
    {
        $violations = [];

        // Имена проверяются только у файлов приложений: имя базового релиза ядра зафиксировано
        // установщиком — он читает файл по этому пути и сам вписывает его имя в журнал.
        foreach ($this->appSqlFiles() as $file) {
            if (preg_match('/^\d{8}_\d{4}_[a-z0-9_]+\.sql$/', basename($file)) !== 1) {
                $violations[] = $file;
            }
        }

        self::assertSame([], $violations);
    }

    public function testИндексыИВнешниеКлючиНосятИмена(): void
    {
        $violations = [];

        foreach ($this->sqlFiles() as $file) {
            $sql = (string) file_get_contents($file);

            if (preg_match('/^\s*KEY\s*\(/mi', $sql) === 1) {
                $violations[] = $file . ' — KEY без имени';
            }

            if (preg_match('/^\s*FOREIGN\s+KEY\s*\(/mi', $sql) === 1) {
                $violations[] = $file . ' — FOREIGN KEY без CONSTRAINT: имя нужно для читаемого отказа';
            }
        }

        self::assertSame([], $violations);
    }

    public function testИменаКолонокНеСовпадаютСЗарезервированнымиСловами(): void
    {
        $violations = [];

        foreach ($this->sqlFiles() as $file) {
            $sql = (string) file_get_contents($file);

            foreach ($this->createTableBodies($sql) as $body) {
                foreach ($this->columnNames($body) as $column) {
                    if (in_array(strtoupper($column), self::MYSQL_WINDOW_AND_INTERNAL_NAMES, true)) {
                        $violations[] = $file . ' — колонка ' . $column . ' названа зарезервированным именем MySQL 8.0';
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    // Таблица без клаузы `ENGINE=InnoDB` создаётся движком по умолчанию всё равно на InnoDB, поэтому
    // information_schema-проверка набора `db` её не поймает. Явная клауза в каждом релизе обязана
    // быть: иначе следующий релиз мог бы уйти от проверки имён колонок, просто убрав слово ENGINE.
    public function testКаждоеСозданиеТаблицыПишетДвижокИКодировку(): void
    {
        $violations = [];

        foreach ($this->sqlFiles() as $file) {
            $sql = (string) file_get_contents($file);

            foreach ($this->createTableStatements($sql) as $statement) {
                if (strpos($statement, 'ENGINE=InnoDB') === false
                    || strpos($statement, 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === false
                ) {
                    $violations[] = $file . ' — CREATE TABLE без клаузы ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci (регистр — как в релизах раздела)';
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * Релизы схемы на проверку содержимого: и приложения, и базовая схема ядра. Правила
     * одинаковые — установщик исполняет файл ядра теми же утверждениями, что журнал исполняет
     * файлы раздела, поэтому `CREATE TABLE` без `IF NOT EXISTS` в любом из них ломает повторный
     * прогон установки.
     *
     * @return list<string>
     */
    private function sqlFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        // Каждый узор обязан отдать хотя бы один файл: пустой список миграций означает, что
        // каталог уехал, и страж без этой строки проверял бы ноль файлов молча. Число файлов
        // не закрепляется — его прибавляет каждый релиз схемы.
        foreach (['sql/*.sql', 'apps/*/sql/*.sql'] as $pattern) {
            $found = glob($root . '/' . $pattern) ?: [];
            self::assertNotEmpty($found, 'Обход ' . $pattern . ' не отдал ни одного SQL-файла — каталог миграций исчез');
            foreach ($found as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function appSqlFiles(): array
    {
        $root = dirname(__DIR__, 2);

        // Порог непустоты: пустой ответ `glob` означает исчезнувший каталог миграций раздела,
        // а не «проверять нечего». Число файлов не закрепляется — его прибавляет каждый релиз схемы.
        $files = glob($root . '/apps/*/sql/*.sql') ?: [];
        self::assertNotEmpty($files, 'Обход apps/*/sql/*.sql не отдал ни одного SQL-файла — каталог миграций исчез');

        sort($files);

        return $files;
    }

    // Утверждения CREATE TABLE читаются из тех же утверждений, что строит установщик: splitSql()
    // из `core/db.php` — единственный канал «файл → утверждения», поэтому страж видит ровно то,
    // что исполнится, включая таблицы без клаузы ENGINE и тела, записанные одной строкой.
    /** @return list<string> */
    private function createTableStatements(string $sql): array
    {
        $statements = [];

        foreach (splitSql($sql) as $statement) {
            if (preg_match('/^\s*CREATE\s+TABLE/i', $statement) === 1) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    // Тело таблицы — между первой открывающей и последней закрывающей скобкой утверждения:
    // границей служит конец утверждения, потому что splitSql() отсекает `;`, а клауза ENGINE=
    // идёт после тела и своих скобок не содержит.
    /** @return list<string> */
    private function createTableBodies(string $sql): array
    {
        $bodies = [];

        foreach ($this->createTableStatements($sql) as $statement) {
            if (preg_match('/\((.*)\)/s', $statement, $match) === 1) {
                $bodies[] = $match[1];
            }
        }

        return $bodies;
    }

    // Имя столбца — первое слово определения; бэктики вокруг имени не спасают: ядро собирает
    // `insert()` без кавычек, и сервер отвергает то же слово в обеих формах. Строки регистраций
    // (`PRIMARY`, `UNIQUE`, `KEY`, `CONSTRAINT`, `FOREIGN`, `INDEX`, `CHECK`) отсекаются по
    // первому слову: под проверкой только имена колонок.
    /** @return list<string> */
    private function columnNames(string $tableBody): array
    {
        $names = [];

        foreach ($this->splitDefinitions($tableBody) as $definition) {
            if (preg_match('/^\s*`?([a-z][a-z0-9_]*)`?\s+[a-z]/i', $definition, $match) !== 1) {
                continue;
            }

            if (in_array(strtoupper($match[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'FOREIGN', 'INDEX', 'CHECK'], true)) {
                continue;
            }

            $names[] = $match[1];
        }

        return $names;
    }

    // Разбор тела по запятым на глубине 0, а не по переносам строк: однострочное тело вида
    // `a INT, lead VARCHAR(10)` даёт все имена, скобки регистраций (`PRIMARY KEY (id)`,
    // `FOREIGN KEY (…) REFERENCES … (…)`) определение не режут, содержимое строковых литералов
    // (`DEFAULT 'DRAFT'`) — тоже.
    /** @return list<string> */
    private function splitDefinitions(string $tableBody): array
    {
        $definitions = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $length = strlen($tableBody);

        for ($i = 0; $i < $length; $i++) {
            $char = $tableBody[$i];

            if ($inString) {
                $current .= $char;

                if ($char === "'") {
                    // Двойная одинарная кавычка — экранированный символ внутри литерала, тот же
                    // приём, что узнаёт `sqlWithTablePrefix()` в канале установщика.
                    if ($i + 1 < $length && $tableBody[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                    } else {
                        $inString = false;
                    }
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $definitions[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $definitions[] = $current;

        return $definitions;
    }
}
