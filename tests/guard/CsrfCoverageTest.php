<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Порядок важнее факта: csrfCheck() в конце обработчика уже принял бы чужой POST — к этому моменту
// attemptLogin() успел бы записать попытку в tbl_login_attempt, а установщик — создать
// администратора. Поэтому страж требует, чтобы первая работа с базой шла после сверки токена.
//
// Прежняя версия промахивалась в трёх местах и краснела не там, где нужно:
// — str_contains() по телу обработчика не видела перестановки проверки после запроса;
// — маршруты читались регуляркой по двум файлам (второго не существует), поэтому обработчики-
//   замыкания из routes.php разделов и «прямые» POST-ветки public/*.php выпадали из обхода;
// — формы искали только в theme, admin и apps и сравнивали их количество с количеством полей на
//   весь файл: форма без токена в core/templates рядом с двумя полями оставалась зелёной.
final class CsrfCoverageTest extends TestCase
{
    private const CODE_DIRS = ['core', 'admin', 'theme', 'apps', 'public'];

    // Примитивы PDO-слоя. Функция, которая зовёт их напрямую или через цепочку помощников,
    // обращается к базе — поиск по телу обработчика идёт по всему замыкающему множеству.
    private const DB_PRIMITIVES = ['db', 'all', 'one', 'value', 'run', 'insert', 'tx'];

    public function testКаждыйPostОбработчикПроверяетТокенДоПервогоОбращенияКБазе(): void
    {
        $reaching = $this->dbReachingFunctions();
        $endpoints = $this->postEndpointSources();
        $violations = [];

        foreach ($endpoints as $label => $source) {
            foreach ($this->orderViolations($label, $source, $reaching) as $violation) {
                $violations[] = $violation;
            }
        }

        // Пустой список означал бы, что обход не нашёл ни одного входа, и проверка прошла бы
        // ничего не проверяя.
        self::assertNotSame([], $endpoints, 'Ни одного POST-эндпоинта не найдено: стражу не на чём работать');
        self::assertSame([], $violations, 'Найден POST-эндпоинт, где база вызывается до сверки токена');
    }

    public function testКаждаяPostФормаНесётПолеТокенаВнутриСебя(): void
    {
        $violations = [];
        $forms = 0;

        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            $relative = $this->relative($file);

            foreach ($this->formExtents($code) as $open) {
                $forms++;
                $close = strpos($code, '</form>', $open);
                if ($close === false) {
                    $violations[] = $relative . ': форма method="post" не закрыта — её границы не измерить';
                    continue;
                }

                $chunk = substr($code, $open, $close - $open);
                if (preg_match('/csrfField\s*\(\s*\)|name=\\\\?["\']_token["\']/', $chunk) !== 1) {
                    $violations[] = $relative . ', строка ' . (substr_count(substr($code, 0, $open), "\n") + 1)
                        . ': форма method="post" без поля токена внутри своих границ';
                }
            }
        }

        self::assertGreaterThan(0, $forms, 'Ни одной POST-формы не найдено: проверке не на чём работать');
        self::assertSame([], $violations, 'Каждая форма method="post" обязана нести csrfField() внутри себя');
    }

    // «Покрыт» значит «сверено поле формы с токеном сессии постоянным по времени сравнением».
    // Если проверка переписана на куку, GET-параметр или «==», обработчики формально содержат
    // csrfCheck(), но защита не работает — страж обязан увидеть это в самом механизме.
    public function testСамМеханизмПроверкиСмотритВФормуИСессию(): void
    {
        $definition = new \ReflectionFunction('csrfCheck');
        self::assertSame('core/csrf.php', $this->relative((string) $definition->getFileName()), 'Проверку токена определил другой файл');

        $body = $this->withoutComments($this->reflectedSource($definition));
        self::assertMatchesRegularExpression('/postString\(\s*\'_token\'\s*\)/', $body, 'Токен обязан приходить из поля формы');
        self::assertStringContainsString('$_SESSION', $body, 'Оригинал токена обязан браться из сессии');
        self::assertMatchesRegularExpression('/hash_equals\s*\(/', $body, 'Сравнение обязано быть постоянным по времени');

        $field = $this->withoutComments($this->reflectedSource(new \ReflectionFunction('csrfField')));
        self::assertMatchesRegularExpression('/name=\\\\?[\'"]_token[\'"]/', $field, 'Скрытое поле обязано называться _token');
    }

    // Собственная сила стража: образцы нарушают правило по-разному, и каждый обязан дать находку.
    // Без этого теста обход мог бы скатиться к пустому списку эндпоинтов — зелёному при любом коде.
    public function testСтражЛовитПерестановкуИПропускПроверки(): void
    {
        $reaching = $this->dbReachingFunctions();
        // Цепочка помощников обязана дотянуться до attemptLogin(): он не примитив, но читает и
        // пишет базу, а перестановка именно за его вызовом — самый частый обход на практике.
        self::assertArrayHasKey('attemptLogin', $reaching, 'Фиксация не дотянулась до помощника входа');

        $broken = [
            'проверка после прямого запроса' => "<?php \$rows = all('SELECT 1'); csrfCheck();",
            'проверка после запроса через помощника' => "<?php attemptLogin('a@b.c', 'x'); csrfCheck();",
            'обработчик вовсе без проверки' => "<?php insert('user', []); render('home');",
            'проверка обращена к другому механизму' => "<?php cookieTokenCheck(); one('SELECT 1');",
        ];

        foreach ($broken as $title => $body) {
            self::assertNotSame([], $this->orderViolations('probe', $body, $reaching), 'Страж пропустил нарушение: ' . $title);
        }

        $correct = [
            'порядок соблюдён' => "<?php csrfCheck(); \$r = attemptLogin('a@b.c', 'x');",
            'метод с тем же именем — не вызов базы' => "<?php \$s->all(); \$s->insert('x'); csrfCheck();",
        ];

        foreach ($correct as $title => $body) {
            self::assertSame([], $this->orderViolations('probe', $body, $reaching), 'Страж ложно сработал: ' . $title);
        }
    }

    /** @return array<string, string> подпись эндпоинта => исходник обработчика */
    private function postEndpointSources(): array
    {
        routesReset();
        registerCoreRoutes();
        registerAppRoutes();

        $out = [];
        foreach (routeTable() as $route) {
            if ((string) $route['method'] !== 'POST') {
                continue;
            }

            $source = $this->handlerSource($route['handler']);
            if ($source !== '') {
                $out['POST ' . (string) $route['pattern']] = $source;
            }
        }

        // Реестр — не единственный вход: страница установщика читает POST напрямую.
        foreach ($this->directPostBranches() as $label => $source) {
            $out[$label] = $source;
        }

        return $out;
    }

    private function handlerSource(string|\Closure $handler): string
    {
        if (is_string($handler) && !function_exists($handler)) {
            // Забытое имя называет страж обработчиков маршрутов — здесь не его зона.
            return '';
        }

        return $this->reflectedSource(new \ReflectionFunction($handler));
    }

    private function reflectedSource(\ReflectionFunction $ref): string
    {
        $file = $ref->getFileName();
        if ($file === false) {
            return '';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        $start = (int) $ref->getStartLine() - 1;
        $length = (int) $ref->getEndLine() - $start;

        return implode('', array_slice($lines, $start, $length));
    }

    /** @return array<string, string> подпись ветки => её исходник */
    private function directPostBranches(): array
    {
        $out = [];

        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            if (!str_contains($code, 'isPost')) {
                continue;
            }

            foreach ($this->postBranchBodies($code) as $index => $body) {
                $out[$this->relative($file) . ', ветка isPost() №' . ($index + 1)] = $body;
            }
        }

        return $out;
    }

    /**
     * Тела веток «if (... isPost() ...) { ... }»: условие и тело читаются балансом скобок по
     * токенам. Берётся именно тело, чтобы порядок считался по ветке, а не по файлу: иначе
     * csrfCheck() из соседней ветки закрывал бы нарушение.
     *
     * @return list<string>
     */
    private function postBranchBodies(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], [T_IF, T_ELSEIF], true)) {
                continue;
            }

            $branch = $this->ifBranch($tokens, $i);
            if ($branch !== null && str_contains($branch['condition'], 'isPost')) {
                $out[] = $branch['body'];
            }
        }

        return $out;
    }

    /**
     * Разбирает ветку, объявленную на позиции index: условие в круглых скобках и тело — в
     * фигурных либо одно операторное выражение до «;».
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{condition: string, body: string}|null
     */
    private function ifBranch(array $tokens, int $index): ?array
    {
        $count = count($tokens);

        $j = $this->skipWhitespace($tokens, $index + 1);
        if ($j >= $count || $this->text($tokens[$j]) !== '(') {
            return null;
        }

        $condition = $this->group($tokens, $j, '(', ')');
        if ($condition === null) {
            return null;
        }

        $after = $this->skipWhitespace($tokens, $this->groupEnd($tokens, $j, '(', ')'));
        if ($after >= $count) {
            return null;
        }

        if ($this->text($tokens[$after]) === '{') {
            $body = $this->group($tokens, $after, '{', '}');

            return $body === null ? null : ['condition' => $condition, 'body' => $body];
        }

        // Ветка без блока: одно выражение до точки с запятой.
        $body = '';
        for ($k = $after; $k < $count; $k++) {
            $char = $this->text($tokens[$k]);
            if ($char === ';') {
                return ['condition' => $condition, 'body' => $body];
            }

            $body .= $char;
        }

        return null;
    }

    /**
     * Текст группы, открывающейся на позиции index, включая обе скобки.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function group(array $tokens, int $index, string $open, string $close): ?string
    {
        $end = $this->groupEnd($tokens, $index, $open, $close);
        if ($end === $index) {
            return null;
        }

        $text = '';
        for ($j = $index; $j <= $end; $j++) {
            $text .= $this->text($tokens[$j]);
        }

        return $text;
    }

    /**
     * Позиция закрывающей скобки группы, открывающейся на index; если группа не закрылась —
     * возвращается сам index, что читатель трактует как отказ. Открывающих токенов у фигурной
     * скобки три: «{», «{$» и «${» — интерполируемая строка открывается двумя последними, а
     * закрывается обычным «}», поэтому без их учёта тело ветки оборвалось бы на первой же такой
     * строке.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function groupEnd(array $tokens, int $index, string $open, string $close): int
    {
        $count = count($tokens);
        $depth = 0;

        for ($j = $index; $j < $count; $j++) {
            $token = $tokens[$j];
            $char = $this->text($token);

            if ($open === '{' && is_array($token)
                && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $index;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function skipWhitespace(array $tokens, int $index): int
    {
        $count = count($tokens);
        while ($index < $count && is_array($tokens[$index]) && $tokens[$index][0] === T_WHITESPACE) {
            $index++;
        }

        return $index;
    }

    /**
     * @param array<string, true> $reaching
     * @return list<string>
     */
    private function orderViolations(string $label, string $body, array $reaching): array
    {
        $code = $this->withoutComments($this->stripDeclaration($body));
        $checkAt = $this->firstCallOffset($code, 'csrfCheck');

        if ($checkAt === null) {
            return [$label . ': нет сверки токена'];
        }

        $dbAt = null;
        $dbName = '';
        foreach ($reaching as $name => $flag) {
            $offset = $this->firstCallOffset($code, (string) $name);
            if ($offset !== null && ($dbAt === null || $offset < $dbAt)) {
                $dbAt = $offset;
                $dbName = (string) $name;
            }
        }

        if ($dbAt !== null && $dbAt < $checkAt) {
            return [$label . ': первое обращение к базе — ' . $dbName . '() — идёт до сверки токена'];
        }

        return [];
    }

    // Вызов глобальной функции: перед именем нет стрелки, двоеточия или буквы, иначе метод
    // $stmt->all() или чужое ::value() сошлись бы тем же шаблоном.
    private function firstCallOffset(string $code, string $name): ?int
    {
        if (preg_match('/(?<![\w>$:])' . preg_quote($name, '/') . '\s*\(/', $code, $match, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $match[0][1];
        }

        return null;
    }

    /** @return array<string, true> имена функций, чей вызов уходит в базу */
    private function dbReachingFunctions(): array
    {
        $bodies = $this->globalFunctionBodies();
        $reaching = array_fill_keys(self::DB_PRIMITIVES, true);

        // Один проход оставил бы цепочку attemptLogin() → loginBlocked() → value() незакрытой:
        // помощник помечается, только когда уже помечен тот, кого он зовёт.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($bodies as $name => $body) {
                if (isset($reaching[$name])) {
                    continue;
                }

                foreach (array_keys($reaching) as $known) {
                    if ($this->firstCallOffset($body, (string) $known) !== null) {
                        $reaching[$name] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return $reaching;
    }

    /**
     * Тело обработчика начинается с первой фигурной скобки: строка объявления function coreLoginSubmit(
     * совпала бы с собственным именем в списке обращающихся к базе, и порядок нарушался бы «сам
     * собой» ещё до первого вызова.
     */
    private function stripDeclaration(string $body): string
    {
        $open = strpos($body, '{');

        return $open === false ? $body : (string) substr($body, $open);
    }

    /** @return array<string, string> имя глобальной функции => её исходник без комментариев */
    private function globalFunctionBodies(): array
    {
        $out = [];

        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            foreach ($this->namedFunctionBodies($code) as $name => $body) {
                $out[$name] = $this->withoutComments($body);
            }
        }

        return $out;
    }

    /**
     * Глобальные функции файла. Методы пропускаются по глубине фигурных скобок: у объявления
     * функции верхнего уровня она нулевая, а внутри класса — уже положительная.
     *
     * @return array<string, string>
     */
    private function namedFunctionBodies(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        $count = count($tokens);
        $depth = 0;
        $pending = null;
        $bodyStart = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $char = $this->text($token);

            if ($pending === null && is_array($token) && $token[0] === T_FUNCTION && $depth === 0) {
                $pending = $this->functionName($tokens, $i);
                continue;
            }

            // «{$» и «${» открывают интерполяцию в двойной строке, а закрывается она обычным «}»:
            // без счёта обоих глубина съехала бы, и метод класса мог бы сойтись за функцию.
            if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
                continue;
            }

            if ($char === '{') {
                $depth++;
                if ($depth === 1 && is_string($pending)) {
                    $bodyStart = $i;
                }

                continue;
            }

            if ($char === '}') {
                if ($depth === 1 && is_string($pending)) {
                    $text = '';
                    for ($j = $bodyStart; $j <= $i; $j++) {
                        $text .= $this->text($tokens[$j]);
                    }

                    $out[$pending] = $text;
                    $pending = null;
                }

                $depth--;
            }
        }

        return $out;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function functionName(array $tokens, int $index): ?string
    {
        $count = count($tokens);

        for ($j = $index + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if (is_array($token) && $token[0] === T_STRING) {
                return (string) $token[1];
            }

            // У замыкания имя не следует за ключевым словом — оно начинается сразу со скобки.
            if ($this->text($token) === '(') {
                return null;
            }
        }

        return null;
    }

    /** @return list<int> позиции открывающих тегов POST-форм */
    private function formExtents(string $code): array
    {
        if (preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>/i', $code, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $out = [];
        foreach ($matches[0] as $match) {
            $out[] = (int) $match[1];
        }

        return $out;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (self::CODE_DIRS as $dir) {
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
        $normalized = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', dirname(__DIR__, 2));

        return str_starts_with($normalized, $root . '/')
            ? (string) substr($normalized, strlen($root) + 1)
            : $normalized;
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private function text(array|string $token): string
    {
        return is_array($token) ? (string) $token[1] : $token;
    }

    private function withoutComments(string $code): string
    {
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

        return (string) preg_replace('#//[^\n]*#', '', $code);
    }
}
