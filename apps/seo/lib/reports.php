<?php

declare(strict_types=1);

// Реестр отчётов раздела: вид отчёта заводится один раз, а подпись, право на фильтр по
// источнику и предел верхних строк читают все выходы — экран экспорта, файл и машинное API.
// До реестра эти правила лежали в трёх списках и уже расходились: экран прятал источник у
// целей, а машинный ответ обещал фильтр, которого у конверсий нет.
//
// `export` и `api` — выходы, где вид отчёта виден человеку или машине. Одно имя не обязано
// стоять на обоих: машине нужны итоги периода целиком, а файл собирает разбивку по дням;
// кампании живут только в файле.

/**
 * @var array<string, array{title: string, source: bool, limit: int|null, export: bool, api: bool}>
 */
const SEO_REPORTS = [
    'summary' => [
        'title' => 'Итоги за период',
        'source' => true,
        'limit' => null,
        'export' => false,
        'api' => true,
    ],
    'daily' => [
        'title' => 'Просмотры по дням',
        'source' => true,
        'limit' => null,
        'export' => true,
        'api' => false,
    ],
    'pages' => [
        'title' => 'Популярные страницы',
        'source' => true,
        'limit' => SEO_STATS_PAGES_LIMIT,
        'export' => true,
        'api' => true,
    ],
    'sources' => [
        'title' => 'Источники',
        'source' => true,
        'limit' => null,
        'export' => true,
        'api' => true,
    ],
    'campaigns' => [
        'title' => 'Кампании по меткам',
        'source' => true,
        'limit' => SEO_STATS_CAMPAIGNS_LIMIT,
        'export' => true,
        'api' => false,
    ],
    'goals' => [
        'title' => 'Цели и конверсии',
        'source' => false,
        'limit' => null,
        'export' => true,
        'api' => true,
    ],
];

/**
 * Виды отчёта одного выхода: ключ => подпись. Экран, проверка адреса и скачивание читают
 * один список, поэтому «предложено на экране» и «принято файлом» совпадают по построению.
 *
 * @param 'api'|'export' $surface
 * @return array<string, string>
 */
function seoReportKinds(string $surface): array
{
    $kinds = [];

    foreach (SEO_REPORTS as $key => $report) {
        $listed = $surface === 'api' ? $report['api'] : $report['export'];

        if ($listed) {
            $kinds[$key] = $report['title'];
        }
    }

    return $kinds;
}

/** Делится ли отчёт по источнику: у конверсий источника нет, и подпись фильтра была бы ложью. */
function seoReportSupportsSource(string $key): bool
{
    return isset(SEO_REPORTS[$key]) && SEO_REPORTS[$key]['source'] === true;
}

/** Предел верхних строк отчёта: null — отчёт отдаёт все строки периода. */
function seoReportLimit(string $key): ?int
{
    return isset(SEO_REPORTS[$key]) ? SEO_REPORTS[$key]['limit'] : null;
}
