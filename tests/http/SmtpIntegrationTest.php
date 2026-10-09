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
                return $this->withContentType($item);
            }
        }

        return null;
    }

    /**
     * Ни список поиска, ни карточка письма в mailpit не отдают заголовок Content-Type: его берём
     * из исходного текста письма (/api/v1/message/<id>/raw) и кладём в ключ ContentType, которым
     * пользуется проверка выше. Без этого проверка сравнивала бы пустую строку и не видела бы,
     * что письмо пришло именно двухчастным.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function withContentType(array $item): array
    {
        $rawUrl = rtrim(self::$mailpit, '/')
            . '/api/v1/message/' . rawurlencode((string) ($item['ID'] ?? '')) . '/raw';
        $raw = @file_get_contents($rawUrl, false, stream_context_create(['http' => ['timeout' => 10]]));

        $item['ContentType'] = $raw === false || preg_match('/^Content-Type:[ \t]*([^\r\n]+)/mi', (string) $raw, $match) !== 1
            ? ''
            : trim($match[1]);

        return $item;
    }
}
