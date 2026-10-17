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
// исходнику: строка ветки длины обязана стоять выше строки вызова verifyPassword().
final class LoginLengthBranchTest extends TestCase
{
    public function testВеткаДлиныОтвечаетДоПроверкиПароля(): void
    {
        $path = dirname(__DIR__, 2) . '/core/auth.php';
        self::assertFileExists($path, 'core/auth.php обязан существовать: страж проверяет исходник входа');
        $source = (string) file_get_contents($path);

        $lengthLine = $this->firstLine(
            $source,
            '/^\s*if \(mb_strlen\(\$clean\) > LOGIN_EMAIL_MAX\)/',
            'Ветка длины адреса исчезла из attemptLogin(): длинный адрес доходит до выборки пользователя '
            . 'и verifyPassword(), а аргон2 на произвольной строке — готовый дешёвый отказ в обслуживании'
        );

        $verifyLine = $this->firstLine(
            $source,
            '/^\s*\$passwordMatches = verifyPassword\(\$password,/',
            'Страж потерял якорь вызова verifyPassword(): закреплять порядок нечем, якорь обновляется '
            . 'осознанно под новую форму вызова, а не удаляется'
        );

        self::assertLessThan(
            $verifyLine,
            $lengthLine,
            'Ветка длины обязана отвечать раньше проверки пароля: verifyPassword() на адресе длиннее '
            . 'колонки журнала считает дорогой аргон2 на заведомо мусорном входе — дешёвый отказ '
            . 'в обслуживании, ради защиты от которого ветка и стоит до выборки пользователя'
        );
    }

    /** @return int<1, max> */
    private function firstLine(string $source, string $pattern, string $failure): int
    {
        $lines = preg_split('/\R/', $source) ?: [];
        self::assertNotSame([], $lines, $failure);

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, (string) $line) === 1) {
                return $index + 1;
            }
        }

        self::fail($failure);
    }
}
