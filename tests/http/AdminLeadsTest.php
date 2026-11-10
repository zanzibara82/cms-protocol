<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Админка заявок настоящим запросом: доступ только администратора, список с фильтрами, смена
 * статуса, удаление и ответ письмом (без SMTP ответ на почту честно отказывает — почтовый контур
 * в этих проверках не поднимается).
 */
final class AdminLeadsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser('admin@school.dev', 'длинный-тестовый-пароль', 'admin');
        TestDb::seedUser('editor@school.dev', 'длинный-тестовый-пароль', 'editor');

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

    private function enter(string $email): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => 'длинный-тестовый-пароль',
        ]);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(array $fields): array
    {
        $page = Kernel::get('/admin/leads');

        return Kernel::post('/admin/leads', array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    private function seedLead(string $contact = '+7 900 000-00-00', string $message = ''): string
    {
        $created = leadCreate([
            'name' => 'Мария',
            'contact' => $contact,
            'message' => $message,
            'source' => '/zayavka#forma',
        ], nowUtc());

        return (string) $created['id'];
    }

    public function testАнонимПолучаетРедиректНаВход(): void
    {
        $page = Kernel::get('/admin/leads');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login', $page['headers']['location']);
    }

    public function testРедакторНеВидитЗаявки(): void
    {
        $this->enter('editor@school.dev');

        self::assertSame(403, Kernel::get('/admin/leads')['status']);
    }

    public function testАдминистраторВидитСписокИФильтры(): void
    {
        $this->seedLead('maria@example.ru', 'Вопрос про группы');
        $this->enter('admin@school.dev');

        $page = Kernel::get('/admin/leads');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Заявки с публичных страниц (1)', $page['body']);
        self::assertStringContainsString('Мария', $page['body']);
        self::assertStringContainsString('maria@example.ru', $page['body']);
        self::assertStringContainsString('Вопрос про группы', $page['body']);
        self::assertStringContainsString('/zayavka#forma', $page['body']);
        self::assertStringContainsString('>Новые<', $page['body']);
        self::assertStringContainsString('>Обработанные<', $page['body']);
        self::assertStringContainsString('no-store', $page['headers']['cache-control']);
    }

    public function testСменаСтатусаНаОбработаннуюИОбратно(): void
    {
        $id = $this->seedLead();
        $this->enter('admin@school.dev');

        $done = $this->press(['op' => 'set-status', 'lead' => $id, 'target' => LEAD_STATUS_DONE, 'status' => LEAD_STATUS_NEW, 'q' => '']);

        self::assertStringContainsString('notice=lead_status_saved', $done['headers']['location']);
        self::assertSame(LEAD_STATUS_DONE, (string) value('SELECT status FROM ' . t('lead') . ' WHERE id = ?', [$id]));
        self::assertNotNull(value('SELECT done_at FROM ' . t('lead') . ' WHERE id = ?', [$id]));

        $back = $this->press(['op' => 'set-status', 'lead' => $id, 'target' => LEAD_STATUS_NEW, 'status' => LEAD_STATUS_DONE, 'q' => '']);

        self::assertStringContainsString('notice=lead_status_saved', $back['headers']['location']);
        self::assertSame(LEAD_STATUS_NEW, (string) value('SELECT status FROM ' . t('lead') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT done_at FROM ' . t('lead') . ' WHERE id = ?', [$id]));
    }

    public function testУдалениеЗаявки(): void
    {
        $id = $this->seedLead();
        $this->enter('admin@school.dev');

        $deleted = $this->press(['op' => 'delete', 'lead' => $id, 'status' => LEAD_STATUS_NEW, 'q' => '']);

        self::assertStringContainsString('notice=lead_deleted', $deleted['headers']['location']);
        self::assertNull(leadRow($id));
    }

    public function testОтветНаТелефонОтказываетСЧеловеческойПричиной(): void
    {
        $id = $this->seedLead('+7 900 000-00-00');
        $this->enter('admin@school.dev');

        $response = $this->press(['op' => 'reply', 'lead' => $id, 'text' => 'Спасибо за вопрос!', 'status' => LEAD_STATUS_NEW, 'q' => '']);

        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);

        $page = Kernel::get('/admin/leads?notice=migration-failed');
        self::assertStringContainsString('Письмо некуда отправить', $page['body'], 'причина отказа печатается из flash');
        self::assertSame(LEAD_STATUS_NEW, (string) value('SELECT status FROM ' . t('lead') . ' WHERE id = ?', [$id]));
    }

    public function testОтветНаПочтуБезНастроеннойПочтыЧестноОтказывет(): void
    {
        $id = $this->seedLead('maria@example.ru');
        $this->enter('admin@school.dev');

        $response = $this->press(['op' => 'reply', 'lead' => $id, 'text' => 'Спасибо за вопрос, Мария!', 'status' => LEAD_STATUS_NEW, 'q' => '']);

        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);

        $page = Kernel::get('/admin/leads?notice=migration-failed');
        self::assertStringContainsString('Письмо не ушло', $page['body'], 'без SMTP отказ честный, а не молчаливый');
        self::assertSame(LEAD_STATUS_NEW, (string) value('SELECT status FROM ' . t('lead') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT reply_text FROM ' . t('lead') . ' WHERE id = ?', [$id]), 'неотправленный текст в заявку не пишется');
    }

    public function testНеизвестноеДействиеОтказывается(): void
    {
        $this->seedLead();
        $this->enter('admin@school.dev');

        $response = $this->press(['op' => 'сломать', 'lead' => '', 'status' => LEAD_STATUS_NEW, 'q' => '']);

        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);

        $page = Kernel::get('/admin/leads?notice=migration-failed');
        self::assertStringContainsString('Неизвестное действие.', $page['body']);
    }
}
