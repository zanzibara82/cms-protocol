<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Чекбокс и его подпись разъезжаются, когда поле растянуто зоной нажатия, а подпись выровнена по
// верхнему краю. Причина измерена на стенде: min-h-[44px] на <input type="checkbox"> делает поле
// 20×44, а браузер рисует квадратик по центру этой высоты — то есть на 14 px ниже центра первой
// строки текста. На скриншоте подпись «Согласие на обработку…» стоит рядом с пустым местом.
//
// Правило: растянутое зонами нажатия поле в подписи требует выравнивания по центру (items-center).
// Тогда центр квадратика и центр строки совпадают. Растянутым считается поле с min-h-[44px] или
// size-11: у обоих высота больше или равна 44 px при меньшей ширине нативного квадратика, поэтому
// по верхнему краю они не сходятся.
//
// Обратная сторона того же правила — зона нажатия не меньше 44 px (TapTargetTest): просто убрать
// min-h-[44px] у поля нельзя, страж зон нажатия этого не пропустит. Центрирование сохраняет и
// зону, и выравнивание.
//
// Второе правило файла — один атрибут class на тег. В coupons.php и promos.php их было по два:
// браузер берёт первый и молча выбрасывает второй, поэтому «h-4 w-4» в разметке не работал,
// а человек видел 44-пиксельный квадрат с рамкой.
final class CheckboxAlignTest extends TestCase
{
    /** Классы, которыми поле растягивают по высоте ради зоны нажатия. */
    private const STRETCH_CLASSES = ['min-h-[44px]', 'size-11'];

    /** Выравнивание подписи, при котором центр квадратика совпадает с центром строки. */
    private const ALIGN_CLASS = 'items-center';

    private const MARKUP_ROOTS = ['theme', 'admin', 'apps', 'core', 'public'];

    public function testЧекбоксВПодписиВыровненПоЦентруИлиНеРастянут(): void
    {
        $violations = [];

        foreach ($this->templates() as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ($this->violations($code, $relative) as $found) {
                $violations[] = $found;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Растянутый чекбокс в подписи без ' . self::ALIGN_CLASS . ': квадратик уезжает от текста'
        );
    }

    public function testУТегаОдинАтрибутClass(): void
    {
        $violations = [];

        foreach ($this->templates() as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ($this->doubleClassViolations($code, $relative) as $found) {
                $violations[] = $found;
            }
        }

        self::assertSame([], $violations, 'У тега два атрибута class: браузер берёт первый, второй не работает');
    }

    // Самопроверка: разметка формы заявки до правки обязана давать находку, исправленная — нет.
    // Без этого зелёный прогон мог бы означать «шаблоны не читаются».
    public function testСтражЛовитРазъехавшийсяЧекбоксИПропускаетВыровненный(): void
    {
        // Разметка формы заявки до правки: растянутое поле, подпись по верхнему краю.
        self::assertSame(
            ['probe.php: строка 1: <input type="checkbox"> растянут и стоит в подписи без items-center — квадратик уезжает от текста'],
            $this->violations(
                '<label class="flex min-h-[44px] cursor-pointer items-start gap-2 text-sm select-none">'
                . '<input class="mt-0.5 h-5 w-5 min-h-[44px] shrink-0 accent-ink" type="checkbox" required>'
                . '<span>Согласие</span></label>',
                'probe.php'
            )
        );

        // Исправленная разметка: выравнивание по центру, зона нажатия сохранена.
        self::assertSame([], $this->violations(
            '<label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm select-none">'
            . '<input class="h-5 w-5 min-h-[44px] shrink-0 accent-ink" type="checkbox" required>'
            . '<span>Согласие</span></label>',
            'probe.php'
        ));

        // Приём админки: поле скрыто, квадратик нарисован рядом — растяжения нет вовсе.
        self::assertSame([], $this->violations(
            '<label class="flex min-h-[44px] items-start gap-2 text-sm">'
            . '<input class="peer sr-only" type="checkbox" name="noindex" value="1">'
            . '<span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded border" aria-hidden="true"></span>'
            . '<span>Закрыть адрес</span></label>',
            'probe.php'
        ));

        // Нерастянутое поле выравнивания не требует: 20 px совпадают со строкой сами.
        self::assertSame([], $this->violations(
            '<label class="flex items-start gap-2 text-sm"><input class="h-5 w-5" type="checkbox"><span>Флажок</span></label>',
            'probe.php'
        ));

        // Многострочный тег читается целиком, а не построчно.
        self::assertSame(
            ['probe.php: строка 2: <input type="checkbox"> растянут и стоит в подписи без items-center — квадратик уезжает от текста'],
            $this->violations(
                "<label class=\"flex items-start gap-3\">\n"
                . "<input type=\"checkbox\" name=\"active\" value=\"1\"\n"
                . " class=\"min-h-[44px] size-11 rounded border\">\n"
                . '<span>Приёмник активен</span></label>',
                'probe.php'
            )
        );

        // Обычное поле формы в подписи по верхнему краю: правило касается только чекбокса.
        self::assertSame([], $this->violations(
            '<label class="flex items-start gap-2"><input class="min-h-[44px] w-full" type="text" name="q"></label>',
            'probe.php'
        ));

        // Значение похожего предела растяжением не считается: min-h-[24px] — не 44 px.
        self::assertSame([], $this->violations(
            '<label class="flex items-start gap-2"><input class="h-5 w-5 min-h-[24px]" type="checkbox"></label>',
            'probe.php'
        ));
    }

    public function testСтражЛовитВторойАтрибутClass(): void
    {
        self::assertSame(
            ['probe.php: строка 1: <input> несёт два атрибута class — работает только первый'],
            $this->doubleClassViolations(
                '<input id="promo_active" name="active" type="checkbox" value="1" class="min-h-[44px] size-11 rounded" checked class="h-4 w-4">',
                'probe.php'
            )
        );

        self::assertSame([], $this->doubleClassViolations(
            '<input id="promo_active" name="active" type="checkbox" value="1" class="min-h-[44px] size-11 rounded" checked>',
            'probe.php'
        ));
    }

    /**
     * Растянутые чекбоксы, чья подпись не выровнена по центру.
     *
     * @return list<string>
     */
    private function violations(string $code, string $relative): array
    {
        if (preg_match_all('/<input\b/i', $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as $match) {
            $start = (int) $match[1];
            $end = $this->tagEnd($code, $start + strlen($match[0]));

            if ($end === null) {
                continue;
            }

            $text = substr($code, $start, $end - $start + 1);

            // Тип читается по значению атрибута: `data-type="checkbox"` — не чекбокс.
            if (preg_match('/[\s"\']type[\s]*=[\s]*"checkbox"/', $text) !== 1) {
                continue;
            }

            $tokens = $this->classTokens($text);

            // Скрытое поле не рисуется вовсе: квадратик рядом рисует разметка, выравнивать нечего.
            if (in_array('sr-only', $tokens, true)) {
                continue;
            }

            if (array_intersect(self::STRETCH_CLASSES, $tokens) === []) {
                continue;
            }

            $label = $this->enclosingLabel($code, $start);

            if ($label === null) {
                // Поле без подписи: выравнивать не с чем, но и зона нажатия не с текстом — это
                // отдельная находка не этого правила.
                continue;
            }

            if (in_array(self::ALIGN_CLASS, $this->classTokens($label), true)) {
                continue;
            }

            $line = 1 + substr_count($code, "\n", 0, $start);
            $found[] = $relative . ': строка ' . $line . ': <input type="checkbox"> растянут и стоит в подписи без '
                . self::ALIGN_CLASS . ' — квадратик уезжает от текста';
        }

        return $found;
    }

    /**
     * Теги с двумя атрибутами class: второй браузер молча выбрасывает.
     *
     * @return list<string>
     */
    private function doubleClassViolations(string $code, string $relative): array
    {
        if (preg_match_all('/<(input|select|textarea|a|button|span|div|label|form|figure|img)\b/i', $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as $match) {
            $start = (int) $match[1];
            $end = $this->tagEnd($code, $start + strlen($match[0]));

            if ($end === null) {
                continue;
            }

            $text = substr($code, $start, $end - $start + 1);

            // Атрибут ищется целиком со значением: `data-class="…"` атрибутом class не является.
            if (preg_match_all('/(?:[\s"\']|^)class[\s]*=[\s]*"[^"]*"/', $text) < 2) {
                continue;
            }

            $line = 1 + substr_count($code, "\n", 0, $start);
            // Имя тега без «<»: в $match лежит пара «совпадение + смещение», группы живут в
            // отдельном элементе $matches[1].
            $tag = strtolower(substr((string) $match[0], 1));
            $found[] = $relative . ': строка ' . $line . ': <' . $tag . '> несёт два атрибута class — работает только первый';
        }

        return $found;
    }

    /** Разметка подписи, внутри которой стоит поле: ближайший <label> до начала тега. */
    private function enclosingLabel(string $code, int $before): ?string
    {
        $position = strripos(substr($code, 0, $before), '<label');

        if ($position === false) {
            return null;
        }

        $end = $this->tagEnd($code, $position + 6);

        return $end === null ? null : substr($code, $position, $end - $position + 1);
    }

    /** @return list<string> */
    private function classTokens(string $tag): array
    {
        if (preg_match('/(?:[\s"\']|^)class[\s]*=[\s]*"([^"]*)"/', $tag, $class) !== 1) {
            return [];
        }

        return array_values(array_filter(
            preg_split('~[\n\r\t ]+~', trim((string) $class[1])) ?: [],
            static fn (string $token): bool => $token !== ''
        ));
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
        $found = [];

        foreach (self::MARKUP_ROOTS as $dir) {
            $root = $this->root() . '/' . $dir;

            self::assertDirectoryExists(
                $root,
                $dir . ': каталог разметки исчез из дерева — страж проверял бы меньше, чем требует правило'
            );

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $found[str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1))] = true;
                }
            }
        }

        $paths = array_keys($found);
        sort($paths);

        self::assertNotEmpty($paths, 'Ни одного файла разметки не найдено — проверять нечего');

        return $paths;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
