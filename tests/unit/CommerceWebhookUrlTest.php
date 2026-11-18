<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Адрес вебхука — это куда сервер сам сделает HTTP-запрос. Поэтому проверять «начинается
 * с http://» мало: адрес указывает администратор магазина, а звонит curl с самого сервера.
 * Без запрета внутренних диапазонов приёмником становится панель хостинга, база на
 * 127.0.0.1:3306 или служба метаданных облака 169.254.169.254 — классический SSRF.
 *
 * Разрешение имён подменяется замыканием: тест обязан быть детерминированным и не
 * зависеть от DNS прогонной машины. Базы нет — правила чистые.
 */
final class CommerceWebhookUrlTest extends TestCase
{
    /** @return list<string> */
    private static function publicHost(string $host): array
    {
        return ['93.184.216.34'];
    }

    public function testВнутренниеАдресаОтклоняютсяБезРазрешенияИмён(): void
    {
        // Литералы не требуют DNS: они и есть адрес. Каждый — реальный вектор атаки.
        $forbidden = [
            'http://127.0.0.1/',
            'http://127.0.0.1:3306/',
            'http://10.0.0.5/orders',
            'http://172.16.31.7/',
            'http://192.168.1.1/',
            'http://169.254.169.254/latest/meta-data/',
            'http://0.0.0.0/',
            'http://100.64.0.1/',
            'http://[::1]/',
            'http://[fe80::1]/',
            'http://[fc00::1]/',
        ];

        foreach ($forbidden as $url) {
            self::assertNotSame('', commerceWebhookUrlProblem($url, self::publicHost(...)), 'Внутренний адрес обязан отклоняться: ' . $url);
        }
    }

    public function testИмяРазрешающеесяВнутрьОтклоняется(): void
    {
        // Имя выглядит публичным, но разрешается в петлю — так обходят проверку по литералам.
        $toLoopback = static fn (string $host): array => ['127.0.0.1'];

        $problem = commerceWebhookUrlProblem('https://приёмник.рф/orders', $toLoopback);
        self::assertNotSame('', $problem, 'Имя, разрешающееся в петлю, обязано отклоняться');

        $toMetadata = static fn (string $host): array => ['169.254.169.254'];
        self::assertNotSame('', commerceWebhookUrlProblem('https://evil.example.com/', $toMetadata));

        // Один публичный адрес среди внутренних не спасает: перебор адресов тоже атака.
        $mixed = static fn (string $host): array => ['93.184.216.34', '10.0.0.5'];
        self::assertNotSame('', commerceWebhookUrlProblem('https://mixed.example.com/', $mixed), 'Достаточно одного внутреннего адреса в ответе');
    }

    public function testПубличныйАдресПроходит(): void
    {
        self::assertSame('', commerceWebhookUrlProblem('https://93.184.216.34/orders', self::publicHost(...)));
        self::assertSame('', commerceWebhookUrlProblem('https://приёмник.рф/orders', self::publicHost(...)));
        self::assertSame('', commerceWebhookUrlProblem('http://example.com:8080/hook', self::publicHost(...)));
    }

    public function testСхемаИУчётныеДанныеПроверяются(): void
    {
        // Только http/https: file://, gopher://, dict:// — чужие протоколы для curl.
        self::assertNotSame('', commerceWebhookUrlProblem('file:///etc/passwd', self::publicHost(...)));
        self::assertNotSame('', commerceWebhookUrlProblem('gopher://example.com/', self::publicHost(...)));
        self::assertNotSame('', commerceWebhookUrlProblem('ftp://example.com/', self::publicHost(...)));

        // Учётные данные в адресе уходят в заголовок Authorization — секрет вебхука не должен туда попасть.
        self::assertNotSame('', commerceWebhookUrlProblem('https://user:pass@example.com/hook', self::publicHost(...)));

        // Битая строка не должна доходить до curl вовсе.
        self::assertNotSame('', commerceWebhookUrlProblem('http://', self::publicHost(...)));
    }

    public function testНеразрешимоеИмяОтклоняется(): void
    {
        $void = static fn (string $host): array => [];

        self::assertNotSame('', commerceWebhookUrlProblem('https://нет-такого-хоста.рф/', $void), 'Имя без адресов доставить некуда');
    }

    public function testВалидаторФормыКраситВнутреннийАдрес(): void
    {
        $check = commerceValidateWebhook(['url' => 'http://169.254.169.254/latest', 'secret' => 'secret-20-symbols-x']);

        self::assertArrayHasKey('url', $check['errors'], 'Форма обязана отклонить внутренний адрес до сохранения');
    }
}
