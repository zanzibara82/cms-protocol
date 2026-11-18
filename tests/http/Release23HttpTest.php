<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.3 настоящими запросами: вход и права, CSRF, создание типа и записи, публикация,
 * черновик-предпросмотр и 404 неизвестного раздела. Доменные сценарии проверены в db-наборе.
 */
final class Release23HttpTest extends TestCase
{
    private const PASSWORD = 'длинный-тестовый-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser('admin23@school.dev', self::PASSWORD, 'admin');
        TestDb::seedUser('editor23@school.dev', self::PASSWORD, 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach (['content_entry_relation', 'content_entry_old_slug', 'content_entry', 'content_field', 'content_type_old_slug', 'content_type', 'global_block', 'content_revisions', 'content_heads', 'audit_log'] as $table) {
            TestDb::clearTable($table);
        }
    }

    public function testАнонимНеПопадаетВУправлениеКонтентом(): void
    {
        foreach (['/admin/content', '/admin/content/types/new', '/admin/content/blocks'] as $path) {
            $page = Kernel::get($path);
            self::assertSame(302, $page['status'], 'Аноним получает редирект с ' . $path);
            self::assertStringContainsString('/login', (string) ($page['headers']['location'] ?? ''));
        }
    }

    public function testРедакторВидитКонтентНоНеУправляетТипами(): void
    {
        $this->enter('editor23@school.dev');

        self::assertSame(200, Kernel::get('/admin/content')['status']);
        self::assertSame(403, Kernel::get('/admin/content/types/new')['status'], 'Типами управляет только администратор');
    }

    public function testНеизвестныеПубличныеАдресаОтвечают404(): void
    {
        self::assertSame(404, Kernel::get('/net-type')['status']);
        self::assertSame(404, Kernel::get('/net-type/zapis')['status']);
    }

    public function testТипИЗаписьПроходятПолныйПутьОтЧерновикаДоПубликации(): void
    {
        $this->enter();

        $form = Kernel::get('/admin/content/types/new');
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('Поля типа', $form['body']);

        $created = Kernel::post('/admin/content/types/new', [
            '_token' => Kernel::tokenFrom($form['body']),
            'op' => 'save-type',
            'name' => 'Статьи',
            'slug' => 'statyi',
            'description' => 'Раздел статей',
            'sort' => '1',
            'field_label[0]' => 'Описание',
            'field_key[0]' => 'opisanie',
            'field_original[0]' => '',
            'field_kind[0]' => CONTENT_FIELD_TEXT,
            'field_required[0]' => '1',
            'field_hint[0]' => '',
            'field_options[0]' => '',
        ]);
        self::assertSame(302, $created['status']);
        $location = (string) ($created['headers']['location'] ?? '');
        self::assertStringContainsString('notice=content_type_created', $location);
        preg_match('~/admin/content/types/([^?]+)~', $location, $matches);
        $typeId = $matches[1] ?? '';
        self::assertNotSame('', $typeId);

        $newForm = Kernel::get('/admin/content/types/' . rawurlencode($typeId) . '/entries/new');
        self::assertSame(200, $newForm['status']);
        self::assertStringContainsString('Описание', $newForm['body']);
        self::assertStringContainsString('enctype="multipart/form-data"', $newForm['body'], 'Форма поля-картинки обязана уметь принимать файл');

        $entrySaved = Kernel::post('/admin/content/types/' . rawurlencode($typeId) . '/entries/new', [
            '_token' => Kernel::tokenFrom($newForm['body']),
            'title' => 'Первая статья',
            'slug' => 'pervaya-statya',
            'fields[opisanie]' => 'Текст первой статьи',
        ]);
        self::assertSame(302, $entrySaved['status']);
        self::assertStringContainsString('notice=content_entry_created', (string) ($entrySaved['headers']['location'] ?? ''));
        preg_match('~/admin/content/entries/([^?]+)~', (string) ($entrySaved['headers']['location'] ?? ''), $entryMatches);
        $entryId = $entryMatches[1] ?? '';
        self::assertNotSame('', $entryId);

        // Черновик гостю недоступен; сотрудник видит его как предпросмотр с noindex.
        Kernel::freshCookies();
        self::assertSame(404, Kernel::get('/statyi/pervaya-statya')['status']);

        $this->enter();
        $draft = Kernel::get('/statyi/pervaya-statya');
        self::assertSame(200, $draft['status']);
        self::assertStringContainsString('Предпросмотр черновика', $draft['body']);
        self::assertStringContainsString('content="noindex, nofollow"', $draft['body']);

        $statusForm = Kernel::get('/admin/content/entries/' . rawurlencode($entryId));
        $published = Kernel::post('/admin/content/entries/' . rawurlencode($entryId), [
            '_token' => Kernel::tokenFrom($statusForm['body']),
            'op' => 'status',
            'status' => CONTENT_STATUS_PUBLISHED,
        ]);
        self::assertSame(302, $published['status']);
        self::assertStringContainsString('notice=content_entry_published', (string) ($published['headers']['location'] ?? ''));

        Kernel::freshCookies();
        $public = Kernel::get('/statyi/pervaya-statya');
        self::assertSame(200, $public['status']);
        self::assertStringContainsString('Текст первой статьи', $public['body']);

        $list = Kernel::get('/statyi');
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('Первая статья', $list['body']);
    }

    private function enter(string $email = 'admin23@school.dev'): void
    {
        Kernel::freshCookies();
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
    }
}
