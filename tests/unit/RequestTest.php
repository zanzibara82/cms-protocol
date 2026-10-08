<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/request.php';

// Тест подставляет суперглобальные массивы запроса, поэтому сохраняет и возвращает их в исходное
// состояние: иначе изменение $_SERVER одного теста просочилось бы в остальные наборы прогона.
final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    /** @var array<string, mixed> */
    private array $getBackup;

    /** @var array<string, mixed> */
    private array $postBackup;

    /** @var array<string, mixed> */
    private array $filesBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->filesBackup = $_FILES;

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_FILES = $this->filesBackup;
    }

    public function testМетодЗапросаВсегдаВВерхнемРегистре(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';

        self::assertSame('POST', requestMethod());
        self::assertTrue(isPost());
    }

    public function testБезМетодаЗапросСчитаетсяГэтом(): void
    {
        self::assertSame('GET', requestMethod());
        self::assertFalse(isPost());
    }

    public function testПутьОчищаетсяОтСтрокиЗапросаИКрайннихСлэшей(): void
    {
        $_SERVER['REQUEST_URI'] = '/news/?page=2';

        self::assertSame('/news', requestPath());
    }

    public function testКорневойПутьОтдаётсяСлэшем(): void
    {
        $_SERVER['REQUEST_URI'] = '/';

        self::assertSame('/', requestPath());
    }

    public function testБезUriПутьРавенКорню(): void
    {
        self::assertSame('/', requestPath());
    }

    public function testЗначениеИзСтрокиЗапроса(): void
    {
        $_GET = ['page' => '2', 'filter' => 'news'];

        self::assertSame('2', queryString('page'));
        self::assertSame('news', queryString('filter'));
    }

    public function testОтсутствующийКлючОтдаётЗаглушку(): void
    {
        self::assertNull(queryString('page'));
        self::assertSame('1', queryString('page', '1'));
    }

    public function testНестроковоеЗначениеЗаменяетсяЗаглушкой(): void
    {
        $_GET = ['page' => ['2']];

        self::assertSame('1', queryString('page', '1'));
    }

    public function testБезКлючаВозвращаетсяВсяСтрокаЗапроса(): void
    {
        $_GET = ['page' => '2'];

        self::assertSame(['page' => '2'], queryString());
    }

    public function testPostStringВозвращаетТолькоСтроку(): void
    {
        $_POST = ['title' => 'Привет', 'tag' => ['a', 'b']];

        self::assertSame('Привет', postString('title'));
        self::assertSame('', postString('tag'));
        self::assertSame('', postString('missing'));
    }

    public function testПостВсеПоля(): void
    {
        $_POST = ['title' => 'Привет'];

        self::assertSame(['title' => 'Привет'], postAll());
    }

    public function testФайлОтдачиётсяТолькоКогдаОнЗагружен(): void
    {
        $_FILES = [
            'ok' => ['name' => 'a.png', 'error' => UPLOAD_ERR_OK],
            'none' => ['name' => '', 'error' => UPLOAD_ERR_NO_FILE],
        ];

        self::assertIsArray(uploadedFile('ok'));
        self::assertNull(uploadedFile('none'));
        self::assertNull(uploadedFile('missing'));
    }

    public function testАйПиКлиентаБерётсяИзСерверногоАдреса(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        self::assertSame('203.0.113.9', clientIp());
    }

    public function testБезСерверногоАдресаКлиентСчитаетсяЛокальным(): void
    {
        self::assertSame('0.0.0.0', clientIp());
    }

    public function testФлагПовышенногоПротокола(): void
    {
        $_SERVER['HTTPS'] = 'on';

        self::assertTrue(isHttps());
    }

    public function testПортСорокЧетыреТожеЗначитШифрование(): void
    {
        $_SERVER['SERVER_PORT'] = '443';

        self::assertTrue(isHttps());
    }

    public function testБезФлагаИПортаПротоколОбычный(): void
    {
        self::assertFalse(isHttps());
    }
}
