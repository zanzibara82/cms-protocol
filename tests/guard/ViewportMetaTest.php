<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Адаптивность держится на одном теге: без meta viewport телефон рендерит страницу в
// виртуальное окно 980 px и ужимает её целиком. Всё остальное при этом зелёное — ни
// переполнения (страница уже окна), ни мелких зон нажатия (всё уменьшено равномерно),
// ни разъехавшихся полей. Проверка адаптивности живёт в headless Chrome и в ворота не
// входит, поэтому пропавший тег заметили бы только глазами на телефоне.
//
// Страж читает каркасы по исходнику и требует viewport в каждом файле, который печатает
// <html>: своя обёртка у админки, ошибок и экрана установки, и потеря тега в любом из них
// ломает адаптивность ровно того класса страниц.
final class ViewportMetaTest extends TestCase
{
    // Каркасы, которые печатают <html> самостоятельно: theme/layout.php (публичная часть
    // и админка), core/templates/error.php (ошибки и 404), not-installed.php (замок установки).
    private const SHELLS = [
        'theme/layout.php',
        'core/templates/error.php',
        'core/templates/not-installed.php',
        // Экран техобслуживания печатает свой каркас: он отдаётся до разбора маршрута и до каркаса
        // темы, а без viewport заглушка на телефоне рендерилась бы в 980 px.
        'core/templates/maintenance.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testКаждыйКаркасНесётViewport(): void
    {
        foreach (self::SHELLS as $shell) {
            $path = $this->root() . '/' . $shell;
            self::assertFileExists($path, 'Нет каркаса ' . $shell . ' — страж проверяет ноль файлов');

            $source = (string) file_get_contents($path);
            self::assertStringContainsString(
                '<html',
                $source,
                'Каркас ' . $shell . ' больше не печатает <html>: список стражей устарел'
            );
            self::assertMatchesRegularExpression(
                '/<meta\s+name="viewport"\s+content="width=device-width,\s*initial-scale=1"/',
                $source,
                'В каркасе ' . $shell . ' нет meta viewport: телефон отрендерит страницу в 980 px'
            );
        }
    }

    // Каждый файл, печатающий <html>, обязан быть в списке: иначе новый каркас (например,
    // отдельный экран мастера установки) выпадает из проверки молча.
    public function testНовыйКаркасПопадаетВПроверку(): void
    {
        $found = [];
        foreach (['theme', 'core/templates', 'core', 'public'] as $dir) {
            $base = $this->root() . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }

            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $files */
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($this->root()) + 1);
                if (str_contains(str_replace('\\', '/', $relative), '/vendor/')) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/<html\b/i', $source) === 1) {
                    $found[] = str_replace('\\', '/', $relative);
                }
            }
        }

        sort($found);
        $found = array_values(array_unique($found));
        $known = self::SHELLS;
        sort($known);

        self::assertSame(
            $known,
            $found,
            'Набор каркасов с <html> изменился: добавьте файл в SHELLS (и проверьте viewport), '
            . 'либо уберите лишний'
        );
    }
}
