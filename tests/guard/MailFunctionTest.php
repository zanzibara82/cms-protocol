<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

final class MailFunctionTest extends TestCase
{
    private const FORBIDDEN = [
        '/(?<![a-zA-Z0-9_>:])mail\s*\(/' => 'mail() — письма отправляются только через Cms\Core\Mailer',
        '/\bmb_send_mail\s*\(/' => 'mb_send_mail() — письма отправляются только через Cms\Core\Mailer',
        '/\bphpmailer\b/i' => 'PHPMailer — рантайм-зависимостей у ядра нет',
        '/\bsymfony\\\\mailer\b/i' => 'Symfony Mailer — рантайм-зависимостей у ядра нет',
    ];

    public function testПисьмаОтправляютсяТолькоЧерезСборщикЯдра(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));

            foreach (self::FORBIDDEN as $pattern => $message) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $file . ' — ' . $message;
                }
            }
        }

        self::assertSame([], $violations);
    }

    /** @return array<int, string> */
    private function phpFiles(): array
    {
        $roots = ['core', 'admin', 'theme', 'apps', 'public'];
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach ($roots as $dir) {
            $base = $root . '/' . $dir;

            if (!is_dir($base)) {
                continue;
            }

            // glob($base.'/**/*.php') не годится: `**` в glob() не рекурсивен, он равен одному
            // уровню каталога, — файлы прямо в корне и вложенные на два уровня молча
            // выпадали бы из проверки. Обход реальный, как в остальных стражах.
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** Комментарии снимаются лексером, а не регуляркой: в `core/SmtpSocket.php` есть
     *  строки `'tcp://'` и `'ssl://'`, и наивная замена `//…` съела бы часть кода. */
    private function withoutComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
