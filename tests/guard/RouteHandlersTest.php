<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Спецификация §10.2: «каждый маршрут манифеста имеет обработчик». Маршрут хранит имя функции
// строкой, поэтому опечатка в routes.php всплывает только на запросе человека — страж ловит её
// до коммита. База не нужна: addRoute() и require routes.php в неё не ходят.
//
// Проверка «каждый приватный маршрут закрыт в robots.txt» живёт здесь с задачи 9: таблица
// маршрутов — единственный источник меток «private», а закрытые префиксы перечисляет
// robotsDisallowed() из core/seo.php.
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

    // Гейт смотрит только в метку маршрута (core/router.php), поэтому забытый «private» открывает
    // админский обработчик публике. Скоуп выпуска «приватные пути закрыты гейтом» сторожится здесь.
    public function testПриватныйПутьЗакрытГейтом(): void
    {
        $prefixes = array_merge(appPrivatePaths(), ['/admin']);
        $violations = [];

        foreach (routeTable() as $route) {
            $pattern = (string) $route['pattern'];
            $isPrivatePath = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($pattern, $prefix)) {
                    $isPrivatePath = true;
                    break;
                }
            }

            if (!$isPrivatePath) {
                continue;
            }

            if (($route['meta']['private'] ?? false) !== true || ($route['meta']['permission'] ?? '') === '') {
                $violations[] = (string) $route['method'] . ' ' . $pattern;
            }
        }

        self::assertSame([], $violations, 'Приватные пути без метки гейта');
    }

    // Словарь прав гейта живёт в dispatch() (core/router.php): «login» и «manage». Значение,
    // которого там нет, не закрыло бы маршрут ничем — опечатку ловим до коммита.
    public function testЗначенияПраваИзвестныГейту(): void
    {
        $unknown = [];

        foreach (routeTable() as $route) {
            $permission = (string) ($route['meta']['permission'] ?? '');
            if ($permission !== '' && !in_array($permission, ['login', 'manage'], true)) {
                $unknown[] = (string) $route['method'] . ' ' . $route['pattern'] . ' → ' . $permission;
            }
        }

        self::assertSame([], $unknown, 'Неизвестные гейту значения права');
    }

    public function testПослеСбросаТаблицыМаршрутыРазделаНаМесте(): void
    {
        // Первый registerAppRoutes() уже исполнил routes.php фикстур в этом процессе.
        // require_once файл повторно не выполнит, поэтому маршруты возвращаются из снимка.
        routesReset();
        registerCoreRoutes();
        registerAppRoutes();

        $paths = [];

        foreach (routeTable() as $route) {
            $paths[] = (string) $route['method'] . ' ' . (string) $route['pattern'];
        }

        self::assertContains('GET /demo', $paths, 'Публичные маршруты раздела после сброса');
        self::assertContains('GET /future', $paths, 'Маршрут раздела из будущего после сброса');

        // Первый registerAppRoutes() уже исполнил apps/news/routes.php в этом процессе.
        // require_once файл повторно не выполнит, поэтому маршруты настоящего раздела
        // возвращаются из снимка: без него страж молча проверял бы только фикстуры.
        self::assertContains('GET /news', $paths, 'Публичные маршруты раздела после сброса');
        self::assertContains('GET /news/{slug}', $paths, 'Карточка материала после сброса');

        foreach (routeTable() as $route) {
            if (($route['meta']['app'] ?? '') === '') {
                continue;
            }

            self::assertTrue(
                is_callable($route['handler']),
                'Обработчик маршрута ' . (string) $route['method'] . ' ' . (string) $route['pattern'] . ' не определён после сброса'
            );
        }
    }

    public function testКаждыйПриватныйМаршрутЗакрытВRobots(): void
    {
        // Приватный маршрут без приватного пути в манифесте — расхождение, которое иначе не видно:
        // гейт закрывает доступ, но поисковик продолжает видеть адрес в robots.txt открытым.
        $closed = robotsDisallowed();

        foreach (routeTable() as $route) {
            if (($route['meta']['private'] ?? false) !== true) {
                continue;
            }

            $covered = false;
            foreach ($closed as $prefix) {
                if (str_starts_with($route['pattern'], (string) $prefix)) {
                    $covered = true;
                    break;
                }
            }

            self::assertTrue($covered, 'Приватный маршрут ' . $route['pattern'] . ' не закрыт в robots.txt');
        }
    }
}
