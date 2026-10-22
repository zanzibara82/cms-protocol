<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Производительность: все однострочные вызовы all('SELECT ...') обязаны иметь LIMIT,
// чтобы случайно не выгрузить целую таблицу в память. SELECT * запрещён вне
// разрешённых файлов, где он оправдан (один объект по первичному ключу / фид JSON).
final class PerformanceTest extends TestCase
{
    // Файлы, где SELECT * оправдан: один объект по первичному ключу / фид JSON.
    private const SELECT_STAR_ALLOWED = [
        'apps/seo/lib/search.php',
        'core/auth.php',
        'core/feed.php',
    ];

    public function testВсеОднострочныеВызовыAllИмеютLimit(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $relative = $this->relative($file);

            // Тестовые файлы работают с тестовыми данными — LIMIT не требуется.
            if (str_starts_with($relative, 'tests/')) {
                continue;
            }

            $code = (string) file_get_contents($file);
            $lines = explode("\n", $code);
            $total = count($lines);

            for ($i = 0; $i < $total; $i++) {
                $line = trim($lines[$i]);

                // Однострочный вызов all('SELECT ...') — вся строка запроса в одной линии.
                if (!preg_match("/\ball\s*\(\s*['\"]SELECT/i", $line)) {
                    continue;
                }

                $upper = strtoupper($line);

                // Подсчёт количества — LIMIT не нужен.
                if (preg_match('/COUNT\s*\(\s*\*\s*\)/i', $line)) {
                    continue;
                }

                // Единичная строка по первичному ключу — LIMIT не нужен.
                if (preg_match('/WHERE\s+`?(?:id|option_name)`?\s*=/i', $line)) {
                    continue;
                }

                if (strpos($upper, 'LIMIT') === false) {
                    $violations[] = $relative . ':' . ($i + 1) . ': all() без LIMIT — возможно выгрузка всей таблицы';
                }
            }
        }

        self::assertSame([], $violations, 'Найдены вызовы all() без LIMIT — возможно выгрузка всей таблицы в память');
    }

    public function testНетSelectZvezdotchkaVProdakshene(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $relative = $this->relative($file);

            // Тестовые файлы работают с тестовыми данными — не критичны для продакшена.
            if (str_starts_with($relative, 'tests/')) {
                continue;
            }

            if (in_array($relative, self::SELECT_STAR_ALLOWED, true)) {
                continue;
            }

            $code = (string) file_get_contents($file);
            $lines = explode("\n", $code);
            $total = count($lines);

            for ($i = 0; $i < $total; $i++) {
                $line = $lines[$i];

                // Пропускаем комментарии — в них SELECT * может быть частью пояснения.
                if (ltrim($line) === '' || str_starts_with(ltrim($line), '//') || str_starts_with(ltrim($line), '#')) {
                    continue;
                }

                if (!preg_match("/SELECT\s+\*/i", $line)) {
                    continue;
                }

                $violations[] = $relative . ':' . ($i + 1) . ': SELECT * в продакшене — укажите колонки явно';
            }
        }

        self::assertSame([], $violations, 'Найдены SELECT * в продакшене — укажите колонки явно');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['core', 'apps', 'tests'] as $dir) {
            self::assertDirectoryExists($root . '/' . $dir, $dir . ': каталог исчез из дерева');
            $found = [];
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $found[] = $item->getPathname();
                }
            }
            self::assertNotEmpty($found, $dir . ': каталог не отдал ни одного PHP-файла');
            $out = array_merge($out, $found);
        }
        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
