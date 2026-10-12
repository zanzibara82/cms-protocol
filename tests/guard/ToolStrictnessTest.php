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
// Пакет M3-1 усилил те же разборы: они видят кавычки и строчные (поточные) формы `neon` —
// анализатор читает ключ и в кавычках, и списком на одной строке, а прежний разбор «ключ отдельной
// строкой» оставлял такие подавления невидимыми для стража. Пакет M3-2 подключил к этим проверкам
// файлы из ключа `includes`: подавление в подключаемом файле анализатор применяет, а сам
// `phpstan.neon` внешне остаётся чистым, поэтому его не запрещаем, а читаем каждое подключение
// теми же списками ключей и масок, красня именем файла. Пакет M3-3 закрыл слепую зону разбора
// `phpunit.xml`: комментарии вырезаются до любого поиска (тестировщик их не видит, а прежний разбор
// считал закомментированный флаг настоящим), а повторное имя набора краснит — карта «имя => каталоги»
// при дубликате смотрит только на последнее вхождение и лжёт.
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
        $configs = $this->phpstanCheckedConfigs($this->read('phpstan.neon'));

        // Пункт M3-2: проверка идёт по всем собираемым файлам, а не только по самому `phpstan.neon`:
        // подключение через `includes` переносит подавление за пределы видимого текста конфигурации.
        self::assertSame(
            [],
            $configs['problems'],
            'Подключение `includes` в `phpstan.neon` разобрано с проблемой: ' . implode('; ', $configs['problems']) . ': такой конфигурации страж не доверяет'
        );

        $flagged = [];
        foreach ($configs['files'] as $file) {
            $suppressions = $this->phpstanSuppressions($this->read($file));
            if ($suppressions !== []) {
                $flagged[] = 'в файле `' . $file . '` найдена секция подавления ошибок (' . implode(', ', $suppressions) . ')';
            }
        }

        self::assertSame(
            [],
            $flagged,
            'Поблажки в анализе запрещены, но найдены секции подавления ошибок: ' . implode('; ', $flagged)
        );
    }

    public function testPhpstanИсключенияНеНакрываютПроверяемыйКод(): void
    {
        // Пункт Т7: `excludePaths: analyse:` исключает файлы, о которых сам файл открыто объясняет
        // решение, и поблажкой по ошибкам не является, — но маска не имеет права накрывать
        // проверяемый код. Значение запрещено, если под него попадает хоть один проверяемый файл
        // скрипта: сюда же входят широкие маски вида `core/**`, `apps/**` и `**/*`. Одобренное
        // значение `public/assets/*` остаётся зелёным: файлов скрипта в этом каталоге нет, поэтому
        // правка `phpstan.neon` не нужна и ворота не ослабляются. Пункт M3-1: те же маски собираются
        // и из строчной (поточной) формы, а значения очищаются от одной пары обрамляющих кавычек
        // до сравнения — иначе маска `"core/**"` уходила в регулярку с кавычками и не накрывала
        // ни одного файла, оставляя стража зелёным при исключённом каталоге `core`. Пункт M3-2:
        // те же маски собираются и из подключаемых через `includes` файлов.
        $neon = $this->read('phpstan.neon');
        $configs = $this->phpstanCheckedConfigs($neon);
        $phpFiles = $this->analysedPhpFiles();

        self::assertSame(
            [],
            $configs['problems'],
            'Подключение `includes` в `phpstan.neon` разобрано с проблемой: ' . implode('; ', $configs['problems']) . ': такой конфигурации страж не доверяет'
        );

        $covering = [];
        foreach ($configs['files'] as $source) {
            $text = $source === 'phpstan.neon' ? $neon : $this->read($source);
            foreach ($this->excludeAnalyseMasks($text) as $mask) {
                foreach ($phpFiles as $file) {
                    if (preg_match($this->globToRegex($mask), $file) === 1) {
                        $covering[] = 'в файле `' . $source . '` маска `' . $mask . '` накрывает ' . $file;
                        break;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $covering,
            'Маска `excludePaths` в `phpstan.neon` или подключаемом им файле накрывает проверяемый код: ' . implode('; ', $covering)
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
        // Пункт M3-3: разбор ведётся по тексту без комментариев. Строка `<!-- … -->` не существует
        // для тестировщика, а прежний поиск находил нужное значение внутри комментария и не
        // различал, где оно настоящее: выключенный атрибут рядом с закомментированным верным
        // значением (`failOnDeprecation="false"` плюс комментарий с `true`) оставлял прогон зелёным.
        $xml = $this->stripXmlComments($this->read('phpunit.xml'));

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
        // Пункт M3-3: имена собираются из текста без комментариев — иначе закомментированный блок
        // набора засчитывался бы как живое объявление.
        $xml = $this->stripXmlComments($this->read('phpunit.xml'));

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
        // Пункт M3-3: разбор ведётся по тексту без комментариев, иначе закомментированный верный блок
        // каталога считался бы настоящим.
        $xml = $this->stripXmlComments($this->read('phpunit.xml'));

        // Пункт M3-3: повторное имя набора краснит, и проверка эта независима от карты и стоит до
        // сверки каталогов. Карта «имя => каталоги» перезаписывается последним вхождением имени,
        // поэтому при дубликате («неверный» набор выше, «верный» ниже) утверждение «имя связано со
        // своим каталогом» лжёт: исполнитель собирает тесты чужого каталога, а страж молчит.
        $names = [];
        if (preg_match_all('/<testsuite name="([^"]+)"/', $xml, $names) === false) {
            self::fail('Разбор имён наборов в `phpunit.xml` не состоялся');
        }

        /** @var array<string, int> $occurrences */
        $occurrences = array_count_values(array_map('strval', $names[1]));
        $repeated = [];
        foreach ($occurrences as $suite => $count) {
            if ($count > 1) {
                $repeated[] = 'число вхождений имени `' . $suite . '` = ' . $count;
            }
        }

        self::assertSame(
            [],
            $repeated,
            'В `phpunit.xml` имя набора объявлено повторно (' . implode(', ', $repeated) . '): сверка каталога смотрит только на последнее вхождение, '
                . 'и набор с таким именем тихо собирает чужой каталог'
        );

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
     * Значения `excludePaths: analyse:` — список масок исключения файлов из анализа. Блок не найден —
     * маски собираются из строчной (поточной) формы (пункт M3-1).
     *
     * @return list<string>
     */
    private function excludeAnalyseMasks(string $neon): array
    {
        $exclude = $this->keyBlock($neon, 'excludePaths');
        if ($exclude === null) {
            return $this->excludePathsInlineMasks($neon);
        }

        $analyse = $this->keyBlock($neon, 'analyse', $exclude['indent'] + 1);
        if ($analyse === null) {
            return [];
        }

        return $this->listItemsFrom($neon, $analyse['indent'], $analyse['line']);
    }

    /**
     * Строчная (поточная) форма `excludePaths` (пункт M3-1): `excludePaths: { analyse: [ … ] }` и
     * `excludePaths: [ … ]` — список без ключа `analyse`, который анализатор тоже понимает.
     * Вызывается, когда блок не найден; собранные значения проходят ту же проверку накрывания,
     * что и значения из блока.
     *
     * @return list<string>
     */
    private function excludePathsInlineMasks(string $neon): array
    {
        $analyse = $this->inlineFlowList($neon, 'excludePaths', 'analyse');
        if ($analyse !== null) {
            return $analyse;
        }

        return $this->inlineFlowList($neon, 'excludePaths') ?? [];
    }

    /**
     * Ключи подавления ошибок, найденные в тексте `neon`: имена берутся из списка, который включает
     * каноническое `ignoreErrors` и ключи сохранённого эталона (пункт Т1), — прежний набор ловил
     * только написания, которых у анализатора нет. Пункт M3-1: ключ допускается в одинарных или
     * двойных кавычках сразу после ведущих пробелов — анализатор читает `"ignoreErrors":` как то же
     * самое подавление, а прежний поиск оставлял такую строку невидимой.
     *
     * @return list<string>
     */
    private function phpstanSuppressions(string $neon): array
    {
        $suppressions = [];
        foreach (self::PHPSTAN_SUPPRESSION_KEYS as $key) {
            $quoted = preg_quote($key, '/');
            if (preg_match('/^[ \t]*(?:(["\'])' . $quoted . '\1|' . $quoted . ')[ \t]*:/mi', $neon) === 1) {
                $suppressions[] = $key;
            }
        }

        return $suppressions;
    }

    /**
     * Подключённые файлы из ключа `includes`: значения собираются разборами, усиленными в M3-1, —
     * блоком (ключ отдельной строкой + список `- путь`) и строчной формой `includes: [ a.neon, b.neon ]`,
     * оба варианта с очисткой значений от кавычек. (Пункт M3-2.)
     *
     * @return list<string>
     */
    private function phpstanIncludes(string $neon): array
    {
        $block = $this->keyBlock($neon, 'includes');
        if ($block !== null) {
            return $this->listItemsFrom($neon, $block['indent'], $block['line']);
        }

        return $this->inlineFlowList($neon, 'includes') ?? [];
    }

    /**
     * Файлы конфигурации, которые страж обязан разобрать: сам `phpstan.neon` и каждый подключаемый
     * им существующий файл (пункт M3-2). Каждый путь из `includes` разрешается относительно корня
     * проекта, где лежит `phpstan.neon`. Абсолютный путь или путь с `..` краснится отдельной
     * строкой: проверка остаётся в границах проекта, иначе страж читает файлы вне дерева.
     * Отсутствующий подключённый файл — тоже падение с именем пути: нельзя проверить то, чего нет,
     * и молча зелёный прогон тут не подходит.
     *
     * @return array{files: list<string>, problems: list<string>}
     */
    private function phpstanCheckedConfigs(string $neon): array
    {
        $files = ['phpstan.neon'];
        $problems = [];

        foreach ($this->phpstanIncludes($neon) as $path) {
            if ($path === '') {
                continue;
            }

            if ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[a-zA-Z]:/', $path) === 1 || str_contains($path, '..')) {
                $problems[] = 'подключение `' . $path . '` ведёт за границы проекта: страж проверяет только файлы внутри дерева';
                continue;
            }

            if (!is_file(self::ROOT . '/' . $path)) {
                $problems[] = 'подключённый файл `' . $path . '` не найден: нельзя проверить то, чего нет';
                continue;
            }

            $files[] = $path;
        }

        return ['files' => $files, 'problems' => $problems];
    }

    /**
     * Значения списка, записанного на одной строке с ключом (пункт M3-1): `ключ: [ a, b ]` или
     * `ключ: { вложенный: [ a, b ] }`. Ключ и вложенный ключ допускаются в одинарных или двойных
     * кавычках; каждое значение очищается от одной пары обрамляющих кавычек. Возвращает `null`,
     * когда ключа со строчным значением нет: блок ищется отдельно методом `keyBlock()`.
     *
     * @return list<string>|null
     */
    private function inlineFlowList(string $text, string $key, ?string $inner = null): ?array
    {
        $quotedKey = preg_quote($key, '/');
        $found = [];
        if (preg_match('/^[ \t]*(?:(["\'])' . $quotedKey . '\1|' . $quotedKey . ')[ \t]*:[ \t]*(?P<rest>\S.*)$/m', $text, $found) !== 1) {
            return null;
        }

        $body = [];
        if ($inner !== null) {
            $quotedInner = preg_quote($inner, '/');
            if (preg_match('/(?:(["\'])' . $quotedInner . '\1|' . $quotedInner . ')[ \t]*:[ \t]*\[(?P<items>[^\]]*)\]/', $found['rest'], $body) !== 1) {
                return null;
            }
        } elseif (preg_match('/\[(?P<items>[^\]]*)\]/', $found['rest'], $body) !== 1) {
            return null;
        }

        $values = [];
        foreach (explode(',', $body['items']) as $item) {
            $value = $this->unquote($item);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Значение `neon` без одной пары обрамляющих кавычек и пробелов вокруг них (пункт M3-1):
     * `'core/**'`, `"core/**"` и `core/**` дают одно и то же. Операция идемпотентна: значение без
     * кавычек чищается так же, как уже очищенное.
     */
    private function unquote(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && $value[$length - 1] === $first) {
                $value = trim(substr($value, 1, -1));
            }
        }

        return $value;
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
     * строке, как в `paths:` или `analyse:`; пункт M3-1: ключ допускается в одинарных или двойных
     * кавычках, оба варианта): возвращает отступ ключа и его номер строки либо `null`,
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
            if (preg_match('/^(\s*)(?:(["\'])' . preg_quote($key, '/') . '\2|' . preg_quote($key, '/') . '):\s*$/', $line, $found) === 1) {
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
     * блока или мельче него: значит, список этого ключа закончился. Каждое значение очищается от
     * одной пары обрамляющих кавычек (пункт M3-1) — до того, как уходит в `globToRegex()` и в
     * сравнение с каталогами.
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
                $items[] = $this->unquote($found[2]);
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
     * Текст `phpunit.xml` без комментариев `<!-- … -->` (пункт M3-3): применяется до любого разбора
     * файла, потому что тестировщик комментария не видит. Без вырезания флаг строгости, записанный
     * комментарием, считался настоящим, а закомментированный верный каталог набора перекрывал
     * наведённый на чужой каталог — прогон оставался зелёным при исполняемых чужих тестах.
     */
    private function stripXmlComments(string $xml): string
    {
        return (string) preg_replace('/<!--.*?-->/s', '', $xml);
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
