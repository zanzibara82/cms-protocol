### Задача 10: SMTP-клиент и письма

**Файлы:**
- Создать: `core/Mailer.php`
- Создать: `core/SmtpSocket.php`
- Изменить: `core/date.php` (добавить `rfc2822Stamp()`)
- Тест: `tests/unit/MailerTest.php`
- Тест: `tests/guard/MailFunctionTest.php`
- Тест: `tests/http/SmtpIntegrationTest.php`

**Интерфейсы:**
- Потребляет: `applyFilters()` и `hooksReset()` из `core/hooks.php`; `configValue()` из `core/config-loader.php`; `newId()` из `core/id.php`; `logInfo()`, `logWarn()`, `logError()` из `core/log.php`.
- Производит: `Cms\Core\Mailer` — `send(array $message): array{ok: bool, error: string}`, где ключи письма: обязательные `to`, `subject`, `text`, необязательные `html`, `replyTo`, `from`; `Cms\Core\Mailer::smtpTransport(): callable`; `Cms\Core\SmtpSocket` — `open()`, `deliver(string $raw)`, `close()`; `rfc2822Stamp(DateTimeImmutable $date): string`.

**Зачем интеграционная проверка лежит в `tests/http/`:** ей нужна сеть контейнера (mailpit), а не база и не `php -S`. Набор `http` уже запускается в контейнере, поэтому тест живёт там; при отсутствии `CMS_MAILPIT_URL` он пропускается, чтобы прогоны на хостинге и в CI оставались зелёными.

**Почему штамп даты добавляется в `core/date.php`:** страж `tests/guard/DateUsageTest.php` запрещает `date(`, `->format(` и `time()` вне этого модуля, поэтому форматирование RFC 2822 делается здесь же.

- [ ] **Шаг 1: Написать падающий тест письма**

Создать `tests/unit/MailerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use Cms\Core\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->sent = [];
        hooksReset();
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'to' => 'uchenik@example.com',
            'subject' => 'Ответ на заявку',
            'text' => 'Здравствуйте! Мы получили вашу заявку.',
        ];
    }

    private function mailer(): Mailer
    {
        return new Mailer(function (array $headers, string $raw): array {
            $this->sent[] = ['headers' => $headers, 'raw' => $raw];

            return ['ok' => true, 'error' => ''];
        });
    }

    public function testПисьмоДоходитДоТранспорта(): void
    {
        $result = $this->mailer()->send($this->message());

        self::assertTrue($result['ok']);
        self::assertSame('', $result['error']);
        self::assertCount(1, $this->sent);
        self::assertSame(configValue('MAIL_FROM'), $this->sent[0]['headers']['from']);
        self::assertSame('uchenik@example.com', $this->sent[0]['headers']['to']);
    }

    public function testЗаголовкиСобираютсяПоСтандарту(): void
    {
        $this->mailer()->send($this->message());
        $raw = (string) $this->sent[0]['raw'];

        self::assertStringContainsString("Date: ", $raw);
        self::assertStringContainsString(" +0000\r\n", $raw);
        self::assertStringContainsString('From: <' . configValue('MAIL_FROM') . ">\r\n", $raw);
        self::assertStringContainsString("To: <uchenik@example.com>\r\n", $raw);
        self::assertStringContainsString('Subject: =?UTF-8?B?', $raw);
        self::assertStringContainsString("Message-ID: <", $raw);
        self::assertStringContainsString("MIME-Version: 1.0\r\n", $raw);
        self::assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\n", $raw);
        self::assertStringContainsString("Content-Transfer-Encoding: base64\r\n", $raw);
        self::assertStringNotContainsString('Reply-To:', $raw);
    }

    public function testТекстКодируетсяВОснование64(): void
    {
        $this->mailer()->send($this->message());
        $raw = (string) $this->sent[0]['raw'];
        $body = substr($raw, (int) strpos($raw, "\r\n\r\n") + 4);

        self::assertSame('Здравствуйте! Мы получили вашу заявку.', base64_decode(trim($body), true) ?: '');
    }

    public function testПисьмоСРазметкойСобираетсяИзДвухЧастей(): void
    {
        $message = $this->message();
        $message['html'] = '<p>Здравствуйте!</p>';

        $this->mailer()->send($message);
        $raw = (string) $this->sent[0]['raw'];

        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="cms-', $raw);
        self::assertSame(2, substr_count($raw, 'Content-Transfer-Encoding: base64'));
        self::assertStringContainsString('<p>Здравствуйте!</p>', base64_decode($this->partOf($raw, 1), true) ?: '');
    }

    public function testПереводыСтрокВТекстеПриводятсяКСтандарту(): void
    {
        $message = $this->message();
        $message['text'] = "Первая строка\nВторая строка";

        $this->mailer()->send($message);
        $decoded = base64_decode($this->bodyOf((string) $this->sent[0]['raw']), true) ?: '';

        self::assertSame("Первая строка\r\nВторая строка", $decoded);
    }

    public function testФильтрМеняетТему(): void
    {
        addFilter('before_mail_send', static function (array $message): array {
            $message['subject'] = 'Тема из фильтра';

            return $message;
        });

        $this->mailer()->send($this->message());

        self::assertSame('Тема из фильтра', $this->sent[0]['headers']['subject']);
    }

    public function testФильтрВернувшийНеМассивОтменяетПисьмо(): void
    {
        addFilter('before_mail_send', static fn (): string => 'сломано');

        $result = $this->mailer()->send($this->message());

        self::assertFalse($result['ok']);
        self::assertSame('Фильтр before_mail_send обязан вернуть массив полей письма.', $result['error']);
        self::assertCount(0, $this->sent);
    }

    public function testФильтрОпустошившийТемуОтменяетПисьмо(): void
    {
        addFilter('before_mail_send', static function (array $message): array {
            $message['subject'] = '   ';

            return $message;
        });

        $result = $this->mailer()->send($this->message());

        self::assertFalse($result['ok']);
        self::assertSame('Не указана тема письма.', $result['error']);
    }

    public function testОтказыОтОтправки(): void
    {
        $cases = [
            'без получателя' => [['to' => '', 'subject' => 'Тема', 'text' => 'Текст'], 'Не указан адрес получателя.'],
            'без темы' => [['to' => 'a@b.ru', 'subject' => '', 'text' => 'Текст'], 'Не указана тема письма.'],
            'пустой текст' => [['to' => 'a@b.ru', 'subject' => 'Тема', 'text' => '  '], 'Пустое письмо не отправляется.'],
            'не почта' => [['to' => 'не почта', 'subject' => 'Тема', 'text' => 'Текст'], 'Адрес получателя не похож на почту.'],
            'внедрение заголовка' => [
                ['to' => "a@b.ru\r\nBcc: vsem@b.ru", 'subject' => 'Тема', 'text' => 'Текст'],
                'В адресе получателя есть перевод строки.',
            ],
            'перевод строки в теме' => [
                ['to' => 'a@b.ru', 'subject' => "Тема\r\nBcc: vsem@b.ru", 'text' => 'Текст'],
                'В теме письма есть перевод строки.',
            ],
            'перевод строки в адресе для ответа' => [
                ['to' => 'a@b.ru', 'subject' => 'Тема', 'text' => 'Текст', 'replyTo' => "x@y.ru\n"],
                'В адресе для ответа есть перевод строки.',
            ],
        ];

        foreach ($cases as $label => [$message, $expected]) {
            $this->sent = [];
            $result = $this->mailer()->send($message);

            self::assertFalse($result['ok'], $label);
            self::assertSame($expected, $result['error'], $label);
            self::assertCount(0, $this->sent, $label);
        }
    }

    public function testСбойТранспортаВозвращаетПонятныйОтказ(): void
    {
        $mailer = new Mailer(static fn (array $headers, string $raw): array => ['ok' => false, 'error' => 'сервер ответил 550']);

        $result = $mailer->send($this->message());

        self::assertFalse($result['ok']);
        self::assertSame('Письмо не отправлено: сервер ответил 550', $result['error']);
    }

    public function testИсключениеТранспортаНеУтекаетНаружу(): void
    {
        $mailer = new Mailer(static function (array $headers, string $raw): array {
            throw new \RuntimeException('почтовый сервер закрыл соединение.');
        });

        $result = $mailer->send($this->message());

        self::assertFalse($result['ok']);
        self::assertSame('Письмо не отправлено: почтовый сервер закрыл соединение.', $result['error']);
    }

    public function testБезНастроенногоСервераПисьмоНеОтправляется(): void
    {
        if ((string) configValue('SMTP_HOST') !== '') {
            self::markTestSkipped('SMTP_HOST заполнен — отказ не проверить.');
        }

        $result = (new Mailer(Mailer::smtpTransport()))->send($this->message());

        self::assertFalse($result['ok']);
        // send() оборачивает отказ транспорта тем же префиксом, что и ответ «сервер ответил 550».
        self::assertSame('Письмо не отправлено: почтовый сервер не настроен: заполните SMTP_HOST в config.php.', $result['error']);
    }

    public function testСборкаПисьмаНеМеняетПереданныйМассив(): void
    {
        $message = $this->message();
        $message['html'] = '<p>Разметка</p>';
        $copy = $message;

        $this->mailer()->render($message);

        self::assertSame($copy, $message);
    }

    private function bodyOf(string $raw): string
    {
        return trim(substr($raw, (int) strpos($raw, "\r\n\r\n") + 4));
    }

    private function partOf(string $raw, int $index): string
    {
        $parts = preg_split("/\r\n--cms-[0-9a-f]+\r\n/", $raw) ?: [];
        $chunk = $parts[$index + 1] ?? '';

        return trim(substr($chunk, (int) strpos($chunk, "\r\n\r\n") + 4));
    }
}
```

- [ ] **Шаг 2: Запустить тест и убедиться, что он падает**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter MailerTest`
Expected: FAIL — `Class "Cms\Core\Mailer" not found`.

- [ ] **Шаг 3: Добавить штамп даты в `core/date.php`**

В `core/date.php` перед функцией `parseFormDateTime()` добавить:

```php
function rfc2822Stamp(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' +0000';
}
```

- [ ] **Шаг 4: Написать сокет SMTP**

Создать `core/SmtpSocket.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Core;

use RuntimeException;

use function configValue;
use function parse_url;

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
        $this->command('EHLO ' . $this->clientName(), 250);

        if ($this->port !== 465) {
            $this->command('STARTTLS', 220);
            $enabled = @stream_socket_enable_crypto(
                $this->stream,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );

            if ($enabled !== true) {
                throw new RuntimeException('почтовый сервер не согласился на шифрование (STARTTLS).');
            }

            $this->command('EHLO ' . $this->clientName(), 250);
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

    private function command(string $line, int $expected): void
    {
        $this->write($line);
        $this->expect($expected);
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

    private function expect(int $expected): string
    {
        if ($this->stream === null) {
            throw new RuntimeException('соединение с почтовым сервером не открыто.');
        }

        $last = '';

        do {
            $line = fgets($this->stream);

            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);

                throw new RuntimeException(
                    ($meta['timed_out'] ?? false) === true
                        ? sprintf('почтовый сервер не ответил за %d секунд.', (int) $this->timeout)
                        : 'почтовый сервер закрыл соединение.'
                );
            }

            $last = rtrim($line, "\r\n");
        } while (isset($last[3]) && $last[3] === '-');

        if ((int) substr($last, 0, 3) !== $expected) {
            throw new RuntimeException(sprintf('сервер ответил «%s» вместо кода %d.', $last, $expected));
        }

        return $last;
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
```

- [ ] **Шаг 5: Написать сборщик писем**

Уточнение контроллера к шагу 4 (внесено по рецензии задачи 10, обязательного кода в блоке выше
оно не отменяет):

- незашифрованный канал на порту 587 — отказ отправки, а не тихая пересылка открытым текстом:
  `config.sample.php:24` задаёт именно порт отправки, где `STARTTLS` обязателен для клиента;
  на прочих портах (курьерское реле на 25, dev-приёмник на 1025) отправка допускается, но обязана
  оставить в журнале `logWarn('mail.plaintext')`;
- признак «канал зашифрован» обязан быть проверяемым: на потоке задаётся явный контекст `ssl`
  с `verify_peer`, `verify_peer_name`, `peer_name` и `local_name` по хосту из настроек, а маска
  шифрования не допускает TLS 1.0 и 1.1. Контекст нужен и при `ssl://` на 465, и после `STARTTLS`;
- отказ при незашифрованном канале с заполненным `SMTP_USER`, обе новые ветки выше, разбор
  многострочного ответа `expect()` и `dotStuff()` покрываются юнит-тестом на подставном сервере
  `stream_socket_server('tcp://127.0.0.1:0')` — приём уже принят в проекте стражем дат, второй
  почтовый контейнер для этого не нужен.

Создать `core/Mailer.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Core;

use Throwable;

use function applyFilters;
use function configValue;
use function filter_var;
use function logError;
use function logInfo;
use function logWarn;
use function rfc2822Stamp;

final class Mailer
{
    public const MAIL_REQUIRED_KEYS = ['to', 'subject', 'text'];

    // Текст начинается со строчной: send() подставляет к нему префикс «Письмо не отправлено: »,
    // как и к ответу транспорта («сервер ответил 550»).
    private const MAIL_ERROR_NO_SERVER = 'почтовый сервер не настроен: заполните SMTP_HOST в config.php.';

    public function __construct(private readonly ?callable $deliver = null)
    {
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

        foreach (['to' => 'получателя', 'subject' => null, 'replyTo' => 'для ответа', 'from' => 'отправителя'] as $key => $label) {
            $value = (string) ($message[$key] ?? '');

            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                return $label === null
                    ? 'В теме письма есть перевод строки.'
                    : sprintf('В адресе %s есть перевод строки.', $label);
            }
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
            $boundary = 'cms-' . newId();
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
        if (preg_match('/<([^>]+)>/', $header, $match) === 1) {
            return trim($match[1]);
        }

        return trim($header);
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
```

- [ ] **Шаг 6: Запустить тест и убедиться, что он проходит**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter MailerTest`
Expected: PASS — 13 проверок.

Цифру снимать как есть, а не подгонять: `testБезНастроенногоСервераПисьмоНеОтправляется` сам
решает, можно ли проверять отказ, и в dev-контейнере он пропускается, когда `SMTP_HOST` заполнен.
Заполнен ли он в прогоне, зависит не только от `docker-compose.dev.yml`: `tests/unit/ConfigLoaderTest.php`
загружается вместе с набором `unit` и на уровне файла обнуляет `CMS_SMTP_HOST`, а `config()` кеширует
настройки на весь процесс. Поэтому пропуск в контейнере означает, что файл-обнуление больше не мешает
набору `http`, — см. шаг 10.

- [ ] **Шаг 7: Написать страж против `mail()`**

Создать `tests/guard/MailFunctionTest.php`:

```php
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
```

- [ ] **Шаг 8: Запустить стража**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter MailFunctionTest`
Expected: PASS.

- [ ] **Шаг 9: Написать интеграционную проверку на mailpit**

Создать `tests/http/SmtpIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Core\Mailer;
use PHPUnit\Framework\TestCase;

final class SmtpIntegrationTest extends TestCase
{
    private static string $mailpit = '';

    public static function setUpBeforeClass(): void
    {
        self::$mailpit = (string) (getenv('CMS_MAILPIT_URL') ?: '');

        if (self::$mailpit === '' || (string) configValue('SMTP_HOST') === '') {
            self::markTestSkipped('Нужны CMS_MAILPIT_URL и SMTP_HOST — проверка пропускается.');
        }
    }

    public function testПисьмоДоходитДоСервера(): void
    {
        $marker = 'проверка-' . newId();
        $to = 'poluchatel@example.com';

        $result = (new Mailer(Mailer::smtpTransport()))->send([
            'to' => $to,
            'subject' => $marker,
            'text' => 'Проверка отправки письма.',
        ]);

        self::assertTrue($result['ok'], $result['error']);
        self::assertNotNull($this->findMessage($to, $marker));
    }

    public function testПисьмоСРазметкойПриходитДвумяЧастями(): void
    {
        $marker = 'разметка-' . newId();
        $to = 'razmetka@example.com';

        $result = (new Mailer(Mailer::smtpTransport()))->send([
            'to' => $to,
            'subject' => $marker,
            'text' => 'Текстовая часть.',
            'html' => '<p>Разметка</p>',
        ]);

        self::assertTrue($result['ok'], $result['error']);
        $message = $this->findMessage($to, $marker);
        self::assertNotNull($message);
        self::assertStringContainsString('multipart/alternative', (string) ($message['ContentType'] ?? ''));
    }

    public function testКириллицаВТемеНеЛомается(): void
    {
        $marker = 'Тема с кириллицей ' . newId();
        $to = 'kirillica@example.com';

        $result = (new Mailer(Mailer::smtpTransport()))->send([
            'to' => $to,
            'subject' => $marker,
            'text' => 'Проверка кодировки темы.',
        ]);

        self::assertTrue($result['ok'], $result['error']);
        self::assertNotNull($this->findMessage($to, $marker));
    }

    /** @return array<string, mixed>|null */
    private function findMessage(string $to, string $marker): ?array
    {
        $url = rtrim(self::$mailpit, '/')
            . '/api/v1/search?limit=50&query=' . rawurlencode('to:' . $to);
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            self::fail('mailpit недоступен по адресу ' . self::$mailpit);
        }

        $decoded = json_decode($body, true);
        $items = is_array($decoded) ? (array) ($decoded['messages'] ?? []) : [];

        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['Subject'] ?? '') === $marker) {
                return $item;
            }
        }

        return null;
    }
}
```

- [ ] **Шаг 10: Запустить интеграционную проверку в контейнере**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter SmtpIntegrationTest`
Expected: PASS — 3 проверки.

Ветка пропуска проверяется той же командой, но с явным обнулением переменной: `docker compose run`
наследует `environment` сервиса `php` (там `CMS_MAILPIT_URL: http://mailpit:8025`), поэтому без
`-e` тест не может пропуститься — он пойдёт на живой приёмник.

Run: `docker compose -f docker-compose.dev.yml run --rm -T -e CMS_MAILPIT_URL= php vendor/bin/phpunit --testsuite http --filter SmtpIntegrationTest`
Expected: SKIPPED — 3 проверки с текстом про `CMS_MAILPIT_URL`.

Отдельно проверить, что `SMTP_HOST` в прогоне не обнулён чужим файлом: `--testsuite unit` загружает
`tests/unit/ConfigLoaderTest.php`, а он на уровне файла делает `putenv('CMS_SMTP_HOST=')`, и `config()`
запоминает настройки на весь процесс. Если набор `http` прогоняется вместе с `unit` (ворота целиком),
проверки выше могут показаться пропускающимися — цифры надо снимать изолированным прогоном, как здесь,
и полным прогоном ворот, и объяснять расхождение.

- [ ] **Шаг 11: Прогнать ворота и зафиксировать**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

Число пропусков в полном прогоне ворот обязано быть объяснимым, а не «просто пропуском»: если
интеграционные SMTP-проверки показаны пропускающимися, значит `SMTP_HOST` в процессе пуст
(см. шаг 10) и доставка письма в воротах не проверяется. Тогда чинится тестовая изоляция,
а цифры в отчёте не подгоняются.

```bash
git add core/Mailer.php core/SmtpSocket.php core/date.php tests/unit/MailerTest.php tests/guard/MailFunctionTest.php tests/http/SmtpIntegrationTest.php
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git commit -m "SMTP-клиент ядра: письмо через сокет, фильтр before_mail_send, страж против mail()"
```

---

### Задача 11: Манифест раздела, схема и начальная закладка

**Файлы:**
- Создать: `apps/news/manifest.php`
- Создать: `apps/news/lib/boot.php`
- Создать: `apps/news/lib/constants.php`
- Создать: `apps/news/seed.php`
- Создать: `apps/news/sql/20261006_0001_catalog.sql`
- Создать: `apps/news/sql/20261006_0002_items.sql`
- Изменить: `tests/bootstrap.php` (подключить `apps/news/lib/boot.php`)
- Изменить: `tests/db/AppsRegistryTest.php` (в теле метода `testПриватныеПутиСобираютсяИзВсехМанифестов`
  в списке приватных путей появился `/news/admin`; ориентир — имя метода, а не номер строк, файл короткий)
- Тест: `tests/db/NewsSchemaTest.php`
- Тест: `tests/unit/NewsConstantsTest.php`
- Тест: `tests/db/NewsSeedTest.php`
- Тест: `tests/db/NewsRegistryTest.php`
- Тест: `tests/guard/AppSqlTest.php`

**Интерфейсы:**
- Потребляет: `appManifest()`, `appState()`, `coreVersionSatisfies()`, `appPrivatePaths()`, `planApp()`, `installApp()`, `seedApp()` из задач 8–9; `run()`, `one()`, `all()`, `value()`, `insert()`, `t()`, `inPlaceholders()` из `core/db.php`; `newId()` из `core/id.php`; `utcStamp()`, `nowUtc()` из `core/date.php`; `TestDb::reset()`, `TestDb::clearTable()` из `tests/TestDb.php`.
- Производит: `NEWS_STATUS`, `NEWS_STATUS_LABEL`, `NEWS_FILTER_LABEL`, `NEWS_FILTERS`, `NEWS_PER_PAGE`, `ADMIN_NEWS_PER_PAGE`, `PINNED_WARN_LIMIT`, `SEARCH_MAX_LENGTH`, `NEWS_SLUG_MIN`, `NEWS_SLUG_MAX`, `NEWS_RESERVED_SLUGS`, `NEWS_FIELDS`, `CATEGORY_FIELDS`, `TAG_FIELDS`, `OLD_SLUGS_MAX`, `NEWS_NOTICE_TEXT`, `CATALOG_NOTICE_TEXT`; таблицы `tbl_news_category`, `tbl_news_tag`, `tbl_news_item`, `tbl_news_item_tag`, `tbl_news_old_slug`.

**Важно:** `apps/news/routes.php` здесь не создаётся — маршруты и контроллеры приходят в задаче 16, а `registerAppRoutes()` из `core/apps.php` проверяет файл через `is_file()`, поэтому раздел без маршрутов не ломает ядро.

**Почему `IF NOT EXISTS` в каждом `CREATE TABLE`:** MySQL фиксирует DDL сразу, поэтому `applyApp()` пишет строку журнала после каждого файла, а не одним оборотом (отклонение 9). Повторный прогон файла обязан проходить молча.

- [ ] **Шаг 1: Написать падающий тест схемы раздела**

Создать `tests/db/NewsSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PDOException;
use PHPUnit\Framework\TestCase;

final class NewsSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropNewsTables();

        $result = installApp('news');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Новости» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropNewsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'news'");
    }

    public function testВсеПятьТаблицРазделаСозданы(): void
    {
        self::assertSame(self::tables(), $this->existingTables());
    }

    public function testЖурналПомнитОбаРелизаВПорядкеОчереди(): void
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['name'],
            all('SELECT name FROM ' . t('app_migration') . " WHERE app = 'news' ORDER BY applied_at, name")
        );

        self::assertSame(['20261006_0001_catalog.sql', '20261006_0002_items.sql'], $names);
    }

    public function testРазделЧислитсяУстановленнымИГотовымКРаботе(): void
    {
        $installation = appInstallation('news');

        self::assertIsArray($installation);
        self::assertSame('1.0.0', (string) $installation['version']);
        self::assertSame(1, (int) $installation['enabled']);
        self::assertSame('ready', appState('news')['state']);
    }

    public function testНеприменённыхРелизовНеОсталось(): void
    {
        self::assertTrue(planApp('news')->isEmpty());
    }

    public function testАдресМатериалаУникален(): void
    {
        $category = self::makeCategory('Рубрика для адреса');

        self::makeItem($category, 'odinnakovyj-adres');

        $this->expectException(PDOException::class);
        self::makeItem($category, 'odinnakovyj-adres');
    }

    public function testНазваниеРубрикиУникально(): void
    {
        self::makeCategory('Одинаковое название');

        $this->expectException(PDOException::class);
        self::makeCategory('Одинаковое название');
    }

    public function testРубрикуМатериаловНеУдалить(): void
    {
        $category = self::makeCategory('Рубрика с материалом');
        self::makeItem($category, 'material-v-rubrike');

        $this->expectException(PDOException::class);
        run('DELETE FROM ' . t('news_category') . ' WHERE id = ?', [$category]);
    }

    public function testУдалениеМатериалаЧиститСвязкиИСтарыеАдреса(): void
    {
        $category = self::makeCategory('Рубрика для каскада');
        $item = self::makeItem($category, 'kasladnyj-material');
        $tag = newId();

        run('INSERT INTO ' . t('news_tag') . ' (id, slug, label) VALUES (?, ?, ?)', [$tag, 'vypusknik', 'Выпускник']);
        run('INSERT INTO ' . t('news_item_tag') . ' (item_id, tag_id) VALUES (?, ?)', [$item, $tag]);
        run(
            'INSERT INTO ' . t('news_old_slug') . ' (slug, item_id, created_at) VALUES (?, ?, ?)',
            ['prezhnij-adres', $item, utcStamp(nowUtc())]
        );

        run('DELETE FROM ' . t('news_item') . ' WHERE id = ?', [$item]);

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_item_tag') . ' WHERE item_id = ?', [$item]));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_old_slug') . ' WHERE item_id = ?', [$item]));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('news_tag') . ' WHERE id = ?', [$tag]));
    }

    public function testТелоМатериалаХранитсяКакJsonИЧитаетсяОбратно(): void
    {
        $item = self::makeItem(self::makeCategory('Рубрика для тела'), 'material-s-telom');
        $paragraphs = [['title' => 'Вступление', 'text' => 'Первый абзац **важное**']];

        run(
            'UPDATE ' . t('news_item') . ' SET body = ? WHERE id = ?',
            [(string) json_encode($paragraphs, JSON_UNESCAPED_UNICODE), $item]
        );

        $stored = value('SELECT body FROM ' . t('news_item') . ' WHERE id = ?', [$item]);
        $decoded = json_decode((string) $stored, true);

        // Колонка body — JSON, а MySQL нормализует его при записи и сортирует ключи внутри
        // объектов. Поэтому порядок ключей обратно не читается: сверяем значения по ключам,
        // а не массив целиком через assertSame.
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('Вступление', $decoded[0]['title']);
        self::assertSame('Первый абзац **важное**', $decoded[0]['text']);
    }

    public function testНовыйМатериалЭтоЧерновикБезСрокаИБезЗакрепления(): void
    {
        $item = self::makeItem(self::makeCategory('Рубрика для умолчаний'), 'material-s-umolchaniyami');
        $row = one('SELECT status, published_at, pinned, noindex, lead_text FROM ' . t('news_item') . ' WHERE id = ?', [$item]);

        self::assertIsArray($row);
        self::assertSame(NEWS_STATUS['DRAFT'], (string) $row['status']);
        self::assertNull($row['published_at']);
        self::assertSame(0, (int) $row['pinned']);
        self::assertSame(0, (int) $row['noindex']);
        self::assertNull($row['lead_text']);
    }

    public function testТаблицыРазделаНаИннодбИВЮникоде(): void
    {
        $tables = self::tables();
        $rows = all(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (' . inPlaceholders(count($tables)) . ')',
            array_merge([(string) configValue('DB_NAME')], $tables)
        );

        self::assertCount(count($tables), $rows);

        foreach ($rows as $row) {
            self::assertSame('InnoDB', (string) $row['ENGINE']);
            self::assertSame('utf8mb4_unicode_ci', (string) $row['TABLE_COLLATION']);
        }
    }

    public function testИндексыЛентыНаМесте(): void
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['INDEX_NAME'],
            all(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [(string) configValue('DB_NAME'), t('news_item')]
            )
        );

        self::assertContains('news_item_status_published', $names);
        self::assertContains('news_item_category_status', $names);
        self::assertContains('news_item_pinned', $names);
        self::assertContains('news_item_slug', $names);
    }

    /** @return list<string> */
    private static function tables(): array
    {
        return [t('news_category'), t('news_item'), t('news_item_tag'), t('news_old_slug'), t('news_tag')];
    }

    /** @return list<string> */
    private function existingTables(): array
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['TABLE_NAME'],
            all(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?',
                [(string) configValue('DB_NAME'), t('news') . '_%']
            )
        );

        sort($names);

        return $names;
    }

    private static function dropNewsTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function makeCategory(string $title): string
    {
        $id = newId();

        run(
            'INSERT INTO ' . t('news_category')
                . ' (id, slug, title, order_index, visible, created_at, updated_at)'
                . ' VALUES (?, ?, ?, 0, 1, ?, ?)',
            [$id, 'rubrika-' . substr($id, 0, 8), $title, utcStamp(nowUtc()), utcStamp(nowUtc())]
        );

        return $id;
    }

    private static function makeItem(string $categoryId, string $slug): string
    {
        $id = newId();

        run(
            'INSERT INTO ' . t('news_item')
                . ' (id, slug, title, category_id, status, created_at, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $slug, 'Материал ' . $slug, $categoryId, NEWS_STATUS['DRAFT'], utcStamp(nowUtc()), utcStamp(nowUtc())]
        );

        return $id;
    }
}
```

- [ ] **Шаг 2: Запустить тест и убедиться, что он падает**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsSchemaTest`
Expected: FAIL — `Раздел «Новости» не установился: Раздел «news» не найден в сборке.`

- [ ] **Шаг 3: Создать манифест и загрузчик правил раздела**

Создать `apps/news/manifest.php`:

```php
<?php

declare(strict_types=1);

// Манифест раздела «Новости». `id` обязан совпадать с именем папки: по нему ядро ищет
// sql/, routes.php и seed.php, а также пишет журнал обновлений.
return [
    'id' => 'news',
    'title' => 'Новости',
    'version' => '1.0.0',
    'requiresCore' => '1.1.0',
    'privatePaths' => ['/news/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [['href' => '/news/admin', 'label' => 'Новости']],
    'seed' => __DIR__ . '/seed.php',
];
```

Создать `apps/news/lib/boot.php`:

```php
<?php

declare(strict_types=1);

// Правила раздела подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключит этот же файл, когда появятся контроллеры (задача 16).
require_once __DIR__ . '/constants.php';
```

Изменить `tests/bootstrap.php` — в конец блока подключений, после `core/bootstrap.php`, добавить:

```php
require_once dirname(__DIR__) . '/apps/news/lib/boot.php';
```

Изменить `tests/db/AppsRegistryTest.php` — тело метода `testПриватныеПутиСобираютсяИзВсехМанифестов` заменяется
целиком (второго метода с тем же именем в классе быть не должно):

```php
    public function testПриватныеПутиСобираютсяИзВсехМанифестов(): void
    {
        self::assertSame(['/demo/admin', '/future/admin', '/news/admin'], appPrivatePaths());
    }
```

- [ ] **Шаг 4: Создать словарь пределов и текстов раздела**

Создать `apps/news/lib/constants.php`:

```php
<?php

declare(strict_types=1);

// Единственный список статусов материала. В базе они строки: добавление нового состояния
// не требует правки схемы, а значение сверяется с этим словарём.
const NEWS_STATUS = [
    'DRAFT' => 'DRAFT',
    'PUBLISHED' => 'PUBLISHED',
];

/** @var array<string, string> */
const NEWS_STATUS_LABEL = [
    'DRAFT' => 'черновик',
    'PUBLISHED' => 'опубликована',
];

// Фильтр списка в админке: `scheduled` — частный случай опубликованной строки, у которой срок
// ещё не наступил; в базе такого значения нет.
/** @var array<string, string> */
const NEWS_FILTER_LABEL = [
    'all' => 'Все материалы',
    'DRAFT' => 'Черновики',
    'scheduled' => 'Ждут срока',
    'PUBLISHED' => 'Опубликованные',
];

/** @var list<string> */
const NEWS_FILTERS = ['all', 'DRAFT', 'scheduled', 'PUBLISHED'];

// Сколько материалов держать на странице ленты и на странице администратора.
const NEWS_PER_PAGE = 12;
const ADMIN_NEWS_PER_PAGE = 20;

// Закреплённых материалов не бывает много: на первой странице ленты они идут первыми, а шесть
// закреплённых вытесняют обычную ленту целиком. Админка предупреждает, но не блокирует.
const PINNED_WARN_LIMIT = 5;

// У поиска по ленте есть предел: строка длиннее заголовка ничего не ищет, а запрос с
// гигантским шаблоном дорог.
const SEARCH_MAX_LENGTH = 100;

// Адрес материала живёт прямо под /news/…, поэтому служебные сегменты раздела заняты: материал
// с адресом «admin» прятал бы за собой админку, а «c» ломал страницы рубрик.
const NEWS_SLUG_MIN = 3;
const NEWS_SLUG_MAX = 60;

/** @var list<string> */
const NEWS_RESERVED_SLUGS = ['admin', 'c', 't'];

// Пределы полей материала и справочников: их читает и форма, и сервер, чтобы подпись поля не
// расходилась с проверкой.
/** @var array<string, array<string, int>|int> */
const NEWS_FIELDS = [
    'title' => ['min' => 3, 'max' => 120],
    'lead' => ['max' => 300],
    'paragraphTitle' => ['max' => 120],
    'paragraphText' => ['max' => 4000],
    'paragraphs' => 40,
    'seoTitle' => ['max' => 120],
    'seoDescription' => ['max' => 300],
    'image' => ['max' => 300],
    'oldSlugs' => 10,
];

/** @var array<string, array<string, int>|int> */
const CATEGORY_FIELDS = ['title' => ['min' => 2, 'max' => 60], 'description' => ['max' => 300]];

// `rawList` — предел одной строки тегов через запятую, как её приносит форма материала: сервер
// проверяет строку целиком, поэтому и поле обрезает то же число.
/** @var array<string, array<string, int>|int> */
const TAG_FIELDS = ['label' => ['min' => 2, 'max' => 40], 'perItem' => 10, 'rawList' => 600];

// Предел прежних адресов на материал: список хранится строками в tbl_news_old_slug,
// а обрезает его rememberOldSlug() (задача 12).
const OLD_SLUGS_MAX = 10;

// Стартовые рубрики: раздел без справочника не даёт сохранить ни один материал, поэтому
// закладка идёт сразу с установкой. Список живёт здесь, а не в seed.php: ядро подключает
// seed.php через `require` при каждой установке, и объявление константы на втором прогоне
// стало бы фатальной ошибкой.
/** @var list<array{slug: string, title: string}> */
const NEWS_SEED_CATEGORIES = [
    ['slug' => 'zhizn-shkoly', 'title' => 'Жизнь школы'],
    ['slug' => 'postuplenie', 'title' => 'Поступление'],
    ['slug' => 'metodika', 'title' => 'Методика'],
];

// Тексты успеха читают страницы по ключу из адресной строки: сообщение «материал опубликован»
// должен подсказывать код, а не набор слов в ссылке.
/** @var array<string, string> */
const NEWS_NOTICE_TEXT = [
    'created' => 'Черновик сохранён. На сайт он не вышел — выберите «На печать», когда допишете текст.',
    'published' => 'Материал на сайте: он появился в ленте, в рубрике и в карте сайтов.',
    'scheduled' => 'Материал назначен к публикации: он появится сам, когда наступит срок.',
    'updated' => 'Материал обновлён. Адрес карточки остался прежним.',
    'unpublished' => 'Материал возвращён в черновики: с сайта он снят, срок публикации сохранён.',
    'pinned' => 'Материал закреплён: он идёт первым в ленте, в рубрике и в подборке тегов.',
    'unpinned' => 'Материал больше не закреплён: он встаёт в ленту по дате.',
    'unchanged' => 'Ничего не изменилось.',
    'slugChanged' => 'Адрес изменён. Со старого адреса ведём на новый постоянно.',
];

/** @var array<string, string> */
const CATALOG_NOTICE_TEXT = [
    'created' => 'Рубрика добавлена в конец списка.',
    'renamed' => 'Название обновлено. Адрес рубрики остался прежним.',
    'described' => 'Описание рубрики сохранено: оно читается под заголовком её страницы.',
    'hidden' => 'Рубрика скрыта: её страница не открывается, а материалы ушли из общей ленты.',
    'shown' => 'Рубрика снова видна.',
    'moved' => 'Порядок обновлён.',
    'deleted' => 'Пустая рубрика удалена.',
    'topped' => 'Эта рубрика уже первая.',
    'bottomed' => 'Эта рубрика уже последняя.',
    'tagRenamed' => 'Тег переименован. Его адрес остался прежним.',
    'tagDeleted' => 'Тег удалён.',
];

/**
 * Предел из справочника полей: у части записей это число (`paragraphs`, `perItem`), у части —
 * пара min/max (`title`, `label`). Один способ чтения убирает приведения типов из кода раздела
 * и из тестов — PHPStan на уровне 6 не пускает обращение по ключу к записи, которая может
 * оказаться числом.
 *
 * @param array<string, array<string, int>|int> $limits
 */
function fieldLimit(array $limits, string $field, string $key = ''): int
{
    $entry = $limits[$field] ?? 0;

    if (is_int($entry)) {
        return $entry;
    }

    return is_array($entry) ? (int) ($entry[$key] ?? 0) : 0;
}
```

- [ ] **Шаг 5: Написать тест пределов и запустить его**

Создать `tests/unit/NewsConstantsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

final class NewsConstantsTest extends TestCase
{
    public function testПределыПолейМатериалаСовпадаютСоСпецификацией(): void
    {
        self::assertSame(3, fieldLimit(NEWS_FIELDS, 'title', 'min'));
        self::assertSame(120, fieldLimit(NEWS_FIELDS, 'title', 'max'));
        self::assertSame(300, fieldLimit(NEWS_FIELDS, 'lead', 'max'));
        self::assertSame(120, fieldLimit(NEWS_FIELDS, 'paragraphTitle', 'max'));
        self::assertSame(4000, fieldLimit(NEWS_FIELDS, 'paragraphText', 'max'));
        self::assertSame(40, fieldLimit(NEWS_FIELDS, 'paragraphs'));
        self::assertSame(120, fieldLimit(NEWS_FIELDS, 'seoTitle', 'max'));
        self::assertSame(300, fieldLimit(NEWS_FIELDS, 'seoDescription', 'max'));
        self::assertSame(300, fieldLimit(NEWS_FIELDS, 'image', 'max'));
        self::assertSame(10, fieldLimit(NEWS_FIELDS, 'oldSlugs'));
        self::assertSame(10, OLD_SLUGS_MAX);
    }

    public function testПределыСправочниковСовпадаютСоСпецификацией(): void
    {
        self::assertSame(2, fieldLimit(CATEGORY_FIELDS, 'title', 'min'));
        self::assertSame(60, fieldLimit(CATEGORY_FIELDS, 'title', 'max'));
        self::assertSame(300, fieldLimit(CATEGORY_FIELDS, 'description', 'max'));
        self::assertSame(2, fieldLimit(TAG_FIELDS, 'label', 'min'));
        self::assertSame(40, fieldLimit(TAG_FIELDS, 'label', 'max'));
        self::assertSame(10, fieldLimit(TAG_FIELDS, 'perItem'));
        self::assertSame(600, fieldLimit(TAG_FIELDS, 'rawList'));
    }

    public function testСтатусовДваИУКаждогоСвояПодпись(): void
    {
        self::assertSame(['DRAFT' => 'DRAFT', 'PUBLISHED' => 'PUBLISHED'], NEWS_STATUS);
        self::assertSame(['DRAFT', 'PUBLISHED'], array_keys(NEWS_STATUS_LABEL));
        self::assertSame('черновик', NEWS_STATUS_LABEL[NEWS_STATUS['DRAFT']]);
        self::assertSame('опубликована', NEWS_STATUS_LABEL[NEWS_STATUS['PUBLISHED']]);
    }

    public function testФильтрыСпискаСостоятИзСтатусовИДвухОсобыхЗначений(): void
    {
        self::assertSame(['all', 'DRAFT', 'scheduled', 'PUBLISHED'], NEWS_FILTERS);
        self::assertSame(NEWS_FILTERS, array_keys(NEWS_FILTER_LABEL));
        self::assertSame('Ждут срока', NEWS_FILTER_LABEL['scheduled']);
    }

    public function testТекстыУспехаЗаканчиваютсяТочкойИНеСодержатПодстановок(): void
    {
        $dictionaries = ['материал' => NEWS_NOTICE_TEXT, 'справочники' => CATALOG_NOTICE_TEXT];

        foreach ($dictionaries as $name => $texts) {
            self::assertNotSame([], $texts, $name);

            foreach ($texts as $key => $text) {
                self::assertNotSame('', $key, $name);
                self::assertNotSame('', $text, $key);
                self::assertStringEndsWith('.', $text, $key);
                self::assertStringNotContainsString('{', $text, $key);
            }
        }
    }

    public function testСлужебныеСегментыРазделаЗаняты(): void
    {
        self::assertSame(['admin', 'c', 't'], NEWS_RESERVED_SLUGS);
        self::assertSame(3, NEWS_SLUG_MIN);
        self::assertSame(60, NEWS_SLUG_MAX);
        self::assertSame(12, NEWS_PER_PAGE);
        self::assertSame(20, ADMIN_NEWS_PER_PAGE);
        self::assertSame(5, PINNED_WARN_LIMIT);
        self::assertSame(100, SEARCH_MAX_LENGTH);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsConstantsTest`
Expected: PASS — 6 проверок.

- [ ] **Шаг 6: Создать первый релиз схемы — каталог**

Создать `apps/news/sql/20261006_0001_catalog.sql`:

```sql
-- Каталог раздела «Новости»: рубрики и теги.
-- IF NOT EXISTS обязателен: MySQL фиксирует DDL сразу, поэтому журнал пишется после каждого
-- файла, а не одним оборотом (отклонение 9 плана).
CREATE TABLE IF NOT EXISTS tbl_news_category (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(60) NOT NULL,
    description VARCHAR(300) NULL,
    order_index INT NOT NULL DEFAULT 0,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY news_category_slug (slug),
    UNIQUE KEY news_category_title (title),
    KEY news_category_order (order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_news_tag (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    label VARCHAR(40) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY news_tag_slug (slug),
    UNIQUE KEY news_tag_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Шаг 7: Создать второй релиз схемы — материалы**

Создать `apps/news/sql/20261006_0002_items.sql`:

```sql
-- Материалы раздела «Новости», их теги и прежние адреса.
-- `body` — JSON-массив абзацев [{title, text}]; сырого HTML в материале нет, выделение
-- задаётся **звёздочками** и разбирается при выводе. Валидность JSON проверяет PHP до записи.
CREATE TABLE IF NOT EXISTS tbl_news_item (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(120) NOT NULL,
    lead_text VARCHAR(300) NULL,
    cover VARCHAR(300) NULL,
    category_id VARCHAR(26) NOT NULL,
    body JSON NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    published_at DATETIME NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    seo_title VARCHAR(120) NULL,
    seo_description VARCHAR(300) NULL,
    og_image VARCHAR(300) NULL,
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    author_id VARCHAR(26) NULL,
    author_email VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY news_item_slug (slug),
    KEY news_item_status_published (status, published_at),
    KEY news_item_category_status (category_id, status, published_at),
    KEY news_item_pinned (pinned, published_at),
    CONSTRAINT fk_news_item_category FOREIGN KEY (category_id)
        REFERENCES tbl_news_category (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_news_item_tag (
    item_id VARCHAR(26) NOT NULL,
    tag_id VARCHAR(26) NOT NULL,
    PRIMARY KEY (item_id, tag_id),
    KEY news_item_tag_tag (tag_id, item_id),
    CONSTRAINT fk_news_item_tag_item FOREIGN KEY (item_id)
        REFERENCES tbl_news_item (id) ON DELETE CASCADE,
    CONSTRAINT fk_news_item_tag_tag FOREIGN KEY (tag_id)
        REFERENCES tbl_news_tag (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Прежние адреса материала: tbl_news_old_slug заменяет String[] oldSlugs из PostgreSQL,
-- потому что в MySQL поиск по JSON-массиву без индекса сканирует таблицу на каждом открытии
-- старого адреса. Лимит «не больше 10 на материал» проверяет rememberOldSlug() в PHP.
CREATE TABLE IF NOT EXISTS tbl_news_old_slug (
    slug VARCHAR(60) NOT NULL,
    item_id VARCHAR(26) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (slug),
    KEY news_old_slug_item (item_id),
    CONSTRAINT fk_news_old_slug_item FOREIGN KEY (item_id)
        REFERENCES tbl_news_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Шаг 8: Запустить тест схемы и убедиться, что он проходит**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsSchemaTest`
Expected: PASS — 12 проверок.

- [ ] **Шаг 9: Создать начальную закладку и её тест**

Создать `apps/news/seed.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/boot.php';

// Закладка идемпотентна: ядро запускает seed.php через `require` при каждой установке,
// поэтому файл не объявляет ни констант, ни функций — только повторяет запись по slug.
// Рубрики, добавленные контент-менеджером вручную, закладка не трогает и не переименовывает.
foreach (NEWS_SEED_CATEGORIES as $index => $category) {
    $exists = (int) value(
        'SELECT COUNT(*) FROM ' . t('news_category') . ' WHERE slug = ?',
        [$category['slug']]
    );

    if ($exists > 0) {
        continue;
    }

    run(
        'INSERT INTO ' . t('news_category')
            . ' (id, slug, title, order_index, visible, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, 1, ?, ?)',
        [newId(), $category['slug'], $category['title'], $index, utcStamp(nowUtc()), utcStamp(nowUtc())]
    );
}
```

Создать `tests/db/NewsSeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class NewsSeedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropNewsTables();

        $result = installApp('news');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Новости» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropNewsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'news'");
    }

    public function testСтартовыеРубрикиЗакладываютсяПоУмолчанию(): void
    {
        // Сортировка по title, а не по order_index: порядок закладки проверяет соседний тест,
        // а здесь важен только состав стартовых рубрик.
        $titles = array_map(
            static fn (array $row): string => (string) $row['title'],
            all('SELECT title FROM ' . t('news_category') . ' ORDER BY title')
        );

        self::assertSame(['Жизнь школы', 'Методика', 'Поступление'], $titles);
    }

    public function testРубрикиВидныИСтоятВЗаданномПорядке(): void
    {
        $rows = all(
            'SELECT slug, order_index, visible FROM ' . t('news_category') . ' ORDER BY order_index'
        );

        self::assertSame(['zhizn-shkoly', 'postuplenie', 'metodika'], array_column($rows, 'slug'));
        self::assertSame([0, 1, 2], array_map(static fn (array $row): int => (int) $row['order_index'], $rows));

        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['visible']);
        }
    }

    public function testПовторнаяЗакладкаНеДублируетРубрики(): void
    {
        $manifest = (array) require dirname(__DIR__, 2) . '/apps/news/manifest.php';
        $before = (int) value('SELECT COUNT(*) FROM ' . t('news_category'));

        seedApp($manifest);
        seedApp($manifest);

        self::assertSame($before, (int) value('SELECT COUNT(*) FROM ' . t('news_category')));
    }

    public function testДобавленнаяВручнуюРубрикаПереживаетЗакладку(): void
    {
        run(
            'INSERT INTO ' . t('news_category')
                . ' (id, slug, title, order_index, visible, created_at, updated_at)'
                . ' VALUES (?, ?, ?, 9, 1, ?, ?)',
            [newId(), 'konkursy', 'Конкурсы', utcStamp(nowUtc()), utcStamp(nowUtc())]
        );

        $manifest = (array) require dirname(__DIR__, 2) . '/apps/news/manifest.php';
        seedApp($manifest);

        $slugs = array_column(
            all('SELECT slug FROM ' . t('news_category') . ' ORDER BY order_index, title'),
            'slug'
        );

        self::assertSame(['zhizn-shkoly', 'postuplenie', 'metodika', 'konkursy'], $slugs);
    }

    private static function dropNewsTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsSeedTest`
Expected: PASS — 4 проверки.

- [ ] **Шаг 10: Написать страж SQL-файлов раздела и тест реестра**

Создать `tests/guard/AppSqlTest.php`. Ниже — каркас с тремя проверками: живых в файле пять, две
добавлены более поздними шагами той же задачи. Проверки содержимого идут по `sqlFiles()` и потому
обязаны читать все релизы — и файлов приложений, и базовую схему ядра; по `appSqlFiles()` ходит
только проверка имён, и её исключение из корневого файла обосновано строкой ниже.

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

use function basename;
use function glob;
use function preg_match;
use function preg_match_all;

final class AppSqlTest extends TestCase
{
    public function testКаждоеСозданиеТаблицыДопускаетПовторныйПрогон(): void
    {
        $violations = [];

        foreach ($this->sqlFiles() as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all('/CREATE\s+TABLE(\s+IF\s+NOT\s+EXISTS)?/i', $sql, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (trim((string) ($match[1] ?? '')) === '') {
                    $violations[] = $file . ' — CREATE TABLE без IF NOT EXISTS (отклонение 9)';
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testИменаРелизовЧитаютсяЖурналом(): void
    {
        $violations = [];

        // Имена проверяются только у файлов приложений: имя базового релиза ядра зафиксировано
        // установщиком — он читает файл по этому пути и сам вписывает его имя в журнал.
        foreach ($this->appSqlFiles() as $file) {
            if (preg_match('/^\d{8}_\d{4}_[a-z0-9_]+\.sql$/', basename($file)) !== 1) {
                $violations[] = $file;
            }
        }

        self::assertSame([], $violations);
    }

    public function testИндексыИВнешниеКлючиНосятИмена(): void
    {
        $violations = [];

        foreach ($this->sqlFiles() as $file) {
            $sql = (string) file_get_contents($file);

            if (preg_match('/^\s*KEY\s*\(/mi', $sql) === 1) {
                $violations[] = $file . ' — KEY без имени';
            }

            if (preg_match('/^\s*FOREIGN\s+KEY\s*\(/mi', $sql) === 1) {
                $violations[] = $file . ' — FOREIGN KEY без CONSTRAINT: имя нужно для читаемого отказа';
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * Релизы схемы на проверку содержимого: и приложения, и базовая схема ядра. Правила
     * одинаковые — установщик исполняет файл ядра теми же утверждениями, что журнал исполняет
     * файлы раздела, поэтому `CREATE TABLE` без `IF NOT EXISTS` в любом из них ломает повторный
     * прогон установки.
     *
     * @return list<string>
     */
    private function sqlFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/sql/*.sql') ?: [];

        foreach (glob($root . '/apps/*/sql/*.sql') ?: [] as $file) {
            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function appSqlFiles(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/apps/*/sql/*.sql') ?: [];

        sort($files);

        return $files;
    }
}
```

Создать `tests/db/NewsRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class NewsRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropNewsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'news'");
    }

    public static function tearDownAfterClass(): void
    {
        self::dropNewsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'news'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'news'");
    }

    public function testМанифестРазделаЧитаетсяИзСборки(): void
    {
        $manifest = appManifest('news');

        self::assertIsArray($manifest);
        self::assertSame('Новости', (string) $manifest['title']);
        self::assertSame('1.0.0', (string) $manifest['version']);
        self::assertSame('1.1.0', (string) $manifest['requiresCore']);
        self::assertStringEndsWith('apps/news', str_replace('\\', '/', (string) $manifest['dir']));
        self::assertSame([['href' => '/news/admin', 'label' => 'Новости']], $manifest['adminNav']);
    }

    public function testДоУстановкиРазделОтмеченОтсутствующим(): void
    {
        $state = appState('news');

        self::assertSame('missing', $state['state']);
        self::assertSame('1.0.0', $state['manifestVersion']);
        self::assertSame('', $state['dbVersion']);
        self::assertSame('Раздел не установлен.', $state['reason']);
    }

    public function testВерсияЯдраПодходитРазделу(): void
    {
        self::assertTrue(coreVersionSatisfies('1.1.0'));
        self::assertFalse(coreVersionSatisfies('99.0.0'));
    }

    public function testПланУстановкиСодержитОбаРелиза(): void
    {
        $plan = planApp('news');

        self::assertFalse($plan->isEmpty());
        self::assertFalse($plan->isBlocked());
        self::assertSame(['20261006_0001_catalog.sql', '20261006_0002_items.sql'], $plan->pendingNames());
    }

    public function testПриватныйПутьРазделаОбъявленВМанифесте(): void
    {
        self::assertContains('/news/admin', appPrivatePaths());
    }

    private static function dropNewsTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "vendor/bin/phpunit --testsuite guard --filter AppSqlTest && vendor/bin/phpunit --testsuite db --filter NewsRegistryTest"`
Expected: PASS — 3 стража и 5 проверок реестра.

- [ ] **Шаг 11: Прогнать все наборы**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — падения только в задачах, которые ещё не написаны, отсутствуют; `AppsRegistryTest` проходит с обновлённым списком приватных путей.

- [ ] **Шаг 12: Ворота и фиксация**

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add apps/news tests/bootstrap.php tests/unit/NewsConstantsTest.php tests/db/NewsSchemaTest.php \
  tests/db/NewsSeedTest.php tests/db/NewsRegistryTest.php tests/guard/AppSqlTest.php tests/db/AppsRegistryTest.php
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git commit -m "Раздел «Новости»: манифест, два релиза схемы, стартовые рубрики и пределы полей"
```

---

### Задача 12: Правила видимости, адресов и проверки полей

**Файлы:**
- Создать: `apps/news/lib/visibility.php`
- Создать: `apps/news/lib/slug.php`
- Создать: `apps/news/lib/validators.php`
- Изменить: `apps/news/lib/boot.php` (подключить три новых файла)
- Тест: `tests/unit/NewsVisibilityTest.php`
- Тест: `tests/unit/NewsSlugTest.php`
- Тест: `tests/unit/NewsValidatorsTest.php`

**Интерфейсы:**
- Потребляет: `fromDb()`, `utcStamp()`, `nowUtc()`, `moscowIso()`, `parseFormDateTime()` из `core/date.php`; `isOn()` из `core/form.php` (объявлена там — раздел её **не** переобъявляет); `fieldLimit()`, `NEWS_STATUS`, `NEWS_FIELDS`, `TAG_FIELDS`, `NEWS_SLUG_MIN`, `NEWS_SLUG_MAX`, `NEWS_RESERVED_SLUGS`, `OLD_SLUGS_MAX`, `SEARCH_MAX_LENGTH`, `PINNED_WARN_LIMIT` из `apps/news/lib/constants.php`.
- Производит: `isOpen()`, `visibleWhere()`, `visibleArgs()`, `orderNews()`, `tooManyPinned()`, `isScheduled()`; `slugify()`, `ensureUniqueSlug()`, `newsSlugPlan()`, `rememberOldSlug()`, `NEWS_TRANSLIT`; `parseBody()`, `bodyToFormText()`, `splitBold()`, `parseTagList()`, `parsePublishedAt()`, `formPublishedAt()`, `imagePath()`, `parseQuery()`, `resolveStatus()`, `parseNewsForm()`, `newsFormErrors()`, `newsTooLongText()`, `NEWS_FORM_FIELDS`, `NEWS_FORM_MAX`, `NEWS_FORM_TOO_LONG`, `NEWS_STATUS_BY_INTENT`.

**Что сюда не входит:** `publishTextError()`, `publishNotice()` и `publishDateOf()` остаются в `apps/news/lib/items.php` (задача 14) вместе со своим тестом `tests/unit/NewsPublishRulesTest.php` — они читают строку материала целиком и без неё не проверяются.

**Что переносится дословно, а что нет:** `NEWS_TRANSLIT`, тексты отказов и правила разбора абзацев перенесены из `src/lib/slug.ts` и `src/apps/news/lib/validators.ts` без изменения смысла. Две осознанные разницы: отказ про занятый адрес называет сам адрес («Адрес «admin» занят…») — так редактору видно, что именно предложить, и правило картинки строже порталовского — путь с `..`, его процент-кодами, обратным слэшем или управляющим символом отклоняется целиком. Zod заменён явными проверками (у ядра нет рантайм-зависимостей), `newsFormValues()` не переносится — её заменяет `formValues()` из `core/form.php`, а предел `categoryId` ужат с 64 до 26 символов: столько занимает строковый идентификатор из `core/id.php`.

- [ ] **Шаг 1: Написать падающий тест видимости**

Создать `tests/unit/NewsVisibilityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class NewsVisibilityTest extends TestCase
{
    private function moment(string $stamp): DateTimeImmutable
    {
        return new DateTimeImmutable($stamp, new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private function published(string $stamp): array
    {
        return ['status' => 'PUBLISHED', 'published_at' => $stamp];
    }

    public function testОпубликованныйМатериалНаступившегоСрокаОткрыт(): void
    {
        $now = $this->moment('2026-10-06 12:00:00');

        self::assertTrue(isOpen($this->published('2026-10-06 09:00:00'), $now));
        self::assertTrue(isOpen($this->published('2026-10-06 12:00:00'), $now), 'Граница включительно');
    }

    public function testМатериалБудущегоСрокаЗакрыт(): void
    {
        self::assertFalse(isOpen($this->published('2026-10-06 15:00:00'), $this->moment('2026-10-06 12:00:00')));
        self::assertTrue(isScheduled($this->published('2026-10-06 15:00:00'), $this->moment('2026-10-06 12:00:00')));
        self::assertFalse(isScheduled($this->published('2026-10-06 09:00:00'), $this->moment('2026-10-06 12:00:00')));
    }

    public function testЧерновикЗакрытДажеСоСрокомВПрошлом(): void
    {
        $row = ['status' => 'DRAFT', 'published_at' => '2026-10-01 09:00:00'];

        self::assertFalse(isOpen($row, $this->moment('2026-10-06 12:00:00')));
        self::assertFalse(isScheduled($row, $this->moment('2026-10-06 12:00:00')));
    }

    public function testНеизвестныйСтатусЗакрыт(): void
    {
        $row = ['status' => 'ARCHIVED', 'published_at' => '2026-10-01 09:00:00'];

        self::assertFalse(isOpen($row, $this->moment('2026-10-06 12:00:00')));
        self::assertFalse(isScheduled($row, $this->moment('2026-10-06 12:00:00')));
    }

    public function testОпубликованныйБезСрокаЗакрыт(): void
    {
        $row = ['status' => 'PUBLISHED', 'published_at' => null];

        self::assertFalse(isOpen($row, $this->moment('2026-10-06 12:00:00')));
        self::assertTrue(isScheduled($row, $this->moment('2026-10-06 12:00:00')), 'Строка без срока ждёт его назначения');
    }

    public function testУсловиеВидимостиЭтоОдинПлейсхолдерБезЛитералаДаты(): void
    {
        $where = visibleWhere();

        self::assertSame(1, substr_count($where, '?'));
        self::assertStringNotContainsString('2026', $where);
        self::assertStringContainsString("status = 'PUBLISHED'", $where);
        self::assertStringContainsString('published_at IS NOT NULL', $where);
        self::assertSame(['2026-10-06 12:00:00'], visibleArgs($this->moment('2026-10-06 12:00:00')));
        self::assertSame(
            substr_count($where, '?'),
            count(visibleArgs($this->moment('2026-10-06 12:00:00'))),
            'плейсхолдеров в условии ровно столько, сколько значений передаётся в запрос'
        );
    }

    public function testПорядокЛентыОдинНаВсехСтраницах(): void
    {
        // Порядок не зависит от страницы: OFFSET считается по той же последовательности, по какой
        // читаются строки. Как только страница меняет порядок, на стыке страниц материал
        // теряется, а закреплённый печатается дважды.
        self::assertSame('pinned DESC, published_at DESC', orderNews());
    }

    public function testФрагментыПорядкаСостоятИзИменКолонок(): void
    {
        self::assertMatchesRegularExpression('/^[a-z_]+ DESC(, [a-z_]+ DESC)?$/', orderNews());
    }

    public function testПредупреждениеОЗакреплённыхСрабатываетПослеПредела(): void
    {
        self::assertFalse(tooManyPinned(0));
        self::assertFalse(tooManyPinned(PINNED_WARN_LIMIT));
        self::assertTrue(tooManyPinned(PINNED_WARN_LIMIT + 1));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsVisibilityTest`
Expected: FAIL — `Call to undefined function isOpen()`.

- [ ] **Шаг 2: Создать правила видимости**

Создать `apps/news/lib/visibility.php`:

```php
<?php

declare(strict_types=1);

// Видимость материала — одно правило на весь код раздела. Черновик публикации не открывается
// строкой в базе: материал выпускает действие контент-менеджера, а срок публикации просто ещё
// не наступил. Публичная выборка обязана идти через visibleWhere() + visibleArgs().

/** @param array<string, mixed> $row */
function isOpen(array $row, DateTimeImmutable $now): bool
{
    if ((string) ($row['status'] ?? '') !== NEWS_STATUS['PUBLISHED']) {
        return false;
    }

    $raw = $row['published_at'] ?? null;
    $published = fromDb(is_string($raw) ? $raw : null);

    return $published !== null && $published <= $now;
}

// Строка условия собирается из словаря статусов, а не пишется текстом: единственная кавычка
// здесь — вокруг значения NEWS_STATUS, и оно приходит из константы, а не из запроса.
function visibleWhere(): string
{
    return "status = '" . NEWS_STATUS['PUBLISHED'] . "' AND published_at IS NOT NULL AND published_at <= ?";
}

/** Момент передаётся отдельным аргументом, чтобы вызывающий код не подставлял дату в SQL.
 *  @return list<string> */
function visibleArgs(DateTimeImmutable $now): array
{
    return [utcStamp($now)];
}

/**
 * Порядок везде один: закреплённые вперёд, затем по сроку. Страница в него не входит — при
 * порядке «закреплённые только на первой» OFFSET читается по одной последовательности, а строки
 * по другой: на стыке страниц материал теряется, а закреплённый показывается дважды. Плата за
 * это иная: закреплённые занимают начало каждой страницы, и при большом их числе лента уходит с
 * первой страницы целиком. Число ничего не блокирует — предельное подсказывает tooManyPinned().
 */
function orderNews(): string
{
    return 'pinned DESC, published_at DESC';
}

/** Пора ли предупредить администратора, что закреплённые вытеснили ленту. */
function tooManyPinned(int $count): bool
{
    return $count > PINNED_WARN_LIMIT;
}

// «Ждут срока» — опубликованная строка, у которой момент публикации ещё не наступил:
// фильтр админки показывает её отдельно от черновиков (ключ `scheduled` из NEWS_FILTERS).
/** @param array<string, mixed> $row */
function isScheduled(array $row, DateTimeImmutable $now): bool
{
    return (string) ($row['status'] ?? '') === NEWS_STATUS['PUBLISHED'] && !isOpen($row, $now);
}
```

Изменить `apps/news/lib/boot.php`:

```php
<?php

declare(strict_types=1);

// Правила раздела подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключит этот же файл, когда появятся контроллеры (задача 16).
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/visibility.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsVisibilityTest`
Expected: PASS — 9 проверок.

- [ ] **Шаг 3: Написать падающий тест адресов**

Создать `tests/unit/NewsSlugTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

final class NewsSlugTest extends TestCase
{
    public function testРусскийЗаголовокСтановитсяАдресом(): void
    {
        $cases = [
            'Жизнь школы' => 'zhizn-shkoly',
            'Поступление в 10 класс' => 'postuplenie-v-10-klass',
            'Конкурс «Ученик года»' => 'konkurs-uchenik-goda',
            'Итоги, итоги и ещё раз итоги' => 'itogi-itogi-i-eschyo-raz-itogi',
            'Щедрый вечер' => 'schedryy-vecher',
            'Объект №5' => 'obekt-5',
            '  Пробелы  вокруг  ' => 'probely-vokrug',
            'A1-B2_test.pdf' => 'a1-b2-test-pdf',
            'Без пробелов' => 'bez-probelov',
            'Ёлка' => 'yolka',
        ];

        foreach ($cases as $input => $expected) {
            self::assertSame($expected, slugify((string) $input), (string) $input);
        }
    }

    public function testАдресОбрезаетсяДоПределаИНеКончаетсяДефисом(): void
    {
        $long = str_repeat('слово ', 40);
        $slug = slugify($long);

        self::assertLessThanOrEqual(NEWS_SLUG_MAX, mb_strlen($slug));
        self::assertStringNotContainsString('--', $slug);
        self::assertStringEndsNotWith('-', $slug);
        self::assertSame(NEWS_SLUG_MAX, mb_strlen(slugify(str_repeat('а', 200))));
    }

    public function testПустойИНечитаемыйЗаголовокДаютПустойАдрес(): void
    {
        self::assertSame('', slugify(''));
        self::assertSame('', slugify('   '));
        self::assertSame('', slugify('!!! ???'));
    }

    public function testКоллизияРешаетсяНумерацией(): void
    {
        $taken = static fn (string $slug): bool => in_array($slug, ['novost', 'novost-2', 'novost-3'], true);

        self::assertSame('novost-4', ensureUniqueSlug('novost', $taken));
        self::assertSame('svobodnyj', ensureUniqueSlug('svobodnyj', $taken));
    }

    public function testПустаяОсноваЗаменяетсяЗапаснымСловом(): void
    {
        self::assertSame('novost', ensureUniqueSlug('', static fn (): bool => false));
        self::assertSame('zagolovok', ensureUniqueSlug('', static fn (): bool => false, 'zagolovok'));
    }

    public function testВписанныйАдресНеНумеруетсяПриЗанятости(): void
    {
        $plan = newsSlugPlan('zanyatyj-adres', 'Заголовок', static fn (): bool => true);

        self::assertSame('', $plan['slug']);
        self::assertSame('По такому адресу уже есть материал — придумайте другой.', $plan['error']);
    }

    public function testСвободныйВписанныйАдресПроходитБезИзменений(): void
    {
        $plan = newsSlugPlan('Vypusk 2026!', 'Другой заголовок', static fn (): bool => false);

        self::assertSame('vypusk-2026', $plan['slug']);
        self::assertSame('', $plan['error']);
    }

    public function testСлужебныйСегментРазделаЗанят(): void
    {
        foreach (NEWS_RESERVED_SLUGS as $reserved) {
            $plan = newsSlugPlan((string) $reserved, 'Заголовок', static fn (): bool => false);

            self::assertSame('', $plan['slug'], (string) $reserved);
            self::assertSame(
                'Адрес «' . $reserved . '» занят служебной страницей раздела — выберите другой.',
                $plan['error']
            );
        }
    }

    public function testКороткийВписанныйАдресОтклоняется(): void
    {
        $plan = newsSlugPlan('ab', 'Заголовок', static fn (): bool => false);

        self::assertSame('', $plan['slug']);
        self::assertSame('Адрес короче 3 символов — по нему материал открывают по ссылке.', $plan['error']);
    }

    public function testПустойАдресСобираетсяИзЗаголовка(): void
    {
        $plan = newsSlugPlan('', 'Жизнь школы', static fn (): bool => false);

        self::assertSame('zhizn-shkoly', $plan['slug']);
        self::assertSame('', $plan['error']);
    }

    public function testПустойАдресИзНечитаемогоЗаголовкаОтклоняется(): void
    {
        $plan = newsSlugPlan('', '!!!', static fn (): bool => false);

        self::assertSame('', $plan['slug']);
        self::assertSame(
            'Адрес не получился: из такого заголовка его не собрать (3 символов минимум). Впишите адрес сами.',
            $plan['error']
        );
    }

    public function testАдресИзЗаголовкаПриКоллизииНумеруется(): void
    {
        $plan = newsSlugPlan('', 'Жизнь школы', static fn (string $slug): bool => $slug === 'zhizn-shkoly');

        self::assertSame('zhizn-shkoly-2', $plan['slug']);
        self::assertSame('', $plan['error']);
    }

    public function testПрежнийАдресЗапоминаетсяПервым(): void
    {
        self::assertSame(['staryj'], rememberOldSlug([], 'staryj', 'novyj'));
        self::assertSame(['staryj', 'proshlyj'], rememberOldSlug(['proshlyj'], 'staryj', 'novyj'));
    }

    public function testПрежнийАдресНеЗапоминаетсяЕслиОнНеМенялся(): void
    {
        self::assertSame(['a'], rememberOldSlug(['a'], '', 'novyj'));
        self::assertSame(['a'], rememberOldSlug(['a'], 'novyj', 'novyj'));
    }

    public function testНовыйАдресИПовторыУбираютсяИзСпискаПрежних(): void
    {
        $slugs = ['novyj', 'a', 'a', 'b'];

        self::assertSame(['staryj', 'a', 'b'], rememberOldSlug($slugs, 'staryj', 'novyj'));
        self::assertSame(['staryj', 'a', 'b'], rememberOldSlug(['a', '', 'a', 'staryj', 'b'], 'staryj', 'novyj'));
    }

    public function testСписокПрежнихАдресовОбрезаетсяПоПределу(): void
    {
        $slugs = [];
        for ($i = 1; $i <= OLD_SLUGS_MAX; $i++) {
            $slugs[] = 'adres-' . $i;
        }

        $result = rememberOldSlug($slugs, 'samyj-novyj', 'tekushchij');

        self::assertCount(OLD_SLUGS_MAX, $result);
        self::assertSame('samyj-novyj', $result[0]);
        self::assertNotContains('adres-' . OLD_SLUGS_MAX, $result);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsSlugTest`
Expected: FAIL — `Call to undefined function slugify()`.

- [ ] **Шаг 4: Создать правила адресов**

Создать `apps/news/lib/slug.php`:

```php
<?php

declare(strict_types=1);

// Транслит для адресов — 33 буквы русского алфавита. Список перенесён из src/lib/slug.ts
// Next.js-портала без изменений, чтобы при переносе содержимого адреса материалов совпали.
/** @var array<string, string> */
const NEWS_TRANSLIT = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
    'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
    'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
    'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
    'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
];

function slugify(string $input): string
{
    $lowered = trim(mb_strtolower($input));
    $out = '';

    foreach (preg_split('//u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        if (array_key_exists($char, NEWS_TRANSLIT)) {
            $out .= NEWS_TRANSLIT[$char];
        } elseif (preg_match('/[a-z0-9]/', $char) === 1) {
            $out .= $char;
        } elseif (preg_match('/[\s\-_.\/]/', $char) === 1) {
            $out .= '-';
        }
        // Прочие символы — знаки препинания и прочая графика — отбрасываются.
    }

    $out = trim((string) preg_replace('/-+/', '-', $out), '-');

    return rtrim(mb_substr($out, 0, NEWS_SLUG_MAX), '-');
}

/**
 * Свободный адрес на основе base: при коллизии добавляет -2, -3… Пустую основу меняет на
 * fallback — у раздела новостей он свой, чтобы материал не назывался уроком.
 *
 * Проверка занятости приходит вызывающим кодом: правило не ходит в базу, поэтому его одинаково
 * проверяют и лента, и форма, и тест без подключения.
 */
function ensureUniqueSlug(string $base, callable $exists, string $fallback = 'novost'): string
{
    $root = $base !== '' ? $base : $fallback;
    $candidate = $root;

    for ($n = 2; $exists($candidate); $n++) {
        $candidate = $root . '-' . $n;
    }

    return $candidate;
}

/**
 * План адреса материала: что положить в базу и что сказать человеку.
 *
 * Пустая строка в поле адреса означает «соберите из заголовка» — тогда коллизия решается
 * нумерацией. Вписанный руками адрес не нумеруется: человек указал конкретную ссылку, и молча
 * заменить её на другую значит сохранить материал не туда, куда он просил.
 *
 * @return array{slug: string, error: string}
 */
function newsSlugPlan(string $typedSlug, string $title, callable $taken): array
{
    $typed = slugify($typedSlug);

    if ($typed !== '') {
        // Занятость служебного сегмента проверяется до длины: однобуквенные адреса «c» и «t»
        // человек может вписать целиком, и отказ обязан называть настоящую причину.
        if (in_array($typed, NEWS_RESERVED_SLUGS, true)) {
            return [
                'slug' => '',
                'error' => 'Адрес «' . $typed . '» занят служебной страницей раздела — выберите другой.',
            ];
        }

        if (mb_strlen($typed) < NEWS_SLUG_MIN) {
            return [
                'slug' => '',
                'error' => 'Адрес короче ' . NEWS_SLUG_MIN . ' символов — по нему материал открывают по ссылке.',
            ];
        }

        if ($taken($typed) === true) {
            return ['slug' => '', 'error' => 'По такому адресу уже есть материал — придумайте другой.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = slugify($title);

    if (mb_strlen($base) < NEWS_SLUG_MIN) {
        return [
            'slug' => '',
            'error' => 'Адрес не получился: из такого заголовка его не собрать ('
                . NEWS_SLUG_MIN . ' символов минимум). Впишите адрес сами.',
        ];
    }

    return ['slug' => ensureUniqueSlug($base, $taken), 'error' => ''];
}

/**
 * Список прежних адресов материала. Прежний адрес ставится первым, пустые записи, совпадения и
 * повторы убираются, а хвост длиннее OLD_SLUGS_MAX отрезается: старые адреса нужны для постоянного
 * перенаправления, а не для того, чтобы хранить всю историю правок. Пустой адрес в списке — это
 * перенаправление на `/news/`, поэтому он не попадает в список вовсе.
 *
 * @param list<string> $slugs
 * @return list<string>
 */
function rememberOldSlug(array $slugs, string $previous, string $next): array
{
    if ($previous === '' || $previous === $next) {
        return $slugs;
    }

    $out = [$previous];

    foreach ($slugs as $slug) {
        if ($slug === '' || $slug === $next || in_array($slug, $out, true)) {
            continue;
        }

        $out[] = $slug;
    }

    return array_slice($out, 0, OLD_SLUGS_MAX);
}
```

Изменить `apps/news/lib/boot.php` — добавить третью строку:

```php
require_once __DIR__ . '/slug.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsSlugTest`
Expected: PASS — 16 проверок.

- [ ] **Шаг 5: Написать падающий тест проверки полей**

Создать `tests/unit/NewsValidatorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NewsValidatorsTest extends TestCase
{
    public function testАбзацыРазбираютсяПоПустымСтрокам(): void
    {
        self::assertSame(
            [
                ['title' => '', 'text' => 'Первый абзац.'],
                ['title' => '', 'text' => 'Второй абзац.'],
            ],
            parseBody("Первый абзац.\n\nВторой абзац.")
        );
    }

    public function testЗаголовокАбзацаЧитаетсяИзСтрокиСРешётками(): void
    {
        self::assertSame(
            [['title' => 'Подготовка', 'text' => 'Текст абзаца.']],
            parseBody("## Подготовка\nТекст абзаца.")
        );
    }

    public function testПустыеСтрокиВНачалеБлокаНеМешаютЗаголовку(): void
    {
        self::assertSame(
            [['title' => 'Заголовок', 'text' => 'Текст']],
            parseBody("\n\n## Заголовок\nТекст")
        );
    }

    public function testПустойТекстНеДаётАбзацев(): void
    {
        self::assertSame([], parseBody(''));
        self::assertSame([], parseBody("\n\n\n"));
        self::assertSame([], parseBody("   \n  "));
    }

    public function testАбзацевНеБольшеПредела(): void
    {
        $raw = implode("\n\n", array_map(static fn (int $n): string => 'Абзац ' . $n, range(1, 60)));

        self::assertCount(fieldLimit(NEWS_FIELDS, 'paragraphs'), parseBody($raw));
    }

    public function testТекстАбзацаОбрезаетсяПоПределу(): void
    {
        $paragraphs = parseBody(str_repeat('а', 5000));

        self::assertCount(1, $paragraphs);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'paragraphText', 'max'), mb_strlen($paragraphs[0]['text']));
    }

    public function testТелоВозвращаетсяВФормуТемЖеТекстом(): void
    {
        $body = [
            ['title' => 'Подготовка', 'text' => 'Текст'],
            ['title' => '', 'text' => 'Просто абзац'],
            ['title' => '', 'text' => '   '],
        ];

        self::assertSame("## Подготовка\nТекст\n\nПросто абзац", bodyToFormText($body));
        self::assertSame('', bodyToFormText(null));
        self::assertSame('', bodyToFormText([]));
        self::assertSame('', bodyToFormText('не массив'));
    }

    public function testВыделениеЗвёздочкамиРазбираетсяНаЧасти(): void
    {
        self::assertSame(
            [
                ['text' => 'Обычный ', 'strong' => false],
                ['text' => 'важный', 'strong' => true],
                ['text' => ' текст', 'strong' => false],
            ],
            splitBold('Обычный **важный** текст')
        );
        self::assertSame([['text' => 'Без выделения', 'strong' => false]], splitBold('Без выделения'));
        self::assertSame([], splitBold(''));
    }

    public function testСписокТеговЧиститсяОтПовторовИПробелов(): void
    {
        // Регистр сохраняется как ввёл человек, а сравнивается в нижнем: «ВЫПУСКНИК» не заводит
        // второй тег рядом с «Выпускник».
        self::assertSame(
            ['Выпускник', 'олимпиада', 'Школа'],
            parseTagList('  Выпускник ,, олимпиада , Школа ,ВЫПУСКНИК')
        );
        self::assertSame([], parseTagList(''));
        self::assertSame([], parseTagList(',,, ,'));
        self::assertSame([], parseTagList('а,б'));
    }

    public function testТеговНеБольшеПределаНаМатериал(): void
    {
        $raw = implode(',', array_map(static fn (int $n): string => 'тег' . $n, range(1, 30)));

        self::assertCount(fieldLimit(TAG_FIELDS, 'perItem'), parseTagList($raw));
    }

    public function testСрокПубликацииЧитаетсяКакМосковскоеВремя(): void
    {
        $parsed = parsePublishedAt('2026-10-07T09:00');

        self::assertArrayNotHasKey('error', $parsed);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed['date']);
        self::assertSame('2026-10-07 06:00:00', utcStamp($parsed['date']));
    }

    public function testПустойСрокОзначаетПубликациюБезОтсрочки(): void
    {
        $parsed = parsePublishedAt('   ');

        self::assertArrayNotHasKey('error', $parsed);
        self::assertNull($parsed['date']);
    }

    public function testСрокНеПоФорматуПоляОтклоняется(): void
    {
        $cases = ['07.10.2026 09:00', '2026-10-07', '2026-10-07T09:00:00Z', 'завтра', '2026-10-07 09:00'];

        foreach ($cases as $raw) {
            $parsed = parsePublishedAt($raw);

            self::assertSame(
                'Срок публикации нужно выбрать в поле даты и времени — например, 04.10.2026 09:00.',
                (string) ($parsed['error'] ?? ''),
                $raw
            );
        }
    }

    public function testНебывалыйМоментОтклоняется(): void
    {
        $parsed = parsePublishedAt('2026-13-45T09:00');

        self::assertSame('Такого момента не бывает — проверьте дату и время.', (string) ($parsed['error'] ?? ''));
    }

    public function testСохранённыйСрокВозвращаетсяВПолеФормы(): void
    {
        $parsed = parsePublishedAt('2026-10-07T09:00');

        self::assertInstanceOf(DateTimeImmutable::class, $parsed['date']);
        self::assertSame('2026-10-07T09:00', formPublishedAt($parsed['date']));
        self::assertSame('', formPublishedAt(null));
    }

    public function testКартинкойБываетТолькоПутьВнутриСайта(): void
    {
        self::assertSame('/uploads/news/1.jpg', imagePath('/uploads/news/1.jpg'));
        self::assertNull(imagePath('https://chuzhoj.ru/1.jpg'));
        self::assertNull(imagePath('//chuzhoj.ru/1.jpg'));
        self::assertNull(imagePath('uploads/news/1.jpg'));
        self::assertNull(imagePath('   '));
        self::assertNull(imagePath('/uploads/../../../etc/passwd'));
        self::assertNull(imagePath('/../etc/passwd'));
        self::assertNull(imagePath('/uploads//../../x'));
        self::assertNull(imagePath('/uploads/./1.jpg'));
        self::assertNull(imagePath('/uploads/%2e%2e/etc/passwd'));
        self::assertNull(imagePath('/uploads/%2E%2E/etc/passwd'));
        self::assertNull(imagePath('/uploads/a\\b.jpg'));
        self::assertNull(imagePath('/uploads/a' . chr(0) . 'b.jpg'));
        self::assertNull(imagePath("/uploads/a\nb.jpg"));
        self::assertNull(imagePath("/uploads/a\tb.jpg"));
        self::assertNull(imagePath('/uploads/news/../secret.php'));
        self::assertNull(imagePath('/uploads/..'));
        self::assertSame('/uploads/news-2026.photo.jpg', imagePath('/uploads/news-2026.photo.jpg'));
        self::assertSame('/a.b/c.jpg', imagePath('/a.b/c.jpg'));
        self::assertSame(
            fieldLimit(NEWS_FIELDS, 'image', 'max'),
            mb_strlen((string) imagePath('/' . str_repeat('a', 400)))
        );
    }

    public function testПоискОбрезаетсяДоПредела(): void
    {
        self::assertSame('олимпиада', parseQuery('  олимпиада  '));
        self::assertSame('', parseQuery(null));
        self::assertSame(SEARCH_MAX_LENGTH, mb_strlen(parseQuery(str_repeat('ы', 300))));
    }

    public function testСтатусРешаетсяПоКнопкеИлиПоИзвестномуЗначению(): void
    {
        self::assertSame('PUBLISHED', resolveStatus('publish', 'DRAFT'));
        self::assertSame('DRAFT', resolveStatus('draft', 'PUBLISHED'));
        self::assertSame('PUBLISHED', resolveStatus('', 'PUBLISHED'));
        self::assertSame('DRAFT', resolveStatus('', 'DRAFT'));
        self::assertSame('DRAFT', resolveStatus('', 'НЕИЗВЕСТНО'));
        self::assertSame('PUBLISHED', resolveStatus('неизвестная', 'PUBLISHED'));
    }

    public function testФормаСобираетсяЦеликом(): void
    {
        $data = $this->data($this->form());

        self::assertSame('Выпускники собрались на вечер встречи', $data['title']);
        self::assertSame('vecher-vstrechi', $data['slug']);
        self::assertSame('Короткий лид.', $data['lead']);
        self::assertSame('/uploads/news/cover.jpg', $data['cover']);
        self::assertSame('kategoriya-1', $data['categoryId']);
        self::assertSame([['title' => 'Подготовка', 'text' => 'Текст абзаца.']], $data['body']);
        self::assertSame(['Выпускник', 'олимпиада'], $data['tags']);
        self::assertSame('PUBLISHED', $data['status']);
        self::assertInstanceOf(DateTimeImmutable::class, $data['publishedAt']);
        self::assertSame('2026-10-07 06:00:00', utcStamp($data['publishedAt']));
        self::assertTrue($data['pinned']);
        self::assertSame('Вечер встречи выпускников', $data['seoTitle']);
        self::assertSame('Описание для поисковика.', $data['seoDescription']);
        self::assertSame('/uploads/news/og.jpg', $data['ogImage']);
        self::assertFalse($data['noindex']);
    }

    public function testЧекбоксыЧитаютсяПравиломЯдра(): void
    {
        $form = $this->form();
        $form['pinned'] = '';
        $form['noindex'] = '1';
        $data = $this->data($form);

        self::assertFalse($data['pinned']);
        self::assertTrue($data['noindex']);
    }

    public function testОтказыФормыИдутВПорядкеПолей(): void
    {
        $form = $this->form();
        $form['title'] = 'Не';
        $form['slug'] = 'admin';
        $form['categoryId'] = '';
        $form['publishedAt'] = 'завтра';

        $errors = $this->errors($form);

        self::assertSame(['title', 'slug', 'categoryId', 'publishedAt'], array_keys($errors));
        self::assertSame(
            'Заголовок короче 3 символов — по нему новость находят в ленте и в поиске.',
            $errors['title']
        );
        self::assertSame('Адрес «admin» занят служебной страницей раздела — выберите другой.', $errors['slug']);
        self::assertSame('Выберите рубрику: без неё новость не попадёт ни в один раздел.', $errors['categoryId']);
    }

    public function testСлишкомДлинныеПоляОтклоняютсяСоСвоимПределом(): void
    {
        $form = $this->form();
        $form['title'] = str_repeat('а', 200);
        $form['lead'] = str_repeat('б', 400);
        $form['tags'] = str_repeat('в', 700);
        $form['seoTitle'] = str_repeat('г', 200);
        $form['seoDescription'] = str_repeat('д', 400);

        $errors = $this->errors($form);

        self::assertSame(['title', 'lead', 'tags', 'seoTitle', 'seoDescription'], array_keys($errors));
        self::assertSame('Заголовок длиннее 120 символов.', $errors['title']);
        self::assertSame('Лид длиннее 300 символов.', $errors['lead']);
        self::assertSame('Список тегов слишком длинный — оставьте самые важные.', $errors['tags']);
        self::assertSame('Title длиннее 120 символов.', $errors['seoTitle']);
        self::assertSame('Description длиннее 300 символов.', $errors['seoDescription']);
    }

    public function testСписокПолейФормыПолный(): void
    {
        $expected = ['title', 'slug', 'lead', 'cover', 'categoryId', 'body', 'tags', 'status',
            'publishedAt', 'pinned', 'seoTitle', 'seoDescription', 'ogImage', 'noindex'];

        self::assertSame($expected, NEWS_FORM_FIELDS);

        // Предел объявлен для всех полей, кроме двух: `body` режет parseBody() по абзацам,
        // а `slug` — slugify() по NEWS_SLUG_MAX.
        foreach (NEWS_FORM_FIELDS as $field) {
            if ($field === 'body' || $field === 'slug') {
                self::assertArrayNotHasKey($field, NEWS_FORM_MAX);

                continue;
            }

            self::assertArrayHasKey($field, NEWS_FORM_MAX, 'У поля ' . $field . ' нет предела длины');
            self::assertArrayHasKey($field, NEWS_FORM_TOO_LONG, 'У поля ' . $field . ' нет текста отказа');
        }
    }

    public function testПределыФормыНеРасходятсяСПределамиПолей(): void
    {
        self::assertSame(fieldLimit(NEWS_FIELDS, 'title', 'max'), NEWS_FORM_MAX['title']);
        // У адреса ключа в NEWS_FORM_MAX нет намеренно: предел режет slugify() по NEWS_SLUG_MAX,
        // и одна граница не должна жить в двух местах.
        self::assertArrayNotHasKey('slug', NEWS_FORM_MAX);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'lead', 'max'), NEWS_FORM_MAX['lead']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'image', 'max'), NEWS_FORM_MAX['cover']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'image', 'max'), NEWS_FORM_MAX['ogImage']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'seoTitle', 'max'), NEWS_FORM_MAX['seoTitle']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'seoDescription', 'max'), NEWS_FORM_MAX['seoDescription']);
        self::assertSame(fieldLimit(TAG_FIELDS, 'rawList'), NEWS_FORM_MAX['tags']);
    }

    public function testКнопкиФормыЗнаютОбаСтатуса(): void
    {
        self::assertSame(['draft', 'publish'], array_keys(NEWS_STATUS_BY_INTENT));
        self::assertSame(['DRAFT', 'PUBLISHED'], array_values(NEWS_STATUS_BY_INTENT));
    }

    public function testТекстОтказаСобираетсяИзПределаПоля(): void
    {
        self::assertSame('Заголовок длиннее 120 символов.', newsTooLongText('title'));
        self::assertSame('Рубрика выбрана неверно.', newsTooLongText('categoryId'));
        self::assertSame('Значение поля слишком длинное.', newsTooLongText('несуществующее'));
    }

    public function testНестроковыеПоляФормыСтановятсяПустойСтрокой(): void
    {
        $result = parseNewsForm([
            'title' => 'Заголовок',
            'slug' => ['zagolovok'],
            'categoryId' => 'abc',
            'body' => "Текст\n\nВторой абзац",
            'tags' => ['выпускник', 'выпуск'],
            'status' => ['PUBLISHED'],
            'publishedAt' => '2026-10-04T09:00',
            'pinned' => ['on'],
            'noindex' => ['on'],
        ]);

        self::assertArrayHasKey('data', $result);
        $data = $result['data'];
        self::assertSame([], $data['tags'], 'список тегов из массива пуст');
        self::assertSame('DRAFT', $data['status'], 'статус из массива не публикуется и остаётся черновиком');
        self::assertFalse($data['pinned']);
        self::assertFalse($data['noindex']);
        self::assertSame('', (string) $data['slug'], 'адрес из массива становится пустым, а не словом Array');
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'title' => '  Выпускники   собрались на вечер встречи ',
            'slug' => ' Вечер встречи ',
            'lead' => " Короткий\nлид. ",
            'cover' => '/uploads/news/cover.jpg',
            'categoryId' => 'kategoriya-1',
            'body' => "## Подготовка\nТекст абзаца.",
            'tags' => 'Выпускник, олимпиада',
            'status' => 'PUBLISHED',
            'publishedAt' => '2026-10-07T09:00',
            'pinned' => 'on',
            'seoTitle' => 'Вечер встречи выпускников',
            'seoDescription' => 'Описание для поисковика.',
            'ogImage' => '/uploads/news/og.jpg',
            'noindex' => '',
        ];
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function data(array $form): array
    {
        $result = parseNewsForm($form);

        if (!isset($result['data'])) {
            self::fail('Форма не собралась: ' . json_encode($result['errors'] ?? [], JSON_UNESCAPED_UNICODE));
        }

        return (array) $result['data'];
    }

    /**
     * @param array<string, string> $form
     * @return array<string, string>
     */
    private function errors(array $form): array
    {
        $result = parseNewsForm($form);

        if (!isset($result['errors'])) {
            self::fail('Форма собралась, хотя должна была отказать.');
        }

        return (array) $result['errors'];
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsValidatorsTest`
Expected: FAIL — `Call to undefined function parseBody()`.

- [ ] **Шаг 6: Создать проверку полей**

Создать `apps/news/lib/validators.php`:

```php
<?php

declare(strict_types=1);

// Проверка входящих данных раздела. Сообщения читают люди, поэтому они по-русски и рядом с тем
// полем, которое не прошло. Zod из Next.js-портала заменён явными проверками: у ядра нет
// рантайм-зависимостей, а пределы берутся из тех же словарей, что читает форма.

// Имена полей формы — один список: при отказе в него возвращают введённый текст, а сервер не
// должен гадать, что пришло из браузера. Список читает formValues() из core/form.php, поэтому
// отдельная newsFormValues() разделу не нужна.
/** @var list<string> */
const NEWS_FORM_FIELDS = [
    'title', 'slug', 'lead', 'cover', 'categoryId', 'body', 'tags', 'status',
    'publishedAt', 'pinned', 'seoTitle', 'seoDescription', 'ogImage', 'noindex',
];

// Пределы строк формы. Предела нет у двух полей: `body` режет parseBody() по абзацам, а `slug`
// обрезает slugify() до NEWS_SLUG_MAX — иначе одна и та же граница жила бы в двух местах.
// `categoryId` ужат до 26 символов против 64 в Next.js-портале: столько занимает строковый
// идентификатор из core/id.php, и более длинное значение не найдёт рубрику.
/** @var array<string, int> */
const NEWS_FORM_MAX = [
    'title' => 120,
    'lead' => 300,
    'cover' => 300,
    'categoryId' => 26,
    'tags' => 600,
    'status' => 16,
    'publishedAt' => 32,
    'pinned' => 8,
    'seoTitle' => 120,
    'seoDescription' => 300,
    'ogImage' => 300,
    'noindex' => 8,
];

// Тексты отказа «длиннее предела». Число не вписано руками: оно подставляется из NEWS_FORM_MAX,
// чтобы предел менялся в одном месте и подпись поля не разъезжалась с проверкой. Там, где текст
// в Next.js-портале писал человек, он перенесён дословно.
/** @var array<string, string> */
const NEWS_FORM_TOO_LONG = [
    'title' => 'Заголовок длиннее %d символов.',
    'lead' => 'Лид длиннее %d символов.',
    'cover' => 'Путь к обложке длиннее %d символов.',
    'categoryId' => 'Рубрика выбрана неверно.',
    'tags' => 'Список тегов слишком длинный — оставьте самые важные.',
    'status' => 'Статус материала неизвестен.',
    'publishedAt' => 'Срок публикации нужно выбрать в поле даты и времени — например, 04.10.2026 09:00.',
    'pinned' => 'Закрепление передано неверно.',
    'seoTitle' => 'Title длиннее %d символов.',
    'seoDescription' => 'Description длиннее %d символов.',
    'ogImage' => 'Путь к картинке для анонса длиннее %d символов.',
    'noindex' => 'Запрет индексации передан неверно.',
];

// Кнопки формы уходят отдельным именем `intent`: скрытое поле с текущим статусом приходит первым,
// и чтение `status` вернуло бы его, а не то, что человек нажал.
/** @var array<string, string> */
const NEWS_STATUS_BY_INTENT = ['draft' => NEWS_STATUS['DRAFT'], 'publish' => NEWS_STATUS['PUBLISHED']];

function newsTooLongText(string $field): string
{
    $template = NEWS_FORM_TOO_LONG[$field] ?? '';

    if ($template === '') {
        return 'Значение поля слишком длинное.';
    }

    return str_contains($template, '%d') ? sprintf($template, NEWS_FORM_MAX[$field] ?? 0) : $template;
}

/**
 * Абзац — блок строк, отделённый пустой строкой. Первая строка вида «## Заголовок» задаёт
 * заголовок абзаца: так контент-менеджер видит структуру в обычном textarea, а на странице
 * собирается вёрстка, знакомая по блоку «Абзацы».
 *
 * @return list<array{title: string, text: string}>
 */
function parseBody(string $raw): array
{
    $source = str_replace(["\r\n", "\r"], "\n", $raw);
    $out = [];
    $limit = fieldLimit(NEWS_FIELDS, 'paragraphs');
    $titleMax = fieldLimit(NEWS_FIELDS, 'paragraphTitle', 'max');
    $textMax = fieldLimit(NEWS_FIELDS, 'paragraphText', 'max');

    foreach (preg_split('/\n{2,}/', $source) ?: [] as $block) {
        $lines = explode("\n", (string) $block);

        // Пустые строки в начале блока — следствие того, как человек раскатил текст: они не должны
        // мешать увидеть заголовок абзаца.
        while ($lines !== [] && trim((string) $lines[0]) === '') {
            array_shift($lines);
        }

        $title = '';

        if ($lines !== [] && str_starts_with(trim((string) $lines[0]), '## ')) {
            $line = mb_substr(trim((string) array_shift($lines)), 3);
            $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $line)), 0, $titleMax);
        }

        $joined = (string) preg_replace('/[ \t]+$/m', '', implode("\n", $lines));
        $text = mb_substr(trim($joined), 0, $textMax);

        if ($title === '' && $text === '') {
            continue;
        }

        $out[] = ['title' => $title, 'text' => $text];

        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/**
 * Обратное превращение — для формы: сохранённый JSON возвращается в textarea тем же текстом,
 * из которого он сложился.
 */
function bodyToFormText(mixed $body): string
{
    $blocks = [];

    foreach (is_array($body) ? $body : [] as $raw) {
        $paragraph = is_array($raw) ? $raw : [];
        $title = trim((string) ($paragraph['title'] ?? ''));
        $text = trim((string) ($paragraph['text'] ?? ''));

        if ($title !== '' && $text !== '') {
            $blocks[] = '## ' . $title . "\n" . $text;
        } elseif ($title !== '' || $text !== '') {
            $blocks[] = $title !== '' ? $title : $text;
        }
    }

    return implode("\n\n", $blocks);
}

/**
 * Выделение в тексте задаётся **звёздочками** — сырого HTML в материале нет, поэтому шаблону
 * нужен список частей, а не готовая разметка (печатает его richText() из core/template.php).
 *
 * @return list<array{text: string, strong: bool}>
 */
function splitBold(string $text): array
{
    $out = [];
    $index = 0;

    foreach (preg_split('/\*\*(.+?)\*\*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
        if ($part !== '') {
            $out[] = ['text' => $part, 'strong' => $index % 2 === 1];
        }

        $index++;
    }

    return $out;
}

/**
 * Теги заводятся прямо из формы материала, поэтому список чистится здесь: лишний пробел или
 * повтор не должны превращаться в новый тег в справочнике. Регистр сохраняется как ввёл человек,
 * а сравнивается в нижнем.
 *
 * @return list<string>
 */
function parseTagList(string $raw): array
{
    $seen = [];
    $out = [];
    $min = fieldLimit(TAG_FIELDS, 'label', 'min');
    $max = fieldLimit(TAG_FIELDS, 'label', 'max');
    $perItem = fieldLimit(TAG_FIELDS, 'perItem');

    foreach (explode(',', $raw) as $part) {
        $label = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $part)), 0, $max);

        if (mb_strlen($label) < $min) {
            continue;
        }

        $key = mb_strtolower($label);

        if (in_array($key, $seen, true)) {
            continue;
        }

        $seen[] = $key;
        $out[] = $label;

        if (count($out) >= $perItem) {
            break;
        }
    }

    return $out;
}

/**
 * Поле datetime-local приносит «2026-10-06T09:00» без часового пояса: время понимается как
 * московское — ровно то, о чём договорён контент-менеджер, назначая публикацию «на девять утра».
 * Разбор делает parseFormDateTime() из core/date.php, здесь остаётся проверка формата поля.
 *
 * @return array{date: DateTimeImmutable|null}|array{error: string}
 */
function parsePublishedAt(string $raw): array
{
    $value = trim($raw);

    if ($value === '') {
        return ['date' => null];
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
        return ['error' => newsTooLongText('publishedAt')];
    }

    $date = parseFormDateTime($value);

    if ($date === null) {
        return ['error' => 'Такого момента не бывает — проверьте дату и время.'];
    }

    return ['date' => $date];
}

/**
 * Обратная сторона parsePublishedAt(): сохранённый момент превращается в значение поля
 * datetime-local. Те же московские часы, что читают openGraph и JSON-LD, — иначе редактор открыл
 * бы форму со временем, которое не совпадает с тем, что стоит на сайте.
 */
function formPublishedAt(?DateTimeImmutable $date): string
{
    return $date === null ? '' : substr(moscowIso($date), 0, 16);
}

// Обложка и картинка анонса — только путь внутри сайта: чужой адрес не пройдёт ни политику CSP
// страницы, ни проверку загрузки, а `//host/path` браузер читает как чужой хост. Тот же путь
// не должен уходить и за пределы сайта: `..` и его процент-коды выводят запрос из каталога
// uploads/.
function imagePath(string $raw): ?string
{
    $value = mb_substr(trim($raw), 0, fieldLimit(NEWS_FIELDS, 'image', 'max'));

    if ($value === '' || !str_starts_with($value, '/') || str_starts_with($value, '//')) {
        return null;
    }

    // Управляющие символы и обратный слэш ломают файловые функции, `..` выводит за uploads/,
    // а браузер раскодирует процент-кодированные точки в тот же `..` уже на своей стороне.
    if (preg_match('#[\x00-\x1f\x7f\\\\]#', $value) === 1) {
        return null;
    }

    foreach (explode('/', $value) as $segment) {
        if (in_array(strtolower($segment), ['.', '..', '%2e', '%2e%2e'], true)) {
            return null;
        }
    }

    return $value;
}

function parseQuery(?string $value): string
{
    return mb_substr(trim((string) $value), 0, SEARCH_MAX_LENGTH);
}

function resolveStatus(string $intent, string $fallback): string
{
    $pressed = NEWS_STATUS_BY_INTENT[$intent] ?? '';

    if ($pressed !== '') {
        return $pressed;
    }

    return in_array($fallback, NEWS_STATUS, true) ? $fallback : NEWS_STATUS['DRAFT'];
}

/**
 * Ошибки возвращаются в порядке NEWS_FORM_FIELDS: форма печатает их сверху вниз, а не в порядке
 * проверок.
 *
 * @param array<string, string> $errors
 * @return array<string, string>
 */
function newsFormErrors(array $errors): array
{
    $out = [];

    foreach (NEWS_FORM_FIELDS as $field) {
        if (($errors[$field] ?? '') !== '') {
            $out[$field] = (string) $errors[$field];
        }
    }

    return $out;
}

/**
 * Одна точка входа для формы материала: пределы полей, абзацы, теги, срок и картинки разбираются
 * здесь, а ошибки возвращаются по именам полей.
 *
 * @param array<string, mixed> $raw
 * @return array{data: array<string, mixed>}|array{errors: array<string, string>}
 */
function parseNewsForm(array $raw): array
{
    // Поле пришло из браузера: вместо строки там бывает массив (`tags[]=a` в запросе). formValues()
    // из ядра меняет не-строку на пустую строку — иначе форма печатает предупреждение PHP и
    // сохраняет в поле слово «Array».
    $values = formValues($raw, NEWS_FORM_FIELDS);

    foreach (NEWS_FORM_FIELDS as $field) {
        // `body` не приводится к одной строке и не обрезается: структуру абзацев задают переводы
        // строк, их разбирает parseBody() со своими пределами.
        $values[$field] = $field === 'body' ? $values[$field] : trim($values[$field]);
    }

    // В заголовке и лиде переноса не бывает: они и в карточке, и в поисковике одной строкой.
    $values['title'] = trim((string) preg_replace('/\s+/u', ' ', $values['title']));
    $values['lead'] = trim((string) preg_replace('/\s+/u', ' ', $values['lead']));
    // Адрес чистится тем же транслитом, что и заголовок: slugify() приводит пробелы к «-»,
    // отбрасывает знаки препинания и обрезает до NEWS_SLUG_MAX.
    $values['slug'] = slugify($values['slug']);

    $errors = [];

    foreach (NEWS_FORM_FIELDS as $field) {
        $max = NEWS_FORM_MAX[$field] ?? 0;

        if ($max > 0 && mb_strlen($values[$field]) > $max) {
            $errors[$field] = newsTooLongText($field);
        }
    }

    $titleMin = fieldLimit(NEWS_FIELDS, 'title', 'min');

    if (mb_strlen($values['title']) < $titleMin) {
        $errors['title'] = 'Заголовок короче ' . $titleMin . ' символов — по нему новость находят в ленте и в поиске.';
    }

    if ($values['categoryId'] === '') {
        $errors['categoryId'] = 'Выберите рубрику: без неё новость не попадёт ни в один раздел.';
    }

    // Адрес может остаться пустым: тогда его соберут из заголовка (newsSlugPlan). Но если человек
    // вписал свой, он обязан читаться как ссылка и не занимать служебный сегмент раздела.
    if ($values['slug'] !== '' && mb_strlen($values['slug']) < NEWS_SLUG_MIN) {
        $errors['slug'] = 'Адрес короче ' . NEWS_SLUG_MIN . ' символов — по нему материал открывают по ссылке.';
    }

    if (in_array($values['slug'], NEWS_RESERVED_SLUGS, true)) {
        $errors['slug'] = 'Адрес «' . $values['slug'] . '» занят служебной страницей раздела — выберите другой.';
    }

    $published = parsePublishedAt($values['publishedAt']);

    if (isset($published['error'])) {
        $errors['publishedAt'] = (string) $published['error'];
    }

    if ($errors !== []) {
        return ['errors' => newsFormErrors($errors)];
    }

    return [
        'data' => [
            'title' => $values['title'],
            'slug' => $values['slug'],
            'lead' => $values['lead'] !== '' ? $values['lead'] : null,
            'cover' => imagePath($values['cover']),
            'categoryId' => $values['categoryId'],
            'body' => parseBody($values['body']),
            'tags' => parseTagList($values['tags']),
            'status' => resolveStatus('', $values['status']),
            'publishedAt' => $published['date'] ?? null,
            'pinned' => isOn($raw['pinned'] ?? ''),
            'seoTitle' => $values['seoTitle'],
            'seoDescription' => $values['seoDescription'],
            'ogImage' => imagePath($values['ogImage']),
            'noindex' => isOn($raw['noindex'] ?? ''),
        ],
    ];
}
```

**Почему теги проверяются по длине, но не обрезаются:** длина строки тегов ограничена `NEWS_FORM_MAX['tags']`, но сама строка при этом не обрезается — иначе отказ «список слишком длинный» не сработал бы никогда. Поэтому в проверку длины и в `parseTagList()` идёт одно и то же очищенное значение из `$values`.

- [ ] **Шаг 7: Подключить правила и запустить тесты**

Заменить `apps/news/lib/boot.php` целиком:

```php
<?php

declare(strict_types=1);

// Правила раздела подключаются одним файлом: тесты читают их без HTTP-запроса,
// а routes.php подключит этот же файл, когда появятся контроллеры (задача 16).
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/validators.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsValidatorsTest`
Expected: PASS — 26 проверок.

- [ ] **Шаг 8: Прогнать все наборы, ворота и зафиксировать**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — все четыре набора зелёные.

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки.

```bash
git add apps/news/lib tests/unit/NewsVisibilityTest.php tests/unit/NewsSlugTest.php tests/unit/NewsValidatorsTest.php
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git commit -m "Правила раздела «Новости»: видимость, адреса и проверка полей формы"
```

---
