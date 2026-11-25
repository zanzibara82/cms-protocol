<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Каталог паттернов пригоден для конструктора. Те же причины, что у библиотеки шаблонов: вид
// должен рисоваться ядром, поля укладываться в пределы формы, а якоря не должны повторяться —
// иначе вставка паттерна откатится у человека, а не здесь. Паттерн короткий, поэтому предел
// секций проверяется отдельно от полной страницы.

final class PatternLibraryTest extends TestCase
{
    public function testВидыБлоковПаттерновОбъявленыВЯдре(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $pattern) {
            foreach ($pattern['sections'] as $index => $section) {
                $kind = (string) ($section['kind'] ?? '');

                if (!in_array($kind, PAGES_KINDS, true)) {
                    $violations[] = $key . '[' . $index . ']: вид «' . $kind . '» не из PAGES_KINDS';
                }
            }
        }

        self::assertSame([], $violations, 'Паттерн использует вид блока, которого ядро не рисует');
    }

    public function testЯкоряВнутриПаттернаНеПовторяются(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $pattern) {
            $seen = [];

            foreach ($pattern['sections'] as $section) {
                $anchor = (string) ($section['anchor'] ?? '');

                if ($anchor === '') {
                    $violations[] = $key . ': пустой якорь — паттерн нельзя отличить от соседнего';

                    continue;
                }

                if (isset($seen[$anchor])) {
                    $violations[] = $key . ': якорь «' . $anchor . '» занят дважды';
                }

                $seen[$anchor] = true;
            }
        }

        self::assertSame([], $violations, 'Два блока паттерна делят якорь: второй не сохранится');
    }

    public function testЯкоряРазныхПаттерновНеСовпадают(): void
    {
        $owners = [];

        foreach ($this->catalog() as $key => $pattern) {
            foreach ($pattern['sections'] as $section) {
                $anchor = (string) ($section['anchor'] ?? '');

                if (isset($owners[$anchor]) && $owners[$anchor] !== $key) {
                    self::fail('Якорь «' . $anchor . '» занят в паттернах ' . $owners[$anchor] . ' и ' . $key);
                }

                $owners[$anchor] = $key;
            }
        }

        self::assertNotSame([], $owners, 'Каталог не читается');
    }

    public function testПаттерныУкладываютсяВПределыКонструктора(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $pattern) {
            if (count($pattern['sections']) > 3) {
                $violations[] = $key . ': блоков ' . count($pattern['sections']) . ' — паттерн должен быть коротким';
            }

            foreach ($pattern['sections'] as $index => $section) {
                $title = trim((string) ($section['title'] ?? ''));
                $lead = (string) ($section['lead_text'] ?? '');

                if ($title === '' && $lead === '' && ($section['items'] ?? []) === [] && ($section['buttons'] ?? []) === []) {
                    $violations[] = $key . '[' . $index . ']: блок без содержимого';
                }

                if (mb_strlen($title) > PAGES_FIELDS['sectionTitle']['max']) {
                    $violations[] = $key . '[' . $index . ']: заголовок длиннее предела формы';
                }

                if (mb_strlen($lead) > PAGES_FIELDS['leadText']['max']) {
                    $violations[] = $key . '[' . $index . ']: лид длиннее предела формы';
                }

                if (count((array) ($section['items'] ?? [])) > PAGES_FIELDS['items']) {
                    $violations[] = $key . '[' . $index . ']: строк больше предела формы';
                }

                if (count((array) ($section['buttons'] ?? [])) > PAGES_FIELDS['buttons']) {
                    $violations[] = $key . '[' . $index . ']: кнопок больше предела формы';
                }
            }
        }

        self::assertSame([], $violations, 'Паттерн не укладывается в пределы конструктора');
    }

    public function testКатегорииИНазванияОбъявлены(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $pattern) {
            if (!array_key_exists((string) $pattern['category'], PATTERN_LIBRARY_CATEGORIES)) {
                $violations[] = $key . ': категория «' . (string) $pattern['category'] . '» не объявлена';
            }

            if (trim((string) $pattern['name']) === '' || trim((string) $pattern['description']) === '') {
                $violations[] = $key . ': пустое название или описание';
            }
        }

        self::assertSame([], $violations, 'Паттерн с неизвестной категорией или без подписи');
    }

    public function testКаталогНеПустой(): void
    {
        self::assertGreaterThan(5, count($this->catalog()), 'Библиотека должна содержать готовые паттерны');
    }

    /** @return array<string, array{name: string, category: string, description: string, sections: list<array<string, mixed>>}> */
    private function catalog(): array
    {
        $root = dirname(__DIR__, 2);

        require_once $root . '/core/bootstrap.php';

        return patternLibraryCatalog();
    }
}
