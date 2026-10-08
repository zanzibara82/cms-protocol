<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class NotFoundTest extends TestCase
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

    public function testГлавнаяОткрывается(): void
    {
        $response = Kernel::get('/');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Читать новости', $response['body']);
    }

    public function testНесуществующийАдресОтдаётЧетырестаЧетыре(): void
    {
        $response = Kernel::get('/такой-страницы-нет');

        self::assertSame(404, $response['status']);
        self::assertStringContainsString('Страницы нет', $response['body']);
    }

    public function testЗаголовкиБезопасностиОтдаютсяНаКаждомЗапросе(): void
    {
        $response = Kernel::get('/');

        self::assertSame('nosniff', $response['headers']['x-content-type-options']);
        self::assertSame('SAMEORIGIN', $response['headers']['x-frame-options']);
        self::assertStringContainsString("default-src 'self'", $response['headers']['content-security-policy']);
    }

    public function testСлужебныеКаталогиНедоступныПоHttp(): void
    {
        foreach (['/config.sample.php', '/composer.json', '/core/bootstrap.php', '/sql/0001_core_baseline.sql'] as $path) {
            $response = Kernel::get($path);
            self::assertContains($response['status'], [403, 404], $path . ' обязан быть закрыт');
        }
    }
}
