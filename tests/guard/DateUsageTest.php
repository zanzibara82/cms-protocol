<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Правило «в базе UTC, Москва только на краях» живёт, пока никто не может отформатировать дату
// в обход core/date.php: иначе половина экранов показала бы время на три часа раньше.
final class DateUsageTest extends TestCase
{
    private const ALLOWED = ['core/date.php'];

    private const FORBIDDEN = [
        '/\bdate\s*\(/' => 'date() — форматирование в обход core/date.php',
        '/\bstrtotime\s*\(/' => 'strtotime() — разбор даты в обход core/date.php',
        '/->\s*format\s*\(/' => '->format() — форматирование в обход core/date.php',
        '/new\s+DateTime(Immutable)?\s*\(/' => 'new DateTime — создание момента в обход core/date.php',
        '/\btime\s*\(\s*\)/' => 'time() — момент в обход core/date.php',
    ];

    public function testНиктоКромеМодуляДатНеФорматируетВремя(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $relative = $this->relative($file);
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $code = $this->withoutComments((string) file_get_contents($file));
            foreach (self::FORBIDDEN as $pattern => $reason) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $relative . ': ' . $reason;
                }
            }
        }

        self::assertSame([], $violations, 'Найдены места, где дата обрабатывается в обход core/date.php');
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['core', 'admin', 'theme', 'apps', 'public'] as $dir) {
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
