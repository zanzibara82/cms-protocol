<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/auth.php';

final class PasswordTest extends TestCase
{
    public function testХешПроверяетсяИНеРавенПаролю(): void
    {
        $hash = hashPassword('проверочный-пароль');

        self::assertNotSame('проверочный-пароль', $hash);
        self::assertTrue(verifyPassword('проверочный-пароль', $hash));
        self::assertFalse(verifyPassword('другой-пароль', $hash));
    }

    public function testВыборАлгоритмаЗависитОтПоддержкиArgon2id(): void
    {
        $supported = defined('PASSWORD_ARGON2ID');

        self::assertSame($supported ? 'argon2id' : 'bcrypt', passwordAlgorithm());
        self::assertStringStartsWith($supported ? '$argon2id$' : '$2y$', hashPassword('проверочный-пароль'));
    }

    public function testЯвныйАлгоритмИспользуетсяДляПроверкиВторойВетки(): void
    {
        // На dev-контейнере Argon2id может не быть: вторая ветка обязана работать всегда.
        $bcrypt = hashPassword('проверочный-пароль', PASSWORD_BCRYPT);

        self::assertStringStartsWith('$2y$', $bcrypt);
        self::assertTrue(verifyPassword('проверочный-пароль', $bcrypt));
    }

    public function testХешСтарогоАлгоритмаПомечаетсяКОбновлению(): void
    {
        $bcrypt = hashPassword('проверочный-пароль', PASSWORD_BCRYPT);

        if (defined('PASSWORD_ARGON2ID')) {
            self::assertTrue(needsRehash($bcrypt), 'bcrypt-хеш нужно пересчитать в argon2id при входе');
        } else {
            self::assertFalse(needsRehash($bcrypt));
        }

        self::assertFalse(needsRehash(hashPassword('проверочный-пароль')));
    }

    public function testРолиАдминистратораИРедактораДаютДоступАУченикаНет(): void
    {
        self::assertTrue(roleGrantsManage(['role' => 'admin', 'email' => 'a@b.c', 'blocked' => false]));
        self::assertTrue(roleGrantsManage(['role' => 'editor', 'email' => 'a@b.c', 'blocked' => false]));
        self::assertFalse(roleGrantsManage(['role' => 'student', 'email' => 'a@b.c', 'blocked' => false]));
        self::assertFalse(roleGrantsManage(['role' => 'admin', 'email' => 'a@b.c', 'blocked' => true]));
        self::assertFalse(roleGrantsManage(null));
    }
}
