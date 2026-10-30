<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

/**
 * Страж исходников: каждый первый сегмент литеральных путей в файлах routes.php приложений
 * обязан стоять в PAGES_RESERVED_SLUGS. Точный маршрут всегда выигрывает у /{slug} по построению
 * сортировки роутера, поэтому страница, создавшаяся с чужим префиксом, была бы недостижима,
 * а человек узнал бы об этом только после публикации. Новый раздел обязан добавить свой префикс
 * в резерв-список ядра — страж краснеет и называет файл.
 *
 * Ядро здесь не обходится: его первые сегменты (admin, login, logout) перечислены в резерв-списке
 * руками и меняются вместе с ядром; имена файлов с точкой (sitemap.xml) слагом страницы не
 * собираются в принципе — точка отбрасывается нормализацией.
 */
final class PagesSlugGuardTest extends TestCase
{
    public function testПрефиксыРазделовПеречисленыВРезервеСлаговСтраниц(): void
    {
        $sources = glob(dirname(__DIR__, 2) . '/apps/*/routes.php') ?: [];

        // Пустой обход — не «нарушений нет», а сломанный страж: узнаваемая причина, а не зелёный свет.
        self::assertNotSame([], $sources, 'Обход не нашёл ни одного файла routes.php приложений');

        $violations = [];
        $seen = 0;

        foreach ($sources as $source) {
            $lines = (array) file((string) $source);
            $file = basename(dirname((string) $source));

            foreach ($lines as $line) {
                if (preg_match("/addRoute\('(?:GET|POST)', '\/([a-z0-9_.-]+)/", (string) $line, $matches) !== 1) {
                    continue;
                }

                $seen++;
                $prefix = (string) $matches[1];

                if (!in_array($prefix, PAGES_RESERVED_SLUGS, true)) {
                    $violations[] = $file . ': /' . $prefix;
                }
            }
        }

        self::assertGreaterThan(0, $seen, 'Обход не распознал ни одного литерального маршрута');

        self::assertSame(
            [],
            $violations,
            'Префиксы разделов, которых нет в PAGES_RESERVED_SLUGS — страница с таким адресом была бы недостижима'
        );
    }
}
