<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Флаги сессионной куки — это защита, которую видно только в браузере: без HttpOnly
// украденный скриптом идентификатор становится сессией, без SameSite чужой сайт
// приносит его POST-ом, без strict_mode сервер принимает подброшенный идентификатор.
// Ни один http-тест этих условий не проверяет (стенд поднимается по HTTP, а браузера
// в контейнере нет), поэтому набор зелёный и после поломки startSession().
//
// Страж читает исходник, как CsrfCoverageTest читает обработчики, и требует:
// — кука сессии задаётся массивом параметров с четырьмя флагами;
// — strict_mode включён;
// — вход перевыпускает идентификатор.
final class SessionFlagsTest extends TestCase
{
    private const SESSION = 'core/session.php';

    private function source(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::SESSION;
        self::assertFileExists($path, 'Нет файла сессии — страж проверяет ноль строк');

        return (string) file_get_contents($path);
    }

    public function testКукаСессииНесётЧетыреФлага(): void
    {
        $source = $this->source();

        foreach (['httponly', 'samesite', 'secure', 'path'] as $flag) {
            self::assertMatchesRegularExpression(
                "/'" . $flag . "'\s*=>/",
                $source,
                'В session_set_cookie_params нет флага ' . $flag . ': защита куки потеряна'
            );
        }

        // Позиционные аргументы (старая форма) не дают выставить SameSite вовсе:
        // седьмого аргумента у функции до PHP 8.2 не было, и флаг молча не работает.
        self::assertStringContainsString('session_set_cookie_params([', $source, 'Кука задаётся массивом параметров, а не позиционно');
        self::assertStringContainsString("'httponly' => true", $source, 'HttpOnly обязан быть включён: иначе украденный идентификатор работает');
        self::assertStringContainsString("'samesite' => 'Lax'", $source, 'SameSite обязан быть не None: иначе чужой сайт несёт POST');
    }

    public function testСтрогийРежимНеДаётПринятьЧужойИдентификатор(): void
    {
        self::assertMatchesRegularExpression(
            "/ini_set\('session\.use_strict_mode',\s*'1'\)/",
            $this->source(),
            'Без use_strict_mode сервер принимает идентификатор, которого сам не выдавал'
        );
    }

    public function testВходПеревыпускаетИдентификатор(): void
    {
        $source = $this->source();

        // Перевыпуск до записи данных в сессию: иначе фиксация сессии остаётся открытой,
        // потому что гость уже успел получить идентификатор до входа.
        $regenerate = strpos($source, 'session_regenerate_id(true)');
        self::assertNotFalse($regenerate, 'Вход обязан перевыпускать идентификатор (защита от фиксации сессии)');

        $login = strpos($source, 'function loginUser(');
        self::assertNotFalse($login, 'Нет loginUser()');

        $write = strpos($source, "\$_SESSION['user_id']", (int) $login);
        self::assertNotFalse($write, 'Нет записи user_id');

        self::assertGreaterThan((int) $login, (int) $regenerate, 'Перевыпуск идёт внутри входа');
        self::assertLessThan((int) $write, (int) $regenerate, 'Перевыпуск обязан идти до записи user_id');
    }

    public function testВыходЧиститИДанныеИКуку(): void
    {
        $source = $this->source();

        self::assertStringContainsString('session_destroy()', $source, 'Выход обязан уничтожать сессию на сервере');
        self::assertMatchesRegularExpression('/\$_SESSION\s*=\s*\[\]/', $source, 'Выход обязан чистить данные сессии');
        self::assertStringContainsString('setcookie(', $source, 'Выход обязан гасить куку и в браузере');
    }
}
