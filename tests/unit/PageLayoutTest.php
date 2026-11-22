<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Правила макетов страницы и ширины секции. Базы нет: макет — это описание каркаса
 * (шапка, подвал, боковые панели, вложенный контейнер), поэтому правило чистое и проверяется
 * без схемы.
 *
 * Причина проверки: до этой работы каркас был один на все страницы — `theme/layout.php`
 * с шапкой, подвалом и контейнером. Лендинг без шапки, страница с сайдбаром и секция во всю
 * ширину были невозможны, а неизвестный макет из формы обязан не ломать страницу, а
 * откатываться к стандартному.
 */
final class PageLayoutTest extends TestCase
{
    public function testПереченьМакетовОписанПолностью(): void
    {
        // Каждый макет перечня обязан иметь описание: селект рисуется по нему, и макет без
        // подписи человеку не выбрать.
        foreach (PAGE_LAYOUTS as $layout) {
            self::assertArrayHasKey($layout, PAGE_LAYOUT_NAMES, 'У макета «' . $layout . '» нет подписи');
            self::assertNotSame('', PAGE_LAYOUT_NAMES[$layout]);
        }

        // Стандартный макет есть всегда: он воспроизводит прежний вид страницы и служит
        // значением по умолчанию для всех существующих страниц после миграции.
        self::assertContains(PAGE_LAYOUT_DEFAULT, PAGE_LAYOUTS);
    }

    public function testНеизвестныйМакетОткатываетсяКСтандартному(): void
    {
        // Значение приходит из базы и из формы: чужая строка не должна давать белый экран.
        foreach (['', 'нет-такого', 'LANDING', '../x', null, 42] as $bad) {
            $plan = pageLayoutPlan($bad);

            self::assertSame(PAGE_LAYOUT_DEFAULT, $plan['layout'], 'Макет обязан откатиться к стандартному');
            self::assertTrue($plan['header'], 'Неизвестный макет не должен лишать страницу шапки');
            self::assertTrue($plan['footer'], 'Неизвестный макет не должен лишать страницу подвала');
            self::assertFalse($plan['blank']);
        }
    }

    public function testЛендингУбираетШапкуИПодвал(): void
    {
        // Пустой макет и лендинг — страницы без сайтовой обвязки: рекламная страница не должна
        // тянуть меню и подвал, но SEO и системные механизмы остаются (их отключает не макет).
        foreach (['landing', 'blank'] as $layout) {
            $plan = pageLayoutPlan($layout);

            self::assertFalse($plan['header'], 'У макета «' . $layout . '» не должно быть шапки');
            self::assertFalse($plan['footer'], 'У макета «' . $layout . '» не должно быть подвала');
            self::assertTrue($plan['blank']);
        }
    }

    public function testСтраницаБезЧастиОбвязкиОставляетОстальное(): void
    {
        // Отдельные макеты убирают только одну часть обвязки: «без шапки» — не лендинг,
        // подвал с контактами на такой странице нужен.
        $noHeader = pageLayoutPlan('no-header');
        self::assertFalse($noHeader['header']);
        self::assertTrue($noHeader['footer']);
        self::assertFalse($noHeader['blank'], '«Без шапки» — не пустой макет');

        $noFooter = pageLayoutPlan('no-footer');
        self::assertTrue($noFooter['header']);
        self::assertFalse($noFooter['footer']);
        self::assertFalse($noFooter['blank']);
    }

    public function testСайдбарЗадаётСторону(): void
    {
        // Сайдбар описывается стороной, а не готовой колонкой: каркас сам решает, где её
        // напечатать, а правило остаётся проверяемым без разметки.
        self::assertSame('left', pageLayoutPlan('sidebar-left')['sidebar']);
        self::assertSame('right', pageLayoutPlan('sidebar-right')['sidebar']);
        self::assertSame('both', pageLayoutPlan('two-sidebars')['sidebar']);
        self::assertSame('none', pageLayoutPlan(PAGE_LAYOUT_DEFAULT)['sidebar']);

        // Сайдбар не снимает обвязку: шапка и подвал остаются.
        self::assertTrue(pageLayoutPlan('sidebar-left')['header']);
        self::assertTrue(pageLayoutPlan('sidebar-left')['footer']);
    }

    public function testШиринаСекцииУправляетКонтейнером(): void
    {
        // Ширина — это то, чему подчиняется контейнер внутри секции. Полная ширина не
        // печатает вложенный контейнер: человек сам решает, ограничивать ли содержимое
        // (концепция Full Width Section + Inner Content Container).
        self::assertTrue(sectionWidthPlan('standard', 0)['container']);
        self::assertTrue(sectionWidthPlan('wide', 0)['container']);
        self::assertTrue(sectionWidthPlan('custom', 1000)['container']);
        self::assertFalse(sectionWidthPlan('full', 0)['container'], 'У полной ширины контейнера нет');

        // Неизвестная ширина откатывается к стандартной, как и неизвестный макет.
        foreach (['', 'huge', '100%', null, 5] as $bad) {
            self::assertSame(PAGE_WIDTH_DEFAULT, sectionWidthPlan($bad, 0)['width']);
        }
    }

    public function testСвояШиринаПодрезаетсяПределами(): void
    {
        // Custom — единственная ширина со значением от человека: предел держится правилом,
        // иначе «999999» превратил бы страницу в горизонтальную прокрутку.
        self::assertSame(1200, sectionWidthPlan('custom', 1200)['custom']);

        // Значение вне пределов подрезается, а не отклоняется: страницу уже открыли, и
        // потерять её из-за одного числа хуже, чем подрезать.
        self::assertSame(PAGE_CONTAINER_CUSTOM_MAX, sectionWidthPlan('custom', 999999)['custom']);
        self::assertSame(PAGE_CONTAINER_CUSTOM_MIN, sectionWidthPlan('custom', 1)['custom']);

        // Нечисловое значение — значение по умолчанию, а не ноль.
        self::assertSame(PAGE_CONTAINER_CUSTOM_DEFAULT, sectionWidthPlan('custom', 'широко')['custom']);
    }
}
