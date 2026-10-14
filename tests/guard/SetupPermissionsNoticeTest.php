<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

/**
 * Страж ветки «не удалось выставить права 600» (пакет M6-2c, коммит 3).
 *
 * Ветка поведенчески непроверяема на этом стенде: в контейнере `chmod()` успешен всегда, а на
 * присоединённом windows-томе проверка прав ничего не доказывает. Поэтому она закрепляется
 * разбором исходника — и это сознательная замена поведенческой проверки, а не её имитация:
 * страж ловит опечатку в ключе и случайное удаление ветки, но НЕ ловит неверный порядок
 * редиректа. Следующий читатель не должен принять этот класс за живую проверку.
 *
 * Файлы читаются по именам, а не по номерам строк: `M6-3` сдвинет разметку, и якорь по номеру
 * строки устарел бы в тот же день. Позиции свойств считаются по последовательности значимых
 * токенов (по `token_get_all()` с пропуском `T_COMMENT` и `T_DOC_COMMENT`): комментарий строкой
 * выше содержит тот же литерал, что и код, и наивный `strpos()` по сырому тексту нашёл бы его,
 * оставшись зелёным после удаления самого вызова.
 *
 * Парная проверка `tests/guard/NoticeKeyTest.php` оставляет за собой право ключа существовать в
 * словаре; этот класс проверяет, что ветка до неё доходит.
 */
final class SetupPermissionsNoticeTest extends TestCase
{
    /** Экран установщика: из него уходит редирект после удачной записи настроек. */
    private const SETUP_SCRIPT = 'public/setup.php';

    /** Чистая механика первого шага: в ней запись файла настроек и флаг смены прав. */
    private const CONFIG_CORE = 'core/setup-config.php';

    /** Ключ уведомления об отказе прав: текст сверяется со словарём, а не со строкой проверки. */
    private const NOTICE_KEY = 'config-permissions';

    /** Функция атомарной записи: её тело — окно разбора для свойства о порядке вызовов. */
    private const WRITE_FUNCTION = 'setupWriteConfig';

    public function testВеткаОтказаПравовВедётНаШагВторойСКлючомУведомления(): void
    {
        $tokens = $this->significantTokens($this->read(self::SETUP_SCRIPT));
        $calls = 0;
        /** @var list<string> первые аргументы вызовов, уводящих на экран с ключом отказа прав */
        $permissionRedirects = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_STRING || strtolower($token['text']) !== 'redirectwithnotice') {
                continue;
            }

            // Объявление функции, метод у объекта и статический вызов — не вызов помощника.
            if (in_array($tokens[$index - 1]['id'] ?? -1, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $arguments = $this->argumentTexts($tokens, $index);
            if ($arguments === []) {
                continue;
            }

            $calls++;

            if (($arguments[1] ?? '') === self::NOTICE_KEY) {
                $permissionRedirects[] = $arguments[0];
            }
        }

        // Ноль вызовов — сломанный разбор, а не «нарушений нет»: без этой строки проверка была
        // бы зелёной и при переезде помощника, и при пустом файле.
        self::assertNotSame(0, $calls, 'Ни одного вызова `redirectWithNotice()` в ' . self::SETUP_SCRIPT . ' разбор не нашёл — сломан страж, а не ветка');
        self::assertNotSame([], $permissionRedirects, 'Отказ по правам не ведёт на шаг 2 с ключом `' . self::NOTICE_KEY . '`: вызова `redirectWithNotice()` с этим ключом во втором аргументе в файле нет — оператор вернулся бы на экран без объяснения');

        $stray = array_values(array_filter(
            $permissionRedirects,
            static fn (string $to): bool => !str_contains($to, 'step=admin')
        ));
        self::assertSame([], $stray, 'Вызов с ключом `' . self::NOTICE_KEY . '` уводит не на шаг 2: в его первом аргументе нет `step=admin` — плашку предупреждения оператор не увидит');
    }

    public function testФлагСменыПравПроверяетсяИЗаполняетПредупреждение(): void
    {
        $tokens = $this->significantTokens($this->read(self::CONFIG_CORE));
        $flag = $this->chmodFlagName($tokens);
        $branch = $this->negatedBranchIndex($tokens, $flag);
        $body = $this->blockTokens($tokens, $branch);
        $warnings = $this->warningAssignments($body);

        self::assertSame(
            [$this->dictionaryNoticeText()],
            $warnings,
            'Текст предупреждения в ветке `!$' . $flag . '` разошёлся со словарём `CORE_NOTICE_TEXT` по ключу `' . self::NOTICE_KEY . '`: плашка на шаге 2 и журнал должны рассказывать оператору одно и то же'
        );
    }

    /**
     * Маска `0o077` обязана выставляться до записи временного файла: окно между созданием файла и
     * закрытием прав — это читаетемость файла с паролем базы другими локальными пользователями
     * виртуального хостинга.
     *
     * Проверка ловит перестановку вызовов и удаление `umask()`, но НЕ доказывает фактические права
     * файла на присоединённом томе: `bind`-монтаж windows-тома режим не отражает, поэтому права
     * смотрит поведенческая проверка в `/tmp` (`tests/unit/SetupConfigTest.php`), а порядок
     * вызовов закрепляется здесь.
     */
    public function testМаскаВыставляетсяДоЗаписиВременногоФайла(): void
    {
        $body = $this->functionBody($this->significantTokens($this->read(self::CONFIG_CORE)), self::WRITE_FUNCTION);

        $mask = $this->sequenceIndex($body, ['umask', '(', '0o077', ')'], 'Вызов маски `umask(0o077)`');
        $write = $this->sequenceIndex($body, ['file_put_contents', '('], 'Первый вызов `file_put_contents()`');

        self::assertLessThan(
            $write,
            $mask,
            'Маска `0o077` выставляется после записи временного файла: файл настроек создаётся до того, как окружение процесса закрыто, и в этом окне он читаем другими пользователями'
        );
    }

    /**
     * Имя переменной, в которую сохранён результат `chmod()`: присваивание вида
     * `$флаг = @chmod($tmp, 0600);`. Без флага ветке предупреждения не о чём спрашивать.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     */
    private function chmodFlagName(array $tokens): string
    {
        $total = count($tokens);

        for ($i = 1; $i < $total; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE || ($tokens[$i + 1]['text'] ?? '') !== '=') {
                continue;
            }

            $call = $i + 2;
            if (($tokens[$call]['text'] ?? '') === '@') {
                $call++;
            }

            if (strtolower($tokens[$call]['text'] ?? '') !== 'chmod' || ($tokens[$call + 1]['text'] ?? '') !== '(') {
                continue;
            }

            return substr($tokens[$i]['text'], 1);
        }

        self::fail('В ' . self::CONFIG_CORE . ' результат `chmod()` не сохранён в переменную: флаг смены прав потерян, и ветка предупреждения не о чём спрашивать');
    }

    /**
     * Индекс `{` ветки `if (!$флаг)`: условие обязано спрашивать ровно тот флаг, который дала
     * запись прав, а не произвольную переменную.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     */
    private function negatedBranchIndex(array $tokens, string $flag): int
    {
        $total = count($tokens);
        $condition = ['(', '!', '$' . $flag, ')', '{'];

        for ($i = 0; $i < $total; $i++) {
            if ($tokens[$i]['id'] !== T_IF) {
                continue;
            }

            foreach ($condition as $offset => $text) {
                if (($tokens[$i + 1 + $offset]['text'] ?? '') !== $text) {
                    continue 2;
                }
            }

            return $i + 5;
        }

        self::fail('В ' . self::CONFIG_CORE . ' нет ветки `if (!$' . $flag . ')`: предупреждение о правах стало бы безусловным либо исчезло вместе с условием');
    }

    /**
     * Токены тела блока между `{` с указанным индексом и парной закрывающей. Разбалан — падение
     * проверки с именем файла, а не молчаливый хвост до конца исходника: тихое самоискажение
     * стража недопустимо.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     * @return list<array{id: int, text: string, line: int}>
     */
    private function blockTokens(array $tokens, int $openIndex): array
    {
        $total = count($tokens);
        $body = [];
        $depth = 0;

        for ($i = $openIndex; $i < $total; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '{') {
                $depth++;
                if ($depth > 1) {
                    $body[] = $tokens[$i];
                }

                continue;
            }

            if ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }

                $body[] = $tokens[$i];
                continue;
            }

            if ($depth > 0) {
                $body[] = $tokens[$i];
            }
        }

        self::fail('В ' . self::CONFIG_CORE . ' тело блока не закрыто до конца исходника: разбор тела невозможен, а зелёный прогон здесь означал бы молчаливый отказ стража');
    }

    /**
     * Присваивания `$result['warning']` внутри тела ветки.
     *
     * @param list<array{id: int, text: string, line: int}> $body
     * @return list<string>
     */
    private function warningAssignments(array $body): array
    {
        $total = count($body);
        $out = [];

        for ($i = 0; $i < $total; $i++) {
            if ($body[$i]['id'] !== T_VARIABLE || $body[$i]['text'] !== '$result') {
                continue;
            }

            if (($body[$i + 1]['text'] ?? '') !== '[') {
                continue;
            }

            $key = $body[$i + 2] ?? null;
            if ($key === null || $key['id'] !== T_CONSTANT_ENCAPSED_STRING || $this->unquote($key['text']) !== 'warning') {
                continue;
            }

            if (($body[$i + 3]['text'] ?? '') !== ']' || ($body[$i + 4]['text'] ?? '') !== '=') {
                continue;
            }

            $value = $body[$i + 5] ?? null;
            if ($value === null || $value['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $out[] = $this->unquote($value['text']);
        }

        self::assertNotSame([], $out, 'В теле ветки отказа прав нет ни одного присваивания `$result[\'warning\']`: предупреждение не заполняется, и оператор не узнает про открытые права');

        return $out;
    }

    /**
     * Текст предупреждения из словаря `CORE_NOTICE_TEXT` по ключу отказа прав. Сверка по словарю,
     * а не по строке, вписанной в проверку буквально: иначе тексты разъедутся, а страж смолчит.
     */
    private function dictionaryNoticeText(): string
    {
        self::assertArrayHasKey(self::NOTICE_KEY, CORE_NOTICE_TEXT, 'Ключ `' . self::NOTICE_KEY . '` пропал из словаря `CORE_NOTICE_TEXT` — сверять предупреждение не с чем');

        return (string) CORE_NOTICE_TEXT[self::NOTICE_KEY];
    }

    /**
     * Тело функции по её имени: окно разбора нужно, чтобы позиция маски сравнивалась с позицией
     * записи внутри одной функции, а не где-то в другом месте файла. Первая `{` после имени —
     * открывающая тело: в списке параметров этой функции фигурных скобок нет.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     * @return list<array{id: int, text: string, line: int}>
     */
    private function functionBody(array $tokens, string $name): array
    {
        $total = count($tokens);

        for ($i = 1; $i < $total; $i++) {
            if (($tokens[$i - 1]['id'] ?? -1) !== T_FUNCTION || $tokens[$i]['text'] !== $name) {
                continue;
            }

            for ($j = $i + 1; $j < $total; $j++) {
                if ($tokens[$j]['text'] === '{') {
                    return $this->blockTokens($tokens, $j);
                }
            }

            break;
        }

        self::fail('Функция `' . $name . '` не найдена в ' . self::CONFIG_CORE . ' — окно разбора потеряно, и свойство о порядке вызовов больше не закреплено ничем');
    }

    /**
     * Индекс начала первой последовательности значимых токенов в окне. Отсутствующий фрагмент —
     * падение проверки, а не `false`: последний в сравнении с целым числом прошёл бы молча, и
     * «удалить вызов» стало бы зелёным прогоном.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     * @param list<string> $sequence
     */
    private function sequenceIndex(array $tokens, array $sequence, string $label): int
    {
        $total = count($tokens);
        $length = count($sequence);

        for ($i = 0; $i + $length <= $total; $i++) {
            $matched = true;

            for ($offset = 0; $offset < $length; $offset++) {
                if (($tokens[$i + $offset]['text'] ?? '') !== $sequence[$offset]) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return $i;
            }
        }

        self::fail($label . ' не найден в теле функции `' . self::WRITE_FUNCTION . '` файла ' . self::CONFIG_CORE . ': свойство записано в комментарий, а не в код');
    }

    /**
     * Тексты аргументов вызова: строковые литералы одного аргумента склеиваются (конкатенация —
     * это один аргумент), а содержимое вложенных вызовов и массивов в расчёт не идёт: глубина
     * круглых и квадратных скобок отличает запятую аргумента от запятой внутри вложенности.
     * Пустой возврат — признака вызова в этом месте нет.
     *
     * @param list<array{id: int, text: string, line: int}> $tokens
     * @return list<string>
     */
    private function argumentTexts(array $tokens, int $nameIndex): array
    {
        $total = count($tokens);
        if (($tokens[$nameIndex + 1]['text'] ?? '') !== '(') {
            return [];
        }

        $out = [];
        $current = '';
        $depth = 0;

        for ($i = $nameIndex + 2; $i < $total; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '(' || $text === '[') {
                $depth++;
                continue;
            }

            if ($text === ')' || $text === ']') {
                if ($depth === 0) {
                    $out[] = $current;

                    return $out;
                }

                $depth--;
                continue;
            }

            if ($text === ',' && $depth === 0) {
                $out[] = $current;
                $current = '';
                continue;
            }

            if ($depth === 0 && $tokens[$i]['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $current .= $this->unquote($text);
            }
        }

        // Скобка вызова не закрыта: аргументы недочитаны, и разбор обязан покраснеть на пустом
        // втором аргументе, а не сделать вид, что проверка прошла.
        return $out;
    }

    /**
     * Значимые токены исходника: пробелы и оба вида комментариев сняты, поэтому позиция в списке
     * не зависит от того, встанет ли над строкой кода новый комментарий.
     *
     * @return list<array{id: int, text: string, line: int}>
     */
    private function significantTokens(string $code): array
    {
        $out = [];

        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $out[] = ['id' => -1, 'text' => $token, 'line' => 0];
                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
        }

        self::assertNotSame([], $out, 'Разбор исходника не дал ни одного значимого токена — страж читает пустоту');

        return $out;
    }

    /** Кавычки с литерала, стоящего в токене целиком, снимаются вместе. */
    private function unquote(string $text): string
    {
        $quote = substr($text, 0, 1);

        if ($quote !== "'" && $quote !== '"') {
            return $text;
        }

        return (string) substr($text, 1, -1);
    }

    /** Содержимое файла репозитория по относительному имени; отсутствие файла — падение, а не пустой разбор. */
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertTrue(is_file($path), 'Файл ' . $relative . ' не найден по имени — страж разбирает ноль исходников и молчит');

        return (string) file_get_contents($path);
    }
}
