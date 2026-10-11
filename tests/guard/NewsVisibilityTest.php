<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Единственный способ опубликовать черновик «случайно» — новый запрос к tbl_news_item без
// visibleWhere(). Страж читает исходники раздела и требует, чтобы каждая функция, которая этот
// стол читала, либо звала visibleWhere(), либо стояла в списке ниже с названной причиной.
final class NewsVisibilityTest extends TestCase
{
    /**
     * Признаки чтения материалов: прямой вызов таблицы, общий FROM публичных выборок и делегирование
     * области видимости. newsItemFrom() и feedScope() — сами по себе не защита, но функция,
     * которая собирает WHERE через feedScope(), получает visibleWhere() оттуда.
     */
    private const READ_MARKERS = ["t('news_item')", 'newsItemFrom(', 'feedScope('];
    private const GUARD_MARKERS = ['visibleWhere(', 'feedScope('];

    private const ALLOWED = [
        // Редактор обязан видеть черновик, не наступивший срок и снятый материал: иначе список
        // «Черновики» был бы пустым, а правка — невозможной.
        'apps/news/lib/items.php' => '*',
        // Справочник считает материалы в рубрике и теге, чтобы сказать, можно ли их удалить.
        'apps/news/lib/catalog.php' => '*',
        // Публичное чтение: три функции, которым материал нужен независимо от срока и статуса.
        'apps/news/lib/feed.php' => ['newsItemFrom', 'findAny', 'findByOldSlug'],
    ];

    public function testЧтениеМатериаловОгражденоВидимостью(): void
    {
        $violations = [];

        foreach ($this->sources() as $file) {
            $relative = $this->relative($file);
            $allowed = self::ALLOWED[$relative] ?? [];

            if ($allowed === '*') {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($file));

            foreach ($this->functions($code) as $name => $body) {
                $reads = false;
                foreach (self::READ_MARKERS as $marker) {
                    if (str_contains($body, $marker)) {
                        $reads = true;
                        break;
                    }
                }

                if (!$reads || in_array($name, $allowed, true)) {
                    continue;
                }

                foreach (self::GUARD_MARKERS as $marker) {
                    if (str_contains($body, $marker)) {
                        continue 2;
                    }
                }

                $violations[] = $relative . ' — ' . $name . '() читает tbl_news_item без visibleWhere()';
            }
        }

        self::assertSame([], $violations, 'Новое чтение материалов обязано быть ограждено видимостью или записано в белый список с причиной');
    }

    public function testБелыйСписокНеДержитЛишнего(): void
    {
        // Белый список — это решение, а не страховка: каждая его строка обязана по-прежнему
        // соответствовать функции, которая читает материалы без проверки видимости.
        $used = [];

        foreach (self::ALLOWED as $file => $names) {
            $path = dirname(__DIR__, 2) . '/' . $file;
            self::assertFileExists($path, $file . ' из белого списка стража больше нет');

            if ($names === '*') {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($path));
            $functions = $this->functions($code);

            foreach ($names as $name) {
                self::assertArrayHasKey($name, $functions, $file . ': функции ' . $name . '() в белом списке нет');

                $reads = false;
                foreach (self::READ_MARKERS as $marker) {
                    if (str_contains((string) $functions[$name], $marker)) {
                        $reads = true;
                    }
                }

                self::assertTrue($reads, $file . ': ' . $name . '() больше не читает материалы — строка списка устарела');
                $used[] = $file . '::' . $name;
            }
        }

        self::assertCount(3, $used, 'Список разрешённых функций читается целиком — три именованные строки feed.php');
    }

    /** @return array<string, string> имя функции → её тело */
    private function functions(string $code): array
    {
        preg_match_all('/\bfunction\s+([a-zA-Z_]\w*)\s*\([^)]*\)[^{]*\{/', $code, $starts, PREG_OFFSET_CAPTURE);

        $out = [];
        $length = strlen($code);

        foreach ($starts[1] as $index => $start) {
            $bodyStart = (int) $starts[0][$index][1] + strlen((string) $starts[0][$index][0]);
            $depth = 1;
            $position = $bodyStart;

            while ($position < $length && $depth > 0) {
                $character = $code[$position];

                if ($character === '{') {
                    ++$depth;
                } elseif ($character === '}') {
                    --$depth;
                }

                ++$position;
            }

            $out[(string) $start[0]] = substr($code, $bodyStart, max(0, $position - $bodyStart));
        }

        return $out;
    }

    private function stripComments(string $code): string
    {
        $out = preg_replace('/(^|\s)\/\/[^\n\r]*/', ' ', $code) ?? $code;
        $out = preg_replace('/#![^\n\r]*/', ' ', $out) ?? $out;
        $out = preg_replace('/\/\*.*?\*\//s', ' ', $out) ?? $out;

        return preg_replace('/(^|\s)#[^\n\r]*/', ' ', $out) ?? $out;
    }

    /** @return list<string> */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach ([$root . '/apps/news/lib', $root . '/apps/news/actions', $root . '/apps/news/templates'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

        foreach ([$root . '/apps/news/admin.php', $root . '/apps/news/routes.php'] as $file) {
            if (is_file($file)) {
                $out[] = $file;
            }
        }

        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
