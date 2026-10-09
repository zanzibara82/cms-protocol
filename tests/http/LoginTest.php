<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class LoginTest extends TestCase
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

        // Каждый тест начинается сам: журнал попыток очищается, аккаунт сидируется заново.
        // Без этого тесты зависели бы друг от друга — например, «подставной заголовок» проходил
        // только потому, что до него пять неудач записал предыдущий тест.
        TestDb::clearTable('login_attempt');
        TestDb::clearTable('user');
        TestDb::seedUser('admin@school.dev', 'верный-пароль', 'admin');
    }

    // Токен разбирается одним помощником на весь набор — Kernel::tokenFrom(). Непустота проверяется
    // здесь: пустой токен csrfCheck() отвергает, и тест падал бы на «свой текст ошибки», а не на том,
    // что разбор формы сломался.
    private function token(): string
    {
        $token = Kernel::tokenFrom(Kernel::get('/login')['body']);
        self::assertNotSame('', $token, 'Форма входа не отдала CSRF-токен');

        return $token;
    }

    /** Пять неудач своими запросами: лимит по адресу исчерпан независимо от порядка тестов. */
    private function exhaustLoginLimit(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        }
    }

    public function testСтраницаВходаОткрытаАнониму(): void
    {
        $page = Kernel::get('/login');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вход для редактора', $page['body']);
        self::assertStringContainsString('method="post"', $page['body'], 'Форма с паролем обязана быть POST');
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        // Минимум проекта — зона нажатия 44 px: каждый контрол экрана обязан нести этот класс.
        self::assertStringContainsString('min-h-[44px] w-full rounded-lg border border-ink/20', $page['body'], 'Поля входа не меньше 44 px');
        self::assertStringContainsString('min-h-[44px] w-full rounded-lg bg-ink', $page['body'], 'Кнопка входа не меньше 44 px');
    }

    // Пакет V, находка 3 аудита просмотра: экран входа запрещает кэширование сам, а не ждёт значения
    // `session.cache_limiter` по умолчанию. Проверяются обе ветки: и чистая форма, и форма с текстом
    // ошибки — обе печатают введённую почту в поле, и обе собираются одним `render()` с `noindex`.
    public function testОбеВеткиВходаСамиЗапрещаютКэширование(): void
    {
        $page = Kernel::get('/login');
        self::assertSame('no-store', $page['headers']['cache-control'], 'Страница входа не отдала собственный запрет кэширования');

        $wrong = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        self::assertSame(200, $wrong['status'], 'Неверный пароль возвращает форму тем же экраном');
        self::assertSame('no-store', $wrong['headers']['cache-control'], 'Ветка отказа на входе не отдала собственный запрет кэширования');
    }

    public function testВерныйПарольПереноситВАдминку(): void
    {
        TestDb::clearTable('login_attempt');
        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location']);

        $page = Kernel::get('/admin');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Сводка', $page['body']);
    }

    // Тест, отложенный задачей 6: страница выхода была и раньше, но проверить гашение сессии можно
    // было только на экране админки — он появился в этой задаче.
    public function testВыходГаситСессию(): void
    {
        TestDb::clearTable('login_attempt');
        Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        $page = Kernel::get('/admin');
        // Токен со страницы админки: он обязан быть непустым, иначе отказ 403 выглядел бы как
        // погашенная сессия.
        $token = Kernel::tokenFrom($page['body']);
        self::assertNotSame('', $token, 'Экран админки не отдал CSRF-токен');

        $logout = Kernel::post('/logout', ['_token' => $token]);

        self::assertSame(302, $logout['status']);
        self::assertStringContainsString('notice=signed-out', $logout['headers']['location']);
        self::assertSame(302, Kernel::get('/admin')['status'], 'После выхода сводка снова закрыта');
    }

    public function testНеверныйПарольПоказываетТотЖеТекстЧтоИЧужойАдрес(): void
    {
        $wrong = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        $absent = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'net@school.dev', 'password' => 'мимо']);

        self::assertSame(200, $wrong['status']);
        self::assertStringContainsString('Почта или пароль не подошли', $wrong['body']);
        self::assertStringContainsString('Почта или пароль не подошли', $absent['body']);
    }

    public function testПослеПятиНеудачВходЗакрывается(): void
    {
        $this->exhaustLoginLimit();

        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    public function testВраждебныйNextНеУводитНаЧужойДомен(): void
    {
        // Обратный слэш сразу после одиночного «/» браузер сводит к «//evil.com», то есть к чужому
        // хосту. После входа в Location обязан остаться внутренний путь, а не адрес из ссылки.
        $response = Kernel::post(
            '/login?next=/\\evil.com',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location'], 'Открытый редирект закрыт: пускаем только внутренний путь');
        self::assertStringNotContainsString('evil.com', $response['headers']['location']);
    }

    public function testВнутреннийNextПослеВходаСохраняется(): void
    {
        // Фильтр не должен выкидывать и законную глубинную ссылку на свой сайт.
        $response = Kernel::post(
            '/login?next=/news',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/news', $response['headers']['location']);
    }

    public function testПодставнойЗаголовокАдресаНеСнимаетЛимит(): void
    {
        $this->exhaustLoginLimit();

        // Даже с «другим» заголовком переадресации сервер считает адрес из серверного массива: лимит остаётся.
        $response = Kernel::post(
            '/login',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль'],
            ['headers' => ['X-Forwarded-For' => '198.51.100.4']]
        );

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    // Равноправные заголовки «кто меня проксировал» шлются все сразу: в суперглобальном массиве
    // каждый из них становится ключом с префиксом HTTP_, и чтение любого из них определило бы
    // адрес вместо серверного. Журнал сравнивается построчно, поэтому подмена заметна даже когда
    // лимит ещё не исчерпан — то есть тест ловит правку clientIp(), а не только её последствия.
    public function testПодставныеЗаголовкиАдресаНеМеняютНиЗаписьНиЛимит(): void
    {
        $spoofed = ['X-Forwarded-For' => '198.51.100.4', 'X-Real-IP' => '203.0.113.9', 'Client-IP' => '192.0.2.7'];
        $fields = ['email' => 'admin@school.dev', 'password' => 'мимо'];

        Kernel::post('/login', ['_token' => $this->token()] + $fields);
        Kernel::post('/login', ['_token' => $this->token()] + $fields, ['headers' => $spoofed]);

        $recorded = array_map(
            static fn (array $row): string => (string) $row['ip'],
            all('SELECT ip FROM ' . t('login_attempt') . ' ORDER BY id')
        );
        self::assertCount(2, $recorded, 'Журнал обязан содержать обе попытки');
        self::assertSame(
            $recorded[0],
            $recorded[1],
            'Подставной заголовок сменил записанный адрес: он читается не из серверного массива'
        );

        $this->exhaustLoginLimit();
        $response = Kernel::post(
            '/login',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль'],
            ['headers' => $spoofed]
        );

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    public function testГлубиннаяСсылкаПереживаетПостФормы(): void
    {
        // Гейт редиректит на /login?next=/…; форма отправляет на /login без адреса в строке,
        // поэтому next обязан доехать до обработчика скрытым полем, а не потеряться в редиректе.
        $page = Kernel::get('/login?next=/news');
        self::assertStringContainsString('name="next" value="/news"', $page['body'], 'Страница входа прячет глубинную ссылку в форме');

        $response = Kernel::post(
            '/login',
            ['_token' => Kernel::tokenFrom($page['body']), 'email' => 'admin@school.dev', 'password' => 'верный-пароль', 'next' => '/news']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/news', $response['headers']['location'], 'Скрытое поле next доживает до редиректа');
    }
}
