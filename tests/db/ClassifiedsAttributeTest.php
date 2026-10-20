<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use PHPUnit\Framework\Attributes\Test;

/**
 * Характеристики категорий: наследование, проверка полей, значения объявлений и фильтры.
 */
final class ClassifiedsAttributeTest extends ClassifiedsDbTestCase
{
    #[Test]
    public function characteristicsAreInheritedFromParentToChild(): void
    {
        $parent = self::makeCategory('avto', 'Авто');
        $child = self::makeCategory('zapchasti', 'Запчасти', $parent);
        $year = self::makeAttribute($parent, 'year', 'Год', 'number');
        $body = self::makeAttribute($child, 'body', 'Кузов', 'select', ['Седан', 'Хэтчбек']);

        $inherited = classifiedsAttributesForCategory($child);
        self::assertSame([$year, $body], array_column($inherited, 'id'));

        $own = classifiedsAttributesForCategory($parent);
        self::assertSame([$year], array_column($own, 'id'));
        self::assertSame([$year, $body], array_column(classifiedsAttributesForCategory($child), 'id'));
        self::assertSame([$body], array_column(classifiedsAttributesOwn($child), 'id'));
    }

    #[Test]
    public function validationBuildsKeyAndRejectsBadListsAndDuplicates(): void
    {
        $category = self::makeCategory('avto', 'Авто');

        $built = classifiedsValidateAttribute(['title' => 'Год выпуска', 'key' => '', 'kind' => 'number', 'unit' => 'год'], $category);
        self::assertSame([], $built['errors']);
        self::assertSame('god-vypuska', $built['values']['key_slug']);
        self::assertSame('год', $built['values']['unit']);
        self::assertSame([], $built['values']['options']);

        classifiedsCreateAttribute($built['values']);

        $duplicate = classifiedsValidateAttribute(['title' => 'Год выпуска', 'key' => 'god-vypuska', 'kind' => 'text'], $category);
        self::assertArrayHasKey('key', $duplicate['errors']);

        $noOptions = classifiedsValidateAttribute(['title' => 'Кузов', 'key' => 'body', 'kind' => 'select', 'options' => ''], $category);
        self::assertArrayHasKey('options', $noOptions['errors']);

        $badKey = classifiedsValidateAttribute(['title' => 'Кузов', 'key' => '1body', 'kind' => 'text'], $category);
        self::assertArrayHasKey('key', $badKey['errors']);

        $noCategory = classifiedsValidateAttribute(['title' => 'Кузов', 'key' => 'body', 'kind' => 'text'], '');
        self::assertArrayHasKey('category', $noCategory['errors']);
    }

    #[Test]
    public function listOptionsAreTrimmedAndDeduplicated(): void
    {
        $category = self::makeCategory('avto', 'Авто');
        $check = classifiedsValidateAttribute([
            'title' => 'Кузов',
            'key' => 'body',
            'kind' => 'select',
            'options' => "Седан\n\n Седан \nХэтчбек\n",
        ], $category);

        self::assertSame([], $check['errors']);
        self::assertSame(['Седан', 'Хэтчбек'], $check['values']['options']);
    }

    #[Test]
    public function usedCharacteristicCannotBeDeleted(): void
    {
        $category = self::makeCategory('avto', 'Авто');
        $seller = self::makeSeller();
        $listing = self::makeListing($seller, $category);
        $attribute = self::makeAttribute($category, 'year', 'Год', 'number');
        classifiedsReplaceListingAttributes($listing, [
            $attribute => ['value_text' => null, 'value_number' => 2019.0],
        ]);

        $blocked = classifiedsDeleteAttribute($attribute);
        self::assertFalse($blocked['ok']);
        self::assertStringContainsString('занята', $blocked['error']);
        self::assertNotNull(classifiedsAttributeById($attribute));

        classifiedsReplaceListingAttributes($listing, []);
        $deleted = classifiedsDeleteAttribute($attribute);
        self::assertTrue($deleted['ok']);
        self::assertSame('attribute_deleted', $deleted['notice']);
        self::assertNull(classifiedsAttributeById($attribute));
    }

    #[Test]
    public function listingValuesAreCheckedAgainstTheCategorySet(): void
    {
        $category = self::makeCategory('avto', 'Авто');
        $year = self::makeAttribute($category, 'year', 'Год', 'number', [], 1);
        $body = self::makeAttribute($category, 'body', 'Кузов', 'select', ['Седан', 'Хэтчбек']);
        $note = self::makeAttribute($category, 'note', 'Примечание');
        $keys = self::makeAttribute($category, 'keys', 'Ключи', 'bool');

        $missing = classifiedsValidateListingAttributes(['attrs' => []], $category);
        self::assertArrayHasKey('attrs_' . $year, $missing['errors']);

        $check = classifiedsValidateListingAttributes(['attrs' => [
            $year => '2 019',
            $body => 'Седан',
            $note => 'Один владелец',
            $keys => '1',
            'foreign' => 'игнор',
        ]], $category);
        self::assertSame([], $check['errors']);
        self::assertSame(2019.0, $check['values'][$year]['value_number']);
        self::assertSame('Седан', $check['values'][$body]['value_text']);
        self::assertSame('Один владелец', $check['values'][$note]['value_text']);
        self::assertSame(1.0, $check['values'][$keys]['value_number']);
        self::assertArrayNotHasKey('foreign', $check['values']);

        $bad = classifiedsValidateListingAttributes(['attrs' => [
            $year => 'две тысячи',
            $body => 'Лифтбек',
            $note => '',
            $keys => '',
        ]], $category);
        self::assertArrayHasKey('attrs_' . $year, $bad['errors']);
        self::assertArrayHasKey('attrs_' . $body, $bad['errors']);
    }

    #[Test]
    public function storedValuesAreDisplayedWithUnitsAndReplacedWholesale(): void
    {
        $category = self::makeCategory('avto', 'Авто');
        $seller = self::makeSeller();
        $listing = self::makeListing($seller, $category);
        $year = self::makeAttribute($category, 'year', 'Год', 'number');
        $body = self::makeAttribute($category, 'body', 'Кузов', 'select', ['Седан']);
        $keys = self::makeAttribute($category, 'keys', 'Ключи', 'bool');
        $note = self::makeAttribute($category, 'note', 'Примечание');

        classifiedsReplaceListingAttributes($listing, [
            $year => ['value_text' => null, 'value_number' => 2019.0],
            $body => ['value_text' => 'Седан', 'value_number' => null],
            $keys => ['value_text' => null, 'value_number' => 0.0],
            $note => ['value_text' => null, 'value_number' => null],
        ]);

        $values = classifiedsListingAttributeValues($listing);
        self::assertSame(['Год', 'Кузов', 'Ключи'], array_column($values, 'title'));
        self::assertSame('2 019 см', $values[0]['display']);
        self::assertSame('Седан', $values[1]['display']);
        self::assertSame('Нет', $values[2]['display']);

        $form = classifiedsListingAttributeFormValues($listing);
        self::assertSame('2019', $form[$year]);
        self::assertSame('Седан', $form[$body]);
        self::assertSame('0', $form[$keys]);

        classifiedsReplaceListingAttributes($listing, [
            $note => ['value_text' => 'Только примечание', 'value_number' => null],
        ]);
        self::assertSame([$note], array_column(classifiedsListingAttributeValues($listing), 'id'));
    }

    #[Test]
    public function filtersAcceptOnlyKnownAndFilterableValues(): void
    {
        $category = self::makeCategory('avto', 'Авто');
        $year = self::makeAttribute($category, 'year', 'Год', 'number');
        $body = self::makeAttribute($category, 'body', 'Кузов', 'select', ['Седан', 'Хэтчбек']);
        $keys = self::makeAttribute($category, 'keys', 'Ключи', 'bool');
        $hidden = self::makeAttribute($category, 'vin', 'VIN', 'text', [], 0, 0);
        $attributes = classifiedsAttributesForCategory($category);

        $check = classifiedsFiltersFromQuery(['f' => [
            $year => ['min' => '2010', 'max' => 'мусор'],
            $body => ['value' => 'Седан'],
            $keys => ['value' => '1'],
            $hidden => ['value' => 'XTA'],
            'foreign' => ['value' => 'игнор'],
        ]], $attributes);

        self::assertTrue($check['active']);
        self::assertSame(['kind' => 'number', 'min' => '2010'], $check['values'][$year]);
        self::assertSame(['kind' => 'select', 'value' => 'Седан'], $check['values'][$body]);
        self::assertSame(['kind' => 'bool', 'value' => '1'], $check['values'][$keys]);
        self::assertArrayNotHasKey($hidden, $check['values']);
        self::assertArrayNotHasKey('foreign', $check['values']);

        $bad = classifiedsFiltersFromQuery(['f' => [
            $year => ['min' => '-5'],
            $body => ['value' => 'Лифтбек'],
            $keys => ['value' => '2'],
        ]], $attributes);
        self::assertSame([], $bad['values']);
        self::assertFalse($bad['active']);
    }
}
