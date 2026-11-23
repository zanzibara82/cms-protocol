<?php

declare(strict_types=1);

namespace Cms\Tests\Unit;

use PHPUnit\Framework\TestCase;

// RELEASE 2.3, PHASE C: правила назначения макета. До этой работы макет был только у страницы
// tbl_page.layout, поэтому тип контента и запись получали стандартную обвязку всегда — лендинг
// из записи каталога был невозможен, хотя макеты уже существовали.
//
// Приоритет в спецификации §14: объект → контекст (архив/категория) → тип контента → по умолчанию.
// Правило живёт функцией, а не веткой в маршруте: одно место отвечает на вопрос «какой макет»,
// и интерфейс может показать человеку тот же ответ, что применит рендер.
final class PageLayoutAssignmentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/core/page-layout.php';
    }

    // Самая частая ошибка назначения: «у объекта пусто» принимают за «показать пусто». Пустое
    // значение означает «не назначено», иначе каждый новый объект ломал бы обвязку сайта.
    public function testПустоеЗначениеОзначаетНеНазначено(): void
    {
        self::assertSame('', pageLayoutAssigned(''));
        self::assertSame('', pageLayoutAssigned('   '));
        self::assertSame('', pageLayoutAssigned(null));
    }

    // Неизвестная строка из базы или формы не должна давать страницу без шапки: она читается как
    // «не назначено», и решение принимает следующая ступень приоритета.
    public function testНеизвестныйМакетНеНазначается(): void
    {
        self::assertSame('', pageLayoutAssigned('hacker-layout'));
        self::assertSame('', pageLayoutAssigned('DEFAULT'));
    }

    public function testИзвестныйМакетНазначается(): void
    {
        self::assertSame('landing', pageLayoutAssigned('landing'));
        self::assertSame('sidebar-left', pageLayoutAssigned(' sidebar-left '));
    }

    // Приоритет: назначенное у объекта перебивает назначенное у типа, а тип — значение по
    // умолчанию. Порядок важен: перепутанные ступени дают «страница не меняет макет никогда».
    public function testОбъектПеребиваетТипИТипПеребиваетУмолчание(): void
    {
        // Объект назначен — побеждает он, даже если у типа другое.
        self::assertSame('blank', pageLayoutResolve('blank', 'landing', 'sidebar-right'));
        // Объект пуст — побеждает тип.
        self::assertSame('landing', pageLayoutResolve('', 'landing', 'sidebar-right'));
        // Объекта и типа нет — побеждает умолчание.
        self::assertSame('sidebar-right', pageLayoutResolve('', '', 'sidebar-right'));
        self::assertSame(PAGE_LAYOUT_DEFAULT, pageLayoutResolve('', '', ''));
        self::assertSame('no-footer', pageLayoutResolve(null, 'no-footer', 'landing'));
        // Мусор на верхней ступени не мешает нижней: неизвестное значение читается как «пусто».
        self::assertSame('landing', pageLayoutResolve('hacker', 'landing', 'blank'));
        self::assertSame('blank', pageLayoutResolve('hacker', 'чужое', 'blank'));
    }

    // Значение по умолчанию подставляется явно: рендер обязан получить имя макета, а не пустую
    // строку, иначе каркас ветвился бы по несуществующему значению.
    public function testУмолчаниеПодставляетсяЯвно(): void
    {
        self::assertSame(PAGE_LAYOUT_DEFAULT, pageLayoutResolve('', '', ''));
        self::assertSame(PAGE_LAYOUT_DEFAULT, pageLayoutResolve('нет-такого', '', ''));
    }

    // Тип контента может нести макет в своих настройках. Ключ хранится в JSON настроек типа,
    // поэтому читается из массива, а не отдельной колонкой: схему ради одного поля не меняем.
    public function testМакетТипаЧитаетсяИзНастроек(): void
    {
        self::assertSame('landing', pageLayoutFromSettings(['layout' => 'landing']));
        self::assertSame('', pageLayoutFromSettings(['layout' => 'чужое']));
        self::assertSame('', pageLayoutFromSettings([]));
        self::assertSame('', pageLayoutFromSettings(['layout' => ['массив']]));
    }
}
