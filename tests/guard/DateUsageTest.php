<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Правило «в базе UTC, Москва только на краях» живёт, пока никто не может отформатировать дату
// в обход core/date.php: иначе половина экранов показала бы время на три часа раньше.
//
// Список ловит равноправные способы создать или отформатировать момент, а не только один удобный
// способ записи. Прежняя версия стража видела «new DateTimeImmutable», но пропускала ту же
// конструкцию с ведущим обратным слэшем, конструктор момента функциональной формой
// (date_create, date_create_immutable, date_create_from_format), статический фабричный вызов
// DateTimeImmutable::createFromFormat() и gmdate(): любая из этих форм уводила форматирование
// из модуля дат, оставаясь зелёной.
final class DateUsageTest extends TestCase
{
    private const ALLOWED = ['core/date.php'];

    /**
     * Регулярки собраны так, чтобы ведущий обратный слэш имени класса был необязательным, а имя
     * метода статического вызова — любым: createFromFormat, createFromInterface и другие
     * фабричные методы класса дат попадают под ту же строку.
     */
    private const FORBIDDEN = [
        '/\bdate\s*\(/' => 'date() — форматирование в обход core/date.php',
        '/\bgmdate\s*\(/' => 'gmdate() — форматирование в обход core/date.php',
        '/\bstrtotime\s*\(/' => 'strtotime() — разбор даты в обход core/date.php',
        '/\bmktime\s*\(/' => 'mktime() — сборка момента в обход core/date.php',
        '/\btime\s*\(\s*\)/' => 'time() — момент в обход core/date.php',
        '/->\s*format\s*\(/' => '->format() — форматирование в обход core/date.php',
        '/->\s*setTimezone\s*\(/' => '->setTimezone() — перевод пояса в обход core/date.php',
        '/\bdate_create\w*\s*\(/' => 'date_create*() — конструктор момента функциональной формой',
        '/new\s+\\\\?DateTime(Immutable|Zone|Diff)?\s*\(/' => 'new DateTime* — создание момента, в том числе с ведущим обратным слэшем',
        '/\\\\?DateTime(Immutable|Interface|Zone)?::\s*\w+\s*\(/' => 'DateTime*::… — статический вызов класса дат без new',
    ];

    public function testНиктоКромеМодуляДатНеФорматируетВремя(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $relative = $this->relative($file);
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $code = $this->withoutComments((string) file_get_contents($file));
            foreach (self::FORBIDDEN as $pattern => $reason) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $relative . ': ' . $reason;
                }
            }
        }

        self::assertSame([], $violations, 'Найдены места, где дата обрабатывается в обход core/date.php');
    }

    // Проверка силы самого стража: равноправная форма обязана давать находку, а разрешённый файл —
    // нет. Без этого теста список регулярок мог бы разойтись с реальностью молча.
    public function testСтражЛовитРавноправныеСпособыСоздатьМомент(): void
    {
        foreach ([
            '<?php $x = new \DateTimeImmutable("now");',
            '<?php $x = new \DateTime("now");',
            '<?php $x = date_create("now");',
            '<?php $x = date_create_immutable("now");',
            '<?php $x = date_create_from_format("Y-m-d", "2026-01-01");',
            '<?php $x = \DateTimeImmutable::createFromFormat("Y-m-d", "2026-01-01");',
            '<?php $x = DateTime::createFromInterface($y);',
            '<?php $x = gmdate("Y-m-d H:i:s");',
            '<?php $x = mktime(0, 0, 0, 1, 1, 2026);',
            '<?php $x = $y->setTimezone($z);',
        ] as $sample) {
            self::assertNotSame([], $this->dateViolations($sample, 'probe.php'), 'Страж пропустил способ создать или отформатировать момент: ' . $sample);
        }

        // Тот же прогон по разрешённому файлу не должен находить ничего: иначе исключение не работает.
        self::assertSame([], $this->dateViolations('<?php $x = new \DateTimeImmutable("now");', 'core/date.php'));
        // Разрешённые читателю помощники модуля дат не считаются нарушением.
        self::assertSame([], $this->dateViolations('<?php $x = utcStamp(nowUtc());', 'core/routes.php'));
        self::assertSame([], $this->dateViolations('<?php $x = formatRuDateTime($y);', 'theme/home.php'));
    }

    /**
     * Находки в одном исходнике: список запрещённых форм, снятые комментарии, имя файла влияет
     * только на исключение core/date.php.
     *
     * @return list<string>
     */
    private function dateViolations(string $code, string $relative): array
    {
        if (in_array($relative, self::ALLOWED, true)) {
            return [];
        }

        $body = $this->withoutComments($code);
        $out = [];
        foreach (self::FORBIDDEN as $pattern => $reason) {
            if (preg_match($pattern, $body) === 1) {
                $out[] = $relative . ': ' . $reason;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['core', 'admin', 'theme', 'apps', 'public'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

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
