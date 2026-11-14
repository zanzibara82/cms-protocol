<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.2: построчное сравнение текста, полей, SEO и секций. Секции сравниваются по
 * стабильному идентификатору, поэтому перестановка не выдаётся за удаление и создание.
 */
final class Release22DiffTest extends TestCase
{
    public function testПострочноеСравнениеПоказываетЗаменуИСохранённыеСтроки(): void
    {
        $diff = contentDiffText("Первая\nВторая\nТретья", "Первая\nИсправленная\nТретья");
        $counts = contentDiffCounts($diff);

        self::assertSame(2, $counts['equal']);
        self::assertSame(1, $counts['add']);
        self::assertSame(1, $counts['remove']);
        self::assertContains(['type' => 'remove', 'text' => 'Вторая'], $diff);
        self::assertContains(['type' => 'add', 'text' => 'Исправленная'], $diff);
        self::assertSame('Первая', $diff[0]['text']);
        self::assertSame('Третья', $diff[array_key_last($diff)]['text']);
    }

    public function testПустойТекстСчитаетсяДобавлениемИлиУдалением(): void
    {
        self::assertSame(
            [['type' => 'add', 'text' => 'Строка']],
            contentDiffText('', 'Строка')
        );
        self::assertSame(
            [['type' => 'remove', 'text' => 'Строка']],
            contentDiffText('Строка', '')
        );
        self::assertSame([['type' => 'add', 'text' => '']], contentDiffText('', ''));
    }

    public function testНормализацияПереносовСтрок(): void
    {
        self::assertSame(['Первая', 'Вторая'], contentDiffSplitLines("Первая\r\nВторая"));
        self::assertSame(['Первая', 'Вторая'], contentDiffSplitLines("Первая\rВторая"));
    }

    public function testПоляСравниваютсяПоПодписямИТолькоИзменённые(): void
    {
        $fields = contentDiffFields(
            ['title' => 'Старый', 'slug' => 'staryy', 'description' => 'Одинаково', 'noindex' => 0, 'unknown' => 'x'],
            ['title' => 'Новый', 'slug' => 'staryy', 'description' => 'Одинаково', 'noindex' => 1, 'unknown' => 'y']
        );

        $byKey = [];
        foreach ($fields as $field) {
            $byKey[(string) $field['key']] = $field;
        }

        self::assertArrayHasKey('title', $byKey);
        self::assertSame('Заголовок', $byKey['title']['label']);
        self::assertSame('Старый', $byKey['title']['old']);
        self::assertSame('Новый', $byKey['title']['new']);
        self::assertTrue($byKey['title']['changed']);
        self::assertArrayHasKey('noindex', $byKey);
        self::assertSame('1', $byKey['noindex']['new']);
        self::assertArrayNotHasKey('slug', $byKey, 'Совпавшие поля в сравнение не попадают');
        self::assertArrayNotHasKey('unknown', $byKey, 'Неизвестные поля не подписываются');
        self::assertArrayNotHasKey('description', $byKey);
    }

    public function testСекцииСравниваютсяПоИдентификатору(): void
    {
        $old = [
            ['id' => 's1', 'sec_key' => 'pervyy', 'kind' => 'hero', 'title' => 'Первый'],
            ['id' => 's2', 'sec_key' => 'vtoroy', 'kind' => 'text', 'title' => 'Второй'],
        ];
        $new = [
            ['id' => 's2', 'sec_key' => 'vtoroy', 'kind' => 'text', 'title' => 'Второй изменённый'],
            ['id' => 's1', 'sec_key' => 'pervyy', 'kind' => 'hero', 'title' => 'Первый'],
        ];

        $diff = contentDiffPayloads(['sections' => $old], ['sections' => $new]);

        self::assertSame(0, $diff['summary']['added']);
        self::assertSame(0, $diff['summary']['removed']);
        self::assertSame(1, $diff['summary']['changed'], 'Изменилась только одна секция');
        self::assertSame(2, $diff['summary']['moved'], 'Обе секции поменялись местами');
        self::assertSame(['id:s1', 'id:s2'], $diff['sections']['order_before']);
        self::assertSame(['id:s2', 'id:s1'], $diff['sections']['order_after']);
        self::assertSame('s2', (string) $diff['sections']['changed'][0]['id']);
        self::assertSame('Второй изменённый', (string) $diff['sections']['changed'][0]['name']);
        self::assertSame(1, (int) $diff['sections']['moved'][0]['old_position']);
        self::assertSame(2, (int) $diff['sections']['moved'][0]['new_position']);
    }

    public function testДобавленныеИУдалённыеСекции(): void
    {
        $old = [['sec_key' => 'odin', 'kind' => 'hero', 'title' => 'Один']];
        $new = [
            ['sec_key' => 'odin', 'kind' => 'hero', 'title' => 'Один'],
            ['sec_key' => 'dva', 'kind' => 'text', 'title' => 'Два'],
        ];

        $diff = contentDiffPayloads(['sections' => $old], ['sections' => $new]);

        self::assertSame(1, $diff['summary']['added']);
        self::assertSame(0, $diff['summary']['removed']);
        self::assertSame('dva', (string) $diff['sections']['added'][0]['sec_key']);
        self::assertSame(['key:odin'], $diff['sections']['order_before']);
        self::assertSame(['key:odin', 'key:dva'], $diff['sections']['order_after']);

        $removed = contentDiffPayloads(['sections' => $new], ['sections' => $old]);
        self::assertSame(0, $removed['summary']['added']);
        self::assertSame(1, $removed['summary']['removed']);
        self::assertSame('dva', (string) $removed['sections']['removed'][0]['sec_key']);
    }

    public function testSeoСравниваетсяОтдельнымБлоком(): void
    {
        $diff = contentDiffPayloads(
            ['seo' => ['title' => 'Старый SEO', 'robots' => 'index, follow', 'images' => ['/a.jpg']]],
            ['seo' => ['title' => 'Новый SEO', 'robots' => 'noindex, nofollow', 'images' => ['/a.jpg', '/b.jpg']]]
        );

        $byKey = [];
        foreach ($diff['seo'] as $field) {
            $byKey[(string) $field['key']] = $field;
        }

        self::assertArrayHasKey('title', $byKey);
        self::assertArrayHasKey('robots', $byKey);
        self::assertArrayHasKey('images', $byKey);
        self::assertSame('Старый SEO', $byKey['title']['old']);
        self::assertSame('Новый SEO', $byKey['title']['new']);
        self::assertArrayNotHasKey('canonical', $byKey, 'Совпавшие SEO-поля не выводятся');
    }

    public function testОбщийТекстСобираетсяИзПолейИСекций(): void
    {
        $text = contentDiffHumanText([
            'title' => 'Заголовок',
            'description' => 'Описание',
            'sections' => [[
                'title' => 'Блок',
                'lead_text' => 'Текст блока',
                'items' => [['title' => 'Пункт']],
            ]],
        ]);

        self::assertSame("Заголовок\nОписание\nБлок\nТекст блока\nПункт", $text);
    }
}
