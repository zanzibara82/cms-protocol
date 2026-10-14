<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Класс отделён от `SetupTest` нарочно: он роняет схему на середине шага 2 и обязан восстановить её
// сам, а порядок проверок `SetupTest` от этого зависеть не должен. Временный файл настроек у класса
// свой (`/tmp/cms-setup-crash-config.php`), чтобы не пересекаться с `SetupTest`.
final class SetupCrashTest extends TestCase
{
    private static function tempConfigPath(): string
    {
        return '/tmp/cms-setup-crash-config.php';
    }

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        // Кеш настроев живёт в процессе PHPUnit и переживает классы: без сброса чтение `site_name`
        // после срыва показало бы значение прошлого набора, а не то, что записал установщик.
        optionsReset();

        self::unlock();

        // Уборка осколков ДО прогона, симметрично уборке после: прогон, убитый между
        // `file_put_contents()` и `rename()`, иначе краснит следующий прогон по чужой вине.
        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        $path = self::tempConfigPath();
        if (is_file($path)) {
            unlink($path);
        }

        Kernel::start(Kernel::installerEnv($path));
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        $path = self::tempConfigPath();
        if (is_file($path)) {
            unlink($path);
        }

        self::unlock();

        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        // Схема восстанавливается после класса: первая половина проверки её ломает, а следующий
        // набор стартует с пустой базы и не обязан разбираться, что здесь произошло.
        TestDb::reset();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    private static function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/public/install.lock';
    }

    // Замок живёт в рабочем каталоге, поэтому убирается до и после набора; в `git` он не попадает.
    private static function unlock(): void
    {
        if (is_file(self::lockPath())) {
            unlink(self::lockPath());
        }
    }

    public function testСрывШагаВторогоСнимаетЗамокИОставляетОтметку(): void
    {
        // Набор шага 2 валиден целиком (тот же, что в боевой проверке `SetupTest`): срыв обязан
        // произойти из-за сломанной схемы, а не из-за отказа проверки полей.
        $adminFields = [
            'site_name' => 'Школа английского',
            'admin_email' => 'crash@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'длинный-тестовый-пароль',
        ];

        $passed = Kernel::passStepOne(Kernel::tokenFrom(Kernel::get('/setup.php?step=db')['body']), ['table_prefix' => 'tbl_']);
        self::assertSame(302, $passed['status'], 'Шаг 1 не прошёл — шаг 2 под гейтом не открылся бы');

        // Таблица пользователей снимается ровно тем же способом, каким её удаляет `TestDb::reset()`.
        // Имя базы проверяет готовый помощник: `TestDb::reset()` первым делом зовёт собственный
        // предохранитель «сброс разрешён только для cms_test», поэтому здесь не заведено ни копии
        // проверки, ни собственного подавления.
        run('SET FOREIGN_KEY_CHECKS = 0');
        run('DROP TABLE IF EXISTS ' . t('user'));
        run('SET FOREIGN_KEY_CHECKS = 1');

        // Однего удаления для срыва мало (замер записан в отчёт): шаг 2 первым делом зовёт
        // `setupApplyBaseline()`, а тот поднимает таблицу по `CREATE TABLE IF NOT EXISTS` и молча
        // залечивает удаление — `POST` шага 2 ответил бы 302 вместо 500. Поэтому на месте снятой
        // таблицы остаётся пригодная только для чтения пустышка с одним столбцом: имя занято,
        // базовый файл её не пересоздаёт, а вставка администратора падает — ровно там, где
        // спецификация ждёт срыва ПОСЛЕ частичной записи опций.
        run('CREATE TABLE ' . t('user') . ' (id VARCHAR(26) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $token = Kernel::tokenFrom(Kernel::get('/setup.php?step=admin')['body']);
        $crashed = Kernel::post('/setup.php?step=admin', array_merge($adminFields, ['_token' => $token]));

        // Срыв — экран непойманного исключения: текст статичный из `core/templates/error.php`,
        // а `Cache-Control: no-store` обязана нести сама строка ответа.
        self::assertSame(500, $crashed['status'], 'Срыв шага 2 не ответил экраном ошибки');
        self::assertStringContainsString('Что-то сломалось', $crashed['body'], 'Экран непойманного исключения не напечатан');
        self::assertSame('no-store', $crashed['headers']['cache-control'] ?? '', 'Экран срыва не отдал собственный запрет кэширования');

        // Ветка `catch` сняла занятый замок: попытку можно повторить.
        self::assertFileDoesNotExist(self::lockPath(), 'После срыва шага 2 замок остался на месте');

        // Срыв случился ПОСЛЕ частичной записи, а не до неё: название сайта уже в базе.
        optionsReset();
        self::assertSame('Школа английского', getOption('site_name'), 'До срыва не записалась ни одна опция — срыв произошёл до записи');

        // Администраторов ноль. Счётчик снимается здесь, по испорченной таблице: она на месте и
        // пуста, а вставка администратора и была той точкой, где предложение оборвалось. После
        // восстановления схемы проверять было бы нечего — `TestDb::reset()` пересоздаёт таблицу, и
        // нуль в ней не отличал бы срыв до вставки от срыва после неё.
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('user')), 'Срыв шага 2 всё же создал пользователя');

        // Вторая половина: схема восстановлена, шаг 1 заново не проходился, куки не трогались —
        // тот же сеанс. Отметка сессии обязана пережить срыв, иначе повтор упрётся в гейт шага 1.
        TestDb::reset();

        $retried = Kernel::post('/setup.php?step=admin', array_merge($adminFields, ['_token' => $token]));

        self::assertSame(302, $retried['status'], 'Повторный POST шага 2 после восстановления не прошёл');
        self::assertStringContainsString('/login', $retried['headers']['location'] ?? '', 'Повторная установка повела не на вход');
        self::assertStringContainsString('notice=site-installed', $retried['headers']['location'] ?? '', 'Повторная установка не принесла уведомления о завершении');
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('user') . ' WHERE role = ?', ['admin']), 'Повторная установка создала не одного администратора');
        self::assertFileExists(self::lockPath(), 'Повторная установка не выставила замок');
    }
}
