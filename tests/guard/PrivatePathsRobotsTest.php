<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Критерий §12.5: robots.txt обязан закрывать приватные пути. Проверка идёт по списку,
// а не по тексту ответа — HTTP-тест видит только то, что сервер отдал в этот момент.
final class PrivatePathsRobotsTest extends TestCase
{
    public function testКаждыйПриватныйПутьЗакрыт(): void
    {
        $closed = robotsDisallowed();

        foreach (appPrivatePaths() as $path) {
            self::assertContains($path, $closed, 'Приватный путь ' . $path . ' не закрыт в robots.txt');
            self::assertTrue(isRobotsDisallowed($path));
        }
    }

    public function testПриватныеПутиНеПопадаютВКартуСайта(): void
    {
        foreach (sitemapUrls() as $url) {
            self::assertFalse(isRobotsDisallowed((string) $url['loc']), 'Закрытый путь ' . (string) $url['loc'] . ' попал в карту сайта');
        }
    }

    public function testСлужебныеКаталогиЗакрыты(): void
    {
        $closed = robotsDisallowed();

        foreach (['/core/', '/apps/', '/sql/', '/tests/', '/config.php', '/setup.php', '/install.lock'] as $path) {
            self::assertContains($path, $closed);
        }
    }

    public function testПриватныеПутиНачинаютсяСоСлэша(): void
    {
        foreach (appPrivatePaths() as $path) {
            self::assertStringStartsWith('/', $path);
            self::assertStringNotContainsString('//', $path);
            self::assertStringNotContainsString('\\', $path);
        }
    }

    public function testАбсолютныеАдресаСтроятсяИзНастроек(): void
    {
        $base = rtrim((string) configValue('SITE_URL', ''), '/');

        foreach (sitemapUrls() as $url) {
            self::assertStringStartsWith($base, absoluteUrl((string) $url['loc']));
        }
    }
}
