<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/setup-config.php';

// Пакет M6-2c: механика первого шага переехала из `public/setup.php` в `core/setup-config.php`,
// поэтому её краевые значения стали видимы юнит-набору. Через реальный тестовый сервер каждый
// помощник проверялся ровно в одном сценарии, а границы длин, числа в выводе `var_export()` и
// отказ записи оставались непокрытыми: здесь они проверяются напрямую.
final class SetupConfigTest extends TestCase
{
    /** @var list<string> каталоги во временной области, созданные проверками этого класса */
    private array $createdDirs = [];

    public static function setUpBeforeClass(): void
    {
        // Отказ записи идёт через logWarn(): боевой журнал набора остаётся чистым.
        putenv('CMS_LOG_PATH=/tmp/m6-2c-unit-errors.log');
    }

    public static function tearDownAfterClass(): void
    {
        putenv('CMS_LOG_PATH');
    }

    protected function tearDown(): void
    {
        // Соседний набор читает файл настроек по пути из окружения: оставленная переменная
        // перенаправила бы его в наш временный файл.
        putenv('CMS_CONFIG_FILE');

        foreach ($this->createdDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $stray) {
                @unlink($stray);
            }

            @rmdir($dir);
        }

        $this->createdDirs = [];
    }

    /**
     * Корректный набор полей формы шага 1: setupValidateDb() читает каждое поле напрямую,
     * поэтому отсутствие ключа стало бы не отказом, а предупреждением.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function formValues(array $overrides = []): array
    {
        $fields = [
            'db_host' => 'db.example.com',
            'db_port' => '3306',
            'db_name' => 'cms_test',
            'db_user' => 'root',
            'db_pass' => '',
            'table_prefix' => 'tbl_',
            'site_url' => 'https://example.com',
            'site_name' => 'Школа',
        ];

        /** @var array<string, string> $merged */
        $merged = array_merge($fields, $overrides);

        return $merged;
    }

    private function tempDir(): string
    {
        $dir = '/tmp/cms-m6-2c-config-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0775, true), 'Временный каталог ' . $dir . ' не создан');
        $this->createdDirs[] = $dir;

        return $dir;
    }

    private function inodeOf(string $path): int
    {
        clearstatcache(true, $path);
        $stats = stat($path);

        return is_array($stats) ? (int) $stats['ino'] : 0;
    }

    public function testОбразецОтдаётНаборКлючейАПортПочтыЧислом(): void
    {
        $sample = setupSampleConfig();

        // Возврат обязан остаться набором: анализатор знает тип функции, поэтому проверка идёт
        // через снимок типа, а не через утверждение о типе (иначе оно всегда истинно).
        self::assertSame('array', get_debug_type($sample), 'Образец вернул не набор');
        self::assertArrayHasKey('DB_HOST', $sample, 'В образце нет обязательного ключа хоста базы');
        self::assertIsInt($sample['SMTP_PORT'] ?? null, 'Порт почты в образце обязан остаться числом');
    }

    public function testСыройФайлЧитаетсяТолькоПоСвоемуПути(): void
    {
        $dir = $this->tempDir();

        // Нет файла — пустой набор: установщик обязан открыться до того, как настройки записаны.
        putenv('CMS_CONFIG_FILE=' . $dir . '/config-которого-нет.php');
        self::assertSame([], setupReadRawConfig(), 'Отсутствующий файл дал не пустой набор');

        $returnsString = $dir . '/config-строка.php';
        file_put_contents($returnsString, "<?php\n\nreturn 'это не набор';\n");
        putenv('CMS_CONFIG_FILE=' . $returnsString);
        self::assertSame([], setupReadRawConfig(), 'Файл, отдающий не набор, обязан дать пустой набор');

        $returnsArray = $dir . '/config-набор.php';
        file_put_contents($returnsArray, "<?php\n\nreturn ['КЛЮЧ' => 'значение'];\n");
        putenv('CMS_CONFIG_FILE=' . $returnsArray);
        self::assertSame(['КЛЮЧ' => 'значение'], setupReadRawConfig(), 'Сырой файл прочитан иначе');
    }

    public function testПробелыСрезаютсяУВсехПолейКромеПароляБазы(): void
    {
        $normalized = setupNormalizeDb($this->formValues([
            'db_host' => '  db.example.com  ',
            'db_port' => '  3306  ',
            'db_name' => '  cms_test  ',
            'db_user' => '  root  ',
            'db_pass' => '  секрет с пробелами  ',
            'table_prefix' => "  tbl_\t",
            'site_name' => '  Школа  ',
        ]));

        self::assertSame('db.example.com', $normalized['db_host'], 'Пробелы хоста не срезаны');
        self::assertSame('3306', $normalized['db_port'], 'Пробелы порта не срезаны');
        self::assertSame('cms_test', $normalized['db_name'], 'Пробелы имени базы не срезаны');
        self::assertSame('root', $normalized['db_user'], 'Пробелы пользователя не срезаны');
        self::assertSame('tbl_', $normalized['table_prefix'], 'Пробелы и табуляция префикса не срезаны');
        self::assertSame('Школа', $normalized['site_name'], 'Пробелы названия сайта не срезаны');

        // Пробелы в пароле легальны: срез изменил бы реальный пароль оператора.
        self::assertSame('  секрет с пробелами  ', $normalized['db_pass'], 'Пароль базы нормализован');
    }

    public function testАдресСайтаТеряетКонечныйСлэш(): void
    {
        self::assertSame(
            'https://example.com',
            setupNormalizeDb($this->formValues(['site_url' => 'https://example.com///']))['site_url'],
            'Конечные слэши адреса сайта не сняты'
        );
        self::assertSame(
            'https://example.com',
            setupNormalizeDb($this->formValues(['site_url' => 'https://example.com']))['site_url'],
            'Адрес сайта без конечного слэша изменён'
        );
    }

    public function testХостБазыНаГраницеСтаДевяностаСимволов(): void
    {
        $pass = setupValidateDb($this->formValues(['db_host' => str_repeat('a', 186) . '.com']));
        self::assertArrayNotHasKey('db_host', $pass, 'Хост в 190 символов отклонён');

        $fail = setupValidateDb($this->formValues(['db_host' => str_repeat('a', 187) . '.com']));
        self::assertSame(
            'Хост базы — адрес вида db.example.com или 127.0.0.1, без схемы, слэшей и пробелов.',
            $fail['db_host'] ?? '',
            'Хост в 191 символ принят'
        );
    }

    public function testИмяБазыНаГраницеШестьдесятЧетырёхСимволов(): void
    {
        $pass = setupValidateDb($this->formValues(['db_name' => str_repeat('a', 64)]));
        self::assertArrayNotHasKey('db_name', $pass, 'Имя базы в 64 символа отклонено');

        $fail = setupValidateDb($this->formValues(['db_name' => str_repeat('a', 65)]));
        self::assertSame(
            'Имя базы — до 64 символов: латинские буквы, цифры, подчёркивание и дефис.',
            $fail['db_name'] ?? '',
            'Имя базы в 65 символов принято'
        );
    }

    public function testПрефиксТаблицНаГраницеТридцатиСимволов(): void
    {
        $pass = setupValidateDb($this->formValues(['table_prefix' => 'a' . str_repeat('b', 29)]));
        self::assertArrayNotHasKey('table_prefix', $pass, 'Префикс в 30 символов отклонён');

        $fail = setupValidateDb($this->formValues(['table_prefix' => 'a' . str_repeat('b', 30)]));
        self::assertSame(
            'Префикс таблиц — строчные латинские буквы, цифры и подчёркивание, начинается с буквы, до 30 символов.',
            $fail['table_prefix'] ?? '',
            'Префикс в 31 символ принят'
        );
    }

    public function testПортБазыНаГраницахДиапазона(): void
    {
        foreach (['0', '65536'] as $rejected) {
            $fail = setupValidateDb($this->formValues(['db_port' => $rejected]));
            self::assertSame(
                'Порт базы — целое число от 1 до 65535.',
                $fail['db_port'] ?? '',
                'Порт ' . $rejected . ' принят'
            );
        }

        foreach (['1', '65535'] as $accepted) {
            $pass = setupValidateDb($this->formValues(['db_port' => $accepted]));
            self::assertArrayNotHasKey('db_port', $pass, 'Порт ' . $accepted . ' отклонён');
        }
    }

    public function testАдресСайтаЧужойСхемыОтклоняется(): void
    {
        $fail = setupValidateDb($this->formValues(['site_url' => 'ftp://example.com']));

        self::assertSame('Адрес сайта — полный URL со схемой http или https.', $fail['site_url'] ?? '', 'Адрес со схемой ftp принят');
    }

    public function testНазваниеСайтаНаГраницахДлины(): void
    {
        $short = setupValidateDb($this->formValues(['site_name' => 'А']));
        self::assertSame('Название сайта — не короче двух символов.', $short['site_name'] ?? '', 'Название из одного символа принято');

        $long = setupValidateDb($this->formValues(['site_name' => str_repeat('а', 121)]));
        self::assertSame('Название сайта — не длиннее 120 символов.', $long['site_name'] ?? '', 'Название из 121 символа принято');
    }

    public function testПустойПарольПриНепустомПользователеНеДаётНиОдногоОтказа(): void
    {
        self::assertSame([], setupValidateDb($this->formValues()), 'Пустой пароль при непустом пользователе отклонён');
    }

    public function testСборкаДержитПорядокКлючейОбразцаИЗначенияФайла(): void
    {
        /** @var array<string, mixed> $sample */
        $sample = ['DB_HOST' => 'хост-из-образца', 'SMTP_HOST' => 'smtp-из-образца', 'SMTP_PORT' => 587, 'TIMEZONE' => 'UTC'];
        /** @var array<string, mixed> $raw */
        $raw = ['DB_HOST' => 'хост-из-файла', 'SMTP_HOST' => '', 'SMTP_PORT' => null, 'КЛЮЧ_ТОЛЬКО_ИЗ_ФАЙЛА' => 'значение'];

        $out = setupBuildConfig($raw, $sample, $this->formValues());

        // Порядок: сначала ключи образца в его порядке, затем ключ файла которого в образце нет,
        // затем восемь полей формы в порядке наложения.
        self::assertSame(
            ['DB_HOST', 'SMTP_HOST', 'SMTP_PORT', 'TIMEZONE', 'КЛЮЧ_ТОЛЬКО_ИЗ_ФАЙЛА', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'TABLE_PREFIX', 'SITE_URL', 'SITE_NAME'],
            array_keys($out),
            'Порядок ключей собранного набора изменился'
        );

        // Оверлей введённых полей формы перекрывает файл.
        self::assertSame('db.example.com', $out['DB_HOST'], 'Хост из формы не перекрыл файл');
        self::assertSame('', $out['DB_PASS'], 'Введённый пароль базы не лёг в собранный набор');

        // Пустая строка в существующем файле — выбор оператора, а не отсутствие ключа.
        self::assertSame('', $out['SMTP_HOST'], 'Пустая строка файла заменена образцом');
        // Значение null — ровно та грань где array_key_exists() расходится с ?? (проверка сборки
        // на пустой строке файла проходит и при ??): подмена здесь видна только по null.
        self::assertNull($out['SMTP_PORT'], 'Значение файла подменено образцом');

        self::assertSame('значение', $out['КЛЮЧ_ТОЛЬКО_ИЗ_ФАЙЛА'], 'Ключ файла которого нет в образце потерян');
        // Ключа которого нет ни в одном источнике и нет в форме в результате нет.
        self::assertArrayNotHasKey('КЛЮЧ_КОТОРОГО_НИГДЕ_НЕТ', $out, 'В собранный набор уехал посторонний ключ');
    }

    /**
     * Годный к запису собранный набор: каждый обязательный ключ скалярный и непустой.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function assembledConfig(array $overrides = []): array
    {
        $config = [];

        foreach (CONFIG_REQUIRED_KEYS as $key) {
            $config[$key] = 'значение-' . $key;
        }

        $config['SMTP_PORT'] = 587;
        $config['DB_PASS'] = 'пароль базы';

        /** @var array<string, mixed> $merged */
        $merged = array_merge($config, $overrides);

        return $merged;
    }

    public function testГодныйСобранныйНаборНеДаётНиОдногоНарушения(): void
    {
        self::assertSame([], setupCheckAssembled($this->assembledConfig()), 'Годный набор помечен нарушенным');

        // Пустой пароль базы — валидное значение (правило F9): ключа нет в списке обязательных, и
        // нарушение по пустоте для него добавлять нельзя.
        self::assertSame([], setupCheckAssembled($this->assembledConfig(['DB_PASS' => ''])), 'Пустой пароль базы помечен нарушенным');
    }

    public function testПустойОбязательныйКлючСобранныхНастроекНазван(): void
    {
        self::assertSame(['TIMEZONE'], setupCheckAssembled($this->assembledConfig(['TIMEZONE' => ''])), 'Пустой обязательный ключ не назван');
    }

    public function testНескалярныеЗначенияОбязательныхКлючейНазваны(): void
    {
        $cases = [
            'значение null' => ['DB_HOST' => null],
            'пустой набор' => ['DB_NAME' => []],
            'непустой набор' => ['SITE_URL' => ['http://example.com']],
            'объект' => ['TABLE_PREFIX' => new \stdClass()],
            // Скаляр, который пустой: приведение лжи к строке даёт пустоту, и ключ нарушен.
            'ложь' => ['SITE_NAME' => false],
        ];

        foreach ($cases as $what => $override) {
            $keys = array_keys($override);
            self::assertSame(
                [$keys[0]],
                setupCheckAssembled($this->assembledConfig($override)),
                'Нескалярное значение (' . $what . ') не названо нарушением'
            );
        }
    }

    public function testНесколькоНарушенийДаётПолныйСписокВПорядкеОбязательныхКлючей(): void
    {
        $violations = setupCheckAssembled($this->assembledConfig([
            'SITE_NAME' => '',
            'DB_HOST' => [],
            'TIMEZONE' => null,
            'SITE_URL' => false,
            // Ноль — непустое скалярное значение: ядро считает заданным всё, что приводится к
            // непустой строке, поэтому нарушения здесь нет.
            'DB_PORT' => 0,
        ]));

        // Список полный и в порядке списка обязательных ключей ядра, а не в порядке нарушения.
        self::assertSame(['DB_HOST', 'SITE_URL', 'SITE_NAME', 'TIMEZONE'], $violations, 'Список нарушений неполный или не в порядке ядра');
        // Экрану показывается первый по порядку ключ: именно он попадёт в текст отказа.
        self::assertSame('DB_HOST', $violations[0], 'Первым показывается не первый по порядку ключ');
    }

    public function testРендерДержитЧислоЧисломАСтрокуВОдинарныхКавычках(): void
    {
        $content = setupRenderConfig(['SMTP_PORT' => 587, 'DB_HOST' => 'db', 'TABLE_PREFIX' => 'tbl_']);

        self::assertStringContainsString("'SMTP_PORT' => 587,", $content, 'Число в выводе оказалось строкой');
        self::assertStringContainsString("'DB_HOST' => 'db',", $content, 'Строка не в одинарных кавычках');
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $content, 'Заголовок файла настроек потерян');
        self::assertTrue(str_ends_with($content, "\n"), 'Файл настроек не заканчивается переводом строки');
    }

    public function testОпасноеЗначениеЗаписываетсяКакДанныеИЧитаетсяОбратно(): void
    {
        $dangerous = "доля '); system(\$_GET['команда']);//первая\nвторая строка";
        $content = setupRenderConfig(['ОПАСНЫЙ_КЛЮЧ' => $dangerous, 'ДРУГОЙ' => 'ещё "двойные" кавычки']);

        $path = $this->tempDir() . '/rendered.php';
        file_put_contents($path, $content);

        /** @var mixed $restored */
        $restored = require $path;

        self::assertIsArray($restored, 'Записанный файл не читается как набор');
        // Значение вернулось целиком: текст про системный вызов остался данными, а не кодом.
        self::assertSame($dangerous, $restored['ОПАСНЫЙ_КЛЮЧ'] ?? null, 'Значение с кавычкой и переводом строки исказилось');
        self::assertSame('ещё "двойные" кавычки', $restored['ДРУГОЙ'] ?? null, 'Значение с двойными кавычками исказилось');
    }

    public function testЗаписьВОбразецОтказываетсяДоЛюбогоОбращенияКДиску(): void
    {
        $missingDir = '/tmp/cms-m6-2c-нет-такого-каталога-' . bin2hex(random_bytes(4));

        $result = setupWriteConfig("<?php\n\nreturn [];\n", $missingDir . '/config.sample.php');

        self::assertNotSame('', $result['error'], 'Запись в образец не отклонена');
        self::assertStringContainsString('config.sample.php', $result['error'], 'Отказ не называет образец');
        self::assertSame('', $result['warning'], 'Отказ записи в образец выдал предупреждение');
        // Каталог цели не создан: отказ обязан произойти ДО любого обращения к диску.
        self::assertFalse(is_dir($missingDir), 'Отказ по имени цели коснулся диска');
    }

    public function testОтказЗаписиВНедоступныйКаталогНеОставляетВременныхФайлов(): void
    {
        $missingDir = '/tmp/cms-m6-2c-нет-такого-каталога-' . bin2hex(random_bytes(4));

        $result = setupWriteConfig("<?php\n\nreturn [];\n", $missingDir . '/config.php');

        self::assertStringContainsString('недоступен для записи', $result['error'], 'Отказ не говорит про недоступность каталога');
        self::assertSame('', $result['warning'], 'Отказ уровня диска выдал предупреждение');
        self::assertSame([], glob('/tmp/.cms-config-*'), 'После отказа в несуществующем каталоге остались осколки');
    }

    public function testУспешнаяЗаписьДаётПраваШестьсотИНовуюИноду(): void
    {
        $dir = $this->tempDir();
        $path = $dir . '/config.php';

        $first = setupWriteConfig("<?php\n\nreturn ['КЛЮЧ' => 'значение'];\n", $path);
        self::assertSame('', $first['error'], 'Первая запись отклонена');
        self::assertSame('', $first['warning'], 'Первая запись выдала предупреждение');
        self::assertFileExists($path, 'Записанный файл не найден');
        // Права смотрятся во временной области операционной системы: bind-монтаж windows-тома
        // режима не отражает, поэтому проверка внутри корня проекта ничего не доказывала бы.
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($path)), -4), 'Права записанного файла не 600');
        $inodeAfterFirst = $this->inodeOf($path);
        self::assertNotSame(0, $inodeAfterFirst, 'Инода цели после первой записи не прочитана');

        $second = setupWriteConfig("<?php\n\nreturn ['КЛЮЧ' => 'другое'];\n", $path);
        self::assertSame('', $second['error'], 'Вторая запись отклонена');

        // rename() из временного файла всегда даёт новую иноду: запись в существующий файл на
        // месте оставил бы прежнюю, и атомарность перестала бы что-то значить.
        self::assertNotSame($inodeAfterFirst, $this->inodeOf($path), 'Вторая запись заменила файл на месте');
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($path)), -4), 'Права после второй записи не 600');
        self::assertSame([], glob('/tmp/.cms-config-*'), 'После успешных записей остались осколки');
    }
}
