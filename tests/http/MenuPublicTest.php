<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Публичная навигация читает базу: шапка печатает пункты по sort и следом ссылки включённых
 * разделов (publicNav манифеста), подвал группирует пункты в колонки по подписи. Смотрится
 * гостьей на главной — состояние экрана описывают данные, а не роль.
 */
final class MenuPublicTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

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

    public function testПустоеМенюДень1СайтОткрывается(): void
    {
        $home = Kernel::get('/');

        self::assertSame(200, $home['status']);
        self::assertStringContainsString('href="/login"', $home['body'], 'вход остаётся статикой шапки');
        self::assertStringContainsString('Карта сайта', $home['body'], 'карта сайта остаётся статикой подвала');
        self::assertStringNotContainsString('>Новости</a>', $home['body'], 'раздел не установлен — навигационной ссылки «Новости» нет: статика ушла, пункт из базы не создан');
    }

    public function testШапкаЧитаетПунктыБазыВПорядке(): void
    {
        createMenuItem('HEADER', ['label' => 'О школе', 'href' => '/o-shkole'], nowUtc());
        createMenuItem('HEADER', ['label' => 'Расписание', 'href' => '/raspisanie'], nowUtc());

        $body = Kernel::get('/')['body'];

        self::assertMatchesRegularExpression('/<a\b[^>]*href="\/o-shkole"[^>]*>О школе<\/a>/', $body, 'пункт печатается ссылкой с подписью');
        self::assertMatchesRegularExpression('/<a\b[^>]*href="\/raspisanie"[^>]*>Расписание<\/a>/', $body);
        self::assertTrue(
            $this->inOrder($body, '>Расписание</a>', 'href="/login"'),
            'пункты базы печатаются по sort, статика входа идёт следом'
        );
    }

    public function testНовоеОкноПечатаетсяПравильнымиАтрибутами(): void
    {
        createMenuItem('HEADER', ['label' => 'Правила', 'href' => '/pravila', 'blank' => '1'], nowUtc());
        createMenuItem('HEADER', ['label' => 'Чат', 'href' => '/chat'], nowUtc());

        $body = Kernel::get('/')['body'];

        $blankTag = $this->tagOf($body, 'href="/pravila"', 'якорь пункта «в новом окне» не напечатан');
        self::assertStringContainsString('target="_blank"', $blankTag);
        self::assertStringContainsString('rel="noopener noreferrer"', $blankTag, 'target=_blank обязан прийти с парным rel');

        $plainTag = $this->tagOf($body, 'href="/chat"', 'якорь обычного пункта не напечатан');
        self::assertStringNotContainsString('target=', $plainTag, 'обычному пункту target не нужен');
    }

    public function testСкрытыйПунктНеПечатается(): void
    {
        // Приманка рядом: тест обязан различать «скрытие работает» от «наивная статика вообще
        // не читает базу». Видимый пункт печатается, скрытый — нет.
        createMenuItem('HEADER', ['label' => 'Архив', 'href' => '/arhiv'], nowUtc());
        $item = createMenuItem('HEADER', ['label' => 'Досье', 'href' => '/dossier'], nowUtc());
        toggleMenuItem((string) $item['id']);

        $body = Kernel::get('/')['body'];

        self::assertSame(1, substr_count($body, 'href="/arhiv"'), 'видимый пункт напечатан ровно один раз');
        self::assertSame(0, substr_count($body, '/dossier'), 'скрытый пункт не печатается публично');
    }

    public function testСсылкиВключённыхРазделовИдутПослеПунктов(): void
    {
        $result = installApp('news');

        self::assertTrue(($result['ok'] ?? false) === true, 'раздел «Новости» не установился: ' . (string) ($result['error'] ?? ''));

        $body = Kernel::get('/')['body'];

        self::assertTrue(
            $this->inOrder($body, '>О школе</a>', '>Расписание</a>', '>Новости</a>'),
            'publicNav включённого раздела печатается следом за пунктами базы'
        );
    }

    public function testВыключенныйРазделПрячетСсылку(): void
    {
        run('UPDATE ' . t('app_installation') . " SET enabled = 0 WHERE id = 'news'");

        self::assertStringNotContainsString('>Новости</a>', Kernel::get('/')['body']);

        run('UPDATE ' . t('app_installation') . " SET enabled = 1 WHERE id = 'news'");

        self::assertStringContainsString('>Новости</a>', Kernel::get('/')['body'], 'включённый раздел возвращает ссылку живьём');
    }

    public function testПодвалГруппируетПунктыПоКолонкам(): void
    {
        createMenuItem('FOOTER', ['label' => 'Абонемент', 'href' => '/abonement', 'section' => 'Обучение'], nowUtc());
        createMenuItem('FOOTER', ['label' => 'Форум', 'href' => '/forum', 'section' => 'Обучение'], nowUtc());
        createMenuItem('FOOTER', ['label' => 'Телефон', 'href' => '/telefon', 'section' => 'Связь'], nowUtc());
        createMenuItem('FOOTER', ['label' => 'Почта', 'href' => '/pochta', 'section' => ''], nowUtc());

        $body = Kernel::get('/')['body'];

        foreach (['Обучение', 'Связь', 'Ссылки', 'Карта сайта'] as $text) {
            self::assertStringContainsString($text, $body, 'заголовки колонок: подписи базы и запасная «Ссылки»');
        }

        self::assertTrue(
            $this->inOrder($body, 'Обучение', '>Абонемент</a>', '>Форум</a>', 'Связь', '>Телефон</a>', 'Ссылки', '>Почта</a>'),
            'группы печатаются по первому появлению подписи, пустая подпись заменяется «Ссылками»'
        );
        self::assertSame(1, substr_count($body, 'href="/abonement"'), 'пункт подвала печатается один раз — в подвале, не в шапке');
    }

    /** Каждая следующая подстрока впервые встречается строго после предыдущей. */
    private function inOrder(string $body, string ...$needles): bool
    {
        $previous = -1;

        foreach ($needles as $needle) {
            $pos = mb_strpos($body, $needle);

            if ($pos === false || $pos <= $previous) {
                return false;
            }

            $previous = $pos;
        }

        return true;
    }

    /** Весь тег <a> по адресу ссылки: атрибутный состав одного якоря (target, rel и классы). */
    private function tagOf(string $body, string $needle, string $message): string
    {
        if (preg_match('/<a\b[^>]*' . preg_quote($needle, '/') . '[^>]*>/u', $body, $matches) !== 1) {
            self::fail($message . ' (не найдено ' . $needle . ')');
        }

        return (string) $matches[0];
    }
}
