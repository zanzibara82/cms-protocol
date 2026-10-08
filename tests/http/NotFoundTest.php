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
        // Каждый путь закрывается ровно одним статусом. Прежнее допущение «403 или 404» проходило
        // и на мёртвом фронт-контроллере: встроенный сервер сам отвечает 404 на файл вне корня,
        // и проверка зеленела, ничего не проверяя. Здесь статус сверяется точно, тело обязано быть
        // страницей приложения, а подпись содержимого не имеет права попасть в ответ ни под каким
        // видом — закрытый каталог не равен «отдали исходник под статусом 404».
        $closed = [
            '/config.sample.php' => 'DB_PASS',
            '/composer.json' => '"require-dev"',
            '/core/bootstrap.php' => 'require_once',
            '/sql/0001_core_baseline.sql' => 'CREATE TABLE',
        ];

        foreach ($closed as $path => $signature) {
            $response = Kernel::get($path);

            self::assertSame(404, $response['status'], $path . ' обязан закрываться приложением со статусом 404');
            self::assertStringContainsString('Страницы нет', $response['body'], $path . ' обязан попасть на страницу 404 приложения');
            self::assertStringNotContainsString($signature, $response['body'], $path . ' отдал наружу своё содержимое');
        }
    }
}
