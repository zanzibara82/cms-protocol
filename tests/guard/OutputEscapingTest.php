<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// XSS в шаблоне — самая частая находка в самописных CMS. Правило: любая переменная печатается
// через e(); исключение — блок, помеченный /* @safe html */, где разметка собрана самим кодом.
final class OutputEscapingTest extends TestCase
{
    public function testШаблоныПечатаютПеременныеТолькоЧерезE(): void
    {
        $violations = [];

        foreach ($this->templates() as $file) {
            $code = $this->withoutSafeBlocks((string) file_get_contents($file));

            /* `\s*+` — притяжательный квантификатор, и это не украшение. Обычный `\s*` отдаёт пробел
            по одному символу, отрицательный lookahead срабатывает на позиции пробела, и законное
            `<?= e($title) ?>` попадало в находки: страж краснел на правильно написанных шаблонах
            и оставался зелёным только на сломанных. У второго условия той же ошибки нет — там
            группа начинается с `\$`, поэтому откат на пробел не приводит к ложному совпадению.
            Комментарий многострочный: `?>` внутри однострочного комментария закрыл бы PHP-блок. */
            if (preg_match_all('/<\?=\s*+(?!e\(|partial\(|richText\()([^;]*?);?\s*+\?>/s', $code, $matches) > 0) {
                foreach ($matches[1] as $expression) {
                    $violations[] = $this->relative($file) . ': <?= ' . trim($expression) . ' ?>';
                }
            }

            if (preg_match_all('/echo\s+(?!e\(|partial\(|richText\()(\$[a-zA-Z_][^\n;]*)/', $code, $matches) > 0) {
                foreach ($matches[1] as $expression) {
                    $violations[] = $this->relative($file) . ': echo ' . trim($expression);
                }
            }
        }

        self::assertSame([], $violations, 'Вывод без e(): строка обязана проходить экранирование либо блок помечается /* @safe html */');
    }

    public function testПометкаБезопасногоБлокаЕстьТолькоТамГдеСобираетсяРазметка(): void
    {
        // Помеченный блок печатает строку, собранную нашим кодом: переменную, в которую
        // шаблон положил разметку, либо вызов помощника, который сам экранирует каждое значение.
        // Проверка идёт по строке с пометкой, а не по тексту после неё: иначе результат зависел бы
        // от того, что случайно оказалось в следующих 200 символах.
        $allowed = '/(\$content|\$head|\$footer|partial\(|richText\(|csrfField\(|adminShell\()/';

        foreach ($this->templates() as $file) {
            foreach ($this->safeBlocks((string) file_get_contents($file)) as $line) {
                self::assertMatchesRegularExpression(
                    $allowed,
                    $line,
                    $this->relative($file) . ': помеченная строка не собирает разметку — ' . trim($line)
                );
            }
        }
    }

    /** @return list<string> */
    private function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['theme', 'admin', 'apps', 'core/templates'] as $dir) {
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
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private function safeBlocks(string $code): array
    {
        $out = [];
        foreach (explode("\n", $code) as $line) {
            if (str_contains($line, '@safe html')) {
                $out[] = $line;
            }
        }

        return $out;
    }

    // Строка с пометкой убирается целиком. Многострочный `echo` при этом теряет только первую
    // строку — ту, на которой стоит `echo`, поэтому continuation-строки под проверку не попадают.
    private function withoutSafeBlocks(string $code): string
    {
        $kept = [];
        foreach (explode("\n", $code) as $line) {
            if (!str_contains($line, '@safe html')) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
