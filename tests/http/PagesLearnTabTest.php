<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Вклад раздела «Курсы» в конструкторе страниц: секция чужого вида learn_levels печатает карту
 * уровней школы. Раздел выключен — секция пустое место, страница живёт дальше; включён — карточки
 * ступеней с числом уроков из живых таблиц курса; кривое число уровней — пустое место и запись
 * в журнал, а не падение страницы.
 */
final class PagesLearnTabTest extends TestCase
{
    /** Маркер карточки уровня: класс карточки этого вклада. */
    private const CARD_MARK = 'rounded-xl border border-ink/10 bg-cream p-6';

    /** Путь журнала прогона: его читает тест кривых настроек; файл создаёт только этот прогон. */
    private static string $logFile;

    /** Идентификатор страницы прогона: по нему открывается конструктор в админке. */
    private static string $pageId = '';

    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        // TestDb::reset() поднимает только ядро: раздел «Курсы» не установлен, и вклад обязан
        // молчать, пока его нет. Три секции вклада на одной странице — карта из трёх ступеней
        // с кнопкой, карта всей программы без кнопки и блок с кривым числом уровней.
        $page = createPage([
            'title' => 'Программа по уровням',
            'slug' => 'programma',
            'description' => '',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());
        self::$pageId = (string) $page['id'];

        $values = static fn (array $settings): array => [
            'kind' => LEARN_PAGES_LEVELS_KIND,
            'anchor' => '',
            'badge' => 'Программа',
            'title' => 'Программа по уровням',
            'lead_text' => 'Шесть ступеней от стартовой до уверенного общения.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => $settings,
            'visible' => true,
        ];

        createSection((string) $page['id'], $values([
            'count' => '3',
            'cta_label' => 'Пройти тест',
            'cta_href' => '/placement-test',
        ]), nowUtc());
        createSection((string) $page['id'], $values([
            'count' => '',
            'cta_label' => '',
            'cta_href' => '',
        ]), nowUtc());
        createSection((string) $page['id'], $values([
            'count' => 'много',
            'cta_label' => 'Сломанная кнопка',
            'cta_href' => '',
        ]), nowUtc());

        // Журнал прогона: файл чист от «хвостов» прошлых запусков, а сервер поднимается отдельным
        // процессом (proc_open), поэтому путь едет в Kernel::start как env-префикс команды сервера.
        self::$logFile = sys_get_temp_dir() . '/pages-learn-tab-test.log';
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

    public function testВыключенныйРазделОставляетПустоеМесто(): void
    {
        $page = Kernel::get('/programma');

        self::assertSame(200, $page['status'], 'страница живёт дальше без раздела — не 500 и не 404');
        self::assertStringContainsString('На этой странице пока нет блоков.', $page['body'], 'секция вклада не печатается вовсе');
        self::assertStringNotContainsString('Pre-A1', $page['body'], 'ступени не печатаются без раздела');
        self::assertSame(0, substr_count((string) $page['body'], self::CARD_MARK), 'карточки не печатаются без раздела');
    }

    public function testВключённыйРазделПечатаетКартуУровней(): void
    {
        installApp('learn');

        $page = Kernel::get('/programma');

        self::assertSame(200, $page['status']);
        // Надзаголовок и лид печатает сам вклад: заголовок «Программа по уровням» печатает ещё и
        // шапка страницы, поэтому проверка идёт по полям, которые приходят только из блока.
        self::assertStringContainsString(
            '<p class="text-sm font-semibold uppercase tracking-wide text-ink/60">Программа</p>',
            $page['body'],
            'вклад рисует шапку секции'
        );
        self::assertStringContainsString('Шесть ступеней от стартовой до уверенного общения.', $page['body'], 'лид секции на месте');
        self::assertStringContainsString('Pre-A1', $page['body'], 'стартовая ступень программы видна');
        self::assertStringContainsString('C1', $page['body'], 'верхняя ступень программы видна');
        self::assertMatchesRegularExpression('/\d+ урок(а|ов)?/', (string) $page['body'], 'у ступени названо число уроков');

        // Предел «3» у первой карты плюс вся программа у второй: 3 + 7 карточек, кривой блок молчит.
        self::assertSame(
            3 + LEARN_PAGES_LEVELS_LIMIT,
            substr_count((string) $page['body'], self::CARD_MARK),
            'настройка числа уровней честно режет карту'
        );

        // Кнопка печатается только целой парой «подпись + адрес»: у второй карты пары нет,
        // поэтому кнопка в ответе одна, а подпись кривого блока не появляется.
        self::assertStringContainsString('href="/placement-test"', $page['body'], 'кнопка ведёт на тест уровня');
        self::assertSame(1, substr_count((string) $page['body'], '/placement-test'), 'кнопка печатается один раз');
        self::assertStringNotContainsString('Сломанная кнопка', $page['body'], 'подпись без адреса не печатается');
    }

    public function testКривоеЧислоУровнейПустоеМестоИЖурнал(): void
    {
        $page = Kernel::get('/programma');

        self::assertSame(200, $page['status'], 'кривая настройка не роняет страницу');
        self::assertSame(
            3 + LEARN_PAGES_LEVELS_LIMIT,
            substr_count((string) $page['body'], self::CARD_MARK),
            'честные карты печатаются как раньше'
        );

        // Журнал прогона пишется в файл, назначенный в setUpBeforeClass: запись появляется только
        // если серверный процесс получил путь через env, поэтому чтение честное.
        $log = (string) @file_get_contents(self::$logFile);

        self::assertStringContainsString('learn.pages_levels_count', $log, 'причина молчания блока записана в журнал');
    }

    public function testКонструкторПредлагаетВидКартыИПоляНастроек(): void
    {
        $this->login();

        $screen = Kernel::get('/admin/pages/' . self::$pageId);
        $body = (string) $screen['body'];

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Курсы — карта уровней', $body, 'вид вклада виден в селекте конструктора');
        self::assertStringContainsString('value="' . LEARN_PAGES_LEVELS_KIND . '"', $body, 'значение опции совпадает с видом блока');
        self::assertStringContainsString('Настройки вклада', $body, 'поля настроек рисуются у секции своего вида');
        self::assertStringContainsString('name="settings[count]"', $body, 'настройка числа уровней на месте');
        self::assertStringContainsString('name="settings[cta_label]"', $body, 'подпись кнопки настраивается');
        self::assertStringContainsString('name="settings[cta_href]"', $body, 'адрес кнопки настраивается');
    }

    /** Вход редактора: конструктор страниц открыт праву «кто правит контентом». */
    private function login(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }
}
