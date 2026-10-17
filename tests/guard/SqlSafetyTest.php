<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Два правила из спецификации §6.1 и §10.2, которые легко нарушить по незнанию:
// в запрос не подставляется строка, а миграция ничего не удаляет.
final class SqlSafetyTest extends TestCase
{
    private const FORBIDDEN_CALLS = [
        '/->\s*query\s*\(/' => 'PDO::query — запрос без плейсхолдеров',
        '/->\s*exec\s*\(/' => 'PDO::exec — запрос без плейсхолдеров',
        '/\bmysqli_/' => 'mysqli_* — второй способ ходить в базу',
        '/\$(sql|query)\s*\.=/' => 'склеивание строки запроса',
        '/"\s*SELECT[^"]*\$/' => 'переменная внутри текста запроса',
        '/\'\s*SELECT[^;]*\'\s*\.\s*\$/' => 'конкатенация запроса со строкой',
    ];

    public function testКодНеСклеиваетЗапросы(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            foreach (self::FORBIDDEN_CALLS as $pattern => $reason) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $this->relative($file) . ': ' . $reason;
                }
            }
        }

        self::assertSame([], $violations, 'Найдены места, где SQL собирается склеиванием строк');
    }

    public function testМиграцииТолькоДополняющие(): void
    {
        $violations = [];
        $forbidden = ['/\bDROP\b/i', '/\bTRUNCATE\b/i', '/\bDELETE\s+FROM\b/i', '/\bRENAME\s+TABLE\b/i', '/\bDROP\s+COLUMN\b/i'];

        foreach ($this->sqlFiles() as $file) {
            $code = (string) file_get_contents($file);
            foreach ($forbidden as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $this->relative($file) . ': ' . $pattern;
                }
            }
        }

        self::assertSame([], $violations, 'Миграция удаляет данные — правки схемы обязаны быть дополняющими');
    }

    public function testКаждаяТаблицаНаInnoDbИUtf8mb4(): void
    {
        foreach ($this->sqlFiles() as $file) {
            $code = (string) file_get_contents($file);
            $creates = preg_match_all('/CREATE TABLE/i', $code);
            $engines = preg_match_all('/ENGINE=InnoDB/i', $code);
            $charsets = preg_match_all('/CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/i', $code);

            self::assertSame($creates, $engines, $this->relative($file) . ': таблица без ENGINE=InnoDB');
            self::assertSame($creates, $charsets, $this->relative($file) . ': таблица без utf8mb4_unicode_ci');
        }
    }

    // F1: подстановка префикса (splitSql → sqlWithTablePrefix) видит только литерал «tbl_». Если файл
    // напишет имя таблицы с другим префиксом, установщик создаст именно его, а запросы пойдут в
    // настроенные таблицы — расхождение всплывает только на живом сайте. Страж ловит это до коммита.
    public function testSqlФайлыПишутИменаТаблицЧерезЛитералTbl(): void
    {
        $violations = [];

        foreach ($this->schemaSqlFiles() as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?([a-zA-Z0-9_]+)/i', $code, $matches) > 0) {
                foreach ($matches[1] as $tableName) {
                    if (!str_starts_with((string) $tableName, 'tbl_')) {
                        $violations[] = $this->relative($file) . ': CREATE TABLE ' . $tableName . ' — имя не начинается с «tbl_»';
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Имена таблиц в SQL-файлах обязаны писаться литералом «tbl_» — иначе подстановка их не увидит');
    }

    /** @return list<string> */
    private function schemaSqlFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        // Каждый узор обязан отдать хотя бы один файл: пустой список означает, что каталог
        // миграций исчез, и без этой строки страж схемы проверял бы ноль файлов молча.
        // Число файлов не закрепляется — его прибавляет каждый релиз схемы.
        foreach (['sql/*.sql', 'tests/fixtures/apps/*/sql/*.sql'] as $pattern) {
            $found = glob($root . '/' . $pattern) ?: [];
            self::assertNotEmpty($found, 'Обход ' . $pattern . ' не отдал ни одного SQL-файла — каталог миграций исчез');
            foreach ($found as $file) {
                $out[] = $file;
            }
        }

        sort($out);

        return $out;
    }

    // F8: имя таблицы в запросе ядро обязано брать через t(); литеральное «tbl_…» внутри PHP-строки
    // означает, что подстановка префикса в запрос не попадёт. Маркер «tbl_» обязателен: он и есть
    // признак литерального имени. Проверка покрывает SELECT/INSERT/UPDATE/DELETE.
    private const TABLE_NAME_PATTERNS = [
        '/\bFROM\s+tbl_[a-zA-Z0-9_]+/i' => 'FROM tbl_…',
        '/\bJOIN\s+tbl_[a-zA-Z0-9_]+/i' => 'JOIN tbl_…',
        '/\bINTO\s+tbl_[a-zA-Z0-9_]+/i' => 'INTO tbl_…',
        '/\bUPDATE\s+tbl_[a-zA-Z0-9_]+/i' => 'UPDATE tbl_…',
        '/\bDELETE\s+FROM\s+tbl_[a-zA-Z0-9_]+/i' => 'DELETE FROM tbl_…',
    ];

    public function testКодНеПишетЛитеральныеИменаТаблиц(): void
    {
        $violations = [];
        foreach ($this->phpFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            foreach (self::TABLE_NAME_PATTERNS as $pattern => $reason) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $this->relative($file) . ': ' . $reason;
                }
            }
        }

        self::assertSame([], $violations, 'Имя таблицы в запросе обязано приходить из t(), а не литералом');
    }

    // Паттерны обязаны ловить каждую модифицирующую команду, а не только SELECT: запросы
    // задач 10–21 пишутся именно так. Проверка на синтетических строках —страховка от
    // опечатки в регулярке, которую прогон по реальным файлам не заметит.
    public function testСтражЛовитЛитеральныеИменаВоВсехКомандах(): void
    {
        $samples = [
            "query('SELECT id FROM tbl_user');",
            "query('SELECT 1 FROM tbl_a JOIN tbl_b');",
            "query('INSERT INTO tbl_option VALUES (1)');",
            "query('UPDATE tbl_option SET value = 1');",
            "query('DELETE FROM tbl_user WHERE id = 1');",
        ];

        foreach ($samples as $sample) {
            $matched = false;
            foreach (array_keys(self::TABLE_NAME_PATTERNS) as $pattern) {
                if (preg_match($pattern, $sample) === 1) {
                    $matched = true;
                    break;
                }
            }
            self::assertTrue($matched, 'Паттерны не поймали литеральное имя в: ' . $sample);
        }

        // Законное обращение через t() не должно попадать ни в один паттерн.
        $legit = "query('SELECT id FROM ' . t('user') . ' WHERE 1');";
        foreach (array_keys(self::TABLE_NAME_PATTERNS) as $pattern) {
            self::assertSame(0, preg_match($pattern, $legit), 'Паттерн ловит запрос, собранный через t()');
        }
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        return $this->files(['core', 'admin', 'theme', 'apps', 'public'], 'php');
    }

    // F(М7-1б): каждое `LIKE ?` в разделе «Новости» обязано объявлять знак снятия `ESCAPE`.
    // `escapeLike()` готовит значение под обратный слэш, и без явного `ESCAPE` снятие живёт
    // только пока в режиме СУБД нет `NO_BACKSLASH_ESCAPES` — при этом режиме слэш становится
    // обычным символом шаблона, и поиск по «100%» начинает искать уже не то. Страж ловит
    // написание нового запроса без `ESCAPE` до коммита: паттерн находит `LIKE` с плейсхолдером,
    // за которым не идёт `ESCAPE` (отрицательное опережение), поэтому корректная строка
    // `LIKE ? ESCAPE ?` не считается нарушением. Пробел перед плейсхолдером необязателен
    // (`\s*` в обеих частях узора): `LIKE?` — валидный MySQL по образцу `id=?`, и с узором
    // `\s+` такой запрос проходил ворота, нарушая инвариант.
    // Пункт М284-Г2-4: обход расширен на весь раздел `apps/news`. Прежний `apps/news/lib`
    // обещал «раздел», а читал только `lib`: запрос `LIKE ?` в `apps/news/actions`,
    // `apps/news/templates` или в новом подкаталоге вне `lib` проходил ворота незамеченным.
    public function testКаждыйLikeВРазделеНовостиОбъявляетEscape(): void
    {
        $violations = [];

        foreach ($this->newsSectionFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            if (preg_match_all('/LIKE\s*\?(?!\s*ESCAPE)/i', $code) > 0) {
                $violations[] = $this->relative($file) . ': LIKE ? без объявления ESCAPE';
            }
        }

        self::assertSame(
            [],
            $violations,
            'Каждый LIKE ? в apps/news обязан объявлять ESCAPE — иначе снятие обратным слэшем живёт только при отсутствии NO_BACKSLASH_ESCAPES'
        );
    }

    /** @return list<string> */
    private function newsSectionFiles(): array
    {
        // Рекурсивный обход (`files()`) по всему разделу, а не по одному его каталогу. Порог
        // непустоты даёт сам helper `files()` (строки с `assertDirectoryExists` и `assertNotEmpty`
        // в его теле): исчезнувший каталог раздела или каталог без единого PHP-файла краснит,
        // а не превращает проверку в «нечего проверять», поэтому дублировать порог здесь не нужно.
        return $this->files(['apps/news'], 'php');
    }

    /** @return list<string> */
    private function sqlFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        // Тот же порог, что и у `schemaSqlFiles()`: обход по списку узоров, и каждый узор обязан
        // отдать файл. Пустой ответ `glob` — исчезнувший каталог миграций, а не «нечего проверять».
        foreach (['sql/*.sql', 'apps/*/sql/*.sql'] as $pattern) {
            $found = glob($root . '/' . $pattern) ?: [];
            self::assertNotEmpty($found, 'Обход ' . $pattern . ' не отдал ни одного SQL-файла — каталог миграций исчез');
            foreach ($found as $file) {
                $out[] = $file;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
    private function files(array $dirs, string $extension): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach ($dirs as $dir) {
            self::assertDirectoryExists(
                $root . '/' . $dir,
                $dir . ': каталог исчез из дерева — страж запросов проверял бы меньше, чем требует правило'
            );

            $found = [];
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === $extension) {
                    $found[] = $item->getPathname();
                }
            }

            self::assertNotEmpty(
                $found,
                $dir . ': каталог не отдал ни одного файла с расширением ' . $extension . ' — проверять нечего'
            );
            $out = array_merge($out, $found);
        }
        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }

    private function withoutComments(string $code): string
    {
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

        return (string) preg_replace('#//[^\n]*#', '', $code);
    }
}
