<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// F2: незаписываемый каталог журнала не должен валить сайт. logWrite() деградирует в no-op,
// если нельзя создать каталог или файл, и никогда не бросает исключение из штатного потока.
final class LogDegradationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CMS_LOG_PATH');
    }

    public function testНезаписываемыйПутьНеБросаетИсключениеИПишетНичего(): void
    {
        // Каталог нельзя создать: один из предков — обычный файл, а не каталог.
        $unreachable = dirname(__DIR__, 2) . '/composer.json/cms-log-probe/errors.log';
        putenv('CMS_LOG_PATH=' . $unreachable);

        logWrite('warn', 'access.forbidden', ['probe' => true]);

        // Основной поток продолжается: функция вернула управление, файл журнала не появился.
        self::assertFileDoesNotExist($unreachable, 'При невозможности создать журнал файл появляться не должен');
    }

    public function testЗаписываемыйПутьПишетСтрокуКакПрежде(): void
    {
        $dir = sys_get_temp_dir() . '/cms-log-probe-' . getmypid();
        $path = $dir . '/errors.log';
        putenv('CMS_LOG_PATH=' . $path);

        logWrite('info', 'probe.event', ['mark' => 'значение']);

        self::assertFileExists($path, 'Обычная ветка обязана писать журнал');
        $line = trim((string) file_get_contents($path));
        self::assertStringContainsString('probe.event', $line);
        self::assertStringContainsString('значение', $line);

        @unlink($path);
        @rmdir($dir);
    }
}
