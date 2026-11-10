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
            'layout' => '',
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

        // Ведомость: строка — характеристика (заголовок строки), значение и примечание. Строка без
        // названия и строка без значения не печатаются — безымянная ячейка ничего не значит.
        createSection((string) $vidy['id'], $values([
            'kind' => 'table',
            'anchor' => 'usloviya',
            'title' => 'Условия обучения',
            'lead_text' => 'Что входит в абонемент.',
            'note' => 'Цены указаны в рублях',
            'items' => [
                ['title' => 'Абонемент', 'text' => '12 занятий', 'note' => 'Месяц'],
                ['title' => 'Пробный урок', 'text' => 'Бесплатно', 'note' => ''],
                ['title' => '', 'text' => 'Ячейка без названия', 'note' => ''],
                ['title' => 'Обрывок без значения', 'text' => '', 'note' => ''],
            ],
        ]), nowUtc());

        // Ведомость без строк: заголовок блока заполнен, печатать нечего — пустое место.
        createSection((string) $vidy['id'], $values([
            'kind' => 'table',
            'anchor' => 'pustaya-vedomost',
            'title' => 'Ведомость без строк',
        ]), nowUtc());

        // Вкладки: без скрипта переключение держит сам браузер — взаимоисключающие details одной
        // группы, открыта первая вкладка. Вкладка без названия не печатается.
        createSection((string) $vidy['id'], $values([
            'kind' => 'tabs',
            'anchor' => 'urovni',
            'title' => 'Программы по уровням',
            'lead_text' => 'Выберите свой уровень.',
            'items' => [
                ['title' => 'Начальный', 'text' => 'A1 — алфавит и первые фразы.'],
                ['title' => 'Средний', 'text' => 'B1 — свободная речь.'],
                ['title' => '', 'text' => 'Вкладка без названия'],
            ],
        ]), nowUtc());

        // Баннер: картинка на подложке, поверх текст и карточки «до/после». Карточка без текста
        // не печатается — пустая плашка «до/после» ничего не сообщает.
        createSection((string) $vidy['id'], $values([
            'kind' => 'banner',
            'anchor' => 'aktsiya',
            'title' => 'Летний интенсив',
            'lead_text' => 'Две недели погружения в язык.',
            'note' => 'Места ограничены',
            'image' => '/uploads/images/banner.jpg',
            'image_alt' => 'Класс летом',
            'items' => [
                ['note' => 'До', 'text' => 'Разрозненные темы.'],
                ['note' => 'После', 'text' => 'Система и свободная речь.'],
                ['note' => 'Обрывок', 'text' => ''],
            ],
            'buttons' => [['label' => 'Узнать программу', 'href' => '/o-nas']],
        ]), nowUtc());

        // Видео: плеером становится только собственный видеофайл сайта — чужая площадка и
        // не-видео файл под CSP не показываются вовсе.
        createSection((string) $vidy['id'], $values([
            'kind' => 'video',
            'anchor' => 'roliki',
            'title' => 'Видео с уроков',
            'lead_text' => 'Короткие ролики о школе.',
            'items' => [
                ['video' => '/videos/lesson.mp4', 'image' => '/uploads/images/lesson-poster.jpg', 'title' => 'Урок', 'text' => 'Фрагмент занятия.'],
                ['video' => 'https://www.youtube.com/watch?v=abc', 'image' => '', 'title' => 'Чужой ролик', 'text' => ''],
                ['video' => '/files/plan.pdf', 'image' => '', 'title' => 'Не видео', 'text' => ''],
            ],
        ]), nowUtc());

        // Карусель: слайд — это картинка с необязательными подписью, текстом и надписью; слайд
        // без картинки не печатается. Переключение держит горизонтальная прокрутка — публичные
        // страницы живут без собственного скрипта.
        createSection((string) $vidy['id'], $values([
            'kind' => 'slider',
            'anchor' => 'karusel',
            'title' => 'Как мы работаем',
            'lead_text' => 'Листайте слайды горизонтально.',
            'items' => [
                ['image' => '/uploads/images/slide-1.jpg', 'title' => 'Первый слайд', 'text' => 'Знакомство и пробный урок.', 'note' => 'Шаг 1'],
                ['image' => '/uploads/images/slide-2.jpg', 'title' => '', 'text' => 'Практика в группе.', 'note' => ''],
                ['image' => '', 'title' => 'Пустой слайд', 'text' => '', 'note' => ''],
            ],
        ]), nowUtc());

        // Отзывы: карточка — фото, имя, текст отзыва и роль. Отзыв без текста не печатается.
        createSection((string) $vidy['id'], $values([
            'kind' => 'review',
            'anchor' => 'otzyvy-uchenikov',
            'title' => 'Отзывы учеников',
            'lead_text' => 'Что говорят после курса.',
            'items' => [
                ['image' => '/uploads/images/student.jpg', 'title' => 'Мария', 'text' => 'Заговорила за месяц.', 'note' => 'A2 → B1'],
                ['image' => '', 'title' => 'Иван', 'text' => 'Грамматика наконец понятна.', 'note' => ''],
                ['image' => '', 'title' => 'Пустой отзыв', 'text' => '', 'note' => ''],
            ],
        ]), nowUtc());

        // Плавающая картинка: на широком экране текст обтекает картинку с выбранной стороны,
        // на узком картинка занимает всю ширину. Абзацы — items (заголовок и текст).
        createSection((string) $vidy['id'], $values([
            'kind' => 'float',
            'anchor' => 'obtekanie',
            'title' => 'Метод школы',
            'lead_text' => 'Учим говорить с первого занятия.',
            'note' => 'Методика проверена годами',
            'image' => '/uploads/images/float.jpg',
            'image_alt' => 'Занятие в классе',
            'layout' => 'right',
            'items' => [
                ['title' => 'Разминка', 'text' => 'Повторяем прошлый урок.'],
                ['title' => 'Практика', 'text' => 'Говорим новую тему.'],
                ['title' => '', 'text' => ''],
            ],
        ]), nowUtc());

        // Материалы: файлы со ссылками и плашкой формата из адреса; layout «cards» — сетка.
        createSection((string) $vidy['id'], $values([
            'kind' => 'files',
            'anchor' => 'materialy',
            'title' => 'Материалы для скачивания',
            'lead_text' => 'Памятки и домашние задания.',
            'note' => 'Файлы лежат на сайте',
            'items' => [
                ['title' => 'Памятка по временам', 'text' => 'Таблица глаголов.', 'href' => '/files/tenses.pdf', 'note' => '1 МБ'],
                ['title' => 'Домашнее задание', 'text' => 'Страница 12.', 'href' => '/files/homework.docx', 'note' => ''],
                ['title' => '', 'text' => 'Файл без названия', 'href' => '/files/x.pdf', 'note' => ''],
            ],
        ]), nowUtc());

        // Контакты: строка — способ связи. title — подпись («Телефон», «Адрес»), href/text —
        // значение (ссылка, если адрес есть), note — примечание. Строка без значения не печатается:
        // подпись без телефона или адреса ничего не сообщает.
        createSection((string) $vidy['id'], $values([
            'kind' => 'contacts',
            'anchor' => 'kontakty',
            'title' => 'Как с нами связаться',
            'lead_text' => 'Звоните, пишите, приходите.',
            'note' => 'Отвечаем в будни',
            'items' => [
                ['title' => 'Телефон', 'href' => 'tel:+71234567890', 'text' => '+7 123 456-78-90', 'note' => 'с 9 до 18'],
                ['title' => 'Адрес', 'href' => '', 'text' => 'ул. Школьная, 1', 'note' => 'офис 12'],
                ['title' => 'Обрывок', 'href' => '', 'text' => '', 'note' => ''],
            ],
        ]), nowUtc());

        // Цепочка шагов: путь курса короткими плашками с переходами-стрелками; первый шаг выделен,
        // шаг без названия не печатается.
        createSection((string) $vidy['id'], $values([
            'kind' => 'chain',
            'anchor' => 'etapy',
            'title' => 'Как устроен курс',
            'lead_text' => 'От первого занятия до свободной речи.',
            'items' => [
                ['title' => 'Пробный урок'],
                ['title' => 'Абонемент'],
                ['title' => ''],
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

    public function testTableПечатаетВедомостьСЗаголовкамиСтрок(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Условия обучения', $page['body'], 'заголовок ведомости напечатан');
        self::assertStringContainsString('Что входит в абонемент.', $page['body'], 'лид ведомости напечатан');
        self::assertMatchesRegularExpression('~<table\b~', $page['body'], 'ведомость печатается таблицей');
        self::assertMatchesRegularExpression(
            '~<th\b[^>]*scope="row"[^>]*>Абонемент</th>~',
            $page['body'],
            'первая ячейка строки — заголовок строки, а не просто текст'
        );
        self::assertStringContainsString('12 занятий', $page['body'], 'значение строки напечатано');
        self::assertStringContainsString('Месяц', $page['body'], 'примечание строки напечатано');
        self::assertStringContainsString('Пробный урок', $page['body'], 'строка без примечания печатается');
        self::assertStringContainsString('Бесплатно', $page['body']);
        self::assertStringNotContainsString('Ячейка без названия', $page['body'], 'безымянная ячейка не печатается');
        self::assertStringNotContainsString('Обрывок без значения', $page['body'], 'строка без значения и примечания не печатается');
        self::assertStringContainsString('Цены указаны в рублях', $page['body'], 'примечание блока — сноска под ведомостью');
        self::assertStringNotContainsString('Ведомость без строк', $page['body'], 'ведомость без строк остаётся пустым местом');
    }

    public function testTabsПереключаютВкладкиБезСкрипта(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Программы по уровням', $page['body'], 'заголовок вкладок напечатан');
        self::assertStringContainsString('Выберите свой уровень.', $page['body'], 'лид вкладок напечатан');
        self::assertMatchesRegularExpression(
            '~<summary\b[^>]*>Начальный</summary>~',
            $page['body'],
            'название вкладки печатается заголовком раскрытия'
        );
        self::assertStringContainsString('A1 — алфавит и первые фразы.', $page['body'], 'содержимое вкладки напечатано');
        self::assertMatchesRegularExpression(
            '~<details\b[^>]*\bopen\b[^>]*>\s*<summary\b[^>]*>Начальный</summary>~s',
            $page['body'],
            'первая вкладка открыта: остальное человек раскрывает сам'
        );
        self::assertMatchesRegularExpression(
            '~<details\b[^>]*\bname="tabs-[^"]+"~',
            $page['body'],
            'вкладки одной группы взаимоисключающие: открыта всегда одна'
        );

        preg_match_all('~<details\b[^>]*\bname="([^"]+)"~', $page['body'], $groups);

        self::assertCount(2, $groups[1], 'группу называют обе вкладки: без name переключение не работает');
        self::assertCount(1, array_unique($groups[1]), 'группа у блока одна — два блока вкладок не закрывают друг друга');
        self::assertStringContainsString('B1 — свободная речь.', $page['body'], 'вторая вкладка напечатана');
        self::assertStringNotContainsString('Вкладка без названия', $page['body'], 'вкладка без названия не печатается');
    }

    public function testBannerПечатаетКартинкуТекстИКарточкиДоПосле(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Летний интенсив', $page['body'], 'заголовок баннера напечатан');
        self::assertStringContainsString('Две недели погружения в язык.', $page['body'], 'лид баннера напечатан');
        self::assertStringContainsString('src="/uploads/images/banner.jpg"', $page['body'], 'картинка баннера напечатана подложкой');
        self::assertStringContainsString('alt="Класс летом"', $page['body'], 'у картинки баннера есть описание');
        self::assertMatchesRegularExpression(
            '~class="[^"]*\bfrom-paper\b[^"]*"~',
            $page['body'],
            'подложка баннера — градиентная плашка от цвета бумаги'
        );
        self::assertStringContainsString('До', $page['body'], 'надпись карточки «до» напечатана');
        self::assertStringContainsString('Разрозненные темы.', $page['body'], 'текст карточки «до» напечатан');
        self::assertStringContainsString('После', $page['body'], 'надпись карточки «после» напечатана');
        self::assertStringContainsString('Система и свободная речь.', $page['body'], 'текст карточки «после» напечатан');
        self::assertStringNotContainsString('Обрывок', $page['body'], 'карточка без текста не печатается');
        self::assertMatchesRegularExpression('~href="/o-nas"[^>]*>Узнать программу</a>~', $page['body'], 'кнопка баннера ведёт на программу');
        self::assertStringContainsString('Места ограничены', $page['body'], 'примечание баннера напечатано сноской');
    }

    public function testVideoПечатаетСайтовыйРоликИМолчитНаЧужом(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Видео с уроков', $page['body'], 'заголовок видео напечатан');
        self::assertStringContainsString('Короткие ролики о школе.', $page['body'], 'лид видео напечатан');
        self::assertMatchesRegularExpression(
            '~<video\b[^>]*\bsrc="/videos/lesson\.mp4"~',
            $page['body'],
            'собственный видеофайл печатается плеером'
        );
        self::assertStringContainsString('poster="/uploads/images/lesson-poster.jpg"', $page['body'], 'обложка ролика печатается');
        self::assertStringContainsString('Урок — Фрагмент занятия.', $page['body'], 'подпись ролика собрана из заголовка и текста');
        self::assertStringNotContainsString('Чужой ролик', $page['body'], 'чужая площадка под CSP плеером не становится');
        self::assertStringNotContainsString('Не видео', $page['body'], 'файл не-видео плеером не становится');
    }

    public function testSliderПечатаетКарусельИМолчитНаПустомСлайде(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Как мы работаем', $page['body'], 'заголовок карусели напечатан');
        self::assertStringContainsString('Листайте слайды горизонтально.', $page['body'], 'лид карусели напечатан');
        self::assertMatchesRegularExpression(
            '~class="[^"]*\bsnap-x\b[^"]*"~',
            $page['body'],
            'карусель — горизонтальная прокрутка со snap-точками'
        );
        self::assertStringContainsString('src="/uploads/images/slide-1.jpg"', $page['body'], 'слайд печатается картинкой');
        self::assertStringContainsString('alt="Первый слайд"', $page['body'], 'подпись слайда служит описанием картинки');
        self::assertStringContainsString('Знакомство и пробный урок.', $page['body'], 'текст слайда напечатан');
        self::assertStringContainsString('Шаг 1', $page['body'], 'надпись слайда напечатана');
        self::assertStringContainsString('src="/uploads/images/slide-2.jpg"', $page['body'], 'слайд без заголовка печатается');
        self::assertStringContainsString('Практика в группе.', $page['body']);
        self::assertStringNotContainsString('Пустой слайд', $page['body'], 'слайд без картинки и текста не печатается');
    }

    public function testReviewПечатаетОтзывыИМолчитНаПустом(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Отзывы учеников', $page['body'], 'заголовок отзывов напечатан');
        self::assertStringContainsString('Что говорят после курса.', $page['body'], 'лид отзывов напечатан');
        self::assertMatchesRegularExpression('~<blockquote\b[^>]*>Заговорила за месяц\.</blockquote>~', $page['body'], 'отзыв печатается выдержкой');
        self::assertStringContainsString('src="/uploads/images/student.jpg"', $page['body'], 'фото автора печатается');
        self::assertStringContainsString('Мария', $page['body'], 'имя автора напечатано');
        self::assertStringContainsString('A2 → B1', $page['body'], 'роль автора напечатана');
        self::assertStringContainsString('Иван', $page['body'], 'отзыв без фото печатается');
        self::assertStringContainsString('Грамматика наконец понятна.', $page['body']);
        self::assertStringNotContainsString('Пустой отзыв', $page['body'], 'отзыв без текста не печатается');
    }

    public function testFloatПечатаетКартинкуСОбтеканиемИАбзацы(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Метод школы', $page['body'], 'заголовок блока напечатан');
        self::assertStringContainsString('Учим говорить с первого занятия.', $page['body'], 'лид блока напечатан');
        self::assertStringContainsString('src="/uploads/images/float.jpg"', $page['body'], 'картинка блока напечатана');
        self::assertStringContainsString('alt="Занятие в классе"', $page['body'], 'у картинки есть описание');
        self::assertMatchesRegularExpression(
            '~class="[^"]*\bsm:float-right\b[^"]*"~',
            $page['body'],
            'layout right обтекает картинку текстом справа'
        );
        self::assertStringContainsString('Разминка', $page['body'], 'заголовок абзаца напечатан');
        self::assertStringContainsString('Повторяем прошлый урок.', $page['body'], 'текст абзаца напечатан');
        self::assertStringContainsString('Практика', $page['body'], 'второй абзац напечатан');
        self::assertStringContainsString('Говорим новую тему.', $page['body']);
        self::assertStringContainsString('Методика проверена годами', $page['body'], 'примечание блока напечатано сноской');
    }

    public function testFilesПечатаетФайлыСсылкамиСПлашкойФормата(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Материалы для скачивания', $page['body'], 'заголовок блока напечатан');
        self::assertStringContainsString('Памятки и домашние задания.', $page['body'], 'лид блока напечатан');
        self::assertMatchesRegularExpression(
            '~<a\b[^>]*href="/files/tenses\.pdf"[^>]*>\s*Памятка по временам</a>~s',
            $page['body'],
            'файл печатается ссылкой на свой адрес'
        );
        self::assertStringContainsString('Таблица глаголов.', $page['body'], 'описание файла напечатано');
        self::assertStringContainsString('1 МБ', $page['body'], 'примечание файла напечатано');
        self::assertStringContainsString('PDF', $page['body'], 'плашка формата собрана из адреса файла');
        self::assertStringContainsString('DOCX', $page['body'], 'плашка второго файла собрана из его адреса');
        self::assertMatchesRegularExpression(
            '~<a\b[^>]*href="/files/homework\.docx"[^>]*>\s*Домашнее задание</a>~s',
            $page['body'],
            'второй файл печатается ссылкой'
        );
        self::assertStringContainsString('Страница 12.', $page['body'], 'описание второго файла напечатано');
        self::assertStringNotContainsString('Файл без названия', $page['body'], 'строка без названия не печатается');
        self::assertStringContainsString('Файлы лежат на сайте', $page['body'], 'примечание блока напечатано сноской');
    }

    public function testContactsПечатаетСпособСвязиИМолчитНаПустом(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Как с нами связаться', $page['body'], 'заголовок блока напечатан');
        self::assertStringContainsString('Звоните, пишите, приходите.', $page['body'], 'лид блока напечатан');
        self::assertStringContainsString('Телефон', $page['body'], 'подпись строки напечатана');
        self::assertMatchesRegularExpression(
            '~<a\b[^>]*href="tel:\+71234567890"[^>]*>\+7 123 456-78-90</a>~',
            $page['body'],
            'значение с адресом печатается ссылкой на телефон'
        );
        self::assertStringContainsString('с 9 до 18', $page['body'], 'примечание строки напечатано');
        self::assertStringContainsString('Адрес', $page['body'], 'вторая подпись напечатана');
        self::assertStringContainsString('ул. Школьная, 1', $page['body'], 'значение без адреса печатается текстом');
        self::assertStringContainsString('офис 12', $page['body'], 'примечание второй строки напечатано');
        self::assertStringNotContainsString('Обрывок', $page['body'], 'строка без значения не печатается');
        self::assertStringContainsString('Отвечаем в будни', $page['body'], 'примечание блока напечатано сноской');
    }

    public function testChainПечатаетЦепочкуШаговИМолчитНаПустом(): void
    {
        $page = Kernel::get('/vidy');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Как устроен курс', $page['body'], 'заголовок блока напечатан');
        self::assertStringContainsString('От первого занятия до свободной речи.', $page['body'], 'лид блока напечатан');
        self::assertMatchesRegularExpression(
            '~<span class="rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream">Пробный урок</span>~',
            $page['body'],
            'первый шаг выделен заливкой'
        );
        self::assertStringContainsString('Абонемент', $page['body'], 'второй шаг напечатан');
        self::assertSame(
            1,
            preg_match_all('~<span class="text-ink/50"[^>]*>→</span>~', $page['body'], $m),
            'переход-стрелка между двумя шагами одна: пустой шаг её не создаёт'
        );
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
