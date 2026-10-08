<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Спецификация §10.2: «каждый маршрут манифеста имеет обработчик». Маршрут хранит имя функции
// строкой, поэтому опечатка в routes.php всплывает только на запросе человека — страж ловит её
// до коммита. База не нужна: addRoute() и require routes.php в неё не ходят.
//
// Проверка «каждый приватный маршрут закрыт в robots.txt» появляется в задаче 9 вместе с самим
// robotsDisallowed(): здесь её держать нельзя, иначе ворота краснеют между задачами.
final class RouteHandlersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        routesReset();
        registerCoreRoutes();
        registerAppRoutes();
    }

    public function testКаждыйОбработчикСуществует(): void
    {
        $missing = [];

        foreach (routeTable() as $route) {
            if (!is_callable($route['handler'])) {
                $missing[] = $route['method'] . ' ' . $route['pattern'] . ' → ' . $route['handler'];
            }
        }

        self::assertSame([], $missing, 'Маршруты без обработчика');
    }

    public function testПриватныйМаршрутСсылаетсяНаИзвестныйРаздел(): void
    {
        foreach (routeTable() as $route) {
            if (($route['meta']['private'] ?? false) !== true) {
                continue;
            }

            $app = (string) ($route['meta']['app'] ?? '');
            self::assertTrue(
                $app === '' || array_key_exists($app, appManifests()),
                'Приватный маршрут ' . $route['pattern'] . ' ссылается на неизвестный раздел «' . $app . '»'
            );
        }
    }

    public function testТаблицаМаршрутовНеПуста(): void
    {
        self::assertNotSame([], routeTable());
    }
}
