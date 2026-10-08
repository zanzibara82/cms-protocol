<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// XSS в шаблоне — самая частая находка в самописных CMS. Правило: любая переменная печатается
// через e(); исключение — блок, помеченный комментарием /* @safe html */, где печатается
// разметка, собранная самим кодом (абзацы с **выделением**, partial(), adminShell()). Пометка
// не освобождает от проверки: переменные в размеченной стро обязаны быть агрегаторами разметки
// ($content, $head, $footer) или лежать внутри вызова-агрегатора — конкатенация с «сырыми»
// данными краснеет даже под пометкой.
final class OutputEscapingTest extends TestCase
{
    // Вызовы, которые сами экранируют или собирают вывод: переменная внутри них в безопасности.
    private const COVERING_CALLS = ['e', 'partial', 'richtext'];

    // Агрегаторы разметки: переменные, куда код шаблона сам кладёт свёрстанные строки
    // (абзацы с **выделением**, блоки partial/adminShell). Печатаются только под пометкой.
    private const MARKED_VARS = ['content', 'head', 'footer'];

    // Добавляют накрытие только на помеченной строке: вне её печать через них не разрешена.
    private const MARKED_CALLS = ['adminshell', 'csrffield'];

    public function testШаблоныПечатаютПеременныеТолькоЧерезE(): void
    {
        $violations = [];

        foreach ($this->templates() as $file) {
            foreach ($this->outputViolations((string) file_get_contents($file), $this->relative($file)) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame([], $violations, 'Вывод без e(): каждое вхождение $ в печатающем выражении обязано быть накрыто e()/partial()/richText(), иначе строка помечается /* @safe html */ и под пометкой — только агрегаторы разметки');
    }

    public function testСтражЛовитКонкатенациюПослеЭкранирующегоВызова(): void
    {
        // Обе формы проходят нынешний префиксный проверкой и обязаны краснеть здесь:
        // проверка идёт по каждому вхождению $, а не по началу выражения.
        self::assertSame(
            ['probe.php: строка 1: $subtitle печатается без экранирования'],
            $this->outputViolations('<?= e($title) . $subtitle ?>', 'probe.php')
        );

        self::assertSame(
            ['probe.php: строка 1: $b печатается без экранирования'],
            $this->outputViolations('<?php echo e($a), $b;', 'probe.php')
        );
    }

    public function testПометкаНеОсвобождаетОтПроверкиСырыхПеременных(): void
    {
        // $content — разрешённый агрегатор разметки, $evil — конкатенация с «сырыми» данными:
        // пометка не спасает.
        self::assertSame(
            ['probe.php: строка 1: $evil печатается без экранирования'],
            $this->outputViolations('<?= $content . $evil /* @safe html */ ?>', 'probe.php')
        );

        self::assertSame([], $this->outputViolations('<?= $head /* @safe html */ ?>', 'probe.php'));
    }

    public function testНакрытыеВызовамиПеременныеНеПомечаютсяКакНарушение(): void
    {
        self::assertSame([], $this->outputViolations('<?= e($title) ?>', 'probe.php'));
        self::assertSame([], $this->outputViolations('<?= partial("partials/cat", ["mood" => $mood]) ?>', 'probe.php'));
        self::assertSame([], $this->outputViolations('<?php echo adminShell("Сводка", $content, $notice); /* @safe html */', 'probe.php'));
        self::assertSame([], $this->outputViolations('<?= csrfField() /* @safe html */ ?>', 'probe.php'));
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

    /**
     * Нарушения в печатающих выражениях файла: `<?= … ?>` и `echo …;` разбираются токенами,
     * поэтому проверяется каждое вхождение `$`, а не только начало выражения — формы
     * `<?= e($x) . $y ?>` и `echo e($x), $y;` краснеют. Строка с пометкой `@safe html`
     * не выбрасывается из проверки: в ней разрешены только агрегаторы разметки и вызовы
     * их накрывающие.
     *
     * @return list<string>
     */
    private function outputViolations(string $code, string $relative): array
    {
        $lines = explode("\n", $code);
        $tokens = token_get_all($code);
        $total = count($tokens);
        $violations = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || ($token[0] !== T_OPEN_TAG_WITH_ECHO && $token[0] !== T_ECHO)) {
                continue;
            }

            // Границы выражения: `;` для echo и закрывающий тег для `<?=` — дальше идёт
            // другой оператор, его проверит следующий проход внешнего цикла.
            $expression = [];
            for ($j = $i + 1; $j < $total; $j++) {
                $current = $tokens[$j];
                if ((is_string($current) && $current === ';') || (is_array($current) && $current[0] === T_CLOSE_TAG)) {
                    break;
                }
                $expression[] = $current;
            }

            /** @var list<bool> $coverStack для каждой открытой скобки: «скобка расставлена вызовом, который накрывает вывод» */
            $coverStack = [];
            $pending = false;

            foreach ($expression as $index => $current) {
                $text = is_array($current) ? $current[1] : $current;

                if ($text === '(') {
                    $coverStack[] = $pending;
                    $pending = false;
                    continue;
                }

                if ($text === ')') {
                    if ($coverStack !== []) {
                        array_pop($coverStack);
                    }
                    $pending = false;
                    continue;
                }

                if (is_array($current) && in_array($current[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (is_array($current) && $current[0] === T_STRING) {
                    $name = strtolower($current[1]);
                    $marked = $this->lineIsMarked($lines, $current[2]);
                    $pending = $this->opensCall($expression, $index)
                        && (in_array($name, self::COVERING_CALLS, true) || ($marked && in_array($name, self::MARKED_CALLS, true)));
                    continue;
                }

                $pending = false;

                if (is_array($current) && $current[0] === T_VARIABLE) {
                    if (in_array(true, $coverStack, true)) {
                        continue;
                    }

                    $name = substr($current[1], 1);
                    if ($this->lineIsMarked($lines, $current[2]) && in_array($name, self::MARKED_VARS, true)) {
                        continue;
                    }

                    $violations[] = $relative . ': строка ' . $current[2] . ': $' . $name . ' печатается без экранирования';
                }
            }
        }

        return $violations;
    }

    /** @param list<array{int, string, int}|string> $expression */
    private function opensCall(array $expression, int $index): bool
    {
        $total = count($expression);
        for ($i = $index + 1; $i < $total; $i++) {
            $current = $expression[$i];
            if (is_array($current) && in_array($current[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_string($current) && $current === '(';
        }

        return false;
    }

    /** @param list<string> $lines */
    private function lineIsMarked(array $lines, int $line): bool
    {
        return str_contains($lines[$line - 1] ?? '', '@safe html');
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
