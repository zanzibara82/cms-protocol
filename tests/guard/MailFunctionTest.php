<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Правило «письма только через Cms\Core\Mailer» живёт, пока никто не может позвонить
// встроенной mail() в обход сборщика: иначе письмо ушло бы без темы, Journal-записи и
// проверки адреса. Список ловит равноправные способы вызова, а не один удобный:
// прямой вызов, вызов с ведущим обратным слэшем и с «@», функциональные обёртки
// call_user_func/call_user_func_array, mb_send_mail() и чужие почтовые классы.
final class MailFunctionTest extends TestCase
{
    /**
     * Формы, которые ищутся по коду с выключенными комментариями и строковыми литералами:
     * «mail(» внутри текста (тема письма, подпись админки) вызовом не является.
     */
    private const FORBIDDEN_CALLS = [
        '/(?<![a-zA-Z0-9_>:])mail\s*\(/' => 'mail() — письма отправляются только через Cms\Core\Mailer',
        '/\bmb_send_mail\s*\(/' => 'mb_send_mail() — письма отправляются только через Cms\Core\Mailer',
    ];

    /**
     * Формы, которые ищутся по коду ДО снятия литералов: у динамического вызова имя функции
     * лежит именно в строковом литерале («call_user_func(\'mail\', …)»), и второй проход его
     * уже не видит. Имена чужих библиотек проверяются здесь же, одним списком.
     */
    private const FORBIDDEN_NAMES = [
        '/\bphpmailer\b/i' => 'PHPMailer — рантайм-зависимостей у ядра нет',
        '/\bsymfony\\\\(?:\w+\\\\)*mailer/i' => 'Symfony Mailer — рантайм-зависимостей у ядра нет',
        '/\bcall_user_func(?:_array)?\s*\(\s*([\'"])\\\\?mail\1\s*,/' =>
            'call_user_func с именем «mail» — письма отправляются только через Cms\Core\Mailer',
    ];

    public function testПисьмаОтправляютсяТолькоЧерезСборщикЯдра(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $code = (string) file_get_contents($file);

            foreach ($this->mailViolations($code, $this->relative($file)) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame([], $violations, 'Найдены места, где письмо отправляется в обход Cms\Core\Mailer');
    }

    // Проверка силы самого стража: каждая равноправная форма обязана давать находку. Без этого
    // теста список регулярок мог бы разойтись с реальностью молча.
    public function testСтражЛовитВсеСпособыВызова(): void
    {
        $samples = [
            '<?php mail($to, $subject, $body);',
            '<?php \mail($to, $subject, $body);',
            '<?php @mail($to, $subject, $body);',
            '<?php mb_send_mail($subject, $body);',
            "<?php call_user_func('mail', \$to, \$subject);",
            '<?php call_user_func("mail", $to, $subject);',
            "<?php call_user_func_array('mail', \$args);",
            '<?php $mailer = new \\PHPMailer\\PHPMailer\\PHPMailer(true);',
            '<?php use PHPMailer\PHPMailer\PHPMailer;',
            '<?php use Symfony\Component\Mailer\MailerInterface;',
        ];

        foreach ($samples as $sample) {
            self::assertNotSame([], $this->mailViolations($sample, 'probe.php'), 'Страж пропустил способ отправить письмо: ' . $sample);
        }
    }

    public function testСтражНеЛовитСовпаденияНеВызовы(): void
    {
        $samples = [
            // Метод объекта, а не встроенная функция: исключение намеренное, иначе собиралось бы
            // любое имя вида $obj->mail(). В проекте такого вызова нет, и править его должен
            // не этот страж, а тот, кто его заведёт.
            '<?php $obj->mail($to, $subject);',
            // Класс ядра: ведущее двоеточие уже входит в запретительное окружение регулярки.
            '<?php $transport = Mailer::smtpTransport();',
            // Комментарии снимаются лексером до поиска.
            "<?php\n// переписка: e-mail (support@example.com)\n\$x = 1;",
            // Текст внутри строки — не вызов: именно этот случай терял прежний разбор.
            "<?php \$subject = 'e-mail (важно)';",
            "<?php echo 'Напишите на e-mail (';\n\$x = 1;",
        ];

        foreach ($samples as $sample) {
            self::assertSame([], $this->mailViolations($sample, 'probe.php'), 'Страж нашёл не вызов письма: ' . $sample);
        }
    }

    /**
     * Находки в одном исходнике: два прохода по разному виду кода, комментарии и литералы
     * снимаются здесь же, чтобы тест силы мог передать голый фрагмент.
     *
     * @return list<string>
     */
    private function mailViolations(string $code, string $relative): array
    {
        $body = $this->withoutComments($code);
        $calls = $this->withoutStrings($body);
        $out = [];

        foreach (self::FORBIDDEN_CALLS as $pattern => $reason) {
            if (preg_match($pattern, $calls) === 1) {
                $out[] = $relative . ': ' . $reason;
            }
        }

        foreach (self::FORBIDDEN_NAMES as $pattern => $reason) {
            if (preg_match($pattern, $body) === 1) {
                $out[] = $relative . ': ' . $reason;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        // `scripts` в списке не случайно: phpstan.neon подключает его как зону разрушительных
        // хостеровых скриптов, и почтовый вызов из них был бы виден только там.
        $roots = ['core', 'admin', 'theme', 'apps', 'public', 'scripts'];
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

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
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

    /**
     * Строковые литералы заменяются пробелом (не пустотой): склеить соседние токены означало бы
     * придумать вызов, которого в коде нет. heredoc и nowdoc покрываются T_ENCAPSED_AND_WHITESPACE
     * вместе с ограничительными строками.
     */
    private function withoutStrings(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array(
                $token[0],
                [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC],
                true
            )) {
                $out .= ' ';
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
