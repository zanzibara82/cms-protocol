<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Макет страницы и ширина секции проверяются настоящим запросом: макет меняет каркас публичной
 * страницы (шапку, подвал, сайдбар), а ширина — контейнер внутри секции.
 *
 * Причина проверки: до этой работы каркас был один на весь сайт. Настройка макета могла бы
 * сохраняться в базе и ни на что не влиять — ровно так уже случалось с колонкой `layout` у
 * секций, которую читали только два вида. Поэтому проверяется не запись в базу, а разметка
 * ответа: есть ли шапка, есть ли подвал, какой контейнер у секции.
 */
final class PageLayoutHttpTest extends TestCase
{
    private const EDITOR_EMAIL = 'layout@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    private function login(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    /** Создаёт опубликованную страницу и возвращает её id. */
    private function makePage(string $slug, string $title): string
    {
        $this->login();
        $list = Kernel::get('/admin/pages');

        $created = Kernel::post('/admin/pages', [
            '_token' => Kernel::tokenFrom($list['body']),
            'op' => 'create-page',
            'title' => $title,
            'slug' => $slug,
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], ['follow' => false]);

        // Успех ведёт в редактор новой страницы: id читается из адреса перехода.
        // К адресу добавляется ключ уведомления, поэтому id берётся до вопроса.
        self::assertSame(302, $created['status']);
        $location = (string) ($created['headers']['location'] ?? '');
        $path = (string) parse_url($location, PHP_URL_PATH);
        self::assertMatchesRegularExpression('~/admin/pages/[A-Za-z0-9]+$~', $path, 'Страница не создалась: ' . $location);

        return substr($path, strlen('/admin/pages/'));
    }

    /** Меняет макет страницы через её редактор. Адрес передаётся: правка полей формы не должна
     * случайно менять адрес — иначе публичный запрос теста уйдёт на несуществующую страницу. */
    private function setLayout(string $pageId, string $layout, string $slug, string $title): void
    {
        $screen = Kernel::get('/admin/pages/' . $pageId);

        $saved = Kernel::post('/admin/pages/' . $pageId, [
            '_token' => Kernel::tokenFrom($screen['body']),
            'op' => 'save-page',
            'title' => $title,
            'slug' => $slug,
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
            'layout' => $layout,
        ], ['follow' => false]);

        self::assertSame(302, $saved['status']);
    }

    public function testЛендингПечатаетсяБезШапкиИПодвала(): void
    {
        $pageId = $this->makePage('lending-bez-shapki', 'Лендинг без шапки');

        // До смены макета страница стандартная: шапка и подвал на месте.
        $before = Kernel::get('/lending-bez-shapki')['body'];
        self::assertStringContainsString('<header', $before, 'У стандартной страницы нет шапки');
        self::assertStringContainsString('<footer', $before, 'У стандартной страницы нет подвала');

        $this->setLayout($pageId, 'landing', 'lending-bez-shapki', 'Лендинг без шапки');

        $after = Kernel::get('/lending-bez-shapki')['body'];
        // Шапка и подвал — отдельные блоки разметки: на лендинге их нет вовсе.
        self::assertStringNotContainsString('<header', $after, 'Лендинг обязан быть без шапки');
        self::assertStringNotContainsString('<footer', $after, 'Лендинг обязан быть без подвала');
        // Содержимое страницы остаётся: макет меняет обвязку, а не данные.
        self::assertStringContainsString('Лендинг без шапки', $after);
    }

    public function testСтраницаБезПодвалаОставляетШапку(): void
    {
        $pageId = $this->makePage('bez-podvala', 'Без подвала');
        $this->setLayout($pageId, 'no-footer', 'bez-podvala', 'Без подвала');

        $body = Kernel::get('/bez-podvala')['body'];
        self::assertStringContainsString('<header', $body, 'У макета «без подвала» шапка остаётся');
        self::assertStringNotContainsString('<footer', $body, 'Подвала быть не должно');
    }

    public function testНеизвестныйМакетВФормеНеЛомаетСтраницу(): void
    {
        $pageId = $this->makePage('chuzhoy-maket', 'Чужой макет');

        // Подделанное значение из формы: страница обязана остаться стандартной, а не отдаться
        // без шапки или с белым экраном.
        $screen = Kernel::get('/admin/pages/' . $pageId);
        Kernel::post('/admin/pages/' . $pageId, [
            '_token' => Kernel::tokenFrom($screen['body']),
            'op' => 'save-page',
            'title' => 'Чужой макет',
            'slug' => 'chuzhoy-maket',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
            'layout' => 'hacker-layout',
        ], ['follow' => false]);

        $page = Kernel::get('/chuzhoy-maket');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<header', $page['body'], 'Неизвестный макет обязан откатиться к стандартному');
    }

    public function testШиринаСекцииМеняетКонтейнер(): void
    {
        $pageId = $this->makePage('shirokaya-sektsiya', 'Широкая секция');
        $screen = Kernel::get('/admin/pages/' . $pageId);

        $created = Kernel::post('/admin/pages/' . $pageId, [
            '_token' => Kernel::tokenFrom($screen['body']),
            'op' => 'create-section',
            'kind' => 'band',
            'anchor' => 'shiroko',
            'badge' => '',
            'title' => 'Полоса во всю ширину',
            'lead_text' => 'Текст полосы',
            'width' => 'full',
        ], ['follow' => false]);
        self::assertSame(302, $created['status']);

        $body = Kernel::get('/shirokaya-sektsiya')['body'];
        self::assertStringContainsString('Полоса во всю ширину', $body);

        // «Во всю ширину» печатает внешний контейнер без ограничителя: у секции нет max-w-5xl.
        $section = $this->sectionHtml($body, 'shiroko');
        self::assertStringContainsString('w-full', $section, 'Полная ширина обязана печатать контейнер во весь экран');
        self::assertStringNotContainsString('max-w-5xl', $section, 'Полная ширина не ограничивает контейнер');
    }

    public function testСвояШиринаПопадаетВСтиль(): void
    {
        $pageId = $this->makePage('svoya-shirota', 'Своя ширина');
        $screen = Kernel::get('/admin/pages/' . $pageId);

        Kernel::post('/admin/pages/' . $pageId, [
            '_token' => Kernel::tokenFrom($screen['body']),
            'op' => 'create-section',
            'kind' => 'band',
            'anchor' => 'svoya',
            'badge' => '',
            'title' => 'Своя ширина блока',
            'lead_text' => 'Текст',
            'width' => 'custom',
            'width_custom' => '900',
        ], ['follow' => false]);

        $section = $this->sectionHtml(Kernel::get('/svoya-shirota')['body'], 'svoya');
        self::assertStringContainsString('max-width: 900px', $section, 'Своя ширина обязана доехать до стиля');
    }

    /** Разметка секции по её якорю: от открывающей секции до её закрытия (не до конца страницы —
     * иначе в срез попадает подвал с его контейнером). */
    private function sectionHtml(string $body, string $anchor): string
    {
        $start = strpos($body, 'id="' . $anchor . '"');
        self::assertNotFalse($start, 'Секция «' . $anchor . '» не напечаталась');

        $from = (int) strrpos(substr($body, 0, $start), '<section');
        $end = strpos($body, '</section>', $start);
        self::assertNotFalse($end, 'Секция «' . $anchor . '» не закрыта');

        return substr($body, $from, $end + strlen('</section>') - $from);
    }
}
