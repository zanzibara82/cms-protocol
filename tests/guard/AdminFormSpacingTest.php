<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Подпись поля и сам контрол не должны слипаться: на экранах админки отступ mt-1 (4 px) читался
// как «надпись почти касается рамки», особенно у селектов с высокой строкой. Правка внешнего вида
// без стража откатывается первой же новой формой, поэтому требование записано проверкой.
//
// Правило узкое с умыслом: контрол внутри подписи обязан нести вертикальный отступ, и это не mt-1.
// Исправленное значение — mt-1.5. Подсказка под полем живёт отдельной строкой и правилом не
// затрагивается: её отступ к контролу не относится.
//
// Проверяются две формы записи класса: литерал в атрибуте и общая переменная шаблона
// (`$input = 'mt-1 w-full …'`), потому что почти все поля админки собираются второй формой.
// Без неё страж зеленел бы на восьми экранах с прижатыми полями и краснел на одном литеральном.
//
// Второе правило — про ту же близость с другой стороны: подпись бывает не <span>, а текстом прямо
// в <label> («Название блока<input …>»). Такой контрол обязан нести mt-* сам: у текстовой подписи
// нет своего margin-bottom, и без отступа строка встаёт вплотную к рамке (блоки новостей — 1 px).
//
// Третье правило — про подсказку ПОД контролом: она отстоит на mt-1.5, как и поле от подписи.
// mt-1 (4 px) читался как «подсказка прилипла к рамке»; проверяются обе формы записи класса.
final class AdminFormSpacingTest extends TestCase
{
    /** Отступ, который запрещён у контрола: с ним подпись почти касается рамки поля. */
    private const FORBIDDEN = 'mt-1';

    /** Отступ, который обязан быть: он и есть исправленное значение. */
    private const REQUIRED = 'mt-1.5';

    /**
     * Каталоги разметки с формами: ядро админки, шаблоны приложений и общая тема. Приложения
     * рисуют свои админки теми же контролами, а форма заявки и вход живут в теме — правило одно
     * на все, иначе «поля ровно» держалось бы только у ядра.
     */
    private const MARKUP_ROOTS = ['core/templates', 'apps', 'theme'];

    public function testКонтролыАдминкиНеПрижимаютсяКПодписи(): void
    {
        $files = $this->adminTemplates();
        self::assertNotEmpty($files, 'Ни одного шаблона админки не найдено — проверять нечего');

        $violations = [];

        foreach ($files as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ([...$this->controlViolations($code), ...$this->variableViolations($code)] as $found) {
                $violations[] = $relative . ': ' . $found;
            }
        }

        self::assertSame([], $violations, 'Контрол админки прижат к подписи: нужен ' . self::REQUIRED);
    }

    // Самопроверка: прежняя разметка обязана давать находку, исправленная — нет. Без этого
    // зелёный прогон мог бы означать «шаблоны не читаются», а не «отступ в порядке».
    public function testСтражЛовитПрижатыйКонтролИПропускаетИсправленный(): void
    {
        self::assertNotEmpty(
            $this->controlViolations('<input class="mt-1 w-full" type="text" name="q">'),
            'mt-1 у контрола не пойман'
        );
        self::assertSame(
            [],
            $this->controlViolations('<input class="mt-1.5 w-full" type="text" name="q">'),
            'исправленный отступ помечен как находка'
        );
        self::assertSame(
            [],
            $this->controlViolations('<span class="mt-1 block text-ink/60">Подсказка</span>'),
            'отступ подсказки правилом не затрагивается'
        );

        // Класс, собранный переменной, виден только своим литералом: у атрибута там имя
        // переменной, и находка обязана прийти из объявления, а не из атрибута.
        self::assertSame(
            [],
            $this->controlViolations('<input class="min-h-[44px] \' . e($input) . \'" type="text">'),
            'класс из переменной не выдумывается'
        );
        self::assertNotEmpty(
            $this->variableViolations("\$input = 'mt-1 w-full rounded-lg text-sm';"),
            'прижатое поле в переменной не поймано'
        );
        self::assertSame(
            [],
            $this->variableViolations("\$input = 'mt-1.5 w-full rounded-lg text-sm';"),
            'исправленная переменная помечена как находка'
        );
    }

    // Подпись текстом прямо в <label> не несёт своего margin-bottom: отступ обязан нести сам
    // контрол. Без mt-* строка подписи встаёт вплотную к рамке — замер на экране блоков новостей
    // дал 1 px вместо 7.
    public function testКонтролСПодписьюТекстомНеПрижимается(): void
    {
        $files = $this->adminTemplates();
        self::assertNotEmpty($files, 'Ни одного шаблона админки не найдено — проверять нечего');

        $violations = [];

        foreach ($files as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ($this->textLabelViolations($code, $this->classLiterals($code)) as $found) {
                $violations[] = $relative . ': ' . $found;
            }
        }

        self::assertSame([], $violations, 'Подпись текстом вплотную к контролу: нужен ' . self::REQUIRED);
    }

    // Самопроверка правила о текстовой подписи: находка обязана приходить на реальной разметке
    // экрана блоков и молчать, когда отступ стоит.
    public function testСтражЛовитТекстовуюПодписьБезОтступа(): void
    {
        self::assertNotEmpty(
            $this->textLabelViolations(
                '<label class="grow text-sm">Название блока'
                . '<input class="min-h-[44px] w-full rounded-lg border" type="text" name="title"></label>',
                []
            ),
            'текстовая подпись без отступа у контрола не поймана'
        );

        self::assertSame(
            [],
            $this->textLabelViolations(
                '<label class="grow text-sm">Название блока'
                . '<input class="mt-1.5 min-h-[44px] w-full" type="text" name="title"></label>',
                []
            ),
            'исправленная разметка помечена как находка'
        );

        // Подпись отдельным <span> правилом не затрагивается: у неё свой блок, и отступ даёт
        // именно контрол — эту сторону держит первая проверка.
        self::assertSame(
            [],
            $this->textLabelViolations(
                '<label class="block text-sm"><span class="font-medium">Подпись</span>'
                . '<input class="min-h-[44px] w-full" type="text" name="q"></label>',
                []
            ),
            'подпись отдельным span не считается текстовой'
        );
    }

    // Подсказка ПОД контролом отстоит на mt-1.5: mt-1 (4 px) читался как «слова прилипли к рамке».
    public function testПодсказкаПодКонтроломНеПрижимается(): void
    {
        $files = $this->adminTemplates();
        self::assertNotEmpty($files, 'Ни одного шаблона админки не найдено — проверять нечего');

        $violations = [];

        foreach ($files as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ([...$this->hintViolations($code), ...$this->wrapperViolations($code)] as $found) {
                $violations[] = $relative . ': ' . $found;
            }
        }

        self::assertSame([], $violations, 'Подсказка прижата к контролу: нужен ' . self::REQUIRED);
    }

    // Самопроверка правила о подсказке: mt-1 ловится, mt-1.5 проходит.
    public function testСтражЛовитПрижатуюПодсказкуИПропускаетИсправленную(): void
    {
        $label = static fn (string $hint): string => '<label class="block">Подпись'
            . '<input class="mt-1.5 w-full" type="text" name="q">'
            . '<span class="' . $hint . '">Подсказка</span></label>';

        self::assertNotEmpty(
            $this->hintViolations($label('mt-1 block text-sm text-ink/60')),
            'подсказка с mt-1 не поймана'
        );
        self::assertSame(
            [],
            $this->hintViolations($label('mt-1.5 block text-sm text-ink/60')),
            'исправленная подсказка помечена как находка'
        );
        // Строка без mt- вовсе отступа не даёт: тоже находка.
        self::assertNotEmpty(
            $this->hintViolations($label('block text-sm text-ink/60')),
            'подсказка без отступа не поймана'
        );
        // Подпись-метка ПЕРЕД полем несёт mb-1, а не mt-: правилом она не затрагивается.
        self::assertSame(
            [],
            $this->hintViolations('<label class="block">'
                . '<span class="mb-1 block text-sm text-ink/70">Подпись</span>'
                . '<input class="min-h-[44px] w-full" type="text" name="q"></label>'),
            'метка перед полем помечена как находка'
        );
    }

    // Подпись отдельным <span> — строчная строка текста. Если у контрола нет w-full, браузер
    // ставит их в одну строку, и надпись оказывается СЛЕВА от поля (экран поиска: «Что найти»
    // вплотную к рамке). w-full переносит контрол на свою строку, поэтому поля полной ширины
    // держались «случайно», а поля с min-w- и дата — нет.
    public function testПодписьНеВстаётСлеваОтПоля(): void
    {
        $files = $this->adminTemplates();
        self::assertNotEmpty($files, 'Ни одного шаблона админки не найдено — проверять нечего');

        $violations = [];

        foreach ($files as $relative) {
            $code = (string) file_get_contents($this->root() . '/' . $relative);

            foreach ($this->labelLayoutViolations($code) as $found) {
                $violations[] = $relative . ': ' . $found;
            }
        }

        self::assertSame([], $violations, 'Подпись стоит слева от поля: нужен block у подписи или w-full у контрола');
    }

    // Самопроверка: находка обязана приходить на разметке экрана поиска и молчать, когда подпись
    // блочная или поле занимает всю ширину.
    public function testСтражЛовитПодписьСлеваОтПоля(): void
    {
        self::assertNotEmpty(
            $this->labelLayoutViolations(
                '<label class="block text-sm"><span class="font-medium">Что найти</span>'
                . '<input class="mt-1.5 min-w-[16rem]" type="search" name="q"></label>'
            ),
            'подпись слева от поля не поймана'
        );
        self::assertSame(
            [],
            $this->labelLayoutViolations(
                '<label class="block text-sm"><span class="block font-medium">Что найти</span>'
                . '<input class="mt-1.5 min-w-[16rem]" type="search" name="q"></label>'
            ),
            'блочная подпись помечена как находка'
        );
        self::assertSame(
            [],
            $this->labelLayoutViolations(
                '<label class="block text-sm"><span class="font-medium">Имя</span>'
                . '<input class="mt-1.5 w-full" type="text" name="q"></label>'
            ),
            'поле полной ширины помечено как находка'
        );
        // Горизонтальная раскладка — другой приём: там подпись и поле стоят рядом намеренно.
        self::assertSame(
            [],
            $this->labelLayoutViolations(
                '<label class="flex items-center gap-2 text-sm"><span class="font-medium">Имя</span>'
                . '<input class="min-h-[44px]" type="text" name="q"></label>'
            ),
            'горизонтальная раскладка помечена как находка'
        );
    }

    /**
     * Подпись, которая встаёт слева от контрола: строчный <span> подписи и контрол без полной
     * ширины внутри одной подписи-блока.
     *
     * Почему проверка читает склейку строк: шаблоны собирают разметку конкатенацией PHP-строк
     * ('. «строка» .'), и без снятия склейки тег и его атрибуты оказываются в разных строках —
     * страж не увидел бы ни одной находки.
     *
     * @return list<string>
     */
    private function labelLayoutViolations(string $code): array
    {
        $literals = $this->classLiterals($code);

        // Значения, напечатанные PHP внутри атрибута (короткий вывод вроде «e($x)»), заменяются
        // заглушкой ДО снятия склейки: иначе тег рвётся на закрывающем теге PHP, и разбор не
        // видит ни подписи, ни контрола — ложные находки на исправных экранах (корзина, заказ).
        $flat = (string) preg_replace('~<\?(?:=|php)\s.*?\?>~s', 'x', $code);
        $flat = (string) preg_replace('~[\'"]\s*\.\s*[\'"]~', '', $flat);

        preg_match_all('~<label\b([^>]*)>(.*?)</label>~s', $flat, $labels, PREG_SET_ORDER);

        $found = [];

        foreach ($labels as $label) {
            $labelClass = $this->attrClass((string) $label[1]) ?? '';

            // Горизонтальная раскладка выравнивает подпись и поле в строку намеренно.
            if (preg_match('~\b(flex|inline-flex|grid)\b~', $labelClass) === 1) {
                continue;
            }

            if (preg_match('~<span class="([^"]*)"[^>]*>[^<]*</span>\s*<(input|select|textarea)\b([^>]*)>~s', (string) $label[2], $match) !== 1) {
                continue;
            }

            $spanClass = (string) $match[1];
            $attrs = (string) $match[3];

            if (str_contains($attrs, 'sr-only') || str_contains($attrs, 'type="hidden"') || str_contains($attrs, 'type="checkbox"')) {
                continue;
            }

            if (preg_match('~\bblock\b~', $spanClass) === 1) {
                continue;
            }

            $control = $this->classOf($attrs, $code, $literals) ?? '';

            // Константа, приклеенная через «. ИМЯ .», приходит в $control своим именем —
            // разворачивается по литералу файла, иначе w-full внутри неё не виден.
            foreach ($literals as $name => $literal) {
                if (str_contains($control, $name)) {
                    $control .= ' ' . $literal;
                }
            }

            if (str_contains($control, 'w-full') || str_contains($control, 'flex-1')) {
                continue;
            }

            $found[] = '<' . $match[2] . ' class="' . trim($control) . '"> после <span class="' . $spanClass . '">';
        }

        return $found;
    }

    /**
     * Контролы внутри <label>, где подпись — текст, а не <span>: у такого контрола обязан быть
     * вертикальный отступ. Класс берётся из литерала или из объявленной переменной/константы.
     *
     * Чекбоксы, радиокнопки и файлы пропускаются: их ставит в строку flex-раскладка подписи
     * (`flex items-center gap-2`), и отступ им не нужен — выравнивание даёт выравнивание по
     * центру. По той же причине пропускается контрол в подписи с `flex`.
     *
     * @param array<string, string> $literals классы переменных и констант файла
     *
     * @return list<string>
     */
    private function textLabelViolations(string $code, array $literals): array
    {
        $found = [];

        // <label …>текст<контрол …> — между «>» подписи и «<» контрола нет другого тега.
        $pattern = '~<label\b([^>]*)>[^<]+<(input|select|textarea)\b([^>]*)>~s';

        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $labelAttrs = (string) $match[1];
            $attrs = (string) $match[3];

            if (str_contains($attrs, 'type="hidden"') || str_contains($attrs, 'sr-only')) {
                continue;
            }

            if (str_contains($attrs, 'type="checkbox"') || str_contains($attrs, 'type="radio"') || str_contains($attrs, 'type="file"')) {
                continue;
            }

            // Горизонтальная раскладка: подпись и контрол стоят в строке, выравнивание не по верху.
            $labelClass = $this->attrClass($labelAttrs);

            if ($labelClass !== null && preg_match('~\b(?:flex|items-|inline-flex)\b~', $labelClass) === 1) {
                continue;
            }

            $class = $this->classOf($attrs, $code, $literals);

            if ($class === null || !$this->hasVerticalOffset($class)) {
                $found[] = '<' . $match[2] . ' class="' . (string) $class . '">';
            }
        }

        return $found;
    }

    /**
     * Подсказки и причины отказа ПОД контролом внутри одной подписи: <span|p class="…text-ink/60…">
     * после поля. Отступ к контролу несёт mt-1.5; mt-1 и его отсутствие — находка.
     *
     * Проверка держится на порядке «контрол, затем подсказка в той же подписи»: у подписи-метки
     * ПЕРЕД полем (mb-1) и у заголовка раздела (text-xs uppercase) отступ к контролу другой, и
     * правилом они не затрагиваются.
     *
     * @return list<string>
     */
    private function hintViolations(string $code): array
    {
        $found = [];

        foreach ($this->labelSegments($code) as $label) {
            // Позиция контрола: подсказкой считается только то, что напечатано ПОСЛЕ него.
            // Метка перед полем несёт mb-1 — это другой отступ и другое правило.
            if (preg_match('~<(?:input|select|textarea)\b[^>]*>~s', $label, $ctrl, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $after = substr($label, (int) $ctrl[0][1] + strlen((string) $ctrl[0][0]));

            preg_match_all('~<(?:span|p)\b[^>]*?class="([^"]*)"~s', $after, $hints);

            foreach ($hints[1] as $class) {
                if (!$this->isHintClass((string) $class)) {
                    continue;
                }

                if (!$this->hasRequiredOffset((string) $class)) {
                    $found[] = '<span class="' . $class . '">';
                }
            }
        }

        return $found;
    }

    /** Подсказка — приглушённый или красный блок текста под полем. */
    private function isHintClass(string $class): bool
    {
        $tokens = preg_split('~[\n\r\t ]+~', $class) ?: [];

        foreach (['text-ink/60', 'text-ink/50', 'text-bad', 'text-red-700'] as $hint) {
            if (in_array($hint, $tokens, true)) {
                return in_array('block', $tokens, true) || in_array('text-sm', $tokens, true);
            }
        }

        return false;
    }

    /**
     * Подсказка отдельной строкой под контролом: обёртка `space-y-1` даёт 4 px вместо 7.
     *
     * Форма записи взята у экранов, которые держат подсказку рядом с полем, а не внутри подписи
     * (item-form новостей, форма урока курсов): <div class="space-y-1">…контрол…<p class="text-sm…">.
     * Замер на стенде: зазор 4 px вместо 7 — подсказка читалась приклеенной к рамке.
     *
     * @return list<string>
     */
    private function wrapperViolations(string $code): array
    {
        $found = [];

        if (preg_match_all('~<div class="space-y-1[^"]*"~', $code) === 0) {
            return $found;
        }

        // Внутри такой обёртки подсказка-абзац обязана нести свой mt-: ровно один раз на обёртку.
        $pattern = '~<div class="space-y-1[^"]*">((?:(?!<div class="space-y-1).)*?)</div>~s';

        preg_match_all($pattern, $code, $matches);

        foreach ($matches[1] as $inner) {
            if (preg_match('~<p class="([^"]*)"~', $inner, $hint) !== 1) {
                continue;
            }

            $class = (string) $hint[1];

            if (!$this->isHintClass($class)) {
                continue;
            }

            if (!$this->hasVerticalOffset($class)) {
                $found[] = '<div class="space-y-1"> + <p class="' . $class . '">';
            }
        }

        return $found;
    }

    /** Отступ подсказки: mt-1.5 или крупнее; mt-1 недостаточен. */
    private function hasRequiredOffset(string $class): bool
    {
        $tokens = preg_split('~[\n\r\t ]+~', $class) ?: [];

        if (in_array(self::FORBIDDEN, $tokens, true)) {
            return false;
        }

        foreach ($tokens as $token) {
            if (preg_match('~^m[ty]-(1\.[5-9]|[2-9]|\d{2,})~', $token) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Содержимое каждой подписи файла: от <label …> до </label>. Вложенные подписи (метка внутри
     * метки) сюда не попадают — размеченные так формы проект не пишет.
     *
     * @return list<string>
     */
    private function labelSegments(string $code): array
    {
        preg_match_all('~<label\b[^>]*>(.*?)</label>~s', $code, $matches);

        return array_map(static fn (mixed $segment): string => (string) $segment, $matches[1]);
    }

    /** Класс из строки атрибутов тега или null, если его нет. */
    private function attrClass(string $attrs): ?string
    {
        return preg_match('~class="([^"]*)"~', $attrs, $match) === 1 ? (string) $match[1] : null;
    }

    /** Есть ли у класса вертикальный отступ (mt-* или my-*). */
    private function hasVerticalOffset(string $class): bool
    {
        $tokens = preg_split('~[\n\r\t ]+~', $class) ?: [];

        foreach ($tokens as $token) {
            if (preg_match('~^m[ty]-~', $token) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Класс контрола: литерал из атрибута или развёрнутая переменная/константа файла.
     *
     * @param array<string, string> $literals
     */
    private function classOf(string $attrs, string $code, array $literals): ?string
    {
        // Литерал или конкатенация `class="min-h-[44px] ' . $field . '"`: берётся весь атрибут,
        // а имя переменной внутри разворачивается ниже.
        if (preg_match('~class="([^"]*)"~', $attrs, $match) === 1) {
            $literal = (string) $match[1];

            if (preg_match('~\$([A-Za-z_][A-Za-z0-9_]*)~', $literal, $var) === 1) {
                return $literals[(string) $var[1]] ?? $literal;
            }

            return $literal;
        }

        if (preg_match('~e\(\$([A-Za-z_][A-Za-z0-9_]*)\)|\'\s*\.\s*\$([A-Za-z_][A-Za-z0-9_]*)~', $attrs, $match) === 1) {
            $name = (string) ($match[1] !== '' ? $match[1] : $match[2]);

            return $literals[$name] ?? null;
        }

        if (preg_match('~\.\s*([A-Z][A-Z0-9_]+)~', $attrs, $match) === 1) {
            return $literals[(string) $match[1]] ?? null;
        }

        return null;
    }

    /**
     * Объявленные в файле классы: `$input = '…'`, `$field = '…'`, `const X_CLASS = '…'`.
     *
     * @return array<string, string>
     */
    private function classLiterals(string $code): array
    {
        $pattern = '~(?:\$([A-Za-z_][A-Za-z0-9_]*)|const\s+([A-Za-z_][A-Za-z0-9_]*))\s*=\s*\'([^\']*)\'~';

        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER);

        $literals = [];

        foreach ($matches as $match) {
            $name = (string) ($match[1] !== '' ? $match[1] : $match[2]);
            $literals[$name] = (string) $match[3];
        }

        return $literals;
    }

    /**
     * Контролы с классом mt-1 прямо в атрибуте.
     *
     * @return list<string>
     */
    private function controlViolations(string $code): array
    {
        $pattern = '~<(?:input|select|textarea)\b[^>]*?class="([^"]*)"[^>]*>~s';

        preg_match_all($pattern, $code, $matches);

        $found = [];

        foreach ($matches[1] as $class) {
            if ($this->hasForbidden($class)) {
                $found[] = '<' . $class . '>';
            }
        }

        return $found;
    }

    /**
     * Переменные шаблона, которыми набирается класс контрола: `$input = '…'`, `$INPUT = '…'`,
     * `const LEARN_FORM_INPUT_CLASS = '…'`. Имя оканчивается на INPUT (любой регистр) или это
     * константа класса ввода — по этому признаку они и отличаются от прочих строк шаблона.
     *
     * @return list<string>
     */
    private function variableViolations(string $code): array
    {
        $pattern = '~(?:\$([A-Za-z_][A-Za-z0-9_]*)|const\s+([A-Za-z_][A-Za-z0-9_]*))\s*=\s*\'([^\']*)\'~';

        preg_match_all($pattern, $code, $matches, PREG_SET_ORDER);

        $found = [];

        foreach ($matches as $match) {
            $name = (string) ($match[1] !== '' ? $match[1] : $match[2]);
            $literal = (string) $match[3];

            if (stripos($name, 'input') === false) {
                continue;
            }

            if ($this->hasForbidden($literal)) {
                $found[] = $name . ' = ' . $literal;
            }
        }

        return $found;
    }

    private function hasForbidden(string $class): bool
    {
        $tokens = preg_split('~[\n\r\t ]+~', $class) ?: [];

        return in_array(self::FORBIDDEN, $tokens, true);
    }

    /** @return list<string> */
    private function adminTemplates(): array
    {
        $files = [];

        foreach (self::MARKUP_ROOTS as $root) {
            $dir = $this->root() . '/' . $root;

            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));
            }
        }

        sort($files);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
