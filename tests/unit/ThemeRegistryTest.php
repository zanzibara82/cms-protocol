<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Правила реестра тем и манифеста темы. Базы нет: реестр читает каталог `themes/`, а манифест
 * разбирается из строки — оба правила чистые, поэтому проверяются без живой схемы.
 *
 * Причина проверки: до этой работы «тема» была ровно одним каталогом `theme/`, второго варианта
 * не существовало. Манифест вводит контракт (slug, название, версия, цвета, макеты), и без
 * проверки испорченный `theme.json` тихо ронял бы сайт вместо понятного отказа.
 */
final class ThemeRegistryTest extends TestCase
{
    public function testМанифестТемыРазбираетсяИПроверяется(): void
    {
        $manifest = themeManifestParse(json_encode([
            'slug' => 'school',
            'name' => 'Школа',
            'version' => '1.0.0',
            'description' => 'Тема школы английского',
            'colors' => ['accent' => '#d97706'],
            'layouts' => ['default', 'landing'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('', $manifest['error'], 'Годный манифест не должен давать ошибку');
        self::assertSame('school', $manifest['slug']);
        self::assertSame('Школа', $manifest['name']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['default', 'landing'], $manifest['layouts']);
        self::assertSame(['accent' => '#d97706'], $manifest['colors']);
    }

    public function testМанифестБезОбязательныхПолейОтклоняется(): void
    {
        // Без slug тему нельзя положить в реестр: имя каталога и идентификатор разошлись бы.
        $noSlug = themeManifestParse(json_encode(['name' => 'Школа', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        self::assertNotSame('', $noSlug['error']);
        self::assertStringContainsString('slug', $noSlug['error']);

        // Без названия тему не показать в каталоге.
        $noName = themeManifestParse(json_encode(['slug' => 'school', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        self::assertNotSame('', $noName['error']);
        self::assertStringContainsString('name', $noName['error']);

        // Версия нужна для совместимости и обновлений.
        $noVersion = themeManifestParse(json_encode(['slug' => 'school', 'name' => 'Школа'], JSON_THROW_ON_ERROR));
        self::assertNotSame('', $noVersion['error']);
        self::assertStringContainsString('version', $noVersion['error']);
    }

    public function testБитыйJSONИНебезопасныйSlugОтклоняются(): void
    {
        // Обрыв JSON: без разбора манифест читался бы как пустой массив, а не как ошибка.
        $broken = themeManifestParse('{"slug": "school", ');
        self::assertNotSame('', $broken['error']);

        // Slug — имя каталога: «..» и слэши вывели бы чтение за пределы `themes/`.
        foreach (['../secret', 'a/b', 'A-Z', 'тема', ''] as $bad) {
            $manifest = themeManifestParse(json_encode([
                'slug' => $bad,
                'name' => 'Тема',
                'version' => '1.0.0',
            ], JSON_THROW_ON_ERROR));

            self::assertNotSame('', $manifest['error'], 'Slug «' . $bad . '» обязан быть отклонён');
        }
    }

    public function testРеестрЧитаетКаталогИПропускаетИспорченныеТемы(): void
    {
        // Пустой каталог — не ошибка: сайт без собственной темы работает на `theme/`.
        $empty = themeRegistryRead(sys_get_temp_dir() . '/cms-themes-empty-' . bin2hex(random_bytes(4)));
        self::assertSame([], $empty['themes']);
        self::assertSame('', $empty['error']);

        // Каталог с одной годной темой и одной испорченной: годная попадает в список,
        // испорченная — в отдельный перечень причин, а не роняет чтение целиком.
        $root = sys_get_temp_dir() . '/cms-themes-' . bin2hex(random_bytes(4));
        mkdir($root . '/good', 0777, true);
        mkdir($root . '/broken', 0777, true);
        file_put_contents($root . '/good/theme.json', json_encode([
            'slug' => 'good',
            'name' => 'Годная',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
        // Каркас обязателен: без него тему нечем отрисовать, и реестр считает её испорченной.
        file_put_contents($root . '/good/layout.php', "<?php\n");
        file_put_contents($root . '/broken/theme.json', '{"slug": ');

        $registry = themeRegistryRead($root);

        self::assertCount(1, $registry['themes']);
        self::assertSame('good', $registry['themes'][0]['slug']);
        self::assertSame('Годная', $registry['themes'][0]['name']);
        self::assertCount(1, $registry['broken']);
        self::assertSame('broken', $registry['broken'][0]['slug']);

        // Уборка временного каталога: тест не должен оставлять мусор между прогонами.
        @unlink($root . '/good/layout.php');
        foreach (['good', 'broken'] as $folder) {
            @unlink($root . '/' . $folder . '/theme.json');
            @rmdir($root . '/' . $folder);
        }
        @rmdir($root);
    }

    public function testКаталогТемыОбязанСодержатьКаркас(): void
    {
        // Тема без layout.php не может отрисовать сайт: реестр помечает её испорченной.
        $root = sys_get_temp_dir() . '/cms-themes-nolayout-' . bin2hex(random_bytes(4));
        mkdir($root . '/bare', 0777, true);
        file_put_contents($root . '/bare/theme.json', json_encode([
            'slug' => 'bare',
            'name' => 'Без каркаса',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        $registry = themeRegistryRead($root);

        self::assertSame([], $registry['themes']);
        self::assertCount(1, $registry['broken']);
        self::assertStringContainsString('layout.php', $registry['broken'][0]['error']);

        @unlink($root . '/bare/theme.json');
        @rmdir($root . '/bare');
        @rmdir($root);
    }
}
