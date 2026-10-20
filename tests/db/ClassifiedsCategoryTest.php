<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use PHPUnit\Framework\Attributes\Test;

/**
 * Дерево категорий доски: порядок внутри ветки, запрет круга, скрытие поддерева,
 * удаление занятой категории и переезд потомков.
 */
final class ClassifiedsCategoryTest extends ClassifiedsDbTestCase
{
    /** Строка категории с проверкой существования: разбор полей не спорит с nullable. */
    /** @return array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool} */
    private static function category(string $id): array
    {
        $row = classifiedsCategoryById($id);
        self::assertNotNull($row, 'Категория ' . $id . ' не найдена.');

        return $row;
    }

    #[Test]
    public function createRootAndChildPutOrderInsideTheirBranch(): void
    {
        $root = classifiedsCreateCategory(['title' => 'Авто', 'slug' => 'avto', 'parent' => '', 'visible' => 1]);
        self::assertTrue($root['ok']);

        $motorcycles = classifiedsCreateCategory(['title' => 'Мото', 'slug' => 'moto', 'parent' => $root['id'], 'visible' => 1]);
        $parts = classifiedsCreateCategory(['title' => 'Запчасти', 'slug' => 'zapchasti', 'parent' => $root['id'], 'visible' => 1]);
        $houses = classifiedsCreateCategory(['title' => 'Недвижимость', 'slug' => 'nedvizhimost', 'parent' => '', 'visible' => 1]);

        self::assertSame(1, self::category($root['id'])['order_index']);
        self::assertSame(1, self::category($motorcycles['id'])['order_index']);
        self::assertSame(2, self::category($parts['id'])['order_index']);
        self::assertSame(2, self::category($houses['id'])['order_index']);

        $flat = classifiedsCategoryFlatTree();
        self::assertSame(['avto', 'moto', 'zapchasti', 'nedvizhimost'], array_column($flat, 'slug'));
        self::assertSame([0, 1, 1, 0], array_column($flat, 'depth'));
    }

    #[Test]
    public function validationRejectsEmptyTitleBadSlugAndForeignParent(): void
    {
        $check = classifiedsValidateCategory(['title' => '  ', 'slug' => 'ok-adres', 'parent' => 'net-takoy']);
        self::assertArrayHasKey('title', $check['errors']);
        self::assertArrayHasKey('parent', $check['errors']);

        $reserved = classifiedsValidateCategory(['title' => 'Кабинет', 'slug' => 'cabinet', 'parent' => '']);
        self::assertArrayHasKey('slug', $reserved['errors']);
        self::assertStringContainsString('служебной страницей', $reserved['errors']['slug']);

        $short = classifiedsValidateCategory(['title' => 'Ав', 'slug' => 'ab', 'parent' => '']);
        self::assertArrayHasKey('slug', $short['errors']);
    }

    #[Test]
    public function validationBuildsSlugFromTitleAndNumbersCollisions(): void
    {
        self::makeCategory('avto', 'Авто');
        $check = classifiedsValidateCategory(['title' => 'Авто', 'slug' => '', 'parent' => '']);
        self::assertSame([], $check['errors']);
        self::assertSame('avto-2', $check['values']['slug']);
    }

    #[Test]
    public function parentCannotBeItselfOrItsOwnDescendant(): void
    {
        $root = self::makeCategory('avto', 'Авто');
        $child = self::makeCategory('moto', 'Мото', $root);

        $itself = classifiedsValidateCategory(['title' => 'Авто', 'slug' => 'avto', 'parent' => $root], $root);
        self::assertArrayHasKey('parent', $itself['errors']);

        $ownBranch = classifiedsValidateCategory(['title' => 'Авто', 'slug' => 'avto', 'parent' => $child], $root);
        self::assertArrayHasKey('parent', $ownBranch['errors']);
        self::assertStringContainsString('круг', $ownBranch['errors']['parent']);

        // Чужой потомок законен: ветку переносят под другую.
        $other = self::makeCategory('dom', 'Дом');
        $valid = classifiedsValidateCategory(['title' => 'Авто', 'slug' => 'avto', 'parent' => $other], $root);
        self::assertSame([], $valid['errors']);
    }

    #[Test]
    public function hiddenParentHidesWholeBranchOnShowcase(): void
    {
        $root = self::makeCategory('avto', 'Авто');
        $child = self::makeCategory('moto', 'Мото', $root);
        $grandchild = self::makeCategory('motocikly', 'Мотоциклы', $child);
        $visible = self::makeCategory('dom', 'Дом');

        self::assertSame(['avto', 'moto', 'motocikly', 'dom'], array_column(classifiedsCategoryVisible(), 'slug'));

        run('UPDATE ' . t('classifieds_category') . ' SET visible = 0 WHERE id = ?', [$child]);
        self::assertSame(['avto', 'dom'], array_column(classifiedsCategoryVisible(), 'slug'));

        // Скрытая категория не открывается, скрытый родитель не прячет открытого ребёнка —
        // ребёнок тоже исчезает из навигации, потому что его место в дереве невидимо.
        run('UPDATE ' . t('classifieds_category') . ' SET visible = 1 WHERE id = ?', [$child]);
        run('UPDATE ' . t('classifieds_category') . ' SET visible = 0 WHERE id = ?', [$grandchild]);
        self::assertSame(['avto', 'moto', 'dom'], array_column(classifiedsCategoryVisible(), 'slug'));
        self::assertNotContains('motocikly', array_column(classifiedsCategoryVisible(), 'slug'));
    }

    #[Test]
    public function deletingOccupiedCategoryIsRefusedAndChildCounted(): void
    {
        $seller = self::makeSeller();
        $root = self::makeCategory('avto', 'Авто');
        $child = self::makeCategory('moto', 'Мото', $root);
        self::makeListing($seller, $child);

        $refused = classifiedsCategoryDelete($root);
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('Категория занята', (string) $refused['error']);
        self::assertNotNull(classifiedsCategoryById($root));
    }

    #[Test]
    public function deletingEmptyCategoryMovesChildrenToRoot(): void
    {
        $root = self::makeCategory('avto', 'Авто');
        $child = self::makeCategory('moto', 'Мото', $root);

        $deleted = classifiedsCategoryDelete($root);
        self::assertTrue($deleted['ok']);
        self::assertSame('category_deleted', $deleted['notice']);
        self::assertNull(classifiedsCategoryById($root));
        self::assertNull(self::category($child)['parent_id']);
    }

    #[Test]
    public function toggleFlipsVisibilityAndSwapChangesOrder(): void
    {
        $first = self::makeCategory('pervyy', 'Первый', null, 1);
        $second = self::makeCategory('vtoroy', 'Второй', null, 2);

        $toggled = classifiedsCategoryToggle($second);
        self::assertTrue($toggled['ok']);
        self::assertFalse(self::category($second)['visible']);

        $swapped = classifiedsSwapCategories($second, $first);
        self::assertTrue($swapped['ok']);
        self::assertSame(['vtoroy', 'pervyy'], array_column(classifiedsCategoryFlatTree(), 'slug'));
    }

    #[Test]
    public function updateMovesNodeToTheEndOfNewParentBranch(): void
    {
        $root = self::makeCategory('avto', 'Авто');
        $other = self::makeCategory('dom', 'Дом');
        $existing = self::makeCategory('komnaty', 'Комнаты', $other, 1);
        $moving = self::makeCategory('moto', 'Мото', $root, 1);

        $check = classifiedsValidateCategory(['title' => 'Мото', 'slug' => 'moto', 'parent' => $other], $moving);
        self::assertSame([], $check['errors']);

        $updated = classifiedsUpdateCategory($moving, $check['values']);
        self::assertTrue($updated['ok']);
        self::assertSame($other, self::category($moving)['parent_id']);
        self::assertSame(2, self::category($moving)['order_index']);
        self::assertGreaterThan(
            self::category($existing)['order_index'],
            self::category($moving)['order_index']
        );
    }
}
