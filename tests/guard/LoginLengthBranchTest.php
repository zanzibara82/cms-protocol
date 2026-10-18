<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Отказ по адресу длиннее колонки журнала (LOGIN_EMAIL_MAX) обязан происходить до всего
// дорогого: до выборки пользователя и до проверки пароля. Аргон2 дорог по построению, и вызов
// его на произвольной длинной строке — готовый дешёвый отказ в обслуживании. Поведенческим
// тестом порядок не различается: текст отказа, пустой журнал и статус ответа одинаковы, где бы
// ветка ни стояла относительно verifyPassword(). Замером времени закреплять нельзя: второй
// тайминг сделал бы набор db плавающим на границе секунды. Поэтому порядок закрепляется по
// исходнику: три позиции — ветка длины, выборка пользователя и вызов verifyPassword() — читаются
// по номерам строк и обязаны идти сверху вниз именно в этом порядке.
// Якоря читают не весь файл, а тело attemptLogin(): копия строки ветки в другой функции иначе
// сдвинула бы позицию якоря, а копия внутри тела сделала бы порядок неоднозначным. Номера страж
// выдаёт номера строк core/auth.php, а не окна: ключи строк окна сохраняются, а разбор на строки
// идёт с модификатором `u` — без него байт 0x85 внутри кирилличной буквы считается переносом
// строки, и все номера съезжают вперёд.
// Порядок — верхняя граница стоимости отказа только относительно текущих строк: второй вызов
// проверки пароля в том же теле оставил бы цепочку зелёной и снова сделал бы отказ дорогим, поэтому
// число вызовов закреплено отдельной единицей. Само число 190 тоже закреплено: оно происходит из
// ширины колонок адреса базового релиза схемы, и расхождение с ними превращает отказ по длинному
// адресу в переполнение колонки и 500-й ответ.
final class LoginLengthBranchTest extends TestCase
{
    public function testВеткаДлиныОтвечаетДоПроверкиПароля(): void
    {
        $path = dirname(__DIR__, 2) . '/core/auth.php';
        self::assertFileExists($path, 'core/auth.php обязан существовать: страж проверяет исходник входа');
        $source = (string) file_get_contents($path);

        $body = $this->attemptLoginBody($source);

        // Верхняя граница стоимости отказа проверяется до порядка строк: второй вызов проверки пароля
        // был бы назван потерянным якорем, а не дорогим отказом, и текст падения увёл бы рецензента
        // не туда. Счёт идёт по тексту окна, поэтому копия вызова из другой функции сюда не попадает.
        $verifyCalls = preg_match_all('/^\s*\$passwordMatches = verifyPassword\(/m', (string) implode("\n", $body));
        if ($verifyCalls === false) {
            self::fail('Страж не смог посчитать вызовы проверки пароля: узор разбора тела attemptLogin() не разобрался');
        }

        if ($verifyCalls === 0) {
            self::fail('Страж потерял якорь вызова verifyPassword(): закреплять порядок нечем, якорь обновляется '
                . 'осознанно под новую форму вызова, а не удаляется');
        }

        self::assertSame(
            1,
            $verifyCalls,
            'В теле `attemptLogin()` больше одного вызова проверки пароля: отказ по заведомо мусорному входе '
            . 'снова считает аргон2 дважды, и дешёвый отказ перестаёт быть дешёвым'
        );

        $lengthLine = $this->firstLine(
            $body,
            '/^\s*if \(mb_strlen\(\$clean\) > LOGIN_EMAIL_MAX\)/',
            'Ветка длины адреса исчезла из attemptLogin(): длинный адрес доходит до выборки пользователя '
            . 'и verifyPassword(), а аргон2 на произвольной строке — готовый дешёвый отказ в обслуживании',
            'В теле attemptLogin() ветка отказа по длине адреса встречается больше одного раза: по номерам '
            . 'строк порядок теряет смысл, пока неясно, какая из копий отвечает заведомо мусорный вход'
        );

        $selectLine = $this->firstLine(
            $body,
            "/^\\s*\\\$row = one\\('SELECT /",
            'Страж потерял якорь выборки пользователя: порядок относительно `one()` закреплять нечем, '
            . 'якорь обновляется осознанно под новую форму запроса',
            'В теле attemptLogin() выборка пользователя встречается больше одного раза: цепочка «ветка длины — '
            . 'выборка — проверка пароля» перестаёт быть цепочкой, пока неясно, какая из копий считает пользователя'
        );

        $verifyLine = $this->firstLine(
            $body,
            '/^\s*\$passwordMatches = verifyPassword\(\$password,/',
            'Страж потерял якорь вызова verifyPassword(): закреплять порядок нечем, якорь обновляется '
            . 'осознанно под новую форму вызова, а не удаляется',
            'В теле attemptLogin() вызов проверки пароля встречается больше одного раза: якорь неоднозначен, '
            . 'и порядок строк выше него нельзя назвать одной цифрой'
        );

        self::assertLessThan(
            $selectLine,
            $lengthLine,
            'Ветка длины обязана отвечать до выборки: иначе длинный адрес уходит в базу — запрос к таблице '
            . 'пользователя на заведомо мусорном входе стоит дороже самого отказа, а журнал попыток при этом '
            . 'всё равно не пишется, поэтому бегущей проверкой порядок не различается'
        );

        self::assertLessThan(
            $verifyLine,
            $selectLine,
            'Выборка пользователя обязана отвечать до проверки пароля: строки сравниваются парами соседних '
            . 'позиций, и без этого звена цепочка «ветка длины — выборка — аргон2» не держится'
        );

        self::assertLessThan(
            $verifyLine,
            $lengthLine,
            'Ветка длины обязана отвечать раньше проверки пароля: verifyPassword() на адресе длиннее '
            . 'колонки журнала считает дорогой аргон2 на заведомо мусорном входе — дешёвый отказ '
            . 'в обслуживании, ради защиты от которого ветка и стоит до выборки пользователя'
        );
    }

    public function testПорогСовпадаетСШиринойКолонкиАдреса(): void
    {
        $authPath = dirname(__DIR__, 2) . '/core/auth.php';
        self::assertFileExists($authPath, 'core/auth.php обязан существовать: страж проверяет порог входа по исходнику');

        $limit = $this->loginEmailMaxLength((string) file_get_contents($authPath));

        $schemaPath = dirname(__DIR__, 2) . '/sql/0001_core_baseline.sql';
        self::assertFileExists(
            $schemaPath,
            'sql/0001_core_baseline.sql обязан существовать: из ширины его колонок адреса выводится порог входа'
        );

        $matched = [];
        $columns = preg_match_all('/^\s*email VARCHAR\((\d+)\)/mi', (string) file_get_contents($schemaPath), $matched);
        if ($columns === false) {
            self::fail('Страж не смог разобрать sql/0001_core_baseline.sql: узор колонки адреса не отработал');
        }

        /** @var list<string> $captures */
        $captures = array_map('strval', $matched[1]);
        self::assertNotEmpty(
            $captures,
            'Базовый релиз схемы sql/0001_core_baseline.sql не отдал ни одной колонки `email`: сверять константу '
            . 'LOGIN_EMAIL_MAX с шириной колонок нечем, якорь обновляется осознанно под новый релиз, а не удаляется'
        );

        /** @var list<int> $widths */
        $widths = array_values(array_unique(array_map('intval', $captures)));

        self::assertSame(
            [$limit],
            $widths,
            'Константа `LOGIN_EMAIL_MAX` разошлась с шириной колонок адреса в базовом релизе схемы: порог обязан '
            . 'отсекать ровно то, что не влезет в колонку журнала попыток, иначе отказ по длинному адресу упирается '
            . 'в переполнение и даёт 500-й ответ. Фактические ширины колонок email: ' . implode(', ', $widths)
        );
    }

    /**
     * Значение константы LOGIN_EMAIL_MAX из core/auth.php: объявление ищется по всему файлу и обязано
     * быть ровно одним, иначе порог нельзя назвать одной цифрой.
     */
    private function loginEmailMaxLength(string $source): int
    {
        $matched = [];
        $declarations = preg_match_all('/^const LOGIN_EMAIL_MAX = (\d+);/m', $source, $matched);
        if ($declarations === false) {
            self::fail('Страж не смог разобрать core/auth.php: узор объявления LOGIN_EMAIL_MAX не отработал');
        }

        if ($declarations === 0) {
            self::fail('Страж потерял якорь константы LOGIN_EMAIL_MAX: сверять порог с шириной колонок схемы нечем — '
                . 'объявление обновляется осознанно под новую форму, а не удаляется');
        }

        if ($declarations > 1) {
            self::fail('В core/auth.php больше одного объявления LOGIN_EMAIL_MAX: порог нельзя назвать одной цифрой, '
                . 'и сверка с шириной колонок адреса теряет смысл');
        }

        /** @var non-empty-list<string> $captures */
        $captures = array_map('strval', $matched[1]);

        return (int) $captures[0];
    }

    /**
     * Строки тела attemptLogin(): от строки объявления функции до следующей строки объявления
     * функции, а если следующей нет — до конца файла. Ключи массива сохраняются, поэтому номер
     * ключа плюс единица — это номер строки core/auth.php, а не строки окна.
     *
     * @return array<int, string>
     */
    private function attemptLoginBody(string $source): array
    {
        $lines = preg_split('/\R/u', $source) ?: [];
        self::assertNotSame([], $lines, 'core/auth.php не разобрался на строки: телу attemptLogin() нечего отдавать стражу');

        $start = null;
        $end = count($lines);

        foreach ($lines as $index => $line) {
            if ($start === null) {
                if (preg_match('/^\s*function attemptLogin\(/', (string) $line) === 1) {
                    $start = $index;
                }

                continue;
            }

            if (preg_match('/^\s*function /', (string) $line) === 1) {
                $end = $index;
                break;
            }
        }

        if ($start === null) {
            self::fail('Страж потерял объявление attemptLogin(): порядок строк внутри входа закреплять нечем — '
                . 'имя функции обновляется в страже осознанно, а не молча');
        }

        $body = array_slice($lines, $start, $end - $start, true);
        self::assertNotEmpty($body, 'Тело attemptLogin() пусто: ни объявления, ни его строк страж не нашёл — '
            . 'закреплять порядок нечем');

        return $body;
    }

    /**
     * Номер строки файла — единственной строки окна, совпавшей с узором.
     *
     * @param array<int, string> $body
     * @return int<1, max>
     */
    private function firstLine(array $body, string $pattern, string $missing, string $repeated): int
    {
        $hits = [];

        foreach ($body as $fileIndex => $line) {
            if (preg_match($pattern, (string) $line) === 1) {
                $hits[] = ((int) $fileIndex) + 1;
            }
        }

        if ($hits === []) {
            self::fail($missing);
        }

        if (count($hits) > 1) {
            self::fail($repeated . ': найдены строки ' . implode(', ', $hits) . '.');
        }

        return $hits[0];
    }
}
