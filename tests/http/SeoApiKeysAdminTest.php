<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран «API-ключи» через настоящий HTTP (S6, спецификация §8/§18): право доступа, список, создание
 * с единственным показом секрета, отзыв и предел числа ключей.
 *
 * Набор держит обещания экрана: ключами распоряжается владелец, а не редактор; полный ключ
 * печатается ровно один раз и в базе лежит только хешем; отозванный ключ виден в списке, но
 * кнопки отзыва больше не имеет.
 */
final class SeoApiKeysAdminTest extends TestCase
{
    private const OWNER_EMAIL = 'seo-keys-owner@school.dev';
    private const EDITOR_EMAIL = 'seo-keys-editor@school.dev';
    private const PASSWORD = 'длинный-тестовый-пароль';

    private const PATH = '/seo/admin/api';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::OWNER_EMAIL, self::PASSWORD, ROLES['admin']);
        TestDb::seedUser(self::EDITOR_EMAIL, self::PASSWORD, 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        run('DROP TABLE IF EXISTS ' . t('seo_api_key'));

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        run('DELETE FROM ' . t('seo_api_key'));
    }

    private function login(string $email): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * POST с токеном настоящей формы экрана.
     *
     * @param array<string, string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(array $fields): array
    {
        $page = Kernel::get(self::PATH);

        return Kernel::post(self::PATH, array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    private function secretFrom(string $body): string
    {
        self::assertSame(1, preg_match('/(seoa_[0-9a-f]{40})/', $body, $matches), 'Экран создания обязан напечатать полный ключ');

        return $matches[1];
    }

    /**
     * Создание ключа целиком: POST уходит редиректом, секрет печатает следующий GET.
     */
    private function createKey(string $label): string
    {
        $response = $this->press(['op' => 'key_create', 'label' => $label]);

        self::assertSame(302, $response['status'], 'Создание уходит на GET-экран: обновление не повторит POST');
        self::assertStringContainsString('notice=api_key_created', $response['headers']['location']);

        return $this->secretFrom(Kernel::get(self::PATH)['body']);
    }

    // ── Право доступа ──────────────────────────────────────

    public function testАнонимУходитНаВход(): void
    {
        $page = Kernel::get(self::PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Fapi', $page['headers']['location']);
    }

    public function testРедакторуЭкранЗакрыт(): void
    {
        $this->login(self::EDITOR_EMAIL);

        self::assertSame(403, Kernel::get(self::PATH)['status'], 'Ключи дают доступ к отчётам — редактор ими не распоряжается');
        self::assertSame(403, $this->press(['op' => 'key_create', 'label' => 'Чужой'])['status'], 'Приватный POST отвечает отказом, а не редиректом');
    }

    // ── Экран ──────────────────────────────────────────────

    public function testВладелецВидитЭкранСоздания(): void
    {
        $this->login(self::OWNER_EMAIL);
        $page = Kernel::get(self::PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('API-ключи', $page['body']);
        self::assertStringContainsString('Создать ключ', $page['body']);
        self::assertStringContainsString('Ключей ещё нет.', $page['body']);
        self::assertStringContainsString('Bearer', $page['body'], 'Экран называет схему передачи ключа');
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page['body']);
        self::assertSame('no-store', $page['headers']['cache-control'] ?? '');
    }

    // ── Создание и единственный показ ──────────────────────

    public function testСозданиеПечатаетСекретИКладётВБазуХеш(): void
    {
        $this->login(self::OWNER_EMAIL);

        $created = $this->press(['op' => 'key_create', 'label' => 'Кабинет отчётов']);
        self::assertSame(302, $created['status'], 'После создания — PRG: обновление не повторяет POST');

        $page = Kernel::get(self::PATH);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Ключ создан', $page['body']);

        $secret = $this->secretFrom($page['body']);

        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('seo_api_key') . ' WHERE key_hash = ?',
            [hash('sha256', $secret)]
        ), 'В базе лежит хеш ключа');
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('seo_api_key') . ' WHERE key_hash = ? OR key_prefix = ?',
            [$secret, $secret]
        ), 'Полного ключа в базе нет ни в одной колонке');
        self::assertStringContainsString($secret, $page['body']);
    }

    public function testОбновлениеЭкранаНеСоздаётВторойКлюч(): void
    {
        $this->login(self::OWNER_EMAIL);

        $this->press(['op' => 'key_create', 'label' => 'Кабинет отчётов']);

        $first = Kernel::get(self::PATH);
        self::assertStringContainsString('Ключ создан', $first['body']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key'), []));

        $second = Kernel::get(self::PATH);

        self::assertSame(0, preg_match('/(seoa_[0-9a-f]{40})/', $second['body']), 'Обновление не возвращает секрет: он показывается один раз');
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key'), []), 'Обновление не создаёт второй ключ');
    }

    public function testСписокПоказываетТолькоПрефикс(): void
    {
        $this->login(self::OWNER_EMAIL);

        $secret = $this->createKey('Кабинет отчётов');
        $page = Kernel::get(self::PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кабинет отчётов', $page['body']);
        self::assertStringContainsString(e(substr($secret, 0, 12)) . '…', $page['body'], 'Экран показывает префикс с многоточием');
        self::assertStringNotContainsString($secret, $page['body'], 'Вернуться к полному ключу нельзя');
        self::assertStringContainsString('не использовался', $page['body']);
        self::assertStringContainsString('действует', $page['body']);
    }

    public function testБезПодписиКлючНеСоздаётся(): void
    {
        $this->login(self::OWNER_EMAIL);

        $response = $this->press(['op' => 'key_create', 'label' => '   ']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString(self::PATH, $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key'), []));

        $page = Kernel::get(self::PATH);
        self::assertStringContainsString('Подпишите ключ', $page['body'], 'Причина отказа видна человеку');
    }

    // ── Предел и отзыв ─────────────────────────────────────

    public function testПределКлючейСообщаетПричину(): void
    {
        for ($i = 1; $i <= SEO_API_KEY_MAX; $i++) {
            self::assertTrue(seoApiKeyCreate('Ключ ' . $i)['ok']);
        }

        $this->login(self::OWNER_EMAIL);

        $response = $this->press(['op' => 'key_create', 'label' => 'Сверх предела']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString(self::PATH, $response['headers']['location']);
        self::assertSame(SEO_API_KEY_MAX, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key'), []), 'Сверх предела ключ не заводится');

        $page = Kernel::get(self::PATH);
        self::assertStringContainsString('отзовите один', $page['body'], 'Отказ называет выход: отозвать один');
    }

    public function testНеизвестноеДействиеНеОтзываетКлюч(): void
    {
        $created = seoApiKeyCreate('Живой ключ');
        self::assertTrue($created['ok'], $created['error']);

        $this->login(self::OWNER_EMAIL);

        $response = $this->press(['op' => 'key_чужое', 'id' => $created['id']]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString(self::PATH, $response['headers']['location']);
        self::assertNull(
            value('SELECT revoked_at FROM ' . t('seo_api_key') . ' WHERE id = ?', [$created['id']]),
            'Опечатка в op не должна отзывать ключ'
        );

        $page = Kernel::get(self::PATH);
        self::assertStringContainsString('Неизвестное действие', $page['body'], 'Причина отказа видна человеку');
    }

    public function testОтзывПишетУведомлениеИГаситКнопку(): void
    {
        $this->login(self::OWNER_EMAIL);

        $secret = $this->createKey('Кабинет отчётов');
        $id = (string) value('SELECT id FROM ' . t('seo_api_key') . ' WHERE key_hash = ?', [hash('sha256', $secret)]);

        $response = $this->press(['op' => 'key_revoke', 'id' => $id]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=api_key_revoked', $response['headers']['location']);

        $page = Kernel::get(self::PATH);

        self::assertStringContainsString('Кабинет отчётов', $page['body'], 'Отозванный ключ остаётся в списке');
        self::assertStringContainsString('отозван', $page['body']);
        self::assertStringNotContainsString('name="op" value="key_revoke"', $page['body'], 'Отозванный ключ нельзя отозвать ещё раз');
    }

    public function testЧужойТокенНеСоздаётКлюч(): void
    {
        $this->login(self::OWNER_EMAIL);

        $response = Kernel::post(self::PATH, [
            '_token' => str_repeat('a', 64),
            'op' => 'key_create',
            'label' => 'Без своего токена',
        ]);

        self::assertSame(403, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_api_key'), []));
    }
}
