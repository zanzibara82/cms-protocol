<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Заголовки безопасности обязаны ехать на каждом ответе, а не только на 404: раньше
// их проверяла одна строка на одной странице, и переписанный securityHeaders() прошёл
// бы весь набор зелёным. HSTS не отдавался вовсе ни на одном ответе, хотя условие
// «сайт работает по HTTPS» в проекте уже есть (isHttps()).
final class SecurityHeadersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    /** @return list<string> */
    private static function paths(): array
    {
        return ['/', '/login', '/robots.txt', '/feed.xml', '/sitemap.xml', '/admin'];
    }

    public function testКаждыйОтветНесётПолныйНаборЗаголовков(): void
    {
        $required = [
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
            'referrer-policy' => 'strict-origin-when-cross-origin',
        ];

        foreach (self::paths() as $path) {
            $response = Kernel::get($path);

            foreach ($required as $header => $value) {
                self::assertSame($value, $response['headers'][$header] ?? null, 'Нет заголовка ' . $header . ' на ' . $path);
            }

            $csp = (string) ($response['headers']['content-security-policy'] ?? '');
            self::assertStringContainsString("default-src 'self'", $csp, 'Нет CSP на ' . $path);
            self::assertStringContainsString("script-src 'self'", $csp, 'CSP не запрещает чужой скрипт на ' . $path);
            self::assertStringContainsString("frame-ancestors 'self'", $csp, 'CSP не ограничивает встраивание на ' . $path);
        }
    }

    public function testHstsОтдаётсяТолькоПоHttps(): void
    {
        // По HTTP заголовок бессмысленен и вреден: браузер запомнит его для домена
        // и сломает локальную приёмку. Проверяем обе стороны.
        $plain = Kernel::get('/robots.txt');
        self::assertArrayNotHasKey('strict-transport-security', $plain['headers'], 'По HTTP HSTS не отдаётся');

        // Значение проверяется чистым помощником: стенд поднимается по HTTP, а
        // подделывать $_SERVER ради одного заголовка дороже, чем проверить правило.
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            securityHeadersHstsValue(true),
            'По HTTPS HSTS отдаётся на год с поддоменами'
        );
        self::assertSame('', securityHeadersHstsValue(false), 'Без HTTPS заголовка нет');
    }
}
