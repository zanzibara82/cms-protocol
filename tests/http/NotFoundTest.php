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
        // Пакет V, фикс финальной рецензии (остаток V4): экран 404 собран через `render()`
        // с `noindex`, поэтому правило «`noindex` запрещает кэширование» догоняет его в том же
        // `core/template.php`. Утверждение точное, а не «содержит»: лимитер сессии в контейнере
        // даёт комбинированное `no-store, no-cache, must-revalidate`, и «содержит» зеленело бы
        // даже без правила; совпадение доказывает работу правила, а не лимитера.
        self::assertSame('no-store', $response['headers']['cache-control'], 'Экран 404 не отдал собственный запрет кэширования');
    }

    public function testКотНаСтранице404ИмеетНастоящиеПропорцииИОтложеннуюЗагрузку(): void
    {
        $response = Kernel::get('/такой-страницы-нет');

        // height считается от настоящих размеров файла (cat-sad.png — 192×170), а не приравнивается
        // к width: иначе кот сплюснут по вертикали, и браузер занимает под картинку неверное место.
        self::assertStringContainsString('width="96" height="85"', $response['body']);
        // Экран ошибки — не первый: картинку можно досылать позже, когда человек уже читает текст.
        self::assertStringContainsString('loading="lazy"', $response['body']);
        self::assertStringContainsString('decoding="async"', $response['body']);
    }

    public function testКотВШапкеГлавнойНеТормозитПервыйЭкран(): void
    {
        $response = Kernel::get('/');

        // Маскот в шапке стоит на первом экране, поэтому отложенной загрузки у него быть не может:
        // lazy на логотипе отдаляет самую заметную картинку сайта.
        self::assertStringContainsString('width="32" height="32"', $response['body']);
        self::assertStringNotContainsString('loading="lazy"', $response['body']);
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
