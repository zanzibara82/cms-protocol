<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Режим техобслуживания: пока флажок в настройках включён, публичная часть отдаёт заглушку, а
// вход и админка остаются открытыми — иначе выключить режим было бы нечем, и сайт остался бы
// закрытым навсегда. Правило путей проверяется отдельным помощником без базы: оно решает, что
// увидит человек, и ошибка здесь либо прячет админку, либо оставляет открытым весь сайт.
final class MaintenanceModeTest extends TestCase
{
    public function testПубличныеАдресаЗакрыты(): void
    {
        foreach (['/', '/news', '/news/pervyy-post', '/kursy', '/feed.xml', '/sitemap.xml', '/robots.txt', '/business-english'] as $path) {
            self::assertFalse(maintenanceAllowsPath($path), 'Публичный адрес обязан закрываться: ' . $path);
        }
    }

    public function testВходИАдминкаОстаютсяОткрытыми(): void
    {
        // Без входа администратор не попадёт в настройки и не снимет флажок; выход и сброс пароля
        // нужны по той же причине — это части той же двери, а не публичный контент.
        foreach (['/login', '/logout', '/admin', '/admin/settings', '/admin/pages/home', '/forgot-password', '/reset-password/abc'] as $path) {
            self::assertTrue(maintenanceAllowsPath($path), 'Служебный адрес обязан оставаться открытым: ' . $path);
        }
    }

    public function testСлужебныеАдресаНеПутаютсяСПубличнымиПоПрефиксу(): void
    {
        // «/administrator» начинается с тех же букв, что «/admin», но админкой не является:
        // сравнение по префиксу без границы сегмента оставило бы его открытым.
        self::assertFalse(maintenanceAllowsPath('/administrator'));
        self::assertFalse(maintenanceAllowsPath('/loginfo'));
        self::assertFalse(maintenanceAllowsPath('/adminx'));
    }

    public function testСайтОткрытСотрудникамНоНеГостям(): void
    {
        // Список ролей — обе стороны правила: администратор и редактор правят содержимое и обязаны
        // видеть результат своей работы; блокировка и чужая роль оставляют заглушку.
        self::assertContains(ROLES['admin'], MAINTENANCE_STAFF_ROLES);
        self::assertContains(ROLES['editor'], MAINTENANCE_STAFF_ROLES);
        self::assertNotContains('student', MAINTENANCE_STAFF_ROLES);
    }
}
