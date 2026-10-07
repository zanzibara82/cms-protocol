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

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
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
