<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use PHPUnit\Framework\TestCase;

// Пакет M6-2: отказ записи по имени цели никогда не целился в настоящий config.sample.php, иначе
// проверка стирала бы версионированный образец. CMS_CONFIG_FILE указывает на копию образца в /tmp,
// refusal проверяется именно на ней.
final class SetupSampleTargetTest extends TestCase
{
    private static function targetPath(): string
    {
        return '/tmp/m62-sample-target/config.sample.php';
    }

    public static function setUpBeforeClass(): void
    {
        self::unlock();

        // Уборка осколков ДО прогона, симметрично уборке после: прогон, убитый между
        // file_put_contents() и rename(), иначе краснит следующий прогон по чужой вине.
        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        $dir = dirname(self::targetPath());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            self::fail('Харнес не создал каталог ' . $dir . ' — копию образца некуда положить');
        }

        // Копия боевого образца: отказ уровня цели проверяется на ней, реальный файл не трогается.
        // Возврат copy() проверяется: без него недоступный /tmp молча переносил бы падение на
        // filemtime() несуществующего файла.
        if (!@copy(dirname(__DIR__, 2) . '/config.sample.php', self::targetPath())) {
            self::fail('Харнес не скопировал образец в ' . self::targetPath() . ' — цель проверки не готова');
        }

        Kernel::start(['CMS_CONFIG_FILE' => self::targetPath()]);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        $target = self::targetPath();
        if (is_file($target)) {
            unlink($target);
        }

        $dir = dirname($target);
        foreach (glob($dir . '/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        if (is_dir($dir)) {
            @rmdir($dir);
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

    private static function freshToken(): string
    {
        $page = Kernel::get('/setup.php?step=db');

        return Kernel::tokenFrom($page['body']);
    }

    /**
     * Корректный запрос: отказ уровня цели не опережает зонд (порядок общий — валидация, зонд,
     * запись), поэтому параметры базы обязаны пройти оба зонда. Пустой пароль — свойство
     * контейнера (MYSQL_ALLOW_EMPTY_PASSWORD, docker-compose.dev.yml:44).
     *
     * @return array<string, string>
     */
    private static function validPost(string $token): array
    {
        return [
            '_token' => $token,
            'db_host' => 'db',
            'db_port' => '3306',
            'db_name' => 'cms_test',
            'db_user' => 'root',
            'db_pass' => '',
            'table_prefix' => 'tbl_setup_test_',
            'site_url' => 'http://localhost:8080/',
            'site_name' => 'Школа с шага первого',
        ];
    }

    public function testОбразецНаПутиЦелиНеПерезаписывается(): void
    {
        $target = self::targetPath();
        clearstatcache();
        $mtimeBefore = (int) filemtime($target);
        $hashBefore = (string) hash_file('sha256', $target);

        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken()));

        self::assertSame(200, $response['status'], 'Запись в цель-образец не вернулась формой отказа');
        self::assertStringContainsString('это образец, а не рабочий файл', $response['body'], 'Нет текста отказа по имени цели');

        clearstatcache();
        self::assertSame($mtimeBefore, (int) filemtime($target), 'Копия образца изменилась по времени');
        self::assertSame($hashBefore, (string) hash_file('sha256', $target), 'Копия образца изменилась по содержимому');
        self::assertSame([], glob(dirname($target) . '/.cms-config-*'), 'У цели-образца остались временные файлы');
    }
}
