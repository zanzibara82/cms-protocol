<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use PHPUnit\Framework\TestCase;

// Пакет M6-2: корректный POST шага 1 проверяет поля, зондирует базу, атомарно пишет config.php
// с правами 600 и ставит в сессию отметку перехода. Класс отделён от SetupConfigStepTest (тот
// проверяет только разметку и приоритет заготовки) и от SetupTest (боевая установка на шаге 2).
final class SetupDbStepTest extends TestCase
{
    private static function tempConfigPath(): string
    {
        return '/tmp/cms-setup-db-step-config.php';
    }

    public static function setUpBeforeClass(): void
    {
        self::unlock();

        // Все обязательные ключи обнулены в окружении: иначе харнес передаёт CMS_DB_NAME=cms_test
        // (tests/http/Kernel.php:51) и унаследованный CMS_DB_HOST=db, а проверка «значение из формы
        // перекрывает окружение» стала бы бутафорской. Пустая строка для ядра — не значение
        // (core/config-loader.php:54), поэтому источниками остаются файл и образец.
        Kernel::start([
            'CMS_CONFIG_FILE' => self::tempConfigPath(),
            'CMS_DB_HOST' => '', 'CMS_DB_PORT' => '', 'CMS_DB_NAME' => '', 'CMS_DB_USER' => '',
            'CMS_TABLE_PREFIX' => '', 'CMS_SITE_URL' => '', 'CMS_SITE_NAME' => '',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        $path = self::tempConfigPath();
        if (is_file($path)) {
            unlink($path);
        }

        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        self::unlock();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        // Каждая проверка стартует «файла нет»: запись шага 1 обязана создаваться с нуля.
        $path = self::tempConfigPath();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/public/install.lock';
    }

    private static function unlock(): void
    {
        if (is_file(self::lockPath())) {
            unlink(self::lockPath());
        }
    }

    // GET открывает сессию и печатает токен; POST идёт в тот же jar, поэтому отметка сессии
    // достаётся проверке 3 по тому же идентификатору.
    private static function freshToken(): string
    {
        $page = Kernel::get('/setup.php?step=db');

        return Kernel::tokenFrom($page['body']);
    }

    /**
     * Эталон корректного запроса. Учётные данные корня с пустым паролем — свойство контейнера
     * (MYSQL_ALLOW_EMPTY_PASSWORD, docker-compose.dev.yml:44): корректный POST использует пустой
     * db_pass, непустой пароль база не примет.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function validPost(string $token, array $overrides = []): array
    {
        $fields = [
            'db_host' => 'db',
            'db_port' => '3306',
            'db_name' => 'cms_test',
            'db_user' => 'root',
            'db_pass' => '',
            'table_prefix' => 'tbl_setup_test_',
            'site_url' => 'http://localhost:8080/',
            'site_name' => 'Школа с шага первого',
        ];

        return array_merge($fields, $overrides, ['_token' => $token]);
    }

    // Значение поля читается из его же тега: name= и value= в шаблоне идут в одном <input>.
    private static function renderedValue(string $body, string $field): string
    {
        if (
            preg_match('/name="' . preg_quote($field, '/') . '".*?>/s', $body, $tag) === 1
            && preg_match('/value="([^"]*)"/', $tag[0], $value) === 1
        ) {
            return $value[1];
        }

        return "\0";
    }

    public function testУспешныйШагПервыйЗаписываетНастройкиИВедётНаШагВторой(): void
    {
        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken()));

        self::assertSame(302, $response['status'], 'Корректный POST шага 1 не ответил редиректом');
        self::assertSame('/setup.php?step=admin', $response['headers']['location'] ?? '', 'Редирект ведёт не на шаг 2');

        $path = self::tempConfigPath();
        self::assertFileExists($path, 'Файл настроек не записан');
        self::assertSame('600', substr(sprintf('%o', (int) fileperms($path)), -3), 'Права записанного файла не 600');

        $contents = (string) file_get_contents($path);
        foreach ([
            "'DB_HOST' => 'db'",
            "'DB_PORT' => '3306'",
            "'DB_NAME' => 'cms_test'",
            "'DB_USER' => 'root'",
            "'DB_PASS' => ''",
            "'TABLE_PREFIX' => 'tbl_setup_test_'",
            "'SITE_NAME' => 'Школа с шага первого'",
            "'SITE_URL' => 'http://localhost:8080'",
            // Невидимые в форме ключи пришли из образца.
            "'SMTP_PORT' => 587",
            "'TIMEZONE' => 'Europe/Moscow'",
        ] as $needle) {
            self::assertStringContainsString($needle, $contents, 'В записанном файле нет подстроки ' . $needle);
        }

        // Конечный слэш снят нормализацией — в файле его быть не должно.
        self::assertStringNotContainsString('8080/', $contents, 'Конечный слэш адреса сайта не снят');
    }

    public function testПустойПарольПриНепустомПользователеПроходит(): void
    {
        // db_pass пустой, db_user=root — это и есть корректный запрос контейнера; отказа «нужен
        // пароль» ни в одном ключе быть не должно.
        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken()));

        self::assertSame(302, $response['status'], 'Пустой пароль при непустом пользователе отклонён');
        self::assertFileExists(self::tempConfigPath(), 'При пустом пароле файл настроек не записан');
    }

    public function testЗначенияФайлаСохраняютсяАВведённыйПарольПерекрываетСтарыйИНеУходитВСессию(): void
    {
        $path = self::tempConfigPath();

        // Предзаписанный конфиг: DB_PASS со старым секретом и SMTP_HOST — невидимый в форме ключ.
        /** @var array<string, mixed> $sample */
        $sample = require dirname(__DIR__, 2) . '/config.sample.php';
        $sample['DB_PASS'] = 'старый-секрет-из-файла';
        $sample['SMTP_HOST'] = 'smtp-из-файла';
        file_put_contents($path, "<?php\n\nreturn " . var_export($sample, true) . ";\n");

        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken()));
        self::assertSame(302, $response['status'], 'Корректный POST при предзаписанном файле не прошёл');

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString("'DB_PASS' => ''", $contents, 'Введённый пустой пароль не перекрыл старый');
        self::assertStringNotContainsString('старый-секрет-из-файла', $contents, 'Старый пароль базы остался в файле');
        self::assertStringContainsString('smtp-из-файла', $contents, 'Невидимый ключ не взят из файла');

        // Отметка сессии читается из файла сессии: пароль в сессию не уходит.
        $sid = Kernel::sessionId();
        self::assertNotSame('', $sid, 'Идентификатор сессии не прочитан из jar');

        $savePath = session_save_path();
        $dir = $savePath !== '' ? $savePath : '/tmp';
        $sessionFile = $dir . '/sess_' . $sid;
        self::assertFileExists($sessionFile, 'Файл сессии ' . $sessionFile . ' не найден — отметку проверить нельзя');

        $sessionContents = (string) file_get_contents($sessionFile);
        self::assertStringContainsString('setup_db', $sessionContents, 'В сессии нет отметки перехода setup_db');
        self::assertStringContainsString('tbl_setup_test_', $sessionContents, 'В сессии нет префикса из отметки');
        self::assertStringContainsString('Школа с шага первого', $sessionContents, 'В сессии нет названия сайта из отметки');
        self::assertStringNotContainsString('старый-секрет-из-файла', $sessionContents, 'Пароль базы ушёл в сессию');
    }

    public function testНедостижимыйХостОтклоняетсяБезЗаписиНаДиск(): void
    {
        $secret = 'секрет-базы-который-нельзя-показать';
        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken(), [
            'db_host' => '127.0.0.1',
            'db_port' => '1',
            'db_pass' => $secret,
        ]));

        self::assertSame(200, $response['status'], 'Недостижимый хост не вернулся формой отказа');
        self::assertStringContainsString('Сервер базы недоступен по адресу 127.0.0.1:1', $response['body'], 'Нет текста зонда сети');
        self::assertStringNotContainsString($secret, $response['body'], 'Введённый пароль базы вернулся в ответ');

        // Введённые значения сохранены (кроме пароля базы).
        self::assertSame('', self::renderedValue($response['body'], 'db_pass'), 'Поле db_pass не пусто после отказа');
        self::assertSame('cms_test', self::renderedValue($response['body'], 'db_name'), 'Имя базы не вернулось в форму');
        self::assertSame('Школа с шага первого', self::renderedValue($response['body'], 'site_name'), 'Название сайта не вернулось в форму');

        self::assertFileDoesNotExist(self::tempConfigPath(), 'При отказе сети файл настроек появился на диске');
        self::assertSame([], glob('/tmp/.cms-config-*'), 'При отказе сети остались временные файлы');
    }

    public function testНесуществующуюБазуПросятСоздатьВПанели(): void
    {
        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken(), [
            'db_name' => 'cms_missing_db_test',
        ]));

        self::assertSame(200, $response['status'], 'Несуществующая база не вернулась формой отказа');
        self::assertStringContainsString('Создайте её в панели хостинга', $response['body'], 'Нет текста про панель хостинга');
        self::assertFileDoesNotExist(self::tempConfigPath(), 'При отказе базы файл настроек появился на диске');
    }

    public function testНевалидныеПоляОтклоняютсяБезОбращенияКБазе(): void
    {
        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken(), [
            'db_host' => 'http://bad host/',
            'db_port' => '0',
            'db_name' => 'имя с пробелом',
            'table_prefix' => '1bad',
            'site_url' => 'ftp://example.com',
            'site_name' => 'А',
        ]));

        self::assertSame(200, $response['status'], 'Невалидные поля не вернулись формой отказа');
        foreach ([
            'Хост базы — адрес вида db.example.com или 127.0.0.1, без схемы, слэшей и пробелов.',
            'Порт базы — целое число от 1 до 65535.',
            'Имя базы — до 64 символов: латинские буквы, цифры, подчёркивание и дефис.',
            'Префикс таблиц — строчные латинские буквы, цифры и подчёркивание, начинается с буквы, до 30 символов.',
            'Адрес сайта — полный URL со схемой http или https.',
            'Название сайта — не короче двух символов.',
        ] as $text) {
            self::assertStringContainsString($text, $response['body'], 'Нет отказа поля: ' . $text);
        }

        // До зонда и записи дело не дошло: плашка отказа уровня формы (role="alert") не появилась.
        self::assertStringNotContainsString('role="alert"', $response['body'], 'Отказ по полям дошёл до уровня формы');
        self::assertFileDoesNotExist(self::tempConfigPath(), 'При отказе полей файл настроек появился на диске');
    }

    public function testВторойЗаходПерекрываетЗаписанноеИНеОставляетВременныхФайлов(): void
    {
        $first = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken()));
        self::assertSame(302, $first['status'], 'Первый корректный POST не прошёл');

        $second = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken(), [
            'site_name' => 'Школа со второго захода',
            'table_prefix' => 'tbl_second_test_',
        ]));
        self::assertSame(302, $second['status'], 'Второй корректный POST не прошёл');

        $contents = (string) file_get_contents(self::tempConfigPath());
        self::assertStringContainsString("'SITE_NAME' => 'Школа со второго захода'", $contents, 'Второй заход не перекрыл название сайта');
        self::assertStringContainsString("'TABLE_PREFIX' => 'tbl_second_test_'", $contents, 'Второй заход не перекрыл префикс');
        self::assertStringNotContainsString('Школа с шага первого', $contents, 'Первое название сайта осталось в файле');
        self::assertStringNotContainsString('tbl_setup_test_', $contents, 'Первый префикс остался в файле');

        self::assertSame([], glob('/tmp/.cms-config-*'), 'После перекрывающего захода остались временные файлы');
    }

    public function testПлашкаУведомленияОПравахЖивётНаШагеВтором(): void
    {
        $notice = Kernel::get('/setup.php?step=admin&notice=config-permissions');
        self::assertSame(200, $notice['status']);
        self::assertStringContainsString('права 600 выставить не удалось', $notice['body'], 'Плашка о правах не напечатана на шаге 2');

        $unknown = Kernel::get('/setup.php?step=admin&notice=' . rawurlencode('ключ-которого-нет'));
        self::assertSame(200, $unknown['status']);
        self::assertStringNotContainsString('ключ-которого-нет', $unknown['body'], 'Неизвестный ключ уведомления напечатан');
    }
}
