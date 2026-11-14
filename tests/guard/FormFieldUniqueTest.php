<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Две ошибки разметки форм, которые браузер не считает ошибкой и потому молчит о них.
//
// Первая: два поля с одним именем внутри ОДНОЙ формы. Сервер получает последнее значение, и
// человек теряет то, что ввёл в первое. Так случилось в форме загрузки медиатеки: строка
// «Название» попала в разметку дважды — в сетке и после неё. Глазами это видно только на
// экране, а в разметке — обычные строки шаблона.
//
// Вторая: две подписи <label for="X">, указывающие на одно поле. Браузер объявляет оба текста
// доступным именем контрола, и читалка экрана произносит «Картинка Выбрать картинку с
// компьютера» вместо одного названия. Так было в конструкторе страниц: подпись «Картинка» и
// кнопка с иконкой папки ссылались на скрытое поле файла одним и тем же for.
//
// Исключения намеренные: радиокнопки и флажки с именем на «[]» одной группы обязаны носить
// общее имя — это не дубль, а группа.
final class FormFieldUniqueTest extends TestCase
{
    /** Каталоги разметки: ядро админки, приложения и тема. Правило одно на всех. */
    private const MARKUP_ROOTS = ['core/templates', 'apps', 'theme', 'admin'];

    public function testВОднойФормеНетПолейСОднимИменем(): void
    {
        $violations = [];

        foreach ($this->templates() as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ($this->duplicateNameViolations($code, $relative) as $found) {
                $violations[] = $found;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Два поля с одним именем в одной форме: сервер получит только последнее'
        );
    }

    public function testНаОдноFieldНеДвеПодписи(): void
    {
        $violations = [];

        foreach ($this->templates() as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ($this->duplicateLabelViolations($code, $relative) as $found) {
                $violations[] = $found;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Две подписи указывают на одно поле: читалка экрана объявит оба текста'
        );
    }

    // Самопроверка: разметка формы загрузки медиатеки до правки обязана давать находку.
    public function testСтражЛовитПовторноеИмяПоля(): void
    {
        // Именно эта разметка была в media.php: «Название» напечатано дважды.
        self::assertSame(
            ['probe.php: строка 2: поле «title» встречается 2 раза в одной форме'],
            $this->duplicateNameViolations(
                '<form method="post">' . "\n"
                . '<input type="text" name="title">'
                . '<input type="text" name="alt">'
                . '<input type="text" name="title">'
                . '</form>',
                'probe.php'
            )
        );

        // Разные формы с одинаковым именем — обычное дело: у каждой карточки своя форма.
        self::assertSame([], $this->duplicateNameViolations(
            '<form><input type="text" name="title"></form><form><input type="text" name="title"></form>',
            'probe.php'
        ));

        // Группа флажков и радиокнопок носит общее имя намеренно.
        self::assertSame([], $this->duplicateNameViolations(
            '<form><input type="radio" name="kind" value="a"><input type="radio" name="kind" value="b">'
            . '<input type="checkbox" name="ids[]" value="1"><input type="checkbox" name="ids[]" value="2"></form>',
            'probe.php'
        ));

        // Скрытые служебные поля (csrf, op) проект печатает по одному; два op — тоже дубль.
        self::assertSame(
            ['probe.php: строка 1: поле «op» встречается 2 раза в одной форме'],
            $this->duplicateNameViolations(
                '<form><input type="hidden" name="op" value="a"><input type="hidden" name="op" value="b"></form>',
                'probe.php'
            )
        );

        // Имя, собранное PHP, — разные поля на разные характеристики: находки нет.
        self::assertSame([], $this->duplicateNameViolations(
            '<form><input type="text" name="attr_\' . e($name) . \'">'
            . '<input type="text" name="attr_\' . e($name) . \'"></form>',
            'probe.php'
        ));
    }

    // Самопроверка: разметка конструктора страниц до правки обязана давать находку.
    public function testСтражЛовитДвеПодписиНаОдноПоле(): void
    {
        // Именно эта разметка была в pages-sections.php: подпись «Картинка» и кнопка с иконкой
        // ссылались на скрытое поле файла одним for.
        self::assertSame(
            ['probe.php: строка 2: подпись for="file-image" встречается 2 раза'],
            $this->duplicateLabelViolations(
                '<form>' . "\n"
                . '<label for="file-image">Картинка</label>'
                . '<input class="sr-only" id="file-image" type="file" name="image_file">'
                . '<label for="file-image" title="Выбрать картинку">иконка</label>'
                . '</form>',
                'probe.php'
            )
        );

        // Одна подпись на контрол — верно.
        self::assertSame([], $this->duplicateLabelViolations(
            '<span>Картинка</span><input class="sr-only" id="file-image" type="file" name="image_file">'
            . '<label for="file-image">иконка</label>',
            'probe.php'
        ));

        // Подписи разных контролов на одно имя не похожи.
        self::assertSame([], $this->duplicateLabelViolations(
            '<label for="a">Первый</label><label for="b">Второй</label>',
            'probe.php'
        ));

        // Значение, собранное PHP, — это разные подписи на разные поля: начало строки совпадает,
        // поле нет (характеристики товара, урок курса, акции магазина).
        self::assertSame([], $this->duplicateLabelViolations(
            '<label for="attr_\' . $attrId . \'">Первая</label>'
            . '<label for="attr_\' . $attrId . \'">Вторая</label>',
            'probe.php'
        ));
    }

    /**
     * Имена полей, повторённые внутри одной формы.
     *
     * Почему разбор идёт по формам, а не по файлу: карточки списка — отдельные формы, и
     * «title» в каждой из них законен. Правило нарушает только повтор внутри одной.
     *
     * @return list<string>
     */
    private function duplicateNameViolations(string $code, string $relative): array
    {
        $found = [];

        foreach ($this->forms($code) as $form) {
            /** @var array<string, list<int>> $counts позиции полей по имени */
            $counts = [];

            // Поле в разметке: имя, тип и признак группы. Тег может занимать несколько строк,
            // поэтому шаблон читается с флагом s — иначе атрибуты уехали бы за границу.
            preg_match_all(
                '~<(?:input|select|textarea)\b([^>]*)>~s',
                $form[0],
                $fields,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            );

            foreach ($fields as $field) {
                $attrs = (string) $field[1][0];

                if (preg_match('~\bname\s*=\s*"([^"]+)"~', $attrs, $name) !== 1) {
                    continue;
                }

                $key = (string) $name[1];

                // Имя, собранное PHP (`name="' . e($name) . '"`), — это разные поля на разные
                // характеристики. Совпадает только исходная строка шаблона, поле — нет.
                if ($this->isDynamicAttr($key)) {
                    continue;
                }

                // Группа: радиокнопки, флажки одной группы и поля с именем на «[]» делят имя
                // намеренно — сервер собирает их в массив и различает по значению.
                if (str_ends_with($key, '[]')) {
                    continue;
                }

                if (preg_match('~\btype\s*=\s*"(?:radio|checkbox)"~', $attrs) === 1) {
                    continue;
                }

                $counts[$key][] = $form[2] + substr_count($form[0], "\n", 0, (int) $field[1][1]);
            }

            foreach ($counts as $key => $positions) {
                if (count($positions) < 2) {
                    continue;
                }

                // Строка второго поля: именно оно перетирает первое при отправке формы.
                $found[] = $relative . ': строка ' . $positions[1]
                    . ': поле «' . $key . '» встречается ' . count($positions) . ' раза в одной форме';
            }
        }

        return $found;
    }

    /**
     * Значения for, повторённые в файле: две подписи на одно поле.
     *
     * Значения, собранные PHP (`for="attr_' . $id . '"`), пропускаются: в исходнике это разные
     * подписи на разные поля, а совпадает у них только начало строки — сравнение по литералу
     * дало бы находку на исправном экране (характеристики товара, урок курса, акции магазина).
     *
     * @return list<string>
     */
    private function duplicateLabelViolations(string $code, string $relative): array
    {
        preg_match_all('~<label\b[^>]*?\bfor\s*=\s*"([^"]+)"~s', $code, $matches, PREG_OFFSET_CAPTURE);

        $found = [];
        $counts = [];

        foreach ($matches[1] as $match) {
            $id = (string) $match[0];

            if ($this->isDynamicAttr($id)) {
                continue;
            }

            $counts[$id][] = (int) $match[1];
        }

        foreach ($counts as $id => $positions) {
            if (count($positions) < 2) {
                continue;
            }

            $found[] = $relative . ': строка ' . (1 + substr_count($code, "\n", 0, $positions[0]))
                . ': подпись for="' . $id . '" встречается ' . count($positions) . ' раза';
        }

        return $found;
    }

    /**
     * Значение атрибута собрано PHP, а не записано литералом.
     *
     * Признак — закрывающая кавычка PHP-строки или знак переменной внутри значения: у литерала
     * их не бывает, а у склейки `attr_' . $id . '` они есть всегда.
     */
    private function isDynamicAttr(string $value): bool
    {
        return str_contains($value, "'") || str_contains($value, '$');
    }

    /**
     * Формы файла: содержимое от <form …> до ближайшего </form>. Вложенных форм проект не пишет —
     * это недопустимая разметка, и страж CsrfCoverageTest следит за тем же соглашением.
     *
     * Строка первого поля формы считается по началу тега <form>: содержимое всегда начинается
     * в строке с самим тегом, поэтому смещение поля отсчитывается от неё.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function forms(string $code): array
    {
        preg_match_all('~<form\b[^>]*>(.*?)</form>~s', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $forms = [];

        foreach ($matches as $match) {
            $forms[] = [
                (string) $match[1][0],
                1 + substr_count($code, "\n", 0, (int) $match[0][1]),
                1 + substr_count($code, "\n", 0, (int) $match[1][1]),
            ];
        }

        return $forms;
    }

    /** @return list<string> */
    private function templates(): array
    {
        $found = [];

        foreach (self::MARKUP_ROOTS as $dir) {
            $root = $this->root() . '/' . $dir;

            if (!is_dir($root)) {
                continue;
            }

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
