<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Заявки на базе: запись, список с фильтром и поиском, статусы, удаление, лимиты и ответ письмом
 * с подставным транспортом. Настоящий SMTP здесь не нужен — письмо подменяется замыканием.
 */
final class LeadsSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('lead_attempt');
        TestDb::clearTable('lead');
    }

    /** @return array{ok: bool, error: string, id: string} */
    private function seedLead(string $contact = '+7 900 000-00-00', string $message = ''): array
    {
        return leadCreate([
            'name' => 'Мария',
            'contact' => $contact,
            'message' => $message,
            'source' => '/o-nas#zayavka',
        ], nowUtc());
    }

    public function testСозданиеЗаявкиПишетСтрокуИПустоеСообщениеОстаётсяНуль(): void
    {
        $created = $this->seedLead();

        self::assertTrue($created['ok']);
        self::assertNotSame('', $created['id']);

        $row = leadRow((string) $created['id']);

        self::assertNotNull($row);
        self::assertSame('Мария', (string) $row['name']);
        self::assertSame('+7 900 000-00-00', (string) $row['contact']);
        self::assertNull($row['message'], 'сообщения нет — в базе NULL, а не пустая строка');
        self::assertSame('/o-nas#zayavka', (string) $row['source']);
        self::assertSame(LEAD_STATUS_NEW, (string) $row['status']);
        self::assertNull($row['done_at']);
    }

    public function testСписокФильтруетПоСтатусуИИщетПоИмениИКонтакту(): void
    {
        $this->seedLead('maria@example.ru', 'Вопрос про группы');
        $done = $this->seedLead('ivan@example.ru', 'Хочу на B1');
        leadSetStatus((string) $done['id'], LEAD_STATUS_DONE, nowUtc());

        $all = leadsPage('ALL', '', 1);
        self::assertSame(2, $all['total']);

        $new = leadsPage(LEAD_STATUS_NEW, '', 1);
        self::assertSame(1, $new['total']);
        self::assertSame('maria@example.ru', (string) $new['rows'][0]['contact']);

        $donePage = leadsPage(LEAD_STATUS_DONE, '', 1);
        self::assertSame(1, $donePage['total']);
        self::assertSame('ivan@example.ru', (string) $donePage['rows'][0]['contact']);

        $found = leadsPage('ALL', 'ivan', 1);
        self::assertSame(1, $found['total']);
        self::assertSame('ivan@example.ru', (string) $found['rows'][0]['contact']);

        $miss = leadsPage('ALL', 'нет-такого', 1);
        self::assertSame(0, $miss['total']);
    }

    public function testСписокСортируетНовыеСверху(): void
    {
        $older = $this->seedLead('old@example.ru');
        run('UPDATE ' . t('lead') . ' SET created_at = ? WHERE id = ?', [utcStamp(shiftSeconds(nowUtc(), -3600)), $older['id']]);

        $newer = $this->seedLead('new@example.ru');

        $rows = leadsPage('ALL', '', 1)['rows'];

        self::assertSame((string) $newer['id'], (string) $rows[0]['id']);
        self::assertSame((string) $older['id'], (string) $rows[1]['id']);
    }

    public function testСтатусПереводитсяТолькоВИзвестныеЗначения(): void
    {
        $lead = $this->seedLead();

        $bad = leadSetStatus((string) $lead['id'], 'ARCHIVED', nowUtc());
        self::assertFalse($bad['ok']);

        $done = leadSetStatus((string) $lead['id'], LEAD_STATUS_DONE, nowUtc());
        self::assertTrue($done['ok']);

        $row = leadRow((string) $lead['id']);
        self::assertSame(LEAD_STATUS_DONE, (string) $row['status']);
        self::assertNotNull($row['done_at']);

        leadSetStatus((string) $lead['id'], LEAD_STATUS_NEW, nowUtc());
        self::assertNull(leadRow((string) $lead['id'])['done_at'], 'возврат в новые снимает время обработки');
    }

    public function testУдалениеЗаявкиУноситСтроку(): void
    {
        $lead = $this->seedLead();

        self::assertTrue(leadDelete((string) $lead['id'])['ok']);
        self::assertNull(leadRow((string) $lead['id']));
        self::assertFalse(leadDelete((string) $lead['id'])['ok'], 'повторное удаление призрака отказывает');
    }

    public function testЛимитПриёмаПропускаетПятьИОтсекаетШестую(): void
    {
        $now = nowUtc();

        for ($i = 0; $i < LEAD_RATE_LIMIT; $i++) {
            self::assertFalse(leadRateTooMany('ip:1.2.3.4', LEAD_RATE_LIMIT, LEAD_RATE_WINDOW_MINUTES, $now), 'Попытка ' . ($i + 1) . ' в окне разрешена');
        }

        self::assertTrue(leadRateTooMany('ip:1.2.3.4', LEAD_RATE_LIMIT, LEAD_RATE_WINDOW_MINUTES, $now), 'шестая попытка в окне отсекается');

        $later = shiftSeconds($now, LEAD_RATE_WINDOW_MINUTES * 60 + 1);
        self::assertFalse(leadRateTooMany('ip:1.2.3.4', LEAD_RATE_LIMIT, LEAD_RATE_WINDOW_MINUTES, $later), 'новое окно открывает счётчик заново');
    }

    public function testОтветПисьмомОтправляетИОтмечаетОбработанной(): void
    {
        $lead = $this->seedLead('maria@example.ru');
        $sent = [];

        $result = leadReplySend(
            (string) $lead['id'],
            'Спасибо за вопрос, Мария!',
            'admin@school.dev',
            static function (array $message) use (&$sent): array {
                $sent[] = $message;

                return ['ok' => true, 'error' => ''];
            },
            nowUtc()
        );

        self::assertTrue($result['ok']);
        self::assertCount(1, $sent);
        self::assertSame('maria@example.ru', (string) $sent[0]['to']);
        self::assertStringContainsString('Ответ школы', (string) $sent[0]['subject']);
        self::assertStringContainsString('Спасибо за вопрос', (string) $sent[0]['text']);

        $row = leadRow((string) $lead['id']);
        self::assertSame(LEAD_STATUS_DONE, (string) $row['status']);
        self::assertSame('Спасибо за вопрос, Мария!', (string) $row['reply_text']);
        self::assertNotNull($row['reply_sent_at']);
        self::assertNotNull($row['done_at']);
    }

    public function testОтветНаТелефонОтказываетБезОтправки(): void
    {
        $lead = $this->seedLead('+7 900 000-00-00');

        $result = leadReplySend(
            (string) $lead['id'],
            'Спасибо за вопрос!',
            'admin@school.dev',
            static function (array $message): array {
                throw new \RuntimeException('Письмо не должно уходить на телефон');
            },
            nowUtc()
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString('телефон', $result['error']);
        self::assertSame(LEAD_STATUS_NEW, (string) leadRow((string) $lead['id'])['status'], 'отказ письма не меняет статус');
    }

    public function testСлишкомКороткийОтветОтказываетДоОтправки(): void
    {
        $lead = $this->seedLead('maria@example.ru');

        $result = leadReplySend(
            (string) $lead['id'],
            'коротко',
            'admin@school.dev',
            static function (array $message): array {
                throw new \RuntimeException('Короткий ответ не должен уходить');
            },
            nowUtc()
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString((string) LEAD_REPLY_MIN, $result['error']);
    }

    public function testЛимитОтветаОтсекаетЧетвёртоеПисьмоЗаДесятьМинут(): void
    {
        $lead = $this->seedLead('maria@example.ru');
        $now = nowUtc();
        $send = static fn (array $message): array => ['ok' => true, 'error' => ''];

        for ($i = 0; $i < LEAD_REPLY_LIMIT; $i++) {
            self::assertTrue(leadReplySend((string) $lead['id'], 'Текст ответа длинный', 'admin@school.dev', $send, $now)['ok'], 'Ответ ' . ($i + 1) . ' уходит');
        }

        $blocked = leadReplySend((string) $lead['id'], 'Текст ответа длинный', 'admin@school.dev', $send, $now);

        self::assertFalse($blocked['ok']);
        self::assertStringContainsString('десять минут', $blocked['error']);
    }
}
