<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

// Единственный способ опубликовать черновик «случайно» — новый запрос к tbl_news_item без
// visibleWhere(). Страж читает исходники раздела и требует, чтобы каждая функция, которая этот
// стол читала, либо звала visibleWhere(), либо стояла в списке ниже с названной причиной.
final class NewsVisibilityTest extends TestCase
{
    /**
     * Признаки чтения материалов: прямой вызов таблицы, общий FROM публичных выборок и делегирование
     * области видимости. newsItemFrom() и feedScope() — сами по себе не защита, но функция,
     * которая собирает WHERE через feedScope(), получает visibleWhere() оттуда.
     */
    private const READ_MARKERS = ["t('news_item')", 'newsItemFrom(', 'feedScope('];
    private const GUARD_MARKERS = ['visibleWhere(', 'feedScope('];

    private const ALLOWED = [
        // Редактор обязан видеть черновик, не наступивший срок и снятый материал: иначе список
        // «Черновики» был бы пустым, а правка — невозможной.
        'apps/news/lib/items.php' => '*',
        // Справочник считает материалы в рубрике и теге, чтобы сказать, можно ли их удалить.
        'apps/news/lib/catalog.php' => '*',
        // Публичное чтение: три функции, которым материал нужен независимо от срока и статуса.
        'apps/news/lib/feed.php' => ['newsItemFrom', 'findAny', 'findByOldSlug'],
    ];

    public function testЧтениеМатериаловОгражденоВидимостью(): void
    {
        $violations = [];

        foreach ($this->sources() as $file) {
            $relative = $this->relative($file);
            $allowed = self::ALLOWED[$relative] ?? [];

            if ($allowed === '*') {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($file));

            foreach ($this->functions($code, $relative) as $name => $body) {
                $reads = false;
                foreach (self::READ_MARKERS as $marker) {
                    if (str_contains($body, $marker)) {
                        $reads = true;
                        break;
                    }
                }

                if (!$reads || in_array($name, $allowed, true)) {
                    continue;
                }

                foreach (self::GUARD_MARKERS as $marker) {
                    if (str_contains($body, $marker)) {
                        continue 2;
                    }
                }

                $violations[] = $relative . ' — ' . $name . '() читает tbl_news_item без visibleWhere()';
            }
        }

        self::assertSame([], $violations, 'Новое чтение материалов обязано быть ограждено видимостью или записано в белый список с причиной');
    }

    public function testБелыйСписокНеДержитЛишнего(): void
    {
        // Белый список — это решение, а не страховка: каждая его строка обязана по-прежнему
        // соответствовать функции, которая читает материалы без проверки видимости.
        $used = [];

        foreach (self::ALLOWED as $file => $names) {
            $path = dirname(__DIR__, 2) . '/' . $file;
            self::assertFileExists($path, $file . ' из белого списка стража больше нет');

            if ($names === '*') {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($path));
            $functions = $this->functions($code, $file);

            foreach ($names as $name) {
                self::assertArrayHasKey($name, $functions, $file . ': функции ' . $name . '() в белом списке нет');

                $reads = false;
                foreach (self::READ_MARKERS as $marker) {
                    if (str_contains((string) $functions[$name], $marker)) {
                        $reads = true;
                    }
                }

                self::assertTrue($reads, $file . ': ' . $name . '() больше не читает материалы — строка списка устарела');
                $used[] = $file . '::' . $name;
            }
        }

        self::assertCount(3, $used, 'Список разрешённых функций читается целиком — три именованные строки feed.php');
    }

    /**
     * Имя проверки для замыкания — номер строки, и для `apps/news/routes.php` это единственная
     * наводка текста падения на место в файле: хук карты сайтов начинается на 48-й строке. Если
     * вычёркивание комментариев сжимает текст, страж называет `{closure:30}`, следующий правщик не
     * может честно внести легальное замыкание в список разрешённого и закрывает весь файл маской.
     */
    public function testНомерСтрокиЗамыканияСчитаетсяПоИсходномуТексту(): void
    {
        $code = $this->stripComments((string) file_get_contents(dirname(__DIR__, 2) . '/apps/news/routes.php'));
        $closures = array_values(array_filter(
            array_keys($this->functions($code, 'apps/news/routes.php')),
            static fn (string $name): bool => str_starts_with($name, '{closure:')
        ));

        self::assertSame(['{closure:55}'], $closures, 'Хук карты сайтов — единственное замыкание файла, его строка — 55');
    }

    // Прежний сборщик считал фигурные скобки внутри строковых литералов: тело функции с `'}'` молча
    // обрезалось до хвоста, а законный код с `'{'` внутри строки ронял падение «потерял закрывающую
    // скобку». Теперь строка пропускается целиком тем же помощником, что бережёт границы выражения, и
    // скобки внутри неё не считаются; настоящая незакрытая фигурная скобка по-прежнему красна
    // падением. Ограничение: интерполяция вида `"{$x}"` и `here-doc` строки под пропуск не попадают —
    // правка отвечает на измеренный дефект, а не строит лексер. Доказательство — подставной текст
    // прямо здесь, разбираемые файлы раздела не тронуты.
    public function testРазбаланСкобокРоняетСборщик(): void
    {
        $fixtures = [
            'незакрытая скобка' => "<?php\nfunction a(): void\n{\n    \$s = 'x';\n",
        ];

        foreach ($fixtures as $label => $code) {
            $crashed = false;

            try {
                $this->functions((string) $code, 'fixture.php');
            } catch (AssertionFailedError $failure) {
                $crashed = true;
                self::assertStringContainsString('файл fixture.php', $failure->getMessage(), $label);
                self::assertStringContainsString('(строка ', $failure->getMessage(), $label);
            }

            self::assertTrue($crashed, 'Сборщик промолчал на разбалан скобок: ' . $label);
        }

        $fixtures = [
            'закрывающая скобка в строке' => "<?php\nfunction a(): void\n{\n    \$s = '}';\n    \$t = 2;\n}\n",
            'открывающая скобка в строке' => "<?php\nfunction a(): void\n{\n    \$s = '{';\n    \$t = 2;\n}\n",
        ];

        foreach ($fixtures as $label => $code) {
            $functions = $this->functions((string) $code, 'fixture.php');

            self::assertSame(
                ['a'],
                array_keys($functions),
                $label . ': вместо единственного имени a в массиве фантомное замыкание'
            );
            self::assertStringContainsString(
                '$t = 2;',
                $functions['a'],
                $label . ': тело обрезано на скобке внутри строки'
            );
        }
    }

    /**
     * Тела функций: именованных, анонимных `function () {}` и стрелочных `fn () => ...`.
     * Замыкания собираются наравне с именованными: чтение материалов внутри `static function () {}`,
     * стоящего на уровне файла, иначе остаётся для стража невидимым. Имя замыкания не выдумывается —
     * в отчёте оно выглядит как `{closure:номер строки}`.
     *
     * @return array<string, string> имя функции → её тело
     */
    private function functions(string $code, string $file): array
    {
        $out = [];

        preg_match_all(
            '/\bfunction\s*([a-zA-Z_]\w*)?\s*\([^)]*\)(?:\s*use\s*\([^)]*\))?\s*[^{;]*\{/',
            $code,
            $starts,
            PREG_OFFSET_CAPTURE
        );

        foreach ($starts[0] as $index => $start) {
            $name = (string) $starts[1][$index][0];
            $bodyStart = (int) $start[1] + strlen((string) $start[0]);
            $key = $name !== '' ? $name : self::closureName($code, (int) $start[1]);

            $out[self::uniqueKey($out, $key)] = self::bracedBody($code, $bodyStart, $file);
        }

        // Стрелочные функции тела в фигурных скобках не имеют, поэтому их граница — конец
        // выражения, а не закрывающая скобка.
        preg_match_all('/(?<![\w$])fn\s*\(/', $code, $arrows, PREG_OFFSET_CAPTURE);

        foreach ($arrows[0] as $arrow) {
            $position = (int) $arrow[1];

            $out[self::uniqueKey($out, self::closureName($code, $position))] = substr(
                $code,
                $position,
                max(0, self::expressionEnd($code, $position) - $position)
            );
        }

        return $out;
    }

    /**
     * Тело от открывающей `{` до парной закрывающей: тот же счёт скобок, что вёл прежний сборщик, но
     * строковый литерал пропускается целиком тем же `skipQuoted()`, что бережёт границы выражения, —
     * скобка внутри строки границу тела не задаёт. Незакрытая строка отдаёт конец кода, цикл доходит
     * до конца и падает тем же сообщением о потерянном закрытии. Разбалан настоящей фигурной скобки —
     * не молчаливый хвост до конца файла, а падение проверки с именем файла и позицией потерянного
     * закрытия: тихое самоискажение в страже недопустимо, даже если достижимо только будущей правкой.
     */
    private static function bracedBody(string $code, int $bodyStart, string $file): string
    {
        $length = strlen($code);
        $depth = 1;
        $position = $bodyStart;

        while ($position < $length && $depth > 0) {
            $character = $code[$position];

            if ($character === "'" || $character === '"') {
                $position = self::skipQuoted($code, $position, $character) + 1;

                continue;
            }

            if ($character === '{') {
                ++$depth;
            } elseif ($character === '}') {
                --$depth;
            }

            ++$position;
        }

        if ($depth > 0) {
            $opened = $bodyStart - 1;

            self::fail(
                'Сборщик тел функций потерял закрывающую скобку: файл ' . $file . ', тело открыто на позиции ' . $opened .
                ' (строка ' . (substr_count($code, "\n", 0, $opened) + 1) . ') и до конца файла не закрыто'
            );
        }

        return substr($code, $bodyStart, max(0, $position - $bodyStart));
    }

    /**
     * Где заканчивается выражение, начатое на `$position`: запятая, точка с запятой или закрывающая
     * скобка собственного уровня. Кавычки пропускаются целиком — запятая внутри строки выражение
     * не завершает, иначе тело стрелочной функции обрезалось бы на первом тексте через пробел.
     */
    private static function expressionEnd(string $code, int $position): int
    {
        $length = strlen($code);
        $depth = 0;

        for (; $position < $length; ++$position) {
            $character = $code[$position];

            if ($character === "'" || $character === '"') {
                $position = self::skipQuoted($code, $position, $character);
                continue;
            }

            if ($character === '(' || $character === '[' || $character === '{') {
                ++$depth;
                continue;
            }

            if ($character === ')' || $character === ']' || $character === '}') {
                if ($depth === 0) {
                    break;
                }

                --$depth;

                continue;
            }

            if ($depth === 0 && ($character === ',' || $character === ';')) {
                break;
            }
        }

        return $position;
    }

    /** Позиция закрывающей кавычки того же вида; незакрытая строка отдаёт конец кода. */
    private static function skipQuoted(string $code, int $position, string $quote): int
    {
        $length = strlen($code);

        for (++$position; $position < $length; ++$position) {
            if ($code[$position] === '\\') {
                ++$position;

                continue;
            }

            if ($code[$position] === $quote) {
                return $position;
            }
        }

        return $length;
    }

    /** Имя замыкания: строка, на которой оно начато — придумывать его стражу не для чего. */
    private static function closureName(string $code, int $position): string
    {
        return '{closure:' . (substr_count($code, "\n", 0, $position) + 1) . '}';
    }

    /**
     * Два замыкания на одной строке не должны затирать тело друг друга: иначе одно из них
     * выпадает из проверки молча.
     *
     * @param array<string, string> $out
     */
    private static function uniqueKey(array $out, string $key): string
    {
        while (isset($out[$key])) {
            $key .= '~';
        }

        return $key;
    }

    /**
     * Вычёркивание комментариев сохраняет длину текста и переводы строк: каждый заменённый кусок
     * становится пробелами той же длины, а перевод строки остаётся на месте. Иначе смещение в
     * вычищенном тексте расходится со смещением в исходном, номер строки замыкания (см.
     * closureName) съезжает, и имя проверки перестаёт быть честной наводкой на место в файле.
     */
    private function stripComments(string $code): string
    {
        $blank = static fn (array $match): string => (string) preg_replace('/[^\n\r]/', ' ', $match[0]);

        $out = preg_replace_callback('/(^|\s)\/\/[^\n\r]*/', $blank, $code) ?? $code;
        $out = preg_replace_callback('/#![^\n\r]*/', $blank, $out) ?? $out;
        $out = preg_replace_callback('/\/\*.*?\*\//s', $blank, $out) ?? $out;

        return preg_replace_callback('/(^|\s)#[^\n\r]*/', $blank, $out) ?? $out;
    }

    /** @return list<string> */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach ([$root . '/apps/news/lib', $root . '/apps/news/actions', $root . '/apps/news/templates'] as $dir) {
            self::assertDirectoryExists(
                $dir,
                $dir . ': каталог исходников раздела исчез из дерева — страж видимости проверял бы меньше, чем требует правило'
            );

            $found = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $found[] = $item->getPathname();
                }
            }

            self::assertNotEmpty($found, $dir . ': каталог не отдал ни одного PHP-файла — проверять нечего');
            $out = array_merge($out, $found);
        }

        foreach ([$root . '/apps/news/admin.php', $root . '/apps/news/routes.php'] as $file) {
            self::assertFileExists(
                $file,
                $file . ': файл раздела исчез из дерева — страж видимости не увидел бы эту точку выдачи'
            );
            $out[] = $file;
        }

        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
