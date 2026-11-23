<?php

declare(strict_types=1);

namespace Cms\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/design-system.php';

/**
 * Правила оформления темы: цвета и типографика.
 *
 * Проверяется не разметка, а решения: чужое значение не должно давать сайт без читаемого текста,
 * а контраст обязан оставаться в допустимых пределах — иначе «своя палитра» делает страницу
 * нечитаемой, и заметить это можно только глазами на живом сайте.
 */
final class DesignSystemTest extends TestCase
{
    public function testЦветаПоУмолчаниюСоответствуютТеме(): void
    {
        $colors = designColorsOf([]);

        self::assertSame('#fdf6ec', $colors['paper']);
        self::assertSame('#2b2118', $colors['ink']);
        self::assertSame('#d97706', $colors['accent']);
    }

    public function testЧужойЦветНеПроходитИОстаётсяПрежний(): void
    {
        // Подделанный POST не должен давать страницу без цвета: `red; background:url(...)`
        // в значении — это уже не цвет, а попытка внедрить правило.
        $colors = designColorsOf([
            'paper' => 'red; background: url(http://evil.test/x.png)',
            'ink' => 'javascript:alert(1)',
            'accent' => '#12',
        ]);

        self::assertSame('#fdf6ec', $colors['paper']);
        self::assertSame('#2b2118', $colors['ink']);
        self::assertSame('#d97706', $colors['accent']);
    }

    public function testКороткаяЗаписьЦветаПриводитсяКПолной(): void
    {
        self::assertSame(['paper' => '#aabbcc'], designColorsOf(['paper' => '#abc'], ['paper' => '#000000']));
    }

    public function testРазмерыЗаголовковДержатсяВПределах(): void
    {
        // Ноль и отрицательное значение дают невидимый заголовок, гигантское — строку из одного
        // слова на весь экран. Оба случая — ошибка ввода, а не замысел.
        self::assertSame(DESIGN_FONT_MIN, designSizeOf('h1', 0));
        self::assertSame(DESIGN_FONT_MAX, designSizeOf('h1', 9999));
        self::assertSame(30, designSizeOf('h1', 30));
    }

    public function testСтилиСобираютсяВПеременныеИзвестныхИмён(): void
    {
        // Печатаются только ИЗМЕНЁННЫЕ значения: совпадающее с умолчанием остаётся за темой.
        $css = designStyleTag(
            designColorsOf(['paper' => '#101010', 'ink' => '#f0f0f0']),
            designTypographyOf(['h1' => 44, 'body' => 18])
        );

        // Переменные те же, что объявлены в @theme: иначе свои цвета не подхватились бы
        // существующими классами (bg-paper, text-ink).
        self::assertStringContainsString('--color-paper: #101010', $css);
        self::assertStringContainsString('--color-ink: #f0f0f0', $css);
        self::assertStringContainsString('--design-h1: 44px', $css);
        self::assertStringContainsString('font-size: 18px', $css);
        self::assertStringContainsString('<style>', $css);
        self::assertStringContainsString('</style>', $css);
        // Неизменённые цвета в стиль не попадают: иначе правка CSS темы не доезжала бы до сайта.
        self::assertStringNotContainsString('--color-accent', $css);
    }

    public function testПустыеНастройкиНеПечатаютСтиль(): void
    {
        // Без своих значений страница должна получить ровно тот CSS, что в артефакте: лишний
        // <style> на каждой странице — это ещё и лишний байт в кеше.
        $css = designStyleTag(designColorsOf([]), designTypographyOf([]));

        self::assertSame('', $css);
    }

    public function testКонтрастТекстаПроверяется(): void
    {
        // Белый текст на светлом фоне — не «стиль», а нечитаемая страница: правило возвращает
        // контраст, чтобы экран мог предупредить человека.
        self::assertGreaterThan(7.0, designContrast('#2b2118', '#fdf6ec'));
        self::assertLessThan(2.0, designContrast('#ffffff', '#fdf6ec'));
    }

    public function testТипографикаПоУмолчаниюПустая(): void
    {
        // Пустая типографика означает «как в теме»: значения по умолчанию живут в CSS темы,
        // а не дублируются в опциях — иначе правка темы не доехала бы до сайта.
        self::assertSame([], designTypographyOf([]));
    }
}
