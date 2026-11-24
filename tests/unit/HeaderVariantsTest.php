<?php

declare(strict_types=1);

namespace Cms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Варианты шапки: перечень, проверка значения и флаги поведения.
 *
 * Значение приходит из опции настроек, то есть из базы и формы. Неизвестная строка обязана
 * означать стандартную шапку: иначе подделанный POST открыл бы пустую полосу вместо шапки.
 */
final class HeaderVariantsTest extends TestCase
{
    public function testПереченьВариантовШапкиОбъявлен(): void
    {
        self::assertArrayHasKey('standard', HEADER_VARIANTS);
        self::assertArrayHasKey('minimal', HEADER_VARIANTS);
        self::assertArrayHasKey('centered', HEADER_VARIANTS);
        self::assertSame('standard', HEADER_VARIANT_DEFAULT);
    }

    public function testИзвестныйВариантОстаётсяСобой(): void
    {
        self::assertSame('centered', headerVariantOf('centered'));
        self::assertSame('minimal', headerVariantOf('minimal'));
    }

    public function testНеизвестныйВариантОткатываетсяКСтандартному(): void
    {
        self::assertSame('standard', headerVariantOf('hacker'));
        self::assertSame('standard', headerVariantOf(''));
        self::assertSame('standard', headerVariantOf(null));
    }

    public function testУзкаяШапкаНеПечатаетМенюВерхнейПолосой(): void
    {
        // minimal — знак сайта и кнопки входа, без пунктов меню в полосе.
        self::assertFalse(headerPlan('minimal')['menu']);
        self::assertTrue(headerPlan('standard')['menu']);
    }

    public function testЦентрированнаяШапкаПечатаетЛоготипПоЦентру(): void
    {
        self::assertTrue(headerPlan('centered')['centered']);
        self::assertFalse(headerPlan('standard')['centered']);
    }

    public function testСписокДляФормыЗнаетВсеВарианты(): void
    {
        $options = headerVariantOptions();

        foreach (array_keys(HEADER_VARIANTS) as $slug) {
            self::assertArrayHasKey($slug, $options);
        }
    }
}
