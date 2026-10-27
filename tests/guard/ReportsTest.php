<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

/**
 * Реестр отчётов раздела «SEO и статистика»: каждый вид отчёта описан ровно один раз, а
 * выходы (экран и файл, машинное API) берут подпись, право на источник и предел из него.
 *
 * Набор держит обещание реестра: экран не предлагает то, чего не примет скачивание, а
 * машинный адрес принимает ровно те разрезы, которые перечислены как его. Списки больше
 * не расходятся молча — расхождение валит этот прогон.
 */
final class ReportsTest extends TestCase
{
    public function testКаждыйОтчётОписанИВиденХотяБыОдномуВыходу(): void
    {
        self::assertNotSame([], SEO_REPORTS, 'Без реестра экран и API не знают, какие отчёты существуют');

        foreach (SEO_REPORTS as $key => $report) {
            $key = (string) $key;

            self::assertNotSame('', trim($report['title']), 'У отчёта ' . $key . ' нет подписи — в списке экрана будет пустая строка');
            self::assertTrue($report['api'] || $report['export'], 'Отчёт ' . $key . ' никому не виден: ни экрану, ни машине');

            if ($report['limit'] !== null) {
                self::assertGreaterThan(0, $report['limit'], 'Предел строк отчёта ' . $key . ' обязан быть положительным');
            }
        }
    }

    public function testВыходыНеПустыИСобираютсяИзРеестра(): void
    {
        $api = seoReportKinds('api');
        $export = seoReportKinds('export');

        self::assertNotSame([], $api, 'Машинное API без разрезов отвечало бы только отказом');
        self::assertNotSame([], $export, 'Экран экспорта без видов отчёта нечего предлагать');

        foreach (SEO_REPORTS as $key => $report) {
            $key = (string) $key;

            self::assertSame($report['api'], isset($api[$key]), 'Выход «api» отчёта ' . $key . ' разошёлся с реестром');
            self::assertSame($report['export'], isset($export[$key]), 'Выход «export» отчёта ' . $key . ' разошёлся с реестром');
        }
    }

    public function testПраваНаИсточникИПределыЧитаютсяТолькоИзРеестра(): void
    {
        self::assertTrue(seoReportSupportsSource('summary'));
        self::assertTrue(seoReportSupportsSource('daily'));
        self::assertTrue(seoReportSupportsSource('campaigns'));
        self::assertFalse(seoReportSupportsSource('goals'), 'У конверсии источника нет: фильтр по нему был бы ложью');

        self::assertSame(SEO_STATS_PAGES_LIMIT, seoReportLimit('pages'), 'Предел страниц — число, которым режет запрос');
        self::assertSame(SEO_STATS_CAMPAIGNS_LIMIT, seoReportLimit('campaigns'), 'Предел кампаний — число, которым режет запрос');
        self::assertNull(seoReportLimit('daily'), 'Просмотры по дням отдают все дни периода');
    }

    public function testДокументацияНазываетПределыТемЖеЧислом(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/seo-analytics.md');

        self::assertNotSame('', $doc, 'Документ раздела обязан существовать: иначе сверять пределы не с чем');

        foreach (SEO_REPORTS as $key => $report) {
            if ($report['limit'] === null) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/верхние\s+' . $report['limit'] . '\b/u',
                $doc,
                'Документ обязан называть предел отчёта ' . (string) $key . ' тем же числом, каким режет запрос'
            );
        }
    }

    public function testНеизвестныйКлючНеПритворяетсяОтчётом(): void
    {
        self::assertFalse(seoReportSupportsSource('нет-такого-отчёта'));
        self::assertNull(seoReportLimit('нет-такого-отчёта'));
        self::assertArrayNotHasKey('нет-такого-отчёта', seoReportKinds('api'));
        self::assertArrayNotHasKey('нет-такого-отчёта', seoReportKinds('export'));
    }
}
