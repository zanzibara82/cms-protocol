<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Экран блоков проверяется запросами к живому серверу: ключ уведомления в адресе возврата, текст
// плашки после него, состояние строки в базе и разметка трёх зон существуют только в ответе.
//
// Каждый тест действует блоком, который создал сам, а tearDown() убирает из таблицы строки,
// добавленные тестом: набор строк возвращается к составу релиза — двум заводским. Иначе следующий
// метод застал бы чужие правки и падение показывало бы не своё. Содержимое двух заводских строк
// tearDown() не восстанавливает и не обещает этого: сегодня их ни один тест успешно не правит,
// а тест, который начнёт править, обязан вернуть правку сам.
final class NewsBlocksAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'bloki@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-3', 'email' => 'bloki@school.dev'];

    // Заводские строки третьего релиза: тот набор строк, к которому tearDown() возвращает таблицу.
    // Их поля тесты не меняют — удаляются только строки, добавленные самим тестом.
    private const FACTORY_IDS = ['newsblk-home', 'newsblk-article'];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        // Материал нужен, чтобы рубрика цели была живой и непустой: без него проверка цели
        // шла бы по справочнику, которого на сайте нет.
        $created = createItem([
            'title' => 'Осенний бал',
            'slug' => 'osennij-bal',
            'lead' => 'Приглашаем на бал.',
            'body' => [['title' => '', 'text' => 'Танцы, песни и чай.']],
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'tags' => ['выпускник'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], self::EDITOR, nowUtc());

        self::assertTrue($created['ok'], (string) $created['error']);

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

    protected function tearDown(): void
    {
        foreach (adminBlockRows() as $row) {
            $id = (string) $row['id'];

            if (!in_array($id, self::FACTORY_IDS, true)) {
                deleteBlock($id);
            }
        }

        parent::tearDown();
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаБлокахУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_BLOCKS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fblocks', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапрет(): void
    {
        $response = Kernel::post(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Проверка', 'take' => '5']);

        self::assertSame(403, $response['status']);
    }

    public function testДействиеБезТокенаОтклоняетсяИНичегоНеМеняет(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'rename', 'id' => 'newsblk-home', 'title' => 'Без токена']);

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('Проверка формы не прошла', $response['body']);
        self::assertStringNotContainsString('Без токена', $this->body(NEWS_ADMIN_BLOCKS_PATH), 'Чужая форма не переименовала блок');
    }

    // ── Разметка экрана ───────────────────────────────────

    public function testЭкранБлоковЧитаетсяИНеИндексируется(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);

        self::assertStringContainsString('<title>Блоки — админка</title>', $body);
        self::assertStringContainsString('noindex, nofollow', $body);
        self::assertStringContainsString('href="/news/admin/blocks" aria-current="page"', $body);
        self::assertStringContainsString('Свежие материалы', $body);
        self::assertStringContainsString('Похожие материалы', $body);
        self::assertStringContainsString('вся лента раздела', $body, 'Блок без цели читается подписью, а не пустой клеткой');
        self::assertStringContainsString('рубрика самого материала', $body, 'У похожих материалов своей цели нет');
    }

    public function testЗоныИдутВПорядкеСловаряАФормаЦелиУстрокиСписка(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);

        $home = strpos($body, 'На главной');
        $category = strpos($body, 'В рубрике');
        $article = strpos($body, 'В материале');
        $retarget = strpos($body, 'value="retarget"');
        self::assertNotFalse($home);
        self::assertNotFalse($category);
        self::assertNotFalse($article);
        self::assertNotFalse($retarget);
        self::assertLessThan($category, $home, 'Первой печатается зона словаря — главная');
        self::assertLessThan($article, $category);

        // Порядок зон задаёт место правки: поле цели принадлежит строке «Свежие материалы» зоны
        // главной, и если условие вида в шаблоне перевёрнуто, форма цели уедет под заголовок
        // «В материале».
        self::assertLessThan($article, $retarget, 'Форма цели печатается у блока-списка, а не у похожих материалов');

        // Три таблицы — три формы добавления со своей зоной; поле цели на стартовом экране одно,
        // потому что вторая заводская строка — вид «похожие».
        self::assertSame(3, substr_count($body, 'name="zone"'), 'Зона уезжает скрытым полем каждой формы добавления');
        self::assertSame(1, substr_count($body, 'name="target"'), 'Цель правится только у строки вида «список»');
    }

    public function testФормаДобавленияНеПечатаетПолеЦели(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);

        $create = strpos($body, 'value="create"');
        self::assertNotFalse($create, 'Форма добавления на экране есть');
        $end = strpos($body, '</form>', $create);
        self::assertNotFalse($end, 'Форма добавления закрыта');

        self::assertStringNotContainsString('name="target"', (string) substr($body, $create, $end - $create), 'Блок создаётся без цели и правится в строке');
    }

    // ── Девять действий ───────────────────────────────────

    public function testСозданиеБлокаСтавитЕгоВКонецСвоейЗоны(): void
    {
        $this->login();
        $response = $this->create('category', 'list', 'К списку рубрик', '4');

        self::assertSame(302, $response['status']);
        self::assertSame('created', $this->noticeOf($response));

        $screen = Kernel::get((string) $response['headers']['location']);
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString(NEWS_BLOCK_NOTICE_TEXT['created'], $screen['body']);

        $row = $this->rowByTitle('К списку рубрик');
        self::assertNotNull($row);
        self::assertSame('category', (string) $row['zone']);
        self::assertSame(1, (int) $row['order'], 'Плотность порядка считается в зоне, а не по всей таблице');
        self::assertNull($row['target'], 'Созданный формой блок цели не имеет');
        self::assertTrue($row['visible']);
    }

    public function testПереименованиеЧитаетсяВБазе(): void
    {
        $this->login();
        $this->create('category', 'list', 'Первое имя', '3');
        $id = $this->idByTitle('Первое имя');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'rename', 'id' => $id, 'title' => 'Второе имя']);

        self::assertSame('renamed', $this->noticeOf($response));
        $row = $this->rowById($id);
        self::assertNotNull($row);
        self::assertSame('Второе имя', (string) $row['title']);
        self::assertStringContainsString('Второе имя', $this->body(NEWS_ADMIN_BLOCKS_PATH));
    }

    public function testСменаЦелиНаРубрикуИЧислоМатериалов(): void
    {
        $this->login();
        $this->create('category', 'list', 'Цель и число', '3');
        $id = $this->idByTitle('Цель и число');
        self::assertNotSame('', $id);
        $categoryId = categoryIdOf('zhizn-shkoly');

        $retargeted = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'category:' . $categoryId]);
        self::assertSame('retargeted', $this->noticeOf($retargeted));
        self::assertStringContainsString(NEWS_BLOCK_NOTICE_TEXT['retargeted'], Kernel::get((string) $retargeted['headers']['location'])['body']);

        $row = $this->rowById($id);
        self::assertNotNull($row);
        $target = $row['target'];
        self::assertNotNull($target);
        self::assertSame('category', (string) $target['kind']);
        self::assertSame('Жизнь школы', (string) $target['label']);
        self::assertTrue($target['alive']);
        self::assertStringContainsString('<option value="category:' . $categoryId . '" selected>', $this->body(NEWS_ADMIN_BLOCKS_PATH));

        $limited = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'limit', 'id' => $id, 'take' => '7']);
        self::assertSame('limited', $this->noticeOf($limited));
        $after = $this->rowById($id);
        self::assertNotNull($after);
        self::assertSame(7, (int) $after['take']);
    }

    public function testПоказПереключаетсяАСкрытыйБлокОстаётсяНаЭкране(): void
    {
        $this->login();
        $this->create('category', 'list', 'Виден и скрыт', '3');
        $id = $this->idByTitle('Виден и скрыт');
        self::assertNotSame('', $id);

        $hidden = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'hide', 'id' => $id]);
        self::assertSame('hidden', $this->noticeOf($hidden));
        $row = $this->rowById($id);
        self::assertNotNull($row);
        self::assertFalse($row['visible']);

        // Кнопку «Показать» ищет привязка к идентификатору именно этой строки: в разметке экрана
        // таких кнопок несколько, и общая проверка ловила бы чужую.
        self::assertStringContainsString(
            '<input type="hidden" name="op" value="show"><input type="hidden" name="id" value="' . $id . '">',
            $this->body(NEWS_ADMIN_BLOCKS_PATH),
            'Скрытой строке экран возвращает кнопку показа, а не прячет её'
        );

        $shown = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'show', 'id' => $id]);
        self::assertSame('shown', $this->noticeOf($shown));
        $back = $this->rowById($id);
        self::assertNotNull($back);
        self::assertTrue($back['visible'], 'Скрытый блок доступен снова — экран его не теряет');
    }

    public function testСтрелкиДвигаютВСвоейЗонеИКраяОтмечают(): void
    {
        $this->login();
        $this->create('category', 'list', 'Стрелки первый', '3');
        $this->create('category', 'list', 'Стрелки второй', '3');
        $first = $this->idByTitle('Стрелки первый');
        $second = $this->idByTitle('Стрелки второй');
        self::assertNotSame('', $first);
        self::assertNotSame('', $second);
        $homeOrder = $this->orderOf('newsblk-home');

        $moved = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'down', 'id' => $first]);
        self::assertSame('moved', $this->noticeOf($moved));
        $movedRow = $this->rowById($first);
        self::assertNotNull($movedRow);
        self::assertSame(2, (int) $movedRow['order'], 'Второй блок зоны отодвинулся на место первого');

        $back = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'up', 'id' => $first]);
        self::assertSame('moved', $this->noticeOf($back));

        self::assertSame('topped', $this->noticeOf($this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'up', 'id' => $first])));
        self::assertSame('bottomed', $this->noticeOf($this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'down', 'id' => $second])));

        // Движение в зоне «рубрика» не трогает порядок зоны «главная» — тот же обход с WHERE zone = ?.
        self::assertSame($homeOrder, $this->orderOf('newsblk-home'));
    }

    public function testУдалениеСнимаетСтрокуСЭкрана(): void
    {
        $this->login();
        $this->create('category', 'list', 'На удаление', '3');
        $id = $this->idByTitle('На удаление');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame('deleted', $this->noticeOf($response));
        self::assertNull($this->rowById($id));
        self::assertStringNotContainsString('На удаление', $this->body(NEWS_ADMIN_BLOCKS_PATH));
    }

    // ── Границы форм ──────────────────────────────────────

    public function testСедьмойБлокВЗонеОтказывается(): void
    {
        $this->login();

        // Заводская строка занимает одно место зоны, поэтому через форму добавляем ещё пять.
        self::assertSame(1, $this->countZone('home'), 'до заполнения в зоне главной одна заводская строка');

        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE - 1; $n++) {
            $response = $this->create('home', 'list', 'Заполнение ' . $n, '3');
            self::assertSame('created', $this->noticeOf($response), 'заход ' . $n . ' обязан добавить блок, а не отказать');
        }

        self::assertSame(
            NEWS_BLOCK_PER_ZONE,
            $this->countZone('home'),
            'пять заходов плюс заводская строка заполняют предел — ровно ' . NEWS_BLOCK_PER_ZONE
        );

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Шестой лишних', 'take' => '3']);

        self::assertSame(302, $response['status']);
        self::assertSame(
            NEWS_BLOCK_PER_ZONE,
            $this->countZone('home'),
            'отказ не оставил в зоне седьмой строки — предел держится по факту таблицы'
        );
        self::assertStringContainsString('В зоне уже 6 блоков', $this->failureBody($response));
    }

    public function testПодделанныеПоляОтказываютсяСвоимТекстом(): void
    {
        // Каждая пара «поле => значение» берётся из договора задачи 3..6 и печатается на экране
        // отказом: форма этих значений не рождает, поэтому POST идёт напрямую.
        /** @var list<array{why: string, fields: array<string, string>, text: string}> $cases */
        $cases = [
            [
                'why' => 'чужая зона',
                'fields' => ['op' => 'create', 'zone' => 'shop', 'kind' => 'list', 'title' => 'Зона из чужого раздела', 'take' => '3'],
                'text' => 'Зоны с таким именем нет.',
            ],
            [
                'why' => 'чужой вид',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'video', 'title' => 'Вид, которого нет', 'take' => '3'],
                'text' => 'Такого вида блока нет.',
            ],
            [
                'why' => 'заголовок короче предела',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'А', 'take' => '3'],
                'text' => 'Название блока должно быть от 2 до 120 символов.',
            ],
            [
                'why' => 'число больше предела',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Девятьсот девяносто', 'take' => '999'],
                'text' => 'Число материалов должно быть от 1 до 12.',
            ],
            [
                'why' => 'ноль вместо числа',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Ноль материалов', 'take' => '0'],
                'text' => 'Число материалов должно быть от 1 до 12.',
            ],
        ];

        $this->login();

        foreach ($cases as $case) {
            $response = Kernel::post(NEWS_ADMIN_BLOCKS_PATH, array_merge(['_token' => $this->token($this->body(NEWS_ADMIN_BLOCKS_PATH))], $case['fields']));

            self::assertSame(302, $response['status'], $case['why']);
            self::assertStringContainsString($case['text'], $this->failureBody($response), $case['why']);
        }
    }

    public function testПохожиеМатериалыЦелиНеИмеют(): void
    {
        $this->login();
        $this->create('category', 'related', 'Похожие для отказа', '3');
        $id = $this->idByTitle('Похожие для отказа');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'category:' . categoryIdOf('zhizn-shkoly')]);

        self::assertStringContainsString('У блока «похожие материалы» цели нет', $this->failureBody($response));
    }

    public function testЧужойВидЦелиДоходитДоПроверкиИОтказывается(): void
    {
        $this->login();
        $this->create('category', 'list', 'Цель из чужого раздела', '3');
        $id = $this->idByTitle('Цель из чужого раздела');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'shop:9']);

        self::assertStringContainsString('Целью блока может быть только рубрика или метка.', $this->failureBody($response));
    }

    public function testНеизвестноеДействиеОтказываетсяТекстом(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'взломать', 'id' => 'newsblk-home']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('Неизвестное действие', $this->failureBody($response));
    }

    public function testБлокаУжеНетИЭтоОтказ(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'rename', 'id' => 'net-takogo-bloka', 'title' => 'Проверка']);

        self::assertStringContainsString('Блока уже нет — обновите страницу.', $this->failureBody($response));
    }

    public function testМёртваяЦельОстаётсяВСтрокеИВыбраннойОпцией(): void
    {
        $this->login();
        self::assertTrue(createCategory('Проба', '')['ok']);

        $categoryId = '';
        foreach (adminCategoryRows() as $row) {
            if ((string) $row['title'] === 'Проба') {
                $categoryId = (string) $row['id'];
            }
        }

        self::assertNotSame('', $categoryId, 'Рубрика для проверки создана');

        $this->create('category', 'list', 'Блок на мёртвую цель', '3');
        $id = $this->idByTitle('Блок на мёртвую цель');
        self::assertNotSame('', $id);
        self::assertSame('retargeted', $this->noticeOf($this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'category:' . $categoryId])));

        self::assertTrue(deleteCategory($categoryId)['ok'], 'Пустая рубрика удаляется — цель блока становится мёртвой');

        $row = $this->rowById($id);
        self::assertNotNull($row, 'Блок с удалённой целью остаётся в таблице');
        $target = $row['target'];
        self::assertNotNull($target);
        self::assertFalse($target['alive'], 'Цель помечена недоступной');

        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);
        self::assertStringContainsString('цель недоступна', $body);
        self::assertStringContainsString('<option value="category:' . $categoryId . '" selected>', $body, 'Мёртвая цель остаётся выбранной — иначе правка вернула бы «всю ленту»');
    }

    // ── Помощники ─────────────────────────────────────────

    private function login(): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    /** @param array<string, string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function send(string $path, array $fields): array
    {
        $page = Kernel::get($path);

        return Kernel::post($path, array_merge(['_token' => $this->token($page['body'])], $fields));
    }

    /** @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>} */
    private function create(string $zone, string $kind, string $title, string $take): array
    {
        return $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'create', 'zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take]);
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     */
    private function noticeOf(array $response): string
    {
        $query = $this->queryOf($response);

        return (string) ($query['notice'] ?? '');
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     */
    private function failureBody(array $response): string
    {
        $location = (string) $response['headers']['location'];
        self::assertStringContainsString('notice=migration-failed', $location);
        self::assertStringNotContainsString(
            'error=',
            $location,
            'Причина отказа не должна уезжать в адрес: он остаётся в истории браузера и в access-логе'
        );

        return (string) Kernel::get($location)['body'];
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     */
    private function locationOf(array $response): string
    {
        $location = (string) ($response['headers']['location'] ?? '');

        return substr($location, strpos($location, '?') === false ? strlen($location) : (int) strpos($location, '?') + 1);
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     *
     * @return array<string, string>
     */
    private function queryOf(array $response): array
    {
        parse_str($this->locationOf($response), $query);

        return $query;
    }

    private function body(string $path): string
    {
        $response = Kernel::get($path);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    private function idByTitle(string $title): string
    {
        foreach (adminBlockRows() as $row) {
            if ((string) $row['title'] === $title) {
                return (string) $row['id'];
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function rowById(string $id): ?array
    {
        foreach (adminBlockRows() as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function rowByTitle(string $title): ?array
    {
        foreach (adminBlockRows() as $row) {
            if ((string) $row['title'] === $title) {
                return $row;
            }
        }

        return null;
    }

    private function orderOf(string $id): int
    {
        $row = $this->rowById($id);
        self::assertNotNull($row);

        return (int) $row['order'];
    }

    /**
     * Сколько строк лежит в зоне физически — счётчик таблицы, а не текст отказа.
     */
    private function countZone(string $zone): int
    {
        $count = 0;

        foreach (adminBlockRows() as $row) {
            if ((string) $row['zone'] === $zone) {
                $count++;
            }
        }

        return $count;
    }
}
