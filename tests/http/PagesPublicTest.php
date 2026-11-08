<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Публичный рендер страниц проверяется настоящим запросом: статус у черновика, 301 прежнего
 * адреса, noindex и карта сайтов видны только в ответе сервера — функция, которая их собирает,
 * про HTTP ничего не знает (механика NewsPublicTest).
 */
final class PagesPublicTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        self::seedPages();

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

    private static function seedPages(): void
    {
        // Опубликованная страница с текстовой секцией: проза — проба диспетчера (задача 5
        // добирает остальные виды).
        $about = createPage([
            'title' => 'О нас',
            'slug' => 'o-nas',
            'description' => 'Школа английского языка: кто мы',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());

        createSection((string) $about['id'], [
            'kind' => 'prose',
            'anchor' => 'o-shkole',
            'badge' => 'Знакомство',
            'title' => 'Школа на карте',
            'lead_text' => "Мы учим взрослых и детей.\n\nПростые <b>правила</b> — честный текст.",
            'note' => 'Обновлено в этом году',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        // Секция с чужим сырым текстом: ядро печатает его экранированным.
        createSection((string) $about['id'], [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Сырьё',
            'lead_text' => 'Тег <b>не живёт</b> в секции.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        // Черновик: гостю — как несуществующий, редактору — предпросмотр.
        $draft = createPage([
            'title' => 'Черновик',
            'slug' => 'chernovik',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], nowUtc());

        createSection((string) $draft['id'], [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Черновой текст',
            'lead_text' => 'Никому не показывать.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        // Переселение адреса: прежний адрес ведёт на новый 301-м.
        $moved = createPage([
            'title' => 'Расписание',
            'slug' => 'staroe-raspisanie',
            'description' => '',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());

        updatePage((string) $moved['id'], [
            'title' => 'Расписание',
            'slug' => 'raspisanie',
            'description' => '',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());

        // Прежний адрес у черновика: редирект выдал бы существование скрытой страницы.
        $withdrawn = createPage([
            'title' => 'Снятая',
            'slug' => 'skrytoe-nazvanie',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], nowUtc());

        updatePage((string) $withdrawn['id'], [
            'title' => 'Снятая',
            'slug' => 'skrytaya-stranitsa',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], nowUtc());

        // Индексируемая и закрытая от поиска опубликованные страницы — для карты сайтов.
        createPage([
            'title' => 'Открытая поисковику',
            'slug' => 'otkrytaya',
            'description' => '',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());

        createPage([
            'title' => 'Закрытая от поиска',
            'slug' => 'zakrytaya-ot-poiska',
            'description' => '',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '1',
        ], nowUtc());

        // Секция главной: «/» собирается из блоков без строки Page.
        createSection(PAGES_HOME_ID, [
            'kind' => 'prose',
            'anchor' => 'privet',
            'badge' => 'Главная',
            'title' => 'Добро пожаловать',
            'lead_text' => 'Секции главной идут как блоки.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        // Страница с видами секций: каждый шаблон проверяется настоящим запросом.
        $values = static fn (array $overrides): array => array_merge([
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => '',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], $overrides);

        $vidy = createPage([
            'title' => 'Виды секций',
            'slug' => 'vidy',
            'description' => 'Проба видов ядра',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());

        // Содержание стоит первым блоком страницы, а ссылается на места, которые напечатались:
        // список собирается после рендера остальных секций, поэтому порядок блоков ему не мешает.
        createSection((string) $vidy['id'], $values([
            'kind' => 'toc',
            'anchor' => 'soderzhanie',
            'title' => 'Содержание страницы',
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'hero',
            'anchor' => 'verh',
            'badge' => 'Набор групп',
            'title' => 'Школа английского языка',
            'lead_text' => "Уроки для взрослых и детей.\n\nВторой абзац лида.",
            'note' => 'Набор идёт до сентября',
            'image' => '/uploads/images/hero.jpg',
            'image_alt' => 'Кабинет школы',
            'buttons' => [
                ['label' => 'Записаться', 'href' => '/o-nas'],
                ['label' => 'Мы в соцсетях', 'href' => 'https://example.com', 'blank' => '1'],
            ],
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'band',
            'anchor' => 'polosa',
            'title' => 'Интенсивы',
            'lead_text' => 'Две недели погружения в язык.',
            'note' => 'Старт каждый понедельник',
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'cards',
            'title' => 'Программы',
            'lead_text' => 'Выберите своё направление.',
            'items' => [
                ['title' => 'Разговорный клуб', 'text' => 'Практика речи по средам.', 'href' => '/o-nas', 'image' => '/uploads/images/card.jpg', 'note' => 'Популярно'],
                ['title' => 'Грамматика', 'text' => 'Система с нуля.', 'href' => '', 'image' => '', 'note' => ''],
            ],
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'steps',
            'title' => 'Как начать',
            'items' => [
                ['title' => 'Заявка', 'text' => 'Оставьте заявку на сайте.'],
                ['title' => 'Пробный урок', 'text' => 'Приходите на пробное занятие.'],
            ],
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'split',
            'title' => 'Как проходит урок',
            'lead_text' => 'Сначала разминка, потом практика.',
            'note' => 'Длительность — час',
            'image' => '/uploads/images/lesson.jpg',
            'image_alt' => 'Урок в классе',
            'items' => [
                ['title' => 'Разминка', 'text' => 'Пять минут на повторение.'],
                ['title' => '', 'text' => ''],
            ],
            'buttons' => [['label' => 'Смотреть программу', 'href' => '/o-nas']],
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'quote',
            'title' => 'Говорят ученики',
            'items' => [
                ['title' => 'Мария', 'text' => 'Через месяц заговорила.', 'note' => 'A2 → B1'],
                ['title' => 'Пустая цитата', 'text' => '', 'note' => 'Не печатается'],
            ],
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'cta',
            'title' => 'Запишитесь на пробный урок',
            'lead_text' => 'Первое занятие бесплатно.',
            'buttons' => [['label' => 'Оставить заявку', 'href' => '/o-nas']],
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'pricing',
            'title' => 'Стоимость обучения',
            'lead_text' => 'Платите за месяц, без взносов.',
            'note' => 'Цены без НДС',
            'items' => [
                ['title' => 'Утро', 'note' => '2 900 ₽ / месяц', 'text' => 'Два урока в неделю.', 'href' => '/o-nas'],
                ['title' => 'Вечер', 'note' => '3 400 ₽ / месяц', 'text' => '', 'href' => ''],
                ['title' => '', 'note' => '', 'text' => 'Обрывок без названия', 'href' => '/o-nas'],
            ],
        ]), nowUtc());

        // Место с якорем, которое ничего не печатает: в содержание оно попасть не должно —
        // ссылка вела бы в пустоту.
        createSection((string) $vidy['id'], $values([
            'kind' => 'quote',
            'anchor' => 'pustye-otzyvy',
            'title' => 'Пустые отзывы',
            'items' => [['title' => 'Автор без текста', 'text' => '']],
        ]), nowUtc());

        // Галерея: кадр без картинки в сетку не идёт, подпись кадра служит описанием картинки.
        createSection((string) $vidy['id'], $values([
            'kind' => 'gallery',
            'anchor' => 'fotografii',
            'title' => 'Как это выглядит',
            'lead_text' => 'Кадры из классов.',
            'note' => 'Снято в этом году',
            'items' => [
                ['image' => '/uploads/images/gallery-1.jpg', 'title' => 'Разговорный клуб', 'note' => 'Среда'],
                ['image' => '/uploads/images/gallery-2.jpg', 'title' => '', 'note' => 'Без подписи'],
                ['image' => '', 'title' => 'Кадр без картинки', 'note' => ''],
            ],
        ]), nowUtc());

        // Соседние места: строка без адреса ссылкой не становится.
        createSection((string) $vidy['id'], $values([
            'kind' => 'related',
            'anchor' => 'sosednee',
            'title' => 'Смотрите также',
            'items' => [
                ['title' => 'Программа курса', 'text' => 'Модули и уроки.', 'href' => '/o-nas'],
                ['title' => 'Без адреса', 'text' => 'Остаётся текстом.', 'href' => ''],
            ],
        ]), nowUtc());

        createSection((string) $vidy['id'], $values([
            'kind' => 'faq',
            'title' => 'Частые вопросы',
            'items' => [
                ['title' => 'Сколько длится урок?', 'text' => 'Шестьдесят минут.'],
                ['title' => 'Бывают ли инъекции?', 'text' => '</script><script>alert(1)</script>'],
                ['title' => 'Есть ли скидки?', 'text' => ''],
            ],
        ]), nowUtc());
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

    public function testГлавнаяБезСекцийОтвечаетЗаглушкой(): void
    {
        // Секции главной живут в той же базе: чистим их, чтобы увидеть ветку «пусто».
        run('DELETE FROM ' . t('page_section') . ' WHERE page_id = ?', [PAGES_HOME_ID]);

        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Сайт собирается на собственной CMS', $page['body'], 'пустая главная не ломает установку');

        // Возвращаем секцию: остальные проверки рассчитывают на неё.
        createSection(PAGES_HOME_ID, [
            'kind' => 'prose',
            'anchor' => 'privet',
            'badge' => 'Главная',
            'title' => 'Добро пожаловать',
            'lead_text' => 'Секции главной идут как блоки.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());
    }

    public function testГлавнаяРисуетСекцииИзБлоков(): void
    {
        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Добро пожаловать', $page['body'], 'секция главной печатается на «/»');
        self::assertStringContainsString('id="privet"', $page['body'], 'якорь секции становится id обёртки');
        self::assertStringContainsString('Секции главной идут как блоки.', $page['body']);
    }

    public function testОпубликованнаяСтраницаОткрываетсяВсем(): void
    {
        $page = Kernel::get('/o-nas');

        self::assertSame(200, $page['status'], 'опубликованная страница открывается гостю');
        self::assertStringContainsString('<title>О нас</title>', $page['body']);
        self::assertStringContainsString('Школа английского языка: кто мы', $page['body'], 'описание страницы идёт в meta');
        self::assertStringContainsString('Мы учим взрослых и детей.', $page['body'], 'лид секции напечатан');
        self::assertStringContainsString('id="o-shkole"', $page['body'], 'якорь секции печатается как id обёртки');
        self::assertStringContainsString('Обновлено в этом году', $page['body'], 'примечание секции напечатано');
        self::assertStringNotContainsString('noindex', $page['body'], 'опубликованная страница индексируется');
    }

    public function testСыройТекстВСекцииЭкранируется(): void
    {
        $page = Kernel::get('/o-nas');

        self::assertStringContainsString(
            'Тег &lt;b&gt;не живёт&lt;/b&gt; в секции.',
            $page['body'],
            'HTML в тексте секции печатается как текст, а не исполняется'
        );
        self::assertStringNotContainsString('<b>правила</b>', $page['body'], 'выделение из текста не превращается в разметку');
    }

    public function testHeroПечатаетКартинкуКнопкиИЦели(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('~<h1\b[^>]*>Школа английского языка</h1>~', $page['body'], 'hero печатает заголовок первым уровнем');
        self::assertStringContainsString('Уроки для взрослых и детей.', $page['body'], 'лид hero напечатан');
        self::assertStringContainsString('Второй абзац лида.', $page['body'], 'перенос в лиде собирает абзацы');
        self::assertStringContainsString('src="/uploads/images/hero.jpg"', $page['body'], 'картинка hero печатается');
        self::assertStringContainsString('alt="Кабинет школы"', $page['body'], 'у картинки есть описание для скринридера');
        self::assertStringContainsString('Набор идёт до сентября', $page['body'], 'примечание hero напечатано');
        self::assertMatchesRegularExpression('~href="/o-nas"[^>]*>Записаться</a>~', $page['body'], 'кнопка hero ведёт на внутренний адрес');
        self::assertMatchesRegularExpression(
            '~target="_blank"[^>]*rel="noopener noreferrer"[^>]*>Мы в соцсетях</a>~',
            $page['body'],
            'внешняя кнопка открывается в новой вкладке без window.opener'
        );
        self::assertStringContainsString('min-h-[44px]', $page['body'], 'кнопки секций тапабельные');
    }

    public function testBandВыделяетсяПолосойИПечатаетТексты(): void
    {
        $page = Kernel::get('/vidy');

        self::assertStringContainsString('Интенсивы', $page['body']);
        self::assertStringContainsString('Две недели погружения в язык.', $page['body']);
        self::assertStringContainsString('Старт каждый понедельник', $page['body']);
        self::assertMatchesRegularExpression('~<section\b[^>]*class="[^"]*\bbg-ink/5\b[^"]*"[^>]*>~', $page['body'], 'band — выделенная полоса на подложке');
    }

    public function testCardsРисуютКарточкиСсылкамиИБез(): void
    {
        $page = Kernel::get('/vidy');

        self::assertStringContainsString('Программы', $page['body']);
        self::assertStringContainsString('Выберите своё направление.', $page['body']);
        self::assertMatchesRegularExpression('~<a\b[^>]*href="/o-nas"[^>]*>\s*Разговорный клуб</a>~s', $page['body'], 'карточка с адресом печатает ссылку');
        self::assertStringContainsString('src="/uploads/images/card.jpg"', $page['body'], 'картинка карточки печатается');
        self::assertStringContainsString('Практика речи по средам.', $page['body']);
        self::assertStringContainsString('Популярно', $page['body']);
        self::assertStringContainsString('Грамматика', $page['body'], 'карточка без адреса печатается текстом');
        self::assertStringContainsString('Система с нуля.', $page['body']);
    }

    public function testStepsНумеруютШагиПоПорядку(): void
    {
        $page = Kernel::get('/vidy');

        self::assertStringContainsString('Как начать', $page['body']);
        self::assertMatchesRegularExpression('~<ol\b[^>]*>.*?Заявка.*?Пробный урок.*?</ol>~s', $page['body'], 'шаги — нумерованный список по порядку');
        self::assertStringContainsString('Оставьте заявку на сайте.', $page['body']);
        self::assertStringContainsString('Приходите на пробное занятие.', $page['body']);
    }

    public function testSplitДелитПолосуНаТекстИКартинку(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Как проходит урок', $page['body']);
        self::assertStringContainsString('Сначала разминка, потом практика.', $page['body'], 'лид split напечатан');
        self::assertStringContainsString('Разминка', $page['body'], 'пункт списка напечатан');
        self::assertStringContainsString('Пять минут на повторение.', $page['body']);
        self::assertStringContainsString('Длительность — час', $page['body'], 'примечание split напечатано');
        self::assertStringContainsString('src="/uploads/images/lesson.jpg"', $page['body'], 'картинка split печатается');
        self::assertStringContainsString('alt="Урок в классе"', $page['body'], 'у картинки есть описание');
        self::assertMatchesRegularExpression('~href="/o-nas"[^>]*>Смотреть программу</a>~', $page['body'], 'кнопка split ведёт на программу');
        self::assertMatchesRegularExpression('~class="[^"]*\bsm:grid-cols-2\b[^"]*"~', $page['body'], 'split — две колонки на широком экране');
    }

    public function testQuoteПечатаетЦитатуСАвторомИМолчитНаПустой(): void
    {
        $page = Kernel::get('/vidy');

        self::assertStringContainsString('Говорят ученики', $page['body'], 'заголовок блока цитат напечатан');
        self::assertMatchesRegularExpression('~<blockquote\b[^>]*>Через месяц заговорила\.</blockquote>~', $page['body'], 'цитата печатается выдержкой');
        self::assertStringContainsString('Мария', $page['body'], 'автор цитаты назван');
        self::assertStringContainsString('A2 → B1', $page['body'], 'подпись к автору напечатана');
        self::assertStringNotContainsString('Пустая цитата', $page['body'], 'цитата без текста не печатается даже с автором');
    }

    public function testGalleryПечатаетКадрыСПодписями(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Как это выглядит', $page['body'], 'заголовок галереи напечатан');
        self::assertStringContainsString('Кадры из классов.', $page['body'], 'лид галереи напечатан');
        self::assertStringContainsString('src="/uploads/images/gallery-1.jpg"', $page['body'], 'кадр печатается картинкой');
        self::assertStringContainsString('alt="Разговорный клуб"', $page['body'], 'подпись кадра служит описанием картинки');
        self::assertMatchesRegularExpression(
            '~<figcaption\b[^>]*>.*?Разговорный клуб.*?</figcaption>~s',
            $page['body'],
            'подпись кадра печатается под ним'
        );
        self::assertStringContainsString('Среда', $page['body'], 'место съёмки напечатано');
        self::assertStringContainsString('src="/uploads/images/gallery-2.jpg"', $page['body'], 'кадр без подписи печатается');
        self::assertStringNotContainsString('Кадр без картинки', $page['body'], 'кадр без картинки не печатается');
        self::assertStringContainsString('Снято в этом году', $page['body'], 'примечание блока — подпись под галереей');
    }

    public function testRelatedВедётНаСоседниеМестаИМолчитНаСтрокеБезАдреса(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Смотрите также', $page['body'], 'заголовок блока напечатан');
        self::assertMatchesRegularExpression(
            '~<a\b[^>]*href="/o-nas"[^>]*>\s*Программа курса</a>~s',
            $page['body'],
            'соседнее место печатается ссылкой'
        );
        self::assertStringContainsString('Модули и уроки.', $page['body'], 'пояснение к ссылке напечатано');
        self::assertStringNotContainsString('Без адреса', $page['body'], 'строка без адреса ссылкой не становится');
        self::assertStringNotContainsString('Остаётся текстом.', $page['body'], 'пояснение обрывка тоже не печатается');
    }

    public function testCtaПечатаетПризывСКнопкой(): void
    {
        $page = Kernel::get('/vidy');

        self::assertStringContainsString('Запишитесь на пробный урок', $page['body']);
        self::assertStringContainsString('Первое занятие бесплатно.', $page['body']);
        self::assertMatchesRegularExpression('~href="/o-nas"[^>]*>Оставить заявку</a>~', $page['body'], 'кнопка призыва ведёт на страницу записи');
        self::assertMatchesRegularExpression('~<section\b[^>]*class="[^"]*\bbg-ink[\s"][^"]*"[^>]*>~', $page['body'], 'cta — тёмная полоса-призыв');
    }

    public function testFaqПечатаетВопросыИРазметкуFaqPage(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Частые вопросы', $page['body']);
        self::assertStringContainsString('Сколько длится урок?', $page['body'], 'вопрос напечатан для человека');
        self::assertStringContainsString('Шестьдесят минут.', $page['body'], 'ответ напечатан для человека');
        self::assertStringContainsString('application/ld+json', $page['body'], 'FAQ печатает разметку JSON-LD');

        preg_match('~<script type="application/ld\+json">(.+?)</script>~s', $page['body'], $ld);

        self::assertNotEmpty($ld, 'фрагмент JSON-LD найден');
        self::assertStringContainsString('FAQPage', $ld[1]);
        self::assertStringContainsString('Сколько длится урок?', $ld[1], 'вопрос в разметке для поисковика');
        self::assertStringContainsString('Шестьдесят минут.', $ld[1], 'ответ в разметке для поисковика');
        self::assertStringNotContainsString('Есть ли скидки?', $ld[1], 'пара без ответа в разметку не идёт');

        // Разметка едет в head: печатается раньше закрытия головы страницы.
        $headEnd = strpos($page['body'], '</head>');
        $ldPos = strpos($page['body'], 'application/ld+json');

        self::assertNotFalse($headEnd);
        self::assertNotFalse($ldPos);
        self::assertTrue($ldPos < $headEnd, 'JSON-LD обязан быть в head, а не в теле страницы');

        // Сырьё в ответе не разрывает script-блок: «<» экранируется в \u003C.
        self::assertStringNotContainsString('</script><script>', $page['body'], 'инъекция в ответе не разрывает JSON-LD');
    }

    public function testPricingПечатаетТарифыЦенуИКнопку(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Стоимость обучения', $page['body']);
        self::assertStringContainsString('Платите за месяц, без взносов.', $page['body'], 'лид прайса напечатан');
        self::assertStringContainsString('Утро', $page['body'], 'название тарифа напечатано');
        self::assertStringContainsString('2 900 ₽ / месяц', $page['body'], 'цена тарифа напечатана');
        self::assertStringContainsString('Два урока в неделю.', $page['body'], 'описание тарифа напечатано');
        self::assertMatchesRegularExpression('~href="/o-nas"[^>]*>Выбрать</a>~', $page['body'], 'у тарифа с адресом есть кнопка');
        self::assertStringContainsString('Цены без НДС', $page['body'], 'примечание блока — сноска под тарифами');
        self::assertStringContainsString('Вечер', $page['body'], 'тариф без кнопки печатается текстом');
        self::assertSame(1, substr_count($page['body'], '>Выбрать</a>'), 'кнопка есть только у тарифа с адресом');
        self::assertStringNotContainsString('Обрывок без названия', $page['body'], 'тариф без названия не печатается');
    }

    public function testСодержаниеСсылаетсяНаНапечатанныеМеста(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression(
            '~<nav\b[^>]*aria-label="Содержание"[^>]*>~',
            $page['body'],
            'содержание печатается навигацией, а не простым списком'
        );
        self::assertStringContainsString('Содержание страницы', $page['body'], 'заголовок блока напечатан');
        self::assertMatchesRegularExpression(
            '~href="#verh"[^>]*>Школа английского языка</a>~',
            $page['body'],
            'пункт ведёт на место первого экрана'
        );
        self::assertMatchesRegularExpression(
            '~href="#polosa"[^>]*>Интенсивы</a>~',
            $page['body'],
            'второй пункт ведёт на следующее место страницы'
        );
        self::assertStringNotContainsString(
            'href="#pustye-otzyvy"',
            $page['body'],
            'место, которое ничего не напечатало, в содержание не попадает'
        );
        self::assertStringNotContainsString('href="#soderzhanie"', $page['body'], 'содержание не ссылается само на себя');
    }

    public function testЧерновикГостюОтвечаетКакНесуществующий(): void
    {
        $absent = Kernel::get('/net-takoj-stranitsy');
        $draft = Kernel::get('/chernovik');

        self::assertSame(404, $draft['status']);
        self::assertSame($absent['body'], $draft['body'], 'по ответу нельзя узнать, что черновик существует');
        self::assertStringNotContainsString('Черновой текст', $draft['body']);
    }

    public function testЧерновикВиденРедакторуПредпросмотром(): void
    {
        $this->login();

        $page = Kernel::get('/chernovik');

        self::assertSame(200, $page['status'], 'редактор с правом manage видит предпросмотр прямым адресом');
        self::assertStringContainsString('Черновой текст', $page['body']);
        self::assertStringContainsString('noindex', $page['body'], 'предпросмотр черновика не индексируется');
    }

    public function testПрежнийАдресПереноситНовый(): void
    {
        $page = Kernel::get('/staroe-raspisanie');

        self::assertSame(301, $page['status']);
        self::assertSame('/raspisanie', $page['headers']['location']);
    }

    public function testПрежнийАдресЧерновикаОтвечаетКакНесуществующий(): void
    {
        $page = Kernel::get('/skrytoe-nazvanie');

        self::assertSame(404, $page['status'], '301 с адреса черновика выдал бы его существование');
        self::assertStringNotContainsString('skrytaya-stranitsa', $page['body']);
    }

    public function testКартаSitesВедётОпубликованныеИндексируемыеСтраницы(): void
    {
        $page = Kernel::get('/sitemap.xml');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('/o-nas', $page['body']);
        self::assertStringContainsString('/otkrytaya', $page['body']);
        self::assertStringNotContainsString('/zakrytaya-ot-poiska', $page['body'], 'noindex-страница не попадает в карту');
        self::assertStringNotContainsString('/chernovik', $page['body'], 'черновики в карте нет');
        self::assertStringContainsString('/raspisanie', $page['body'], 'опубликованная индексируемая страница в карте');
    }

    public function testЗакрытаяОтПоискаСтраницаЖивётСМетаНоиндекс(): void
    {
        $page = Kernel::get('/zakrytaya-ot-poiska');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('noindex', $page['body'], 'noindex страницы читается рендером');
    }
}
