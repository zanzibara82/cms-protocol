<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    private static function tempConfigPath(): string
    {
        return '/tmp/cms-setup-test-config.php';
    }

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        // Ворота гоняют все наборы одним процессом: кеш настроев переживает классы, и без сброса
        // OptionsTest оставил бы в нём свои значения — проверка getOption() после установки читала бы
        // устаревший кеш, а не то, что записал установщик-подпроцесс.
        optionsReset();

        self::unlock();

        // Уборка осколков ДО прогона, симметрично уборке после: прогон, убитый между
        // file_put_contents() и rename(), иначе краснит следующий прогон по чужой вине.
        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        $path = self::tempConfigPath();
        if (is_file($path)) {
            unlink($path);
        }

        // Набор CMS_* обнулён через Kernel::installerEnv(): причина — в самом помощнике, а здесь важно
        // только то, что шаг 1 пишет временный файл настроек и сервер после записи читает его, поэтому
        // CMS_DB_NAME из префикса команды (Kernel.php:51) не должен пересиливать записанное значение.
        Kernel::start(Kernel::installerEnv($path));
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

    // Проходит шаг 1 в текущем (свежем) сеансе, чтобы шаг 2 открылся под гейтом отметки сессии
    // (public/setup.php). Префикс tbl_ совпадает с config.sample.php: сервер после записи читает
    // временный файл именно с этим префиксом, а тест-процесс читает config.sample.php; при разных
    // префиксах t('user') в проверке и таблицы, созданные установкой, разошлись бы, и ассерты по
    // количеству администраторов смотрели бы в несуществующую таблицу.
    private function enterStepTwo(): void
    {
        $page = Kernel::get('/setup.php?step=db');
        $response = Kernel::passStepOne(Kernel::tokenFrom($page['body']), ['table_prefix' => 'tbl_']);

        self::assertSame(302, $response['status'], 'Шаг 1 не прошёл — шаг 2 под гейтом не открылся бы');
    }

    // Первая по объявлению: замок выставляют только последующие проверки, а порядок исполнения —
    // порядок объявления, поэтому здесь сеанс гарантированно без отметки, без замка и без записей.
    public function testБезОтметкиСессииШагВторойВозвращаетНаПервыйШаг(): void
    {
        $blocked = Kernel::get('/setup.php?step=admin');

        self::assertSame(302, $blocked['status'], 'Шаг 2 без отметки сессии не вернул редирект на шаг 1');
        $target = $blocked['headers']['location'] ?? '';
        // queryAppend() дописывает notice к уже имеющемуся step, поэтому сверяем вхождением, а не
        // равенством всей строки: цель — /setup.php?step=db&notice=db-step-required.
        self::assertStringContainsString('/setup.php?step=db', $target, 'Отказ шага 2 ведёт не на шаг 1');
        self::assertStringContainsString('notice=db-step-required', $target, 'Отказ шага 2 не нёс ключ объяснения');

        $explained = Kernel::get($target);
        self::assertSame(200, $explained['status'], 'Цель редиректа отказа не открыла экран шага 1');
        self::assertStringContainsString(
            (string) CORE_NOTICE_TEXT['db-step-required'],
            $explained['body'],
            'Экран шага 1 не напечатал текст объяснения'
        );

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('user')), 'Отказ шага 2 создал пользователя');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('app_installation')), 'Отказ шага 2 создал запись установки');

        // Вторая половина держит порядок гейта: мусорный токен на шаге 2 без отметки даёт 302, а не
        // 403 — это единственная проверка, отличающая «гейт до csrfCheck()» от «гейт после неё».
        $posted = Kernel::post('/setup.php?step=admin', [
            '_token' => str_repeat('0', 64),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(302, $posted['status'], 'POST шага 2 без отметки вернул не редирект (ожидался 302 до токена)');
        $postedTarget = $posted['headers']['location'] ?? '';
        self::assertStringContainsString('/setup.php?step=db', $postedTarget, 'POST-отказ шага 2 ведёт не на шаг 1');
        self::assertStringContainsString('notice=db-step-required', $postedTarget, 'POST-отказ шага 2 не нёс ключ объяснения');

        // Отказ по течению не тратит замок и не трогает базу: файл замка отсутствует после того же POST.
        self::assertFileDoesNotExist(self::lockPath(), 'Отказ шага 2 выставил замок');
    }

    public function testФормаУстановкиОткрывается(): void
    {
        // Шаг 2 закрыт гейтом отметки сессии (§2.1): сеанс обязан предварительно пройти шаг 1.
        $this->enterStepTwo();
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
        $this->enterStepTwo();
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
        $this->enterStepTwo();
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
        // Сеанс прошёл шаг 1, поэтому отметка есть и гейт шага 2 пропускает запрос к csrfCheck():
        // отказ обязан остаться 403 — это доказывает, что гейт не отменил барьер токена.
        $this->enterStepTwo();
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
        // Боевая установка живёт на шаге 2 и закрыта гейтом: сначала прохождается шаг 1 в том же
        // сеансе, затем POST шага 2 создаёт ядро, администратора и замок.
        $this->enterStepTwo();
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
