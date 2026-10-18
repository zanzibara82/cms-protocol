<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class CommerceShopTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        CommerceHttpSetup::install();

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
        CommerceHttpSetup::cleanup();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        CommerceHttpSetup::prepare();
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    public function testКаталогОткрываетсяАнониму(): void
    {
        $page = Kernel::get('/shop');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кофемашина', $page['body']);
        self::assertStringNotContainsString('Черновой товар', $page['body']);
    }

    public function testЧерновикНеОткрываетсяПоАдресу(): void
    {
        $page = Kernel::get('/shop/draft-tovar');

        self::assertSame(404, $page['status']);
    }

    public function testКарточкаТовараПоказываетЦенуИРазметку(): void
    {
        $page = Kernel::get('/shop/kofemashina');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кофемашина', $page['body']);
        self::assertStringContainsString('"@type":"Product"', $page['body']);
        self::assertStringContainsString('"@type":"Offer"', $page['body']);
        self::assertStringContainsString('COFFEE-1', $page['body']);
    }

    public function testСкрытаяРубрикаОтвечает404(): void
    {
        CommerceHttpSetup::hideCategory();

        $page = Kernel::get('/shop/c/drobnost');

        self::assertSame(404, $page['status']);
    }

    public function testАдминкаМагазинаМанитНаВход(): void
    {
        $page = Kernel::get('/commerce/admin');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login', (string) ($page['headers']['location'] ?? ''));
    }

    public function testПотокПокупкиСписываетОстатокИДержитПодтверждение(): void
    {
        // Карточка товара: токен формы живёт на странице
        $page = Kernel::get('/shop/kofemashina');
        self::assertSame(200, $page['status']);

        $added = Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);
        self::assertSame(302, $added['status']);

        $cart = Kernel::get('/shop/cart');
        self::assertSame(200, $cart['status']);
        self::assertStringContainsString('Кофемашина', $cart['body']);
        self::assertStringContainsString('2 240,00 ₽', $cart['body']);

        $checkout = Kernel::post('/shop/order', [
            '_token' => $this->token($cart['body']),
            'customer_name' => 'Анна Полугодова',
            'customer_email' => 'anna@example.dev',
            'customer_phone' => '+7 999 111 22 33',
            'address' => 'Псков, улица Ленина 1',
            'comment' => '',
        ]);

        self::assertSame(302, $checkout['status']);
        $location = (string) ($checkout['headers']['location'] ?? '');
        self::assertStringContainsString('/shop/order/', $location);

        // Подтверждение в собственной сессии печатает заказ покупателя.
        $confirm = Kernel::get($location);
        self::assertSame(200, $confirm['status']);
        self::assertStringContainsString('Анна Полугодова', $confirm['body']);

        // Без cookies тот же адрес — отказ: номер держит сессия, а не адрес.
        Kernel::freshCookies();
        $foreign = Kernel::get($location);
        self::assertSame(404, $foreign['status']);

        // Остаток товара уменьшился ровно на одну штуку.
        self::assertSame(34, CommerceHttpSetup::stockOf());
    }

    public function testЗаказБезТокенаОтклоняется(): void
    {
        $response = Kernel::post('/shop/order', [
            'customer_name' => 'Гадюка',
            'customer_email' => 'gadyaka@example.dev',
        ]);

        self::assertSame(403, $response['status']);
    }

    public function testЧастныйПутьМагазинаЗакрытRobots(): void
    {
        $robots = Kernel::get('/robots.txt');

        self::assertSame(200, $robots['status']);
        self::assertStringContainsString('Disallow: /commerce/admin', $robots['body']);
    }
}
