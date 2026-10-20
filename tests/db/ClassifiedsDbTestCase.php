<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Общая подставка тестов доски: установка раздела, чистка таблиц и фабрики записей.
 * Дерево категорий и объявления проверяются двумя наборами, поэтому подготовка живёт здесь.
 */
abstract class ClassifiedsDbTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropClassifiedsTables();

        $result = installApp('classifieds');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Доска объявлений» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropClassifiedsTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'classifieds'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'classifieds'");
    }

    protected function setUp(): void
    {
        foreach (['classifieds_notification', 'classifieds_report', 'classifieds_message', 'classifieds_favorite', 'classifieds_seller_profile', 'classifieds_listing_attribute', 'classifieds_listing_image', 'classifieds_listing', 'classifieds_attribute', 'classifieds_category', 'classifieds_registration_attempt'] as $table) {
            run('DELETE FROM ' . t($table));
        }

        // Продавцы — строки общей tbl_user: чистим только свою роль, чтобы не задеть
        // редактора, которого могли завести другие наборы.
        run('DELETE FROM ' . t('user') . " WHERE role = 'seller'");
    }

    protected static function dropClassifiedsTables(): void
    {
        // Порядок важен: объявления снимаются раньше категорий, иначе MySQL не даст уронить
        // таблицу, на которую ссылается внешний ключ.
        foreach (['classifieds_notification', 'classifieds_report', 'classifieds_message', 'classifieds_favorite', 'classifieds_seller_profile', 'classifieds_listing_attribute', 'classifieds_listing_image', 'classifieds_listing', 'classifieds_attribute', 'classifieds_category', 'classifieds_registration_attempt'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
    }

    /**
     * Категория строкой: тест чтения обязан класть ровно то состояние, которое проверяет.
     */
    protected static function makeCategory(
        string $slug,
        string $title,
        ?string $parentId = null,
        int $orderIndex = 0,
        int $visible = 1
    ): string {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('classifieds_category', [
            'id' => $id,
            'parent_id' => $parentId,
            'slug' => $slug,
            'title' => $title,
            'order_index' => $orderIndex,
            'visible' => $visible,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }

    /**
     * Объявление строкой в базу с опциями: статус, цена, изображение.
     *
     * @param array<string, mixed> $overrides
     */
    protected static function makeListing(string $ownerId, string $categoryId, array $overrides = []): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('classifieds_listing', array_merge([
            'id' => $id,
            'owner_id' => $ownerId,
            'category_id' => $categoryId,
            'slug' => (string) ($overrides['slug'] ?? 'obyavlenie-' . $id),
            'title' => (string) ($overrides['title'] ?? 'Объявление'),
            'body' => (string) ($overrides['body'] ?? 'Описание объявления.'),
            'price' => $overrides['price'] ?? 1000.00,
            'currency' => 'RUB',
            'negotiable' => (int) ($overrides['negotiable'] ?? 0),
            'location' => $overrides['location'] ?? null,
            'image' => $overrides['image'] ?? null,
            'status' => (string) ($overrides['status'] ?? 'PUBLISHED'),
            'seo_title' => null,
            'seo_description' => null,
            'noindex' => (int) ($overrides['noindex'] ?? 0),
            'created_at' => (string) ($overrides['created_at'] ?? $moment),
            'updated_at' => $moment,
        ], $overrides));

        return $id;
    }

    /** Продавец: строка tbl_user с ролью seller — так кабинет отличает своего от редактора. */
    /**
     * Характеристика категории для фикстур: тип, варианты и флаги — напрямую в таблицу,
     * чтобы проверять значения и ленту, не повторяя админскую форму.
     *
     * @param list<string> $options
     */
    protected static function makeAttribute(
        string $categoryId,
        string $key,
        string $title,
        string $kind = 'text',
        array $options = [],
        int $required = 0,
        int $filterable = 1
    ): string {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('classifieds_attribute', [
            'id' => $id,
            'category_id' => $categoryId,
            'key_slug' => $key,
            'title' => $title,
            'kind' => $kind,
            'unit' => $kind === 'number' ? 'см' : null,
            'option_list' => $options === [] ? null : implode("\n", $options),
            'required' => $required,
            'filterable' => $filterable,
            'order_index' => (int) value(
                'SELECT COALESCE(MAX(order_index), 0) + 1 FROM ' . t('classifieds_attribute') . ' WHERE category_id = ?',
                [$categoryId]
            ),
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }

    protected static function makeSeller(string $email = 'seller@example.ru'): string
    {
        return TestDb::seedUser($email, 'secret-pass-1', 'seller');
    }
}
