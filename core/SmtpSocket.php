<?php

declare(strict_types=1);

namespace Cms\Core;

use function configValue;
use function parse_url;

use RuntimeException;

final class SmtpSocket
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly float $timeout = 15.0
    ) {
    }

    public function open(): void
    {
        $scheme = $this->port === 465 ? 'ssl://' : 'tcp://';
        $reason = '';
        $stream = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $code,
            $reason,
            $this->timeout
        );

        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'не удалось подключиться к %s:%d — %s',
                $this->host,
                $this->port,
                $reason !== '' ? $reason : 'код ' . $code
            ));
        }

        stream_set_timeout($stream, (int) $this->timeout);
        $this->stream = $stream;

        $this->expect(220);
        // Возможности сервера читаются из ответа EHLO: по RFC 5321 команда STARTTLS допустима
        // только если сервер её объявил. Локальная заглушка mailpit на 1025 работает без
        // сертификата и отвечает на STARTTLS «502 команда не реализована», а почтовый сервер
        // хостинга на 587 её объявляет — там письмо шифруется обязательно.
        $capabilities = $this->command('EHLO ' . $this->clientName(), 250);
        $encrypted = $this->port === 465;

        if (!$encrypted && stripos($capabilities, 'STARTTLS') !== false) {
            $this->command('STARTTLS', 220);
            $enabled = @stream_socket_enable_crypto(
                $this->stream,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );

            if ($enabled !== true) {
                throw new RuntimeException('почтовый сервер не согласился на шифрование (STARTTLS).');
            }

            $encrypted = true;
            $this->command('EHLO ' . $this->clientName(), 250);
        }

        if (!$encrypted && $this->user !== '') {
            throw new RuntimeException(
                'почтовый сервер не шифрует соединение, а SMTP_USER заполнен: логин и пароль ушли бы открытым текстом.'
            );
        }

        if ($this->user !== '') {
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->user) ?: '', 334);
            $this->command(base64_encode($this->pass) ?: '', 235);
        }
    }

    public function deliver(string $raw): void
    {
        $from = Mailer::addressOf((string) ($this->headerOf($raw, 'From')));
        $to = Mailer::addressOf((string) ($this->headerOf($raw, 'To')));

        $this->command('MAIL FROM:<' . $from . '>', 250);
        $this->command('RCPT TO:<' . $to . '>', 250);
        $this->command('DATA', 354);
        $this->write($this->dotStuff($raw) . "\r\n.");
        $this->expect(250);
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        try {
            $this->command('QUIT', 221);
        } catch (RuntimeException) {
            // Сервер вправе закрыть соединение сразу — это не ошибка.
        }

        fclose($this->stream);
        $this->stream = null;
    }

    private function command(string $line, int $expected): string
    {
        $this->write($line);

        return $this->expect($expected);
    }

    private function write(string $line): void
    {
        if ($this->stream === null) {
            throw new RuntimeException('соединение с почтовым сервером не открыто.');
        }

        $written = @fwrite($this->stream, $line . "\r\n");

        if ($written === false) {
            throw new RuntimeException('почтовый сервер разорвал соединение.');
        }
    }

    /**
     * Дочитывает ответ сервера до последней строки и возвращает весь ответ целиком: возможности
     * EHLO разбросаны по строкам с дефисом, и по одной последней строке их не увидеть.
     */
    private function expect(int $expected): string
    {
        if ($this->stream === null) {
            throw new RuntimeException('соединение с почтовым сервером не открыто.');
        }

        $last = '';
        $transcript = '';

        do {
            $line = fgets($this->stream);

            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);

                throw new RuntimeException(
                    $meta['timed_out'] === true
                        ? sprintf('почтовый сервер не ответил за %d секунд.', (int) $this->timeout)
                        : 'почтовый сервер закрыл соединение.'
                );
            }

            $last = rtrim($line, "\r\n");
            $transcript .= $last . "\n";
        } while (isset($last[3]) && $last[3] === '-');

        if ((int) substr($last, 0, 3) !== $expected) {
            throw new RuntimeException(sprintf('сервер ответил «%s» вместо кода %d.', $last, $expected));
        }

        return $transcript;
    }

    private function headerOf(string $raw, string $name): ?string
    {
        $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n"));

        foreach (explode("\r\n", $head) as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    /** Точка в начале строки удваивается по RFC 5321 §4.5.2. */
    private function dotStuff(string $raw): string
    {
        return (string) preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)));
    }

    private function clientName(): string
    {
        $parts = parse_url((string) configValue('SITE_URL'));
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        return $host !== '' ? $host : 'localhost';
    }
}
