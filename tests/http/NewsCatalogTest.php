<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Справочники проверяются настоящим запросом: адрес рубрики после переименования, плашка с текстом
// отказа после редиректа и зелёное уведомление действия видны только в ответе сервера.
final class NewsCatalogTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-2', 'email' => 'redaktor@shkola.ru'];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        // Один материал в «Жизни школы» с тегом: рубрика становится непустой (её нельзя удалить),
        // тег — занятым (его нельзя удалить), а пустой справочник остаётся проверять удаление.
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

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function send(string $path, array $fields): array
    {
        $page = Kernel::get($path);

        return Kernel::post($path, array_merge(['_token' => $this->token($page['body'])], $fields));
    }

    /**
     * Ключ из адреса ответа: `?notice=renamed` или `?notice=migration-failed`.
     *
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     */
    private function noticeOf(array $response): string
    {
        $query = $this->queryOf($response);

        return (string) ($query['notice'] ?? '');
    }

    // Причина отказа живёт в сессионном flash, поэтому её читает экран, куда ведёт редирект, а не
    // заголовок Location. Помощник проверяет и адрес (в нём только ключ словаря), и возвращает тело
    // открытого экрана — так проверка не пропускает случай, когда отказ уехал в адрес.
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

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаРубрикахУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_CATEGORIES_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fcategories', $page['headers']['location']);
    }

    public function testАнонимНаТегахУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_TAGS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Ftags', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапрет(): void
    {
        $response = Kernel::post(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'create', 'title' => 'Проверка']);

        self::assertSame(403, $response['status']);
    }

    public function testДействиеБезТокенаОтклоняется(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'create', 'title' => 'Без токена']);

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('Проверка формы не прошла', $response['body']);
        self::assertStringNotContainsString('Без токена', $this->body(NEWS_ADMIN_CATEGORIES_PATH), 'Чужая форма не создала рубрику');
    }

    // ── Рубрики ───────────────────────────────────────────

    public function testЭкранРубрикЧитаетсяИНеИндексируется(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_CATEGORIES_PATH);

        self::assertStringContainsString('<title>Рубрики — админка</title>', $body);
        self::assertStringContainsString('noindex, nofollow', $body);
        self::assertStringContainsString('Жизнь школы', $body);
        self::assertStringContainsString('zhizn-shkoly', $body, 'Адрес рубрики печатается в строке');
        self::assertStringContainsString('href="/news/c/zhizn-shkoly"', $body, 'Ссылка на страницу рубрики');
        self::assertStringContainsString('Осенний бал', $this->body(NEWS_ADMIN_ITEMS_PATH . '?cat=' . categoryIdOf('zhizn-shkoly')));
    }

    public function testНоваяРубрикаВстаётВКонецСписка(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'create', 'title' => 'Выпускные', 'description' => '']);

        self::assertSame(302, $response['status']);
        self::assertSame('created', $this->noticeOf($response));

        $rows = adminCategoryRows();
        self::assertSame('Выпускные', (string) end($rows)['title']);
        self::assertSame('vypusknye', (string) end($rows)['slug'], 'Адрес сложен транслитерацией один раз');
    }

    public function testЭкранРубрикПечатаетОбещанныйТекстУспеха(): void
    {
        // Ключ в адресе — только половина договора: экран за перенаправлением обязан напечатать
        // текст из словаря раздела. Проверка идёт по адресу перенаправления тем же клиентом и
        // сверяет тело со словарём, а не с вписанной строкой.
        $this->login();
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'create', 'title' => 'Кружок пения', 'description' => '']);

        self::assertSame('created', $this->noticeOf($response));

        $screen = Kernel::get((string) $response['headers']['location']);
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString(CATALOG_NOTICE_TEXT['created'], $screen['body']);
    }

    public function testПереименованиеНеМеняетАдрес(): void
    {
        // Критерий §12.14. Адрес остался бы прежним и без теста, но проверить надо именно
        // открытую страницу: переименование, после которого ссылка умерла, выглядит успехом.
        $this->login();
        $id = categoryIdOf('postuplenie');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'rename', 'id' => $id, 'title' => 'Поступление в школу']);

        self::assertSame('renamed', $this->noticeOf($response));

        $page = Kernel::get('/news/c/postuplenie');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Поступление в школу', $page['body']);

        $row = $this->rowOf(adminCategoryRows(), $id);
        self::assertSame('postuplenie', (string) $row['slug'], 'Адрес рубрики не изменился');
    }

    public function testЗанятоеНазваниеОтказываетсяИНеПереименовывает(): void
    {
        $this->login();
        $id = categoryIdOf('metodika');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'rename', 'id' => $id, 'title' => 'Жизнь школы']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('уже есть', $this->failureBody($response));
        self::assertSame('Методика', (string) $this->rowOf(adminCategoryRows(), $id)['title']);
    }

    public function testОписаниеЧитаетсяПодЗаголовкомСтраницы(): void
    {
        $this->login();
        $id = categoryIdOf('zhizn-shkoly');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, [
            'op' => 'describe',
            'id' => $id,
            'title' => 'Жизнь школы',
            'description' => 'Рассказываем про праздники, поездки и школьные будни.',
        ]);

        self::assertSame('described', $this->noticeOf($response));

        $page = Kernel::get('/news/c/zhizn-shkoly');
        self::assertStringContainsString('Рассказываем про праздники, поездки и школьные будни.', $page['body']);
    }

    public function testСкрытиеУбираетСтраницуИМатериалыИзОбщейЛенты(): void
    {
        // Критерий §12.14. Материал остаётся в базе и в рубрике — уходит только из ленты,
        // поэтому проверка читает ленту, а не таблицу.
        $this->login();
        $id = categoryIdOf('zhizn-shkoly');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'hide', 'id' => $id]);

        self::assertSame('hidden', $this->noticeOf($response));
        self::assertSame(404, Kernel::get('/news/c/zhizn-shkoly')['status']);
        self::assertStringNotContainsString('Осенний бал', Kernel::get('/news')['body']);

        $shown = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'show', 'id' => $id]);
        self::assertSame('shown', $this->noticeOf($shown));
        self::assertStringContainsString('Осенний бал', Kernel::get('/news')['body']);
    }

    public function testСкрытаяРубрикаНаЭкранеНеПечатаетСсылкуНаСайт(): void
    {
        // Порт подхода чипов: на публичных страницах скрытая рубрика печатается без ссылки,
        // и справочник обязан согласиться — её страница отвечает как несуществующая,
        // поэтому «Открыть на сайте» у скрытой строки ведёт на 404.
        $this->login();
        $id = categoryIdOf('zhizn-shkoly');

        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'hide', 'id' => $id]);
        self::assertSame('hidden', $this->noticeOf($response));

        $body = $this->body(NEWS_ADMIN_CATEGORIES_PATH);
        self::assertStringNotContainsString(
            'href="/news/c/zhizn-shkoly"',
            $body,
            'Ссылка на закрытую страницу живёт только у видимой рубрики'
        );
        self::assertStringContainsString('Страница закрыта', $body, 'Вместо ссылки экран объясняет, почему кликать некуда');

        $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'show', 'id' => $id]);
        self::assertStringContainsString(
            'href="/news/c/zhizn-shkoly"',
            $this->body(NEWS_ADMIN_CATEGORIES_PATH),
            'Возвращённая рубрика снова печатает ссылку на страницу'
        );
    }

    public function testПорядокМеняетсяКнопкамиАНаКрайнейСтрокеОтвечаетТекстом(): void
    {
        $this->login();
        $order = static fn (): string => implode(',', array_column(adminCategoryRows(), 'slug'));
        $before = $order();

        $moved = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'down', 'id' => categoryIdOf('zhizn-shkoly')]);
        self::assertSame('moved', $this->noticeOf($moved));
        self::assertNotSame($before, $order(), 'Порядок списка изменился');

        $topped = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'up', 'id' => categoryIdOf('zhizn-shkoly')]);
        self::assertSame('moved', $this->noticeOf($topped), 'Кнопка «Вверх» вернула рубрику на первое место');

        $again = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'up', 'id' => categoryIdOf('zhizn-shkoly')]);
        self::assertSame('topped', $this->noticeOf($again), 'У первой рубрики «Вверх» отвечает текстом');
        self::assertSame($before, $order());
    }

    public function testУдалениеНепустойРубрикиОтказываетсяСПонятнымТекстом(): void
    {
        $this->login();
        $id = categoryIdOf('zhizn-shkoly');
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame(302, $response['status']);
        $body = $this->failureBody($response);
        self::assertStringContainsString('В рубрике 1 материал', $body);
        self::assertStringContainsString('role="alert"', $body, 'Причина печатается плашкой отказа, а не голым текстом');
        self::assertNotNull($this->rowOf(adminCategoryRows(), $id), 'Рубрика осталась');
    }

    public function testПустаяРубрикаУдаляется(): void
    {
        $this->login();
        $created = createCategory('Кружок рисования');
        self::assertTrue($created['ok'], (string) $created['error']);

        // createCategory() возвращает только «ок / отказ»: идентификатор новой строки берут по
        // её адресу, как это делает экран после редиректа.
        $id = categoryIdOf('kruzhok-risovaniya');
        self::assertNotSame('', $id, 'Рубрика появилась в базе');

        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame('deleted', $this->noticeOf($response));
        self::assertNull($this->rowOf(adminCategoryRows(), $id));
    }

    public function testЧужогоИлиСтертогоИдентификатораНеЕсть(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_CATEGORIES_PATH, ['op' => 'rename', 'id' => 'нет-такого-ident', 'title' => 'Проверка']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('обновите страницу', $this->failureBody($response));
    }

    // ── Теги ──────────────────────────────────────────────

    public function testЭкранТеговПоказываетНадписиИСчётчик(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_TAGS_PATH);

        self::assertStringContainsString('<title>Теги — админка</title>', $body);
        self::assertStringContainsString('выпускник', $body);
        self::assertStringContainsString('href="/news/t/vypusknik"', $body);
        self::assertStringNotContainsString('value="create"', $body, 'Создать тег из справочника нельзя (§12.15)');
    }

    public function testПереименованиеТегаСохраняетЕгоАдрес(): void
    {
        // Критерий §12.15. Надпись русская, адрес — транслит: страница тега обязана открыться
        // по старому адресу с новой надписью.
        $this->login();
        $rows = adminTagRows();
        $tag = $rows[0];
        $response = $this->send(NEWS_ADMIN_TAGS_PATH, [
            'op' => 'rename',
            'id' => (string) $tag['id'],
            'label' => 'Выпускники',
        ]);

        self::assertSame('tagRenamed', $this->noticeOf($response));

        $page = Kernel::get('/news/t/' . $tag['slug']);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Выпускники', $page['body']);
        self::assertSame((string) $tag['slug'], (string) $this->tagRowById((string) $tag['id'])['slug']);
    }

    public function testУдалениеЗанятогоТегаОтказывается(): void
    {
        $this->login();
        $tag = adminTagRows()[0];
        $response = $this->send(NEWS_ADMIN_TAGS_PATH, ['op' => 'delete', 'id' => (string) $tag['id']]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('Тег стоит на 1 материале', $this->failureBody($response));
        self::assertNotNull($this->tagRowById((string) $tag['id']), 'Тег остался');
    }

    public function testПустойТегУдаляется(): void
    {
        // Пустой тег форма материала не рождает, поэтому строку заводят напрямую: проверка
        // удаления справочника, а не создания тегов.
        $this->login();
        $id = newId();
        run(
            'INSERT INTO ' . t('news_tag') . ' (id, slug, label) VALUES (?, ?, ?)',
            [$id, 'pustoj-tag', 'Пустой тег']
        );

        $response = $this->send(NEWS_ADMIN_TAGS_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame('tagDeleted', $this->noticeOf($response));
        self::assertNull($this->tagRowById($id));
    }

    public function testНеизвестноеДействиеОтказываетсяТекстом(): void
    {
        $this->login();
        foreach ([NEWS_ADMIN_CATEGORIES_PATH, NEWS_ADMIN_TAGS_PATH] as $path) {
            $response = $this->send($path, ['op' => 'взломать', 'id' => categoryIdOf('zhizn-shkoly')]);

            self::assertSame(302, $response['status']);
            self::assertStringContainsString('Неизвестное действие', $this->failureBody($response));
        }
    }

    // ── Помощники ─────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function rowOf(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function tagRowById(string $id): ?array
    {
        return $this->rowOf(adminTagRows(), $id);
    }

    private function body(string $path): string
    {
        $response = Kernel::get($path);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }
}
