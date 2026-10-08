<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Коммит 2550410 перестал применять пустые значения окружения в цикле перекрытия настроек
// (core/config-loader.php): пустая строка из CMS_SITE_URL обязана оставить адрес сайта из файла
// настроек, иначе absoluteUrl() отдаёт относительные пути и карта сайта теряет абсолютные адреса,
// которых требует формат. Доказательство живёт только в http-наборе: config() держит мемоизированный
// static $loaded, поэтому процесс PHPUnit видит настройки первого обратившегося теста и больше не
// проходит цикл перекрытия, а предикат configHasKey() от него не зависит — в юнит-наборе мутацию
// увидеть красным нельзя. Единственный способ поймать поведение — поднять сервер с чистым
// окружением, где пустая строка задана до первого вызова config() внутри подпроцесса, поэтому
// прогон идёт отдельным классом со своим харнелем Kernel и собственным окружением сервера.
final class EmptySiteUrlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        // Присваивание пустой строки доходит до php -S: Kernel::start() ставит ИМЯ='' после
        // снятия унаследованных ключей, поэтому перекрытие окружением происходит именно пустым
        // значением CMS_SITE_URL.
        Kernel::start(['CMS_SITE_URL' => '']);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    public function testSitemapОстаётсяАбсолютнымПриПустомПерекрытииОкружения(): void
    {
        // Пустое CMS_SITE_URL не затёрло SITE_URL из файла настроек: карта сохраняет абсолютный
        // адрес. Мутация «условие только на false» вернула бы относительный путь — обе проверки
        // ниже тогда краснеют.
        $page = Kernel::get('/sitemap.xml');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<loc>http://localhost:8080/</loc>', $page['body']);
        self::assertStringNotContainsString('<loc>/', $page['body']);
    }

    public function testRobotsСсылаетсяНаАбсолютнуюКартуСайта(): void
    {
        // Строку «Sitemap:» в robots.txt собирает тот же absoluteUrl(): пустое перекрытие сдвинуло
        // бы её на относительный путь, поэтому здесь обязателен абсолютный адрес карты.
        self::assertStringContainsString(
            'Sitemap: http://localhost:8080/sitemap.xml',
            Kernel::get('/robots.txt')['body']
        );
    }
}
