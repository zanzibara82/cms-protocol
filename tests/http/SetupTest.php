<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        // Ворота гоняют все наборы одним процессом: кеш настроев переживает классы, и без сброса
        // OptionsTest оставил бы в нём свои значения — проверка getOption() после установки читала бы
        // устаревший кеш, а не то, что записал установщик-подпроцесс.
        optionsReset();

        self::unlock();
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
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

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $m);

        return $m[1] ?? '';
    }

    public function testФормаУстановкиОткрывается(): void
    {
        $page = Kernel::get('/setup.php');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Установка сайта', $page['body']);
        self::assertStringContainsString('method="post"', $page['body'], 'Форма с паролем обязана быть POST');
        self::assertStringContainsString('noindex, nofollow', $page['body']);
    }

    public function testКороткийПарольНеСоздаётАдминистратора(): void
    {
        $page = Kernel::get('/setup.php');
        $response = Kernel::post('/setup.php', [
            '_token' => $this->token($page['body']),
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
        $page = Kernel::get('/setup.php');
        $response = Kernel::post('/setup.php', [
            '_token' => $this->token($page['body']),
            'site_name' => 'Школа английского',
            'admin_email' => 'admin@school.dev',
            'admin_name' => 'Алексей',
            'password' => 'длинный-тестовый-пароль',
            'password_again' => 'другой-длинный-пароль',
        ]);

        self::assertStringContainsString('Пароли не совпадают', $response['body']);
        self::assertFileDoesNotExist(self::lockPath());
    }

    public function testЧужойТокенОтклоняется(): void
    {
        $response = Kernel::post('/setup.php', [
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
        $page = Kernel::get('/setup.php');
        $response = Kernel::post('/setup.php', [
            '_token' => $this->token($page['body']),
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
    }

    public function testЗамокНеОтдаётсяФайлом(): void
    {
        $page = Kernel::get('/install.lock');

        self::assertContains($page['status'], [403, 404]);
        self::assertStringNotContainsString('install.lock', $page['body']);
    }

    public function testВходСозданнымАдминистраторомРаботает(): void
    {
        $page = Kernel::get('/login');
        $response = Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => 'admin@school.dev',
            'password' => 'длинный-тестовый-пароль',
        ]);

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location']);
    }
}
