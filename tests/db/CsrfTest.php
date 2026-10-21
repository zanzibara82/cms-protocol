<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Core\ResponseFinished;
use PHPUnit\Framework\TestCase;

// CSRF проверяется без HTTP: токен в сессии против строки из формы.
final class CsrfTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    /** @var array<string, mixed> */
    private array $postBackup = [];

    /** @var array<string, list<array{fn: callable, priority: int, kind: string, order: int}>> */
    private array $hooksBackup = [];

    protected function setUp(): void
    {
        // Снимок суперглобальных до изменения: тест подменяет сессию, форму и адрес запроса,
        // и обязан вернуть их как было — иначе изменённый $_SERVER поедет в следующие тесты
        // этого процесса.
        $this->serverBackup = $_SERVER;
        $this->sessionBackup = isset($_SESSION) ? (array) $_SESSION : [];
        $this->postBackup = $_POST;

        // Реестр подписок — тоже общее состояние процесса: чужие наборы снимают его hooksReset(),
        // а набор со своей подпиской обязан вернуть реестр как было.
        $registry = &hookRegistry();
        $this->hooksBackup = $registry;

        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $registry = &hookRegistry();
        $registry = $this->hooksBackup;

        $_SERVER = $this->serverBackup;
        $_SESSION = $this->sessionBackup;
        $_POST = $this->postBackup;
    }

    public function testТокенСоздаётсяОдинРазИПовторяется(): void
    {
        $first = csrfToken();

        self::assertSame(64, strlen($first));
        self::assertSame($first, csrfToken());
    }

    public function testПолеНесётТокенВРазметке(): void
    {
        $field = csrfField();

        self::assertStringContainsString('name="_token"', $field);
        self::assertStringContainsString('value="' . csrfToken() . '"', $field);
    }

    public function testСовпадающийТокенПроходит(): void
    {
        $token = csrfToken();
        $_POST['_token'] = $token;

        csrfCheck();

        // Совпадение принято: проверка дошла до конца и не подменила токен в сессии.
        // Без «?? ''» потеря ключа в сессии краснеет, а не превращается в пустую строку.
        self::assertArrayHasKey('csrf_token', $_SESSION, 'Принятая проверка обязана оставить токен в сессии');
        self::assertSame($token, $_SESSION['csrf_token'], 'Проверка не отвергла свой токен и не подменила его');
    }

    // Источник истины — сессия, а не форма: без этой проверки реализация, которая сверяет
    // «токен сам с собой» или вовсе игнорирует сессию, осталась бы зелёной.
    public function testПустаяСессияОтвергаетДажеПравильноВыглядещийТокен(): void
    {
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_POST['_token'] = str_repeat('a', 64);

        $this->expectException(ResponseFinished::class);
        $this->expectExceptionMessage('403');
        $this->expectOutputRegex('/Проверка формы не прошла/');

        csrfCheck();
    }

    public function testЧужойТокенОтвергаетсяСтатусомЧетырестаТри(): void
    {
        csrfToken();
        $_POST['_token'] = str_repeat('0', 64);
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->expectException(ResponseFinished::class);
        $this->expectExceptionMessage('403');
        // Тело отказа — собранная страница theme/403.php, поэтому проверка по вхождению текста, а не по строке целиком.
        $this->expectOutputRegex('/Проверка формы не прошла/');

        csrfCheck();
    }

    // ── Публичный режим: машинный приёмник без сессии ────

    // Публичный режим сверяет подпись через фильтр 'csrf_public_token': у приёмника нет
    // сессии посетителя, и метка «запрос с нашего сайта» приезжает телом запроса.
    public function testПубличныйРежимПринимаетПодписьРаздела(): void
    {
        addFilter('csrf_public_token', static fn (mixed $verified, mixed $token): bool => $token === 'подпись-раздела');
        $_POST['_token'] = 'подпись-раздела';

        csrfCheck('public');

        self::assertArrayNotHasKey('csrf_token', $_SESSION, 'Машинный приёмник не заводит сессионный токен');
    }

    public function testПубличныйРежимОтвергаетЧужуюПодпись(): void
    {
        addFilter('csrf_public_token', static fn (mixed $verified, mixed $token): bool => $token === 'подпись-раздела');
        $_SERVER['REQUEST_URI'] = '/seo/collect';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_POST['_token'] = 'подпись-чужого-раздела';

        $this->expectException(ResponseFinished::class);
        $this->expectExceptionMessage('403');
        $this->expectOutputRegex('/Проверка запроса не прошла/');

        csrfCheck('public');
    }

    // Режим без сессии не должен «на всякий случай» принять сессионный токен: подпись приёмника
    // и метка формы — разные вещи, и смешивать их — значит открыть машинам чужую форму.
    public function testПубличныйРежимНеПринимаетСессионныйТокен(): void
    {
        $token = csrfToken();
        $_POST['_token'] = $token;
        $_SERVER['REQUEST_URI'] = '/seo/collect';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        $this->expectException(ResponseFinished::class);
        $this->expectExceptionMessage('403');
        $this->expectOutputRegex('/Проверка запроса не прошла/');

        csrfCheck('public');
    }
}
