<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Обобщающая проверка связки «страница помечена `noindex` ⇒ заголовок `Cache-Control` запрещает
// хранение» (задача-кандидат №181 широкой рецензии ветки). Инвариант держится частями: механизм в
// `core/template.php` ставит заголовок на каждом `render()` с меткой, пять отдельных проверок держат
// экраны ядра, одна — установщик, а два автономных экрана («Сайт не установлен» и 500) собираются
// мимо `render()` и задают заголовок сами. Новый экран с меткой, забытый в списке, ворота проходил
// именно потому, что обхода не было; эта проверка его не пропустит.
//
// Список адресов — план обхода (`plan()` для строк таблицы маршрутов и `additions()` для двух адресов
// вне неё). План сверяется с живой таблицей маршрутов в обе стороны: строка таблицы без строки плана
// краснит и называет её, строка плана без своей строки таблицы краснит как устаревшая. Ключ плана
// различает позицию записи — «метод шаблон» для первой и «метод шаблон №2» для повторной на том же
// шаблоне, поэтому второй обработчик на занятом адресе не схлопнется с первым.
//
// Каждый вариант адреса несёт ожидаемый исход (помеченный экран, открытый экран без метки,
// перенаправление) и ожидаемый статус: факт сверяется с планом, и адрес не может выпасть из проверки
// молча, уйдя в редирект. Заголовок запрета хранения сверяется ТОЧНО (равен `no-store`), а не по
// вхождению: лимитер сессии в контейнере присылает «no-store, no-cache, must-revalidate», и сверка по
// вхождению не отличала бы правило ядра от лимитера. Принятая в репозитории форма точной сверки —
// `tests/http/ErrorScreenTest.php:43-48`, `tests/http/AdminAppsTest.php:100-113`,
// `tests/http/SiteNotInstalledTest.php:50`.
final class NoIndexNoStoreSweepTest extends TestCase
{
    private const EDITOR_EMAIL = 'obhod@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    // Исходы адреса: третий нужен ровно затем, чтобы перенаправление перестало быть молча прощённым.
    private const MARKED = 'помеченный экран';
    private const OPEN = 'открытый экран без метки';
    private const REDIRECT = 'перенаправление';

    // Объём обхода выверен по плану и таблице маршрутов, а не «больше нуля»: 21 строка таблицы
    // метода GET (ядро 6, раздел 10, фикстуры 5) даёт 28 вариантов, ещё 2 адреса вне таблицы —
    // всего 30 запросов, из них помеченных 23 (ядро 2, раздел 14, фикстуры 5, добавки 2).
    // Числа не подгоняются под прогон: любое расхождение плана с деревом краснит именем адреса,
    // а молча удалённый из списка вариант краснит нижней границей объёма.
    private const VARIANTS_TOTAL = 30;
    private const MARKED_TOTAL = 23;

    // Канал «сайт не установлен» отвечает одним экраном на любой адрес маршрутизатора, поэтому его
    // ожидаемый исход — один на весь список: помеченный экран со статусом 503. Единственное
    // исключение названо: адрес установщика обслуживается файлом в документ-корне мимо
    // `public/index.php` и остаётся формой 200 с меткой.
    private const NOT_INSTALLED_STATUS = 503;
    private const NOT_INSTALLED_FILE_ADDRESSES = ['вне таблицы: установщик' => 200];

    /** @var array<string, list<array{path: string, outcome: string, status: int}>> позиция обхода: ключ => варианты */
    private static array $addresses = [];

    /** @var array<string, string> токены адресов: имя подстановки => значение из базы после посадки */
    private static array $tokens = [];

    /** @var list<string> строки таблицы маршрутов метода GET, для которых в плане нет строки */
    private static array $unplanned = [];

    /** @var list<string> строки плана, которым в таблице маршрутов места не нашлось */
    private static array $stale = [];

    /** @var list<string> адреса, в которых остался неразложенный плейсхолдер */
    private static array $unresolved = [];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::unlockSetup();

        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');
        installApp('news');

        // Материалы садятся штатным сохранением раздела: лента обязана влезать на вторую страницу,
        // иначе ветка «метка из параметра `page`» не проверяется вовсе.
        $items = self::seedItems();

        self::$tokens = self::buildTokens($items);

        // Отметка CMS_TEST_OMITTED_HANDLER поднимает в фикстуре маршрут с отсутствующим
        // обработчиком — тот же приём, что в ErrorScreenTest: экран 500 идёт мимо `render()`
        // и обязан попасть в этот же обход. Каталог фикстур передаётся серверу, чтобы таблица
        // маршрутов в процессе теста и на стенде совпадали.
        Kernel::start([
            'CMS_EXTRA_APPS_DIR' => dirname(__DIR__, 2) . '/tests/fixtures/apps',
            'CMS_TEST_OMITTED_HANDLER' => '1',
        ]);

        // Сеанс редактора: приватные экраны админки (все с меткой) иначе просто не открыться,
        // а GET /admin/apps под редактором даёт ещё и экран отказа 403 — тоже вне `render()`.
        $login = Kernel::get('/login');
        $entered = Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($login['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
        self::assertSame(302, $entered['status'], 'Редактор не вошёл: обход приватных экранов повиснет на редиректах');

        routesReset();
        registerCoreRoutes();
        registerAppRoutes();
        self::buildAddresses();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    public function testПланОбходаНазванДляКаждойСтрокиТаблицыМаршрутов(): void
    {
        // Тот же класс «тихого самоисключения», который ловили трижды: опустевшая таблица, пустой
        // список или план, разошедшийся с деревом, дают обход нуля адресов, зелёный как ни в чём
        // не бывало.
        self::assertNotSame([], routeTable(), 'Таблица маршрутов пуста — связку проверяет не на чём, это падение, а не зелёный прогон');
        self::assertNotSame([], self::$addresses, 'Список адресов обхода пуст — проверять связку не на чём');
        self::assertSame(
            [],
            self::$unplanned,
            'Строки таблицы маршрутов метода GET без строки плана обхода: ' . implode(', ', self::$unplanned)
                . ' — новый экран обязан попасть в обход, добавь для него вариант с ожидаемым исходом'
        );
        self::assertSame(
            [],
            self::$stale,
            'Строки плана обхода, которым нет места в таблице маршрутов: ' . implode(', ', self::$stale)
                . ' — маршрут снят или переписан, сними и строку плана'
        );
        self::assertSame(
            [],
            self::$unresolved,
            'Адреса плана с неразложенным плейсхолдером: ' . implode(', ', self::$unresolved)
                . ' — фикстура значения не подставилась'
        );
    }

    public function testОбъёмСпискаАдресовВыверенПоПлану(): void
    {
        $getRows = 0;
        foreach (routeTable() as $route) {
            if ($route['method'] === 'GET') {
                $getRows++;
            }
        }

        // Число позиций списка обязано равняться числу строк таблицы с методом запроса к страницам
        // плюс добавки вне таблицы: ключ без позиции сворачивал бы вторую запись того же шаблона
        // с первой, и её экран не запрашивался бы ни разу.
        self::assertSame(
            $getRows + count(self::additions()),
            count(self::$addresses),
            'Список адресов обхода короче таблицы маршрутов: часть записей свёрнута или выпала'
        );

        // Нижняя граница объёма — явное число, выверенное по плану, а не «больше нуля»: вариант
        // с параметром, молча удалённый из списка, краснит именно здесь.
        self::assertSame(self::VARIANTS_TOTAL, self::variantCount(), 'Число вариантов обхода разошлось с выверенным объёмом плана');
        self::assertCount(self::MARKED_TOTAL, self::expectedMarkedIdentities(), 'Число помеченных позиций плана разошлось с выверенным');
    }

    public function testСвязкаNoindexNoStoreНаУстановленномСайте(): void
    {
        // Первой строкой — явное утверждение об установленности: без него этот обход держится
        // только на порядке объявления методов в файле. Стоит набору поехать в другом порядке
        // (или восстановлению схемы ошибиться), как «установленный» канал пройдёт по экранам 503
        // и останется зелёным, проверяя один экран вместо тридцати.
        self::assertTrue(isSiteInstalled(), 'Обход установленной схемы идёт по базе без установочной отметки: каждый адрес отвечает экраном 503, и этот канал подменяет собой проверку неустановленного сайта');

        $sweep = self::sweep(true);

        self::assertSame(
            [],
            $sweep['violations'],
            'Нарушения связки «метка запрета индексирования ⇒ `Cache-Control: no-store`» на установленном сайте:'
        );
        self::assertSame(self::VARIANTS_TOTAL, $sweep['checked'], 'Обход запросил не весь список адресов плана — проверка деградирует в ничего');
        self::assertSame(
            self::expectedMarkedIdentities(),
            $sweep['markedIdentities'],
            'Список помеченных адресов разошёлся с планом: метка запрета индексирования пропала с экрана или появилась там, где её не было'
        );
        self::assertSame(self::MARKED_TOTAL, $sweep['marked'], 'Число помеченных адресов на установленном сайте разошлось с выверенным');
    }

    public function testСвязкаNoindexNoStoreНаНеустановленномСайте(): void
    {
        // Экран «Сайт не установлен» — один из двух экранов, идущих мимо `render()`
        // (`partial()` + `respond()` в `public/index.php`): централизованный механизм его не
        // касается, а до `startSession()` лимитер сессии тоже молчит. Проверка снимает таблицу —
        // признак установки — и прогоняет тот же список: каждый адрес обязан отдать 503
        // с согласованной парой запретов, кроме названного адреса установщика.
        try {
            run('DROP TABLE IF EXISTS ' . t('app_installation'));
            self::assertFalse(isSiteInstalled(), 'После снятия таблицы установщика сайт обязан числиться неустановленным');

            $home = Kernel::get('/');
            self::assertSame(503, $home['status'], 'Неустановленный сайт обязан отвечать подсказкой на главной');

            $sweep = self::sweep(false);

            self::assertSame(
                [],
                $sweep['violations'],
                'Экраны неустановленного сайта разошлись с ожидаемым исходом (помеченный экран 503, адрес установщика — форма 200):'
            );
            self::assertSame(self::VARIANTS_TOTAL, $sweep['checked'], 'Обход неустановленной схемы не запросил весь список адресов');
            self::assertSame(
                self::VARIANTS_TOTAL,
                $sweep['marked'],
                'На неустановленной схеме помеченным обязан быть каждый адрес обхода: часть экранов потеряла метку запрета индексирования'
            );
        } finally {
            // Схема восстанавливается сразу: следующий набор должен застать целую базу,
            // а не чужие таблицы (тот же порядок, что в SiteNotInstalledTest).
            TestDb::reset();
        }
    }

    /**
     * Один обход списка: для каждого варианта адреса сверяется ожидаемый исход (помеченный экран,
     * открытый экран без метки, перенаправление), ожидаемый статус и — для помеченных — точное
     * значение заголовка запрета хранения.
     *
     * @return array{checked: int, marked: int, violations: list<string>, markedIdentities: list<string>}
     */
    private static function sweep(bool $siteInstalled): array
    {
        $checked = 0;
        $marked = 0;
        $violations = [];
        $markedIdentities = [];

        foreach (self::$addresses as $key => $variants) {
            foreach ($variants as $variant) {
                $identity = $key . ' → ' . $variant['path'];
                $response = Kernel::get($variant['path']);
                $checked++;

                // Мёртвый ответ (обрыв соединения: статус 0, пустое тело, без редиректа) не должен
                // проходить как «не помечен — нечего проверять»: это тот же тихий пропуск.
                if ($response['status'] === 0 || ($response['body'] === '' && ($response['headers']['location'] ?? '') === '')) {
                    $violations[] = $identity . ': сервер не ответил ни страницей, ни редиректом (статус ' . $response['status'] . ')';
                    continue;
                }

                $actual = self::outcome($response);
                $expectedOutcome = $siteInstalled ? $variant['outcome'] : self::MARKED;
                $expectedStatus = $siteInstalled
                    ? $variant['status']
                    : (self::NOT_INSTALLED_FILE_ADDRESSES[$key] ?? self::NOT_INSTALLED_STATUS);

                if ($actual !== $expectedOutcome) {
                    $violations[] = sprintf(
                        '%s: ожидался %s со статусом %d, а получен %s со статусом %d',
                        $identity,
                        $expectedOutcome,
                        $expectedStatus,
                        $actual,
                        $response['status']
                    );

                    continue;
                }

                if ($response['status'] !== $expectedStatus) {
                    $violations[] = sprintf(
                        '%s: исход «%s» сошёлся, но статус %d вместо ожидаемого %d',
                        $identity,
                        $actual,
                        $response['status'],
                        $expectedStatus
                    );
                }

                if ($actual !== self::MARKED) {
                    continue;
                }

                $marked++;
                $markedIdentities[] = $identity;

                // Точная сверка, а не «содержит»: значение лимитера сессии («no-store, no-cache,
                // must-revalidate») тоже содержит запрет хранения, и по вхождению проверка не
                // отличала правило ядра от лимитера — снятый заголовок в `core/template.php`
                // обходил её зелёным.
                $cacheControl = $response['headers']['cache-control'] ?? '';
                if ($cacheControl !== 'no-store') {
                    $violations[] = sprintf(
                        '%s: страница помечена запретом индексирования со статусом %d, а `Cache-Control` — «%s»; сверяется точное значение `no-store`',
                        $identity,
                        $response['status'],
                        $cacheControl
                    );
                }
            }
        }

        return [
            'checked' => $checked,
            'marked' => $marked,
            'violations' => $violations,
            'markedIdentities' => $markedIdentities,
        ];
    }

    /**
     * Фактический исход ответа: перенаправление — по статусу и заголовку `Location`, метка — по
     * строке шаблона или заголовку `X-Robots-Tag`, остальное — открытый экран.
     *
     * @param array{status: int, body: string, headers: array<string, string>} $response
     */
    private static function outcome(array $response): string
    {
        if ($response['status'] >= 300 && $response['status'] < 400 && ($response['headers']['location'] ?? '') !== '') {
            return self::REDIRECT;
        }

        $marked = preg_match('/<meta\s[^>]*name="robots"[^>]*content="[^"]*noindex/i', $response['body']) === 1
            || str_contains(strtolower($response['headers']['x-robots-tag'] ?? ''), 'noindex');

        return $marked ? self::MARKED : self::OPEN;
    }

    /**
     * План обхода по строкам таблицы маршрутов: ключ — «метод шаблон» (для повторной записи того же
     * шаблона — «метод шаблон №2»), значение — варианты адреса с ожидаемым исходом и статусом.
     * Пути вариантов пишутся токенами (`{item}`, `{category}` и далее), которые разлагает
     * `buildTokens()` по данным из базы.
     *
     * @return array<string, list<array{path: string, outcome: string, status: int}>>
     */
    private static function plan(): array
    {
        return [
            // Ядро: главная и служебные адреса индексироваться не запрещают, вход под сеансом
            // редактора уезжает в админку, сводка админки помечена, «Приложения» под редактором —
            // экран отказа 403, собираемый `renderToString()` и `respond()`.
            'GET /' => [
                ['path' => '/', 'outcome' => self::OPEN, 'status' => 200],
            ],
            'GET /login' => [
                ['path' => '/login', 'outcome' => self::REDIRECT, 'status' => 302],
            ],
            'GET /admin' => [
                ['path' => '/admin', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /admin/apps' => [
                ['path' => '/admin/apps', 'outcome' => self::MARKED, 'status' => 403],
            ],
            'GET /sitemap.xml' => [
                ['path' => '/sitemap.xml', 'outcome' => self::OPEN, 'status' => 200],
            ],
            'GET /robots.txt' => [
                ['path' => '/robots.txt', 'outcome' => self::OPEN, 'status' => 200],
            ],

            // Раздел «Новости»: ветки, где метку ставит сам обработчик из параметра запроса,
            // стоят отдельными вариантами — лента со второй страницей и с поиском, рубрика со
            // второй страницей, закрытый материал в предпросмотре редактора.
            'GET /news' => [
                ['path' => '/news', 'outcome' => self::OPEN, 'status' => 200],
                ['path' => '/news?page=2', 'outcome' => self::MARKED, 'status' => 200],
                ['path' => '/news?q=obhod', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /news/c/{slug}' => [
                ['path' => '/news/c/{category}', 'outcome' => self::OPEN, 'status' => 200],
                ['path' => '/news/c/{category}?page=2', 'outcome' => self::MARKED, 'status' => 200],
                ['path' => '/news/c/net-takogo-rubriki', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /news/t/{slug}' => [
                ['path' => '/news/t/{tag}', 'outcome' => self::MARKED, 'status' => 200],
                ['path' => '/news/t/net-takogo-tega', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /news/{slug}' => [
                ['path' => '/news/{item}', 'outcome' => self::OPEN, 'status' => 200],
                // Ветка `notFound()` того же обработчика: отказ тоже помечен и обязан быть без хранения.
                ['path' => '/news/net-takogo-adresa', 'outcome' => self::MARKED, 'status' => 404],
                ['path' => '/news/{draft}?draft=1', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /news/admin' => [
                ['path' => '/news/admin', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /news/admin/items' => [
                ['path' => '/news/admin/items', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /news/admin/items/new' => [
                ['path' => '/news/admin/items/new', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /news/admin/items/{id}' => [
                // Форма правки открывается по номеру записи, а не по адресу материала.
                ['path' => '/news/admin/items/{id}', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /news/admin/categories' => [
                ['path' => '/news/admin/categories', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /news/admin/tags' => [
                ['path' => '/news/admin/tags', 'outcome' => self::MARKED, 'status' => 200],
            ],

            // Фикстурные разделы не установлены, поэтому гейт маршрута отвечает по ним 404 —
            // тот же помеченный экран, собранный ядром.
            'GET /broken' => [
                ['path' => '/broken', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /demo' => [
                ['path' => '/demo', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /demo/admin' => [
                ['path' => '/demo/admin', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /demo/closure' => [
                ['path' => '/demo/closure', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /future' => [
                ['path' => '/future', 'outcome' => self::MARKED, 'status' => 404],
            ],
        ];
    }

    /**
     * Адреса вне таблицы маршрутов, каждый назван с причиной: `/setup.php` обслуживается файлом
     * в документ-корне (в реестр маршрутов он не попадает принципиально), а маршрут с отсутствующим
     * обработчиком регистрируется на сервере только при отметке окружения — в таблице процесса
     * тестера его нет.
     *
     * @return array<string, list<array{path: string, outcome: string, status: int}>>
     */
    private static function additions(): array
    {
        return [
            'вне таблицы: установщик' => [
                ['path' => '/setup.php', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'вне таблицы: экран внутреннего отказа' => [
                ['path' => '/test-omitted-handler', 'outcome' => self::MARKED, 'status' => 500],
            ],
        ];
    }

    /**
     * Идентичности вариантов, которые план считает помеченными, — в том же порядке, в котором их
     * обходит `sweep()`: расхождение называет адрес, а не только число.
     *
     * @return list<string>
     */
    private static function expectedMarkedIdentities(): array
    {
        $out = [];

        foreach (self::$addresses as $key => $variants) {
            foreach ($variants as $variant) {
                if ($variant['outcome'] === self::MARKED) {
                    $out[] = $key . ' → ' . $variant['path'];
                }
            }
        }

        return $out;
    }

    /** @return int */
    private static function variantCount(): int
    {
        $count = 0;
        foreach (self::$addresses as $variants) {
            $count += count($variants);
        }

        return $count;
    }

    /**
     * Список адресов: каждая строка таблицы маршрутов метода GET обязана иметь свою строку плана,
     * повторная запись на занятом шаблоне получает ключ с позицией. План без своей строки таблицы
     * считается устаревшим — так список не сужается молча.
     */
    private static function buildAddresses(): void
    {
        self::$addresses = [];
        self::$unplanned = [];
        self::$stale = [];
        self::$unresolved = [];

        $plan = self::plan();
        /** @var array<string, int> $occurrences */
        $occurrences = [];

        foreach (routeTable() as $route) {
            if ($route['method'] !== 'GET') {
                continue;
            }

            $base = $route['method'] . ' ' . $route['pattern'];
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
            $key = $occurrences[$base] === 1 ? $base : $base . ' №' . $occurrences[$base];

            if (!isset($plan[$key])) {
                self::$unplanned[] = $key;
                continue;
            }

            self::$addresses[$key] = self::resolveTokens($plan[$key], $key);
        }

        foreach (self::additions() as $key => $variants) {
            self::$addresses[$key] = self::resolveTokens($variants, $key);
        }

        foreach (array_keys($plan) as $key) {
            if (!isset(self::$addresses[$key])) {
                self::$stale[] = $key;
            }
        }
    }

    /**
     * Подстановка токенов в пути вариантов: неразложенный плейсхолдер остаётся в списке и краснит
     * проверку полноты, а не превращает адрес в молчаливый пропуск.
     *
     * @param list<array{path: string, outcome: string, status: int}> $variants
     * @return list<array{path: string, outcome: string, status: int}>
     */
    private static function resolveTokens(array $variants, string $key): array
    {
        $resolved = [];

        foreach ($variants as $variant) {
            $path = $variant['path'];
            foreach (self::$tokens as $token => $value) {
                $path = str_replace('{' . $token . '}', $value, $path);
            }

            if (preg_match('/\{[^}]+\}/', $path) === 1) {
                self::$unresolved[] = $key . ' → ' . $path;
            }

            $resolved[] = ['path' => $path, 'outcome' => $variant['outcome'], 'status' => $variant['status']];
        }

        return $resolved;
    }

    /**
     * Токены адресов: реальные данные раздела — адрес опубликованного материала, черновика,
     * рубрики, тега и номер записи читаются из базы после посадки, а не переписываются руками.
     *
     * @param array{item: array{ok: bool, notice: string, error: string, id: string, slug: string}, draft: array{ok: bool, notice: string, error: string, id: string, slug: string}} $items
     * @return array<string, string>
     */
    private static function buildTokens(array $items): array
    {
        $category = one('SELECT slug FROM ' . t('news_category') . ' WHERE id = ?', [categoryIdOf('zhizn-shkoly')]);
        self::assertIsArray($category, 'Фикстура: видимая рубрика «zhizn-shkoly» не найдена в схеме раздела');

        $tag = one(
            'SELECT tag.slug FROM ' . t('news_tag') . ' AS tag'
            . ' JOIN ' . t('news_item_tag') . ' AS link ON link.tag_id = tag.id'
            . ' WHERE link.item_id = ? ORDER BY tag.slug LIMIT 1',
            [$items['item']['id']]
        );
        self::assertIsArray($tag, 'Фикстура: у созданного материала нет ни одного тега, страница тега недоступна обходу');

        return [
            'item' => $items['item']['slug'],
            'id' => $items['item']['id'],
            'draft' => $items['draft']['slug'],
            'category' => (string) $category['slug'],
            'tag' => (string) $tag['slug'],
        ];
    }

    /**
     * Фикстуры раздела: опубликованный материал (его адрес открыт без метки), ещё ровно столько
     * же опубликованных, сколько не хватает до второй страницы ленты, и закрытый черновик для
     * предпросмотра редактора. Все — в видимой рубрике, чтобы страница рубрики тоже влезала
     * на второй адрес.
     *
     * @return array{item: array{ok: bool, notice: string, error: string, id: string, slug: string}, draft: array{ok: bool, notice: string, error: string, id: string, slug: string}}
     */
    private static function seedItems(): array
    {
        $editor = ['id' => 'obhod-1', 'email' => self::EDITOR_EMAIL];

        $item = createItem(self::input([
            'title' => 'Материал для обхода',
            'slug' => 'obhodnyj-material',
            'tags' => ['Выпускник'],
        ]), $editor);
        self::assertSame('', $item['error'], 'Фикстурный материал не создан: ' . $item['error']);

        for ($n = 2; $n <= NEWS_PER_PAGE + 1; $n++) {
            $extra = createItem(self::input([
                'title' => 'Материал ленты ' . $n,
                'slug' => 'obhodnyj-material-' . $n,
                'tags' => [],
            ]), $editor);
            self::assertSame('', $extra['error'], 'Фикстура ленты №' . $n . ' не создана: ' . $extra['error']);
        }

        $draft = createItem(self::input([
            'title' => 'Черновик для предпросмотра',
            'slug' => 'obhodnyj-chernovik',
            'status' => NEWS_STATUS['DRAFT'],
            'tags' => [],
        ]), $editor);
        self::assertSame('', $draft['error'], 'Фикстурный черновик не создан: ' . $draft['error']);

        return ['item' => $item, 'draft' => $draft];
    }

    /**
     * Значения полей материала: один источник для всех фикстур, чтобы разница была только в том,
     * что переопредено вызывающим кодом.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function input(array $overrides = []): array
    {
        return array_merge([
            'lead' => 'Фикстура связки запретов.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Абзац фикстуры.']],
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

    // Замок установщика живёт в рабочем каталоге: без его снятия /setup.php отдавал бы 404
    // вместо формы, и обход проверял бы не тот экран (тот же приём, что в SetupTest).
    private static function unlockSetup(): void
    {
        $lock = dirname(__DIR__, 2) . '/public/install.lock';
        if (is_file($lock)) {
            unlink($lock);
        }
    }
}
