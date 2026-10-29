<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/setup-config.php';
require_once dirname(__DIR__, 2) . '/core/setup-probe.php';

// Оба файла механики первого шага подключаются по `require_once`: весь набор идёт в одном
// процессе, а `tests/unit/SetupConfigTest.php` уже загрузил `core/setup-config.php` этим же
// способом, поэтому второй `require` того же файла дал бы `Cannot redeclare`. Помощник `excerpt()`
// из `core/text.php` тестовому процессу доступен через `core/bootstrap.php`, который загружен в
// `tests/bootstrap.php`, и отдельно его требовать не нужно.
//
// Здесь проверяется граница текста отказа — то, что через реальный тестовый сервер не проверяется
// вовсе: сообщение `1045` на этом стенде однострочное и короче порога, поэтому в http-наборе
// утверждений про вырезку нет (и появиться не должно).
//
// Путь замка уводится из боевого public/install.lock (М6) переопределением setupLockOverride()
// в setUp: clearLock() стирает за собой, и прогон набора на развёрнутой копии сайта не открыл бы
// установщик заново. Не переменная окружения: PHPUnit при обнаружении включает каждый тестовый
// файл, и поставленная на верхнем уровне переменная протекла бы через процесс PHPUnit в
// дочерние php -S http-набора — сервер установщика писал бы замок во временный путь.

final class SetupProbeTest extends TestCase
{
    /** Порог среза первой строки: тот же, что стоит в `setupFailureHint()`. */
    private const HINT_LIMIT = 160;

    protected function setUp(): void
    {
        // Каждый тест ставит переопределение заново, tearDown снимает: наследие не доезжает
        // до соседних наборов в том же прогоне.
        setupLockOverride(sys_get_temp_dir() . '/cms-unit-install.lock');
    }

    protected function tearDown(): void
    {
        setupLockOverride(null);
    }

    /**
     * Первая строка сообщения драйвера — единственное, что интересно оператору: в ней имя
     * пользователя и способ аутентификации. Всё ниже (`host`, `version`, стек) уходит из текста.
     */
    public function testПервойСтрокиДостаточно(): void
    {
        // Суммарная длина фикстуры обязана оставаться короче порога: иначе `excerpt()` обрезал бы
        // вторую строку раньше, чем до неё доберётся обратный замер П-1, и «секрет-второй-строки»
        // исчез бы из возврата при любом варианте кода — замер стал бы ложнозелёным.
        $message = 'Access denied for user \'root\'@\'localhost\' (using password: YES)' . "\n" . 'секрет-второй-строки во второй строке';
        self::assertSame(101, mb_strlen($message), 'Фикстура длиннее порога: замер П-1 на ней не различим');

        $hint = setupFailureHint($message);

        self::assertStringStartsWith('Access denied for user ', $hint, 'Первая строка сообщения драйвера не взята целиком');
        self::assertStringContainsString('using password: YES', $hint, 'Первая строка срезана раньше конца');
        self::assertStringNotContainsString('секрет-второй-строки', $hint, 'Вторая строка сообщения ушла в текст отказа');
    }

    /**
     * Длинная первая строка срезается по порогу, а не показывается целиком: текст отказа живёт в
     * плашке на экране, а не в журнале.
     */
    public function testДлиннаяПерваяСтрокаСрезана(): void
    {
        $longLine = str_repeat('x', 300);
        $message = $longLine . "\n" . 'вторая строка' . "\n" . 'секрет-третьей-строки';

        $hint = setupFailureHint($message);

        // Порог плюс один символ границы: `excerpt()` при переполнении добавляет многоточие.
        self::assertSame(self::HINT_LIMIT + 1, mb_strlen($hint), 'Длинная первая строка не срезана по порогу');
        self::assertSame(mb_substr($longLine, 0, 1), mb_substr($hint, 0, 1), 'Срез начался не с первого символа сообщения');
        self::assertStringNotContainsString('секрет-третьей-строки', $hint, 'Третья строка сообщения ушла в текст отказа');
    }

    /**
     * Ровно тот текст, который драйвер отдаёт на этом стенде: однострочный и короче порога, он
     * возвращается без изменений — видимая часть отказа при выносе функции не поехала.
     */
    public function testОднострочноеКороткоеСообщениеНеМеняется(): void
    {
        $message = 'Access denied for user \'root\'@\'localhost\'';

        self::assertSame($message, setupFailureHint($message), 'Однострочное короткое сообщение переписано');
    }

    /** Пустое сообщение — пустая граница: функция обязана отдавать край, а не фантом. */
    public function testПустоеСообщениеДаётПустуюГраницу(): void
    {
        self::assertSame('', setupFailureHint(''), 'Пустое сообщение дало непустую границу');
    }

    /**
     * Убирает замок любого вида перед и после проверки: настоящий файл и мусорный каталог
     * на его месте стираются. Путь берётся из setupLockPath(): переопределение setUp уводит
     * замок во временный каталог, и боевой public/install.lock набором не трогается (М6) —
     * на развёрнутой копии сайта он стоит честно.
     */
    private static function clearLock(): void
    {
        $path = setupLockPath();

        if (is_file($path)) {
            unlink($path);
        }

        if (is_dir($path)) {
            @rmdir($path);
        }
    }

    /**
     * Свободный замок захватывается вердиктом 'proceed' и честно создаёт файл: без него
     * «повторяемость шага после сбоя» держалась бы на честном слове.
     */
    public function testСвободныйЗамокДаётProceedИСоздаётФайл(): void
    {
        self::clearLock();

        try {
            self::assertSame('proceed', setupLockVerdict(false), 'Свободный замок захватывается');
            self::assertFileExists(setupLockPath(), 'Захват оставляет файл замка на диске');
        } finally {
            self::clearLock();
        }
    }

    /**
     * Стоящий замок при поднятой схеме — вердикт 'installed': сайт уже установлен, захват
     * не происходит и файл не переписывается. Признак установки передаётся параметром,
     * чтобы вердикт не лез в базу из юнит-набора.
     */
    public function testСтоящийЗамокДаётInstalled(): void
    {
        self::clearLock();
        file_put_contents(setupLockPath(), utcStamp(nowUtc()) . "\n");

        try {
            self::assertSame('installed', setupLockVerdict(true), 'Стоящий замок закрывает экран');
        } finally {
            self::clearLock();
        }
    }

    /**
     * Стоящий замок при НЕподнятой схеме — вердикт 'stale' (М7): замок без срока жизни
     * остаётся и после смерти процесса посреди установки, и «сайт уже установлен» для
     * полусмонтированного сайта врёт. Человеку нужен путь восстановления: стереть замок.
     */
    public function testСтоящийЗамокБезСхемыДаётStale(): void
    {
        self::clearLock();
        file_put_contents(setupLockPath(), utcStamp(nowUtc()) . "\n");

        try {
            self::assertSame('stale', setupLockVerdict(false), 'Замок без схемы не зовётся «сайт уже установлен»');
        } finally {
            self::clearLock();
        }
    }

    /**
     * Проигравший захват при «замка на диске нет» — вердикт 'busy' (M1): продолжать без
     * замка нельзя, ровно эту руку в POST-ветке встречает отказ. Гонку двух запросов
     * однопоточный тест не поднимает, поэтому предикат руки проверяется его же гранью:
     * fopen('xb') отказывает, а is_file замка ложно — мусорный каталог на месте замка
     * даёт ровно эту пару (файл каталогом не читается). Отказ fopen при этом «File
     * exists» (проверено зондом на этом PHP): руке busy соответствует именно он.
     */
    public function testПроигранныйЗахватБезЗамкаНаДискеДаётBusy(): void
    {
        self::clearLock();
        self::assertTrue(mkdir(setupLockPath()), 'Каталог на месте замка создан');

        try {
            self::assertSame('busy', setupLockVerdict(false), 'Захват без замка на диске — рука проигравшего');
        } finally {
            self::clearLock();
        }
    }

    /**
     * Захват, проигранный не из-за замка, — вердикт 'denied' (М8): fopen('xb') падает
     * «No such file or directory» на отсутствующем родительском каталоге (и «Permission
     * denied» при чужих правах), а прежний вердикт звал обе руки «busy» и отправлял
     * ждать минуту, хотя ожидание путь не оживит. Отсутствующий каталог выбран вместо
     * chmod: под root контейнера права каталога не мешают созданию файла, а побочная
     * рука детерминирована на любом пользователе.
     */
    public function testЗахватНаНедоступномПутиДаётDenied(): void
    {
        self::clearLock();
        $detour = dirname(setupLockPath()) . '/missing-' . bin2hex(random_bytes(4)) . '/install.lock';
        setupLockOverride($detour);

        try {
            self::assertSame('denied', setupLockVerdict(false), 'Захват не из-за замка не зовётся «чужой запрос»');
        } finally {
            setupLockOverride(null);
            self::clearLock();
        }
    }
}
