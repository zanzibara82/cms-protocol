<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Зона нажатия не меньше 44 px — глобальное требование проекта без исключений; оно записано и в
// комментариях источника (admin/layout.php:59-60), и в глобальных ограничениях пилота. Проверка
// идёт по исходникам представлений, а не только по отрендеренным страницам: http-набор видит
// ровно те маршруты, до которых дошёл тест, а требование касается каждого контрола — в том числе
// тех, что появятся в приложениях позже.
//
// Класс ищется в значении атрибута class, а не где угодно в теге; строчной ссылке этого мало — у
// неё требуются классы блочного вывода. Класс, собранный в PHP, проверяется по литералам, из
// которых его собирают, поэтому файлов, освобождённых от требования, в наборе нет.
final class TapTargetTest extends TestCase
{
    // Класс, задающий минимальную зону нажатия: он уже есть в собранном артефакте стилей.
    private const REQUIRED_CLASS = 'min-h-[44px]';

    // Ширина — та же зона нажатия по другой оси: правило 44 px в требовании не разделяет оси.
    // Признак прицеплен к совмещению «высота + центрирует содержимое»: элемент, который держит
    // высоту и центрирует короткий текст, — это компактная квадратная цель, чью ширину набирает
    // только сам текст и отступы; у ссылки с произвольным текстом ширина по правилу не
    // проверяется. Совмещение этих двух классов в дереве встречается ровно в пагинаторе, поэтому
    // ни один другой контрол под признак не попадает.
    private const REQUIRED_WIDTH_CLASS = 'min-w-[44px]';
    private const SQUARE_HINT_CLASS = 'justify-center';

    // min-height не действует на строчном элементе, а ссылка по умолчанию строчная: чтобы высота
    // вообще стала зоной нажатия, нужен блочный или строчно-блочный вывод.
    private const BLOCK_DISPLAY_CLASSES = ['block', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid'];

    // Скрытое поле — не цель для пальца: зоны нажатия у него нет вовсе. Пробел здесь и в остальных
    // разборах задан списком байтов, а не `\s`: без модификатора `u` PCRE считает пробелом и байт
    // 0x85, а он входит в кириллическую «х» — русский комментарий мог бы сойти за код.
    private const HIDDEN_INPUT = '/(?<=[\n\r\t ])type[\n\r\t ]*=[\n\r\t ]*"hidden"/';

    // Каталоги разметки. Отсутствие любого из них — падение стража, а не зелёный прогон: тихо
    // вычеркнутый каталог означал бы, что проверяется меньше, чем требует правило.
    private const MARKUP_DIRS = ['theme', 'admin', 'apps', 'core', 'public'];

    public function testКаждыйКонтролВШаблонахНесётЗонуНажатияСорокЧетыреПикселя(): void
    {
        $violations = [];

        foreach ($this->templates() as $relative) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
            foreach ($this->violations($code, $relative) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Контрол без ' . self::REQUIRED_CLASS . ': зона нажатия меньше 44 px'
        );
    }

    public function testСтражЛовитКонтролБезЗоныНажатия(): void
    {
        self::assertSame(
            ['probe.php: строка 1: <a> без min-h-[44px]'],
            $this->violations('<a class="underline" href="/news">Новости</a>', 'probe.php')
        );

        // Значение похожего предела классом не считается: `min-h-[24px]` — это не 44 px.
        self::assertSame(
            ['probe.php: строка 1: <a> без min-h-[44px]'],
            $this->violations('<a class="inline-flex min-h-[24px] items-center" href="/news">Новости</a>', 'probe.php')
        );

        // Многострочный тег: проверка читает его целиком, а не только строку с открытием.
        self::assertSame(
            ['probe.php: строка 1: <input> без min-h-[44px]'],
            $this->violations("<input class=\"mt-1 w-full\"\n       type=\"text\" name=\"login\">", 'probe.php')
        );

        self::assertSame([], $this->violations('<a class="inline-flex min-h-[44px] items-center" href="/news">Новости</a>', 'probe.php'));
    }

    // Класс обязан стоять именно в атрибуте class: иначе `title="min-h-[44px]"` (или любое другое
    // значение того же текста) закрывало бы требование, а зона нажатия оставалась бы строчной.
    public function testКлассВДругомАтрибутеНеЗачитывается(): void
    {
        self::assertSame(
            ['probe.php: строка 1: <a> без min-h-[44px]'],
            $this->violations('<a class="inline-flex items-center" title="min-h-[44px]" href="/news">Новости</a>', 'probe.php')
        );
    }

    // min-height на строчной ссылке не растёт в высоту: проверка требует блочного вывода там, где
    // без него правило было бы выполнено на бумаге и нарушено в браузере.
    public function testСтрочнаяСсылкаСКлассомВсёРавноНеГодится(): void
    {
        self::assertSame(
            ['probe.php: строка 1: <a> с ' . self::REQUIRED_CLASS . ', но min-height к строчной ссылке не применяется — нужен block, inline-block, flex, inline-flex или grid'],
            $this->violations('<a class="min-h-[44px] hover:underline" href="/news">Новости</a>', 'probe.php')
        );

        // У строчных форм и кнопок вывод блочный по умолчанию, поэтому от них этот класс не требуется.
        self::assertSame([], $this->violations('<button class="min-h-[44px] px-4" type="submit">Сохранить</button>', 'probe.php'));
        self::assertSame([], $this->violations('<input class="min-h-[44px] w-full" type="text" name="login">', 'probe.php'));
    }

    // Класс собирается в PHP (вкладки админки, кнопки строк справочника): тег проверяется по
    // литералам, из которых переменную собирают, а не освобождается целиком.
    public function testКлассИзПеременнойПроверяетсяПоПрисваиваниям(): void
    {
        $good = "\$class = \$active\n"
            . "    ? 'inline-flex min-h-[44px] items-center bg-ink'\n"
            . "    : 'inline-flex min-h-[44px] items-center hover:bg-ink/5';\n"
            . "\$items .= '<li><a class=\"' . \$class . '\" href=\"/admin\">Админка</a></li>';\n";
        self::assertSame([], $this->violations($good, 'probe.php'));

        // Тот же тег, но одна из двух ветки потеряла класс — объединение веток этого не увидит.
        $noTap = str_replace("'inline-flex min-h-[44px] items-center hover:bg-ink/5'", "'inline-flex items-center hover:bg-ink/5'", $good);
        self::assertSame(
            ['probe.php: строка 4: <a> берёт class из $class, а в его присваиваниях нет min-h-[44px]'],
            $this->violations($noTap, 'probe.php')
        );

        // Ветка без блочного вывода: зона нажатия есть на бумаге, а в браузере ссылка остаётся строчной.
        $inline = str_replace("'inline-flex min-h-[44px] items-center bg-ink'", "'min-h-[44px] items-center bg-ink'", $good);
        self::assertSame(
            ['probe.php: строка 4: <a> берёт class из $class, а его ветка не задала block, inline-block, flex, inline-flex или grid'],
            $this->violations($inline, 'probe.php')
        );

        // Переменная названа в атрибуте, но в файле её не собирают: проверка не выполнена — находка.
        $unknown = "\$items = '<li><a class=\"' . \$class . '\" href=\"/admin\">Админка</a></li>';\n";
        self::assertSame(
            ['probe.php: строка 1: <a> берёт class из $class, а в файле нет ни одного его литерала — зону нажатия не проверить'],
            $this->violations($unknown, 'probe.php')
        );

        // Литерал плюс приклеенная переменная: классы в литерале, суффикс из переменной.
        $mixed = "\$kind = 'text-cream';\n"
            . "return '<button class=\"inline-flex min-h-[44px] items-center text-sm ' . \$kind . '\" type=\"submit\">Удалить</button>';\n";
        self::assertSame([], $this->violations($mixed, 'probe.php'));
    }

    // Квадратная компактная цель: класс квадратности рядом с высотой требует и ширины 44 px.
    public function testКвадратныйКонтролБезШириныЛовится(): void
    {
        // Совмещение признаков без ширины — одна находка с предписанным текстом.
        self::assertSame(
            ['probe.php: строка 1: <a> с ' . self::REQUIRED_CLASS . ' и ' . self::SQUARE_HINT_CLASS . ', но без ' . self::REQUIRED_WIDTH_CLASS],
            $this->violations('<a class="inline-flex min-h-[44px] min-w-9 items-center justify-center" href="/news/2">2</a>', 'probe.php')
        );

        // Ширина доросла до правила — защита молчит.
        self::assertSame([], $this->violations('<a class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center" href="/news/2">2</a>', 'probe.php'));

        // Признака квадратности нет: ширина у ссылки с произвольным текстом не требуется.
        self::assertSame([], $this->violations('<a class="inline-flex min-h-[44px] items-center" href="/news">Новости</a>', 'probe.php'));

        // Класс собирается переменной: ветка с признаком квадратности несёт и ширину.
        $squareVar = "\$class = 'inline-flex min-h-[44px] items-center justify-center';\n"
            . "\$items .= '<li><a class=\"' . \$class . '\" href=\"/news/2\">2</a></li>';\n";
        self::assertSame(
            ['probe.php: строка 2: <a> берёт class из $class, а его ветка несёт ' . self::SQUARE_HINT_CLASS . ', но не ' . self::REQUIRED_WIDTH_CLASS],
            $this->violations($squareVar, 'probe.php')
        );

        // Порядок веток закреплён текстом нарушения: зона нажатия важнее ширины, поэтому элемент
        // без высоты отчитывается ею, а не шириной.
        self::assertSame(
            ['probe.php: строка 1: <a> без ' . self::REQUIRED_CLASS],
            $this->violations('<a class="inline-flex justify-center" href="/news">Новости</a>', 'probe.php')
        );
    }

    // Исключения читают значения атрибутов, а не подстроку тега: `data-type="hidden"` — видимый
    // контрол, а класс `not-sr-only` появляется на экране и обязан быть крупным.
    public function testСкрытоеПолеИКлавиатурнаяСсылкаНеСчитаютсяЦелямиДляПальца(): void
    {
        self::assertSame([], $this->violations('<input type="hidden" name="_token" value="ab">', 'probe.php'));
        self::assertSame([], $this->violations('<a class="sr-only focus:not-sr-only" href="#main">К содержимому</a>', 'probe.php'));

        self::assertSame(
            ['probe.php: строка 1: <button> без min-h-[44px]'],
            $this->violations('<button data-type="hidden" class="px-2" type="submit">Скрыть</button>', 'probe.php')
        );
        self::assertSame(
            ['probe.php: строка 1: <a> без min-h-[44px]'],
            $this->violations('<a class="not-sr-only hover:underline" href="/news">Новости</a>', 'probe.php')
        );
    }

    /**
     * Нарушения в файле: каждый тег контрола обязан нести класс зоны нажатия в атрибуте class —
     * прямо в разметке или через переменную, все присваивания которой в этом файле несут класс.
     *
     * @return list<string>
     */
    private function violations(string $code, string $relative): array
    {
        if (preg_match_all('/<(a|button|input|select|textarea)\b/i', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $out = [];

        foreach ($matches as $match) {
            $tag = strtolower((string) $match[1][0]);
            $start = (int) $match[0][1];
            $end = $this->tagEnd($code, $start + strlen($match[0][0]));

            if ($end === null) {
                continue;
            }

            $text = substr($code, $start, $end - $start + 1);
            $line = 1 + substr_count($code, "\n", 0, $start);

            if (preg_match(self::HIDDEN_INPUT, $text) === 1) {
                continue;
            }

            $class = $this->classAttributeValue($text);

            if ($class === null) {
                $out[] = $relative . ': строка ' . $line . ': <' . $tag . '> без class — зона нажатия не задана';

                continue;
            }

            $tokens = $this->classTokens($class);

            if (in_array('sr-only', $tokens, true)) {
                continue;
            }

            foreach ($this->check($code, $class, $tokens, $tag) as $violation) {
                $out[] = $relative . ': строка ' . $line . ': <' . $tag . '> ' . $violation;
            }
        }

        return $out;
    }

    /**
     * Претензия к значению class: пусто, нет зоны нажатия, класс приходит из неполной переменной,
     * либо зона нажатия есть, но ссылку она не делает — min-height на строчном элементе не действует.
     * Пустой список значит «всё в порядке».
     *
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private function check(string $code, string $class, array $tokens, string $tag): array
    {
        // Токены, названные прямо в разметке: `$class` классом не является.
        $declared = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => !str_starts_with($token, '$')
        ));

        // Класс не назван в разметке вовсе — он приходит из переменной, и каждая ветка, из которой
        // её собирают, обязана нести зону нажатия сама: объединение веток прятало бы половину.
        if ($declared === []) {
            return $this->variableClassProblem($code, $class, $tag);
        }

        if (!in_array(self::REQUIRED_CLASS, $declared, true)) {
            return ['без ' . self::REQUIRED_CLASS];
        }

        // Строчная ссылка — единственный контрол из списка, которому нужен блочный вывод: у кнопки,
        // поля, списка и области вывода вывод строчно-блочный или заменяемый по умолчанию.
        if ($tag === 'a' && array_intersect($declared, self::BLOCK_DISPLAY_CLASSES) === []) {
            return ['с ' . self::REQUIRED_CLASS . ', но min-height к строчной ссылке не применяется — нужен block, inline-block, flex, inline-flex или grid'];
        }

        // Квадратная компактная цель: признак квадратности стоит вместе с высотой, поэтому ширина
        // обязана дорасти до тех же 44 px. Ветка стоит после зоны нажатия, потому что первая
        // претензия возвращает сразу: порядок веток не перекрывает существующих нарушений.
        if (in_array(self::SQUARE_HINT_CLASS, $declared, true) && !in_array(self::REQUIRED_WIDTH_CLASS, $declared, true)) {
            return ['с ' . self::REQUIRED_CLASS . ' и ' . self::SQUARE_HINT_CLASS . ', но без ' . self::REQUIRED_WIDTH_CLASS];
        }

        return [];
    }

    /**
     * Зона нажатия, которую в разметке несёт переменная: проверяются все её литералы в этом файле.
     *
     * @return list<string>
     */
    private function variableClassProblem(string $code, string $class, string $tag): array
    {
        $variables = $this->classVariables($class);

        if ($variables === []) {
            return ['без ' . self::REQUIRED_CLASS];
        }

        foreach ($variables as $variable) {
            $literals = $this->assignmentLiterals($code, $variable);

            if ($literals === []) {
                return ['берёт class из $' . $variable . ', а в файле нет ни одного его литерала — зону нажатия не проверить'];
            }

            foreach ($literals as $literal) {
                $branch = $this->classTokens($literal);

                if (!in_array(self::REQUIRED_CLASS, $branch, true)) {
                    return ['берёт class из $' . $variable . ', а в его присваиваниях нет ' . self::REQUIRED_CLASS];
                }

                if ($tag === 'a' && array_intersect($branch, self::BLOCK_DISPLAY_CLASSES) === []) {
                    return ['берёт class из $' . $variable . ', а его ветка не задала block, inline-block, flex, inline-flex или grid'];
                }

                if (in_array(self::SQUARE_HINT_CLASS, $branch, true) && !in_array(self::REQUIRED_WIDTH_CLASS, $branch, true)) {
                    return ['берёт class из $' . $variable . ', а его ветка несёт ' . self::SQUARE_HINT_CLASS . ', но не ' . self::REQUIRED_WIDTH_CLASS];
                }
            }
        }

        return [];
    }

    // Значение атрибута class целиком (вместе с приклеенными переменными) или null, если атрибута нет.
    private function classAttributeValue(string $text): ?string
    {
        if (preg_match('/(?:[\n\r\t "\']|^)class[\n\r\t ]*=[\n\r\t ]*"([^"]*)"/', $text, $matches) === 1) {
            return $matches[1];
        }

        // Атрибут есть, но не в двойных кавычках: разбор не выполнен — молчать страж не вправе.
        return preg_match('/(?:[\n\r\t ]|^)class[\n\r\t ]*=/', $text) === 1 ? '' : null;
    }

    /**
     * Классы, названные прямо в значении: PHP-склейка `' . $x . '` и вставка `<?= … ?>` разбивают
     * строку, но сами классы остаются словами.
     *
     * @return list<string>
     */
    private function classTokens(string $class): array
    {
        $clean = (string) preg_replace('/[\'"][\n\r\t ]*\.[\n\r\t ]*|[\n\r\t ]*\.[\n\r\t ]*[\'"]|<\?=|<\?php|\?>|\([\n\r\t ]*\)|\([\n\r\t ]*|[\n\r\t ]*\)/', ' ', $class);
        // Классы разделяются списком пробельных байтов, а не `\s`: без модификатора `u` PCRE считает
        // пробелом и 0x85, а он входит в кириллическую «х».
        $tokens = array_filter(
            explode(' ', trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $clean))),
            static fn (string $token): bool => $token !== ''
        );

        return array_values($tokens);
    }

    /** @return list<string> */
    private function classVariables(string $class): array
    {
        if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $class, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Литералы, из которых в этом же файле собирается значение переменной: `$x = '…'`, `$x .= '…'`
     * и ветки тернарника. Каждое присваивание и каждая ветка отдаются отдельным элементом списка:
     * объединение веток спрятало бы ветку, потерявшую класс, за соседней.
     *
     * @return list<string>
     */
    private function assignmentLiterals(string $code, string $variable): array
    {
        $pattern = '/^[ \t\r\n]*\$' . preg_quote($variable, '/') . '[ \t\r\n]*\.?=[ \t\r\n]*(.*?);[ \t\r\n]*$/ms';

        if (preg_match_all($pattern, $code, $statements) === false) {
            return [];
        }

        $literals = [];

        foreach ($statements[1] as $statement) {
            // Кавычки в разбор не попадают: иначе одна из них приклеилась бы к последнему классу.
            if (preg_match_all("/'([^']*)'|\"([^\"]*)\"/", (string) $statement, $found, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
                continue;
            }

            foreach ($found as $quote) {
                $literals[] = (string) ($quote[1] ?? $quote[2] ?? '');
            }
        }

        return $literals;
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

    /** @return list<string> путей относительно корня проекта */
    private function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (self::MARKUP_DIRS as $dir) {
            self::assertDirectoryExists(
                $root . '/' . $dir,
                $dir . ': каталог разметки исчез из дерева — страж проверял бы меньше, чем требует правило'
            );

            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));

            foreach ($items as $item) {
                if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                    $relative = $this->relative($item->getPathname());
                    $found[$relative] = true;
                }
            }
        }

        $paths = array_keys($found);
        sort($paths);

        self::assertNotEmpty($paths, 'Ни одного файла разметки не найдено — проверять нечего');

        return $paths;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
