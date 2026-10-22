<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/version.php';

/**
 * Состояние движка PHP по обновлению безопасности 21 октября 2026 года. Выпуск 8.3.36 закрывает
 * CVE-2026-109618 и CVE-2026-109619 (повреждение состояния движка при аварийном выходе; в
 * объявлении releases указана возможность удалённого выполнения кода без аутентификации). Частичных
 * мер нет: обновление движка — единственный способ закрыть дыру, поэтому список безопасных версий
 * проверяется, а не подразумевается.
 */
final class PhpSecurityTest extends TestCase
{
    public function testИсправленнаяВерсияСвоейВеткиСчитаетсяЗакрытой(): void
    {
        self::assertSame('ok', corePhpSecurityState('8.2.35'));
        self::assertSame('ok', corePhpSecurityState('8.3.36'));
        self::assertSame('ok', corePhpSecurityState('8.4.27'));
        self::assertSame('ok', corePhpSecurityState('8.5.12'));
    }

    public function testВерсияДоОбновленияСчитаетсяОткрытой(): void
    {
        self::assertSame('old', corePhpSecurityState('8.2.34'));
        self::assertSame('old', corePhpSecurityState('8.3.35'));
        self::assertSame('old', corePhpSecurityState('8.4.26'));
        self::assertSame('old', corePhpSecurityState('8.5.11'));
    }

    public function testВеткаБезИсправленийОтличаетсяОтНеизвестной(): void
    {
        self::assertSame('none', corePhpSecurityState('8.1.29'), 'поддержка 8.1 закончилась: исправлений не будет');
        self::assertSame('unknown', corePhpSecurityState('9.0.0'), 'о ветке новее списка данных у нас нет');
    }

    public function testПодсказкаНазываетНужнуюВерсиюИОбновление(): void
    {
        $hint = corePhpSecurityHint('8.3.35');

        self::assertStringContainsString('8.3.36', $hint, 'подсказка обязана называть версию, на которую переходить');
        self::assertStringContainsString('CVE-2026-109618', $hint);
        self::assertStringContainsString('CVE-2026-109619', $hint);
        self::assertSame('', corePhpSecurityHint('8.3.36'), 'на исправленной версии называть нечего');
    }

    public function testДвижокВОкруженияОбновлён(): void
    {
        // Прогон на уязвимом движке обязан краснеть: гейт должен ловить не только уязвимый код,
        // но и уязвимый рантайм, в котором этот код исполняется.
        self::assertSame(
            'ok',
            corePhpSecurityState(PHP_VERSION),
            'PHP ' . PHP_VERSION . ' — версия до обновления безопасности 21 октября 2026 года'
        );
    }
}
