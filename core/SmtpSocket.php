<?php

declare(strict_types=1);

namespace Cms\Core;

use function configValue;
use function logWarn;
use function parse_url;

use RuntimeException;

final class SmtpSocket
{
    /** Порт, где канал шифруется с самого начала: соединение сразу идёт через `ssl://`. */
    private const IMPLICIT_TLS_PORT = 465;

    /** Порт отправки. По профилю службы отправки письмо без шифрования на нём не отправляется. */
    private const SUBMISSION_PORT = 587;

    /**
     * Версии TLS, которые считаются защищёнными: 1.0 и 1.1 из маски исключены, иначе сервер
     * мог бы согласовать рукопожатие, не годящееся ни для логина, ни для письма.
     */
    private const TLS_MASK = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

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
        $scheme = $this->port === self::IMPLICIT_TLS_PORT ? 'ssl://' : 'tcp://';
        // Контекст создаётся до соединения: и `ssl://` на 465, и переход по STARTTLS берут
        // правила проверки из опций потока, а STARTTLS отдельного контекста не принимает.
        $reason = '';
        $stream = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $code,
            $reason,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $this->sslOptions()])
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
        $encrypted = $this->port === self::IMPLICIT_TLS_PORT;

        if (!$encrypted && $this->advertises($capabilities, 'STARTTLS')) {
            $this->command('STARTTLS', 220);
            $enabled = @stream_socket_enable_crypto($this->stream, true, self::TLS_MASK);

            if ($enabled !== true) {
                throw new RuntimeException(
                    'почтовый сервер не согласился на шифрование (STARTTLS): рукопожатие TLS не удалось или сертификат не проверен.'
                );
            }

            $encrypted = true;
            $this->command('EHLO ' . $this->clientName(), 250);
        }

        if (!$encrypted && $this->port === self::SUBMISSION_PORT) {
            throw new RuntimeException(sprintf(
                'почтовый сервер на порту %d не поддерживает STARTTLS: письмо без шифрования не отправляется.',
                self::SUBMISSION_PORT
            ));
        }

        if (!$encrypted && $this->user !== '') {
            throw new RuntimeException(
                'почтовый сервер не шифрует соединение, а SMTP_USER заполнен: логин и пароль ушли бы открытым текстом.'
            );
        }

        if (!$encrypted) {
            // Курьерское реле на 25 и dev-приёмник на 1025 работают без шифрования: письмо уходит,
            // но открытый канал обязан остаться в журнале, а не проходить незаметно.
            logWarn('mail.plaintext', ['host' => $this->host, 'port' => $this->port]);
        }

        if ($this->user !== '') {
            $this->command('AUTH LOGIN', 334, true);
            $this->command(base64_encode($this->user) ?: '', 334, true);
            $this->command(base64_encode($this->pass) ?: '', 235, true);
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

    /**
     * Правила проверки канала. Включены явным потоковым контекстом: у `ssl://` на 465 контекст
     * принимается сразу, а переход по STARTTLS контекста не принимает и берёт те же опции из
     * уже созданного потока. `peer_name` задаёт имя, которому обязан соответствовать сертификат,
     * иначе проверка имени сравнивала бы сертификат с адресом, на который смотрит сокет.
     *
     * @return array<string, mixed>
     */
    private function sslOptions(): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
            'SNI_server_name' => $this->host,
        ];
    }

    /**
     * Возможность ищется по началу строки ответа, а не подстрокой: сервер мог упомянуть чужое
     * слово в собственном баннере или в названии, и по простому вхождению STARTTLS посчитался бы
     * объявленным. По RFC 5321 возможности EHLO идут строками «250-ТЕСТ» и завершаются «250 …».
     */
    private function advertises(string $capabilities, string $name): bool
    {
        return preg_match('/^250[- ]' . preg_quote($name, '/') . '(\s|$)/mi', $capabilities) === 1;
    }

    private function command(string $line, int $expected, bool $hideServerLine = false): string
    {
        $this->write($line);

        return $this->expect($expected, $hideServerLine);
    }

    private function write(string $line): void
    {
        if ($this->stream === null) {
            throw new RuntimeException('соединение с почтовым сервером не открыто.');
        }

        $payload = $line . "\r\n";
        $written = @fwrite($this->stream, $payload);

        if ($written === false) {
            throw new RuntimeException('почтовый сервер разорвал соединение.');
        }

        // Сокет блокирующий, поэтому неполная запись на нём — редкость, но молчаливо отправленное
        // «недописанное» письмо считается целым запросом на стороне сервера: сверяем длину.
        if ($written !== strlen($payload)) {
            throw new RuntimeException('почтовый сервер принял не весь запрос.');
        }
    }

    /**
     * Дочитывает ответ сервера до последней строки и возвращает весь ответ целиком: возможности
     * EHLO разбросаны по строкам с дефисом, и по одной последней строке их не увидеть.
     *
     * @param bool $hideServerLine для шага аутентификации: строка ответа сервера в отказ не
     *                             попадает, остаётся только код. Сервер может поставить в неё
     *                             введённые нами данные, а они уезжают и в журнал.
     */
    private function expect(int $expected, bool $hideServerLine = false): string
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
            throw new RuntimeException($hideServerLine
                ? sprintf('сервер ответил %d.', (int) substr($last, 0, 3))
                : sprintf('сервер ответил «%s» вместо кода %d.', $last, $expected));
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
