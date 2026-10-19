<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * Характеристики рубрик: слаг-ключи, справочник CHOICE и значения на товарах,
 * которые кормят фильтры витрины и таблицу характеристик.
 */
final class CommerceAttributeTest extends CommerceDbTestCase
{
    // -----------------------------------------------------------------------
    // Валидация
    // -----------------------------------------------------------------------

    public function testПустоеНазваниеРежется(): void
    {
        $result = commerceValidateAttribute(['label' => '', 'type' => 'CHOICE'], '');

        self::assertArrayHasKey('label', $result['errors']);
    }

    public function testТипВыбираетсяИзСпискаИСлагИзНазвания(): void
    {
        $badType = commerceValidateAttribute(['label' => 'Мощность', 'type' => 'FLOAT'], '');
        self::assertArrayHasKey('type', $badType['errors']);

        $good = commerceValidateAttribute(['label' => 'Мощность', 'type' => 'NUMBER', 'unit' => 'Вт'], '');
        self::assertSame([], $good['errors']);
        self::assertSame('moschnost', $good['values']['slug']);
        self::assertSame('Вт', $good['values']['unit']);
    }

    public function testСлагАтрибутаУникаленСредиВсех(): void
    {
        $category = self::makeCategory('kolonki', 'Колонки');
        $first = commerceValidateAttribute(['label' => 'Мощность'], '');
        commerceCreateAttribute($category, $first['values']);

        $second = commerceValidateAttribute(['label' => 'Другая', 'slug' => 'Мощность' /* → moschnost */], '');

        self::assertArrayHasKey('slug', $second['errors']);
        self::assertTrue($second['values']['slug'] === '');
    }

    public function testСлужебныеКючиАдресаЗапрещены(): void
    {
        $result = commerceValidateAttribute(['label' => 'Поиск', 'slug' => 'q'], '');
        self::assertArrayHasKey('slug', $result['errors']);
        self::assertSame('', $result['values']['slug']);
    }

    public function testТекущийСлагНеМешаетПравке(): void
    {
        $category = self::makeCategory('kolonki', 'Колонки');
        $first = commerceValidateAttribute(['label' => 'Мощность'], '');
        commerceCreateAttribute($category, $first['values']);
        $attribute = commerceAttributes($category)[0];

        $same = commerceValidateAttribute(['label' => 'Мощность', 'slug' => 'Мощность' /* → moschnost */], 'moschnost');

        self::assertSame('moschnost', $same['values']['slug']);
        self::assertSame([], $same['errors']);
        self::assertSame('moschnost', (string) $attribute['slug']);
    }

    // -----------------------------------------------------------------------
    // Жизненный цикл
    // -----------------------------------------------------------------------

    public function testСозданиеСтавитПозициюИУдалениеЧиститКаскадом(): void
    {
        $category = self::makeCategory('kolonki', 'Колонки');
        $first = commerceValidateAttribute(['label' => 'Мощность', 'type' => 'NUMBER', 'unit' => 'Вт'], '');
        commerceCreateAttribute($category, $first['values']);
        $second = commerceValidateAttribute(['label' => 'Цвет'], '');
        commerceCreateAttribute($category, $second['values']);

        $attributes = commerceAttributes($category);
        self::assertCount(2, $attributes);
        self::assertSame('moschnost', (string) $attributes[0]['slug']);
        self::assertSame(1, (int) $attributes[0]['order_index']);

        $id = (string) $attributes[0]['id'];
        insert('commerce_product_attribute', ['product_id' => self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']), 'attribute_id' => $id, 'value' => '20']);
        insert('commerce_attribute_choice', ['id' => newId(), 'attribute_id' => $id, 'value' => '20', 'order_index' => 1]);

        $result = commerceDeleteAttribute($id);

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame('attribute_deleted', $result['notice']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_product_attribute')));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_attribute_choice')));
        self::assertCount(1, commerceAttributes($category));
    }

    public function testНесуществующийАтрибутОтказывается(): void
    {
        $result = commerceDeleteAttribute('A' . str_repeat('0', 23));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('уже нет', $result['error']);
    }

    // -----------------------------------------------------------------------
    // Значения на товарах
    // -----------------------------------------------------------------------

    public function testЗначенияПишутсяПоРубрикамТовара(): void
    {
        $category = self::makeCategory('kolonki', 'Колонки');
        $other = self::makeCategory('chayniki', 'Чайники');

        $power = commerceValidateAttribute(['label' => 'Мощность', 'type' => 'NUMBER', 'unit' => 'Вт'], '');
        commerceCreateAttribute($category, $power['values']);
        $color = commerceValidateAttribute(['label' => 'Цвет'], '');
        commerceCreateAttribute($category, $color['values']);
        $volume = commerceValidateAttribute(['label' => 'Объём'], '');
        commerceCreateAttribute($other, $volume['values']);

        $attributes = commerceAttributes($category);
        $moshnost = (string) $attributes[0]['id'];
        $tsvet = (string) $attributes[1]['id'];
        $obem = (string) commerceAttributes($other)[0]['id'];

        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        self::attach($product, $category);

        commerceSaveProductAttributes($product, [$category], [
            'attr_' . $moshnost => '20',
            'attr_' . $obem => '1,7 л',
        ]);

        $rows = commerceProductAttributes($product);
        self::assertCount(1, $rows);
        self::assertSame('20', (string) $rows[0]['value']);
        self::assertEqualsWithDelta(20.0, (float) $rows[0]['value_num'], 0.001);

        // Чужой атрибут (объём из другой рубрики) записан не был;-choice-цвет
        // дописался в справочник рубрики при сохранении товара.
        commerceSaveProductAttributes($product, [$category], [
            'attr_' . $tsvet => 'Красный',
        ]);

        $choices = commerceAttributeChoices($tsvet);
        self::assertCount(1, $choices);
        self::assertSame('Красный', (string) $choices[0]['value']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('commerce_product_attribute') . ' WHERE product_id = ?', [$product]));

        // Тот же цвет второй раз не плодит справочник.
        commerceSaveProductAttributes($product, [$category], ['attr_' . $tsvet => 'Красный']);
        self::assertCount(1, commerceAttributeChoices($tsvet));
    }

    public function testНечислоВЧисловойХарактеристикеОтбрасывается(): void
    {
        $category = self::makeCategory('kolonki', 'Колонки');
        $power = commerceValidateAttribute(['label' => 'Мощность', 'type' => 'NUMBER'], '');
        commerceCreateAttribute($category, $power['values']);
        $moshnost = (string) commerceAttributes($category)[0]['id'];

        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        commerceSaveProductAttributes($product, [$category], ['attr_' . $moshnost => 'до 100 Вт']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_product_attribute')));

        commerceSaveProductAttributes($product, [$category], ['attr_' . $moshnost => '1 250,5']);
        $rows = commerceProductAttributes($product);
        self::assertSame('1 250,5', (string) $rows[0]['value']);
        self::assertEqualsWithDelta(1250.5, (float) $rows[0]['value_num'], 0.001);
    }

    public function testПерезаписьЗначенийТовараЧиститПрошлые(): void
    {
        $category = self::makeCategory('kolonki', 'Колонки');
        $color = commerceValidateAttribute(['label' => 'Цвет'], '');
        commerceCreateAttribute($category, $color['values']);
        $tsvet = (string) commerceAttributes($category)[0]['id'];

        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);
        self::attach($product, $category);

        commerceSaveProductAttributes($product, [$category], ['attr_' . $tsvet => 'Красный']);
        commerceSaveProductAttributes($product, [$category], ['attr_' . $tsvet => 'Синий', 'attr_chuzhoy' => 'мусор']);

        $rows = commerceProductAttributes($product);
        self::assertCount(1, $rows);
        self::assertSame('Синий', (string) $rows[0]['value']);
    }

    public function testБезРубрикЗначенияНеПишутся(): void
    {
        $category = self::makeCategory('kolonki', 'Колонки');
        $color = commerceValidateAttribute(['label' => 'Цвет'], '');
        commerceCreateAttribute($category, $color['values']);
        $tsvet = (string) commerceAttributes($category)[0]['id'];

        $product = self::makeProduct(['slug' => 'kolonka', 'sku' => 'SPK-1']);

        commerceSaveProductAttributes($product, [], ['attr_' . $tsvet => 'Красный']);

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_product_attribute')));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_attribute_choice')));
    }
}
