<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    private static string $tempConfigPath = '';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        // Ворота гоняют все наборы одним процессом: кеш настроев переживает классы, и без сброса
        // OptionsTest оставил бы в нём свои значения — проверка getOption() после установки читала бы
        // устаревший кеш, а не то, что записал установщик-подпроцесс.
        optionsReset();

        self::unlock();

        // Пакет M6-1: установщик заготавливает загрузчик из файла настроек (setupSeedConfig), и
        // сервер php -S обязан читать боевые для теста значения. Без отдельного файла сид подставил
        // бы образец (localhost/cms_dev), и установщик стучался бы не в cms_test.
        self::$tempConfigPath = sys_get_temp_dir() . '/cms-setup-test-config.php';
        self::writeReachableConfig();

        Kernel::start(['CMS_CONFIG_FILE' => self::$tempConfigPath]);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
        self::unlock();

        if (self::$tempConfigPath !== '' && is_file(self::$tempConfigPath)) {
            unlink(self::$tempConfigPath);
        }
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    private static function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/public/install.lock';
    }

    // Замок убирается до и после набора: он живёт в рабочем каталоге, а тесты обязаны быть
    // повторяемыми. В git он не попадает — public/install.lock закрыт в .gitignore (задача 1).
    private static function unlock(): void
    {
        if (is_file(self::lockPath())) {
            unlink(self::lockPath());
        }
    }

    // Файл возвращает те же обязательные ключи, что читает процесс PHPUnit: хост и имя базы берутся
    // из окружения контейнера, чтобы сервер php -S попал ровно в cms_test с тем же префиксом таблиц.
    private static function writeReachableConfig(): void
    {
        $config = [
            'DB_HOST' => (string) (getenv('CMS_DB_HOST') ?: 'db'),
            'DB_PORT' => (string) (getenv('CMS_DB_PORT') ?: '3306'),
            'DB_NAME' => 'cms_test',
            'DB_USER' => (string) (getenv('CMS_DB_USER') ?: 'root'),
            'DB_PASS' => (string) (getenv('CMS_DB_PASS') ?: ''),
            'TABLE_PREFIX' => 'tbl_',
            'SITE_URL' => 'http://127.0.0.1:8123',
            'SITE_NAME' => 'Тестовая школа',
            'TIMEZONE' => 'Europe/Moscow',
        ];

        file_put_contents(self::$tempConfigPath, "<?php\n\nreturn " . var_export($config, true) . ";\n");
    }

    public function testФормаУстановкиОткрывается(): void
    {
        $page = Kernel::get('/setup.php?step=admin');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Установка сайта', $page['body']);
        self::assertStringContainsString('method="post"', $page['body'], 'Форма с паролем обязана быть POST');
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        // Пакет V, находка 3 аудита просмотра: запрет кэширования обязан исходить от самой страницы, а не
        // из значения `session.cache_limiter` по умолчанию. Отказ проверки возвращает в форму введённые
        // название сайта и почту администратора, и сохранённая страница не должна доставаться
        // другому человеку.
        self::assertSame('no-store', $page['headers']['cache-control'], 'Страница установки не отдала собственный запрет кэширования');
    }

    public function testКороткийПарольНеСоздаётАдминистратора(): void
    {
        $page = Kernel::get('/setup.php?step=admin');
        $response = Kernel::post('/setup.php?step=admin', [
            '_token' => Kernel::tokenFrom($page['body']),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'короткий',
            'password_again' => 'короткий',
        ]);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Пароль — не короче 10 символов', $response['body']);
        self::assertFileDoesNotExist(self::lockPath());
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('user')));
    }

    public function testНесовпавшиеПаролиОтклоняются(): void
    {
        $page = Kernel::get('/setup.php?step=admin');
        $response = Kernel::post('/setup.php?step=admin', [
            '_token' => Kernel::tokenFrom($page['body']),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'другой-длинный-пароль',
        ]);

        self::assertStringContainsString('Пароли не совпадают', $response['body']);
        self::assertFileDoesNotExist(self::lockPath());
        // F9: отказ не должен возвращать введённый пароль в разметку — шаблон печатает только
        // белый список полей, и ни один из паролей в ответ не попадает.
        self::assertStringNotContainsString('длинный-тестовый-пароль', $response['body']);
        self::assertStringNotContainsString('другой-длинный-пароль', $response['body']);
    }

    public function testЧужойТокенОтклоняется(): void
    {
        $response = Kernel::post('/setup.php?step=admin', [
            '_token' => str_repeat('0', 64),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(403, $response['status']);
        self::assertFileDoesNotExist(self::lockPath());
    }

    public function testУстановкаСоздаётЯдроАдминистратораИЗамок(): void
    {
        $page = Kernel::get('/setup.php?step=admin');
        $response = Kernel::post('/setup.php?step=admin', [
            '_token' => Kernel::tokenFrom($page['body']),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('/login', $response['headers']['location']);
        self::assertStringContainsString('notice=site-installed', $response['headers']['location']);

        self::assertFileExists(self::lockPath());
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('user') . ' WHERE role = ?', ['admin']));
        self::assertSame(
            ['0001_core_baseline.sql'],
            array_map(
                static fn (array $row): string => (string) $row['name'],
                all('SELECT name FROM ' . t('app_migration') . ' WHERE app = ?', ['core'])
            )
        );
        self::assertSame(CORE_VERSION, (string) value('SELECT version FROM ' . t('app_installation') . ' WHERE id = ?', ['core']));
        self::assertSame('Школа английского', getOption('site_name'));
        self::assertSame('admin@school.dev', getOption('admin_email'));
    }

    public function testПослеЗамкаУстановщикОтвечает404(): void
    {
        $page = Kernel::get('/setup.php');

        self::assertSame(404, $page['status']);
        self::assertStringNotContainsString('Установка сайта', $page['body']);
        // Причина отказа обязана быть видна: notFound('Сайт уже установлен.') говорит человеку,
        // почему страница закрыта, иначе вместо ответа он получает общую подсказку про опечатку.
        self::assertStringContainsString('Сайт уже установлен.', $page['body']);
        self::assertStringNotContainsString('Адрес мог измениться', $page['body']);
    }

    // Порядок объявления важен: замок уже выставлен предыдущим прогоном, и POST обязан получить
    // тот же 404 до CSRF-проверки. Заглушка токена это доказывает: отказ происходит раньше,
    // чем сравнивается _token, иначе csrfCheck() выдал бы 403.
    public function testПослеЗамкаУстановщикОтклоняетИPOST(): void
    {
        $response = Kernel::post('/setup.php', [
            '_token' => str_repeat('0', 64),
            'site_name' => 'Школа английского',
            'admin_email' => 'second@school.dev',
            'admin_name' => 'Второй',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(404, $response['status']);
        self::assertStringNotContainsString('Установка сайта', $response['body']);
        self::assertSame(
            1,
            (int) value('SELECT COUNT(*) FROM ' . t('user') . ' WHERE role = ?', ['admin']),
            'POST поверх замка не должен создать второго администратора'
        );
    }

    // Замок физически лежит в docroot, поэтому проверяется ровно это: ответ 404, а не «403 или
    // 404». Прежнее допущение пропускало деградацию барьера до отдачи файла текстом — наружу
    // ушёл бы штамп времени установки. Содержание замка пишется сюда явным маркером: тест не
    // зависит от порядка объявления и сверяется с непустым эталоном.
    public function testЗамокНеОтдаётсяФайлом(): void
    {
        file_put_contents(self::lockPath(), 'маркер-содержимого-замка');

        $page = Kernel::get('/install.lock');

        self::assertSame(404, $page['status']);
        self::assertStringNotContainsString('маркер-содержимого-замка', $page['body']);
    }

    public function testВходСозданнымАдминистраторомРаботает(): void
    {
        $page = Kernel::get('/login');
        $response = Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => 'admin@school.dev',
            'password' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location']);
    }
}
