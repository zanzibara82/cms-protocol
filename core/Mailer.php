<?php

declare(strict_types=1);

namespace Cms\Core;

use function applyFilters;
use function configValue;
use function filter_var;
use function logError;
use function logInfo;
use function logWarn;
use function rfc2822Stamp;

use Throwable;

final class Mailer
{
    public const MAIL_REQUIRED_KEYS = ['to', 'subject', 'text'];

    // Текст начинается со строчной: send() подставляет к нему префикс «Письмо не отправлено: »,
    // как и к ответу транспорта («сервер ответил 550»).
    private const MAIL_ERROR_NO_SERVER = 'почтовый сервер не настроен: заполните SMTP_HOST в config.php.';

    // Транспорт письма передаётся замыканием. PHP не разрешает `callable` в качестве типа свойства
    // класса — на это падает уже разбор файла, поэтому поле объявлено без типа, а договорённость
    // держится в PHPDoc: конструктор по-прежнему принимает `?callable`.
    /** @var callable|null */
    private $deliver;

    public function __construct(?callable $deliver = null)
    {
        $this->deliver = $deliver;
    }

    /**
     * @param array<string, mixed> $message
     * @return array{ok: bool, error: string}
     */
    public function send(array $message): array
    {
        $filtered = applyFilters('before_mail_send', $message);

        if (!is_array($filtered)) {
            logWarn('mail.filter_broken');

            return ['ok' => false, 'error' => 'Фильтр before_mail_send обязан вернуть массив полей письма.'];
        }

        /** @var array<string, mixed> $message */
        $message = $filtered;
        $problem = $this->problem($message);

        if ($problem !== null) {
            logWarn('mail.rejected', ['reason' => $problem]);

            return ['ok' => false, 'error' => $problem];
        }

        $raw = $this->render($message);
        $transport = $this->deliver ?? self::smtpTransport();

        try {
            $result = $transport($this->headersOf($message), $raw);
        } catch (Throwable $error) {
            logError('mail.failed', ['reason' => $error->getMessage()]);

            return ['ok' => false, 'error' => 'Письмо не отправлено: ' . $error->getMessage()];
        }

        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $reason = is_array($result) ? (string) ($result['error'] ?? '') : 'транспорт вернул неожиданный ответ';
            logError('mail.failed', ['reason' => $reason]);

            return ['ok' => false, 'error' => 'Письмо не отправлено: ' . $reason];
        }

        logInfo('mail.sent', ['to' => self::addressOf((string) $message['to'])]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $message
     */
    public function problem(array $message): ?string
    {
        $to = trim((string) ($message['to'] ?? ''));

        if ($to === '') {
            return 'Не указан адрес получателя.';
        }

        // Перевод строки отбивает заголовок и привносит в письмо лишний, а остальные управляющие
        // символы (NUL, ESC) портят экранирование той же строки. Тексты про перевод строки
        // дословные: ими покрыт отказ из критериев приёмки.
        foreach (['to' => 'получателя', 'subject' => null, 'replyTo' => 'для ответа', 'from' => 'отправителя'] as $key => $label) {
            $value = (string) ($message[$key] ?? '');
            $what = null;

            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                $what = 'перевод строки';
            } elseif (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                $what = 'управляющий символ';
            }

            if ($what === null) {
                continue;
            }

            return $label === null
                ? 'В теме письма есть ' . $what . '.'
                : sprintf('В адресе %s есть %s.', $label, $what);
        }

        if (trim((string) ($message['subject'] ?? '')) === '') {
            return 'Не указана тема письма.';
        }

        if (trim((string) ($message['text'] ?? '')) === '') {
            return 'Пустое письмо не отправляется.';
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return 'Адрес получателя не похож на почту.';
        }

        $replyTo = trim((string) ($message['replyTo'] ?? ''));

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            return 'Адрес для ответа не похож на почту.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function render(array $message): string
    {
        $headers = $this->headersOf($message);

        // render() публичный и может быть вызван мимо send(), где проверка стоит раньше:
        // адресные поля зачищаются тем же способом, что и тема, чтобы перевод строки в поле
        // не превратился в лишний заголовок готового письма.
        foreach (['from', 'to', 'replyTo'] as $key) {
            $headers[$key] = self::headerText($headers[$key]);
        }

        $lines = [
            'Date: ' . rfc2822Stamp(nowUtc()),
            'From: <' . $headers['from'] . '>',
            'To: <' . $headers['to'] . '>',
            'Subject: ' . self::encoded($headers['subject']),
            'Message-ID: <' . newId() . '@' . self::domainOf($headers['from']) . '>',
            'MIME-Version: 1.0',
        ];

        if ($headers['replyTo'] !== '') {
            $lines[] = 'Reply-To: <' . $headers['replyTo'] . '>';
        }

        $text = self::crlf($headers['text']);
        $html = $headers['html'];

        if ($html === null) {
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $body = rtrim(chunk_split(base64_encode($text) ?: '', 76, "\r\n"), "\r\n");
        } else {
            // Граница обязана быть шестнадцатеричной: тест разрезает письмо шаблоном
            // "/\r\n--cms-[0-9a-f]+\r\n/", а newId() печатает символы тридцатидвоичного алфавита
            // из core/id.php — там есть буквы g, h и k, которые в класс [0-9a-f] не входят, и разрез
            // молча не находил бы части письма. Границе нужна только уникальность в пределах одного
            // письма, поэтому здесь случайные байты, а не идентификатор записи.
            $boundary = 'cms-' . bin2hex(random_bytes(12));
            $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . rtrim(chunk_split(base64_encode($text) ?: '', 76, "\r\n"), "\r\n") . "\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . rtrim(chunk_split(base64_encode(self::crlf((string) $html)) ?: '', 76, "\r\n"), "\r\n") . "\r\n"
                . '--' . $boundary . '--';
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    public static function smtpTransport(): callable
    {
        return static function (array $headers, string $raw): array {
            $host = (string) configValue('SMTP_HOST');

            if ($host === '') {
                return ['ok' => false, 'error' => self::MAIL_ERROR_NO_SERVER];
            }

            $socket = new SmtpSocket(
                $host,
                (int) configValue('SMTP_PORT'),
                (string) configValue('SMTP_USER'),
                (string) configValue('SMTP_PASS')
            );

            try {
                $socket->open();
                $socket->deliver($raw);
            } catch (Throwable $error) {
                return ['ok' => false, 'error' => $error->getMessage()];
            } finally {
                $socket->close();
            }

            return ['ok' => true, 'error' => ''];
        };
    }

    public static function addressOf(string $header): string
    {
        $value = preg_match('/<([^>]+)>/', $header, $match) === 1 ? $match[1] : $header;

        // Конверт письма — одно значение: всё после первого пробела адресом не считается.
        // Заголовок чистит `headerText()`, но `render()` публичный и может собрать строку с
        // пробелом внутри, а `deliver()` берёт адрес уже из него.
        return preg_match('/^\s*([^\s]*)/', $value, $first) === 1 ? $first[1] : trim($value);
    }

    /**
     * @param array<string, mixed> $message
     * @return array{from: string, to: string, subject: string, text: string, html: string|null, replyTo: string}
     */
    private function headersOf(array $message): array
    {
        $from = trim((string) ($message['from'] ?? ''));

        return [
            'from' => $from !== '' ? $from : (string) configValue('MAIL_FROM'),
            'to' => trim((string) ($message['to'] ?? '')),
            'subject' => trim((string) ($message['subject'] ?? '')),
            'text' => (string) ($message['text'] ?? ''),
            'html' => isset($message['html']) && trim((string) $message['html']) !== '' ? (string) $message['html'] : null,
            'replyTo' => trim((string) ($message['replyTo'] ?? '')),
        ];
    }

    private static function headerText(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }

    private static function encoded(string $value): string
    {
        $clean = self::headerText($value);

        if (preg_match('/[^\x20-\x7e]/', $clean) !== 1) {
            return $clean;
        }

        return '=?UTF-8?B?' . base64_encode($clean) . '?=';
    }

    private static function domainOf(string $address): string
    {
        $clean = self::addressOf($address);
        $at = strrpos($clean, '@');

        return $at === false ? 'localhost' : substr($clean, $at + 1);
    }

    private static function crlf(string $text): string
    {
        return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text));
    }
}
