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
// Список адресов — план обхода (`plan()` для строк таблицы маршрутов и `additions()` для двух
// позиций вне неё: установщик тремя адресами и экран внутреннего отказа). План сверяется с живой
// таблицей маршрутов в обе стороны: строка таблицы без строки плана
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

    // Объём обхода выверен по плану и таблице маршрутов, а не «больше нуля»: ко времени M5
    // было 57 вариантов из 44 строк таблицы метода GET, помеченных 44 из них. M6 добавляет
    // 4 маршрута (список и карточка Commerce API, список и правка webhooks) и 5 вариантов:
    // было 62 запроса, из них помеченных 49. C1 доски добавляет 9 маршрутов и 12 вариантов
    // (витрина, категория с промахом, регистрация, кабинет, новая форма, чужая правка,
    // карточка с промахом и черновиком, две админские страницы): всего 74, помеченных 57.
    // C3 добавляет профиль и избранное кабинета (помечены) и публичную страницу продавца
    // (открыта). C4 добавляет переписку и уведомления кабинета, форму жалобы и два экрана
    // админки — все помечены: всего 85 вариантов, помеченных 67. Числа не подгоняются под
    // прогон: любое расхождение плана с деревом краснит именем адреса, а не молчит.
    //
    // Адресов вне таблицы четыре: у установщика два экрана, и в плане их три
    // строки. `/setup.php` и `/setup.php?step=db` — один и тот же экран шага 1 (`setupStep()` в
    // `public/setup.php:38-43` признаёт шагом 2 только `admin`, всё остальное — шаг 1), но оба адреса
    // обязан знать план: человек набирает и короткий адрес, и адрес с шагом. Третий — шаг 2, он
    // закрыт гейтом отметки сессии и без отметки отвечает перенаправлением (причина — в
    // `additions()`).
    // Числа не подгоняются под прогон: любое расхождение плана с деревом краснит именем адреса,
    // а молча удалённый из списка вариант краснит нижней границей объёма.
    private const VARIANTS_TOTAL = 85;
    private const MARKED_TOTAL = 67;

    // Помеченных на неустановленной схеме — на один меньше объёма: канал 503 подменяет собой каждый
    // экран маршрутизатора, но адрес установщика обслуживается файлом и от признака установки не
    // зависит, поэтому шаг 2 остаётся перенаправлением и на этой схеме (85 − 1).
    private const MARKED_ON_NOT_INSTALLED = 84;

    // Канал «сайт не установлен» отвечает одним экраном на любой адрес маршрутизатора, поэтому его
    // ожидаемый исход — один на весь список: помеченный экран со статусом 503. Исключение названо
    // списком позиций плана, а не отдельной картой статусов: адрес установщика обслуживается файлом в
    // документ-корне мимо `public/index.php`, от признака установки не зависит вовсе, и его ожидаемые
    // исход и статус берутся из плана на обеих схемах. Карта статусов при трёх строках одной позиции
    // начала бы жить своей жизнью рядом с планом.
    private const NOT_INSTALLED_STATUS = 503;
    private const NOT_INSTALLED_FILE_KEYS = ['вне таблицы: установщик'];

    /** @var array<string, list<array{path: string, outcome: string, status: int, target?: string}>> позиция обхода: ключ => варианты */
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

        // Раздел «Магазин» идёт в тот же обход: CommerceHttpSetup поднимает две рубрики,
        // товар и черновик, а заказ-фикстура питает адрес подтверждения (номер 1 проставит
        // сам AUTO_INCREMENT колонки `number`).
        CommerceHttpSetup::install();
        CommerceHttpSetup::hideCategory();
        insert('commerce_order', [
            'id' => newId(),
            'customer_name' => 'Фикстура обхода',
            'customer_email' => 'obhod@school.dev',
            'customer_phone' => null,
            'address' => null,
            'comment' => null,
            'currency' => 'RUB',
            'total' => 28.00,
            'status' => 'NEW',
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        // Скидки М4 в обход: купон и промо-правило питают адреса правки — экран открывается
        // по идентификатору, а не по коду или названию.
        insert('commerce_coupon', [
            'id' => newId(),
            'code' => 'OBHOD-10',
            'kind' => 'PERCENT',
            'value' => 10.00,
            'min_total' => null,
            'expires_at' => null,
            'max_uses' => null,
            'used_count' => 0,
            'active' => 1,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);
        insert('commerce_promo', [
            'id' => newId(),
            'title' => 'Правило для обхода',
            'kind' => 'PERCENT',
            'value' => 5.00,
            'min_total' => null,
            'starts_at' => null,
            'ends_at' => null,
            'active' => 1,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        // Поставщик М5 в обход: экран правки питается идентификатором фикстуры.
        insert('commerce_supplier', [
            'id' => newId(),
            'title' => 'Поставщик для обхода',
            'note' => null,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        // Вебхук М6 в обход: экран правки питается идентификатором фикстуры. Приёмник
        // активен, но события в этом наборе не рождаются: заказы здесь не оформляются
        // и статусы не правятся — наружный curl не звенит.
        insert('commerce_webhook', [
            'id' => newId(),
            'url' => 'https://obhod.school.dev/webhooks/nova-orders',
            'secret' => 'obhod-secret-2026-long-enough-minus-need-40',
            'active' => 1,
            'last_state' => null,
            'last_at' => null,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        // Раздел «Доска объявлений» идёт в тот же обход: одна видимая категория, одно
        // опубликованное объявление и черновик продавца. Объявления принадлежат отдельному
        // продавцу, поэтому под редактором кабинет правки и черновик честно дают 404.
        installApp('classifieds');
        $sellerId = TestDb::seedUser('prodavec@obhod.school.dev', 'обход-продавца-1', 'seller');
        $categoryId = newId();
        insert('classifieds_category', [
            'id' => $categoryId,
            'parent_id' => null,
            'slug' => 'obhod-tehnika',
            'title' => 'Техника обхода',
            'order_index' => 1,
            'visible' => 1,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        foreach ([
            ['id' => newId(), 'slug' => 'obhod-obyyavlenie', 'title' => 'Объявление обхода', 'status' => 'PUBLISHED'],
            ['id' => newId(), 'slug' => 'obhod-chernovik', 'title' => 'Черновик обхода', 'status' => 'DRAFT'],
        ] as $listing) {
            insert('classifieds_listing', [
                'id' => $listing['id'],
                'owner_id' => $sellerId,
                'category_id' => $categoryId,
                'slug' => $listing['slug'],
                'title' => $listing['title'],
                'body' => 'Текст объявления для обхода.',
                'price' => 1000.00,
                'currency' => 'RUB',
                'negotiable' => 0,
                'location' => 'Москва',
                'image' => null,
                'status' => $listing['status'],
                'seo_title' => null,
                'seo_description' => null,
                'noindex' => 0,
                'created_at' => utcStamp(nowUtc()),
                'updated_at' => utcStamp(nowUtc()),
            ]);
        }

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
        // и останется зелёным, проверяя один экран вместо тридцати трёх.
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
        // с согласованной парой запретов, кроме позиции установщика — она обслуживается файлом и
        // берёт ожидание из плана на обеих схемах (`NOT_INSTALLED_FILE_KEYS`).
        try {
            run('DROP TABLE IF EXISTS ' . t('app_installation'));
            self::assertFalse(isSiteInstalled(), 'После снятия таблицы установщика сайт обязан числиться неустановленным');

            $home = Kernel::get('/');
            self::assertSame(503, $home['status'], 'Неустановленный сайт обязан отвечать подсказкой на главной');

            $sweep = self::sweep(false);

            self::assertSame(
                [],
                $sweep['violations'],
                'Экраны неустановленного сайта разошлись с ожидаемым исходом (помеченный экран 503, адрес установщика — по плану):'
            );
            self::assertSame(self::VARIANTS_TOTAL, $sweep['checked'], 'Обход неустановленной схемы не запросил весь список адресов');
            self::assertSame(
                self::MARKED_ON_NOT_INSTALLED,
                $sweep['marked'],
                'На неустановленной схеме помеченным обязан быть каждый адрес обхода, кроме шага 2 установщика под гейтом отметки сессии: часть экранов потеряла метку запрета индексирования'
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

                // Адрес установщика обслуживается файлом в документ-корне, поэтому его ожидание
                // берётся из плана на обеих схемах; остальным адресам неустановленная схема
                // подменяет ожидание одним экраном 503.
                $fromPlan = $siteInstalled || in_array($key, self::NOT_INSTALLED_FILE_KEYS, true);
                $expectedOutcome = $fromPlan ? $variant['outcome'] : self::MARKED;
                $expectedStatus = $fromPlan ? $variant['status'] : self::NOT_INSTALLED_STATUS;

                if ($actual !== $expectedOutcome) {
                    // Имена исходов разного рода («помеченный экран», «перенаправление»), поэтому
                    // подлежащие — неизменные слова, а имена стоят в кавычках: текст падения обязан
                    // читаться согласованно при любом подставленном имени.
                    $violations[] = sprintf(
                        '%s: ожидаемый исход — «%s» (статус %d), фактический — «%s» (статус %d)',
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

                if ($actual === self::REDIRECT) {
                    // План обязан назвать цель перенаправления: фактический заголовок `Location`
                    // признаётся соответствующим цели, только если равен ей или продолжается от неё по
                    // границе сегмента (цель плюс `/`), начала параметра (цель плюс `?`) либо конца
                    // параметра (цель плюс `&`). Голый префикс пропускал чужой адрес с общим началом —
                    // `/administration` засчитывался за цель `/admin`, и страж проходил на адресе,
                    // которого в плане нет. Границы `?` и `&` оставлены нарочно: `queryAppend()`
                    // дописывает в адрес параметр уведомления — `?` перед первым и `&` после него
                    // (`core/response.php:34-39`), поэтому законно усиленный редирект обязан проходить.
                    // Граница конца параметра не ослабляет сверку: цель, продолженная `&`, уже исчерпана,
                    // и `/setup.php?step=db2` под цель `/setup.php?step=db` не подойдёт — после `db` нет
                    // ни одной из трёх границ. Пустая цель — нарушение само по себе, оно ловит и второй
                    // адрес с исходом «перенаправление», если план забыт.
                    $expectedTarget = $variant['target'] ?? '';
                    if ($expectedTarget === '') {
                        $violations[] = sprintf(
                            '%s: адрес с исходом «перенаправление» не называет цель — у строки плана обязана быть указана ожидаемая цель заголовка `Location`',
                            $identity
                        );
                    } else {
                        $location = $response['headers']['location'] ?? '';
                        if ($location !== $expectedTarget
                            && !str_starts_with($location, $expectedTarget . '/')
                            && !str_starts_with($location, $expectedTarget . '?')
                            && !str_starts_with($location, $expectedTarget . '&')
                        ) {
                            $violations[] = sprintf(
                                '%s: перенаправление ведёт не в цель — `Location` «%s» не равен цели «%s» и не продолжается от неё по границе сегмента, начала или конца параметра',
                                $identity,
                                $location,
                                $expectedTarget
                            );
                        }
                    }
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
     * @return array<string, list<array{path: string, outcome: string, status: int, target?: string}>>
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
                ['path' => '/login', 'outcome' => self::REDIRECT, 'status' => 302, 'target' => '/admin'],
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
            'GET /news/admin/blocks' => [
                ['path' => '/news/admin/blocks', 'outcome' => self::MARKED, 'status' => 200],
            ],

            // Магазин: витрина, рубрика, корзина, форма состояния заказа и админка — семь
            // экранов без метки со статусом 200; отказ же (нет товара с таким адресом,
            // рубрика не видима, подтверждение без отметки сессии) обрабатывает notFound()
            // и потому помечен.
            'GET /shop' => [
                ['path' => '/shop', 'outcome' => self::OPEN, 'status' => 200],
            ],
            'GET /shop/c/{slug}' => [
                ['path' => '/shop/c/{shopcategory}', 'outcome' => self::OPEN, 'status' => 200],
                // Скрытая рубрика CommerceHttpSetup (ветка hideCategory): гейт видимости
                // обрабатывает notFound(), и отказ обязан быть с меткой.
                ['path' => '/shop/c/drobnost', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /shop/cart' => [
                ['path' => '/shop/cart', 'outcome' => self::OPEN, 'status' => 200],
            ],
            'GET /shop/order' => [
                ['path' => '/shop/order', 'outcome' => self::OPEN, 'status' => 200],
            ],
            'GET /shop/order/{number}' => [
                // Проверка требует отметку сессии: заказ №1 в базе есть (фикстура в setUpBeforeClass),
                // а сессия обхода не оформляла его — гейс уверенно отвечает помеченным 404.
                ['path' => '/shop/order/1', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /shop/{slug}' => [
                ['path' => '/shop/{product}', 'outcome' => self::OPEN, 'status' => 200],
                // Черновик «draft-tovar» из CommerceHttpSetup не в поиске витрины: адрес 404.
                ['path' => '/shop/net-takogo-tovara', 'outcome' => self::MARKED, 'status' => 404],
            ],
            // Commerce API М6: JSON-ответы идут без механики render(), но посылают пару
            // заголовков сами (X-Robots-Tag: noindex + Cache-Control: no-store), исход тот
            // же. Отсутствующий товар красит 404 этими же заголовками.
            'GET /shop/api/products' => [
                ['path' => '/shop/api/products', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /shop/api/products/{slug}' => [
                ['path' => '/shop/api/products/{product}', 'outcome' => self::MARKED, 'status' => 200],
                ['path' => '/shop/api/products/net-takogo-tovara', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /commerce/admin' => [
                ['path' => '/commerce/admin', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/products' => [
                ['path' => '/commerce/admin/products', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/products/new' => [
                ['path' => '/commerce/admin/products/new', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/products/{id}' => [
                ['path' => '/commerce/admin/products/{pid}', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/products/{id}/variants' => [
                ['path' => '/commerce/admin/products/{pid}/variants', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/categories' => [
                ['path' => '/commerce/admin/categories', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/categories/{id}' => [
                ['path' => '/commerce/admin/categories/{cid}', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/orders' => [
                ['path' => '/commerce/admin/orders', 'outcome' => self::MARKED, 'status' => 200],
            ],

            // Скидки М4: списки купонов и промо-правил, экран правки каждого объекта.
            'GET /commerce/admin/coupons' => [
                ['path' => '/commerce/admin/coupons', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/coupons/{id}' => [
                ['path' => '/commerce/admin/coupons/{coupon}', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/promos' => [
                ['path' => '/commerce/admin/promos', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/promos/{id}' => [
                ['path' => '/commerce/admin/promos/{promo}', 'outcome' => self::MARKED, 'status' => 200],
            ],

            // Поставщики и прайс-лист М5: справочник с правкой по идентификатору, экран
            // обмена ценами и сам файл скачиванием. CSV не ёт метки механики render(),
            // но посылает запрет индексации заголовком — исход тот же.
            'GET /commerce/admin/suppliers' => [
                ['path' => '/commerce/admin/suppliers', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/suppliers/{id}' => [
                ['path' => '/commerce/admin/suppliers/{supplier}', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/pricebook' => [
                ['path' => '/commerce/admin/pricebook', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/pricebook/export' => [
                ['path' => '/commerce/admin/pricebook/export', 'outcome' => self::MARKED, 'status' => 200],
            ],

            // Webhooks М6: список приёмников и экран правки выбранного по идентификатору.
            'GET /commerce/admin/webhooks' => [
                ['path' => '/commerce/admin/webhooks', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /commerce/admin/webhooks/{id}' => [
                ['path' => '/commerce/admin/webhooks/{webhook}', 'outcome' => self::MARKED, 'status' => 200],
            ],

            // Доска объявлений: витрина и категория открыты, карточка опубликованного
            // объявления открыта. Кабинет под редактором — не его экран: форма правки
            // чужого объявления честно даёт 404, а сам кабинет помечен noindex. Черновик
            // редактор видит как предпросмотр: модерации нужен полный текст до решения.
            'GET /classifieds' => [
                ['path' => '/classifieds', 'outcome' => self::OPEN, 'status' => 200],
            ],
            'GET /classifieds/c/{slug}' => [
                ['path' => '/classifieds/c/{clscategory}', 'outcome' => self::OPEN, 'status' => 200],
                ['path' => '/classifieds/c/net-takoy-kategorii', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /classifieds/search' => [
                ['path' => '/classifieds/search', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/register' => [
                ['path' => '/classifieds/register', 'outcome' => self::REDIRECT, 'status' => 302, 'target' => '/classifieds/cabinet'],
            ],
            'GET /classifieds/cabinet' => [
                ['path' => '/classifieds/cabinet', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/cabinet/profile' => [
                ['path' => '/classifieds/cabinet/profile', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/cabinet/favorites' => [
                ['path' => '/classifieds/cabinet/favorites', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/cabinet/messages' => [
                ['path' => '/classifieds/cabinet/messages', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/cabinet/messages/{id}' => [
                // Редактор не участник переписки по чужому объявлению: тема отвечает 404.
                ['path' => '/classifieds/cabinet/messages/{clsid}', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /classifieds/cabinet/notifications' => [
                ['path' => '/classifieds/cabinet/notifications', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/cabinet/new' => [
                ['path' => '/classifieds/cabinet/new', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/cabinet/{id}' => [
                // Редактор вошёл в обход, но объявление принадлежит продавцу: экран не его.
                ['path' => '/classifieds/cabinet/{clsid}', 'outcome' => self::MARKED, 'status' => 404],
            ],
            'GET /classifieds/seller/{id}' => [
                ['path' => '/classifieds/seller/{clsowner}', 'outcome' => self::OPEN, 'status' => 200],
            ],
            'GET /classifieds/{slug}/report' => [
                // Форма жалобы закрыта от индексации и открыта вошедшему не-владельцу.
                ['path' => '/classifieds/{clsitem}/report', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/{slug}' => [
                ['path' => '/classifieds/{clsitem}', 'outcome' => self::OPEN, 'status' => 200],
                ['path' => '/classifieds/net-takogo-obyavleniya', 'outcome' => self::MARKED, 'status' => 404],
                // Редактор видит предпросмотр чужого черновика: C4 пускает модератора к очереди.
                ['path' => '/classifieds/{clsdraft}', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/admin' => [
                ['path' => '/classifieds/admin', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/admin/categories' => [
                ['path' => '/classifieds/admin/categories', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/admin/attributes' => [
                ['path' => '/classifieds/admin/attributes', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/admin/moderation' => [
                ['path' => '/classifieds/admin/moderation', 'outcome' => self::MARKED, 'status' => 200],
            ],
            'GET /classifieds/admin/reports' => [
                ['path' => '/classifieds/admin/reports', 'outcome' => self::MARKED, 'status' => 200],
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
     * Экранов у установщика два, и шаг 2 закрыт гейтом отметки сессии (`public/setup.php:322-324`):
     * сеанс обхода входит редактором и шага 1 не проходил, поэтому без отметки шаг 2 отвечает
     * перенаправлением на шаг 1 с ключом объяснения `db-step-required`. Исход назван честно —
     * «перенаправление» с целью, а не прощён молча: адрес остаётся в обходе, и снятый гейт либо
     * уехавшая цель отказа покраснит именно здесь. Точное значение `Cache-Control: no-store` на самом
     * экране шага 2 держит двухшаговый путь поведенческого набора — `tests/http/SetupTest.php:160`
     * (его `enterStepTwo()`, строка 85, проходит шаг 1 через `Kernel::passStepOne()`).
     *
     * Обход не повторяет двухшаговый путь нарочно. Отметку сессии ставит только успешная запись
     * `config.php` (`setupMarkSession()` вызывается в `public/setup.php:287`), а штатный префикс
     * `Kernel::start()` указывает серверу на версионированный `config.sample.php`
     * (`tests/http/Kernel.php:51`) — запись в файл с таким базовым именем запрещена
     * `setupWriteConfig()`. Значит, обходу пришлось бы стартовать с временным файлом настроек и
     * записать в него префикс таблиц и адрес сайта из формы шага 1, после чего все тридцать прежних
     * адресов проверялись бы уже в чужом окружении — цена выше, чем польза: связка на экране шага 2
     * уже проверена там, где двухшаговый путь существует штатно.
     *
     * @return array<string, list<array{path: string, outcome: string, status: int, target?: string}>>
     */
    private static function additions(): array
    {
        return [
            'вне таблицы: установщик' => [
                ['path' => '/setup.php', 'outcome' => self::MARKED, 'status' => 200],
                ['path' => '/setup.php?step=db', 'outcome' => self::MARKED, 'status' => 200],
                ['path' => '/setup.php?step=admin', 'outcome' => self::REDIRECT, 'status' => 302, 'target' => '/setup.php?step=db'],
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
     * @param list<array{path: string, outcome: string, status: int, target?: string}> $variants
     * @return list<array{path: string, outcome: string, status: int, target?: string}>
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

            $resolved[] = ['path' => $path, 'outcome' => $variant['outcome'], 'status' => $variant['status'], 'target' => $variant['target'] ?? ''];
        }

        return $resolved;
    }

    /**
     * Токены адресов: реальные данные разделов — адрес опубликованного материала, черновика,
     * рубрики, тега и номер записи читаются из базы после посадки, а не переписываются руками.
     * Товар магазинаоснабжается CommerceHttpSetup: адрес витрины и экран правки используют его.
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

        $product = one('SELECT id, slug FROM ' . t('commerce_product') . ' WHERE status = ?', ['PUBLISHED']);
        self::assertIsArray($product, 'Фикстура: опубликованный товар магазина не найден, витрина недоступна обходу');

        $shopCategory = one('SELECT id, slug FROM ' . t('commerce_category') . ' WHERE visible = ?', [1]);
        self::assertIsArray($shopCategory, 'Фикстура: видимой рубрики магазина нет — витрина рубрики недоступна обходу');

        self::assertNotSame('', CommerceHttpSetup::$productId, 'Фикстура: CommerceHttpSetup не запомнил номер товара');

        $classifiedsCategory = one('SELECT slug FROM ' . t('classifieds_category') . ' WHERE visible = ?', [1]);
        self::assertIsArray($classifiedsCategory, 'Фикстура: видимой категории доски нет — обходу нечего открывать');

        $classifiedsListing = one(
            'SELECT id, slug, owner_id FROM ' . t('classifieds_listing') . ' WHERE status = ? ORDER BY slug',
            ['PUBLISHED']
        );
        self::assertIsArray($classifiedsListing, 'Фикстура: опубликованного объявления доски нет');

        $classifiedsDraft = one('SELECT slug FROM ' . t('classifieds_listing') . ' WHERE status = ?', ['DRAFT']);
        self::assertIsArray($classifiedsDraft, 'Фикстура: черновика доски нет — предпросмотр не проверить');

        return [
            'item' => $items['item']['slug'],
            'id' => $items['item']['id'],
            'draft' => $items['draft']['slug'],
            'category' => (string) $category['slug'],
            'tag' => (string) $tag['slug'],
            // Товар витрины: адрес карточки и экран правки в админке магазина.
            'product' => (string) $product['slug'],
            'pid' => CommerceHttpSetup::$productId,
            'shopcategory' => (string) $shopCategory['slug'],
            // Рубрика магазина: экран характеристик в админке.
            'cid' => (string) $shopCategory['id'],
            // Доска: витрина, карточка, черновик и экран правки кабинета.
            'clscategory' => (string) $classifiedsCategory['slug'],
            'clsitem' => (string) $classifiedsListing['slug'],
            'clsid' => (string) $classifiedsListing['id'],
            'clsdraft' => (string) $classifiedsDraft['slug'],
            'clsowner' => (string) $classifiedsListing['owner_id'],
        ] + self::buildDiscountTokens();
    }

    /**
     * Токены скидок М4: купон и промо-правило фикстур сада строкой — экран правки
     * использует их идентификаторы. Поставщик М5 и вебхук М6 идут той же дорогой.
     *
     * @return array<string, string>
     */
    private static function buildDiscountTokens(): array
    {
        $coupon = one('SELECT id FROM ' . t('commerce_coupon') . " WHERE code = 'OBHOD-10'");
        self::assertIsArray($coupon, 'Фикстура: купон обхода не найден, экран правки купона недоступен');

        $promo = one('SELECT id FROM ' . t('commerce_promo') . " WHERE title = 'Правило для обхода'");
        self::assertIsArray($promo, 'Фикстура: промо-правило обхода не найдено, экран правки недоступен');

        $supplier = one('SELECT id FROM ' . t('commerce_supplier') . " WHERE title = 'Поставщик для обхода'");
        self::assertIsArray($supplier, 'Фикстура: поставщик обхода не найден, экран правки недоступен');

        $webhook = one(
            'SELECT id FROM ' . t('commerce_webhook') . " WHERE url = 'https://obhod.school.dev/webhooks/nova-orders'"
        );
        self::assertIsArray($webhook, 'Фикстура: вебхук обхода не найден, экран правки недоступен');

        return [
            'coupon' => (string) $coupon['id'],
            'promo' => (string) $promo['id'],
            'supplier' => (string) $supplier['id'],
            'webhook' => (string) $webhook['id'],
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
