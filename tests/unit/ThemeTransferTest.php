<?php

declare(strict_types=1);

namespace Cms\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/theme-transfer.php';

/**
 * Обмен темой: выгрузка в JSON и проверка ввезённого файла.
 *
 * Формат JSON, а не ZIP: архив пришлось бы распаковывать, а распаковка чужого архива — это Zip
 * Slip, права файлов и типы содержимого. Перенос настроек и манифеста не требует файлов вовсе,
 * поэтому здесь нет ни одного места, где чужой путь дошёл бы до файловой системы.
 *
 * Проверяется именно защита: файл приходит от человека, и всё в нём — недоверенные данные.
 */
final class ThemeTransferTest extends TestCase
{
    /**
     * Годный перенос: манифест, палитра, размеры.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'format' => THEME_TRANSFER_FORMAT,
            'version' => 1,
            'manifest' => [
                'slug' => 'school',
                'name' => 'Школа',
                'version' => '1.0.0',
                'description' => 'Тема школы',
                'author' => 'NovaCMS',
            ],
            'colors' => ['paper' => '#101010', 'ink' => '#f5f5f5'],
            'fonts' => ['h1' => 44],
        ];
    }

    public function testГодныйФайлПроходитПроверку(): void
    {
        $result = themeTransferValidate((string) json_encode($this->payload(), JSON_UNESCAPED_UNICODE));

        self::assertSame('', $result['error']);
        self::assertSame('school', $result['payload']['manifest']['slug']);
        self::assertSame('#101010', $result['payload']['colors']['paper']);
        self::assertSame(44, $result['payload']['fonts']['h1']);
    }

    public function testБитыйJsonОтклоняется(): void
    {
        $result = themeTransferValidate('{"format":"cms-theme"}');

        self::assertNotSame('', $result['error'], 'обрыв JSON не должен читаться как пустой перенос');
    }

    public function testЧужойФорматОтклоняется(): void
    {
        // Файл другого назначения не должен молча «примениться» как тема.
        $result = themeTransferValidate((string) json_encode(['format' => 'wordpress-wxr', 'version' => 1]));

        self::assertNotSame('', $result['error']);
        self::assertStringContainsString('формат', $result['error']);
    }

    public function testНебезопасныйSlugОтклоняется(): void
    {
        // `../..` в slug — попытка вывести чтение за пределы themes/. Проверка стоит до любой
        // записи, поэтому такой файл не доходит до файловой системы.
        foreach (['../../etc', 'a/b', 'School', '..'] as $bad) {
            $payload = $this->payload();
            $payload['manifest']['slug'] = $bad;

            $result = themeTransferValidate((string) json_encode($payload));

            self::assertNotSame('', $result['error'], 'slug «' . $bad . '» обязан быть отклонён');
        }
    }

    public function testЦветаПроверяютсяПоФорматy(): void
    {
        $payload = $this->payload();
        $payload['colors'] = ['paper' => 'red; background: url(http://evil.test/x.png)', 'ink' => '#f5f5f5'];

        $result = themeTransferValidate((string) json_encode($payload));

        self::assertSame('', $result['error']);
        // Негодное значение не отклоняет весь файл — оно просто не попадает в палитру: терять
        // остальные настройки из-за одной опечатки хуже, чем применить их без неё.
        self::assertArrayNotHasKey('paper', $result['payload']['colors']);
        self::assertSame('#f5f5f5', $result['payload']['colors']['ink']);
    }

    public function testРазмерыДержатсяВПределах(): void
    {
        $payload = $this->payload();
        $payload['fonts'] = ['h1' => 9999, 'body' => 0, 'h2' => 28];

        $result = themeTransferValidate((string) json_encode($payload));

        self::assertSame(DESIGN_FONT_MAX, $result['payload']['fonts']['h1']);
        // Ноль означает «как в теме» — ключ не печатается вовсе, а не превращается в минимум.
        self::assertArrayNotHasKey('body', $result['payload']['fonts']);
        self::assertSame(28, $result['payload']['fonts']['h2']);
    }

    public function testНеизвестныеКлючиВыбрасываются(): void
    {
        // В файл можно положить что угодно: `routes`, `layout`, `hooks` — переносится только то,
        // что программа умеет применить. Иначе «импорт» стал бы каналом настройки сайта.
        $payload = $this->payload();
        $payload['hooks'] = ['onRender' => 'system("ls")'];
        $payload['routes'] = ['/evil' => 'text'];

        $result = themeTransferValidate((string) json_encode($payload));

        self::assertSame('', $result['error']);
        self::assertArrayNotHasKey('hooks', $result['payload']);
        self::assertArrayNotHasKey('routes', $result['payload']);
    }

    public function testСлишкомБольшойФайлОтклоняется(): void
    {
        $result = themeTransferValidate(str_repeat('a', THEME_TRANSFER_MAX_BYTES + 1));

        self::assertNotSame('', $result['error']);
        self::assertStringContainsString('Размер', $result['error']);
    }

    public function testВыгрузкаВозвращаетФорматПригодныйДляВвоза(): void
    {
        // Круг «выгрузил → ввезено» обязан сходиться: иначе файл своей же темы не проходит
        // проверку, и перенос между сайтами не работает вовсе.
        $json = themeTransferJson(
            ['slug' => 'school', 'name' => 'Школа', 'version' => '1.0.0'],
            ['paper' => '#101010'],
            ['h1' => 44]
        );

        $result = themeTransferValidate($json);

        self::assertSame('', $result['error'], 'выгруженный файл обязан проходить проверку');
        self::assertSame('school', $result['payload']['manifest']['slug']);
        self::assertSame('#101010', $result['payload']['colors']['paper']);
        self::assertSame(44, $result['payload']['fonts']['h1']);
    }
}
