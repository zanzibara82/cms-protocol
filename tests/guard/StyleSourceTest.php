<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Сборка стилей обязана зависеть от разметки, а не от того, какие слова случайно написаны рядом.
// До явных @source CLI Tailwind v4 сканировал всё рабочее дерево и вычитал только то, что лежит
// в .gitignore: на состоянии до этой правки страж перечислял 60 классов артефакта (из 171), которые
// не объяснялись ни одним объявленным источником — они пришли из текста планов в docs/ и из литералов
// тестов, а не со страниц. С явными @source из артефакта ушло 64 правила, и ни одно из них не
// используется в разметке. Страницы при этом не изменились бы никак, но артефакт пересобирался бы
// при правке плана — и пропадал класс ровно там, где его никто не ждал.
//
// Страж проверяет четыре независимых вещи:
// 1) конфигурация сборки не скатывается к автосканированию и объявляет каждый каталог разметки;
// 2) экран, добавленный вне объявленных каталогов, не остаётся незамеченным сборкой;
// 3) в артефакте нет ни одного класса, которого нельзя найти в объявленных источниках;
// 4) каждый класс из атрибутов разметки лежит в артефакте, включая обязательную зону нажатия.
final class StyleSourceTest extends TestCase
{
    /** Каталоги разметки: они же обязаны быть объявлены директивами @source в src-css/app.css. */
    private const MARKUP_DIRS = ['theme', 'admin', 'core/templates', 'apps'];

    /** Где вообще ядро и приложения печатают HTML: просвет между этим списком и MARKUP_DIRS — находка. */
    private const PRINT_DIRS = ['core', 'admin', 'theme', 'apps', 'public'];

    /** Классы пункта зон нажатия: их отсутствие ломает 44 px на живых страницах. */
    private const REQUIRED_CLASSES = ['min-h-[44px]', 'inline-flex', 'items-center'];

    private const SOURCE_FILE = 'src-css/app.css';
    private const ARTIFACT_FILE = 'public/assets/app.css';

    public function testСборкаВидитРазметкуАНеПрозуПланов(): void
    {
        $config = $this->sourceConfig();
        $files = $this->markupFiles();

        self::assertNotEmpty($files, 'Ни одного файла разметки не найдено — проверять нечего');
        self::assertSame([], $this->sourceProblems($config), 'Конфигурация сборки стилей разошлась с разметкой');

        // Пустой каталог разметки — это тоже падение: @source по несуществующему пути молча
        // собирает ноль классов, и артефакт остаётся «свежим» без единого класса страницы.
        foreach (self::MARKUP_DIRS as $dir) {
            self::assertDirectoryExists($this->root() . '/' . $dir, 'Каталог разметки ' . $dir . ' исчез');
        }
    }

    // Самопроверка: каждая из трёх форм обхода обязана давать находку, а рабочий конфиг — нет.
    public function testСтражЛовитАвтосканированиеИПропущенныйКаталог(): void
    {
        $working = $this->sourceConfig();
        self::assertSame([], $this->sourceProblems($working), 'Рабочая конфигурация сборки помечена как сломанная');

        $autoscanned = (string) preg_replace('~source\(none\)~', '', $working);
        self::assertNotEmpty($this->sourceProblems($autoscanned), 'Скатывание к автосканированию дерева не поймано');

        foreach (self::MARKUP_DIRS as $dir) {
            $missing = (string) preg_replace('~@source[^;]*' . preg_quote($dir, '~') . '/[^;]*;~', '', $working);
            self::assertNotEmpty(
                $this->sourceProblems($missing),
                'Пропущенный @source для каталога ' . $dir . ' не пойман'
            );
        }

        // Источник, который ничего не даёт странице, но подмешивает в артефакт прозу.
        self::assertNotEmpty(
            $this->sourceProblems($working . "\n" . '@source "../docs/**/*.md";'),
            'Объявленный источник из docs/ не пойман'
        );
        self::assertNotEmpty(
            $this->sourceProblems($working . "\n" . '@source "../tests/**/*.php";'),
            'Объявленный источник из tests/ не пойман'
        );

        // Без импорта Tailwind артефакт не собирается вовсе: молча зелёным это быть не должно.
        self::assertNotEmpty($this->sourceProblems('@source "../theme/**/*.php";'), 'Сборка без @import не поймана');
    }

    public function testРазметкаНеРастётВнеОбъявленныхКаталогов(): void
    {
        $withMarkup = [];

        foreach ($this->files(self::PRINT_DIRS) as $path) {
            if (str_contains((string) file_get_contents($path), 'class=')) {
                $withMarkup[] = $this->relative($path);
            }
        }

        self::assertNotEmpty($withMarkup, 'Ни один файл проекта не печатает атрибут class — список каталогов разошёлся с деревом');
        self::assertSame(
            [],
            $this->outsideMarkupDirs($withMarkup),
            'Разметка появилась в каталоге, который сборка стилей не читает: её классы не соберутся'
        );
    }

    public function testСтражЛовитЭкранВнеСписокКаталоговСборки(): void
    {
        self::assertSame([], $this->outsideMarkupDirs(['theme/login.php', 'core/templates/setup.php', 'apps/news/templates/feed.php']));
        self::assertSame(
            ['public/setup.php'],
            $this->outsideMarkupDirs(['theme/login.php', 'public/setup.php']),
            'Экран с разметкой в public/ не найден: его классы не попадут в артефакт'
        );
        self::assertSame(
            ['core/form.php'],
            $this->outsideMarkupDirs(['core/form.php']),
            'Разметка ядра вне core/templates не найдена'
        );
    }

    public function testАртефактНеСодержитКлассовВнеОбъявленныхИсточников(): void
    {
        $artifact = $this->artifact();
        $sources = $this->sourcesBlob();

        self::assertGreaterThan(10, count($this->classSelectors($artifact)), 'В артефакте почти нет классов — сборка нечего не собрала');
        self::assertNotEmpty($sources, 'Источники сборки пусты — сравнивать артефакт не с чем');

        $unexplained = $this->unexplainedClasses($artifact, $sources);

        self::assertSame(
            [],
            $unexplained,
            'Артефакт содержит классы, которых нет ни в разметке, ни в ручных стилях (пришли из прозы планов или текстов тестов): '
            . implode(', ', $unexplained)
        );
    }

    public function testСтражЛовитКлассАртефактаБезРазметки(): void
    {
        // Класс-подстрока уже использованного имени: «flex» внутри «inline-flex» не считается
        // объяснённым — иначе правка плана с одним потерянным словом добавляла бы правило молча.
        $artifact = '.flex{display:flex}.inline-flex{display:inline-flex}.sm\\:hidden{display:none}';
        $sources = '<div class="inline-flex">';

        self::assertSame(['flex', 'sm:hidden'], $this->unexplainedClasses($artifact, $sources));
        self::assertSame([], $this->unexplainedClasses($artifact, $sources . '<a class="flex sm:hidden">'));

        // Вариант с префиксом не объясняет голое правило: `.bg-ink/5` в артефакте остаётся мёртвым,
        // если в разметке есть только `hover:bg-ink/5`.
        $prefixed = '.bg-ink\/5{background-color:red}.hover\\:bg-ink\/5:hover{background-color:red}';
        self::assertSame(
            ['bg-ink/5'],
            $this->unexplainedClasses($prefixed, '<a class="hover:bg-ink/5">'),
            'Голое правило объяснилось вариантом с префиксом: мёртвая утилита прошла бы незамеченной'
        );
        self::assertSame([], $this->unexplainedClasses($prefixed, '<a class="bg-ink/5 hover:bg-ink/5">'));
    }

    public function testКлассыРазметкиСобралисьВАртефакте(): void
    {
        $classes = $this->classSelectors($this->artifact());

        foreach (self::REQUIRED_CLASSES as $required) {
            self::assertContains($required, $classes, 'Класс ' . $required . ' обязан быть собран: без него зона нажатия исчезает');
        }

        $tokens = [];
        foreach ($this->markupFiles() as $path) {
            foreach ($this->classAttributeTokens((string) file_get_contents($path)) as $token) {
                $tokens[$token] = $this->relative($path);
            }
        }

        self::assertGreaterThan(40, count($tokens), 'Разметка почти не даёт классов — проверка собралась в ноль');

        $missing = array_values(array_diff(array_keys($tokens), $classes));

        self::assertSame(
            [],
            $missing,
            'Классы используются в разметке, но не собраны в артефакт: ' . implode(', ', array_map(
                static fn (string $class): string => $class . ' (' . $tokens[$class] . ')',
                $missing
            )) . '. Нужен пересборка стилей: bash scripts/css'
        );
    }

    public function testСтражЛовитНесобранныйКлассРазметки(): void
    {
        $artifact = '.inline-flex{display:inline-flex}.cat-idle{animation:none}';

        self::assertSame(['sm:hidden'], $this->missingClasses('<div class="inline-flex sm:hidden">', $artifact));
        self::assertSame([], $this->missingClasses('<div class="inline-flex">', $artifact));
        // Ручной класс из src-css/app.css не утилита Tailwind, и его отсутствие среди утилит
        // находкой не считается: он попадает в артефакт как написанное правило.
        self::assertSame([], $this->missingClasses('<span class="cat-idle">', $artifact));
        // Обрывки PHP-разрыва и имена переменных классами не являются.
        self::assertSame([], $this->missingClasses('<a class="<?= $kind ?>">', $artifact));
        // Кавычка у токена не прячет класс: «bg-ink» и «text-cream» обязаны быть проверены.
        self::assertSame(
            ['bg-ink', 'text-cream'],
            $this->missingClasses('<a class="bg-ink\' . $kind . \'text-cream">', $artifact),
            'Класс, склеенный из литералов вокруг переменной, выпал из проверки'
        );
        self::assertSame([], $this->missingClasses(
            '<a class="bg-ink\' . $kind . \'text-cream">',
            $artifact . '.bg-ink{color:black}.text-cream{color:white}'
        ));
    }

    /** @return list<string> */
    private function sourceProblems(string $config): array
    {
        $problems = [];

        if (preg_match('~@import~', $config) !== 1) {
            $problems[] = 'в ' . self::SOURCE_FILE . ' нет @import tailwindcss — собирать нечего';
        } elseif (preg_match('~@import[\n\r\t ]+[\'"]tailwindcss[\'"][\n\r\t ]+source\(none\)~', $config) !== 1) {
            $problems[] = '@import tailwindcss без source(none): сборка сканирует всё дерево и берёт классы из docs/ и tests/';
        }

        foreach (self::MARKUP_DIRS as $dir) {
            if (preg_match('~@source[^;]*' . preg_quote($dir, '~') . '/[^;]*~', $config) !== 1) {
                $problems[] = 'каталог разметки ' . $dir . ' не объявлен директивой @source — его классы не соберутся';
            }
        }

        if (preg_match_all('~@source[\n\r\t ]+"([^"]*)"~', $config, $found) > 0) {
            foreach ($found[1] as $pattern) {
                if (preg_match('~(^|/|\.\./)(docs|tests|node_modules|vendor)(/|$)~', $pattern) === 1) {
                    $problems[] = '@source "' . $pattern . '" объявляет источником прозу или тесты, а не разметку';
                }
            }
        }

        return $problems;
    }

    /**
     * Файлы, которые печатают разметку, но лежат вне каталогов, объявленных сборке.
     *
     * @param list<string> $withMarkup относительные пути файлов с атрибутом class
     *
     * @return list<string>
     */
    private function outsideMarkupDirs(array $withMarkup): array
    {
        $out = [];

        foreach ($withMarkup as $relative) {
            foreach (self::MARKUP_DIRS as $dir) {
                if (str_starts_with($relative, $dir . '/')) {
                    continue 2;
                }
            }

            $out[] = $relative;
        }

        return $out;
    }

    /** @return list<string> */
    private function unexplainedClasses(string $artifact, string $sources): array
    {
        $out = [];

        foreach ($this->classSelectors($artifact) as $class) {
            // Граница токена обязательна: иначе «flex» считается объяснённым классом «inline-flex».
            // Двоеточие тоже ограничитель, а не продолжение: «hover:bg-ink/5» в разметке не объясняет
            // голое правило `.bg-ink/5` в артефакте — у Tailwind это разные утилиты.
            if (preg_match('~(?:^|[^A-Za-z0-9_\-:])' . preg_quote($class, '~') . '(?:[^A-Za-z0-9_]|\z)~m', $sources) !== 1) {
                $out[] = $class;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function missingClasses(string $code, string $artifact): array
    {
        $classes = $this->classSelectors($artifact);

        return array_values(array_diff($this->classAttributeTokens($code), $classes));
    }

    /**
     * Классы из атрибутов class. Пробел задан списком байтов, а не «\s»: без модификатора «u»
     * PCRE считает пробелом байт 0x85, который служит вторым байтом кириллицы.
     *
     * @return list<string>
     */
    private function classAttributeTokens(string $code): array
    {
        $tokens = [];

        if (preg_match_all('~class[\n\r\t ]*=[\n\r\t ]*"([^"]*)"~', $code, $found) > 0) {
            foreach ($found[1] as $value) {
                foreach (preg_split('~[\n\r\t ]+~', trim($value)) ?: [] as $raw) {
                    $token = trim($raw, '\'"');
                    if ($token === '' || preg_match('~^[a-z0-9][a-z0-9:_/.#\[\]%-]*$~', $token) !== 1) {
                        continue;
                    }

                    $tokens[$token] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    /**
     * Селекторы-классы stylesheet'а: селектором считается текст между «}» (или «;», или началом
     * файла) и ближайшим «{». Блоковые комментарии вырезаны, иначе проза внутри них сочиняла бы
     * классы из подсказок в кавычках.
     *
     * @return list<string>
     */
    private function classSelectors(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', ' ', $css);
        $classes = [];
        $buffer = '';

        for ($position = 0; $position < strlen($css); $position++) {
            $character = $css[$position];
            if ($character === '{') {
                if (preg_match_all('~\.((?:\\\\.|[A-Za-z0-9_\-])+)~', $buffer, $found) > 0) {
                    foreach ($found[1] as $selector) {
                        $classes[str_replace('\\', '', $selector)] = true;
                    }
                }
            }

            if ($character === '{' || $character === '}' || $character === ';') {
                $buffer = '';
                continue;
            }

            $buffer .= $character;
        }

        return array_keys($classes);
    }

    private function sourceConfig(): string
    {
        $path = $this->root() . '/' . self::SOURCE_FILE;
        self::assertFileExists($path, 'Нет исходника стилей ' . self::SOURCE_FILE);

        return (string) file_get_contents($path);
    }

    private function artifact(): string
    {
        $path = $this->root() . '/' . self::ARTIFACT_FILE;
        self::assertFileExists($path, 'Нет собранного ' . self::ARTIFACT_FILE . ' — артефакт лежит в репозитории');

        return (string) file_get_contents($path);
    }

    private function sourcesBlob(): string
    {
        $out = '';

        foreach ($this->markupFiles() as $path) {
            $out .= (string) file_get_contents($path) . "\n";
        }

        return $out . $this->sourceConfig();
    }

    /** @return list<string> */
    private function markupFiles(): array
    {
        $paths = [];

        foreach (self::MARKUP_DIRS as $dir) {
            foreach ($this->files([$dir]) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * PHP-файлы каталогов: каталог обязана существовать и отдать хотя бы один файл, иначе
     * молча пустой список превратил бы страж в зелёную заглушку.
     *
     * @param list<string> $dirs
     *
     * @return list<string>
     */
    private function files(array $dirs): array
    {
        $root = $this->root();
        $paths = [];

        foreach ($dirs as $dir) {
            $absolute = $root . '/' . $dir;
            self::assertDirectoryExists($absolute, 'Каталог ' . $dir . ' исчез из проекта');

            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute));
            $found = 0;

            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $paths[] = $item->getPathname();
                    $found++;
                }
            }

            self::assertGreaterThan(0, $found, 'Каталог ' . $dir . ' не отдал ни одного PHP-файла — проверять нечего');
        }

        return $paths;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($this->root()) + 1));
    }
}
