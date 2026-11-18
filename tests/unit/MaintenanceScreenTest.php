<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Заглушка техобслуживания собирается из тех же настроек, что правит экран «Настройки»:
// текст объяснения и картинка. Разметка не должна зависеть от базы, поэтому проверяется отдельным
// помощником, который получает значения аргументами, — так страж не требует установленной базы.
final class MaintenanceScreenTest extends TestCase
{
    public function testЗаглушкаНесётЗаголовокИТекстИзНастроек(): void
    {
        $html = partial('maintenance', ['text' => 'Идут технические работы. Вернитесь через час.', 'image' => '/assets/img/cats/cat-sad.png']);

        self::assertStringContainsString('Сайт на техобслуживании', $html, 'заголовок заглушки');
        self::assertStringContainsString('Идут технические работы. Вернитесь через час.', $html, 'свой текст из настроек');
        self::assertStringContainsString('/assets/img/cats/cat-sad.png', $html, 'картинка из настроек');
    }

    public function testПустойТекстПечатаетПоУмолчанию(): void
    {
        // Пустое поле — не пустой экран: человек видит объяснение, а не голое «на техобслуживании».
        $html = partial('maintenance', ['text' => '', 'image' => '']);

        self::assertStringContainsString('Сайт на техобслуживании', $html);
        self::assertStringContainsString('Скоро вернёмся', $html, 'запасной текст объяснения');
        self::assertStringContainsString('/assets/img/cats/cat-happy.png', $html, 'запасная картинка');
    }

    public function testТекстЭкранируется(): void
    {
        // Текст из настроек может содержать угловые скобки: экран обязан экранировать его,
        // иначе админ настроек получил бы исполняемую разметку на публичной странице.
        $html = partial('maintenance', ['text' => '<script>alert(1)</script>', 'image' => '']);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'сырой скрипт в заглушке');
        self::assertStringContainsString('&lt;script&gt;', $html, 'текст экранирован');
    }

    public function testАдминкаНеПрячетсяЗаЗаглушкой(): void
    {
        // Заглушка — экран для посетителя. Админские адреса и вход обязаны открываться,
        // иначе включённый режим нечем снять.
        self::assertTrue(maintenanceAllowsPath('/admin/settings'));
        self::assertFalse(maintenanceAllowsPath('/'));
    }
}
