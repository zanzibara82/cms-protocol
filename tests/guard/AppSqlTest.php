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
}
