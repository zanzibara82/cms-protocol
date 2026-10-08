<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Лимиты входа и журнал читают адрес клиента. Если взять его из заголовка, любой посетитель
// обойдёт блокировку одной строчкой в curl — правило из спецификации §7.
final class ClientIpTest extends TestCase
{
    private const FORBIDDEN_HEADERS = ['x-forwarded-for', 'x-real-ip', 'client-ip', 'x-client-ip', 'forwarded'];

    public function testКодНеЧитаетАдресИзПодставляемыхЗаголовков(): void
    {
        $violations = [];
        $root = dirname(__DIR__, 2);

        foreach (['core', 'admin', 'theme', 'apps', 'public'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if (!$item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }
                $code = strtolower((string) file_get_contents($item->getPathname()));
                foreach (self::FORBIDDEN_HEADERS as $header) {
                    if (str_contains($code, $header)) {
                        $violations[] = $item->getPathname() . ': ' . $header;
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Адрес клиента берётся только из REMOTE_ADDR');
    }

    public function testЕдинственныйИсточникАдресаЭтоRemoteAddr(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/core/request.php');

        self::assertStringContainsString('REMOTE_ADDR', $code);
        self::assertSame(1, preg_match_all('/REMOTE_ADDR/', $code), 'clientIp() читает REMOTE_ADDR один раз');
    }
}
