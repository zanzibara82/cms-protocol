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
        $paths = appPrivatePaths();

        // Пустой список — не «нечего проверять», а выключенный страж: без этой строки обход
        // молча проходил бы, стоит appPrivatePaths() перестать что-либо возвращать.
        self::assertNotEmpty($paths, 'Список приватных путей пуст — страж robots проверяет ноль путей');

        foreach ($paths as $path) {
            self::assertContains($path, $closed, 'Приватный путь ' . $path . ' не закрыт в robots.txt');
            self::assertTrue(isRobotsDisallowed($path));
        }
    }

    // Прежняя версия обходила sitemapUrls() и спрашивала у каждого адреса, не закрыт ли он, —
    // а сам sitemapUrls() уже отбраковывает закрытые, поэтому найти нарушение было невозможно:
    // тест краснел только на пустой карте. Здесь приватные пути добавляются фильтром в самом
    // тесте, и проверка сравнивает «что вернул фильтр» с «что выпустила карта».
    public function testПриватныеПутиНеПопадаютВКартуСайта(): void
    {
        $private = ['/admin/apps', '/login', '/setup.php'];
        $this->injectIntoSitemap($private);

        $offered = array_column($this->filteredSitemap(), 'loc');
        foreach ($private as $path) {
            // Невыполненное условие проверки: если фильтр не доносит приватный путь, утверждение
            // ниже снова ничего не фиксирует.
            self::assertContains($path, $offered, 'Фильтр не добавил приватный путь ' . $path . ': проверке не на чём работать');
        }

        $published = array_column(sitemapUrls(), 'loc');
        self::assertContains('/', $published, 'Публичная страница «/» исчезла из карты сайта');

        foreach ($private as $path) {
            self::assertNotContains($path, $published, 'Закрытый путь ' . $path . ' попал в карту сайта');
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
        // проверяет, — иначе вырожденный вход прошёл бы молча.
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

    protected function tearDown(): void
    {
        // Фильтр нужен только этому набору: после него реестр пуст, как при загрузке ядра.
        hooksReset();

        parent::tearDown();
    }

    /** @param list<string> $paths */
    private function injectIntoSitemap(array $paths): void
    {
        hooksReset();

        // Так раздел добавляет свои адреса в карту сайта (§5.4): тест проигрывает тот же путь,
        // но с приватными адресами — продуктового кода в проверке нет.
        addFilter('sitemap_urls', static function (array $urls) use ($paths): array {
            foreach ($paths as $path) {
                $urls[] = ['loc' => $path];
            }

            return $urls;
        });
    }

    /**
     * То, что отдал фильтр до отбраковки ядром: тот же вызов applyFilters(), который делает
     * sitemapUrls(), но без её решета — иначе проверка вновь стала бы тавтологией.
     *
     * @return list<array{loc: string}>
     */
    private function filteredSitemap(): array
    {
        $filtered = applyFilters('sitemap_urls', coreSitemapUrls());
        if (!is_array($filtered)) {
            throw new \RuntimeException('Фильтр карты сайта вернул не список адресов.');
        }

        return $filtered;
    }
}
