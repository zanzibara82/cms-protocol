<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/** RELEASE 2.3: чистые правила адресов, ключей полей, ссылок и перечней — база не нужна. */
final class Release23ContentRulesTest extends TestCase
{
    public function testАдресТипаНеЗанимаетСлужебныйРаздел(): void
    {
        $plan = contentTypeSlugPlan('admin', 'Админка', static fn (): bool => false);
        self::assertSame('', $plan['slug']);
        self::assertStringContainsString('служебным', $plan['error']);

        $plan = contentTypeSlugPlan('', 'Статьи', static fn (): bool => false);
        self::assertSame('stati', $plan['slug']);
        self::assertSame('', $plan['error']);
    }

    public function testАдресЗаписиСобираетсяИзЗаголовкаИУникаленВнутриТипа(): void
    {
        $taken = ['pervaya-zapis'];
        $exists = static fn (string $slug): bool => in_array($slug, $taken, true);

        $plan = contentEntrySlugPlan('', 'Первая запись', $exists);
        self::assertSame('pervaya-zapis-2', $plan['slug']);
        self::assertSame('', $plan['error']);

        $plan = contentEntrySlugPlan('mozhno-vruchnuyu', 'Первая запись', $exists);
        self::assertSame('mozhno-vruchnuyu', $plan['slug']);
    }

    public function testКлючПоляНеРазрешаетПосторонниеСимволы(): void
    {
        self::assertSame('field_one', contentFieldKeyPlan('', 'Field One'));
        self::assertSame('pole_1', contentFieldKeyPlan('Pole 1', 'Поле 1'));
        self::assertSame('', contentFieldKeyPlan('---', '---'));
    }

    public function testСсылкиПолейПроверяютсяПередСохранением(): void
    {
        self::assertTrue(contentSafeHref('/assets/img/a.png', true));
        self::assertTrue(contentSafeHref('https://school.test/stranitsa'));
        self::assertTrue(contentSafeHref('#ankersekciya'));
        self::assertFalse(contentSafeHref('javascript:alert(1)'));
        self::assertFalse(contentSafeHref('//chuzhoy.test/a.png', true));
        self::assertFalse(contentSafeHref('data:text/html,a', true));
    }

    public function testПеречниСтатусовВидовИВерстокПолны(): void
    {
        self::assertSame(['draft', 'published', 'scheduled', 'archived'], CONTENT_STATUSES);
        self::assertCount(10, CONTENT_FIELD_KIND_NAMES);
        self::assertSame(['text', 'media-left', 'media-right', 'banner'], array_keys(GLOBAL_BLOCK_LAYOUTS));
        self::assertSame('content_entry_published', contentStatusNotice(CONTENT_STATUS_PUBLISHED));
        self::assertSame('content_entry_archived', contentStatusNotice(CONTENT_STATUS_ARCHIVED));
    }
}
