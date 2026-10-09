<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Экран внутреннего отказа — автономная страница: он собирается `partial()` и печатается
// напрямую через `respond()` в маршрутизаторе, минуя `render()` с правилом «`noindex`
// запрещает кэширование». Находка финальной рецензии пакета V: шаблон `core/templates/error.php`
// печатает `meta name="robots" content="noindex, nofollow"`, а заголовка `Cache-Control` у ответа
// не было — единственная в пакете противоречивая пара «`noindex` без `no-store`».
final class ErrorScreenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        // Фикстурный маршрут с отсутствующим обработчиком живёт в tests/fixtures/apps/broken
        // и открывается только по отметке окружения — она же поднимает каталог фикстур,
        // иначе файла маршрутов в реестре нет.
        Kernel::start([
            'CMS_EXTRA_APPS_DIR' => dirname(__DIR__, 2) . '/tests/fixtures/apps',
            'CMS_TEST_OMITTED_HANDLER' => '1',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    public function testЭкран500ОтдаётТочныйNoStoreВместеСNoindex(): void
    {
        $response = Kernel::get('/test-omitted-handler');

        self::assertSame(500, $response['status']);
        // Тело обязано быть экраном, помеченным `noindex`: без этой пары тест проверял бы
        // заголовок страницы, которую никто не просил не индексировать.
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response['body']);
        self::assertStringContainsString('Что-то сломалось', $response['body']);
        // Утверждение точное, а не «содержит»: `session.cache_limiter` в контейнере уже даёт
        // комбинированное `no-store, no-cache, must-revalidate`, и проверка «содержит» зеленела бы,
        // даже если наша строка заголовка в `respond()` не выполняется. Точное совпадение доказывает,
        // что запрет кэширования пришёл от правила пакета, а не от лимитера сессии. Тем же точным
        // утверждением закрыт второй автономный экран — «Сайт не установлен» (SiteNotInstalledTest).
        self::assertSame('no-store', $response['headers']['cache-control'], 'Экран 500 не отдал собственный запрет кэширования');
    }
}
