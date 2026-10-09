<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Лимиты входа и журнал читают адрес клиента. Если взять его из заголовка, любой посетитель
// обойдёт блокировку одной строчкой в curl — правило из спецификации §7.
//
// Поиск идёт по токенам, а не по подстроке сырого текста, и по нормализованному ключу
// (регистр и дефис с подчёркиванием равноправны). В суперглобальном массиве имя заголовка
// всегда с префиксом HTTP_ и подчёркиваниями: «X-Real-IP» приходит как HTTP_X_REAL_IP,
// «Client-IP» — как HTTP_CLIENT_IP. Дефисные образцы таких чтений не видели вовсе, поэтому
// страж оставался зелёным, пока clientIp() читал подставной заголовок.
final class ClientIpTest extends TestCase
{
    // Зона обхода — всё дерево кода, а не один request.php: адрес могли читать и в другом файле.
    private const SCAN_DIRS = ['core', 'admin', 'theme', 'apps', 'public'];

    // Ключи в нормализованном виде: строчные буквы, подчёркивания вместо дефисов.
    private const FORBIDDEN_KEYS = ['x_forwarded_for', 'x_real_ip', 'client_ip', 'x_client_ip', 'forwarded'];

    // Единственный файл, которому разрешено читать серверный адрес: остальным — только clientIp().
    private const REMOTE_ADDR_SOURCE = 'core/request.php';

    public function testКодНеЧитаетАдресИзПодставляемыхЗаголовков(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            foreach ($this->headerReads((string) file_get_contents($file)) as $violation) {
                $violations[] = $this->relative($file) . ': ' . $violation;
            }
        }

        self::assertSame([], $violations, 'Адрес клиента берётся только из серверного массива: подставляемые заголовки не читаются нигде, включая комментарии');
    }

    // Счёт ведётся по всему дереву: правка, из-за которой второй адрес начал читаться в другом
    // файле в обход clientIp(), обязана краснить ворота, а не проходить молча.
    public function testЕдинственныйИсточникАдресаЭтоRemoteAddr(): void
    {
        $occurrences = [];

        foreach ($this->phpFiles() as $file) {
            $count = preg_match_all('/REMOTE_ADDR/', (string) file_get_contents($file));
            if ($count > 0) {
                $occurrences[$this->relative($file)] = $count;
            }
        }

        self::assertSame(
            [self::REMOTE_ADDR_SOURCE => 1],
            $occurrences,
            'clientIp() — единственное место в дереве, где читается серверный адрес; остальные обязаны звать её'
        );
    }

    /**
     * Чтения подставляемых заголовков в исходнике: каждый токен нормализуется и сверяется со
     * списком. Комментарии не выбрасываются — название заголовка в русском комментарии считается
     * тем же сигналом, что и в коде.
     *
     * @return list<string>
     */
    private function headerReads(string $code): array
    {
        $found = [];

        foreach (token_get_all($code) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? $token[2] : 0;
            $normalized = str_replace('-', '_', strtolower($text));

            foreach (self::FORBIDDEN_KEYS as $key) {
                if (str_contains($normalized, $key)) {
                    $found[] = 'строка ' . $line . ': найден ключ заголовка «' . $key . '»';
                    break;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (self::SCAN_DIRS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
