<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Кнопка без ветки match мёртвая, ветка match без подписи — действие без названия, а уведомление
// без текста — молчание вместо ответа. Страж сверяет четыре константы админки (задачи 17 и 19)
// с тем, что реально разбирают файлы действий.
final class AppActionContractTest extends TestCase
{
    /** @var array<string, array{ops: list<string>, default: string}> файл действий → чем он сверяется */
    private const ACTIONS = [
        'apps/news/actions/items.php' => ['ops' => ['NEWS_ROW_OP_LABEL'], 'default' => 'itemFailure'],
        'apps/news/actions/catalog.php' => ['ops' => ['NEWS_CATEGORY_OP_LABEL', 'NEWS_TAG_OP_LABEL'], 'default' => 'catalogFailure'],
        'apps/news/actions/blocks.php' => ['ops' => ['NEWS_BLOCK_OP_LABEL'], 'default' => 'catalogFailure'],
    ];

    public function testКаждоеОбещанноеДействиеРазобрано(): void
    {
        $handled = $this->handledOps('apps/news/actions/items.php');

        self::assertSame(array_keys(NEWS_ROW_OP_LABEL), $handled, 'items.php: кнопки списка и match расходятся');

        $catalog = $this->handledOps('apps/news/actions/catalog.php');
        self::assertSame(
            array_merge(NEWS_CATEGORY_OPS, NEWS_TAG_OPS),
            $catalog,
            'catalog.php: порядок действий в match обязан совпадать со списком'
        );

        self::assertSame(
            array_keys(NEWS_BLOCK_OP_LABEL),
            $this->handledOps('apps/news/actions/blocks.php'),
            'blocks.php: кнопки экрана и match расходятся'
        );
    }

    public function testКаждоеРазобранноеДействиеИмеетПодпись(): void
    {
        $rows = $this->handledOps('apps/news/actions/items.php');
        $catalog = $this->handledOps('apps/news/actions/catalog.php');
        $captions = array_merge(array_keys(NEWS_CATEGORY_OP_LABEL), array_keys(NEWS_TAG_OP_LABEL));

        // Вперёд: действие без подписи — кнопка, у которой нет названия.
        self::assertSame(
            [],
            array_values(array_diff($rows, array_keys(NEWS_ROW_OP_LABEL))),
            'items.php: разобранное действие обязано иметь подпись'
        );
        self::assertSame(
            [],
            array_values(array_diff($catalog, $captions)),
            'catalog.php: разобранное действие обязано иметь подпись одного из справочников'
        );

        // Назад: подпись без ветки match — обещание кнопки, которую никто не рисует.
        self::assertSame(
            [],
            array_values(array_diff(array_keys(NEWS_ROW_OP_LABEL), $rows)),
            'items.php: подпись без ветки match — мёртвая кнопка'
        );
        self::assertSame(
            [],
            array_values(array_diff($captions, $catalog)),
            'catalog.php: подпись без ветки match — мёртвая кнопка'
        );

        $blocks = $this->handledOps('apps/news/actions/blocks.php');

        self::assertSame(
            [],
            array_values(array_diff($blocks, array_keys(NEWS_BLOCK_OP_LABEL))),
            'blocks.php: разобранное действие обязано иметь подпись'
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys(NEWS_BLOCK_OP_LABEL), $blocks)),
            'blocks.php: подпись без ветки match — мёртвая кнопка'
        );
    }

    public function testНеизвестноеДействиеОтказывается(): void
    {
        foreach (array_keys(self::ACTIONS) as $file) {
            $code = (string) file_get_contents($this->path($file));

            self::assertSame(
                substr_count($code, 'match ($op)'),
                substr_count($code, 'default =>'),
                $file . ': у каждой ветки разбора обязан быть отказ'
            );

            self::assertMatchesRegularExpression(
                '/default\s*=>\s*' . self::ACTIONS[$file]['default'] . '\(/',
                $code,
                $file . ': отказ обязан возвращать текст, а не пустой результат'
            );
        }
    }

    public function testСпискиДействийСовпадаютСоСвоимиПодписями(): void
    {
        self::assertSame(NEWS_CATEGORY_OPS, array_keys(NEWS_CATEGORY_OP_LABEL));
        self::assertSame(NEWS_TAG_OPS, array_keys(NEWS_TAG_OP_LABEL));
        self::assertSame(NEWS_BLOCK_OPS, array_keys(NEWS_BLOCK_OP_LABEL));
    }

    /**
     * Действия, которые разбирает `match ($op)` файла. Ключ ветки — единственная строка вида
     * `'op' =>` в блоке: правые части веток — вызовы функций, а не массивы, поэтому пары
     * «строка => …» в них нет. Если в ветке появится массив с такими ключами, страж придётся
     * дополнять отдельным утверждением — ограничение записано честно.
     *
     * @return list<string>
     */
    private function handledOps(string $file): array
    {
        $code = (string) file_get_contents($this->path($file));

        preg_match_all('/match \(\$op\)\s*\{(.*?)\n\s*\};/s', $code, $blocks);

        $ops = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([a-z]+)'\s*=>/", (string) $block, $arms);
            foreach ($arms[1] as $op) {
                if ($op !== 'default') {
                    $ops[] = (string) $op;
                }
            }
        }

        return $ops;
    }

    private function path(string $file): string
    {
        return dirname(__DIR__, 2) . '/' . $file;
    }
}
