<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use DateInterval;
use PHPUnit\Framework\TestCase;

// Публичные страницы проверяются настоящим запросом: статус у черновика, редирект прежнего адреса,
// noindex у поиска и метаданные в <head> видны только так — функция, которая их собирает,
// про ответ сервера ничего не знает.
final class NewsPublicTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');
        self::seedItems();
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
            'title' => 'Вечер встречи',
            'slug' => '',
            'lead' => 'Короткий лид про вечер.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => ['Выпускник'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    private static function seedItems(): void
    {
        $editor = ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];

        createItem(self::input([
            'slug' => 'vecher-vstrechi',
            'body' => [['title' => 'Программа', 'text' => 'Начинаем **в шесть** вечера.']],
        ]), $editor);

        createItem(self::input(['title' => 'Второй вечер', 'slug' => 'vtoroy-vecher']), $editor);

        createItem(self::input([
            'title' => 'Невидимая заметка',
            'slug' => 'chernovik',
            'status' => NEWS_STATUS['DRAFT'],
            'tags' => [],
        ]), $editor);

        createItem(self::input([
            'title' => 'Заметка про будущее',
            'slug' => 'skoro',
            'publishedAt' => nowUtc()->add(new DateInterval('PT3H')),
            'tags' => [],
        ]), $editor);

        $renamed = createItem(self::input([
            'title' => 'Приём в первый класс',
            'slug' => 'novoe-nazvanie',
            'categoryId' => categoryIdOf('postuplenie'),
            'tags' => [],
        ]), $editor);

        saveOldSlugs($renamed['id'], ['staroe-nazvanie']);

        // Снятый материал с прежним адресом — носитель отдельной проверки: у него был публичный
        // адрес, поэтому редирект с него выдал бы наружу и существование материала, и новый slug.
        $withdrawn = createItem(self::input([
            'title' => 'Снятая с публикации заметка',
            'slug' => 'snjataja-zametka',
            'status' => NEWS_STATUS['DRAFT'],
            'tags' => [],
        ]), $editor);

        saveOldSlugs($withdrawn['id'], ['byvshee-nazvanie']);

        // Материал в закрытой рубрике: рубрика снята с показа в setUpBeforeClass, поэтому страница
        // открывается по прямому адресу (§8.4), а чип рубрики обязан печататься без ссылки.
        createItem(self::input([
            'title' => 'Заметка в закрытой рубрике',
            'slug' => 'v-zakrytoj-rubrike',
            'categoryId' => categoryIdOf('metodika'),
            'tags' => [],
        ]), $editor);
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $token);

        Kernel::post('/login', [
            '_token' => $token[1] ?? '',
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    public function testЛентаОткрытаАнонимуИПоказываетТолькоВыпущенное(): void
    {
        $page = Kernel::get('/news');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вечер встречи', $page['body']);
        self::assertStringContainsString('Второй вечер', $page['body']);
        self::assertStringNotContainsString('Невидимая заметка', $page['body'], 'черновика в ленте нет');
        self::assertStringNotContainsString('Заметка про будущее', $page['body'], 'материала с ненаступившим сроком нет');
    }

    public function testЛентаНесётПревьюРаздела(): void
    {
        $page = Kernel::get('/news');

        self::assertStringContainsString('<title>Новости школы</title>', $page['body']);
        self::assertStringContainsString('<meta property="og:type" content="website">', $page['body']);
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost:8080/news">',
            $page['body'],
            'каноник ленты печатает хук page_head'
        );
        self::assertStringNotContainsString('noindex', $page['body'], 'первая страница ленты индексируется');
    }

    public function testСтраницаМатериалаОткрываетсяВсем(): void
    {
        $page = Kernel::get('/news/vecher-vstrechi');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Вечер встречи</title>', $page['body']);
        self::assertStringContainsString('Начинаем <strong>в шесть</strong> вечера.', $page['body'], 'выделение звёздочками');
        self::assertStringContainsString('/news/t/vypusknik', $page['body'], 'тег ведёт на свою страницу');
        self::assertStringContainsString('Программа', $page['body'], 'заголовок абзаца напечатан');
        self::assertStringContainsString('/news/c/zhizn-shkoly', $page['body'], 'ссылка на видимую рубрику остаётся живой');
    }

    public function testСтраницаМатериалаВСкрытойРубрикеНеПечатаетСсылку(): void
    {
        $page = Kernel::get('/news/v-zakrytoj-rubrike');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Заметка в закрытой рубрике', $page['body']);
        self::assertStringContainsString('Методика', $page['body']);
        self::assertStringNotContainsString('/news/c/metodika', $page['body']);
    }

    public function testСтраницаМатериалаНесётРазметкуСтатьи(): void
    {
        $page = Kernel::get('/news/vecher-vstrechi');

        self::assertStringContainsString('<meta property="og:type" content="article">', $page['body']);
        self::assertStringContainsString('<script type="application/ld+json">', $page['body']);
        self::assertStringContainsString('NewsArticle', $page['body']);
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost:8080/news/vecher-vstrechi">',
            $page['body']
        );
    }

    public function testЧерновикОтвечаетТойЖеСтраницейЧтоИЧужойАдрес(): void
    {
        $absent = Kernel::get('/news/net-takogo-materiala');
        $draft = Kernel::get('/news/chernovik');

        self::assertSame(404, $draft['status']);
        self::assertSame($absent['body'], $draft['body'], 'по ответу нельзя узнать, что материал существует');
        self::assertStringNotContainsString('Невидимая заметка', $draft['body']);
    }

    public function testМатериалСНенаступившимСрокомОтвечаетТойЖеСтраницей(): void
    {
        $page = Kernel::get('/news/skoro');

        self::assertSame(404, $page['status']);
        self::assertStringContainsString('Страницы нет', $page['body']);
        self::assertStringNotContainsString('Заметка про будущее', $page['body']);
    }

    public function testПрежнийАдресПереноситНаНовый(): void
    {
        $page = Kernel::get('/news/staroe-nazvanie');

        self::assertSame(301, $page['status']);
        self::assertSame('/news/novoe-nazvanie', $page['headers']['location']);
    }

    public function testПрежнийАдресЗакрытогоМатериалаОтвечаетКакНесуществующий(): void
    {
        $page = Kernel::get('/news/byvshee-nazvanie');
        $unknown = Kernel::get('/news/net-takogo-adresa');

        self::assertSame(404, $page['status'], 'снятый материал не редиректит анонима');
        self::assertStringNotContainsString('snjataja-zametka', $page['body'], 'новый адрес не утекает');
        self::assertSame($unknown['body'], $page['body'], 'ответ совпадает с ответом на чужой адрес');
    }

    public function testРедакторуПрежнийАдресЗакрытогоМатериалаОткрываетПредпросмотр(): void
    {
        $this->login();
        $page = Kernel::get('/news/byvshee-nazvanie');

        self::assertSame(301, $page['status']);
        self::assertSame('/news/snjataja-zametka?draft=1', $page['headers']['location']);
    }

    public function testРедакторВидитЧерновикПоСсылкеПредпросмотра(): void
    {
        $this->login();
        $page = Kernel::get('/news/chernovik?draft=1');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Невидимая заметка', $page['body']);
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'предпросмотр не индексируется');
        self::assertStringContainsString('Так материал видят редакторы', $page['body']);
        self::assertStringNotContainsString('application/ld+json', $page['body'], 'разметки статьи у черновика нет');
    }

    public function testАнонимуПредпросмотрНеОтдаётся(): void
    {
        $page = Kernel::get('/news/chernovik?draft=1');

        self::assertSame(404, $page['status']);
    }

    public function testСтраницаРубрикиПоказываетЕёМатериалы(): void
    {
        $page = Kernel::get('/news/c/zhizn-shkoly');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Жизнь школы · Новости школы</title>', $page['body']);
        self::assertStringContainsString('Вечер встречи', $page['body']);
        self::assertStringNotContainsString('Приём в первый класс', $page['body'], 'чужая рубрика не показывается');
        self::assertStringContainsString(
            '<link rel="canonical" href="http://localhost:8080/news/c/zhizn-shkoly">',
            $page['body']
        );
    }

    public function testСкрытаяРубрикаНеОткрывается(): void
    {
        $page = Kernel::get('/news/c/metodika');

        self::assertSame(404, $page['status']);
        self::assertStringNotContainsString('Методика', $page['body']);
    }

    public function testСтраницаТегаНеИндексируется(): void
    {
        $page = Kernel::get('/news/t/vypusknik');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'подборки по тегам в индекс не идут');
        self::assertStringContainsString('Материалы по тегу', $page['body']);
        self::assertStringContainsString('Вечер встречи', $page['body']);
    }

    public function testПоискНаходитИСнятСИндекса(): void
    {
        $page = Kernel::get('/news?q=' . rawurlencode('первый'));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Приём в первый класс', $page['body']);
        self::assertStringNotContainsString('Вечер встречи', $page['body'], 'чужой материал в результаты не попадает');
        self::assertStringContainsString('noindex, nofollow', $page['body'], 'результаты поиска не индексируются');
    }

    public function testУМатериалаВидноПохожиеИзЕгоРубрики(): void
    {
        // Виджет переехал в блок зоны `article`: заголовок человек задаёт на экране «Блоки», и
        // на странице он обязан прозвучать ровно один раз — второй значил бы, что старый виджет
        // не удалён (критерий 4 §6 спецификации).
        $page = Kernel::get('/news/vecher-vstrechi');

        self::assertStringContainsString('<section class="region region-article">', $page['body']);
        self::assertStringContainsString('Похожие материалы', $page['body'], 'заводской блок зоны article');
        self::assertStringContainsString('Второй вечер', $page['body']);
        self::assertStringNotContainsString('Приём в первый класс', $page['body'], 'чужая рубрика в блоке не появляется');
        self::assertStringNotContainsString('Читайте также', $page['body'], 'старый заголовок виджета снят вместе с виджетом');
        self::assertSame(1, substr_count((string) $page['body'], 'Похожие материалы'), 'один блок — одна печать заголовка');
    }

    public function testНаГлавнойБлокСвежихМатериалов(): void
    {
        // Зона `home` живёт в теме, а не в разделе: если подписка подключена только к шаблонам
        // «Новостей», эта проверка красна, и наоборот — она же доказывает, что ядро зовёт
        // приложение, а не наоборот.
        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<section class="region region-home">', $page['body']);
        self::assertStringContainsString('Свежие материалы', $page['body']);
        self::assertStringContainsString('/news/vecher-vstrechi', $page['body'], 'карточка блока ведёт на материал');
    }

    public function testЗонаБезБлоковНеПечатаетОбёртку(): void
    {
        // В зоне `category` заводских строк нет и человек их не заводил: страница-список обязана
        // остаться ровно такой, какой была до слоя (критерий 1 приёмки).
        $page = Kernel::get('/news');

        self::assertStringNotContainsString('<section class="region', $page['body']);
    }
}
