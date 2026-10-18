<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\AssertionFailedError;
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
// Пакет #290б закрыл четвёртую слепую зону: ветку «если метод — пост», написанную через
// `requestMethod()`, а не через `isPost()`, не видел ни предфильтр файла, ни распознавание условия.
// Живых нарушений эта зона не скрывает: вне ядра `requestMethod()` не вызывает ни один обработчик,
// а в `core/router.php:106` он читается в переменную при разборе таблицы маршрутов, не как ветка.
// Правка убирает латентную зону, а не чинит сломанное место.
//
// Пункт М284-Г2-5 закрыл пятую слепую зону: `postBranchBodies()` собирает тела только для `if` и
// `elseif`, поэтому пост-обработчик через `switch`, `match` или прямое чтение
// `$_SERVER['REQUEST_METHOD']` выпадал из обхода целиком и молча. Такую форму не разбирают, а
// краснят: страж обязан различать «нарушений нет» и «нарушение стоит там, где обход не видит».
final class CsrfCoverageTest extends TestCase
{
    private const CODE_DIRS = ['core', 'admin', 'theme', 'apps', 'public'];

    // Единственное законное место прямого чтения `$_SERVER['REQUEST_METHOD']` — определитель метода
    // (пункт М284-Г2-5): вне него форма не читается обходом тел веток, а значит краснится.
    private const REQUEST_METHOD_HOME = 'core/request.php';

    // Примитивы PDO-слоя. Функция, которая зовёт их напрямую или через цепочку помощников,
    // обращается к базе — поиск по телу обработчика идёт по всему замыкающему множеству.
    private const DB_PRIMITIVES = ['db', 'all', 'one', 'value', 'run', 'insert', 'tx'];

    public function testКаждыйPostОбработчикПроверяетТокенДоПервогоОбращенияКБазе(): void
    {
        // Пункт М284-Г2-5: слепая зона распознавания называется первым делом и отдельно от порядка
        // проверок, чтобы падение различало «нарушений нет» и «нарушение стоит в ветке, которую
        // обход тел не читает».
        self::assertSame(
            [],
            $this->unreadablePostBranches(),
            'Найдена пост-ветка в форме, которую обход тел веток не читает: сверка порядка токенов по такому файлу ничего не стоит'
        );

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

    // Пункт М284-Г2-5: пост-ветка, объявленная не через `if`/`elseif`, из обхода тел выпадает
    // целиком. Разбирать гипотетическую форму не решено, а решено краснить на ней, поэтому фикстуры
    // живут здесь же, строками: каждая обязан дать ровно одну находку, названную меткой и строкой.
    public function testПостВеткаЧерезСwitchИлиMatchКраснит(): void
    {
        $switch = "<?php switch (requestMethod()) { case 'POST': attemptLogin('a@b.c', 'x'); csrfCheck(); break; }";
        $match = "<?php match (isPost()) { true => attemptLogin('a@b.c', 'x'), false => null };";
        $server = "<?php \$m = \$_SERVER['REQUEST_METHOD']; if (\$m === 'POST') { attemptLogin('a@b.c', 'x'); csrfCheck(); }";
        $readable = "<?php if (isPost()) { attemptLogin('a@b.c', 'x'); csrfCheck(); }";
        $noPostMarker = "<?php switch (\$page) { case 2: \$rows = all('SELECT 1'); break; }";

        $fromSwitch = $this->unreadableFormsIn($switch, 'probe.php');
        self::assertSame(1, count($fromSwitch), 'Пост-ветка через `switch (requestMethod())` не названа ровно одной находкой');
        self::assertStringContainsString('probe.php, строка 1:', $fromSwitch[0] ?? '', 'Находка `switch` не названа файлом и номером строки');
        self::assertStringContainsString('switch', $fromSwitch[0] ?? '', 'Находка `switch` не названа самой формой');

        $fromMatch = $this->unreadableFormsIn($match, 'probe.php');
        self::assertSame(1, count($fromMatch), 'Пост-ветка через `match` с признаком поста не названа ровно одной находкой');
        self::assertStringContainsString('match', $fromMatch[0] ?? '', 'Находка `match` не названа самой формой');

        $fromServer = $this->unreadableFormsIn($server, 'probe.php');
        self::assertSame(1, count($fromServer), 'Прямое чтение `$_SERVER[\'REQUEST_METHOD\']` вне определителя метода не названо ровно одной находкой');
        self::assertStringContainsString('REQUEST_METHOD', $fromServer[0] ?? '', 'Находка прямого чтения не названа формой');

        // Пункт Г2Ф-1 (Н-3): номер строки в находке обязан быть номером строки исходника. Живой
        // обход подаёт в разбор текст после `withoutComments()`, поэтому фикстура идёт тем же путём:
        // если блочный комментарий вырезается вместе со своими переводами строк, `switch` под ним
        // уезжает на строку выше, и maintainer по красному прогону идёт не туда, где стоит ветка.
        $docblock = "<?php\n/**\n * Пояснение\n * ещё строка\n */\nswitch (requestMethod()) { case 'POST': attemptLogin('a@b.c', 'x'); csrfCheck(); break; }\n";
        $fromDocblock = $this->unreadableFormsIn($this->withoutComments($docblock), 'probe.php');
        self::assertSame(
            1,
            count($fromDocblock),
            'Пост-ветка через `switch` под многострочным докблоком не названа ровно одной находкой'
        );
        self::assertStringContainsString(
            'probe.php, строка 6:',
            $fromDocblock[0] ?? '',
            'Находка называет строку текста без комментариев, а не строку исходника: адрес в сообщении'
            . ' обязан указывать на ту строку файла, где пост-ветка стоит на самом деле'
        );

        // Пункт Г2Ф-2 (Н-7): условие, которое разбор не смог прочитать, краснит, а не молчит —
        // зелёный прогон на неразобранном коде правило проекта запрещает, и опираться на шаг
        // `php -l` ворот нельзя. Ветка «условие разобрано, но признака поста в нём нет» продолжает
        // молчать: она разбирается, и ложное падение на ней стоило бы красным всем дереву.
        $brokenSwitch = "<?php switch (requestMethod() { case 'POST': break; }";
        $brokenMatch = "<?php match (isPost() { true => attemptLogin('a@b.c', 'x'), false => null };";

        $switchFailure = $this->unreadableFormsFailure($brokenSwitch, 'probe.php');
        self::assertNotNull(
            $switchFailure,
            'Неразобранное условие `switch` прошло молча: форма признака поста не сверена, а прогон зелёный'
        );
        self::assertStringContainsString(
            'не разобрано',
            (string) $switchFailure,
            'Падение неразобранного условия `switch` не называет причину словами «не разобрано»'
        );
        self::assertStringContainsString(
            'probe.php',
            (string) $switchFailure,
            'Падение неразобранного условия `switch` не называет файла, в котором стоит ветка'
        );
        self::assertStringContainsString(
            'строке 1',
            (string) $switchFailure,
            'Падение неразобранного условия `switch` не называет строки, на которой стоит ветка'
        );

        $matchFailure = $this->unreadableFormsFailure($brokenMatch, 'probe.php');
        self::assertNotNull(
            $matchFailure,
            'Неразобранное условие `match` прошло молча: та же слепая зона, что и у `switch`'
        );
        self::assertStringContainsString(
            'не разобрано',
            (string) $matchFailure,
            'Падение неразобранного условия `match` не называет причину словами «не разобрано»'
        );
        self::assertStringContainsString(
            'match',
            (string) $matchFailure,
            'Падение неразобранного условия `match` не называет формы этой ветки'
        );

        // Контроль на норме: разбираемая ветка `if` и `switch` без признака поста краснить не должны,
        // иначе проверка превращается в ложное падение на всём дереве.
        self::assertSame([], $this->unreadableFormsIn($readable, 'probe.php'), 'Разбор покраснел на разбираемой ветке `if (isPost())`');
        self::assertSame([], $this->unreadableFormsIn($noPostMarker, 'probe.php'), 'Разбор покраснел на `switch` без признака пост-запроса');

        // Пункт Г2Ф-5 (Н-6): форма через посредника — сознательная граница распознавания, а не
        // пропуск. Ожидание ниже закрепляет решение: молчание без ожидания читатель принимает за
        // слепую зону, а расширять границу на чтение переменной нечем — в дереве таких форм нет.
        $mediator = "<?php \$m = requestMethod(); switch (\$m) { case 'POST': attemptLogin('a@b.c', 'x'); csrfCheck(); break; }";
        self::assertSame(
            [],
            $this->unreadableFormsIn($mediator, 'probe.php'),
            'Форма через посредника (`$m = requestMethod()` и `switch ($m)`) краснит: распознавание'
            . ' условия по имени помощника — сознательная граница, и сдвигать её можно только вместе с этим ожиданием'
        );

        // Белый список прямых обращений ровно один и ровно этот файл: тот же текст вне его краснит,
        // а в самом определителе метода он легален — иначе страж краснил бы собственную норму.
        self::assertSame(
            [],
            $this->unreadableFormsIn($server, self::REQUEST_METHOD_HOME),
            'Определитель метода краснится собственным литералом — белый список не работает'
        );
        self::assertSame(
            1,
            count($this->unreadableFormsIn($server, 'admin/probe.php')),
            'Тот же литерал в чужом файле не краснится — белый список шире одного файла'
        );
    }

    public function testКаждаяPostФормаНесётПолеТокенаВнутриСебя(): void
    {
        $violations = [];
        $forms = 0;

        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);
            $scan = $this->formsScan($code, $this->relative($file));
            $forms += $scan['forms'];

            foreach ($scan['violations'] as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertGreaterThan(0, $forms, 'Ни одной POST-формы не найдено: проверке не на чём работать');
        self::assertSame([], $violations, 'Каждая форма method="post" обязана нести csrfField() внутри себя');
    }

    // Отдельная проверка на множественные формы: экран «Приложения» собирает две формы в одном
    // файле, и ровно этот случай страж пропускал, требуя «в файле ровно одно совпадение».
    public function testСтражПроверяетКаждуюФормуЕслиИхВФайлеНесколько(): void
    {
        $both = <<<'PHP'
<?php
return '<form method="post">' . csrfField() . '</form>'
    . '<form method="post">' . csrfField() . '</form>';
PHP;

        $secondWithoutToken = <<<'PHP'
<?php
return '<form method="post">' . csrfField() . '</form>'
    . '<form method="post"><input name="title"></form>';
PHP;

        $firstWithoutToken = <<<'PHP'
<?php
return '<form method="post"><input name="title"></form>'
    . '<form method="post">' . csrfField() . '</form>';
PHP;

        $unclosed = <<<'PHP'
<?php
return '<form method="post">' . csrfField() . '</form><form method="post">';
PHP;

        self::assertSame(2, $this->formsScan($both)['forms'], 'Обход не увидел обе формы в одном файле');
        self::assertSame([], $this->formsScan($both)['violations'], 'Страж ложно сработал на корректные формы');
        self::assertSame(
            ['probe.php, строка 3: форма method="post" без поля токена внутри своих границ'],
            $this->formsScan($secondWithoutToken)['violations'],
            'Вторая форма в том же файле не найдена или названа без номера строки'
        );
        self::assertSame(
            ['probe.php, строка 2: форма method="post" без поля токена внутри своих границ'],
            $this->formsScan($firstWithoutToken)['violations'],
            'Первая форма в файле с несколькими формами не найдена или названа без номера строки'
        );
        self::assertSame(
            ['probe.php, строка 2: форма method="post" не закрыта — её границы не измерить'],
            $this->formsScan($unclosed, 'probe.php')['violations'],
            'Незакрытая вторая форма не найдена или названа без номера строки'
        );

        // Разметка «</FORM>» и «</form >» равноправна «</form>»: поиск подстрокой считал бы
        // такую форму незакрытой и краснил ворота на корректном коде.
        $closedUpper = <<<'PHP'
<?php
return '<form method="post">' . csrfField() . '</FORM>'
    . '<form method="post"> <input name="op" value="enable"></form >';
PHP;

        self::assertSame(
            ['probe.php, строка 3: форма method="post" без поля токена внутри своих границ'],
            $this->formsScan($closedUpper)['violations'],
            'Форма с закрывающим тегом в другом регистре посчитана незакрытой'
        );
    }

    // Незакавыченное значение метода — корректная разметка, и прежний шаблон форм не считал её
    // вовсе: форма `method=post` без поля токена оставалась зелёной. Пара проб ниже закрепляет оба
    // конца, а третья проба проверяет границу значения: шаблон не имеет права ловить `postfix`.
    public function testФормаБезКавычекВЗначенииМетодаУчтена(): void
    {
        $withoutToken = <<<'PHP'
<?php
return '<form method=post><input name="title"></form>';
PHP;

        $withToken = <<<'PHP'
<?php
return '<form method=post>' . csrfField() . '</form>';
PHP;

        $suffixed = <<<'PHP'
<?php
return '<form method=postfix><input name="title"></form>';
PHP;

        self::assertSame(
            1,
            $this->formsScan($withoutToken)['forms'],
            'Незакавыченная форма без поля токена не посчитана: обход её не видит'
        );
        self::assertSame(
            ['probe.php, строка 2: форма method="post" без поля токена внутри своих границ'],
            $this->formsScan($withoutToken)['violations'],
            'Незакавыченная форма без поля токена не названа находкой'
        );

        self::assertSame(
            1,
            $this->formsScan($withToken)['forms'],
            'Незакавыченная форма с полем токена не посчитана'
        );
        self::assertSame(
            [],
            $this->formsScan($withToken)['violations'],
            'Незакавыченная форма с полем токена покраснела ложно'
        );

        self::assertSame(
            0,
            $this->formsScan($suffixed)['forms'],
            'Шаблон схватил значение `postfix`: граница значения не обязательна'
        );
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
            'проверка после запроса в ветке помощника метода' => "<?php if (requestMethod() === 'POST') { attemptLogin('a@b.c', 'x'); csrfCheck(); } ?>",
        ];

        foreach ($broken as $title => $body) {
            self::assertNotSame([], $this->orderViolations('probe', $body, $reaching), 'Страж пропустил нарушение: ' . $title);
        }

        $correct = [
            'порядок соблюдён' => "<?php csrfCheck(); \$r = attemptLogin('a@b.c', 'x');",
            'метод с тем же именем — не вызов базы' => "<?php \$s->all(); \$s->insert('x'); csrfCheck();",
            'чтение базы в ветке гета после сверки токена' => "<?php if (requestMethod() === 'GET') { csrfCheck(); all('SELECT 1'); } ?>",
        ];

        foreach ($correct as $title => $body) {
            self::assertSame([], $this->orderViolations('probe', $body, $reaching), 'Страж ложно сработал: ' . $title);
        }
    }

    // Распознавание ветки — тоже сила стража, и оно проверяется полным путём обхода: предфильтр по
    // файлу, разбор условия и сверка порядка в теле ветки. Прежний предфильтр знал одно слово
    // `isPost`, поэтому обработчик «если метод — пост», написанный через `requestMethod()`, выпадал
    // из проверки молча: латентная слепая зона, а не сломанное место.
    public function testВеткаПомощникаМетодаНеВыпадаетИзОбхода(): void
    {
        $reaching = $this->dbReachingFunctions();

        $postBranch = "<?php\nif (requestMethod() === 'POST') {\n    attemptLogin('a@b.c', 'x');\n    csrfCheck();\n}\n";
        $isPostBranch = "<?php\nif (isPost()) {\n    attemptLogin('a@b.c', 'x');\n    csrfCheck();\n}\n";
        // Файл упоминает POST в поле формы, поэтому предфильтр его пускает: отбраковать ветку гета
        // обязано условие самой ветки, а не файл.
        $getBranch = "<?php\n\$token = \$_POST['_token'] ?? '';\nif (requestMethod() === 'GET') {\n    attemptLogin('a@b.c', 'x');\n}\n";

        self::assertNotSame(
            [],
            $this->branchOrderViolations($postBranch, 'probe.php', $reaching),
            'Страж пропустил нарушение: ветка `requestMethod() === \'POST\'` не дошла до сверки порядка'
        );

        self::assertCount(
            1,
            $this->directPostBranchesIn($postBranch, 'probe.php'),
            'Ветка через `requestMethod()` не распознана либо распознана дважды: обход читает файл неверно'
        );

        self::assertCount(
            1,
            $this->directPostBranchesIn($isPostBranch, 'probe.php'),
            'Распознавание по `isPost()` сломано: ветка живого помощника метода выпала из обхода'
        );

        self::assertSame(
            [],
            $this->directPostBranchesIn($getBranch, 'probe.php'),
            'Ветка «метод — гет» посчитана пост-обработчиком: страж краснеет ложно на чтении базы вне поста'
        );
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
            // Забытое имя называет страж обработчиков маршрутов: RouteHandlersTest краснеет,
            // если обработчик маршрута не существует, — здесь не его зона.
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
            foreach ($this->directPostBranchesIn((string) file_get_contents($file), $this->relative($file)) as $label => $body) {
                $out[$label] = $body;
            }
        }

        return $out;
    }

    /**
     * Прямые пост-ветки одного исходника. Выделено отдельно, чтобы проба-нарушение шла тем же
     * путём, что и живой обработчик: предфильтр по файлу, распознавание ветки и только потом
     * сверка порядка. Прежний предфильтр знал одно слово `isPost`, поэтому обработчик «если метод
     * — пост», написанный через готовый помощник `requestMethod()`, выпадал из обхода целиком.
     *
     * Сырой текст здесь достаточен сознательно (пункт Г2Ф-6, находка Н-8): разбор веток ищет токены
     * `T_IF` и `T_ELSEIF`, которых комментарий не даёт вовсе, а комментарии из тела ветки выключает
     * `withoutComments()` на шаге сверки порядка — отличие от соседа `unreadablePostBranches()` стоит
     * ровно одного лишнего разбора закомментированной ветки. Номера строк этот обход не вычисляет:
     * метки веток порядковые, поэтому перевод адресов, важный для пункта Г2Ф-1, сюда не распространяется.
     *
     * @return array<string, string> подпись ветки => её исходник
     */
    private function directPostBranchesIn(string $code, string $fileLabel): array
    {
        if (!$this->mentionsDirectPost($code)) {
            return [];
        }

        $out = [];
        foreach ($this->postBranchBodies($code) as $index => $body) {
            $out[$fileLabel . ', ветка прямого пост-запроса №' . ($index + 1)] = $body;
        }

        return $out;
    }

    /**
     * Признак прямого пост-запроса в тексте: готовый помощник `isPost()` либо обращение к
     * `requestMethod()` при обязательной литере `POST`. Литера нужна, чтобы ветка «если метод —
     * гет» не сошлась пост-обработчиком: иначе страж покраснел бы ложно на всём дереве.
     */
    private function mentionsDirectPost(string $text): bool
    {
        return str_contains($text, 'isPost')
            || (str_contains($text, 'requestMethod') && str_contains($text, 'POST'));
    }

    /**
     * Нарушения порядка по пост-веткам одного исходника — тот же путь обхода, что у живого дерева.
     *
     * @param array<string, true> $reaching
     * @return list<string>
     */
    private function branchOrderViolations(string $code, string $fileLabel, array $reaching): array
    {
        $out = [];

        foreach ($this->directPostBranchesIn($code, $fileLabel) as $label => $body) {
            foreach ($this->orderViolations($label, $body, $reaching) as $violation) {
                $out[] = $violation;
            }
        }

        return $out;
    }

    /**
     * Тела веток прямого пост-запроса: условие читает `isPost()` либо `requestMethod()` с литерой
     * `POST`. Условие и тело читаются балансом скобок по токенам. Берётся именно тело, чтобы
     * порядок считался по ветке, а не по файлу: иначе csrfCheck() из соседней ветки закрывал бы
     * нарушение.
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
            if ($branch !== null && $this->mentionsDirectPost($branch['condition'])) {
                $out[] = $branch['body'];
            }
        }

        return $out;
    }

    /**
     * Пост-ветки в формах, которые `postBranchBodies()` не читает (пункт М284-Г2-5): `switch` или
     * `match`, условие которых несёт признак поста, и прямое чтение `$_SERVER['REQUEST_METHOD']`
     * вне определителя метода. Обход опирается на `phpFiles()`, и порог непустоты отдаёт он же:
     * пустой список каталогов краснит, а не превращает проверку в «нечего проверять».
     *
     * @return list<string>
     */
    private function unreadablePostBranches(): array
    {
        $out = [];

        foreach ($this->phpFiles() as $file) {
            $label = $this->relative($file);
            $code = $this->withoutComments((string) file_get_contents($file));

            foreach ($this->unreadableFormsIn($code, $label) as $violation) {
                $out[] = $violation;
            }
        }

        return $out;
    }

    /**
     * Неразбираемые пост-формы одного исходника. Вынесено отдельно, чтобы замеры шли тем же путём,
     * что и живой обход дерева: разбор вызывается и по файлу, и по строковой фикстуре. Текст
     * обязан быть без комментариев — закомментированная форма живой не считается.
     *
     * `switch` и `match` ищутся под тем же предфильтром файла, что и живой обход веток, а прямое
     * чтение `$_SERVER['REQUEST_METHOD']` ищется всегда: такой файл может не содержать ни `isPost`,
     * ни `requestMethod` вовсе, и предфильтр не имел права превращать эту форму в молчание.
     * Белый список у него ровно один и берётся из метки: `core/request.php` — определитель метода,
     * единственный файл, где прямое чтение легально.
     *
     * Условие, которое `conditionText()` не прочитал, краснит падением (пункт Г2Ф-2), а форма через
     * посредника (`$m = requestMethod()`, затем `switch ($m)`) находки не даёт осознанно: признак
     * поста сверяется по имени помощника в самом условии, а чтение переменной потребовало бы
     * анализа потока данных. Границу сдвигать не решено — в дереве таких форм нет, и закреплено это
     * ожиданием в `testПостВеткаЧерезСwitchИлиMatchКраснит()`.
     *
     * @return list<string>
     */
    private function unreadableFormsIn(string $code, string $label): array
    {
        $out = [];

        if ($this->mentionsDirectPost($code)) {
            $tokens = token_get_all($code);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token) || !in_array($token[0], [T_SWITCH, T_MATCH], true)) {
                    continue;
                }

                $condition = $this->conditionText($tokens, $i);
                if ($condition === null) {
                    self::fail(
                        'Условие `' . ($token[0] === T_SWITCH ? 'switch' : 'match') . '` на строке ' . (int) $token[2]
                            . ' файла `' . $label . '` не разобрано: форма признака поста не сверена, а молчать'
                            . ' здесь означало бы зелёный прогон на неразобранном коде'
                    );
                }
                if (!$this->mentionsMethodReader($condition)) {
                    // Условие разобрано, но признака метода запроса в нём нет: это не слепая зона,
                    // а корректная форма (`switch ($page)`), и краснить её значит дать ложное падение.
                    continue;
                }

                $out[] = $this->unreadableViolation($label, (int) $token[2], $token[0] === T_SWITCH ? 'switch' : 'match');
            }
        }

        if ($label === self::REQUEST_METHOD_HOME) {
            return $out;
        }

        foreach ($this->serverLiteralLines($code) as $line) {
            $out[] = $this->unreadableViolation($label, $line, "\$_SERVER['REQUEST_METHOD']");
        }

        return $out;
    }

    /**
     * Сообщение падения разбора по одной фикстуре: пустой результат означает, что разбор промолчал.
     * Нужен ровно для того, чтобы сверить текст падения словами теста, а не уронить весь прогон
     * (пункт Г2Ф-2).
     */
    private function unreadableFormsFailure(string $code, string $label): ?string
    {
        try {
            $this->unreadableFormsIn($code, $label);
        } catch (AssertionFailedError $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * Признак того, что условие `switch` или `match` читает метод запроса. Отличается от
     * `mentionsDirectPost()`: у разбираемой ветки `if` литера `POST` стоит в самом условии, а у
     * формы `switch` она уезжает в `case`, у `match` — в ветку сопоставления, поэтому требовать её
     * в условии означало бы не находить ничего.
     */
    private function mentionsMethodReader(string $condition): bool
    {
        return str_contains($condition, 'requestMethod') || str_contains($condition, 'isPost');
    }

    /**
     * Строки, в которых стоит литерал `$_SERVER['REQUEST_METHOD']`. Форма записи, которую разбор
     * не ждёт (двойные кавычки, `getenv`, переменная-ключ), краснит: иначе новая форма стала бы
     * ещё одной молчаливой слепой зоной вместо проверяемой.
     *
     * @return list<int>
     */
    private function serverLiteralLines(string $code): array
    {
        $out = [];
        $offset = 0;

        while (true) {
            $position = strpos($code, "\$_SERVER['REQUEST_METHOD']", $offset);
            if ($position === false) {
                break;
            }

            $out[] = substr_count(substr($code, 0, $position), "\n") + 1;
            $offset = $position + 1;
        }

        if ($out === [] && str_contains($code, 'REQUEST_METHOD')) {
            self::fail(
                'В тексте встречено `REQUEST_METHOD` в форме, которую разбор не читает: сверить прямое'
                    . ' чтение метода нечем, а молчать означало бы зелёный прогон при слепой зоне'
            );
        }

        return $out;
    }

    /**
     * Текст условия, стоящего сразу за `switch` или `match`: от открывающей круглой скобки до
     * парной закрывающей, вместе с обеими скобками. Запись, в которой скобка не найдена или не
     * закрылась до конца текста, отдаёт null. Молчания на null больше нет (пункт Г2Ф-2, находка
     * Н-7): обход `unreadableFormsIn()` краснит такое условие, потому что неразобранный код не
     * имеет права давать зелёный прогон, а полагаться на шаг `php -l` ворот нельзя.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function conditionText(array $tokens, int $index): ?string
    {
        $count = count($tokens);
        $start = $this->skipWhitespace($tokens, $index + 1);

        if ($start >= $count || $this->text($tokens[$start]) !== '(') {
            return null;
        }

        return $this->group($tokens, $start, '(', ')');
    }

    private function unreadableViolation(string $label, int $line, string $form): string
    {
        return $label . ', строка ' . $line . ': пост-ветка в форме `' . $form
            . '`, которую обход стража `CsrfCoverageTest` не читает; перепишите её через `if`/`elseif`'
            . ' или расширьте распознавание осознанно';
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
        // Совпадений может быть несколько на файл: экран «Приложения» собирает по форме на каждую
        // строку списка. Условие «ровно одно» молча возвращало пустой список и оставляло весь
        // такой файл вне проверки — форма без токена рядом с формой с токеном проходила зелёной.
        //
        // Значение метода пишется и без кавычек: `method=post` — корректный HTML, а прежний шаблон
        // требовал кавычки и такую форму не считал вовсе. Для незакавыченного варианта обязательна
        // граница значения — пробел, слэш или закрывающая скобка тега: после литеры `post` обязан
        // идти один из них, поэтому `method=postfix` в список не попадает. Жадное `post.*` сюда не
        // подходит: оно схватало бы и `postfix`, и всё, что стоит после значения.
        $found = preg_match_all('/<form\b[^>]*method=(?:"post"|\'post\'|post(?=[\s\/>]))[^>]*>/i', $code, $matches, PREG_OFFSET_CAPTURE);
        if ($found === false || $found === 0) {
            return [];
        }

        $out = [];
        foreach ($matches[0] as $match) {
            $out[] = (int) $match[1];
        }

        return $out;
    }

    /**
     * Разбор форм одного файла: сколько POST-форм найдено и какие из них не несут поля токена.
     * Обход один — количество и находки считаются по одному списку позиций.
     *
     * @return array{forms: int, violations: list<string>}
     */
    private function formsScan(string $code, string $relative = 'probe.php'): array
    {
        $extents = $this->formExtents($code);
        $violations = [];

        foreach ($extents as $open) {
            $line = substr_count(substr($code, 0, $open), "\n") + 1;
            // Закрывающий тег ищется без учёта регистра и с необязательными пробелами: «</FORM>»
            // и «</form >» — равноправная разметка, а поиск подстрокой оставил бы форму
            // «незакрытой» и выдал бы ложный отказ.
            if (preg_match('/<\/form\s*>/i', substr($code, $open), $tail, PREG_OFFSET_CAPTURE) !== 1) {
                $violations[] = $relative . ', строка ' . $line . ': форма method="post" не закрыта — её границы не измерить';
                continue;
            }

            $chunk = substr($code, $open, (int) $tail[0][1]);
            if (preg_match('/csrfField\s*\(\s*\)|name=\\\\?["\']_token["\']/', $chunk) !== 1) {
                $violations[] = $relative . ', строка ' . $line . ': форма method="post" без поля токена внутри своих границ';
            }
        }

        return ['forms' => count($extents), 'violations' => $violations];
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (self::CODE_DIRS as $dir) {
            self::assertDirectoryExists(
                $root . '/' . $dir,
                $dir . ': каталог исчез из дерева — страж токенов проверял бы меньше, чем требует правило'
            );

            $found = [];
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $found[] = $item->getPathname();
                }
            }

            self::assertNotEmpty($found, $dir . ': каталог не отдал ни одного PHP-файла — проверять нечего');
            $out = array_merge($out, $found);
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

    /**
     * Текст без комментариев, который подают разборам все обходы этого стража. Блочный комментарий
     * заменяется таким же количеством переводов строк, а не вырезается вместе с ними (пункт
     * Г2Ф-1, находка Н-3): адрес находки считается по этому тексту, и maintainer обязан идти на ту
     * строку файла, где ветка стоит на самом деле, а не на строку укороченного текста.
     */
    private function withoutComments(string $code): string
    {
        $code = (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
            $code
        );

        return (string) preg_replace('#//[^\n]*#', '', $code);
    }
}
