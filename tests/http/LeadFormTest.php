<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Публичный приём заявок настоящим запросом: POST /lead, ловушка, валидация, лимит и рендер
 * формы секции «lead» на странице. Письма на телефоне не отправляются, поэтому почтовый контур
 * в этих проверках не участвует.
 */
final class LeadFormTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        $page = createPage([
            'title' => 'Заявка',
            'slug' => 'zayavka',
            'description' => '',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '0',
        ], nowUtc());

        createSection((string) $page['id'], [
            'kind' => 'lead',
            'anchor' => 'forma',
            'badge' => 'Запись',
            'title' => 'Оставьте заявку',
            'lead_text' => 'Имя и контакт — остальное школа уточнит сама.',
            'note' => 'Ответим в будни',
            'image' => '',
            'image_alt' => '',
            'layout' => '',
            'buttons' => [],
            'items' => [['text' => 'Пробный урок'], ['text' => 'Подбор группы']],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        TestDb::clearTable('lead_attempt');
        TestDb::clearTable('lead');
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private function tokenPage(): array
    {
        return Kernel::get('/login');
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function postLead(array $fields): array
    {
        $page = $this->tokenPage();

        return Kernel::post('/lead', array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    public function testФормаСекцииПечатаетсяНаСтранице(): void
    {
        $page = Kernel::get('/zayavka');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Оставьте заявку', $page['body']);
        self::assertStringContainsString('Имя и контакт — остальное школа уточнит сама.', $page['body']);
        self::assertStringContainsString('action="/lead"', $page['body'], 'форма уходит на маршрут приёма');
        self::assertStringContainsString('name="_token"', $page['body'], 'публичная форма несёт CSRF-токен');
        self::assertStringContainsString('name="company"', $page['body'], 'ловушка для ботов скрыта в форме');
        self::assertStringContainsString('value="/zayavka#forma"', $page['body'], 'источник заявки собран из адреса страницы и якоря');
        self::assertStringContainsString('name="name"', $page['body']);
        self::assertStringContainsString('name="contact"', $page['body']);
        self::assertStringContainsString('name="message"', $page['body']);
        self::assertStringContainsString('Согласие на обработку персональных данных', $page['body']);
        self::assertStringContainsString('min-h-[44px]', $page['body'], 'поля и кнопки формы тапабельные');
    }

    public function testДействующаяЗаявкаПишетсяИВозвращаетПодтверждение(): void
    {
        $response = $this->postLead([
            'name' => 'Мария',
            'contact' => '+7 900 000-00-00',
            'message' => 'Хочу на B1',
            'source' => '/zayavka#forma',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=lead_sent', $response['headers']['location']);
        self::assertStringContainsString('#forma', $response['headers']['location'], 'человек остаётся у формы');

        $row = one('SELECT name, contact, message, source, status FROM ' . t('lead') . ' LIMIT 1');

        self::assertNotNull($row);
        self::assertSame('Мария', (string) $row['name']);
        self::assertSame('+7 900 000-00-00', (string) $row['contact']);
        self::assertSame('Хочу на B1', (string) $row['message']);
        self::assertSame('/zayavka#forma', (string) $row['source']);
        self::assertSame(LEAD_STATUS_NEW, (string) $row['status']);
    }

    public function testЛовушкаОтвечаетТемЖеСпасибоИНичегоНеПишет(): void
    {
        $response = $this->postLead([
            'name' => 'Бот',
            'contact' => 'bot@example.ru',
            'source' => '/zayavka#forma',
            'company' => 'ООО Ромашка',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=lead_sent', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('lead')), 'ловушка заявку не пишет');
    }

    public function testКривойКонтактОтказываетИНеПишет(): void
    {
        $response = $this->postLead([
            'name' => 'Мария',
            'contact' => 'позвоните',
            'source' => '/zayavka#forma',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=lead_failed', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('lead')));
    }

    public function testЛимитПропускаетПятьИОтсекаетШестую(): void
    {
        for ($i = 0; $i < LEAD_RATE_LIMIT; $i++) {
            $response = $this->postLead([
                'name' => 'Мария',
                'contact' => '+7 900 000-00-0' . $i,
                'source' => '/zayavka#forma',
            ]);

            self::assertSame(302, $response['status']);
            self::assertStringContainsString('notice=lead_sent', $response['headers']['location']);
        }

        $sixth = $this->postLead([
            'name' => 'Мария',
            'contact' => '+7 900 000-00-05',
            'source' => '/zayavka#forma',
        ]);

        self::assertStringContainsString('notice=lead_failed', $sixth['headers']['location']);
        self::assertSame(LEAD_RATE_LIMIT, (int) value('SELECT COUNT(*) FROM ' . t('lead')));
    }

    public function testЧужойТокенОтвергается(): void
    {
        $response = Kernel::post('/lead', [
            '_token' => str_repeat('0', 64),
            'name' => 'Мария',
            'contact' => '+7 900 000-00-00',
            'source' => '/zayavka#forma',
        ]);

        self::assertSame(403, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('lead')));
    }
}
