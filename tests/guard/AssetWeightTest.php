<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Шапка и экраны отказа отдают трёх котов на каждый запрос: до пакета V (задача V6) это было
// ~0,6 МБ PNG при всём CSS в 12 746 байт. Файлы уменьшены до двойной ширины показываемого
// (шапка — 32 px, экраны 403/404 — 96 px, иконка браузера — до 32 px), и без проверки веса
// правило «статика лёгкая» откатывается молча: следующий, кто положит в public/assets картинку
// на 300 КБ, падения не увидит. Числа бюджетов — требования задачи V6 пакета V: они НЕ выводятся
// из фактического веса файлов, а предписаны сверху, и правка их вниз под получившееся содержимое
// была бы ослаблением стража, а не настройкой. Поднять константу «чтобы пролезло» — тоже выключить
// страж; любое изменение чисел решает человек, а не автор упавшего прогона.
//
// Страж проверяет две независимые вещи:
// 1) вес каждого файла не больше байтового бюджета;
// 2) ширина в пикселях не больше удвоенной показываемой — иначе файл снова вырастет до 488 px,
//    уложившись в байты за счёт сильного сжатия, и браузер понесёт лишние пиксели.
final class AssetWeightTest extends TestCase
{
    /**
     * Байтовые бюджеты — числа из требования задачи V6 пакета V, они не выводятся из фактического
     * веса уменьшенного файла и вниз под содержимое не правятся (это было бы ослабление стража).
     * Одновременно бюджеты заметно ниже старого веса (343 587 / 176 650 / 93 013 байт),
     * чтобы возврат тяжёлых файлов не прошёл.
     */
    private const BUDGETS = [
        'assets/cat-happy.png' => 40 * 1024,
        'assets/cat-sad.png' => 20 * 1024,
        'assets/favicon.png' => 8 * 1024,
    ];

    /** Пиксельные пределы: двойная максимальная показываемая ширина (32 / 96 / 32 px). */
    private const MAX_WIDTHS = [
        'assets/cat-happy.png' => 64,
        'assets/cat-sad.png' => 192,
        'assets/favicon.png' => 64,
    ];

    public function testСтатикаШапкиИЭкрановОтказаВБюджете(): void
    {
        $measured = $this->measureAssets($this->publicPaths(array_keys(self::BUDGETS)));

        self::assertSame(
            [],
            $this->assetProblems($measured),
            'Статика на каждый запрос вылезла за бюджет веса или ширины — лёгкость шапки откатилась'
        );
    }

    // Самопроверка: каждая форма отката обязана краснить страж, а рабочий набор — нет.
    public function testСтражЛовитПосаженныйВесШиринуИПропажуФайла(): void
    {
        // Зелёный контрпример: замеры заведомо лёгкого набора дают пустой список находок.
        $working = [
            'assets/cat-happy.png' => ['bytes' => 30 * 1024, 'width' => 64],
            'assets/cat-sad.png' => ['bytes' => 15 * 1024, 'width' => 192],
            'assets/favicon.png' => ['bytes' => 6 * 1024, 'width' => 64],
        ];
        self::assertSame([], $this->assetProblems($working), 'Лёгкий набор помечен как тяжёлый');

        // Посаженный вес: прежний cat-happy.png (343 587 байт) обязан быть находкой по байтам.
        $heavy = $working;
        $heavy['assets/cat-happy.png'] = ['bytes' => 343587, 'width' => 64];
        $problems = $this->assetProblems($heavy);
        self::assertNotEmpty($problems, 'Возврат тяжёлого файла не пойман');
        self::assertStringContainsString('весит', $problems[0]);

        // Посаженная ширина: 488 px при вписанном весе — картинка снова несёт лишние пиксели.
        $wide = $working;
        $wide['assets/cat-sad.png'] = ['bytes' => 15 * 1024, 'width' => 366];
        self::assertNotEmpty($this->assetProblems($wide), 'Разрастание пикселей не поймано');

        // Граница предела: ровно 192 px — ещё предел, 193 px — уже находка.
        self::assertSame([], $this->assetProblems($working));
        $edge = $working;
        $edge['assets/cat-sad.png'] = ['bytes' => 15 * 1024, 'width' => 193];
        self::assertNotEmpty($this->assetProblems($edge), 'Предел ширины сработал не строго');

        // Граница бюджета: вес ровно по бюджету — уже находка («вес < бюджета», не «<=»).
        $exact = $working;
        $exact['assets/favicon.png'] = ['bytes' => 8 * 1024, 'width' => 64];
        self::assertNotEmpty($this->assetProblems($exact), 'Вес ровно по бюджету прошёл зелёным');

        // Исчезнувший файл: пустой список замеров — тоже находка, а не зелёная заглушка.
        self::assertNotEmpty($this->assetProblems([]), 'Пропажа файлов не поймана');

        // Посадка настоящего файла: временный файл заведомо тяжелее бюджета cat-happy.png
        // обязан краснить проверку по весу на фактическом измерении, а не на сочинённом числе.
        $probe = tempnam(sys_get_temp_dir(), 'cms-asset-weight');
        self::assertIsString($probe, 'Временный каталог недоступен — самопроверка нечего сажать');

        try {
            self::assertIsInt(file_put_contents($probe, str_repeat("\0", 44 * 1024)));
            $probeProblems = $this->assetProblems($this->measureAssets(['assets/cat-happy.png' => $probe]));
            self::assertNotEmpty($probeProblems, 'Заведомо тяжёлый временный файл прошёл зелёным');
            $weightHits = array_values(array_filter($probeProblems, static fn (string $p): bool => str_contains($p, 'весит')));
            self::assertNotEmpty($weightHits, 'Тяжёлый временный файл найден, но не по весу');
        } finally {
            unlink($probe);
        }
    }

    /**
     * Находки по списку замеров: чистая функция, чтобы самопроверка садила нарушения на тех же
     * мере и сообщении, на которых их ищет основная проверка.
     *
     * @param array<string, array{bytes: int|null, width: int|null}> $measured
     *
     * @return list<string>
     */
    private function assetProblems(array $measured): array
    {
        $problems = [];

        foreach (self::BUDGETS as $name => $budget) {
            $asset = $measured[$name] ?? null;

            if ($asset === null || $asset['bytes'] === null) {
                $problems[] = 'файл ' . $name . ' недоступен для проверки веса';
                continue;
            }

            if ($asset['bytes'] >= $budget) {
                $problems[] = 'файл ' . $name . ' весит ' . $asset['bytes'] . ' байт, бюджет — ' . $budget . ' байт';
            }

            if ($asset['width'] === null) {
                $problems[] = 'файл ' . $name . ' не читается как картинка — проверять ширину нечем';
            } elseif ($asset['width'] > self::MAX_WIDTHS[$name]) {
                $problems[] = 'файл ' . $name . ' шириной ' . $asset['width'] . ' px, предел — ' . self::MAX_WIDTHS[$name] . ' px';
            }
        }

        return $problems;
    }

    /**
     * Фактические замеры файлов: вес с диска, ширина из заголовка картинки. Сюда приходят либо
     * сборки publicPaths() (основная проверка), либо явный временный путь самопроверки.
     *
     * @param array<string, string> $paths имя относительно public => абсолютный путь
     *
     * @return array<string, array{bytes: int|null, width: int|null}>
     */
    private function measureAssets(array $paths): array
    {
        $measured = [];

        foreach ($paths as $name => $absolute) {
            $bytes = null;
            $width = null;

            if (is_file($absolute)) {
                $size = filesize($absolute);
                $bytes = is_int($size) ? $size : null;

                if ($bytes !== null) {
                    $info = @getimagesize($absolute);
                    $width = is_array($info) ? (int) $info[0] : null;
                }
            }

            $measured[$name] = ['bytes' => $bytes, 'width' => $width];
        }

        return $measured;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private function publicPaths(array $names): array
    {
        $root = dirname(__DIR__, 2) . '/public';
        $paths = [];

        foreach ($names as $name) {
            $paths[$name] = $root . '/' . $name;
        }

        return $paths;
    }
}
