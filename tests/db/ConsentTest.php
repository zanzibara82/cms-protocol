<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Журнал согласий на настоящей базе: запись факта, версия текста, источник и выгрузка.
 *
 * Согласие — доказательство, а не свойство заявки: строка переживает удаление заявки, а версия
 * текста фиксируется в момент согласия. Иначе правка формулировки задним числом меняла бы то,
 * на что человек согласился.
 */
final class ConsentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('consent_log');
        TestDb::clearTable('lead');
        TestDb::clearTable('lead_attempt');
    }

    public function testСогласиеПишетсяСИсточникомИВерсиейТекста(): void
    {
        consentRecord('+7 900 111-22-33', 'lead', '/o-nas#zayavka');

        $row = one('SELECT subject, kind, text_version, source FROM ' . t('consent_log'));

        self::assertNotNull($row);
        self::assertSame('+7 900 111-22-33', (string) $row['subject']);
        self::assertSame('lead', (string) $row['kind']);
        self::assertSame(CONSENT_TEXT_VERSION, (string) $row['text_version'], 'Версия текста фиксируется в момент согласия');
        self::assertSame('/o-nas#zayavka', (string) $row['source']);
    }

    // Версия приходит параметром: у формы может быть свой текст, и он обязан записаться как есть.
    public function testВерсияТекстаПередаётсяИзФормы(): void
    {
        consentRecord('maria@example.ru', 'lead', '/', '2026-01-01');

        $row = one('SELECT text_version FROM ' . t('consent_log'));

        self::assertNotNull($row);
        self::assertSame('2026-01-01', (string) $row['text_version']);
    }

    public function testПредметИИсточникОбрезаютсяПоПределамКолонок(): void
    {
        consentRecord(str_repeat('я', 300), 'lead', str_repeat('п', 300));

        $row = one('SELECT subject, source FROM ' . t('consent_log'));

        self::assertNotNull($row);
        self::assertSame(190, mb_strlen((string) $row['subject']));
        self::assertSame(190, mb_strlen((string) $row['source']));
    }

    // Строка согласия не удаляется вместе с заявкой: заявку убирают, доказательство остаётся.
    public function testСогласиеПереживаетУдалениеЗаявки(): void
    {
        $created = leadCreate([
            'name' => 'Мария',
            'contact' => 'maria@example.ru',
            'message' => '',
            'source' => '/o-nas#zayavka',
        ], nowUtc());
        self::assertTrue($created['ok']);

        consentRecord('maria@example.ru', 'lead', '/o-nas#zayavka');
        leadDelete((string) $created['id']);

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('lead')));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('consent_log')), 'Согласие обязано остаться');
    }

    public function testСписокИщетПоПредметуИИсточнику(): void
    {
        consentRecord('maria@example.ru', 'lead', '/o-nas#zayavka');
        consentRecord('+7 900 111-22-33', 'lead', '/kursy#zayavka');

        $found = consentPage(1, 'maria');
        self::assertSame(1, $found['total']);
        self::assertSame('maria@example.ru', (string) $found['rows'][0]['subject']);

        self::assertSame(1, consentPage(1, 'kursy')['total'], 'Поиск по источнику тоже работает');
        self::assertSame(2, consentPage(1, '')['total']);
    }

    public function testВыгрузкаВозвращаетВсеСтрокиИЭкранируетФормулы(): void
    {
        // Значение, начинающееся с «=», табличный редактор исполнил бы как формулу. Запись
        // обязана прийти с ведущей кавычкой, иначе открытие CSV в Excel запускает вычисление.
        consentRecord('=1+1', 'lead', '/');

        $csv = consentCsv();

        self::assertStringContainsString('subject', $csv, 'Первая строка — заголовки колонок');
        self::assertStringContainsString("'=1+1", $csv, 'Формула обезврежена ведущей кавычкой');
        self::assertStringNotContainsString(',=1+1,', $csv, 'Формула не должна начинать ячейку');

        self::assertCount(1, consentExportRows());
        self::assertCount(1, consentExportRows('=1+1'), 'Выгрузка с поиском фильтрует так же, как экран');
    }
}
