<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Подпись поля и сам контрол не должны слипаться: на экранах админки отступ mt-1 (4 px) читался
// как «надпись почти касается рамки», особенно у селектов с высокой строкой. Правка внешнего вида
// без стража откатывается первой же новой формой, поэтому требование записано проверкой.
//
// Правило узкое с умыслом: контрол внутри подписи обязан нести вертикальный отступ, и это не mt-1.
// Исправленное значение — mt-1.5. Подсказка под полем живёт отдельной строкой и правилом не
// затрагивается: её отступ к контролу не относится.
//
// Проверяются две формы записи класса: литерал в атрибуте и общая переменная шаблона
// (`$input = 'mt-1 w-full …'`), потому что почти все поля админки собираются второй формой.
// Без неё страж зеленел бы на восьми экранах с прижатыми полями и краснел на одном литеральном.
final class AdminFormSpacingTest extends TestCase
{
    /** Отступ, который запрещён у контрола: с ним подпись почти касается рамки поля. */
    private const FORBIDDEN = 'mt-1';

    /** Отступ, который обязан быть: он и есть исправленное значение. */
    private const REQUIRED = 'mt-1.5';

    /**
     * Каталоги разметки с формами: ядро админки, шаблоны приложений и общая тема. Приложения
     * рисуют свои админки теми же контролами, а форма заявки и вход живут в теме — правило одно
     * на все, иначе «поля ровно» держалось бы только у ядра.
     */
    private const MARKUP_ROOTS = ['core/templates', 'apps', 'theme'];

    public function testКонтролыАдминкиНеПрижимаютсяКПодписи(): void
    {
        $files = $this->adminTemplates();
        self::assertNotEmpty($files, 'Ни одного шаблона админки не найдено — проверять нечего');

        $violations = [];

        foreach ($files as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ([...$this->controlViolations($code), ...$this->variableViolations($code)] as $found) {
                $violations[] = $relative . ': ' . $found;
            }
        }

        self::assertSame([], $violations, 'Контрол админки прижат к подписи: нужен ' . self::REQUIRED);
    }

    // Самопроверка: прежняя разметка обязана давать находку, исправленная — нет. Без этого
    // зелёный прогон мог бы означать «шаблоны не читаются», а не «отступ в порядке».
    public function testСтражЛовитПрижатыйКонтролИПропускаетИсправленный(): void
    {
        self::assertNotEmpty(
            $this->controlViolations('<input class="mt-1 w-full" type="text" name="q">'),
            'mt-1 у контрола не пойман'
        );
        self::assertSame(
            [],
            $this->controlViolations('<input class="mt-1.5 w-full" type="text" name="q">'),
            'исправленный отступ помечен как находка'
        );
        self::assertSame(
            [],
            $this->controlViolations('<span class="mt-1 block text-ink/60">Подсказка</span>'),
            'отступ подсказки правилом не затрагивается'
        );

        // Класс, собранный переменной, виден только своим литералом: у атрибута там имя
        // переменной, и находка обязана прийти из объявления, а не из атрибута.
        self::assertSame(
            [],
            $this->controlViolations('<input class="min-h-[44px] \' . e($input) . \'" type="text">'),
            'класс из переменной не выдумывается'
        );
        self::assertNotEmpty(
            $this->variableViolations("\$input = 'mt-1 w-full rounded-lg text-sm';"),
            'прижатое поле в переменной не поймано'
        );
        self::assertSame(
            [],
            $this->variableViolations("\$input = 'mt-1.5 w-full rounded-lg text-sm';"),
            'исправленная переменная помечена как находка'
        );
    }

    /**
     * Контролы с классом mt-1 прямо в атрибуте.
     *
     * @return list<string>
     */
    private function controlViolations(string $code): array
    {
        $pattern = '~<(?:input|select|textarea)\b[^>]*?class="([^"]*)"[^>]*>~s';

        preg_match_all($pattern, $code, $matches);

        $found = [];

        foreach ($matches[1] as $class) {
            if ($this->hasForbidden($class)) {
                $found[] = '<' . $class . '>';
            }
        }

        return $found;
    }

    /**
     * Переменные шаблона, которыми набирается класс контрола: `$input = '…'`, `$INPUT = '…'`,
     * `const LEARN_FORM_INPUT_CLASS = '…'`. Имя оканчивается на INPUT (любой регистр) или это
     * константа класса ввода — по этому признаку они и отличаются от прочих строк шаблона.
     *
     * @return list<string>
     */
    private function variableViolations(string $code): array
    {
        $pattern = '~(?:\$([A-Za-z_][A-Za-z0-9_]*)|const\s+([A-Za-z_][A-Za-z0-9_]*))\s*=\s*\'([^\']*)\'~';

        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER);

        $found = [];

        foreach ($matches as $match) {
            $name = (string) ($match[1] !== '' ? $match[1] : $match[2]);
            $literal = (string) $match[3];

            if (stripos($name, 'input') === false) {
                continue;
            }

            if ($this->hasForbidden($literal)) {
                $found[] = $name . ' = ' . $literal;
            }
        }

        return $found;
    }

    private function hasForbidden(string $class): bool
    {
        $tokens = preg_split('~[\n\r\t ]+~', $class) ?: [];

        return in_array(self::FORBIDDEN, $tokens, true);
    }

    /** @return list<string> */
    private function adminTemplates(): array
    {
        $files = [];

        foreach (self::MARKUP_ROOTS as $root) {
            $dir = $this->root() . '/' . $root;

            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));
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
