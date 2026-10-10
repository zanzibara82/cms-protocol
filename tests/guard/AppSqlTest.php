<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use function basename;
use function glob;

use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;

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

        foreach ($this->sqlFiles() as $file) {
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
            preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\S+\s*\((.*?)\)\s*ENGINE/is',
                $sql,
                $blocks,
                PREG_SET_ORDER
            );

            foreach ($blocks as $block) {
                foreach ($this->columnNames((string) ($block[1] ?? '')) as $column) {
                    if (in_array(strtoupper($column), self::MYSQL_WINDOW_AND_INTERNAL_NAMES, true)) {
                        $violations[] = $file . ' — колонка ' . $column . ' названа зарезервированным именем MySQL 8.0';
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    /** @return list<string> */
    private function sqlFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (glob($root . '/apps/*/sql/*.sql') ?: [] as $file) {
            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    // Имя столбца — первое слово строки определения; строки регистраций (`KEY`, `UNIQUE KEY`,
    // `CONSTRAINT`, `FOREIGN KEY`, `PRIMARY KEY`, `CHECK`) отсекаются по первому слову:
    // под проверкой только имена колонок.
    /** @return list<string> */
    private function columnNames(string $tableBody): array
    {
        $names = [];

        foreach (explode("\n", $tableBody) as $line) {
            if (preg_match('/^\s*([a-z][a-z0-9_]*)\s+[a-z]/i', $line, $match) !== 1) {
                continue;
            }

            if (in_array(strtoupper($match[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'FOREIGN', 'INDEX', 'CHECK'], true)) {
                continue;
            }

            $names[] = $match[1];
        }

        return $names;
    }
}
