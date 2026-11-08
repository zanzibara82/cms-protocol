<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/upload.php';

// Приём картинки конструктора: имя генерирует сервер, формат решает содержимое, а не расширение.
// Перенос на диск проверяет http-набор: только там файл приходит настоящим multipart-запросом.
final class PageImageUploadTest extends TestCase
{
    public function testИмяФайлаГенерируетСервер(): void
    {
        $href = pageImageHrefOf('png');

        self::assertMatchesRegularExpression('#^/uploads/images/[0-9a-hjkmnp-tv-z]{26}\.png$#', $href);
        self::assertNotSame($href, pageImageHrefOf('png'), 'Два вызова дают два разных имени');
    }

    public function testФорматОпределяетсяПоСодержимому(): void
    {
        $check = pageImageInspect([
            'name' => 'переименованный.php',
            'type' => 'application/x-php',
            'tmp_name' => dirname(__DIR__, 2) . '/public/assets/img/brand/favicon.png',
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
        ]);

        self::assertSame('png', $check['ext'], 'имя и присланный тип не важны');
        self::assertSame('', $check['error']);
    }

    public function testТекстВместоКартинкиОтклоняется(): void
    {
        $check = pageImageInspect([
            'name' => 'заметка.png',
            'type' => 'image/png',
            'tmp_name' => dirname(__DIR__, 2) . '/config.sample.php',
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
        ]);

        self::assertSame('', $check['ext']);
        self::assertStringContainsString('jpg, png или webp', $check['error']);
    }

    public function testФайлБольшеПятиМегабайтОтклоняется(): void
    {
        $check = pageImageInspect([
            'name' => 'большая.png',
            'type' => 'image/png',
            'tmp_name' => dirname(__DIR__, 2) . '/public/assets/img/brand/favicon.png',
            'error' => UPLOAD_ERR_OK,
            'size' => PAGE_IMAGE_MAX_BYTES + 1,
        ]);

        self::assertSame('', $check['ext']);
        self::assertStringContainsString('5 МБ', $check['error']);
    }

    public function testОтказПоКодуЗагрузкиЧеловеческий(): void
    {
        $iniSize = pageImageRefusalOf(UPLOAD_ERR_INI_SIZE);
        $partial = pageImageRefusalOf(UPLOAD_ERR_PARTIAL);

        self::assertNotSame('', $iniSize);
        self::assertNotSame($iniSize, $partial, 'Разные причины не должны звучать одинаково');
        self::assertStringNotContainsString('UPLOAD_', $iniSize, 'Константу PHP человеку показывать нельзя');
    }
}
