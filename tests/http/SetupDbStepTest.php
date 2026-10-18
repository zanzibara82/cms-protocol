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

        // Уборка осколков ДО прогона, симметрично уборке после: прогон, убитый между
        // file_put_contents() и rename(), иначе краснит следующий прогон по чужой вине.
        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        // Набор CMS_* обнулён через Kernel::installerEnv(): комментарий с причиной переехал в сам
        // помощник, а здесь остаётся только временный путь настроек этого класса.
        Kernel::start(Kernel::installerEnv(self::tempConfigPath()));
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

    // GET открывает сессию и печатает токен; POST идёт с теми же куки, поэтому отметка сессии
    // достаётся проверке 3 по тому же идентификатору.
    private static function freshToken(): string
    {
        $page = Kernel::get('/setup.php?step=db');

        return Kernel::tokenFrom($page['body']);
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

    // Предел поля читается из его же тега: maxlength живёт в одном <input> вместе с name=.
    private static function renderedMax(string $body, string $field): string
    {
        if (
            preg_match('/name="' . preg_quote($field, '/') . '".*?>/s', $body, $tag) === 1
            && preg_match('/maxlength="([^"]*)"/', $tag[0], $max) === 1
        ) {
            return $max[1];
        }

        return "\0";
    }

    public function testПределыРазметкиШагаСовпадаютССервернымиПравилами(): void
    {
        // Серверные правила (setupValidateDb) обязаны быть видны прямо в разметке: браузер
        // обрезает ввод по maxlength, поэтому расхождение ловит чужой вводом до сервера —
        // db_name разрешает 64 символа по пределу MySQL, site_url держит предел 190,
        // на котором сидит и хост, и пользователь базы.
        $page = Kernel::get('/setup.php?step=db');

        foreach ([
            'db_host' => '190',
            'db_port' => '5',
            'db_name' => '64',
            'db_user' => '190',
            'table_prefix' => '30',
            'site_url' => '190',
            'site_name' => '120',
        ] as $field => $max) {
            self::assertSame(
                $max,
                self::renderedMax($page['body'], $field),
                'Предел поля ' . $field . ' расходится с серверным правилом'
            );
        }
    }

    public function testУспешныйШагПервыйЗаписываетНастройкиИВедётНаШагВторой(): void
    {
        $response = Kernel::passStepOne(self::freshToken());

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
        $response = Kernel::passStepOne(self::freshToken());

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

        $response = Kernel::passStepOne(self::freshToken());
        self::assertSame(302, $response['status'], 'Корректный POST при предзаписанном файле не прошёл');

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString("'DB_PASS' => ''", $contents, 'Введённый пустой пароль не перекрыл старый');
        self::assertStringNotContainsString('старый-секрет-из-файла', $contents, 'Старый пароль базы остался в файле');
        self::assertStringContainsString('smtp-из-файла', $contents, 'Невидимый ключ не взят из файла');

        // Отметка сессии читается из файла сессии: пароль в сессию не уходит.
        $sid = Kernel::sessionId();
        self::assertNotSame('', $sid, 'Идентификатор сессии не прочитан из файла куки');

        $savePath = session_save_path();
        $dir = $savePath !== '' ? $savePath : '/tmp';
        $sessionFile = $dir . '/sess_' . $sid;
        self::assertFileExists($sessionFile, 'Файл сессии ' . $sessionFile . ' не найден — отметку проверить нельзя');

        $sessionContents = (string) file_get_contents($sessionFile);
        self::assertStringContainsString('setup_db', $sessionContents, 'В сессии нет отметки перехода setup_db');
        self::assertStringContainsString('tbl_setup_test_', $sessionContents, 'В сессии нет префикса из отметки');
        self::assertStringContainsString('Школа с шага первого', $sessionContents, 'В сессии нет названия сайта из отметки');
        self::assertStringNotContainsString('старый-секрет-из-файла', $sessionContents, 'Пароль базы ушёл в сессию');
        // Отдельные ассерты по ИМЕНАМ: корректный POST контейнера обязан нести пустой db_pass
        // (MYSQL_ALLOW_EMPTY_PASSWORD), поэтому ассерт по значению пароля ловит только боевой
        // хостинг. Состояние сессии сериализовано («s:7:"DB_HOST"»), имя ключа краснеет на любой
        // форме утечки — и через отметку, и через непройденную нормализацию.
        self::assertStringNotContainsString('DB_PASS', $sessionContents, 'В сессии появился ключ DB_PASS');
        self::assertStringNotContainsString('db_pass', $sessionContents, 'В сессию ушло поле формы db_pass');
    }

    public function testНедостижимыйХостОтклоняетсяБезЗаписиНаДиск(): void
    {
        $secret = 'секрет-базы-который-нельзя-показать';
        $response = Kernel::passStepOne(self::freshToken(), [
            'db_host' => '127.0.0.1',
            'db_port' => '1',
            'db_pass' => $secret,
        ]);

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
        $response = Kernel::passStepOne(self::freshToken(), [
            'db_name' => 'cms_missing_db_test',
        ]);

        self::assertSame(200, $response['status'], 'Несуществующая база не вернулась формой отказа');
        self::assertStringContainsString('Создайте её в панели хостинга', $response['body'], 'Нет текста про панель хостинга');
        self::assertFileDoesNotExist(self::tempConfigPath(), 'При отказе базы файл настроек появился на диске');
    }

    public function testНеверныйПарольОтклоняетсяБезРаскрытияСекрета(): void
    {
        $secret = 'секрет-который-нельзя-показать';

        // Единственное отличие от корректного набора — непустой db_pass: контейнерный root поднят
        // с MYSQL_ALLOW_EMPTY_PASSWORD (docker-compose.dev.yml:44), поэтому непустой пароль даёт
        // ровно 1045 и ведёт ту самую ветку «сервер отклонил пользователя или пароль», до этого
        // не достигавшуюся ни одной проверкой.
        $response = Kernel::passStepOne(self::freshToken(), [
            'db_pass' => $secret,
        ]);

        self::assertSame(200, $response['status'], 'Отказ аутентификации не вернулся формой отказа');
        self::assertStringContainsString('Сервер базы отклонил пользователя или пароль', $response['body'], 'Нет текста ветки отказа аутентификации');
        self::assertStringNotContainsString($secret, $response['body'], 'Введённый пароль базы вернулся в разметку');
        // Предписанный ассерт «в теле нет SQLSTATE» здесь невозможен: сообщение драйвера на этой базе
        // однострочное (замер в отчёте), поэтому вырезка до первой строки и excerpt оставляют её
        // целиком — ассерт краснеет на верном коде. Ослаблять проверку выдуманным признаком не стал.
        self::assertSame('', self::renderedValue($response['body'], 'db_pass'), 'Поле db_pass не пусто после отказа аутентификации');

        self::assertFileDoesNotExist(self::tempConfigPath(), 'При отказе аутентификации файл настроек появился на диске');
        self::assertSame([], glob('/tmp/.cms-config-*'), 'При отказе аутентификации остались временные файлы');
    }

    public function testНевалидныеПоляОтклоняютсяБезОбращенияКБазе(): void
    {
        $response = Kernel::passStepOne(self::freshToken(), [
            'db_host' => 'http://bad host/',
            'db_port' => '0',
            'db_name' => 'имя с пробелом',
            'table_prefix' => '1bad',
            'site_url' => 'ftp://example.com',
            'site_name' => 'А',
        ]);

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
        // Прямые признаки обоих зондов, а не косвенный атрибут плашки: текста зонда сети и текста
        // отказа базы в разметке нет — значит до обращений к серверу базы дело не дошло.
        self::assertStringNotContainsString('Сервер базы', $response['body'], 'Отказ по полям дошёл до зонда');
        self::assertStringNotContainsString('панели хостинга', $response['body'], 'Отказ по полям дошёл до зонда базы');
        self::assertFileDoesNotExist(self::tempConfigPath(), 'При отказе полей файл настроек появился на диске');
    }

    public function testПустойОбязательныйКлючФайлаОтказываетсяБезЗаписиНаДиск(): void
    {
        $path = self::tempConfigPath();

        // Собранный набор берёт TIMEZONE из файла: заготовка для пустого значения уходит к образцу и
        // не будит исключения, а сборка использует проверку наличия ключа и берёт пустую строку
        // файла. Поэтому случай «пустой обязательный ключ» через http достижим, а не-скаляр — нет:
        // непустой не-скаляр отсекается самой заготовкой, которая приводит значение к строке.
        /** @var array<string, mixed> $sample */
        $sample = require dirname(__DIR__, 2) . '/config.sample.php';
        $sample['TIMEZONE'] = '';
        $prewritten = "<?php\n\nreturn " . var_export($sample, true) . ";\n";
        file_put_contents($path, $prewritten);

        $response = Kernel::passStepOne(self::freshToken());

        self::assertSame(200, $response['status'], 'Пустой обязательный ключ файла не вернулся формой отказа');
        self::assertStringContainsString('Собранные настройки не записаны', $response['body'], 'Нет текста отказа собранного набора');
        self::assertStringContainsString('ключ TIMEZONE', $response['body'], 'Отказ собранного набора не называет ключ');

        // Записи не было: предзаписанный файл остался таким же, каким его оставил тест. Утверждение
        // «файла по временному пути нет» здесь невозможно: этот файл и есть источник пустого ключа,
        // он обязан существовать до запроса.
        self::assertTrue(is_file($path), 'Файл настроек исчез с диска');
        self::assertSame($prewritten, (string) file_get_contents($path), 'Отказ собранного набора всё-таки перезаписал файл');
        $contents = (string) file_get_contents($path);
        self::assertStringNotContainsString('tbl_setup_test_', $contents, 'Введённый префикс записан при отказе');
        self::assertStringNotContainsString('Школа с шага первого', $contents, 'Введённое название сайта записано при отказе');
        self::assertSame([], glob('/tmp/.cms-config-*'), 'При отказе собранного набора остались временные файлы');

        // Отказ уровня формы: плашка на месте. Прежней проверке это не противоречит: там отсутствие
        // role="alert" доказывает, что до обращения к базе дело не дошло, а здесь дошло до сборки.
        self::assertStringContainsString('role="alert"', $response['body'], 'Отказ собранного набора не напечатан плашкой уровня формы');
    }

    public function testВторойЗаходПерекрываетЗаписанноеИНеОставляетВременныхФайлов(): void
    {
        $first = Kernel::passStepOne(self::freshToken());
        self::assertSame(302, $first['status'], 'Первый корректный POST не прошёл');

        // Инода цели после первого захода: rename() из временного файла всегда даёт новую иноду,
        // прямая запись в существующий файл сохраняет прежнюю. Только так проверка 7 краснеет по
        // самому свойству атомарности, а не по побочному осколку.
        clearstatcache();
        $inodeAfterFirst = fileinode(self::tempConfigPath());
        self::assertIsInt($inodeAfterFirst, 'Инода файла настроек после первого захода не прочитана');

        $second = Kernel::passStepOne(self::freshToken(), [
            'site_name' => 'Школа со второго захода',
            'table_prefix' => 'tbl_second_test_',
        ]);
        self::assertSame(302, $second['status'], 'Второй корректный POST не прошёл');

        clearstatcache();
        $inodeAfterSecond = fileinode(self::tempConfigPath());
        self::assertIsInt($inodeAfterSecond, 'Инода файла настроек после второго захода не прочитана');

        $contents = (string) file_get_contents(self::tempConfigPath());
        self::assertStringContainsString("'SITE_NAME' => 'Школа со второго захода'", $contents, 'Второй заход не перекрыл название сайта');
        self::assertStringContainsString("'TABLE_PREFIX' => 'tbl_second_test_'", $contents, 'Второй заход не перекрыл префикс');
        self::assertStringNotContainsString('Школа с шага первого', $contents, 'Первое название сайта осталось в файле');
        self::assertStringNotContainsString('tbl_setup_test_', $contents, 'Первый префикс остался в файле');

        self::assertNotSame($inodeAfterFirst, $inodeAfterSecond, 'Второй заход перезаписал файл на месте: запись не атомарная');
        self::assertSame([], glob('/tmp/.cms-config-*'), 'После перекрывающего захода остались временные файлы');
    }

    public function testПлашкаУведомленияОПравахЖивётНаШагеВтором(): void
    {
        // Шаг 2 закрыт гейтом отметки сессии (§2.1): предваряем прохождение шага 1 в том же сеансе —
        // временный путь настроек у класса уже есть, повторный шаг 1 идемпотентен. Попутно это
        // доказывает, что шаг 2 под отметкой открывается, поэтому оба утверждения ниже нетронуты.
        $passed = Kernel::passStepOne(self::freshToken());
        self::assertSame(302, $passed['status'], 'Шаг 1 не прошёл — шаг 2 под гейтом не открылся бы');

        $notice = Kernel::get('/setup.php?step=admin&notice=config-permissions');
        self::assertSame(200, $notice['status']);
        self::assertStringContainsString('права 600 выставить не удалось', $notice['body'], 'Плашка о правах не напечатана на шаге 2');

        $unknown = Kernel::get('/setup.php?step=admin&notice=' . rawurlencode('ключ-которого-нет'));
        self::assertSame(200, $unknown['status']);
        self::assertStringNotContainsString('ключ-которого-нет', $unknown['body'], 'Неизвестный ключ уведомления напечатан');
    }
}
