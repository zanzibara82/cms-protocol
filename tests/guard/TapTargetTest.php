<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Зона нажатия не меньше 44 px — глобальное требование проекта без исключений; оно записано и в
// комментариях источника (admin/layout.php:59-60), и в глобальных ограничениях пилота. Проверка
// идёт по исходникам представлений, а не только по отрендеренным страницам: http-набор видит
// ровно те маршруты, до которых дошёл тест, а требование касается каждого контрола — в том числе
// тех, что появятся в приложениях позже.
final class TapTargetTest extends TestCase
{
    // Класс, задающий минимальную зону нажатия: он уже есть в собранном артефакте стилей.
    private const REQUIRED_CLASS = 'min-h-[44px]';

    // Не цели для пальца: у скрытых полей зоны нажатия нет вовсе, а «К содержимому» живёт в
    // sr-only и показывается только с клавиатуры.
    private const NON_POINTER_ATTRS = ['type="hidden"', 'sr-only'];

    // Класс контрола собран в PHP, поэтому внутри тега его не видно: проверка уходит на
    // отрендеренную страницу, её закрывает tests/http/AdminAppsTest.
    private const DYNAMIC_CLASS_FILES = ['admin/layout.php'];

    public function testКаждыйКонтролВШаблонахНесётЗонуНажатияСорокЧетыреПикселя(): void
    {
        $violations = [];

        foreach ($this->templates() as $file) {
            $code = (string) file_get_contents($file);
            foreach ($this->violations($code, $this->relative($file)) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Контрол без ' . self::REQUIRED_CLASS . ': зона нажатия меньше 44 px — '
                . 'ссылке и полю нужны min-h-[44px] (для строчной ссылки ещё inline-flex и items-center)'
        );
    }

    public function testДинамическийКлассНеОсвобождаетФайлОтТребования(): void
    {
        // Исключение по файлу снимает проверку с тега, но не с разметки: класс обязан остаться
        // в строке кода, а не в комментарии — иначе комментарий про 44 px заменял бы сам класс.
        // Строки режутся по переводу строки, а не по `\R`: без модификатора `u` тот считает
        // переводом и байт 0x85, а это половина кириллической «х» — комментарий разрезался бы, и
        // его хвост подошёл бы вместо кода.
        foreach (self::DYNAMIC_CLASS_FILES as $relative) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);

            self::assertNotEmpty(
                $this->codeCarriers($code),
                $relative . ': класс собирается в PHP — файл обязан нести зону нажатия в коде, а не в комментарии'
            );
        }
    }

    // Проверка на посаженной разметке: комментарий с кириллической «х» (байт 0x85 внутри буквы) не
    // должен сойти за код. Без этого probe мутация «снять класс из кода, оставить в комментарии»
    // проходила бы молча — ровно так, как прошло первое воплощение этой проверки.
    public function testКомментарийНеСчитаетсяНосителемКласса(): void
    {
        $commentOnly = "// Вкладки несут min-h-[44px] — зона нажатия\n\$a = 1;\n";
        self::assertSame([], $this->codeCarriers($commentOnly));

        $withCode = "// Вкладки несут min-h-[44px] — зона нажатия\n\$class = 'min-h-[44px]';\n";
        self::assertSame(["\$class = 'min-h-[44px]';"], $this->codeCarriers($withCode));
    }

    /** @return list<string> */
    private function codeCarriers(string $code): array
    {
        return array_values(array_filter(
            explode("\n", $code),
            function (string $line): bool {
                return $this->isCodeCarrier($line);
            }
        ));
    }

    private function isCodeCarrier(string $line): bool
    {
        $trimmed = trim($line);

        return str_contains($line, self::REQUIRED_CLASS)
            && !str_starts_with($trimmed, '//')
            && !str_starts_with($trimmed, '*');
    }

    public function testСтражЛовитКонтролБезЗоныНажатия(): void
    {
        self::assertSame(
            ['probe.php: строка 1: <a> без min-h-[44px]'],
            $this->violations('<a class="underline" href="/news">Новости</a>', 'probe.php')
        );

        // Многострочный тег: проверка читает его целиком, а не только строку с открытием.
        self::assertSame(
            ['probe.php: строка 1: <input> без min-h-[44px]'],
            $this->violations("<input class=\"mt-1 w-full\"\n       type=\"text\" name=\"login\">", 'probe.php')
        );

        self::assertSame([], $this->violations('<a class="inline-flex min-h-[44px] items-center" href="/news">Новости</a>', 'probe.php'));
    }

    public function testСкрытоеПолеИКлавиатурнаяСсылкаНеСчитаютсяЦелямиДляПальца(): void
    {
        self::assertSame([], $this->violations('<input type="hidden" name="_token" value="ab">', 'probe.php'));
        self::assertSame([], $this->violations('<a class="sr-only focus:not-sr-only" href="#main">К содержимому</a>', 'probe.php'));
    }

    /**
     * Нарушения в файле: каждый тег контрола обязан нести класс зоны нажатия. Тег читается как
     * текст до закрывающей скобки, которая не является концом PHP-блока (`?>`), — шаблоны ставят
     * внутрь тега условные куски разметки.
     *
     * @return list<string>
     */
    private function violations(string $code, string $relative): array
    {
        if (in_array($relative, self::DYNAMIC_CLASS_FILES, true)) {
            return [];
        }

        if (preg_match_all('/<(a|button|input|select|textarea)\b/i', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $out = [];

        foreach ($matches as $match) {
            $tag = strtolower($match[1][0]);
            $start = (int) $match[0][1];
            $end = $this->tagEnd($code, $start + strlen($match[0][0]));

            if ($end === null) {
                continue;
            }

            $text = substr($code, $start, $end - $start + 1);

            foreach (self::NON_POINTER_ATTRS as $attr) {
                if (str_contains($text, $attr)) {
                    continue 2;
                }
            }

            if (str_contains($text, self::REQUIRED_CLASS)) {
                continue;
            }

            $out[] = $relative . ': строка ' . (1 + substr_count($code, "\n", 0, $start)) . ': <' . $tag . '> без ' . self::REQUIRED_CLASS;
        }

        return $out;
    }

    private function tagEnd(string $code, int $from): ?int
    {
        $length = strlen($code);

        for ($i = $from; $i < $length; $i++) {
            // Символ `>` после вопроса закрывает PHP-блок внутри тега, а не сам тег.
            if ($code[$i] === '>' && ($i === 0 || $code[$i - 1] !== '?')) {
                return $i;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (['theme', 'admin', 'apps', 'core/templates'] as $dir) {
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

        sort($out);

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
