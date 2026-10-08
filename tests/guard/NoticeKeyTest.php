<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Уведомление едет в адресную строку ключом, а текст берётся из словаря CORE_NOTICE_TEXT.
// Правило проекта «кнопка ↔ обработчик ↔ словарь» работает в обе стороны: код не вправе передавать
// ключ, которого в словаре нет (экран тогда молча остаётся без плашки), и словарь не вправе держать
// ключ, который никто не передаёт (мёртвая строка переживает удаление кнопки и выдаёт себя за
// работающую функцию). Regex здесь не годится: аргумент вызова — это вложенные скобки, тернарник и
// match, поэтому исходники разбираются токенами PHP.
//
// Каналы передачи ключа, которые знает страж:
//   1) литеральный аргумент вызова redirectWithNotice()/noticeText()/finishAppAction() —
//      функции ядра, которые кладут ключ в URL или читают по нему текст;
//   2) литералы присваивания переменной $notice (правило match-а, где ключ выбирается по действию).
// Новый канал — сюда в self::NOTICE_ARGUMENTS, иначе он останется нестороженным.
// Третья сторона правила: ключ отказа живёт в двух списках (CORE_FAILURE_NOTICE_KEYS и словарь),
// и расхождение между ними глушит объяснение на экране админки.
final class NoticeKeyTest extends TestCase
{
    /** @var array<string, int> имя вызова в нижнем регистре => номер аргумента с ключом уведомления */
    private const NOTICE_ARGUMENTS = [
        'redirectwithnotice' => 1,
        'noticetext' => 0,
        'finishappaction' => 2,
    ];

    /** Имя переменной, в которую код собирает ключ уведомления (без знака доллара). */
    private const NOTICE_VARIABLE = 'notice';

    /**
     * Каждый ключ, который код передаёт как «?notice=<ключ>», обязан быть в словаре: иначе
     * человек вернётся на экран без единого объяснения, что произошло.
     */
    public function testКаждыйПередаваемыйКлючЕстьВСловаре(): void
    {
        $emitted = $this->emittedKeys();
        // Пустой набор — сломанный разбор, а не «нарушений нет»: без этой строки тест зелёный
        // и при молчаливо распавшемся сканере.
        self::assertNotSame([], $emitted, 'Страж не нашёл ни одной передачи ключа уведомления — разбор исходников сломан');

        $unknown = [];
        foreach ($emitted as $key => $sites) {
            if (!array_key_exists($key, CORE_NOTICE_TEXT)) {
                $unknown[] = $key . ' ← ' . implode(', ', $sites);
            }
        }

        self::assertSame([], $unknown, 'Код передаёт ключ уведомления, которого нет в CORE_NOTICE_TEXT');
    }

    /**
     * Обратная сторона: у каждого ключа словаря обязан найтись передающий его код. Сиротский ключ
     * — это обещание интерфейса, которое никто не показывает.
     */
    public function testВСловареНетСиротскихКлючей(): void
    {
        $emitted = array_keys($this->emittedKeys());
        $orphans = [];

        foreach (array_keys(CORE_NOTICE_TEXT) as $key) {
            if (!in_array((string) $key, $emitted, true)) {
                $orphans[] = (string) $key;
            }
        }

        self::assertSame([], $orphans, 'В CORE_NOTICE_TEXT есть ключ, который код нигде не передаёт');
    }

    /**
     * Ключ отказа показывается отдельной плашкой, а её заголовок берётся из того же словаря:
     * поэтому каждый ключ CORE_FAILURE_NOTICE_KEYS обязан стоять и в CORE_NOTICE_TEXT. Ключ только
     * из списка отказов означал бы экран, где действие отменено, а объяснения нет — плашка осталась
     * бы пустой, и человек не понял бы, что произошло.
     */
    public function testКаждыйКлючОтказаЕстьВСловаре(): void
    {
        self::assertNotSame([], CORE_FAILURE_NOTICE_KEYS, 'Список ключей отказа пуст — проверка ниже ничего не проверяет');

        // array_diff, а не array_key_exists по конкретному ключу: состав списка отказов ядро
        // может расширить, и проверка обязана держать новый ключ без правки своего тела.
        $missing = array_values(array_diff(CORE_FAILURE_NOTICE_KEYS, array_keys(CORE_NOTICE_TEXT)));

        self::assertSame([], $missing, 'Ключ отказа есть в CORE_FAILURE_NOTICE_KEYS, но текста для него в CORE_NOTICE_TEXT нет');
    }

    public function testСловарьНеПустИКаждыйЕгоКлючЧитается(): void
    {
        self::assertNotSame([], CORE_NOTICE_TEXT, 'Пустой словарь делает первую проверку пустой');

        foreach (array_keys(CORE_NOTICE_TEXT) as $key) {
            self::assertNotSame('', noticeText((string) $key, CORE_NOTICE_TEXT), 'Ключ ' . $key . ' не читается словарём');
        }
    }

    // Укус стража доказывается на синтетике: реальные файлы не содержат поломки, а проверка
    // «паттерн вообще способен поймать» на них не видна.
    public function testСтражНаходитКлючВоВсехКаналахПередачи(): void
    {
        $samples = [
            "<?php redirectWithNotice('/admin/apps', 'kluch');",
            "<?php noticeText('kluch', CORE_NOTICE_TEXT);",
            "<?php finishAppAction(\$result, '/admin/apps', \$ok ? '' : 'kluch');",
            "<?php function a(): void\n{\n    \$notice = match (\$op) {\n        'install' => 'kluch',\n        default => '',\n    };\n    finishAppAction(\$result, '/admin/apps', \$notice);\n}",
        ];

        foreach ($samples as $source) {
            self::assertSame(['kluch'], $this->keysOf($source), 'Канал передачи ключа не найден: ' . $source);
        }
    }

    // Ложные срабатывания смертельны для доверия к стражу: значение по умолчанию параметра,
    // сравнение и чужая переменная не считают передачей ключа.
    public function testСтражНеСчитаетКлючомЧужиеСтроки(): void
    {
        $samples = [
            "<?php function shell(?string \$notice = null): string\n{\n    return '<b>' . \$notice . '</b>';\n}",
            "<?php if (\$notice === 'kluch') {\n    doWork();\n}",
            "<?php \$drug = ['key' => 'kluch'];",
            "<?php function adminNotice(?string \$text): string\n{\n    return \$text === null ? '' : \$text;\n}",
        ];

        foreach ($samples as $source) {
            self::assertSame([], $this->keysOf($source), 'Страж приписал ключ там, где его не передают: ' . $source);
        }
    }

    /** @return array<string, list<string>> ключ => список «файл:строка» мест передачи */
    private function emittedKeys(): array
    {
        $out = [];

        foreach ($this->phpFiles() as $file) {
            $relative = $this->relative($file);

            foreach ($this->keysIn((string) file_get_contents($file)) as [$key, $line]) {
                $out[$key][] = $relative . ':' . $line;
            }
        }

        ksort($out);

        return $out;
    }

    /** @return list<string> найденные ключи исходника без дублей */
    private function keysOf(string $code): array
    {
        return array_values(array_unique(array_column($this->keysIn($code), 0)));
    }

    /** @return list<array{string, int}> пары «ключ => строка» */
    private function keysIn(string $code): array
    {
        $tokens = $this->significant($code);
        $total = count($tokens);
        $found = [];

        for ($i = 0; $i < $total; $i++) {
            $name = strtolower($tokens[$i]['text']);

            if ($tokens[$i]['id'] === T_STRING && isset(self::NOTICE_ARGUMENTS[$name]) && $this->isCall($tokens, $i)) {
                $key = $this->argumentLiterals($tokens, $i, self::NOTICE_ARGUMENTS[$name]);
                foreach ($key as [$literal, $line]) {
                    $found[] = [$literal, $line];
                }

                continue;
            }

            if (
                $tokens[$i]['id'] === T_VARIABLE
                && substr($name, 1) === self::NOTICE_VARIABLE
            ) {
                foreach ($this->noticeAssignmentKeys($tokens, $i) as [$literal, $line]) {
                    $found[] = [$literal, $line];
                }
            }
        }

        return $found;
    }

    /**
     * Токены без пробелов и комментариев, каждый с глубиной круглых и квадратных скобок на момент
     * своего чтения. Фигурные скобки в счёт не идут: тело функции — не вложенность вызова, иначе
     * присваивание внутри блока выглядело бы параметром функции.
     *
     * @return list<array{id: int, text: string, line: int, depth: int}>
     */
    private function significant(string $code): array
    {
        $out = [];
        $depth = 0;

        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $id = -1;
                $text = $token;
                $line = 0;
            } else {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $id = $token[0];
                $text = $token[1];
                $line = $token[2];
            }

            if ($text === ')' || $text === ']') {
                $depth--;
            }

            $out[] = ['id' => $id, 'text' => $text, 'line' => $line, 'depth' => max($depth, 0)];

            if ($text === '(' || $text === '[') {
                $depth++;
            }
        }

        return $out;
    }

    /**
     * Вызов ли это функции, а не объявление, метод у объекта или «use function».
     *
     * @param list<array{id: int, text: string, line: int, depth: int}> $tokens
     */
    private function isCall(array $tokens, int $index): bool
    {
        $open = $tokens[$index + 1]['text'] ?? '';
        if ($open !== '(') {
            return false;
        }

        $previous = $tokens[$index - 1]['id'] ?? null;

        return !in_array(
            $previous,
            [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_STRING, T_AS, T_USE, null],
            true
        );
    }

    /**
     * Литералы внутри аргумента с указанным номером. Скобки и скобки-квадраты считаются вложенностью,
     * поэтому запятая внутри массива или вызова аргументом не считает границей аргумента.
     *
     * @param list<array{id: int, text: string, line: int, depth: int}> $tokens
     * @return list<array{string, int}>
     */
    private function argumentLiterals(array $tokens, int $nameIndex, int $wanted): array
    {
        $total = count($tokens);
        $open = $nameIndex + 1;
        $spans = [];
        $depth = 0;
        $start = $open + 1;

        for ($i = $open + 1; $i < $total; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    $spans[] = [$start, $i];

                    break;
                }

                $depth--;

                continue;
            }

            if ($text === ',' && $depth === 0) {
                $spans[] = [$start, $i];
                $start = $i + 1;
            }
        }

        if (!isset($spans[$wanted])) {
            return [];
        }

        return $this->literals($tokens, $spans[$wanted][0], $spans[$wanted][1]);
    }

    /**
     * Ключи из присваивания переменной $notice: литерал справа от «=» либо значения веток match
     * и массива (всё, что стоит сразу после «=>»). Присваивание внутри списка параметров
     * (значение по умолчанию) в расчёт не идёт: там нет передачи ключа.
     *
     * @param list<array{id: int, text: string, line: int, depth: int}> $tokens
     * @return list<array{string, int}>
     */
    private function noticeAssignmentKeys(array $tokens, int $varIndex): array
    {
        $total = count($tokens);

        if ($tokens[$varIndex]['depth'] !== 0) {
            return [];
        }

        // Знак присваивания токенизатор отдаёт одиночным символом: T_EQUAL в PHP нет,
        // а «==» и «===» — отдельные токены, поэтому сравнение сюда не попадает.
        if (($tokens[$varIndex + 1]['text'] ?? '') !== '=') {
            return [];
        }

        $start = $varIndex + 2;
        $end = $total;
        for ($i = $start; $i < $total; $i++) {
            if ($tokens[$i]['text'] === ';' && $tokens[$i]['depth'] === 0) {
                $end = $i;

                break;
            }
        }

        $out = [];

        // $notice = 'kluch'; — единственный литерал справа от «=».
        if (($tokens[$start]['id'] ?? -1) === T_CONSTANT_ENCAPSED_STRING) {
            $key = $this->unquote($tokens[$start]['text']);
            if ($key !== '') {
                $out[] = [$key, $tokens[$start]['line']];
            }
        }

        // $notice = match ($op) { 'install' => 'kluch', … } — литералы после «=>».
        for ($i = $start; $i < $end; $i++) {
            if (($tokens[$i]['id'] ?? -1) !== T_DOUBLE_ARROW) {
                continue;
            }

            $value = $tokens[$i + 1] ?? null;
            if ($value === null || $value['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $key = $this->unquote($value['text']);
            if ($key !== '') {
                $out[] = [$key, $value['line']];
            }
        }

        return $out;
    }

    /**
     * @param list<array{id: int, text: string, line: int, depth: int}> $tokens
     * @return list<array{string, int}>
     */
    private function literals(array $tokens, int $from, int $to): array
    {
        $out = [];

        for ($i = $from; $i < $to; $i++) {
            if ($tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $key = $this->unquote($tokens[$i]['text']);
            if ($key !== '') {
                $out[] = [$key, $tokens[$i]['line']];
            }
        }

        return $out;
    }

    private function unquote(string $text): string
    {
        $quote = substr($text, 0, 1);

        if ($quote !== "'" && $quote !== '"') {
            return $text;
        }

        return (string) substr($text, 1, -1);
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (['core', 'admin', 'theme', 'apps', 'public'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }

            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
