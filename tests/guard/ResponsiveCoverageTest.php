<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Адаптивность админки проверяется руками: scripts/responsive-check.mjs поднимает Chrome,
// которого в контейнере нет, поэтому в воротах шага нет. Единственный, кто держит полноту
// списка проверяемых экранов, — этот страж. До задачи 31 в списке были только экраны
// новостей (10 адресов), а конструктор страниц, медиатека, редакция, настройки, заявки и
// журнал — ровно те экраны, где обрезку и разъезжающиеся поля уже ловили дважды, — в
// проверке отсутствовали. Новый админский маршрут обязан попасть в список, иначе он
// останется вне единственной автоматической проверки раскладки.
final class ResponsiveCoverageTest extends TestCase
{
    private const SCRIPT = 'scripts/responsive-check.mjs';

    private const ROUTES = [
        'core/routes.php',
        'core/editorial-routes.php',
        'apps/news/routes.php',
        'apps/commerce/routes.php',
        'apps/classifieds/routes.php',
        'apps/seo/routes.php',
        'apps/learn/routes.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Статические адреса `/admin…` из роутеров. Адреса с `{id}` отброшены: без подставленного
     * идентификатора они дают 404, а раскладку правки отдаёт тот же шаблон, что и создание.
     *
     * @return list<string>
     */
    private function adminRoutes(): array
    {
        $routes = [];

        foreach (self::ROUTES as $file) {
            $path = $this->root() . '/' . $file;

            if (!is_file($path)) {
                continue;
            }

            $text = (string) file_get_contents($path);

            if (preg_match_all("/addRoute\('GET',\s*'([^']+)'/", $text, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $route) {
                if (!str_contains($route, '/admin') || str_contains($route, '{')) {
                    continue;
                }

                $routes[$route] = true;
            }
        }

        $list = array_keys($routes);
        sort($list);

        return $list;
    }

    /** @return list<string> */
    private function screenedRoutes(): array
    {
        $path = $this->root() . '/' . self::SCRIPT;
        self::assertFileExists($path, 'Нет скрипта проверки адаптивности');

        $text = (string) file_get_contents($path);

        // Только блок ADMIN_PAGES: в скрипте есть и публичные адреса (fixed), их сверять с
        // маршрутами `/admin` бессмысленно. Строка запроса отбрасывается — `?q=` открывает
        // тот же экран, что и без неё.
        $start = strpos($text, 'const ADMIN_PAGES = [');
        self::assertNotFalse($start, 'В скрипте нет списка ADMIN_PAGES');

        $end = strpos($text, ']', (int) $start);
        self::assertNotFalse($end, 'Список ADMIN_PAGES не закрыт');

        $block = substr($text, (int) $start, (int) $end - (int) $start);

        if (preg_match_all("/href:\s*'([^']+)'/", $block, $matches) === false) {
            return [];
        }

        $list = [];

        foreach ($matches[1] as $href) {
            $list[] = strtok($href, '?') ?: $href;
        }

        $list = array_values(array_unique($list));
        sort($list);

        return $list;
    }

    public function testСписокЭкрановПокрываетВсеАдминскиеМаршруты(): void
    {
        // Пустой список — не «нечего проверять», а выключенный страж: без этой строки он молча
        // зеленел бы, стоит регулярке перестать совпадать.
        $routes = $this->adminRoutes();
        self::assertNotEmpty($routes, 'Ни одного админского маршрута не найдено — страж проверяет ноль адресов и молчит');

        $screened = $this->screenedRoutes();
        self::assertNotEmpty($screened, 'Список проверяемых экранов пуст');

        $missing = array_values(array_diff($routes, $screened));

        self::assertSame(
            [],
            $missing,
            'Админские экраны вне проверки адаптивности: ' . implode(', ', $missing)
        );
    }

    public function testСписокСодержитТолькоЖивыеАдреса(): void
    {
        // Обратная сторона: адрес удалённого экрана в списке даёт 404 и «проверяет» страницу
        // ошибки вместо настоящей раскладки — тихая ложь отчёта.
        $routes = $this->adminRoutes();
        $stale = array_values(array_diff($this->screenedRoutes(), $routes));

        self::assertSame([], $stale, 'В списке проверки остались несуществующие адреса: ' . implode(', ', $stale));
    }
}
