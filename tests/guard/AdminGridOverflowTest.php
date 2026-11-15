<?php

declare(strict_types=1);

namespace Cms\Tests\Guard;

use PHPUnit\Framework\TestCase;

/**
 * Сетка админки не должна обрезать содержимое на узком экране.
 *
 * Почему это страж: у `grid`-элемента `min-width` по умолчанию `auto`, поэтому ячейка не
 * сжимается ниже своего содержимого. Широкая таблица внутри ячейки распирает всю сетку, документ
 * становится шире окна, и на телефоне человек видит обрезанные секции — не прокрутку, а обрезку.
 *
 * Замер в браузере (окно 360, ширина содержимого 345) показал: `/admin/editorial` расширял
 * документ до 594 px и обрезал 12 элементов — секции, их заголовки и обёртки таблиц. Добавление
 * `min-w-0` сетке возвращало документ к 345 px и убирало все обрезанные элементы. На остальных
 * 14 экранах админки обрезанных элементов нет вовсе: их таблицы лежат в `overflow-x-auto` прямо
 * внутри блочного родителя, а не в ячейке сетки.
 *
 * Правило узкое намеренно: проверяется только сетка с колонками, внутри которой стоит таблица с
 * `min-w-[…]`. Требовать `min-w-0` от каждой сетки нельзя — у сетки из обычных полей широкого
 * содержимого нет, и страж краснел бы на 35 исправных местах (проверено: первая версия стража
 * дала ровно столько находок при одном настоящем дефекте).
 */
final class AdminGridOverflowTest extends TestCase
{
    /** @return list<string> */
    private function screens(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach ([$root . '/core/templates/admin', $root . '/admin'] as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }

        foreach (glob($root . '/apps/*/templates/admin', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Ячейка сетки с широкой таблицей обязана иметь `min-w-0`.
     *
     * Разметка собирается конкатенацией PHP, поэтому «внутри» определяется окном строк: от
     * объявления ячейки до её закрытия в пределах разумного блока.
     *
     * @return list<array{file: string, line: int}>
     */
    private function violations(string $source, string $file): array
    {
        $found = [];
        $lines = explode("\n", $source);

        foreach ($lines as $i => $line) {
            if (preg_match('/class="([^"]*)"/', $line, $m) !== 1) {
                continue;
            }

            $class = $m[1];

            // Обёртка прокрутки таблицы — не ячейка сетки: она и обязана быть шире.
            if (strpos($class, 'overflow-x-auto') !== false || strpos($class, 'overflow-x-scroll') !== false) {
                continue;
            }

            // Ячейка = section или div прямо в сетке: у неё в классе нет grid, но есть рамка
            // карточки. Проверяем только элементы внутри размеченных сеток — их и распирает.
            if (strpos($line, '<section') === false && strpos($line, '<div class=') === false) {
                continue;
            }

            if (strpos($class, 'min-w-0') !== false) {
                continue;
            }

            // Широкая таблица именно внутри этой ячейки: окно до её закрытия. Закрытие ищем
            // по первому '</section>' после объявления, иначе окно захватывает таблицу соседней
            // ячейки и страж краснеет на исправной разметке (проверено: так и случилось).
            $limit = 40;
            for ($j = $i; $j < min(count($lines), $i + 40); $j++) {
                if (strpos($lines[$j], '</section>') !== false && $j > $i) {
                    $limit = $j - $i + 1;
                    break;
                }
            }
            $block = implode("\n", array_slice($lines, $i, $limit));
            if (preg_match('/<table[^>]*min-w-\[/', $block) !== 1) {
                continue;
            }

            // Ячейка обязана быть внутри сетки: проверяем, что выше есть объявление grid.
            $above = implode("\n", array_slice($lines, max(0, $i - 40), 40));
            if (preg_match('/class="[^"]*\bgrid\b[^"]*cols-\d/', $above) !== 1) {
                continue;
            }

            $found[] = ['file' => $file, 'line' => $i + 1];
        }

        return $found;
    }

    public function testЯчейкаСеткиСШирокойТаблицейНеОбрезаетСодержимое(): void
    {
        $bad = [];

        foreach ($this->screens() as $file) {
            foreach ($this->violations((string) file_get_contents($file), $file) as $item) {
                $bad[] = $item;
            }
        }

        self::assertSame(
            [],
            $bad,
            'У ячейки grid min-width: auto — широкая таблица внутри распирает ячейку и обрезает '
            . "содержимое на узком экране. Нужен min-w-0 у ячейки (класс на самой сетке не помогает):\n"
            . implode("\n", array_map(
                static fn (array $b): string => '  ' . $b['file'] . ':' . $b['line'],
                $bad,
            )),
        );
    }
}
