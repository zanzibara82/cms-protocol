<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// У каждого поля загрузки файла есть кнопка с иконкой папки.
//
// Причина требования: нативное <input type="file"> рисует браузерная строка «Выберите файл | Файл
// не выбран» — она выглядит по-разному в Chrome, Firefox и Safari, не несёт иконки и не даёт
// зоны нажатия 44 px. В админке принят другой приём (core/templates/partials/file-picker.php):
// поле скрыто (sr-only), рядом стоит подпись-кнопка с иконкой папки, зону нажатия 44×44 несёт
// она. Подпись с for открывает выбор файла сама, поэтому кнопка работает без JavaScript.
//
// На скриншотах прайс-листа и формы объявления видно, что часть полей осталась нативной: иконки
// нет, высота строки другая, а подпись над полем — обычный текст. Правило записано проверкой,
// иначе следующая новая форма снова напечатает нативное поле.
//
// Проверяются обе формы записи: поле скрыто и подпись-кнопка с svg (правильно), либо поле видимо
// (находка). Подпись-кнопка без svg — тоже находка: иконку папки человек ищет глазами.
final class FilePickerIconTest extends TestCase
{
    /** Каталоги разметки: ядро, приложения и тема — правило одно на все. */
    private const MARKUP_ROOTS = ['core/templates', 'apps', 'theme'];

    public function testУКаждогоПоляЗагрузкиЕстьКнопкаСИконкой(): void
    {
        $violations = [];

        foreach ($this->templates() as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ($this->violations($code, $relative) as $found) {
                $violations[] = $found;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Поле загрузки файла без кнопки с иконкой папки: нужен partials/file-picker'
        );
    }

    // Самопроверка: нативное поле обязано давать находку, разметка партиала — нет. Без этого
    // зелёный прогон мог бы означать «шаблоны не читаются», а не «иконки на месте».
    public function testСтражЛовитНативноеПолеИПропускаетКнопкуСИконкой(): void
    {
        // Так выглядело поле прайс-листа до правки: нативная строка браузера.
        self::assertSame(
            ['probe.php: строка 1: <input type="file"> нарисован браузером — нет иконки папки и зоны нажатия'],
            $this->violations(
                '<label class="block" for="pricebook_file"><span class="text-sm">Файл CSV</span>'
                . '<input class="min-h-[44px] block w-full" id="pricebook_file" name="csvfile" type="file" accept=".csv">'
                . '</label>',
                'probe.php'
            )
        );

        // Приём партиала: поле скрыто, иконка нарисована подписью-кнопкой.
        self::assertSame([], $this->violations(
            '<input class="sr-only" id="file-image-image" type="file" name="image" accept="image/png">'
            . '<label class="inline-flex min-h-[44px] min-w-[44px]" for="file-image-image" title="Выбрать картинку">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"></svg></label>',
            'probe.php'
        ));

        // Скрытое поле без подписи-кнопки: иконки нет вовсе — находка.
        self::assertSame(
            ['probe.php: строка 1: <input type="file"> скрыт, но кнопки с иконкой папки у него нет'],
            $this->violations(
                '<input class="sr-only" id="lost" type="file" name="lost">',
                'probe.php'
            )
        );

        // Подпись-кнопка без svg: текста «Выбрать» мало, иконку папки человек ищет глазами.
        self::assertSame(
            ['probe.php: строка 1: <input type="file"> у кнопки нет иконки папки (svg)'],
            $this->violations(
                '<input class="sr-only" id="noicon" type="file" name="noicon">'
                . '<label class="inline-flex min-h-[44px]" for="noicon"><span class="sr-only">Выбрать</span></label>',
                'probe.php'
            )
        );

        // Поле другого типа правилом не затрагивается.
        self::assertSame([], $this->violations(
            '<label class="block"><input class="min-h-[44px] w-full" type="text" name="q"></label>',
            'probe.php'
        ));
    }

    /**
     * Поля загрузки файла в файле: каждое обязано быть скрытым и иметь подпись-кнопку с иконкой.
     *
     * @return list<string>
     */
    private function violations(string $code, string $relative): array
    {
        if (preg_match_all('/<input\b/i', $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as $match) {
            $start = (int) $match[1];
            $end = $this->tagEnd($code, $start + strlen($match[0]));

            if ($end === null) {
                continue;
            }

            $text = substr($code, $start, $end - $start + 1);

            // Тип читается по значению атрибута: `data-type="file"` — не поле файла.
            if (preg_match('/[\s"\']type[\s]*=[\s]*"file"/', $text) !== 1) {
                continue;
            }

            $line = 1 + substr_count($code, "\n", 0, $start);

            if (!$this->isHidden($text)) {
                $found[] = $relative . ': строка ' . $line
                    . ': <input type="file"> нарисован браузером — нет иконки папки и зоны нажатия';

                continue;
            }

            $id = $this->idOf($text);
            $label = $id === '' ? '' : $this->labelFor($code, $id);

            if ($label === '') {
                $found[] = $relative . ': строка ' . $line
                    . ': <input type="file"> скрыт, но кнопки с иконкой папки у него нет';

                continue;
            }

            if (preg_match('~<svg\b~i', $label) !== 1) {
                $found[] = $relative . ': строка ' . $line . ': <input type="file"> у кнопки нет иконки папки (svg)';
            }
        }

        return $found;
    }

    /** Поле скрыто от глаза: sr-only в классе или hidden в атрибутах. */
    private function isHidden(string $tag): bool
    {
        if (preg_match('/[\s"\']hidden(?:[\s"\'=>]|$)/', $tag) === 1) {
            return true;
        }

        if (preg_match('/(?:[\s"\']|^)class[\s]*=[\s]*"([^"]*)"/', $tag, $class) !== 1) {
            return false;
        }

        $tokens = preg_split('~[\n\r\t ]+~', trim((string) $class[1])) ?: [];

        return in_array('sr-only', $tokens, true);
    }

    /** Значение атрибута id или пустая строка. */
    private function idOf(string $tag): string
    {
        return preg_match('/[\s"\']id[\s]*=[\s]*"([^"]*)"/', $tag, $match) === 1 ? (string) $match[1] : '';
    }

    /** Разметка подписи-кнопки, которая открывает это поле: <label … for="id" …>…</label>. */
    private function labelFor(string $code, string $id): string
    {
        $pattern = '~<label\b[^>]*\bfor="' . preg_quote($id, '~') . '"[^>]*>.*?</label>~s';

        return preg_match($pattern, $code, $match) === 1 ? (string) $match[0] : '';
    }

    private function tagEnd(string $code, int $from): ?int
    {
        $length = strlen($code);

        for ($i = $from; $i < $length; $i++) {
            // Символ `>` после вопроса закрывает PHP-блок внутри тега, а не сам тег.
            if ($code[$i] === '>' && ($i === 0 || $code[$i - 1] !== '?')) {
                return $i;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function templates(): array
    {
        $found = [];

        foreach (self::MARKUP_ROOTS as $dir) {
            $root = $this->root() . '/' . $dir;

            self::assertDirectoryExists(
                $root,
                $dir . ': каталог разметки исчез из дерева — страж проверял бы меньше, чем требует правило'
            );

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $found[str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1))] = true;
                }
            }
        }

        $paths = array_keys($found);
        sort($paths);

        self::assertNotEmpty($paths, 'Ни одного файла разметки не найдено — проверять нечего');

        return $paths;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
