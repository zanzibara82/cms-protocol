<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// Крошки — единая навигация, а не набор ручных повторов: общий partial задаёт выравнивание,
// стрелку, зону нажатия и aria-current. Страж читает публичные шаблоны и требует вызов partial,
// чтобы новая страница не осталась без пути наверх; шапка сайта, вход, ошибки и админка в
// проверку не входят.
final class BreadcrumbsTest extends TestCase
{
    private const PARTIAL = 'theme/partials/breadcrumbs.php';

    private const PUBLIC_DIRS = [
        'apps/news/templates',
        'apps/commerce/templates',
        'apps/classifieds/templates',
        'apps/learn/templates',
        'theme',
    ];

    private const SKIP_DIRS = [
        'admin',
        'partials',
        'sections',
    ];

    private const SKIP_FILES = [
        '403.php',
        '404.php',
        'home.php',
        'layout.php',
        'login.php',
    ];

    public function testОбщийPartialКрошекЗадаётВыравниваниеИДоступность(): void
    {
        $code = (string) file_get_contents($this->root() . '/' . self::PARTIAL);

        self::assertStringContainsString('aria-label="Хлебные крошки"', $code);
        self::assertStringContainsString('flex flex-wrap items-center gap-2', $code, 'пункты выровнены по одной линии');
        self::assertStringContainsString('min-h-[44px]', $code, 'зона нажатия ссылки не меньше 44 px');
        self::assertStringContainsString('aria-current="page"', $code, 'последний пункт помечен как текущая страница');
        self::assertStringContainsString('→', $code, 'пункты разделяет стрелка');
    }

    public function testКаждыйПубличныйШаблонПечатаетКрошки(): void
    {
        $files = $this->publicTemplates();

        self::assertGreaterThanOrEqual(25, count($files), 'Список публичных шаблонов не должен схлопнуться');
        self::assertContains('theme/page.php', $files);
        self::assertContains('apps/news/templates/feed.php', $files);
        self::assertContains('apps/classifieds/templates/cabinet.php', $files);
        self::assertContains('apps/learn/templates/lesson.php', $files);

        $missing = [];

        foreach ($files as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            if (!str_contains($code, "partial('partials/breadcrumbs'")) {
                $missing[] = $relative;
            }
        }

        self::assertSame([], $missing, 'Публичная страница без общего partial крошек');
    }

    public function testПубличныеШаблоныНеПовторяютКрошкиВручную(): void
    {
        $manual = [];

        foreach ($this->publicTemplates() as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            if (str_contains($code, 'aria-label="Хлебные крошки"') || str_contains($code, 'aria-label="Путь до')) {
                $manual[] = $relative;
            }
        }

        self::assertSame([], $manual, 'Крошки собираются только общим partial');
    }

    /** @return list<string> */
    private function publicTemplates(): array
    {
        $files = [];

        foreach (self::PUBLIC_DIRS as $directory) {
            $path = $this->root() . '/' . $directory;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));

                if (in_array($file->getFilename(), self::SKIP_FILES, true)) {
                    continue;
                }

                foreach (self::SKIP_DIRS as $skip) {
                    if (str_contains($relative, '/' . $skip . '/')) {
                        continue 2;
                    }
                }

                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
