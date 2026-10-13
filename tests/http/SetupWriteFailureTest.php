<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use PHPUnit\Framework\TestCase;

// Пакет M6-2, фикс-раунд 1: отказ записи на уровне диска. Цель указывает в несуществующий каталог,
// поэтому file_put_contents() обязан упасть даже под root (нет пути, а не прав) — ветка отказа
// достижима. Класс отдельный, потому что уровень цели и путь журнала задаются окружением на весь
// класс: в общем классе он отравил бы бытовые проверки шага 1.
final class SetupWriteFailureTest extends TestCase
{
    private static function dir(): string
    {
        return '/tmp/m62-write-fail';
    }

    private static function logFilePath(): string
    {
        return self::dir() . '/errors.log';
    }

    private static function targetPath(): string
    {
        return self::dir() . '/nowhere/config.php';
    }

    public static function setUpBeforeClass(): void
    {
        self::unlock();

        // Уборка осколков ДО прогона, симметрично уборке после: прогон, убитый между
        // file_put_contents() и rename(), иначе краснит следующий прогон по чужой вине.
        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0700, true) && !is_dir(self::dir())) {
            self::fail('Харнес не создал каталог ' . self::dir() . ' — журнал отказа некуда писать');
        }

        @unlink(self::logFilePath());

        Kernel::start([
            'CMS_CONFIG_FILE' => self::targetPath(),
            'CMS_LOG_PATH' => self::logFilePath(),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        if (is_file(self::logFilePath())) {
            @unlink(self::logFilePath());
        }

        foreach (glob('/tmp/.cms-config-*') ?: [] as $stray) {
            @unlink($stray);
        }

        if (is_dir(self::dir())) {
            @rmdir(self::dir());
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
     * Корректный запрос шага 1: оба зонда обязаны пройти, чтобы дело дошло до записи — а падает
     * именно запись. db_pass пустой: это свойство контейнера (MYSQL_ALLOW_EMPTY_PASSWORD,
     * docker-compose.dev.yml:44). Название сайта содержит «секрет», чтобы проверка журнала была
     * не пустой: значение не должно попасть в журнал ни в каком виде.
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
            'table_prefix' => 'tbl_m62fail_',
            'site_url' => 'http://localhost:8080/',
            'site_name' => 'Школа с секрет-названием',
        ];
    }

    // Последняя непустая строка журнала разбирается как json: без exec и без вызова оболочки.
    /** @return array<string, mixed> */
    private static function lastLogEntry(string $log): array
    {
        $lines = array_filter(
            explode("\n", $log),
            static fn (string $line): bool => trim($line) !== ''
        );
        self::assertNotEmpty($lines, 'Журнал пуст — причина отказа в него не записана');

        $decoded = json_decode((string) end($lines), true);
        self::assertIsArray($decoded, 'Последняя строка журнала не разбирается как json');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testЦельВНедоступномКаталогеОтклоняетсяИПишетПричинуВЖурнал(): void
    {
        $response = Kernel::post('/setup.php?step=db', self::validPost(self::freshToken()));

        self::assertSame(200, $response['status'], 'Отказ записи в недоступный каталог не вернулся формой');
        self::assertStringContainsString('Не удалось подготовить файл настроек', $response['body'], 'Нет текста отказа записи');

        self::assertFileDoesNotExist(self::targetPath(), 'Отказавшая запись создала файл настроек');
        self::assertSame([], glob('/tmp/.cms-config-*'), 'После отказа записи остались временные файлы');

        $journal = self::logFilePath();
        self::assertFileExists($journal, 'Журнал отказа не создан — причина не записана');

        $entry = self::lastLogEntry((string) file_get_contents($journal));
        self::assertSame('setup.config_write_failed', $entry['event'] ?? null, 'В журнале нет события отказа записи');

        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        self::assertNotSame('', (string) ($context['reason'] ?? ''), 'Причина отказа записи в журнал не записана');

        // Секретов в журнале нет: ни подстроки «секрет», ни введённого названия сайта, ни пароля.
        self::assertStringNotContainsString('секрет', (string) file_get_contents($journal), 'В журнал попало значение из формы');
        self::assertStringNotContainsString('db_pass', (string) file_get_contents($journal), 'В журнале появились поля формы');
    }
}
