<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use DateInterval;
use PHPUnit\Framework\TestCase;

// Экраны админки раздела проверяются настоящим запросом: редирект анонима, плашка после действия,
// отказ кнопки и разметка строки видны только в ответе сервера. Функция, которая собирает эти
// строки, про коды ответа ничего не знает.
final class NewsAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];

    /**
     * Закладка: шесть закреплённых (предел `PINNED_WARN_LIMIT` — пять, поэтому предупреждение
     * экрана обязано быть видно на настоящих числах, а не только в правиле tooManyPinned()),
     * черновик, материал с ненаступившим сроком, материал в скрытой рубрике и пятнадцать
     * обычных — всего 24, чтобы список встал ровно в две страницы по 20.
     */
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        foreach (range(1, 6) as $index) {
            self::create(self::input([
                'title' => 'Закреплённый материал ' . $index,
                'slug' => 'zakreplennyj-' . $index,
                'pinned' => true,
            ]), $index);
        }

        self::create(self::input([
            'title' => 'Черновик поездки',
            'slug' => 'chernovik-poezdki',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 7);

        self::create(self::input([
            'title' => 'Выпускной через час',
            'slug' => 'vypusknov-cherez-chas',
            'publishedAt' => nowUtc()->add(new DateInterval('PT1H')),
        ]), 8);

        self::create(self::input([
            'title' => 'Репетиция в закрытой рубрике',
            'slug' => 'repeticija',
            'categoryId' => categoryIdOf('metodika'),
        ]), 9);

        foreach (range(1, 15) as $index) {
            self::create(self::input([
                'title' => 'Материал ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'slug' => 'material-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]), 9 + $index);
        }

        // Рубрика скрывается после закладки: материал в ней уже опубликован, и экран обязан
        // сказать об этом прямо в строке.
        toggleCategoryVisibility(categoryIdOf('metodika'));

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

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function input(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Осенний бал',
            'slug' => 'osennij-bal',
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

    /**
     * Создание с своим моментом: `updated_at` решает порядок списка, а без разных секунд его
     * решила бы база, и страница первой/второй перестала бы быть предсказуемой.
     *
     * @param array<string, mixed> $input
     */
    private static function create(array $input, int $agoMinutes): string
    {
        $result = createItem($input, self::EDITOR, nowUtc()->sub(new DateInterval('PT' . $agoMinutes . 'M')));

        self::assertTrue($result['ok'], (string) $result['error']);

        return (string) $result['id'];
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
     * @param array<string, string> $extra
     *
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(string $op, string $id, array $extra = []): array
    {
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);
        $fields = [
            '_token' => $this->token($page['body']),
            'op' => $op,
            'id' => $id,
            'back' => NEWS_ADMIN_ITEMS_PATH,
        ];

        return Kernel::post(NEWS_ADMIN_ITEMS_PATH, array_merge($fields, $extra));
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаСводкеРазделаУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin', $page['headers']['location']);
    }

    public function testАнонимНаСпискеРазделаУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fitems', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапретАНеРедирект(): void
    {
        // Редирект на форму потерял бы тело запроса, поэтому приватный POST отвечает сразу.
        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH, ['op' => 'publish', 'id' => 'любой']);

        self::assertSame(403, $response['status']);
    }

    public function testЭкраныРазделаЗакрытыВRobots(): void
    {
        // Критерий §12.5: закрытость админки раздела — свойство ответа сервера, а не манифеста.
        $page = Kernel::get('/robots.txt');

        self::assertStringContainsString('Disallow: /news/admin', $page['body']);
    }

    // ── Сводка ────────────────────────────────────────────

    public function testСводкаСчитаетМатериалыПоВкладкам(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        self::assertStringContainsString('<title>Новости — админка</title>', $page['body']);
        self::assertStringContainsString('всего материалов</dt><dd class="font-medium tabular-nums">24</dd>', $page['body']);
        self::assertStringContainsString('черновиков</dt><dd class="font-medium tabular-nums">1</dd>', $page['body']);
        self::assertStringContainsString('ждут срока</dt><dd class="font-medium tabular-nums">1</dd>', $page['body']);
        self::assertStringContainsString('на сайте</dt><dd class="font-medium tabular-nums">21</dd>', $page['body'], 'без одного материала в скрытой рубрике');
        self::assertStringContainsString('закреплено из 24 материала</dt><dd class="font-medium tabular-nums">6</dd>', $page['body']);
    }

    public function testСводкаПредупреждаетОПерекосеЛенты(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertStringContainsString('Закреплено 6 материалов — это больше 5', $page['body']);
        self::assertStringContainsString('role="alert"', $page['body'], 'Предупреждение обязан прочесть скринридер');
    }

    public function testСводкаНесётПолосуЭкрановРазделаИСсылкуНаЛенту(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_PATH);

        self::assertStringContainsString('aria-label="Экраны раздела «Новости»"', $page['body']);
        self::assertStringContainsString('href="/news/admin" aria-current="page"', $page['body']);
        self::assertStringContainsString('>Материалы</a>', $page['body']);
        self::assertStringContainsString('href="/news"', $page['body'], 'Ссылка на ленту с админки — единственный путь посмотреть результат');
        self::assertStringContainsString('/news/admin/categories', $page['body'], 'С задачи 19 справочники стоят и в полосе вкладок, и панелями на сводке');
    }

    // ── Список ────────────────────────────────────────────

    public function testСписокОткрываетсяИПоказываетПервуюСтраницу(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Материалы — админка</title>', $page['body']);
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'Список закрыт от индексирования, как и сводка');
        self::assertStringContainsString('24 материала', $page['body'], 'Число согласуется с русским plural()');
        self::assertStringContainsString('Закреплённый материал 1', $page['body']);
        self::assertStringContainsString('Материал 11', $page['body'], 'Двадцатая строка первой страницы');
        self::assertStringNotContainsString('Материал 12', $page['body'], 'Одиннадцатая строка уже на второй странице');
        self::assertStringContainsString('/news/admin/categories', $page['body']);
    }

    public function testСписокПредупреждаетОПерекосеТемЖеТекстом(): void
    {
        // Оповещение собирает одна функция (newsPinnedSkewAlert), а экранов у него два. Проверка
        // держится именно на списке: пока текст был переписан в оба шаблона, второй экземпляр
        // не проверялся ни одним тестом и мог расходиться со сводкой.
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertStringContainsString('Закреплено 6 материалов — это больше 5', $page['body']);
        self::assertStringContainsString('role="alert"', $page['body'], 'Предупреждение обязан прочесть скринридер');
    }

    public function testСтрокаСпискаНесётДанныеИКнопки(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH);

        self::assertStringContainsString('опубликована', $page['body'], 'Подпись состояния из NEWS_STATUS_LABEL печатается как в словаре');
        self::assertStringContainsString('закреплена', $page['body']);
        self::assertStringContainsString('Репетиция в закрытой рубрике', $page['body']);
        self::assertStringContainsString('Методика (скрыта)', $page['body'], 'Экран обязан сказать, что рубрика снята с показа');
        self::assertStringContainsString('>Снять с сайта</button>', $page['body']);
        self::assertStringContainsString('>Открепить</button>', $page['body']);
        self::assertStringContainsString('name="back"', $page['body'], 'Действие возвращает в тот же список с теми же условиями');
    }

    public function testВкладкаЧерновикиНеПоказываетОпубликованное(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Черновик поездки', $page['body']);
        self::assertStringNotContainsString('Закреплённый материал 1', $page['body']);
        self::assertStringContainsString('>На печать</button>', $page['body']);
        self::assertStringNotContainsString('>Снять с сайта</button>', $page['body'], 'Черновику снятие не предлагается');

        // Скрытое поле возврата несёт адрес списка вместе с фильтром вкладки: «На печать» из
        // «Черновиков» возвращает в те же «Черновики», а не на полный список. Без этой проверки
        // ветка плана про возврат с фильтрами не закреплена ни одним тестом.
        self::assertStringContainsString(
            'name="back" value="/news/admin/items?status=DRAFT"',
            $page['body'],
            'Действие возвращает в тот же список с той же вкладкой'
        );
    }

    public function testЖдущиеСрокаПоказываютСрокИНеПредлагаютЗакрепление(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?status=scheduled');

        self::assertStringContainsString('Выпускной через час', $page['body']);
        self::assertStringContainsString('>Снять с сайта</button>', $page['body']);
        self::assertStringNotContainsString('>Закрепить</button>', $page['body'], 'Материала в ленте ещё нет — закрепление сервер отклонит');
    }

    public function testПоискРаботаетПоАдресуИНеЛомаетСсылки(): void
    {
        $this->login();
        // Поиск по slug, а не по русскому заголовку: адрес — единственное поле списка, которое
        // в запросе не зависит от кодировки URL.
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=chernovik');

        self::assertStringContainsString('Черновик поездки', $page['body']);
        self::assertStringNotContainsString('Закреплённый материал 1', $page['body'], 'Фильтр показывает одну строку из двадцати четырёх');
        self::assertStringContainsString('value="chernovik"', $page['body'], 'Поле поиска возвращает введённое');
        self::assertStringNotContainsString('Страницы материалов', $page['body'], 'Пагинатор по одной строке не рисуется');
    }

    public function testФильтрПоРубрикеОграничиваетСписок(): void
    {
        $this->login();
        $metodika = categoryIdOf('metodika');
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?cat=' . rawurlencode($metodika));

        self::assertStringContainsString('Репетиция в закрытой рубрике', $page['body']);
        self::assertStringNotContainsString('Закреплённый материал 1', $page['body']);
        self::assertStringContainsString('Методика (скрыта)</a>', $page['body'], 'Чип активной рубрики остаётся ссылкой и говорит, что рубрика снята с показа');
    }

    public function testЧужойФильтрДаётПустойСписокАНеОшибку(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?cat=net-takoj-rubriki');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Под эти условия материалов нет.', $page['body']);
        self::assertStringContainsString('>Сбросить фильтры</a>', $page['body']);
    }

    public function testВтораяСтраницаНесётОстальныеСтроки(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?page=2');

        self::assertStringContainsString('Материал 15', $page['body']);
        self::assertStringContainsString('Материал 12', $page['body']);
        self::assertStringNotContainsString('Материал 11', $page['body']);
        self::assertStringContainsString('aria-current="page">2</span>', $page['body'], 'Текущая страница отмечена, а не ссылки на себя');
        self::assertStringContainsString('href="' . NEWS_ADMIN_ITEMS_PATH . '">1</a>', $page['body'], 'Ссылка на первую страницу ведёт обратно');
    }

    // ── Кнопки строки ─────────────────────────────────────

    public function testПечатьЧерновикаВозвращаетНаСписокСУведомлением(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Собрание кружка',
            'slug' => 'sobranie-kruzhka',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 30);

        $response = $this->press('publish', $id, ['back' => NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT&page=2']);

        self::assertSame(302, $response['status']);
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT&page=2&notice=published',
            $response['headers']['location'],
            'Фильтр и страница теряться не должны'
        );

        // Плашка приезжает ключом в адресе, поэтому читается открытием адреса редиректа:
        // страница без `?notice=` не знает, какое действие только что случилось.
        $page = Kernel::get($response['headers']['location']);
        self::assertStringContainsString(NEWS_NOTICE_TEXT['published'], $page['body']);
        self::assertStringContainsString('role="status"', $page['body']);
    }

    public function testОтказПубликацииБезТекстаЧитаетсяНаТойЖеСтранице(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Пустая заметка',
            'slug' => 'pustaya-zametka',
            'status' => NEWS_STATUS['DRAFT'],
            'body' => [],
        ]), 31);

        $response = $this->press('publish', $id);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringNotContainsString(
            'error=',
            $response['headers']['location'],
            'Причина отказа не должна уезжать в адрес: он остаётся в истории браузера и в access-логе'
        );

        // Отказ читается на экране: заголовок берётся из словаря по ключу, а подробная причина
        // приезжает из сессионного flash. В базе от отказа нет ничего, и страница без flash
        // показать его не обязана.
        $page = Kernel::get($response['headers']['location']);
        self::assertStringContainsString(NEWS_PUBLISH_NEEDS_TEXT, $page['body']);
        self::assertStringContainsString('role="alert"', $page['body']);

        // Строка ищется по адресу, а не читается с первой страницы: у созданной с запасом по
        // времени строки `updated_at` самый старый, и на первой странице её бы не было.
        $row = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=pustaya-zametka');
        self::assertStringContainsString('Пустая заметка', $row['body'], 'Отказ не снял материал с места');
        self::assertStringContainsString('>На печать</button>', $row['body'], 'Отказ не перевёл материал в опубликованные');
    }

    public function testСнятиеОставляетСтрокуВЧерновикахСоСроком(): void
    {
        $this->login();
        $publishedAt = nowUtc()->sub(new DateInterval('PT2H'));
        $id = self::create(self::input([
            'title' => 'Снятая линейка',
            'slug' => 'snytaja-linejka',
            'publishedAt' => $publishedAt,
        ]), 32);

        $response = $this->press('unpublish', $id);
        self::assertStringContainsString('notice=unpublished', $response['headers']['location']);

        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT');
        self::assertStringContainsString('Снятая линейка', $page['body']);
        self::assertStringContainsString(formatRuDateTime($publishedAt), $page['body'], 'Срок после снятия сохраняется');
    }

    public function testЗакреплениеИОткреплениеМеняютНаборКнопок(): void
    {
        $this->login();
        $id = self::create(self::input(['title' => 'Обычная строка', 'slug' => 'obichnaja-strocka']), 33);

        $response = $this->press('pin', $id);
        self::assertStringContainsString('notice=pinned', $response['headers']['location']);

        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=obichnaja-strocka');
        self::assertStringContainsString('>Открепить</button>', $page['body']);
        self::assertStringNotContainsString('>Закрепить</button>', $page['body'], 'Кнопка обязана смениться, а не удваиваться');

        $this->press('unpin', $id);
        $again = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=obichnaja-strocka');
        self::assertStringContainsString('>Закрепить</button>', $again['body']);
    }

    public function testНеизвестноеДействиеОтвечаетТекстомАНеПустотой(): void
    {
        $this->login();
        $id = self::create(self::input(['title' => 'Строка без действий', 'slug' => 'bez-dejstvij']), 34);

        $response = $this->press('teleport', $id);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringContainsString(
            'Неизвестное действие со материалом.',
            Kernel::get($response['headers']['location'])['body']
        );
    }

    public function testЧужойТокенНеВыполняетДействие(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Непечатанный',
            'slug' => 'nepechatannyj',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 35);

        $response = $this->press('publish', $id, ['_token' => 'чужой-токен']);

        self::assertSame(403, $response['status']);
        self::assertSame(
            NEWS_STATUS['DRAFT'],
            (string) value('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]),
            'Отказ CSRF не должен сдвинуть материал'
        );
    }

    public function testЧужойАдресВозвратаНеУводитСРаздела(): void
    {
        $this->login();
        $id = self::create(self::input([
            'title' => 'Возврат наружу',
            'slug' => 'vozvrat-naruzhu',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 36);

        $response = $this->press('publish', $id, ['back' => 'https://evil.example/news/admin/items']);

        self::assertSame(302, $response['status']);
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?notice=published',
            $response['headers']['location'],
            'Открытый редирект из формы — это выход с сайта, поэтому возврат принимается только свой'
        );
    }

    public function testСписокНеОтдаётСыройHTMLВСтроке(): void
    {
        $this->login();
        self::create(self::input([
            'title' => '<script>alert(1)</script>',
            'slug' => 'ekraniruju-titul',
            'status' => NEWS_STATUS['DRAFT'],
        ]), 37);

        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '?q=ekraniruju-titul');

        self::assertStringNotContainsString('<script>alert(1)</script>', $page['body']);
        self::assertStringContainsString('&lt;script&gt;', $page['body']);
    }
}
