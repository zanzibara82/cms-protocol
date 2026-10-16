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
        $dictionaries = ['материал' => NEWS_NOTICE_TEXT, 'справочники' => CATALOG_NOTICE_TEXT, 'блоки' => NEWS_BLOCK_NOTICE_TEXT];

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
