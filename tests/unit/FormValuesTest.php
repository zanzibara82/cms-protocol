<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/form.php';
require_once dirname(__DIR__, 2) . '/core/notice.php';

final class FormValuesTest extends TestCase
{
    public function testВозвратВведённогоСодержитВсеПоляСписка(): void
    {
        $values = formValues(['title' => 'Заголовок', 'lead' => null], ['title', 'lead', 'cover']);

        self::assertSame(['title' => 'Заголовок', 'lead' => '', 'cover' => ''], $values);
    }

    public function testЛишниеКлючиНеПопадаютВВозврат(): void
    {
        $values = formValues(['title' => 'Да', '_token' => 'секрет', 'password' => 'чужое'], ['title']);

        self::assertSame(['title' => 'Да'], $values);
    }

    public function testЧекбоксСчитаетсяВключённымПоТрёмЗначениям(): void
    {
        self::assertTrue(isOn('on'));
        self::assertTrue(isOn('1'));
        self::assertTrue(isOn('true'));
        self::assertFalse(isOn(''));
        self::assertFalse(isOn('off'));
        self::assertFalse(isOn(null));
    }

    public function testОшибкаПоляПустаЕслиОшибкиНет(): void
    {
        self::assertSame('Заголовок короче 3 символов.', fieldError(['title' => 'Заголовок короче 3 символов.'], 'title'));
        self::assertSame('', fieldError([], 'title'));
    }

    public function testОтказФормыНесётТекстИПустыеЗначения(): void
    {
        self::assertSame(['errors' => ['form' => 'Не получилось'], 'values' => []], formError('Не получилось'));
    }

    public function testОтказПоПолямНесётИОшибкиИВведённое(): void
    {
        $result = errorResult(['slug' => 'Занято'], ['slug' => 'novost']);

        self::assertSame('Занято', $result['errors']['slug']);
        self::assertSame('novost', $result['values']['slug']);
    }

    public function testСловарьУведомленийОтдаётТекстПоКлючу(): void
    {
        self::assertSame('Вы вышли из аккаунта.', noticeText('signed-out', CORE_NOTICE_TEXT));
        self::assertNull(noticeText('несуществующий', CORE_NOTICE_TEXT));
    }

    public function testНеизвестныйКлючНеПечатаетТекстАдреснойСтроки(): void
    {
        // Иначе ?notice=<script> стал бы дырой в разметке.
        self::assertNull(noticeText('<script>alert(1)</script>', CORE_NOTICE_TEXT));
    }
}
