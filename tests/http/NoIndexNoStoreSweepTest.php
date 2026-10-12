<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Обобщающая проверка связки «страница с меткой `noindex` ⇒ заголовок `Cache-Control` запрещает
// хранение» (задача-кандидат #181 широкой рецензии ветки). Инвариант держится частями: механизм в
// `core/template.php` ставит заголовок на каждом `render()` с `noindex`, пять отдельных проверок
// держат экраны ядра, одна — установщик, а два автономных экрана («Сайт не установлен» и 500)
// собираются мимо `render()` и задают заголовок сами. Новый экран с `noindex`, забытый в списке,
// сегодня ворота проходит — эта проверка его не пропустит.
//
// Список адресов строится из `routeTable()`, а не переписывается руками: статические GET-маршруты
// попадают в обход автоматически, параметрические — только с фикстурой на месте плейсхолдера, а
// маршрут, которому фикстуры нет, — это падение с перечислением. Вне таблицы к обходу добавляются
// ровно два адреса, и каждый назван с причиной: `/setup.php` (обслуживается файлом, а не
// маршрутизатором) и экран 500 (маршрут с отсутствующим обработчиком, живёт по отметке окружения).
final class NoIndexNoStoreSweepTest extends TestCase
{
    private const EDITOR_EMAIL = 'obhod@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    /** @var array<string, list<string>> адрес обхода: ключ маршрута (или пояснение) => запрошенные пути */
    private static array $addresses = [];

    /** @var list<string> GET-маршруты с плейсхолдерами, которым не нашлось фикстуры */
    private static array $unresolved = [];

    /** @var array<string, list<string>> фикстуры адресов подстановкам вместо плейсхолдеров */
    private static array $fixtures = [];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::unlockSetup();

        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');
        installApp('news');

        $item = createItem([
            'title' => 'Материал для обхода',
            'slug' => 'obhodnyj-material',
            'lead' => 'Фикстура связки запретов.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Абзац фикстуры.']],
            'tags' => ['Выпускник'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], ['id' => 'obhod-1', 'email' => self::EDITOR_EMAIL]);

        self::assertSame('', $item['error'], 'Фикстурный материал не создан: ' . $item['error']);

        self::$fixtures = self::buildFixtures($item);

        // Отметка CMS_TEST_OMITTED_HANDLER поднимает в фикстуре маршрут с отсутствующим
        // обработчиком — тот же приём, что в ErrorScreenTest: экран 500 идёт мимо `render()`
        // и обязан попасть в этот же обход. Каталог фикстур передаётся серверу, чтобы таблица
        // маршрутов в процессе теста и на стенде совпадали.
        Kernel::start([
            'CMS_EXTRA_APPS_DIR' => dirname(__DIR__, 2) . '/tests/fixtures/apps',
            'CMS_TEST_OMITTED_HANDLER' => '1',
        ]);

        // Сеанс редактора: приватные экраны админки (все с `noindex`) иначе просто не открыться,
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

    public function testСписокАдресовНеПустИПостроенИзТаблицыМаршрутов(): void
    {
        // Тот же класс «тихого самоисключения», который ловили трижды: опустевшая таблица или
        // пустой список дают обход нуля адресов, зелёный как ни в чём не бывало.
        self::assertNotSame([], routeTable(), 'Таблица маршрутов пуста — связку проверяет не на чём, это падение, а не зелёный прогон');
        self::assertNotSame([], self::$addresses, 'Список адресов обхода пуст — проверять связку не на чём');
        // Два адреса вне таблицы не дают права называть список построенным: ядро обязано стоять в нём.
        self::assertGreaterThan(2, count(self::$addresses), 'Список адресов состоит только из добавок вне таблицы маршрутов');
    }

    public function testКаждыйGetМаршрутТаблицыОбойден(): void
    {
        // Новый параметрический маршрут без фикстуры не имеет права молча выпасть из обхода:
        // ровно так же, как забытый в списке экран проходил ворота до этой проверки.
        self::assertSame(
            [],
            self::$unresolved,
            'Проверка не обошла GET-маршруты с непредставленными плейсхолдерами: ' . implode(', ', self::$unresolved)
                . ' — добавь фикстуру адреса или скажи явно, почему экран закрыт'
        );
    }

    public function testСвязкаNoindexNoStoreНаОбоихКаналахУстановленногоСайта(): void
    {
        $sweep = self::sweep();

        self::assertGreaterThan(0, $sweep['checked'], 'Обход не запросил ни одного адреса — проверка деградирует в ничего');
        self::assertGreaterThan(0, $sweep['marked'], 'На всём списке нет ни одной страницы с меткой `noindex`: обход зелёный, потому что проверяет пустоту, а это падение');
        self::assertSame(
            [],
            $sweep['violations'],
            'Страницы с меткой `noindex` без `no-store` в заголовке `Cache-Control`:'
        );
    }

    // Порядок объявления важен: метод разбирает схему и обязан идти последним.
    public function testЭкранНеустановленногоСайтаДержитСвязкуНаКаждомАдресе(): void
    {
        // Экран «Сайт не установлен» — один из двух экранов, идущих мимо `render()`
        // (`partial()` + `respond()` в `public/index.php`): централизованный механизм его не
        // касается, а до `startSession()` лимитер сессии тоже молчит. Проверка снимает таблицу —
        // признак установки — и прогоняет тот же список: каждый адрес обязан отдать 503
        // с согласованной парой запретов.
        try {
            run('DROP TABLE IF EXISTS ' . t('app_installation'));
            self::assertFalse(isSiteInstalled(), 'После снятия таблицы установщика сайт обязан числиться неустановленным');

            $home = Kernel::get('/');
            self::assertSame(503, $home['status'], 'Неустановленный сайт обязан отвечать подсказкой на главной');

            $sweep = self::sweep();

            self::assertGreaterThan(0, $sweep['checked'], 'Обход неустановленной схемы не запросил ни одного адреса');
            self::assertGreaterThan(0, $sweep['marked'], 'На неустановленной схеме не найдено ни одного экрана с меткой запрет индексирования');
            self::assertSame(
                [],
                $sweep['violations'],
                'Экраны неустановленного сайта с меткой `noindex` без `no-store` в `Cache-Control`:'
            );
        } finally {
            // Схема восстанавливается сразу: следующий набор должен застать целую базу,
            // а не чужие таблицы (тот же порядок, что в SiteNotInstalledTest).
            TestDb::reset();
        }
    }

    /**
     * Один обход списка: для каждого адреса связка «метка запрет индексирования в теле или
     * заголовке ⇒ запрет хранения в `Cache-Control`».
     *
     * @return array{checked: int, marked: int, violations: list<string>}
     */
    private static function sweep(): array
    {
        $checked = 0;
        $marked = 0;
        $violations = [];

        foreach (self::$addresses as $key => $paths) {
            foreach ($paths as $path) {
                $response = Kernel::get($path);
                $checked++;

                // Мёртвый ответ (обрыв соединения: статус 0, пустое тело, без редиректа) не должен
                // проходить как «нет метки — нечего проверять»: это тот же тихий пропуск.
                if ($response['body'] === '' && ($response['headers']['location'] ?? '') === '') {
                    $violations[] = $key . ' → ' . $path . ': сервер не ответил ни страницей, ни редиректом (статус ' . $response['status'] . ')';
                    continue;
                }

                $hasMark = preg_match('/<meta\s[^>]*name="robots"[^>]*content="[^"]*noindex/i', $response['body']) === 1
                    || str_contains(strtolower($response['headers']['x-robots-tag'] ?? ''), 'noindex');

                if (!$hasMark) {
                    continue;
                }

                $marked++;
                $cacheControl = $response['headers']['cache-control'] ?? '';
                if (!str_contains(strtolower($cacheControl), 'no-store')) {
                    $violations[] = sprintf(
                        '%s → %s: статус %d, страница помечена запретом индексирования, а `Cache-Control`: «%s» — нет запрета хранения',
                        $key,
                        $path,
                        $response['status'],
                        $cacheControl
                    );
                }
            }
        }

        return ['checked' => $checked, 'marked' => $marked, 'violations' => $violations];
    }

    /**
     * Фикстуры вместо плейсхолдеров адресов: реальные данные раздела — адрес материала,
     * рубрики и тега читаются из базы после посадки, а не переписываются руками.
     *
     * @param array{ok: bool, notice: string, error: string, id: string, slug: string} $item
     * @return array<string, list<string>>
     */
    private static function buildFixtures(array $item): array
    {
        $category = one('SELECT slug FROM ' . t('news_category') . ' WHERE id = ?', [categoryIdOf('zhizn-shkoly')]);
        self::assertIsArray($category, 'Фикстура: видимая рубрика «zhizn-shkoly» не найдена в схеме раздела');

        $tag = one(
            'SELECT tag.slug FROM ' . t('news_tag') . ' AS tag'
            . ' JOIN ' . t('news_item_tag') . ' AS link ON link.tag_id = tag.id'
            . ' WHERE link.item_id = ? ORDER BY tag.slug LIMIT 1',
            [$item['id']]
        );
        self::assertIsArray($tag, 'Фикстура: у созданного материала нет ни одного тега, страница тега недоступна обходу');

        return [
            // Второй адрес под тем же маршрутом — заведомо отсутствующий адрес материала:
            // ветка notFound() тоже обязана держать связку, это тот же обработчик.
            'GET /news/{slug}' => ['/news/' . $item['slug'], '/news/net-takogo-adresa'],
            'GET /news/c/{slug}' => ['/news/c/' . (string) $category['slug']],
            'GET /news/t/{slug}' => ['/news/t/' . (string) $tag['slug']],
            'GET /news/admin/items/{id}' => ['/news/admin/items/' . $item['id']],
        ];
    }

    /**
     * Список адресов из таблицы маршрутов: статические GET-маршруты берутся как есть,
     * параметрические — только из фикстур; непредставленный маршрут становится падением
     * в проверке обхода.
     */
    private static function buildAddresses(): void
    {
        self::$addresses = [];
        self::$unresolved = [];

        foreach (routeTable() as $route) {
            if ($route['method'] !== 'GET') {
                continue;
            }

            $key = 'GET ' . $route['pattern'];

            if (!str_contains($route['pattern'], '{')) {
                self::$addresses[$key] = [$route['pattern']];
                continue;
            }

            if (!isset(self::$fixtures[$key])) {
                self::$unresolved[] = $key;
                continue;
            }

            self::$addresses[$key] = self::$fixtures[$key];
        }

        // Экраны вне таблицы маршрутов: `/setup.php` обслуживается файлом в документ-корне
        // (в реестр маршрутов он не попадает принципиально), а фикстурный маршрут с отсутствующим
        // обработчиком регистрируется только при отметке окружения на сервере — в таблице процесса
        // тестера его нет, поэтому он добавлен здесь открыто, а не промолчан.
        self::$addresses['вне таблицы: установщик'] = ['/setup.php'];
        self::$addresses['вне таблицы: экран внутреннего отказа'] = ['/test-omitted-handler'];
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
