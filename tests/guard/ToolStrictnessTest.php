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
// при дубликате смотрит только на последнее вхождение и лжёт. Пакет M3-4 сцепил два эталона одного
// шага: текст признака и регулярку строки запуска. Прежняя проверка искала лишь регулярку, а признак
// участвовал только в сообщении, поэтому снятый якорь конца строки вместе с сузившим путь шагом ворот
// оставлял страж зелёным; теперь регулярка обязана быть одной из двух форм, собранных из текста
// признака, а признак вида `vendor/bin/…` — только формой полной команды. Пакет M3-5 потребовал от
// строки `set -euo pipefail` не только наличия, но и позиции: первой рабочей строки файла ворот
// (перенос её перед последним вызовом обесценивает четыре шага, а текстовый поиск его не видел).
// Фикс-раунд M3а-1 (пункт Ф1): блок и строчная форма `excludePaths` перестали быть
// взаимоисключающими — маски собираются из обоих видов сразу; ключ подавления ошибок виден и в
// поточной форме (после `{`, `[` или `- `), а разбиение поточного списка учитывает кавычки и
// фигурные скобки, поэтому искалеченная маска не становится молчаливой зеленью; форма записи,
// которую разбор не понял, краснит вместо тишины. Пункт Ф2 того же фикс-раунда: подключения
// обходятся в ширину (цепочка `phpstan.neon` → `a.neon` → `b.neon` с подавлением в `b.neon`
// читается целиком, а повторное попадание файла краснится циклом), уровень строгости сверяется по
// всем собранным файлам, а путь подключения разрешается относительно каталога включающего файла и
// очищается от хвостового комментария. Пункт Ф3 того же фикс-раунда: разборы проверяются на образцах
// строки прямо в дереве (новый тест `testPhpstanРазборыПонимаютКавычкиИСтрочнуюФорму`), поэтому снятие
// очистки кавычек больше не возвращает зелёный прогон незамеченным; непустота списка проверяемых
// файлов утверждена, а недоверие к списку подключений падает одним способом вместо трёх копий.
// Пункт Ф5 того же фикс-раунда: отмена остановки на ошибке ниже по файлу ворот краснится — строка
// `set +e` сразу за первой рабочей строкой снимала режим со всех пяти шагов, а проверка позиции
// этого не видела.
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
    // оставался зелёным. Пункт M3-4: признак шага «синтаксис» — строка, которой этот шаг реально
    // запускается внутри контейнера (`xargs -0 -r -n1 php -l`), а не голая подстрока `php -l`:
    // текст признака читается регуляркой запуска, и оба эталона обязаны сходитьться.
    /** @var array<string, list<string>> */
    private const GATE_STEPS = [
        'свежесть стилей' => ['node_modules/.bin/tailwindcss -i ./src-css/app.css', 'cmp -s "$css_tmp/app.css" public/assets/app.css'],
        'синтаксис' => ['xargs -0 -r -n1 php -l'],
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

        // Пункт Ф2: уровень сверяется по всем собранным файлам, поэтому список подключений обязан
        // быть разобранным до первой проверки уровня.
        $configs = $this->phpstanCheckedConfigs($neon);

        $this->assertConfigsTrustworthy($configs);

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

        // Пункт Ф2: `includes: [ a.neon ]` с `level: 4` внутри `a.neon` понижал строгость всего
        // анализа, а проверка читала только `phpstan.neon` — то есть понижение уровня через
        // подключаемый файл оставалось зелёным. Отсутствие уровня в подключаемом файле не падение:
        // он наследуется от основного, и требовать его значило бы краснить легальную запись.
        $weakened = [];
        foreach ($configs['files'] as $source) {
            if ($source === 'phpstan.neon') {
                continue;
            }

            $found = [];
            if (preg_match('/^\s*level:\s*(\d+)/m', $this->read($source), $found) !== 1) {
                continue;
            }

            $inherited = (int) $found[1];
            if ($inherited < self::PHPSTAN_MIN_LEVEL) {
                $weakened[] = 'в файле `' . $source . '` уровень анализа опущен до ' . $inherited
                    . ': требуется не ниже ' . self::PHPSTAN_MIN_LEVEL;
            }
        }

        self::assertSame(
            [],
            $weakened,
            'Уровень анализа понижен в подключаемом файле: ' . implode('; ', $weakened) . ': анализатор применяет его к тому же коду, что и уровень основного файла'
        );
    }

    public function testPhpstanБезСекцийПодавленияОшибок(): void
    {
        $configs = $this->phpstanCheckedConfigs($this->read('phpstan.neon'));

        // Пункт M3-2: проверка идёт по всем собираемым файлам, а не только по самому `phpstan.neon`:
        // подключение через `includes` переносит подавление за пределы видимого текста конфигурации.
        $this->assertConfigsTrustworthy($configs);

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
        // те же маски собираются и из подключаемых через `includes` файлов. Пункт Ф1: блок и
        // строчная форма собираются вместе, а непонятая форма записи (ключ есть, масок ноль;
        // значение с незакрытой кавычкой) краснит сама — раньше она молчала.
        $neon = $this->read('phpstan.neon');
        $configs = $this->phpstanCheckedConfigs($neon);
        $phpFiles = $this->analysedPhpFiles();

        $this->assertConfigsTrustworthy($configs);

        // Пункт Ф3: сравнение маски с файлами опирается на `$phpFiles`, а ни одна проверка его
        // непустоту не утверждала. Каталог исчезает — `analysedPhpFiles()` молча пропускает его,
        // внутренний цикл не выполняется ни разу, и прогон зелёный без единой проверки накрывания.
        self::assertNotSame(
            [],
            $phpFiles,
            'Список проверяемых файлов пуст (' . implode(', ', self::PHPSTAN_ANALYSED_PATHS) . ' не дали ни одного `.php`-файла): '
                . 'сверке маски с файлом не на чем строиться, и зелёный прогон ничего бы не проверял'
        );

        $covering = [];
        $unparsed = [];
        foreach ($configs['files'] as $source) {
            $text = $source === 'phpstan.neon' ? $neon : $this->read($source);
            $collected = $this->excludeAnalyseMasks($source, $text);
            $unparsed = array_merge($unparsed, $collected['problems']);

            foreach ($collected['masks'] as $mask) {
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
            $unparsed,
            'Разбор `excludePaths` не понял форму записи, а молчаливый прогон тут не подходит: ' . implode('; ', $unparsed)
        );

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
        $matched = [];
        if (preg_match_all('/<testsuite name="([^"]+)"/', $xml, $matched) === false) {
            self::fail('Разбор имён наборов в `phpunit.xml` не состоялся');
        }

        /** @var list<string> $declared */
        $declared = array_map('strval', $matched[1]);
        $repeated = [];
        foreach (array_count_values($declared) as $suite => $count) {
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

        // Пункт M3-5: позиция вместо факта наличия. Прежний поиск находил `set -euo pipefail`
        // якорем начала строки в любом месте файла, и перенос этой строки перед последним вызовом
        // оставлял прогон зелёным: коды возврата `php -l`, анализатора и фиксера перестают валить
        // скрипт, а статусом ворот становится последний вызов. Позиция считается по сырому тексту
        // файла, а не по тексту с вырезанными комментариями: срезанный комментарий сдвинул бы счёт,
        // и «первая строка после комментария» перестала бы быть первой строкой файла.
        $lines = preg_split('/\r\n|\r|\n/', $gate) ?: [];

        if (($lines[0] ?? '') !== '#!/usr/bin/env bash') {
            $problems[] = 'первая строка `scripts/gate.sh` — `' . ($lines[0] ?? '') . '`, а требуется `#!/usr/bin/env bash`';
        }

        $firstWork = null;
        foreach ($lines as $number => $line) {
            if ($number === 0 || trim($line) === '' || preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $firstWork = ['number' => $number + 1, 'text' => ltrim($line)];
            break;
        }

        if ($firstWork === null) {
            $problems[] = 'в `scripts/gate.sh` после заголовка нет ни одной рабочей строки: ворота не исполняют ничего';
        } elseif ($firstWork['text'] !== 'set -euo pipefail') {
            $problems[] = 'строка `set -euo pipefail` обязана идти первой рабочей строкой ворот, а на строке '
                . $firstWork['number'] . ' стоит `' . $firstWork['text'] . '`: перенесённая в конец файла, '
                . 'она не валит прогон на ошибке, и статусом скрипта становится последний вызов';
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

                // Пункт M3-4: строка запуска сверяется и с текстом самого признака, а не только с
                // регуляркой эталона. Прежняя проверка искала лишь регулярку, и двух шагов хватило,
                // чтобы её обмануть целиком: у запуска «тесты» снимался якорь конца строки
                // (`$#` → `\b#`), а в воротах шаг сужался путём (`run vendor/bin/phpunit tests/guard`) —
                // оба класса стража зелёные, и весь набор `unit`/`db`/`http` мимо ворот не исполняется.
                $forms = $this->signLaunchForms($sign);
                if ($this->headMatchingAny($heads, $forms) === null) {
                    $problems[] = 'шаг «' . $name . '»: признак `' . $sign . '` не стоит строкой запуска '
                        . 'в разрешённой форме (`' . implode('` или `', $forms) . '`) — шаг можно сузить аргументом';
                }
            }
        }

        self::assertSame(
            [],
            $problems,
            'В `scripts/gate.sh` шаг заявлен, но не доходит до исполнения: ' . implode('; ', $problems)
        );
    }

    public function testВоротаНеОтключаютОстановкуНаОшибке(): void
    {
        // Пункт Ф5: проверка позиции из соседнего теста ловит перенос и исчезновение строки
        // `set -euo pipefail`, но не ловит её отмену ниже по файлу. Вставленная сразу за первой
        // рабочей строкой строка `set +e` выключает остановку на ошибке для всех пяти шагов — ровно
        // тот вред, против которого стоит пункт M3-5: статусом скрипта становится последний вызов, и
        // упавший инструмент оставляет ворота зелёными. Поэтому падением считается любая головка
        // вызова, первым словом которой стоит ровно слово `set` (не `setup` и не часть пути), — либо
        // с символом `+` в аргументах, либо не единственная разрешённая первая рабочая строка.
        $heads = $this->commandHeads($this->stripShellComments($this->read('scripts/gate.sh')));

        $cancelled = [];
        $allowed = 0;
        foreach ($heads as $head) {
            if (preg_match('/^set(\s|$)/', $head) !== 1) {
                continue;
            }

            // Второго вызова `set` в воротах быть не может: итог определяет порядок флагов, поэтому
            // повторная строка даже с верными флагами уже снимает режим, установленный первой.
            if (str_contains($head, '+') || $head !== 'set -euo pipefail' || $allowed === 1) {
                $cancelled[] = $head;
                continue;
            }

            $allowed++;
        }

        self::assertSame(
            [],
            $cancelled,
            'В `scripts/gate.sh` отключена остановка на ошибке: строка `' . implode('`, `', $cancelled)
                . '` снимает режим, установленный первой рабочей строкой, — статусом скрипта становится '
                . 'последний вызов, и упавший инструмент оставляет ворота зелёными'
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
            // а не текстом сообщения об ошибке. Пункт M3-4: регулярка обязана быть собранной из
            // текста своего признака (одна из двух форм), иначе два эталона шага живут независимо:
            // признак подменяют безобидной подстрокой либо снимают у регулярки якорь конца строки, и
            // оба класса проверки остаются зелёными. Признак вида `vendor/bin/…` — только форма
            // полной команды.
            foreach ($launches as $index => $launch) {
                if (!isset($signs[$index])) {
                    continue;
                }

                $forms = $this->signLaunchForms($signs[$index]);
                if (!in_array($launch, $forms, true)) {
                    $bad[] = 'шаг «' . $name . '»: регулярка `' . $launch . '` не собрана из признака `'
                        . $signs[$index] . '`, разрешённые формы — `' . implode('` или `', $forms) . '`';
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

    public function testPhpstanРазборыПонимаютКавычкиИСтрочнуюФорму(): void
    {
        // Пункт Ф3: ни одно усиление разборов не оставляло закреплённого в дереве доказательства —
        // снятие `unquote()` или огрубление регулярки вернуло бы зелёный прогон, и заметили бы это
        // только на следующем ручном замере. Поэтому разборы проверяются на образцах строки прямо
        // здесь: ни чтения файлов дерева, ни записи на диск, и каждый кейс с утверждением.
        $quoted = $this->excludeAnalyseMasks('phpstan.neon', "parameters:\n  excludePaths:\n    analyse:\n      - \"core/**\"\n");
        $plain = $this->excludeAnalyseMasks('phpstan.neon', "parameters:\n  excludePaths:\n    analyse:\n      - core/**\n");

        self::assertSame(
            ['core/**'],
            $quoted['masks'],
            'Разбор блока `excludePaths` не почистил кавычки: значение `"core/**"` собрано как ' . implode(', ', $quoted['masks'])
                . ' — маска с кавычкой не накрывает ни одного файла, и исключённый каталог остаётся невидимым'
        );

        // Кейс 1: значение в кавычках после очистки совпадает с блочным.
        self::assertSame(
            $plain['masks'],
            $quoted['masks'],
            'Маска `"core/**"` и маска `core/**` разошлись после разбора: без кавычек собрано ' . implode(', ', $plain['masks'])
        );

        // Кейс 2: строчная (поточная) форма блока `excludePaths` даёт собранную маску.
        $inline = $this->excludeAnalyseMasks('phpstan.neon', "parameters:\n  excludePaths: { analyse: [ \"vendor/**\" ] }\n");
        self::assertSame(
            ['vendor/**'],
            $inline['masks'],
            'Строчная форма `excludePaths: { analyse: [ … ] }` не собрана методом `inlineFlowList()`: ' . implode(', ', $inline['masks'])
        );
        self::assertSame([], $inline['problems'], 'Понятая строчная форма записи не должна попадать в список проблем: ' . implode('; ', $inline['problems']));

        // Тот же кейс в смешанной форме: блок на месте, а `analyse:` внутри него записан строчной
        // строкой — именно этот случай прежний разбор считал взаимоисключающими формами.
        $mixed = $this->excludeAnalyseMasks('phpstan.neon', "  excludePaths:\n    analyse:\n      - public/assets/*\n        analyse: [ \"core/**\" ]\n");
        self::assertContains(
            'core/**',
            $mixed['masks'],
            'Строчная строка `analyse:` внутри найденного блока не собрана: собрано только ' . implode(', ', $mixed['masks'])
        );

        // Кейс 3: подключение второго уровня разрешается относительно каталога включающего файла,
        // а не корня проекта, и хвостовой комментарий у значения срезается.
        self::assertSame(
            'config/b.neon',
            $this->includedConfigPath('config/a.neon', 'b.neon'),
            'Относительный путь подключения второго уровня разрешился вне каталога включающего файла'
        );
        self::assertSame(
            'phpstan.neon',
            $this->includedConfigPath('phpstan.neon', 'phpstan.neon # пояснение'),
            'Хвостовой комментарий после `#` не срезан у значения `includes`: легальная запись давала бы ложное «файл не найден»'
        );
    }

    /**
     * Список подключений — причина отдельной проверки (пункт Ф3): дословно повторяющаяся строка
     * `assertSame([], $configs['problems'])` с одинаковым текстом сообщения жила в двух тестах, а
     * после пункта Ф2 прожила бы в трёх. Результат проверок подавлений и масок не должен
     * смешиваться с недоверием к самому списку файлов: испорченный список больше не может быть
     * отчитан как «подавлений не найдено».
     *
     * @param array{files: list<string>, problems: list<string>} $configs
     */
    private function assertConfigsTrustworthy(array $configs): void
    {
        self::assertSame(
            [],
            $configs['problems'],
            'Подключение `includes` в `phpstan.neon` разобрано с проблемой: ' . implode('; ', $configs['problems']) . ': такой конфигурации страж не доверяет'
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
     * Значения `excludePaths: analyse:` — список масок исключения файлов из анализа. Пункт Ф1:
     * блок и строчная форма не исключают друг друга, а дополняют, поэтому собираются оба вида
     * сразу и без повторов; внутри найденного блока значение `analyse:` разбирается и как блок,
     * и как строчная строка на любой строке файла (вторая строчная строка при уже найденном блоке
     * и `excludePaths: {` с переносом списка на следующую строку — тоже). Если ключ `excludePaths`
     * в проверяемом файле есть, а масок не собрано ни одной, — это падение с именем файла:
     * молчаливый зелёный прогон означал бы, что разбор не понял форму записи, а анализатор тем
     * временем исключает целый каталог.
     *
     * @return array{masks: list<string>, problems: list<string>}
     */
    private function excludeAnalyseMasks(string $source, string $neon): array
    {
        $masks = [];
        $malformed = [];
        $declared = false;

        $exclude = $this->keyBlock($neon, 'excludePaths');
        if ($exclude !== null) {
            $declared = true;
            $analyse = $this->keyBlock($neon, 'analyse', $exclude['indent'] + 1);
            if ($analyse !== null) {
                $masks = array_merge($masks, $this->listItemsFrom($neon, $analyse['indent'], $analyse['line']));
            }
        }

        // Строчная форма в дополнение к блоку: и `{ analyse: [ … ] }` на строке ключа, и `[ … ]`
        // без ключа `analyse`, и `analyse: [ … ]` отдельной строкой внутри блока или в перенесённом
        // потоке. Каждое вхождение ключа, а не первое: `preg_match()` оставлял вторую строчную
        // строку невидимой при уже найденном блоке.
        foreach ([
            $this->inlineFlowList($neon, 'excludePaths', 'analyse'),
            $this->inlineFlowList($neon, 'excludePaths'),
            $this->inlineFlowList($neon, 'analyse'),
        ] as $inline) {
            if ($inline === null) {
                continue;
            }

            $declared = true;
            $masks = array_merge($masks, $inline['values']);
            $malformed = array_merge($malformed, $inline['malformed']);
        }

        $masks = array_values(array_unique($masks));

        $problems = [];
        foreach (array_unique($malformed) as $value) {
            $problems[] = 'В `' . $source . '` значение `excludePaths` записано с незакрытой кавычкой: `'
                . $value . '` — страж не считает его маской и не молчит: искалеченный огрызок не накрывает ни одного файла';
        }

        if ($declared && $masks === [] && $problems === []) {
            $problems[] = 'В `' . $source . '` ключ `excludePaths` найден, но ни одной маски не собрано: разбор не понял форму записи';
        }

        return ['masks' => $masks, 'problems' => $problems];
    }

    /**
     * Ключи подавления ошибок, найденные в тексте `neon`: имена берутся из списка, который включает
     * каноническое `ignoreErrors` и ключи сохранённого эталона (пункт Т1), — прежний набор ловил
     * только написания, которых у анализатора нет. Пункт M3-1: ключ допускается в одинарных или
     * двойных кавычках сразу после ведущих пробелов — анализатор читает `"ignoreErrors":` как то же
     * самое подавление, а прежний поиск оставлял такую строку невидимой. Пункт Ф1: ключ ищется и в
     * поточной форме — сразу после `{`, `[` или `- `, а не только с начала строки, — иначе строка
     * `parameters: { ignoreErrors: [ … ] }` оставалась невидимой ровно тем же способом, что и
     * подавление в кавычках до M3-1.
     *
     * @return list<string>
     */
    private function phpstanSuppressions(string $neon): array
    {
        $suppressions = [];
        foreach (self::PHPSTAN_SUPPRESSION_KEYS as $key) {
            $quoted = preg_quote($key, '/');
            if (preg_match('/(?:^|[{[-])[ \t]*(?:(["\'])' . $quoted . '\1|' . $quoted . ')[ \t]*:/mi', $neon) === 1) {
                $suppressions[] = $key;
            }
        }

        return $suppressions;
    }

    /**
     * Подключённые файлы из ключа `includes`: значения собираются разборами, усиленными в M3-1, —
     * блоком (ключ отдельной строкой + список `- путь`) и строчной формой `includes: [ a.neon, b.neon ]`,
     * оба варианта с очисткой значений от кавычек. (Пункт M3-2.) Пункт Ф2: формы собираются вместе,
     * как в `excludePaths`, а признак «ключ в файле объявлен» возвращается отдельно — пустой при
     * объявленном ключе список больше не молчит.
     *
     * @return array{paths: list<string>, malformed: list<string>, declared: bool}
     */
    private function phpstanIncludes(string $neon): array
    {
        $paths = [];
        $malformed = [];
        $declared = false;

        $block = $this->keyBlock($neon, 'includes');
        if ($block !== null) {
            $declared = true;
            $paths = array_merge($paths, $this->listItemsFrom($neon, $block['indent'], $block['line']));
        }

        $inline = $this->inlineFlowList($neon, 'includes');
        if ($inline !== null) {
            $declared = true;
            $paths = array_merge($paths, $inline['values']);
            $malformed = array_merge($malformed, $inline['malformed']);
        }

        return ['paths' => $paths, 'malformed' => $malformed, 'declared' => $declared];
    }

    /**
     * Путь подключаемого файла (пункт Ф2): у значения срезается хвостовой комментарий после символа
     * `#` — в `neon` это комментарий, и без срезания легальная запись `- config.neon # пояснение`
     * давала бы ложную красноту «файл не найден». Относительный путь разрешается относительно
     * каталога включающего файла, а не корня проекта: подключение второго уровня лежит рядом со
     * своим включающим файлом, и прежняя база его просто не находила. Для самого `phpstan.neon`
     * база — корень проекта, как раньше. Абсолютный путь и путь с `..` возвращаются без изменений:
     * их классифицирует вызывающая проверка, и сверка остаётся внутри дерева.
     */
    private function includedConfigPath(string $includingFile, string $value): string
    {
        $path = trim($value);
        $comment = strpos($path, '#');
        if ($comment !== false) {
            $path = rtrim(substr($path, 0, $comment));
        }

        if ($path === '' || $path[0] === '/' || $path[0] === '\\' || preg_match('/^[a-zA-Z]:/', $path) === 1 || str_contains($path, '..')) {
            return $path;
        }

        $directory = $includingFile === 'phpstan.neon' ? '' : trim(str_replace('\\', '/', dirname($includingFile)), '.');
        if ($directory === '') {
            return $path;
        }

        return $directory . '/' . $path;
    }

    /**
     * Файлы конфигурации, которые страж обязан разобрать: сам `phpstan.neon` и каждый подключаемый
     * им существующий файл. Пункт M3-2 собирал один уровень подключений, и этого мало (пункт Ф2):
     * цепочка `phpstan.neon` → `a.neon` → `b.neon` с подавлением в `b.neon` оставалась зелёной,
     * потому что `b.neon` не читался вовсе. Поэтому обход в ширину: очередь файлов и множество уже
     * посещённых, для каждого файла собираются и его собственные `includes`. Повторное попадание
     * того же файла — не зацикливание, а цикл: он краснится, и обход этого плеча прекращается.
     * Ключ `includes` объявлен, а путей собрано ноль — тоже падение: молча зелёного прогона тут быть
     * не должно, как и для отсутствующего файла. Абсолютный путь или путь с `..` — прежняя отдельная
     * строка проблемы: проверка остаётся в границах проекта.
     *
     * @return array{files: list<string>, problems: list<string>}
     */
    private function phpstanCheckedConfigs(string $neon): array
    {
        $files = ['phpstan.neon'];
        $problems = [];
        $visited = ['phpstan.neon' => true];

        /** @var list<array{source: string, text: string}> $queue */
        $queue = [['source' => 'phpstan.neon', 'text' => $neon]];

        for ($position = 0; $position < count($queue); $position++) {
            $entry = $queue[$position];
            $source = $entry['source'];
            $collected = $this->phpstanIncludes($entry['text']);

            foreach ($collected['malformed'] as $value) {
                $problems[] = 'В `' . $source . '` подключение записано с незакрытой кавычкой: `'
                    . $value . '` — страж не считает его путём и не молчит';
            }

            if ($collected['declared'] && $collected['paths'] === [] && $collected['malformed'] === []) {
                $problems[] = 'В `' . $source . '` ключ `includes` найден, но ни одного файла не собрано: разбор не понял форму записи';
            }

            foreach ($collected['paths'] as $path) {
                $resolved = $this->includedConfigPath($source, $path);

                if ($resolved === '') {
                    continue;
                }

                if ($resolved[0] === '/' || $resolved[0] === '\\' || preg_match('/^[a-zA-Z]:/', $resolved) === 1 || str_contains($resolved, '..')) {
                    $problems[] = 'подключение `' . $path . '` ведёт за границы проекта: страж проверяет только файлы внутри дерева';
                    continue;
                }

                if (isset($visited[$resolved])) {
                    $problems[] = 'В `' . $source . '` цикл подключаемых файлов: ' . $resolved . ' подключается из ' . $source;
                    continue;
                }

                if (!is_file(self::ROOT . '/' . $resolved)) {
                    $problems[] = 'подключённый файл `' . $resolved . '` не найден: нельзя проверить то, чего нет';
                    continue;
                }

                $visited[$resolved] = true;
                $files[] = $resolved;
                $queue[] = ['source' => $resolved, 'text' => $this->read($resolved)];
            }
        }

        return ['files' => $files, 'problems' => $problems];
    }

    /**
     * Значения списка, записанного на одной строке с ключом (пункт M3-1): `ключ: [ a, b ]` или
     * `ключ: { вложенный: [ a, b ] }`. Ключ и вложенный ключ допускаются в одинарных или двойных
     * кавычках; каждое значение очищается от одной пары обрамляющих кавычек. Пункт Ф1: собираются
     * все вхождения ключа, а не первое (иначе вторая строчная строка при уже найденном блоке
     * оставалась невидимой), и разбиение по запятым учитывает кавычки и фигурные скобки.
     * Возвращает `null`, когда ключа со строчным значением нет: блок ищется отдельно методом
     * `keyBlock()`.
     *
     * @return array{values: list<string>, malformed: list<string>}|null
     */
    private function inlineFlowList(string $text, string $key, ?string $inner = null): ?array
    {
        $quotedKey = preg_quote($key, '/');
        $found = [];
        if (preg_match_all('/^[ \t]*(?:(["\'])' . $quotedKey . '\1|' . $quotedKey . ')[ \t]*:[ \t]*(?P<rest>\S.*)$/m', $text, $found, PREG_SET_ORDER) === false) {
            return null;
        }

        $values = [];
        $malformed = [];
        $collected = 0;

        foreach ($found as $occurrence) {
            $rest = (string) ($occurrence['rest'] ?? '');
            $body = null;
            $items = [];

            if ($inner !== null) {
                $quotedInner = preg_quote($inner, '/');
                if (preg_match('/(?:(["\'])' . $quotedInner . '\1|' . $quotedInner . ')[ \t]*:[ \t]*\[(?P<items>[^\]]*)\]/', $rest, $items) === 1) {
                    $body = (string) $items['items'];
                }
            } elseif (preg_match('/\[(?P<items>[^\]]*)\]/', $rest, $items) === 1) {
                $body = (string) $items['items'];
            }

            if ($body === null) {
                continue;
            }

            $collected++;
            $split = $this->flowValues($body);
            $values = array_merge($values, $split['values']);
            $malformed = array_merge($malformed, $split['malformed']);
        }

        if ($collected === 0) {
            return null;
        }

        return ['values' => $values, 'malformed' => $malformed];
    }

    /**
     * Значения поточного списка, разбитые по запятым только там, где запятая стоит вне кавычки и
     * вне фигурной скобки (пункт Ф1): маска вида `"core/{ct,or}/**"` оставалась двумя обрывками,
     * `globToRegex()` якорил их на начало и конец строки и не накрывал ни одного файла — молчаливая
     * зелень при живом подавлении. Значение с незакрытой (или сиротливой) кавычкой не чистится и не
     * считается маской: оно уходит в список проблем того же вида, что и путь подключения вне
     * дерева, — разбор не притворяется, что понял запись.
     *
     * @return array{values: list<string>, malformed: list<string>}
     */
    private function flowValues(string $body): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $depth = 0;
        $length = strlen($body);

        for ($position = 0; $position < $length; $position++) {
            $char = $body[$position];
            if ($quote === null && ($char === '"' || $char === "'")) {
                $quote = $char;
            } elseif ($quote !== null && $char === $quote) {
                $quote = null;
            } elseif ($quote === null && $char === '{') {
                $depth++;
            } elseif ($quote === null && $char === '}') {
                $depth = $depth > 0 ? $depth - 1 : 0;
            } elseif ($quote === null && $depth === 0 && $char === ',') {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        $values = [];
        $malformed = [];
        foreach ($parts as $part) {
            $value = trim($part);
            if ($value === '') {
                continue;
            }

            if ((substr_count($value, '"') + substr_count($value, "'")) % 2 === 1) {
                $malformed[] = $value;
                continue;
            }

            $clean = $this->unquote($value);
            if ($clean !== '') {
                $values[] = $clean;
            }
        }

        return ['values' => $values, 'malformed' => $malformed];
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

    /**
     * Первая строка запуска, подходящая хоть под одну из регулярк, либо `null`.
     *
     * @param list<string> $heads
     * @param list<string> $patterns
     */
    private function headMatchingAny(array $heads, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            $head = $this->headMatching($heads, $pattern);
            if ($head !== null) {
                return $head;
            }
        }

        return null;
    }

    /**
     * Две разрешённые регулярки строки запуска, собранные из текста признака шага (пункт M3-4):
     * форма начала строки `#^<признак>\b#` и форма полной команды `#^run <признак>$#`. Признак,
     * начинающийся с `vendor/bin/`, имеет право только на форму полной команды: снимаемый якорь
     * конца строки — это и есть способ сузить прогон аргументом (путём набора или фильтром),
     * оставив подстроку признака на месте.
     *
     * Экранирование — `preg_quote()` с ограничителем `#`. PHP 8.3 экранирует и дефис, а одобренные
     * регулярки в файле ворот его не экранируют; вне символьного класса `\-` и `-` для регулярки
     * значат одно и то же, поэтому дефис возвращается как есть — сила проверки от этого не меняется,
     * и усиление не требует правки эталонных строк ни одного шага.
     *
     * @return list<string>
     */
    private function signLaunchForms(string $sign): array
    {
        $escaped = str_replace('\\-', '-', preg_quote($sign, '#'));

        if (str_starts_with($sign, 'vendor/bin/')) {
            return ['#^run ' . $escaped . '$#'];
        }

        return ['#^' . $escaped . '\b#', '#^run ' . $escaped . '$#'];
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
