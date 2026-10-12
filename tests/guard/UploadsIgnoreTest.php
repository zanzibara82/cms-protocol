<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Рецензия задачи 18 принесла находку, которую не поймали зелёные ворота: `.gitignore` обещает,
// что загруженные обложки не пачкают рабочее дерево, а `git check-ignore` на путь обложки отвечает
// «не игнорируется». Причина в семантике маски: `/public/uploads/*` не достреливает через `/`,
// а строка `!/public/uploads/images/` после неё разрешает git зайти в каталог — и он считает каждую
// новую картинку изменением. Правим файл — и остаёмся без проверки: следующий, кто переставит
// строки местами, повторит дефект молча. Отсюда этот страж: он перечитывает правила `.gitignore`
// сам и отвечает на вопрос «что git считает игнорируемым», а не на вопрос «есть ли нужная строка
// в файле».
//
// Загрузок в дереве теперь два каталога — картинки и прочие файлы, — и схема правил у каждого
// своя тройка строк. Мутации поэтому идут по каждому каталогу отдельно: сила стража на картинках
// ничего не доказывает про каталог файлов.
//
// Разбор сознательно узкий: файл знает только правила с якорем от корня, `*` внутри одного сегмента
// и повторное включение по имени. Любой образец, который страж не умеет разобрать (`**`, класс
// символов), поднимает ошибку, а не молча проходит: молчаливая слепая зона — тот же выключенный
// страж, что и его отсутствие.
final class UploadsIgnoreTest extends TestCase
{
    private const FILE = '.gitignore';
    private const GIT = 'git';

    /** Адреса, которые обязаны быть закрыты: их кладёт на диск человек или сервер. */
    private const MUST_IGNORE = [
        'public/uploads/images/01m4n284s46hp26mtcz51j3peh.png',
        'public/uploads/images/01zzzzzzzzzzzzzzzzzzzzzzzz.jpg',
        'public/uploads/files/otchet-za-god.pdf',
        'config.php',
        'core/storage/error.log',
        'public/install.lock',
        'vendor/autoload.php',
    ];

    /**
     * Адреса, обязанные остаться в репозитории: без них каталоги загрузок не существуют после
     * выгрузки, а барьеры в них не работают.
     */
    private const MUST_TRACK = [
        'public/uploads/.htaccess',
        'public/uploads/.gitkeep',
        'public/uploads/images',
        'public/uploads/images/.gitkeep',
        'public/uploads/files',
        'public/uploads/files/.gitkeep',
    ];

    public function testЗагруженныеФайлыЗакрытыASлужебныеНет(): void
    {
        self::assertSame(
            [],
            $this->violations($this->rulesOfRepository()),
            'Ни одно правило git не сместилось: загрузки обязаны быть игнором, каркас каталогов — в репозитории'
        );
    }

    // Собственный разбор правил отвечает на вопрос «что git считает игнорируемым», но пересобирает
    // семантику git сам: расхождение своего матчера с настоящей командой остаётся невидимым, пока
    // кто-то не сравнит их лоб в лоб. Здесь сверка идёт по тому же списку путей, что проверяют
    // остальные проверки стража. Вызов только читает правила: ничего не пишется, индекс не меняется.
    public function testСобственныйМатчерСовпадаетСКомандойGitCheckIgnore(): void
    {
        $rules = $this->rulesOfRepository();

        foreach (array_merge(self::MUST_IGNORE, self::MUST_TRACK) as $path) {
            self::assertSame(
                self::ignoredByGit($path),
                self::ignored($rules, $path),
                'Матчер стража и `git check-ignore` разошлись на пути ' . $path
            );
        }
    }

    // Страж обязан краснеть на удалённой маске содержимого, а не молчать: без этой проверки
    // возврат дефекта (строки переставлены, маска убрана) остаётся зелёным, а находку видит
    // только человек на `git status` сервера.
    public function testБезМаскиСодержимогоКаталогаЗагрузкиУтекают(): void
    {
        /** @var array<string, array{mask: string, leaks: list<string>}> $cases */
        $cases = [
            'картинки' => [
                'mask' => '/public/uploads/images/*',
                'leaks' => [
                    'обложка не игнорируется git: public/uploads/images/01m4n284s46hp26mtcz51j3peh.png',
                    'обложка не игнорируется git: public/uploads/images/01zzzzzzzzzzzzzzzzzzzzzzzz.jpg',
                ],
            ],
            'файлы' => [
                'mask' => '/public/uploads/files/*',
                'leaks' => ['файл загрузки не игнорируется git: public/uploads/files/otchet-za-god.pdf'],
            ],
        ];

        foreach ($cases as $catalog => $case) {
            $rules = $this->rulesOfRepository();
            unset($rules[self::ruleIndex($rules, $case['mask'])]);

            self::assertSame(
                $case['leaks'],
                $this->ignorationViolations($rules),
                'Убранная маска содержимого каталога «' . $catalog . '» не найдена — проверка не имеет силы'
            );
        }
    }

    // Повторное включение `.gitkeep` обязано идти ПОСЛЕ маски содержимого: если поставить его
    // впереди, маска становится последним совпадением для этого имени, и пустой каталог
    // исчезает из репозитория — первая же загрузка на сервере падает.
    public function testПовторноеВключениеGitkeepПередМаскойТеряетКаталог(): void
    {
        /** @var array<string, array{mask: string, keep: string, lost: list<string>}> $cases */
        $cases = [
            'картинки' => [
                'mask' => '/public/uploads/images/*',
                'keep' => '/public/uploads/images/.gitkeep',
                'lost' => ['каркас каталога загрузок исчезает из репозитория: public/uploads/images/.gitkeep'],
            ],
            'файлы' => [
                'mask' => '/public/uploads/files/*',
                'keep' => '/public/uploads/files/.gitkeep',
                'lost' => ['каркас каталога загрузок исчезает из репозитория: public/uploads/files/.gitkeep'],
            ],
        ];

        foreach ($cases as $catalog => $case) {
            $rules = $this->rulesOfRepository();
            $masked = self::ruleIndex($rules, $case['mask']);
            $keep = self::ruleIndex($rules, $case['keep'], true);

            $reincluded = $rules[$keep];
            array_splice($rules, $keep, 1);
            array_splice($rules, $masked, 0, [$reincluded]);

            self::assertSame(
                $case['lost'],
                $this->trackingViolations($rules),
                'Маска, оказавшаяся после повторного включения каталога «' . $catalog . '», не найдена'
            );
        }
    }

    // Строка, разрешающая сам каталог, — часть схемы, а не украшение: без неё `/public/uploads/*`
    // остаётся последним совпадением для имени каталога, и git не заходит внутрь. Тогда из
    // репозитория исчезает и каталог, и его `.gitkeep`, а на сервере после выгрузки каталога
    // загрузок нет вовсе — первая же картинка падает на недоступный путь.
    public function testБезРазрешенияКаталогаИсчезаетВесьЕгоКаркас(): void
    {
        /** @var array<string, array{allow: string, lost: list<string>}> $cases */
        $cases = [
            'картинки' => [
                'allow' => '/public/uploads/images/',
                'lost' => [
                    'каркас каталога загрузок исчезает из репозитория: public/uploads/images',
                    'каркас каталога загрузок исчезает из репозитория: public/uploads/images/.gitkeep',
                ],
            ],
            'файлы' => [
                'allow' => '/public/uploads/files/',
                'lost' => [
                    'каркас каталога загрузок исчезает из репозитория: public/uploads/files',
                    'каркас каталога загрузок исчезает из репозитория: public/uploads/files/.gitkeep',
                ],
            ],
        ];

        foreach ($cases as $catalog => $case) {
            $rules = $this->rulesOfRepository();
            unset($rules[self::ruleIndex($rules, $case['allow'], true)]);

            self::assertSame(
                $case['lost'],
                $this->trackingViolations($rules),
                'Убранное разрешение каталога «' . $catalog . '» не найдено — проверка не имеет силы'
            );
        }
    }

    public function testПустойФайлНеПроходитНиОднуПроверку(): void
    {
        self::assertSame(
            [
                'обложка не игнорируется git: public/uploads/images/01m4n284s46hp26mtcz51j3peh.png',
                'обложка не игнорируется git: public/uploads/images/01zzzzzzzzzzzzzzzzzzzzzzzz.jpg',
                'файл загрузки не игнорируется git: public/uploads/files/otchet-za-god.pdf',
                'боевой конфиг не игнорируется git: config.php',
                'журнал ошибок не игнорируется git: core/storage/error.log',
                'замок установщика не игнорируется git: public/install.lock',
                'содержимое vendor не игнорируется git: vendor/autoload.php',
            ],
            $this->violations([]),
            'Количество находок на пустом наборе правил не равно числу проверяемых закрытий'
        );
    }

    /**
     * Все находки сразу: и открытые адреса, и каркас каталога, уехавший из репозитория.
     *
     * @param list<array{pattern: string, negate: bool}> $rules
     *
     * @return list<string>
     */
    private function violations(array $rules): array
    {
        return array_merge($this->ignorationViolations($rules), $this->trackingViolations($rules));
    }

    /**
     * @param list<array{pattern: string, negate: bool}> $rules
     *
     * @return list<string>
     */
    private function ignorationViolations(array $rules): array
    {
        $found = [];

        foreach (self::MUST_IGNORE as $path) {
            if (! self::ignored($rules, $path)) {
                $found[] = self::reason($path) . ': ' . $path;
            }
        }

        return $found;
    }

    /**
     * @param list<array{pattern: string, negate: bool}> $rules
     *
     * @return list<string>
     */
    private function trackingViolations(array $rules): array
    {
        $found = [];

        foreach (self::MUST_TRACK as $path) {
            if (self::ignored($rules, $path)) {
                $found[] = 'каркас каталога загрузок исчезает из репозитория: ' . $path;
            }
        }

        return $found;
    }

    // Подпись находки называет, что именно открылось: список закрытий растёт вместе с файлом
    // правил, а текст должен оставаться понятным без сверки с путем.
    private static function reason(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'public/uploads/images/') => 'обложка не игнорируется git',
            str_starts_with($path, 'public/uploads/files/') => 'файл загрузки не игнорируется git',
            $path === 'config.php' => 'боевой конфиг не игнорируется git',
            $path === 'public/install.lock' => 'замок установщика не игнорируется git',
            str_starts_with($path, 'core/storage/') => 'журнал ошибок не игнорируется git',
            str_starts_with($path, 'vendor/') => 'содержимое vendor не игнорируется git',
            default => 'адрес не игнорируется git',
        };
    }

    /** @return list<array{pattern: string, negate: bool}> */
    private function rulesOfRepository(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::FILE;
        self::assertFileExists($path, 'Правил игнора репозитория нет вовсе');

        return self::rules((string) file_get_contents($path));
    }

    /**
     * Вердикт настоящего git по пути: `git check-ignore -q` отвечает кодом `0`, когда путь
     * игнорируется, и кодом `1`, когда нет. Три исхода различаются явно. Недоступный запуск
     * команд — падение с текстом «git недоступен»: при отключённой функции запуска статус не
     * меняется и остаётся нулём, а ноль читается как «игнорируется» — молча выдать такой вердикт
     * значит враньём накрыть расхождение. Вердикт вне ожидаемых кодов — падение с кодом: нет
     * бинарника, нет каталога `.git`, путь за пределами репозитория. Иначе — настоящий разбор
     * сверки. Проверка падает, а не пропускается: молча выключенная сверка — тот же выключенный
     * страж.
     */
    private static function ignoredByGit(string $path): bool
    {
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            self::fail('git недоступен: запуск команд отключён, сверка с настоящей проверкой игнора не состоялась');
        }

        $output = [];
        $status = 0;

        exec(
            self::GIT . ' -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' check-ignore -q ' . escapeshellarg($path) . ' 2>&1',
            $output,
            $status
        );

        if ($status !== 0 && $status !== 1) {
            self::fail('Сверка с `git check-ignore` по пути ' . $path . ' не состоялась (код ' . $status . '): ' . trim(implode("\n", $output)));
        }

        return $status === 0;
    }

    /**
     * Правила файла: порядок значим, потому что решает последнее совпадение.
     *
     * @return list<array{pattern: string, negate: bool}>
     */
    private static function rules(string $text): array
    {
        $rules = [];

        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $negate = str_starts_with($line, '!');

            $rules[] = ['pattern' => $negate ? substr($line, 1) : $line, 'negate' => $negate];
        }

        return $rules;
    }

    /**
     * Игнорируется ли адрес. Родительские каталоги проверяются первыми: git не заходит в
     * исключённый каталог, и повторное включение файла внутри него ничего не стоит.
     *
     * @param list<array{pattern: string, negate: bool}> $rules
     */
    private static function ignored(array $rules, string $path): bool
    {
        $segments = explode('/', $path);

        for ($i = 1; $i < count($segments); ++$i) {
            if (self::verdict($rules, implode('/', array_slice($segments, 0, $i)), true)) {
                return true;
            }
        }

        return self::verdict($rules, $path, !str_contains($path, '.'));
    }

    /**
     * @param list<array{pattern: string, negate: bool}> $rules
     */
    private static function verdict(array $rules, string $path, bool $directory): bool
    {
        $ignored = false;

        foreach ($rules as $rule) {
            if (! self::patternMatches($rule['pattern'], $path, $directory)) {
                continue;
            }

            $ignored = !$rule['negate'];
        }

        return $ignored;
    }

    private static function patternMatches(string $pattern, string $path, bool $directory): bool
    {
        $dirOnly = str_ends_with($pattern, '/');

        if ($dirOnly && !$directory) {
            return false;
        }

        $pattern = rtrim($pattern, '/');
        $anchored = str_starts_with($pattern, '/') || str_contains($pattern, '/');
        $body = ltrim($pattern, '/');

        if ($body === '') {
            return false;
        }

        foreach (['**', '[', '?'] as $unsupported) {
            if (str_contains($body, $unsupported)) {
                self::fail('Страж не умеет читать это правило git: ' . $pattern);
            }
        }

        // `*` подставляется ДО экранирования, а не после: `preg_quote` сам превращает звезду в
        // `\*`, и замена по уже экранированному тексту оставила бы в образце обратный слэш —
        // правило переставало бы что-либо накрывать, а страж делал бы вид, что закрыто.
        $quoted = implode('[^/]*', array_map(
            static fn (string $part): string => preg_quote($part, '#'),
            explode('*', $body)
        ));

        $regex = '#^' . ($anchored ? '' : '(?:[^/]*/)*') . $quoted . '$#';

        return preg_match($regex, $path) === 1;
    }

    /**
     * @param list<array{pattern: string, negate: bool}> $rules
     *
     * Номер правила по его тексту и по способу (запрет или возврат в репозиторий): одно и то же
     * имя встречается в файле и так и так, а мутации нужен ровно второй.
     * Отсутствие фрагмента — ошибка самого теста, а не зелёный прогон.
     */
    private static function ruleIndex(array $rules, string $pattern, bool $negate = false): int
    {
        foreach ($rules as $index => $rule) {
            if ($rule['pattern'] === $pattern && $rule['negate'] === $negate) {
                return $index;
            }
        }

        self::fail('В `.gitignore` нет ожидаемого правила: ' . ($negate ? '!' : '') . $pattern);
    }
}
