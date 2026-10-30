<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Вклад живых новостей в конструкторе страниц (§5 дизайна слоя): секция чужого вида news_feed
 * рендерится разделом «Новости». Раздел выключен — секция пустое место, страница живёт дальше;
 * включён — лента карточек по настройкам {count, category}; настройки кривые — пустое место и
 * запись в журнал, а не падение страницы.
 */
final class PagesNewsTabTest extends TestCase
{
    /** Маркер карточки ленты новостей: class тега article из partial карточки. */
    private const CARD_MARK = 'flex flex-col overflow-hidden rounded-xl';

    /** Путь журнала прогона: его же читает тест кривых настроек; файл создаёт только этот прогон. */
    private static string $logFile;

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        // TestDb::reset() поднимает только ядро: раздел «Новости» не установлен, и вклад обязан
        // молчать, пока его нет. Три секции вклада на одной странице — общая лента с пределом,
        // лента по рубрике и блок с кривым числом материалов.
        $page = createPage([
            'title' => 'Лента на странице',
            'slug' => 'lenta',
            'description' => '',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());

        $values = static fn (string $title, array $settings): array => [
            'kind' => 'news_feed',
            'anchor' => '',
            'badge' => '',
            'title' => $title,
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => $settings,
            'visible' => true,
        ];

        createSection((string) $page['id'], $values('Лента школы', ['count' => '2', 'category' => '', 'layout' => '']), nowUtc());
        createSection((string) $page['id'], $values('Новости поступления', ['count' => '3', 'category' => 'postuplenie', 'layout' => '']), nowUtc());
        createSection((string) $page['id'], $values('Сломанный блок', ['count' => 'много', 'category' => '', 'layout' => '']), nowUtc());

        // Журнал прогона: файл чист от «хвостов» прошлых запусков — если тест кривых настроек
        // найдёт запись, она написана этим прогоном. Сервер поднимается отдельным процессом
        // (proc_open), поэтому putenv тестового процесса туда не доезжает: путь едет в
        // Kernel::start как env-префикс команды сервера.
        self::$logFile = sys_get_temp_dir() . '/pages-news-tab-test.log';
        @unlink(self::$logFile);
        Kernel::start(['CMS_LOG_PATH' => self::$logFile]);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function input(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Вечер встречи',
            'slug' => '',
            'lead' => 'Короткий лид про вечер.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => [],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    public function testВыключенныйРазделОставляетПустоеМесто(): void
    {
        $page = Kernel::get('/lenta');

        self::assertSame(200, $page['status'], 'страница живёт дальше без раздела — не 500 и не 404');
        self::assertStringContainsString('На этой странице пока нет блоков.', $page['body'], 'секция вклада не печатается вовсе');
        self::assertStringNotContainsString('Лента школы', $page['body'], 'заголовок вклада не рисуется без раздела');
        self::assertStringNotContainsString('/news/', $page['body'], 'ссылки на раздел не появляются без раздела');
    }

    public function testВключённыйРазделРисуетЛентуПоНастройкам(): void
    {
        installApp('news');

        $editor = ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];
        createItem(self::input(['slug' => 'vecher-vstrechi']), $editor);
        createItem(self::input(['title' => 'Второй вечер', 'slug' => 'vtoroy-vecher']), $editor);
        createItem(self::input([
            'title' => 'Приём в первый класс',
            'slug' => 'priyom-v-pervyy-klass',
            'categoryId' => categoryIdOf('postuplenie'),
        ]), $editor);
        createItem(self::input([
            'title' => 'Невидимая заметка',
            'slug' => 'nevidimaya',
            'status' => NEWS_STATUS['DRAFT'],
        ]), $editor);

        $page = Kernel::get('/lenta');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Лента школы', $page['body'], 'первый вклад рисует свой заголовок');
        self::assertStringContainsString('Новости поступления', $page['body'], 'второй вклад рисует свой заголовок');

        // Предел «2» у общей ленты плюс одна рубрика «Поступление» — ровно три карточки; без
        // разбора настроек было бы 4 (3 + 1) или больше.
        self::assertSame(3, substr_count((string) $page['body'], self::CARD_MARK), 'настройки count и category честно режут подборку');

        self::assertStringContainsString('Приём в первый класс', $page['body'], 'рубриковый блок показывает материал своей рубрики');
        self::assertStringContainsString('Второй вечер', $page['body'], 'общий блок показывает материал общей ленты');
        self::assertStringNotContainsString('Невидимая заметка', $page['body'], 'черновик в ленту вклада не попадает');
        self::assertStringContainsString('href="/news/priyom-v-pervyy-klass"', $page['body'], 'карточка ведёт на адрес материала');
    }

    public function testКривыеНастройкиПустоеМестоИЖурнал(): void
    {
        $page = Kernel::get('/lenta');

        self::assertSame(200, $page['status'], 'кривые настройки не роняют страницу');
        self::assertStringNotContainsString('Сломанный блок', $page['body'], 'кривой блок не печатается');
        self::assertSame(3, substr_count((string) $page['body'], self::CARD_MARK), 'честные блоки печатаются как раньше');

        // Журнал прогона пишется в файл, назначенный в setUpBeforeClass: чтение честное —
        // запись появляется только если серверный процесс получил путь через env.
        $log = (string) @file_get_contents(self::$logFile);

        self::assertStringContainsString('news.pages_feed', $log, 'причина молчания блока записана в журнал');
    }
}
