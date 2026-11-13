<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Медиатека на настоящей базе: подписи, контроль использования и безопасное удаление.
 * Главное свойство — файл нельзя удалить, пока на него ссылается контент: иначе страница
 * осталась бы с битой картинкой, а человек узнал бы об этом от посетителя.
 */
final class MediaLibraryTest extends TestCase
{
    /** @var list<string> пути, которые тест создал на диске */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('media');
        TestDb::clearTable('trash');
        run('DELETE FROM ' . t('page_section'));
        run('DELETE FROM ' . t('page'));
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            $file = pageImageDir() . '/' . basename($path);
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->created = [];
    }

    public function testСтрокаМедиатекиХранитРазмерыИПодписи(): void
    {
        $path = $this->png('media-probe-1');
        $id = $this->register($path, 'Логотип', 'Логотип школы');

        $row = mediaById($id);
        self::assertNotNull($row);
        self::assertSame($path, (string) $row['path']);
        self::assertSame('Логотип', (string) $row['title']);
        self::assertSame('Логотип школы', (string) $row['alt']);
        self::assertSame('image/png', (string) $row['mime']);
        self::assertSame(2, (int) $row['width'], 'Ширина берётся из файла');
        self::assertSame(2, (int) $row['height'], 'Высота берётся из файла');
        self::assertGreaterThan(0, (int) $row['bytes']);
    }

    public function testПодписиОбновляютсяИОбрезаютсяПоПределамКолонок(): void
    {
        $path = $this->png('media-probe-2');
        $id = $this->register($path);

        $result = mediaUpdate($id, str_repeat('т', 300), str_repeat('а', 400));
        self::assertTrue($result['ok']);

        $row = mediaById($id);
        self::assertNotNull($row);
        self::assertSame(190, mb_strlen((string) $row['title']), 'Заголовок обрезан по пределу колонки');
        self::assertSame(300, mb_strlen((string) $row['alt']), 'ALT обрезан по пределу колонки');
    }

    public function testОбновлениеНесуществующегоФайлаОтказывает(): void
    {
        $result = mediaUpdate('net-takogo', 'Заголовок', 'ALT');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('исчез', $result['error']);
    }

    // Главное свойство медиатеки: пока путь упомянут в секции, удаление запрещено.
    public function testИспользуемыйФайлУдалитьНельзя(): void
    {
        $path = $this->png('media-probe-3');
        $id = $this->register($path);

        $page = createPage(['slug' => 's-kartinkoy', 'title' => 'С картинкой', 'status' => PAGES_STATUS_DRAFT]);
        createSection((string) $page['id'], [
            'sec_key' => 'kartinka',
            'kind' => 'image',
            'title' => 'Картинка',
            'image' => $path,
        ]);

        $result = mediaDelete($id);

        self::assertFalse($result['ok'], 'Файл из секции удалять нельзя');
        self::assertStringContainsString('используется', $result['error']);
        self::assertNotEmpty($result['usage'], 'Отказ обязан объяснить, где файл используется');
        self::assertNotNull(mediaById($id), 'Строка медиатеки остаётся на месте');
        self::assertFileExists(pageImageDir() . '/' . basename($path), 'Файл на диске остаётся');
    }

    // Снимок корзины — тоже контент: после восстановления страницы картинка обязана быть на месте.
    // Без этой проверки медиатека удаляла бы файл, на который ещё ссылается удалённая страница.
    public function testФайлВКорзинеСчитаетсяИспользованием(): void
    {
        $path = $this->png('media-probe-9');
        $id = $this->register($path);

        $page = createPage(['slug' => 'v-korzine', 'title' => 'В корзине', 'status' => PAGES_STATUS_DRAFT]);
        createSection((string) $page['id'], [
            'sec_key' => 'kartinka',
            'kind' => 'image',
            'title' => 'Картинка',
            'image' => $path,
        ]);

        self::assertTrue(trashPage((string) $page['id'])['ok']);

        $usage = mediaUsage($path);
        self::assertNotEmpty($usage, 'Снимок корзины обязан удерживать файл');
        self::assertSame('/admin/trash', $usage[0]['href'] ?? '');
        self::assertFalse(mediaDelete($id)['ok'], 'Файл из корзины удалять нельзя');
        self::assertFileExists(pageImageDir() . '/' . basename($path), 'Файл на диске остаётся');
    }

    public function testСвободныйФайлУдаляетсяСТекиИЗБазы(): void
    {
        $path = $this->png('media-probe-4');
        $id = $this->register($path);
        $file = pageImageDir() . '/' . basename($path);

        self::assertFileExists($file);
        self::assertSame([], mediaUsage($path), 'Файл нигде не используется');

        $result = mediaDelete($id);

        self::assertTrue($result['ok'], 'Свободный файл обязан удалиться: ' . $result['error']);
        self::assertNull(mediaById($id));
        self::assertFileDoesNotExist($file);
    }

    // Ссылка внутри JSON-списка секции — тоже использование: карусель и галерея хранят пути там.
    public function testСсылкаВнутриСпискаСекцииСчитаетсяИспользованием(): void
    {
        $path = $this->png('media-probe-5');
        $id = $this->register($path);

        $page = createPage(['slug' => 's-galereey', 'title' => 'С галереей', 'status' => PAGES_STATUS_DRAFT]);
        createSection((string) $page['id'], [
            'sec_key' => 'galereya',
            'kind' => 'gallery',
            'title' => 'Галерея',
            'items' => [['image' => $path, 'title' => 'Кадр']],
        ]);

        $usage = mediaUsage($path);
        self::assertNotEmpty($usage, 'Путь внутри items обязан считаться использованием');

        self::assertFalse(mediaDelete($id)['ok']);
    }

    // Обратная сторона: путь, которого нет ни в одной секции, не мешает удалению. Иначе
    // медиатека не чистилась бы никогда.
    public function testПохожийНоДругойПутьНеБлокируетУдаление(): void
    {
        $path = $this->png('media-probe-6');
        $id = $this->register($path);

        $other = $path . '-copy.png';
        $page = createPage(['slug' => 's-drugoy', 'title' => 'С другой', 'status' => PAGES_STATUS_DRAFT]);
        createSection((string) $page['id'], ['sec_key' => 'drugaya', 'kind' => 'image', 'title' => 'Другая', 'image' => $other]);

        self::assertSame([], mediaUsage($path), 'Другой путь не считается использованием этого');
        self::assertTrue(mediaDelete($id)['ok']);
    }

    public function testПоискПоПодписиИПутиФильтруетСписок(): void
    {
        $a = $this->png('media-probe-7a');
        $b = $this->png('media-probe-7b');
        $this->register($a, 'Осенняя листва');
        $this->register($b, 'Зимний лес');

        self::assertCount(2, mediaRows());
        self::assertCount(1, mediaRows('листва'), 'Поиск по заголовку обязан отсеять второй файл');
        self::assertCount(1, mediaRows('probe-7b'), 'Поиск по пути тоже работает');
        self::assertCount(0, mediaRows('нет-такого'));
    }

    // Синхронизация подхватывает файлы, положенные в каталог до появления медиатеки, и не
    // плодит дубли при повторном прогоне.
    public function testСинхронизацияДобавляетФайлОдинРаз(): void
    {
        $path = $this->png('media-probe-8');
        // Файл лежит на диске, строки в медиатеке нет — ровно состояние «до медиатеки».
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('media')));

        $first = mediaSync();
        self::assertTrue($first['ok']);
        self::assertGreaterThanOrEqual(1, $first['added'], 'Синхронизация обязана найти наш файл');

        $found = one('SELECT id, width FROM ' . t('media') . ' WHERE path = ?', [$path]);
        self::assertNotNull($found);
        self::assertSame(2, (int) $found['width']);

        $second = mediaSync();
        self::assertSame(0, $second['added'], 'Повторная синхронизация не плодит дубли');
    }

    public function testРазмерПечатаетсяПоЧеловечески(): void
    {
        self::assertSame('512 Б', mediaBytes(512));
        self::assertSame('2 КБ', mediaBytes(2048));
        self::assertSame('1,5 МБ', mediaBytes(1572864));
    }

    // --- Помощники ---------------------------------------------------------------------------

    /** Кладёт на диск настоящий PNG 2×2 и возвращает публичный путь. */
    private function png(string $name): string
    {
        $relative = PAGE_IMAGE_PREFIX . $name . '.png';
        $file = pageImageDir() . '/' . $name . '.png';
        $dir = pageImageDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // 2×2 PNG: размеры нужны медиатеке, чтобы взять ширину и высоту из файла.
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFklEQVR4nGP8z8Dwn4GBgYGJAQiAAAAt5gH6VYq1WQAAAABJRU5ErkJggg=='
        );
        file_put_contents($file, (string) $bytes);
        $this->created[] = $relative;

        return $relative;
    }

    /** Строка медиатеки для уже лежащего на диске файла: тем же путём, что и настоящая загрузка. */
    private function register(string $path, string $title = '', string $alt = ''): string
    {
        $file = pageImageDir() . '/' . basename($path);
        $size = getimagesize($file);
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('media', [
            'id' => $id,
            'path' => $path,
            'title' => $title,
            'alt' => $alt,
            'mime' => is_array($size) ? (string) $size['mime'] : '',
            'width' => is_array($size) ? (int) $size[0] : 0,
            'height' => is_array($size) ? (int) $size[1] : 0,
            'bytes' => is_array($size) ? 123 : 0,
            'uploaded_by' => null,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }
}
