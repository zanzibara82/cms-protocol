<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use PHPUnit\Framework\TestCase;

// Пакет M6-1: установщик обязан открывать экран шага 1 даже без файла настроек на диске. Класс
// намеренно отделён от SetupTest (тот проверяет саму установку на боевой базе) и не переименован:
// здесь проверяется только разметка шага и приоритет заготовки, а база намеренно недоступна.
final class SetupConfigStepTest extends TestCase
{
    private const DB_FIELDS = ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'table_prefix', 'site_url', 'site_name'];

    private static string $tempConfigPath = '';

    public static function setUpBeforeClass(): void
    {
        self::unlock();

        // Файл на диске отсутствует: харнес передаёт CMS_CONFIG_FILE поверх обязательного префикса
        // config.sample.php (tests/http/Kernel.php), и присваивание идёт позже префикса, поэтому
        // сервер читает именно отсутствующий путь.
        self::$tempConfigPath = sys_get_temp_dir() . '/cms-setup-config-step-config.php';
        if (is_file(self::$tempConfigPath)) {
            unlink(self::$tempConfigPath);
        }

        Kernel::start(['CMS_CONFIG_FILE' => self::$tempConfigPath]);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        if (self::$tempConfigPath !== '' && is_file(self::$tempConfigPath)) {
            unlink(self::$tempConfigPath);
        }

        self::unlock();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
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

    // Значение поля читается из его же тега: name= и value= в шаблоне шага 1 идут в одном <input>,
    // поэтому хват тега целиком привязывает значение именно к полю, а не к любому SITE_URL в каркасе.
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

    public function testШагПервыйОткрываетсяБезФайлаНастроек(): void
    {
        $page = Kernel::get('/setup.php');

        self::assertSame(200, $page['status'], 'Экран шага 1 не открылся без файла настроек');
        self::assertStringContainsString('name="robots"', $page['body'], 'Каркас не печатает метку роботов');
        self::assertStringContainsString('noindex', $page['body'], 'Экран шага 1 не помечен как noindex');
        self::assertSame('no-store', $page['headers']['cache-control'], 'Экран шага 1 не отдал запрет кэширования');
        self::assertStringContainsString('method="post"', $page['body'], 'Форма шага 1 обязана быть POST');

        foreach (self::DB_FIELDS as $field) {
            self::assertStringContainsString('name="' . $field . '"', $page['body'], 'На экране шага 1 нет поля ' . $field);
        }

        self::assertStringContainsString('Шаг первый из двух', $page['body'], 'Экран шага 1 не объявил себя первым шагом');
    }

    public function testПарольБазыНеПоказываетсяИЧужойТокенОтклоняется(): void
    {
        $page = Kernel::get('/setup.php?step=db');

        $dbPassLine = '';
        foreach (explode("\n", $page['body']) as $candidate) {
            if (str_contains($candidate, 'name="db_pass"')) {
                $dbPassLine = $candidate;
                break;
            }
        }

        self::assertNotSame('', $dbPassLine, 'В разметке шага 1 нет поля db_pass');
        self::assertStringContainsString('value=""', $dbPassLine, 'Поле db_pass обязано печататься пустым (правило F9)');

        $response = Kernel::post('/setup.php?step=db', [
            '_token' => str_repeat('0', 64),
            'db_host' => 'хост-из-отказа',
            'db_pass' => 'секрет-который-нельзя-показывать',
        ]);

        self::assertSame(403, $response['status'], 'Чужой токен шага 1 не отклонён');
        self::assertStringNotContainsString('секрет-который-нельзя-показывать', $response['body'], 'Введённый пароль базы вернулся в ответ');
        self::assertStringNotContainsString('хост-из-отказа', $response['body'], 'Введённое значение вернулось после отказа');
    }

    public function testШагВторойЖивётПодОтметкойStepAdmin(): void
    {
        $admin = Kernel::get('/setup.php?step=admin');
        self::assertStringContainsString('Установка сайта', $admin['body'], 'Шаг 2 не открылся под отметкой step=admin');
        self::assertStringContainsString('name="admin_email"', $admin['body'], 'Шаг 2 не печатает поле администратора');

        $db = Kernel::get('/setup.php?step=db');
        self::assertStringNotContainsString('name="admin_email"', $db['body'], 'Шаг 1 не должен печатать поле администратора');

        $garbage = Kernel::get('/setup.php?step=' . rawurlencode('что-угодно'));
        self::assertStringNotContainsString('name="admin_email"', $garbage['body'], 'Мусорная отметка шага повела себя не как шаг 1');
        self::assertStringContainsString('name="db_host"', $garbage['body'], 'Мусорная отметка шага не открыла экран шага 1');
    }

    public function testЗначенияИзФайлаПерекрываютОбразец(): void
    {
        file_put_contents(
            self::$tempConfigPath,
            "<?php\n\nreturn " . var_export([
                'DB_HOST' => 'mysql.example-host.test',
                'SITE_URL' => '',
            ], true) . ";\n"
        );

        try {
            $page = Kernel::get('/setup.php');

            self::assertSame(200, $page['status']);
            self::assertSame('mysql.example-host.test', self::renderedValue($page['body'], 'db_host'), 'Значение из файла не перекрывает образец');
            self::assertSame('http://localhost:8080', self::renderedValue($page['body'], 'site_url'), 'Пустой ключ файла не упал на образец');
        } finally {
            unlink(self::$tempConfigPath);
        }
    }
}
