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

    // Свойство фиксируется явно, а не «каждый адрес как-то начинается с базы»: при пустом
    // SITE_URL прошлая версия проходила молча, пустая карта сайтов — тоже. Теперь пустые
    // входы красные, а сборка адреса сверяется с определением на эталонном пути.
    public function testАбсолютныеАдресаСтроятсяИзНастроек(): void
    {
        $base = rtrim((string) configValue('SITE_URL', ''), '/');
        self::assertNotSame('', $base, 'SITE_URL пуст: проверка сборки адреса ничего не фиксирует');
        // Схема обязательна: без неё «абсолютный» адрес карты сайта остаётся путём, и поисковик
        // не сможет его разрешить. Проверка смотрит на настройки, а не на ту же склейку, которую
        // проверяет, — иначе вырожденный вход прошёл бы бы молча.
        self::assertMatchesRegularExpression('#^https?://#', $base, 'SITE_URL обязан содержать схему http:// или https://');

        $urls = sitemapUrls();
        self::assertNotSame([], $urls, 'Карта сайта пуста: проверке не на чём работать');

        // Эталон: абсолютный адрес = настройки + путь с одним ведущим слэшем.
        self::assertSame($base . '/probe', absoluteUrl('/probe'), 'Эталонный путь собран не из настроек');
        self::assertSame($base . '/probe', absoluteUrl('probe'), 'Путь без ведущего слэша не нормализуется');

        foreach ($urls as $url) {
            $loc = (string) $url['loc'];
            self::assertSame($base . '/' . ltrim($loc, '/'), absoluteUrl($loc), 'Адрес ' . $loc . ' собран не из SITE_URL');
        }
    }
}
