<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// F1: подстановка префикса таблиц живёт в одном месте и обязана быть точным no-op при префиксе
// «tbl_», менять имена таблиц и индексов, но не трогать строковые литералы (в т.ч. с «»-экранированием).
final class SqlTablePrefixTest extends TestCase
{
    public function testПриПрефиксеTblЕстьТочноеОтсутствиеИзменений(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS tbl_user (\n  email VARCHAR(190)\n) ENGINE=InnoDB;";

        self::assertSame($sql, sqlWithTablePrefix($sql, 'tbl_'), 'При настроенном «tbl_» функция не должна менять ни символа');
    }

    public function testПодстановкаМеняетИмяТаблицы(): void
    {
        self::assertSame(
            'CREATE TABLE IF NOT EXISTS shop_user (id INT)',
            sqlWithTablePrefix('CREATE TABLE IF NOT EXISTS tbl_user (id INT)', 'shop_')
        );
    }

    public function testПодстановкаМеняетИмяИндекса(): void
    {
        self::assertSame(
            'UNIQUE KEY shop_user_email (email)',
            sqlWithTablePrefix('UNIQUE KEY tbl_user_email (email)', 'shop_')
        );
    }

    public function testСтроковыйЛитералНеМеняется(): void
    {
        $in = "INSERT INTO tbl_note (title) VALUES ('текст tbl_ внутри')";
        $out = "INSERT INTO shop_note (title) VALUES ('текст tbl_ внутри')";

        self::assertSame($out, sqlWithTablePrefix($in, 'shop_'), 'Данные в одинарных кавычках трогать нельзя');
    }

    public function testДвойнаяКавычкаНеСдвигаетГраницуЛитерала(): void
    {
        $in = "INSERT INTO tbl_note (title) VALUES ('it''s tbl_ ок')";
        $out = "INSERT INTO shop_note (title) VALUES ('it''s tbl_ ок')";

        self::assertSame($out, sqlWithTablePrefix($in, 'shop_'), '«»-экранирование внутри литерала не должно ломать границу');
    }
}
