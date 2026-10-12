<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Страж строгости самих инструментов (пункт M-15 широкой рецензии ветки): ворота ровно так сильны,
// как их настройки. Стереть `level: 6` в `phpstan.neon`, выключить флаг в `phpunit.xml` или вырезать
// шаг из `scripts/gate.sh` сегодня нечем: прогон остаётся зелёным, а дерево под ним перестаёт
// проверяться. Поэтому этот страж читает три файла конфигурации как текст, ничего не запускает
// и не зависит от того, установлен ли PHP или помощники в окружении: отсутствие шага или флага
// видно по самому файлу. Каждый assert отвечает на вопрос «что начнёт проходить мимо ворот, если
// эту строку убрать». Фикс-раунд M2 усилил проверки ровно там, где рецензия нашла «проверка слабее,
// чем заявлено»: каноническое имя секции подавления ошибок, маски исключения путей, различение шага
// ворот по признакам самой работы, требование, чтобы вызовы доходили до исполнения, связка имени
// набора с его каталогом, полнота путей анализа и состав собственного эталона вместо счётчика.
final class ToolStrictnessTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    // Минимальный уровень `phpstan.neon`: ниже — это ослабление анализа типов, а не настройка.
    private const PHPSTAN_MIN_LEVEL = 6;

    // Корневые каталоги кода и тестов, которые анализ обязан покрывать (пункт Т4): список путей
    // анализа не имеет права сузиться, иначе снятый каталог перестаёт проверяться молча.
    private const PHPSTAN_ANALYSED_PATHS = ['core', 'admin', 'theme', 'apps', 'public', 'tests', 'scripts'];

    // Имена секций подавления ошибок анализатора (пункт Т1): прежний список смотрел на ключи
    // `ignore` и `ignore_errors`, а настоящее имя секции у PHPStan — `ignoreErrors`, поэтому
    // проверка не могла покраснеть ни на одном настоящем подавлении. `reportUnmatchedIgnoredErrors`
    // глушит отчёт о неиспользованных подавлениях, `baselineNeon` подключает файл сохранённого
    // эталона подавлений. Ни одного из этих ключей в одобренном дереве нет: запрет не требует
    // правки `phpstan.neon` и не ослабляет ворота.
    private const PHPSTAN_SUPPRESSION_KEYS = [
        'ignore_errors',
        'ignore',
        'ignoreErrors',
        'reportUnmatchedIgnoredErrors',
        'baselineNeon',
    ];

    // Флаги строгости `phpunit.xml`, списанные дословно из файла: каждый закрывает свой шум
    // прогона — предупреждение, notice, устаревание, рискованный тест или печать вместо проверки.
    private const PHPUNIT_STRICT_FLAGS = [
        'failOnWarning' => 'true',
        'failOnNotice' => 'true',
        'failOnDeprecation' => 'true',
        'failOnRisky' => 'true',
        'beStrictAboutOutputDuringTests' => 'true',
    ];

    // Наборы тестов, заявленные в `phpunit.xml`: список имеет право расти и не имеет права
    // сокращаться или терять имя — иначе проворот набора остаётся незамеченным.
    private const PHPUNIT_SUITES = ['unit', 'db', 'guard', 'http'];

    // Пять шагов ворот и признаки самой работы каждого (пункт Т2): не подстрока имени исполняемого
    // файла — она стоит в `scripts/gate.sh` трижды, в проверке наличия сборщика, в тексте об его
    // отсутствии и в самой сборке, — а вызов с явными аргументами и точкой сравнения. У шага обязаны
    // найтись все перечисленные признаки: вырезанный блок сборки и побайтового сравнения оставляет
    // в файле имя сборщика, но лишает сверку артефакта, и по одной подстроке имени такой срез
    // оставался зелёным.
    /** @var array<string, list<string>> */
    private const GATE_STEPS = [
        'свежесть стилей' => ['node_modules/.bin/tailwindcss -i ./src-css/app.css', 'cmp -s "$css_tmp/app.css" public/assets/app.css'],
        'синтаксис' => ['php -l'],
        'статический анализ' => ['vendor/bin/phpstan analyse --no-progress'],
        'стиль' => ['vendor/bin/php-cs-fixer fix --dry-run --diff'],
        'тесты' => ['vendor/bin/phpunit'],
    ];

    // Независимый эталон имени инструмента каждого шага (пункт Т6): собственная проверка полноты
    // сверяет, что значение `GATE_STEPS` описывает вызов именно этого инструмента, а не безобидную
    // подстроку, найденную в файле ворот и оставившую зелёный прогон.
    /** @var array<string, string> */
    private const GATE_STEP_EXECUTABLES = [
        'свежесть стилей' => 'node_modules/.bin/tailwindcss',
        'синтаксис' => 'php -l',
        'статический анализ' => 'vendor/bin/phpstan',
        'стиль' => 'vendor/bin/php-cs-fixer',
        'тесты' => 'vendor/bin/phpunit',
    ];

    // Строка запуска каждого шага (пункт Т3): «вызов есть» не равно «шаг исполняется». Признак
    // обязан стоять в позиции запуска команды, а не внутри текста; для прогонов инструментов строка
    // обязана оканчиваться своими аргументами — иначе набор можно сузить фильтром или путём,
    // сохранив подстроку.
    /** @var array<string, list<string>> */
    private const GATE_STEP_LAUNCHES = [
        'свежесть стилей' => [
            '#^node_modules/\.bin/tailwindcss -i \./src-css/app\.css\b#',
            '#^cmp -s "\$css_tmp/app\.css" public/assets/app\.css\b#',
        ],
        'синтаксис' => ['#^xargs -0 -r -n1 php -l\b#'],
        'статический анализ' => ['#^run vendor/bin/phpstan analyse --no-progress$#'],
        'стиль' => ['#^run vendor/bin/php-cs-fixer fix --dry-run --diff$#'],
        'тесты' => ['#^run vendor/bin/phpunit$#'],
    ];

    public function testPhpstanУровеньНеНижеШести(): void
    {
        $neon = $this->read('phpstan.neon');

        $matched = [];
        if (preg_match('/^\s*level:\s*(\d+)/m', $neon, $matched) !== 1) {
            self::fail('В `phpstan.neon` не найдена строка `level` — уровень анализа обязан быть задан явно, молчаливое значение по умолчанию недопустимо');
        }

        $level = (int) $matched[1];
        self::assertGreaterThanOrEqual(
            self::PHPSTAN_MIN_LEVEL,
            $level,
            'В `phpstan.neon` уровень анализа опущен до ' . $level . ': требуется не ниже ' . self::PHPSTAN_MIN_LEVEL
        );
    }

    public function testPhpstanБезСекцийПодавленияОшибок(): void
    {
        $neon = $this->read('phpstan.neon');

        // Пункт Т1: имена берутся из списка, который включает каноническое `ignoreErrors` и ключи
        // сохранённого эталона, — прежний набор ловил только написания, которых у анализатора нет.
        $suppressions = [];
        foreach (self::PHPSTAN_SUPPRESSION_KEYS as $key) {
            if (preg_match('/^\s*' . $key . '\s*:/mi', $neon) === 1) {
                $suppressions[] = $key;
            }
        }

        self::assertSame(
            [],
            $suppressions,
            'В `phpstan.neon` найдена секция подавления ошибок (' . implode(', ', $suppressions) . '): поблажки в анализе запрещены'
        );
    }

    public function testPhpstanИсключенияНеНакрываютПроверяемыйКод(): void
    {
        // Пункт Т7: `excludePaths: analyse:` исключает файлы, о которых сам файл открыто объясняет
        // решение, и поблажкой по ошибкам не является, — но маска не имеет права накрывать
        // проверяемый код. Значение запрещено, если под него попадает хоть один проверяемый файл
        // скрипта: сюда же входят широкие маски вида `core/**`, `apps/**` и `**/*`. Одобренное
        // значение `public/assets/*` остаётся зелёным: файлов скрипта в этом каталоге нет, поэтому
        // правка `phpstan.neon` не нужна и ворота не ослабляются.
        $neon = $this->read('phpstan.neon');
        $masks = $this->excludeAnalyseMasks($neon);
        $phpFiles = $this->analysedPhpFiles();

        $covering = [];
        foreach ($masks as $mask) {
            foreach ($phpFiles as $file) {
                if (preg_match($this->globToRegex($mask), $file) === 1) {
                    $covering[] = $mask . ' накрывает ' . $file;
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $covering,
            'В `phpstan.neon` маска `excludePaths: analyse:` накрывает проверяемый код: ' . implode('; ', $covering)
        );
    }

    public function testPhpstanПутиАнализаПолны(): void
    {
        // Пункт Т4: уровень защищён, но список анализируемых путей страж не смотрел вовсе —
        // каталог можно вычеркнуть, и его код перестанет проверяться молча.
        $neon = $this->read('phpstan.neon');
        $paths = $this->phpstanPaths($neon);

        $missing = [];
        foreach (self::PHPSTAN_ANALYSED_PATHS as $path) {
            if (!in_array($path, $paths, true)) {
                $missing[] = $path;
            }
        }

        self::assertSame(
            [],
            $missing,
            'Из `paths` в `phpstan.neon` исчез каталог анализа (' . implode(', ', $missing) . '): его код перестаёт проверяться, и это не видно по зелёному прогону'
        );
    }

    public function testPhpunitФлагиСтрогостиСохранены(): void
    {
        $xml = $this->read('phpunit.xml');

        $missing = [];
        foreach (self::PHPUNIT_STRICT_FLAGS as $flag => $value) {
            if (preg_match('/\b' . $flag . '="' . $value . '"/', $xml) !== 1) {
                $missing[] = $flag . '="' . $value . '"';
            }
        }

        self::assertSame(
            [],
            $missing,
            'В `phpunit.xml` пропали флаги строгости: ' . implode(', ', $missing) . ' — без них прогон зеленеет на тесте, который ничего не проверяет'
        );
    }

    public function testPhpunitНаборыНеСокращаются(): void
    {
        $xml = $this->read('phpunit.xml');

        $matched = [];
        if (preg_match_all('/<testsuite name="([^"]+)"/', $xml, $matched) === false) {
            self::fail('Разбор списка наборов в `phpunit.xml` не состоялся');
        }

        /** @var list<string> $names */
        $names = array_map('strval', $matched[1]);

        self::assertGreaterThanOrEqual(
            count(self::PHPUNIT_SUITES),
            count($names),
            'Наборов тестов стало меньше четырёх: в `phpunit.xml` найдено только ' . implode(', ', $names)
        );

        $lost = [];
        foreach (self::PHPUNIT_SUITES as $suite) {
            if (!in_array($suite, $names, true)) {
                $lost[] = $suite;
            }
        }

        self::assertSame(
            [],
            $lost,
            'Из `phpunit.xml` исчез набор (наборы): ' . implode(', ', $lost) . ' — прогон такого набора не идёт, и это не видно по зелёному результату'
        );
    }

    public function testPhpunitКаждыйНаборСмотритВСвойКаталог(): void
    {
        // Пункт Т4: имя набора защищено, а его каталог — нет: значение `<directory>` можно навести
        // на другой каталог или снабдить суффиксом, и набор перестанет собирать свои тесты, сохранив
        // имя и счёт «не меньше четырёх». Связываем имя набора с его каталогом: набор `unit` обязан
        // читать `tests/unit`, `http` — `tests/http`, и так для каждого заявленного набора.
        $xml = $this->read('phpunit.xml');
        $suiteDirs = $this->phpunitSuiteDirectories($xml);

        $wrong = [];
        foreach (self::PHPUNIT_SUITES as $suite) {
            $expected = 'tests/' . $suite;
            $found = $suiteDirs[$suite] ?? [];
            $actual = array_map(static fn (array $entry): string => $entry['directory'], $found);

            if (!in_array($expected, $actual, true)) {
                $wrong[] = $suite . ' → ожидался каталог ' . $expected . ', найдено: ' . (implode(', ', $actual) ?: 'ничего');
            }

            foreach ($found as $entry) {
                if ($entry['suffix'] !== '' && $entry['suffix'] !== 'Test.php') {
                    $wrong[] = $suite . ' → каталог ' . $entry['directory'] . ' с суффиксом `' . $entry['suffix']
                        . '`: набор собирал бы чужие файлы или не собирал бы ничего';
                }
            }
        }

        self::assertSame(
            [],
            $wrong,
            'В `phpunit.xml` набор смотрит не в свой каталог: ' . implode('; ', $wrong) . ' — такой набор тихо собирает чужие тесты или не собирает ничего'
        );
    }

    public function testВоротаСодержатВсеПятьШагов(): void
    {
        $gate = $this->read('scripts/gate.sh');

        // Комментарии вырезаются до поиска вызовов: шаг обязан стоять командой, а не упоминанием.
        $code = $this->stripShellComments($gate);

        // Пункт Т2: шаг различается признаками самой работы — программой с аргументами и точкой
        // сравнения, а не подстрокой имени исполняемого файла.
        $missing = [];
        foreach (self::GATE_STEPS as $name => $signs) {
            foreach ($signs as $sign) {
                if (!str_contains($code, $sign)) {
                    $missing[] = 'шаг «' . $name . '» (признак работы `' . $sign . '` не найден)';
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'В `scripts/gate.sh` не найдены шаги ворот по их признакам работы: ' . implode('; ', $missing)
        );
    }

    public function testВоротаЗапускаютШагиДОсполнения(): void
    {
        // Пункт Т3: мимо прежней проверки проходили две тихие порчи ворот — пустая обёртка
        // `run() { :; }` (четыре шага перестают что-либо выполнять, подстроки на месте) и снятый
        // `set -euo pipefail` (коды возврата `php -l`, PHPStan и фиксера перестают влиять на итог:
        // статусом скрипта становится последний вызов). Требуем объявление режима остановки, живое
        // исполнение обёрткой и строку запуска каждого шага с его аргументами.
        $gate = $this->read('scripts/gate.sh');
        $code = $this->stripShellComments($gate);
        $heads = $this->commandHeads($code);

        $problems = [];

        if (preg_match('/^set -euo pipefail\b/m', $gate) !== 1) {
            $problems[] = 'нет строки `set -euo pipefail`: без остановки на ошибке статусом скрипта становится последний вызов';
        }

        if (!str_contains($code, 'docker compose -f docker-compose.dev.yml run --rm -T php "$@"')) {
            $problems[] = 'обёртка `run` не исполняет команду через docker compose: шаги ворот можно заменить пустой функцией';
        }

        foreach (self::GATE_STEPS as $name => $signs) {
            $launches = self::GATE_STEP_LAUNCHES[$name];
            foreach ($signs as $index => $sign) {
                if ($this->headMatching($heads, $launches[$index]) === null) {
                    $problems[] = 'шаг «' . $name . '»: признак `' . $sign . '` не стоит строкой запуска команды';
                }
            }
        }

        self::assertSame(
            [],
            $problems,
            'В `scripts/gate.sh` шаг заявлен, но не доходит до исполнения: ' . implode('; ', $problems)
        );
    }

    // Собственный список стража — тоже цель атаки: урезанный эталон зелёного прогона ничего не
    // доказывает. Пункт Т6: сверяется не только длина, но и состав — каждое значение обязано
    // называть инструмент своего шага и встречаться в живом файле ворот строкой запуска команды.
    public function testЭталонСтражаНеУрезан(): void
    {
        self::assertCount(5, self::PHPUNIT_STRICT_FLAGS, 'Список флагов строгости в самом страже урезан');
        self::assertCount(4, self::PHPUNIT_SUITES, 'Список наборов в самом страже урезан');
        self::assertCount(5, self::GATE_STEPS, 'Список шагов ворот в самом страже урезан');
        self::assertSame(6, self::PHPSTAN_MIN_LEVEL, 'Минимальный уровень в самом страже понижен');
        self::assertSame(
            array_keys(self::GATE_STEPS),
            array_keys(self::GATE_STEP_EXECUTABLES),
            'Названия шагов ворот в самом страже разошлись с эталоном исполняемых файлов'
        );
        self::assertSame(
            array_keys(self::GATE_STEPS),
            array_keys(self::GATE_STEP_LAUNCHES),
            'Названия шагов ворот в самом страже разошлись с эталоном строк запуска'
        );

        $gate = $this->stripShellComments($this->read('scripts/gate.sh'));
        $heads = $this->commandHeads($gate);

        $bad = [];
        foreach (self::GATE_STEPS as $name => $signs) {
            $launches = self::GATE_STEP_LAUNCHES[$name];

            if (count($signs) !== count($launches)) {
                $bad[] = 'шаг «' . $name . '»: признаков работы ' . count($signs) . ', а строк запуска ' . count($launches);
            }

            $named = false;
            foreach ($signs as $sign) {
                if (str_contains($sign, self::GATE_STEP_EXECUTABLES[$name])) {
                    $named = true;
                }
            }

            // Хоть один признак шага обязан называть именно этот инструмент: иначе подмена иглы
            // на безобидную подстроку сохранила бы счёт пять и обнулила бы проверку шага.
            if (!$named) {
                $bad[] = 'шаг «' . $name . '»: ни один признак не называет инструмент `'
                    . self::GATE_STEP_EXECUTABLES[$name] . '`';
            }

            // Каждый признак обязан встречаться в файле ворот именно строкой запуска команды,
            // а не текстом сообщения об ошибке.
            foreach ($launches as $index => $launch) {
                if (!isset($signs[$index])) {
                    continue;
                }

                if ($this->headMatching($heads, $launch) === null) {
                    $bad[] = 'шаг «' . $name . '»: признак `' . $signs[$index] . '` не встречается в `scripts/gate.sh` строкой запуска';
                }
            }
        }

        self::assertSame(
            [],
            $bad,
            'Собственный эталон стража урезан или подменён: ' . implode('; ', $bad)
        );

        // Каждый флаг строгости в эталоне обязан носить имя флага тестировщика, а не произвольную
        // строку: иначе список наполняется безобидными ключами при сохранённом счётчике.
        $flagProblems = [];
        foreach (array_keys(self::PHPUNIT_STRICT_FLAGS) as $flag) {
            if (preg_match('/^(failOn|beStrictAbout)/', $flag) !== 1) {
                $flagProblems[] = $flag;
            }
        }

        self::assertSame(
            [],
            $flagProblems,
            'В эталоне флагов строгости появились ключи, не похожие на флаги: ' . implode(', ', $flagProblems)
        );
    }

    /**
     * Пути анализа из блока `paths:` в `phpstan.neon` — только значения списка, комментарии и
     * соседние секции не в счёт.
     *
     * @return list<string>
     */
    private function phpstanPaths(string $neon): array
    {
        $paths = $this->keyBlock($neon, 'paths');
        if ($paths === null) {
            return [];
        }

        return $this->listItemsFrom($neon, $paths['indent'], $paths['line']);
    }

    /**
     * Значения `excludePaths: analyse:` — список масок исключения файлов из анализа.
     *
     * @return list<string>
     */
    private function excludeAnalyseMasks(string $neon): array
    {
        $exclude = $this->keyBlock($neon, 'excludePaths');
        if ($exclude === null) {
            return [];
        }

        $analyse = $this->keyBlock($neon, 'analyse', $exclude['indent'] + 1);
        if ($analyse === null) {
            return [];
        }

        return $this->listItemsFrom($neon, $analyse['indent'], $analyse['line']);
    }

    /**
     * Каталог-набор `phpunit.xml`: имя набора => перечень его каталогов вместе с суффиксом, если
     * он указан (пункт Т4: суффикс меняет то, что набор собирает, не меняя имени набора).
     *
     * @return array<string, list<array{directory: string, suffix: string}>>
     */
    private function phpunitSuiteDirectories(string $xml): array
    {
        $map = [];
        if (preg_match_all('#<testsuite\s+name="([^"]+)">(.*?)</testsuite>#s', $xml, $blocks) === false) {
            return $map;
        }

        /** @var list<non-empty-string> $names */
        $names = $blocks[1];
        /** @var list<string> $bodies */
        $bodies = $blocks[2];

        foreach ($names as $index => $name) {
            $directories = [];
            $found = [];
            if (preg_match_all('#<directory([^>]*)>([^<]+)</directory>#', $bodies[$index], $found) !== false) {
                /** @var list<string> $attributes */
                $attributes = $found[1];
                /** @var list<non-empty-string> $raw */
                $raw = $found[2];

                foreach ($raw as $position => $dir) {
                    $suffix = [];
                    $suffixValue = preg_match('/\bsuffix="([^"]*)"/', $attributes[$position], $suffix) === 1 ? $suffix[1] : '';

                    $directories[] = ['directory' => trim($dir), 'suffix' => $suffixValue];
                }
            }

            $map[$name] = $directories;
        }

        return $map;
    }

    /**
     * Относительные пути всех `.php`-файлов в покрываемых каталогах: именно их не имеет права
     * накрывать маска `excludePaths`. Каталог отсутствует — просто ничего не добавляем.
     *
     * @return list<string>
     */
    private function analysedPhpFiles(): array
    {
        $files = [];
        foreach (self::PHPSTAN_ANALYSED_PATHS as $dir) {
            $absolute = realpath(self::ROOT . '/' . $dir);
            if ($absolute === false) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($absolute) + 1));
                $files[] = $dir . '/' . trim($relative, '/');
            }
        }

        return $files;
    }

    /**
     * Перевод маски glob в регулярку по семантике анализатора: `**` пересекает разделители каталогов,
     * `*` и `?` остаются в пределах одного сегмента.
     */
    private function globToRegex(string $mask): string
    {
        $regex = '';
        $length = strlen($mask);
        for ($i = 0; $i < $length; $i++) {
            $char = $mask[$i];
            if ($char === '*') {
                if ($i + 1 < $length && $mask[$i + 1] === '*') {
                    $regex .= '.*';
                    $i++;
                    continue;
                }

                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#^' . $regex . '$#';
    }

    /**
     * Строка объявления ключа `key:` (ключ обязан стоять отдельной строкой без значения на той же
     * строке, как в `paths:` или `analyse:`): возвращает отступ ключа и его номер строки либо `null`,
     * если ключ не найден или его отступ меньше `minIndent`. Позиция строки нужна, чтобы собирать
     * список значений ровно под этим ключом и не принимать за него соседнюю секцию того же уровня.
     *
     * @return array{indent: int, line: int}|null
     */
    private function keyBlock(string $text, string $key, int $minIndent = 0): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        foreach ($lines as $number => $line) {
            $found = [];
            if (preg_match('/^(\s*)' . preg_quote($key, '/') . ':\s*$/', $line, $found) === 1) {
                $indent = strlen($found[1]);
                if ($indent >= $minIndent) {
                    return ['indent' => $indent, 'line' => $number];
                }
            }
        }

        return null;
    }

    /**
     * Значения `- текст`, идущие строками глубже отступа `blockIndent` сразу после строки `startLine`
     * (объявления ключа). Сбор останавливается на первой непустой строке, вернувшейся к отступу
     * блока или мельче него: значит, список этого ключа закончился.
     *
     * @return list<string>
     */
    private function listItemsFrom(string $text, int $blockIndent, int $startLine): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $items = [];
        $total = count($lines);

        for ($number = $startLine + 1; $number < $total; $number++) {
            $line = $lines[$number];
            if (trim($line) === '') {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));
            if ($indent <= $blockIndent) {
                break;
            }

            $found = [];
            if (preg_match('/^(\s*)-\s*(.+?)\s*$/', $line, $found) === 1) {
                $items[] = $found[2];
            }
        }

        return $items;
    }

    /**
     * Текст ворот без целых строк комментариев: шаг обязан стоять командой, а не упоминанием.
     */
    private function stripShellComments(string $gate): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $gate);
    }

    /**
     * Начало команды каждой строки файла ворот: ведущие пробелы и служебные слова оболочки (`if`,
     * `then`, `else`, отрицание `!`, связки `&&` и `||`) снимаются по кругу, пока строка не
     * начнётся имени запускаемой программы. Так «вызов стоит строкой запуска» отличается от
     * того же текста внутри сообщения об ошибке.
     *
     * @return list<string>
     */
    private function commandHeads(string $code): array
    {
        $heads = [];

        foreach (preg_split('/\r\n|\r|\n/', $code) ?: [] as $line) {
            $head = rtrim(ltrim($line));
            $previous = null;

            while ($previous !== $head) {
                $previous = $head;
                $head = (string) preg_replace('/^(?:if|elif|then|else|do|while|until|for|!|&&|\|\||;)\s+/', '', $head);
            }

            if ($head !== '') {
                $heads[] = $head;
            }
        }

        return $heads;
    }

    /**
     * Первая строка запуска, подходящая под регулярку шага, либо `null`.
     *
     * @param list<string> $heads
     */
    private function headMatching(array $heads, string $pattern): ?string
    {
        foreach ($heads as $head) {
            if (preg_match($pattern, $head) === 1) {
                return $head;
            }
        }

        return null;
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path, $relative . ': файл настройки инструментов обязан существовать');

        $code = file_get_contents($path);
        self::assertIsString($code);

        return $code;
    }
}
