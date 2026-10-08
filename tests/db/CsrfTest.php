<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use PHPUnit\Framework\TestCase;

// CSRF проверяется без HTTP: токен в сессии против строки из формы.
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
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
        // Свой токен принят: проверка дошла до конца и не подменила токен в сессии.
        self::assertSame($token, $_SESSION['csrf_token'] ?? '', 'Проверка не отвергла свой токен');
    }

    public function testЧужойТокенОтвергаетсяСтатусомЧетырестаТри(): void
    {
        csrfToken();
        $_POST['_token'] = str_repeat('0', 64);
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->expectException(\Cms\Core\ResponseFinished::class);
        $this->expectExceptionMessage('403');
        // Тело отказа — собранная страница theme/403.php, поэтому проверка по вхождению текста, а не по строке целиком.
        $this->expectOutputRegex('/Проверка формы не прошла/');

        csrfCheck();
    }
}
