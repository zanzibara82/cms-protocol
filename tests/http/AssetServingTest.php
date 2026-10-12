<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Картинки лежат в подкаталогах по назначению, а адрес каждой печатается из шаблона или из
// константы раздела. При переносе файла достаточно не дождаться одной такой строки, и страница
// откроется с пустым местом вместо картинки: ответ сервера при этом двеста, ни один тест про
// наличие файла не спрашивает, и порча молчит. Здесь вопрос задаётся разметке: какие адреса она
// обещает, те и обязаны отдавать файл.
final class AssetServingTest extends TestCase
{
    /**
     * Страницы, чья разметка обещает статику: главная отдаёт шапку с котом, стили и иконку,
     * экран отказа — грустного кота. Обе открываются без входа и без данных раздела, поэтому
     * набор не зависит от сидов и от установленного «Новостями» состояния базы.
     *
     * @var array<string, int>
     */
    private const PAGES = [
        '/' => 200,
        '/такой-страницы-нет' => 404,
    ];

    /**
     * Адреса прежней плоской раскладки: файлы из них перенесены, и открыть их больше нечем.
     *
     * @var list<string>
     */
    private const STALE_ADDRESSES = [
        '/assets/cat-happy.png',
        '/assets/cat-sad.png',
        '/assets/favicon.png',
        '/assets/og-default.png',
    ];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    public function testКаждыйОбещанныйРазметкойАдресОтдаётФайл(): void
    {
        $addresses = [];

        foreach (self::PAGES as $page => $status) {
            $response = Kernel::get($page);
            self::assertSame($status, $response['status'], 'Страница ' . $page . ' открылась не тем статусом — собирать адреса не из чего');

            foreach ($this->addresses($response['body']) as $address) {
                $addresses[$address] = true;
            }
        }

        // Порог смысла: пустой список означал бы, что сборщик не нашёл в разметке ни одного адреса,
        // и вся проверка выключилась бы зелёным.
        self::assertGreaterThanOrEqual(4, count($addresses), 'В разметке главной и экрана отказа найдено меньше четырёх адресов статики — сборщик слепой');

        foreach (array_keys($addresses) as $address) {
            $response = Kernel::get($address);

            // Тип содержимого проверяется рядом со статусом: фронт-контроллер отвечает двеста и на
            // страницу, и на картинку, а браузеру нужен именно файл.
            self::assertSame(200, $response['status'], 'Адрес ' . $address . ' из разметки не отдаётся');
            self::assertNotSame('', $response['body'], 'Адрес ' . $address . ' отдан пустым телом');
            self::assertFalse(
                str_starts_with((string) ($response['headers']['content-type'] ?? ''), 'text/html'),
                'Адрес ' . $address . ' отдан страницей вместо файла'
            );
        }
    }

    // Фирменное превью раздел печатает из константы `NEWS_OG_IMAGE` и только в метатеге, где адрес
    // идёт вместе с доменом, — в разметке главной его нет. Проверка берёт значение константы как
    // есть: перенос файла без правки константы краснит именно здесь.
    public function testФирменноеПревьюИзКонстантыОтдаётся(): void
    {
        $response = Kernel::get(NEWS_OG_IMAGE);

        self::assertSame(200, $response['status'], 'Превью ' . NEWS_OG_IMAGE . ' из константы раздела не отдаётся');
        self::assertNotSame('', $response['body'], 'Превью ' . NEWS_OG_IMAGE . ' отдано пустым телом');
        self::assertSame('image/png', $response['headers']['content-type'] ?? '', 'Превью отдано не как картинка');
    }

    // Самопроверка в двух частях. Сборщик обязан вычитать адреса из разметки, а не из головы:
    // эталонный список берётся из сочинённого тела с двумя адресами и лишним текстом вокруг.
    // И закрытые адреса проверяются тем же мерителем, что и открытые: без этой строки список
    // перенесённых путей был бы утверждением, а не фактом, и половину переноса можно было бы
    // пропустить зелёным.
    public function testСборщикЛовитАдресаАПеренесённыеЗакрываются(): void
    {
        $body = '<link rel="stylesheet" href="/assets/app.css"><img src="/assets/img/cats/cat-happy.png" alt="кот">';
        self::assertSame(
            ['/assets/app.css', '/assets/img/cats/cat-happy.png'],
            $this->addresses($body),
            'Сборщик не вычитает адреса из разметки ровно или выдумывает лишние'
        );

        self::assertSame(
            self::STALE_ADDRESSES,
            $this->notServed(self::STALE_ADDRESSES),
            'Прежний плоский адрес отдаётся: файлы лежат в двух местах либо разметка указала на старый путь'
        );
    }

    /**
     * Адреса статики в тексте ответа: порядок не важен, поэтому список сортируется и убираются
     * повторы — одна и та же картинка может быть обещана дважды.
     *
     * @return list<string>
     */
    private function addresses(string $body): array
    {
        $found = [];
        preg_match_all('#/assets/[A-Za-z0-9._/-]+#', $body, $matches);

        foreach ($matches[0] as $address) {
            $found[$address] = true;
        }

        $list = array_keys($found);
        sort($list);

        return $list;
    }

    /**
     * Из списка адресов те, что не отдаются как файл.
     *
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private function notServed(array $addresses): array
    {
        $gone = [];

        foreach ($addresses as $address) {
            $response = Kernel::get($address);

            if ($response['status'] !== 200 || $response['body'] === '') {
                $gone[] = $address;
            }
        }

        return $gone;
    }
}
