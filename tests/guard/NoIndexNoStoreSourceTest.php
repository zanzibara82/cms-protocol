<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Правило пакета V: страница с меткой запрета индексирования обязана запрещать и кэширование.
// Механизм живёт в `render()` (`core/template.php`), но автономные экраны печатаются мимо него
// и получают заголовок явно. Обход `tests/http/NoIndexNoStoreSweepTest.php` сверяет связку
// только на адресах из таблицы маршрутов, поэтому экран, который печатается минуя
// маршрутизатор, ему не виден. Этот страж читает исходники: он краснеет, когда в дереве
// появляется новый автономный экран или когда у существующей точки выдачи пропадает директива.
final class NoIndexNoStoreSourceTest extends TestCase
{
    /** Каркас страницы: метку печатает под условием по `$noindex`, а заголовок отдаёт `render()`. */
    private const LAYOUT = 'theme/layout.php';

    /**
     * Реестр автономных экранов, снят с дерева базы `4f04c38`: шаблон → точки выдачи
     * (продуктовый файл → литерал, по которому находим выдачу в его тексте).
     *
     * @var array<string, array<string, string>>
     */
    private const SCREENS = [
        'core/templates/error.php' => [
            'core/router.php' => "partial('error')",
            'core/bootstrap.php' => 'templates/error.php',
        ],
        'core/templates/not-installed.php' => [
            'public/index.php' => "partial('not-installed')",
        ],
    ];

    /**
     * Две разрешённые формы директивы запрета хранения: элемент массива заголовков `respond()`
     * и собственный вызов `header()`. Точные подстроки — сверка по тексту, а не по номеру строки.
     *
     * @var list<string>
     */
    private const DIRECTIVES = [
        "'Cache-Control' => 'no-store'",
        "header('Cache-Control: no-store')",
    ];

    /** Файл, для которого сейчас ищем обрамляющий блок: нужен текстом падения при разбалане скобок. */
    private string $scannedFile = '';

    public function testРеестрЭкрановСNoindexСовпадаетСДеревом(): void
    {
        $found = $this->autonomousTemplates();
        // Пустой список — не «нечего проверять», а выключенный страж: без этой строки обход
        // молча проходил бы, стоит разметке экрана переехать или переименоваться.
        self::assertNotEmpty($found, 'Ни одного шаблона с меткой `noindex` не найдено — страж проверяет ноль экранов и молчит');

        $expected = array_merge([self::LAYOUT], array_keys(self::SCREENS));
        sort($expected);

        $unlisted = array_values(array_diff($found, $expected));
        self::assertSame([], $unlisted, 'Найден автономный экран вне реестра — новый экран обязан перейти на `render()` или попасть в реестр вместе со своей директивой запрета хранения: ' . implode(', ', $unlisted));

        $stale = array_values(array_diff($expected, $found));
        self::assertSame([], $stale, 'Файл из реестра больше не печатает метку `noindex` — строка реестра устарела: ' . implode(', ', $stale));
    }

    public function testМеханизмКаркасаНесётNoStoreВВеткеNoindex(): void
    {
        $layout = (string) file_get_contents($this->path(self::LAYOUT));
        $metaPosition = strpos($layout, 'name="robots"');
        self::assertNotFalse($metaPosition, 'Каркас больше не печатает метку `name="robots"` — механизм `noindex` потерян');

        // Метка обязана стоять внутри ветки с условием по `$noindex`: между ближайшим сверху
        // `<?php if` и самой меткой не должно быть `endif`, а условие должно проверять `$noindex`.
        $beforeMeta = substr($layout, 0, $metaPosition);
        $conditionStart = strrpos($beforeMeta, '<?php if');
        self::assertNotFalse($conditionStart, 'Метка каркаса не стоит ни под каким условием — `noindex` печатался бы всегда');
        $branchText = substr($beforeMeta, $conditionStart);
        self::assertStringContainsString('$noindex', $branchText, 'Условие перед меткой каркаса не проверяет `$noindex` — метка отсоединилась от правила');
        self::assertStringNotContainsString('endif', $branchText, 'Ветка условия закрыта до метки каркаса — метка печатается вне `noindex`');

        $template = (string) file_get_contents($this->path('core/template.php'));
        $directivePosition = strpos($template, self::DIRECTIVES[1]);
        self::assertNotFalse($directivePosition, 'Вызов `header(\'Cache-Control: no-store\')` исчез из `core/template.php` — страницы с `noindex` снова зависят от лимитера сессии');

        // Тот же заголовок обязан жить внутри ветки, проверяющей `$noindex` и `headers_sent()`:
        // сверяем текст блока и непосредственно предшествующее ему условие, а не номер строки.
        $this->scannedFile = 'core/template.php';
        $block = $this->blockAround($template, $directivePosition);
        $blockStart = strpos($template, $block);
        self::assertNotFalse($blockStart, 'Блок директивы не найден в тексте `core/template.php`');
        $condition = substr($template, max(0, $blockStart - 200), min(200, $blockStart));
        self::assertStringContainsString('$noindex', $condition, 'Ветка, напечатавшая директиву каркаса, не проверяет `$noindex`');
        self::assertStringContainsString('headers_sent()', $condition, 'Ветка, напечатавшая директиву каркаса, не проверяет `headers_sent()`');
    }

    public function testКаждаяТочкаВыдачиНесётNoStore(): void
    {
        $violations = [];

        foreach (self::SCREENS as $templatePath => $points) {
            self::assertFileExists($this->path($templatePath), 'Шаблон автономного экрана из реестра исчез из дерева: ' . $templatePath);

            foreach ($points as $file => $literal) {
                $code = $this->read($file);
                if ($code === null) {
                    $violations[] = 'Файл точки выдачи `' . $file . '` для шаблона `' . $templatePath . '` не найден в дереве';
                    continue;
                }

                $position = strpos($code, $literal);
                if ($position === false) {
                    $violations[] = 'Файл `' . $file . '` больше не содержит литерал выдачи `' . $literal . '` шаблона `' . $templatePath . '`';
                    continue;
                }

                $this->scannedFile = $file;
                $block = $this->blockAround($code, $position);
                if (!$this->blockCarriesDirective($block)) {
                    $violations[] = 'Точка выдачи `' . $file . '` (шаблон `' . $templatePath . '`) не несёт директиву запрета хранения — экран помечен `noindex`, а его ответ остаётся кэшируемым';
                }
            }
        }

        self::assertSame([], $violations, 'Каждая точка выдачи автономного экрана обязана запрещать и хранение ответа');
    }

    public function testНовыйФайлВыдачиНеПроскакиваетБезNoStore(): void
    {
        $violations = [];

        foreach (self::SCREENS as $templatePath => $points) {
            $literals = array_values($points);
            $found = [];

            foreach ($this->productSources() as $relative => $code) {
                foreach ($literals as $literal) {
                    if (str_contains($code, $literal)) {
                        $found[$relative] = true;
                        break;
                    }
                }
            }

            $expected = array_keys($points);
            $extra = array_values(array_diff(array_keys($found), $expected));
            foreach ($extra as $file) {
                $violations[] = 'Шаблон `' . $templatePath . '` печатается из нового файла `' . $file . '` — путь выдачи обязан попасть в таблицу реестра вместе со своей директивой запрета хранения';
            }

            $missing = array_values(array_diff($expected, array_keys($found)));
            foreach ($missing as $file) {
                $violations[] = 'Названная в таблице точка выдачи `' . $file . '` для шаблона `' . $templatePath . '` не найдена обходом `core/`, `public/` и `apps/` — таблица реестра устарела';
            }
        }

        self::assertSame([], $violations, 'Множество файлов выдачи обязано совпадать с таблицей реестра — сличение по путям, а не по числу записей');
    }

    /**
     * Ближайший блок `{ ... }`, содержащий позицию: сдвиг назад до непарной открывающей скобки
     * и парный счёт скобок вперёд. Позиция уровня файла, где обрамляющего блока нет, отдаёт
     * весь код. Строковые литералы в одинарных и двойных кавычках пропускаются целиком —
     * скобка внутри текста не задаёт границу; дефект посчитан и закрыт в
     * `tests/guard/NewsVisibilityTest.php` коммитом `4f04c38`, но помощники оттуда приватны
     * в чужом классе, поэтому обход переписан здесь. Интерполяция вида `"{$x}"` и here-doc
     * под пропуск не попадают: проверка отвечает на измеренный дефект, а не строит лексер.
     * Разбалан скобок падает с именем файла и номером строки, а не молча возвращает хвост.
     */
    private function blockAround(string $code, int $position): string
    {
        $masked = $this->maskQuoted($code);
        $length = strlen($masked);

        $depth = 0;
        $open = false;
        for ($i = min($position, $length) - 1; $i >= 0; --$i) {
            if ($masked[$i] === '}') {
                ++$depth;
            } elseif ($masked[$i] === '{') {
                if ($depth === 0) {
                    $open = $i;
                    break;
                }
                --$depth;
            }
        }

        if ($open === false) {
            if ($depth > 0) {
                self::fail('Скобки разбалансированы: в файле ' . $this->scannedFile . ' закрывающих фигурных скобок больше, чем открывающих, до позиции ' . $position . ' (строка ' . ($this->lineOf($code, $position)) . ')');
            }

            return $code;
        }

        $depth = 0;
        $close = false;
        for ($i = $open; $i < $length; ++$i) {
            if ($masked[$i] === '{') {
                ++$depth;
            } elseif ($masked[$i] === '}') {
                --$depth;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }

        if ($close === false) {
            self::fail('Сборщик потерял закрывающую скобку: файл ' . $this->scannedFile . ', блок открыт на позиции ' . $open . ' (строка ' . ($this->lineOf($code, (int) $open)) . ') и до конца файла не закрыт');
        }

        return substr($code, (int) $open, (int) $close - (int) $open + 1);
    }

    /**
     * Копия кода той же длины, где содержимое строковых литералов заменено пробелами:
     * переводы строк и кавычки остаются, поэтому позиция символа не съезжает.
     * Незакрытая строка доходит до конца кода — по тому же правилу, что у сборщика
     * `tests/guard/NewsVisibilityTest.php`.
     */
    private function maskQuoted(string $code): string
    {
        $chars = str_split($code);
        $length = count($chars);

        for ($i = 0; $i < $length; ++$i) {
            $quote = $chars[$i];
            if ($quote !== "'" && $quote !== '"') {
                continue;
            }

            for (++$i; $i < $length; ++$i) {
                if ($chars[$i] === '\\') {
                    ++$i;
                    continue;
                }

                if ($chars[$i] === $quote) {
                    break;
                }

                if ($chars[$i] !== "\n" && $chars[$i] !== "\r") {
                    $chars[$i] = ' ';
                }
            }
        }

        return implode('', $chars);
    }

    /** Номер строки позиции в исходном тексте: единица плюс число переводов до неё. */
    private function lineOf(string $code, int $position): int
    {
        return substr_count($code, "\n", 0, $position) + 1;
    }

    /** Есть ли в тексте блока хотя бы одна из двух разрешённых форм директивы. */
    private function blockCarriesDirective(string $block): bool
    {
        foreach (self::DIRECTIVES as $directive) {
            if (str_contains($block, $directive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Обход шаблонов по образцу реестра (пункт М284-Г2-3): обход рекурсивный — файлы берутся со
     * всего дерева каталогов `theme` и `core/templates` и со всего дерева `templates` каждого
     * приложения в `apps`; файл автономен, если одна и та же строка несёт и `name="robots"`,
     * и `noindex`.
     *
     * Прежний плоский `glob` читал один уровень: автономный экран, положенный в `theme/partials/`
     * или `core/templates/admin/`, страж не видел вовсе, хотя докблок обещал каталоги «целиком».
     *
     * @return list<string> относительные пути, отсортированные
     */
    private function autonomousTemplates(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        // Порог непустоты и сверка существования каталога живут в `phpFiles()`: она падает и на
        // исчезнувшем каталоге, и на каталоге без единого PHP-файла, поэтому прежние
        // `assertNotEmpty` по узорам `*.php` убраны как дубли.
        foreach ([$root . '/theme', $root . '/core/templates'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $files[] = $file;
            }
        }

        // Приложений в дереве может стать больше — число каталогов не закрепляется, но ноль
        // означает, что папка шаблонов приложений уехала, и это падение, а не «нечего проверять».
        $templatesDirs = glob($root . '/apps/*/templates', GLOB_ONLYDIR) ?: [];
        self::assertNotEmpty($templatesDirs, 'Обход apps/*/templates не отдал ни одного каталога — шаблоны приложений исчезли');

        foreach ($templatesDirs as $templatesDir) {
            foreach ($this->phpFiles($templatesDir) as $file) {
                $files[] = $file;
            }
        }

        $found = [];
        foreach ($files as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $line) {
                if (str_contains($line, 'name="robots"') && str_contains($line, 'noindex')) {
                    $found[] = $this->relative($file);
                    break;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * Продуктовые исходники для обхода точек выдачи: `core/`, `public/` и `apps/` целиком,
     * кроме `public/assets/**` — там лежат собранные артефакты стилей, а не PHP.
     *
     * @return array<string, string> относительный путь → содержимое
     */
    private function productSources(): array
    {
        $out = [];

        foreach (['core', 'public', 'apps'] as $dir) {
            foreach ($this->phpFiles(dirname(__DIR__, 2) . '/' . $dir) as $file) {
                $relative = $this->relative($file);
                if (str_starts_with($relative, 'public/assets/')) {
                    continue;
                }

                $out[$relative] = (string) file_get_contents($file);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        self::assertDirectoryExists(
            $dir,
            $dir . ': каталог исчез из дерева — страж автономных экранов проверял бы меньше, чем требует правило'
        );

        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $out[] = $item->getPathname();
            }
        }

        self::assertNotEmpty($out, $dir . ': каталог не отдал ни одного PHP-файла — проверять нечего');

        sort($out);

        return $out;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    /** Содержимое продукта по относительному пути или `null`, если файла нет. */
    private function read(string $relative): ?string
    {
        $path = $this->path($relative);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** Относительный путь от корня репозитория с прямыми слэшами на любой платформе. */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
