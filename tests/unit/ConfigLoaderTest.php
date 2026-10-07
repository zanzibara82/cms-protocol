<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/date.php';
require_once dirname(__DIR__, 2) . '/core/log.php';
require_once dirname(__DIR__, 2) . '/core/config-loader.php';

// Dev-контейнер задачи 1 выставляет CMS_SMTP_HOST=mailpit, и config() честно применяет
// перекрытие из окружения. Тест проверяет ключ без значения, поэтому переменную обнуляем
// здесь же: до первого вызова config() кеш настроек ещё пуст.
putenv('CMS_SMTP_HOST=');

final class ConfigLoaderTest extends TestCase
{
    public function testНастройкиБерутсяИзФайлаОбразца(): void
    {
        putenv('CMS_CONFIG_FILE=' . dirname(__DIR__, 2) . '/config.sample.php');

        self::assertSame('tbl_', configValue('TABLE_PREFIX'));
        self::assertSame('Europe/Moscow', configValue('TIMEZONE'));
    }

    public function testОкружениеПерекрываетФайл(): void
    {
        putenv('CMS_CONFIG_FILE=' . dirname(__DIR__, 2) . '/config.sample.php');
        putenv('CMS_DB_NAME=cms_test');

        self::assertSame('cms_test', configValue('DB_NAME'));
    }

    public function testОтсутствующийКлючОтдаётЗначениеПоУмолчанию(): void
    {
        self::assertSame('', (string) configValue('SMTP_HOST', ''));
        self::assertNull(configValue('такого_ключа_нет'));
    }

    public function testBootstrapПодключаетсяИДаётБазовыеФункции(): void
    {
        self::assertTrue(function_exists('e'));
        self::assertTrue(function_exists('newId'));
        self::assertTrue(function_exists('nowUtc'));
        // Отдельного assertTrue(defined('CORE_VERSION')) здесь нет: PHPStan знает файл константы
        // и считает такое утверждение всегда истинным (находка alreadyNarrowedType). Следующая
        // строка проверяет и определение, и значение: без константы самого обращения не будет.
        self::assertSame('1.1.0', CORE_VERSION);
    }
}
