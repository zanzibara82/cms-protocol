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

        self::assertStringContainsString('Date: ', $raw);
        self::assertStringContainsString(" +0000\r\n", $raw);
        self::assertStringContainsString('From: <' . configValue('MAIL_FROM') . ">\r\n", $raw);
        self::assertStringContainsString("To: <uchenik@example.com>\r\n", $raw);
        self::assertStringContainsString('Subject: =?UTF-8?B?', $raw);
        self::assertStringContainsString('Message-ID: <', $raw);
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
            'перевод строки в адресе отправителя' => [
                ['to' => 'a@b.ru', 'subject' => 'Тема', 'text' => 'Текст', 'from' => "c@d.ru\r\nBcc: vsem@b.ru"],
                'В адресе отправителя есть перевод строки.',
            ],
            'управляющий символ в адресе получателя' => [
                ['to' => "a@b\x01.ru", 'subject' => 'Тема', 'text' => 'Текст'],
                'В адресе получателя есть управляющий символ.',
            ],
            'управляющий символ в теме' => [
                ['to' => 'a@b.ru', 'subject' => "Тема\x1b[31m", 'text' => 'Текст'],
                'В теме письма есть управляющий символ.',
            ],
            'управляющий символ в адресе отправителя' => [
                ['to' => 'a@b.ru', 'subject' => 'Тема', 'text' => 'Текст', 'from' => "c@d\x7f.ru"],
                'В адресе отправителя есть управляющий символ.',
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
        // В dev-контейнере сервис `php` получает CMS_SMTP_HOST=mailpit из docker-compose.dev.yml,
        // и здесь проверка пропускается. Вне контейнера (прогон без этой переменной окружения)
        // SMTP_HOST остаётся пустым из config.sample.php, и отказ проверяется по-настоящему.
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

    public function testПрямаяСборкаПисьмаНеДобавляетЛишнихЗаголовков(): void
    {
        // send() отклоняет такое письмо раньше, здесь проверяется сам сборщик: вызов render()
        // напрямую не обязан полагаться на стоящую впереди проверку.
        $raw = (new Mailer())->render([
            'to' => "a@b.ru\r\nBcc: vsem@b.ru",
            'subject' => "Тема\r\nBcc: vsem@b.ru",
            'text' => 'Текст',
            'from' => "c@d.ru\r\nBcc: vsem@b.ru",
            'replyTo' => "e@f.ru\r\nBcc: vsem@b.ru",
        ]);
        $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n"));

        self::assertSame(1, preg_match('/^To: /m', $head));
        self::assertSame(1, preg_match('/^From: /m', $head));
        self::assertSame(1, preg_match('/^Reply-To: /m', $head));
        self::assertSame(0, preg_match('/^Bcc: /m', $head));
        // Перевод строки заменён пробелом: внедрённая строка остаётся текстом внутри одного заголовка.
        self::assertMatchesRegularExpression('/^To: <a@b\.ru +Bcc: vsem@b\.ru>\r$/m', $head);
    }

    public function testКонвертБерётАдресАНеВсёСодержимоеЗаголовка(): void
    {
        // `deliver()` строит `MAIL FROM:` и `RCPT TO:` из заголовков собранного письма. Если в
        // заголовок попала вставленная строка (её чистит `headerText()`, но `render()` публичный),
        // в конверт обязан уйти только адрес: сервер читает конверт как одно значение, и «адрес
        // + пробел + что-то ещё» для него — уже чужой адрес.
        self::assertSame('a@b.ru', Mailer::addressOf('<a@b.ru  Bcc: vsem@b.ru>'));
        self::assertSame('a@b.ru', Mailer::addressOf('a@b.ru  Bcc: vsem@b.ru'));
        self::assertSame('a@b.ru', Mailer::addressOf(' Имя <a@b.ru>'));
        self::assertSame('a@b.ru', Mailer::addressOf('  <a@b.ru>  '));
        self::assertSame('a@b.ru', Mailer::addressOf('a@b.ru'));
    }

    private function bodyOf(string $raw): string
    {
        return trim(substr($raw, (int) strpos($raw, "\r\n\r\n") + 4));
    }

    private function partOf(string $raw, int $index): string
    {
        $parts = preg_split("/\r\n--cms-[0-9a-f]+\r\n/", $raw) ?: [];
        $chunk = $parts[$index + 1] ?? '';
        // Закрывающая граница («--cms-…--») не входит в шаблон разрезания: над ней стоит «--»,
        // а не перевод строки. Её надо снять, иначе base64_decode в строгом режиме отвергает
        // дефисы и возвращает false — часть письма не проверялась бы вовсе.
        $chunk = (string) preg_replace("/\r\n--cms-[0-9a-f]+--.*$/s", '', $chunk);

        return trim(substr($chunk, (int) strpos($chunk, "\r\n\r\n") + 4));
    }
}
