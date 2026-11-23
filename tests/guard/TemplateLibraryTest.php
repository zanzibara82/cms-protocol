<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Готовые шаблоны библиотеки пригодны для конструктора.
//
// Причина требования: шаблон — это набор блоков, который ставится на страницу одним нажатием.
// Если вид блока не из перечня ядра, блок не напечатается, и человек получит дыру посреди
// готовой страницы при ответе 200. Если шаблон превышает предел секций, применение упадёт у
// человека, а не здесь. Если два блока делят якорь, ядро откажет на втором и весь шаблон
// откатится — но узнать об этом лучше на прогоне.
//
// Отдельно проверяется, что в шаблоне нет пустых блоков: блок с заголовком и без содержимого
// печатает пустую рамку, и шаблон выглядит сломанным. Это ловушка тише предыдущих — сервер
// отвечает 200, страница открывается.

final class TemplateLibraryTest extends TestCase
{
    public function testВидыБлоковШаблоновОбъявленыВЯдре(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $template) {
            foreach ($template['sections'] as $index => $section) {
                $kind = (string) ($section['kind'] ?? '');

                if (!in_array($kind, PAGES_KINDS, true)) {
                    $violations[] = $key . '[' . $index . ']: вид «' . $kind . '» не из PAGES_KINDS';
                }
            }
        }

        self::assertSame([], $violations, 'Шаблон использует вид блока, которого ядро не рисует');
    }

    public function testЯкоряВнутриШаблонаНеПовторяются(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $template) {
            $seen = [];

            foreach ($template['sections'] as $section) {
                $anchor = (string) ($section['anchor'] ?? '');

                if ($anchor === '') {
                    continue;
                }

                if (isset($seen[$anchor])) {
                    $violations[] = $key . ': якорь «' . $anchor . '» занят дважды';
                }

                $seen[$anchor] = true;
            }
        }

        self::assertSame([], $violations, 'Два блока шаблона делят якорь: второй не сохранится');
    }

    public function testШаблоныУкладываютсяВПределыКонструктора(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $template) {
            if (count($template['sections']) > PAGES_SECTIONS_LIMIT) {
                $violations[] = $key . ': блоков ' . count($template['sections'])
                    . ' при пределе ' . PAGES_SECTIONS_LIMIT;
            }

            foreach ($template['sections'] as $index => $section) {
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

        self::assertSame([], $violations, 'Шаблон не укладывается в пределы конструктора');
    }

    public function testКатегорииИМакетыОбъявлены(): void
    {
        $violations = [];

        foreach ($this->catalog() as $key => $template) {
            $category = (string) $template['category'];

            if (!array_key_exists($category, TEMPLATE_LIBRARY_CATEGORIES)) {
                $violations[] = $key . ': категория «' . $category . '» не объявлена';
            }

            // Макет шаблона проверяется тем же правилом, что и макет страницы: чужая строка
            // означала бы «применить шаблон и потерять обвязку».
            $layout = pageLayoutPlan((string) $template['layout'])['layout'];

            if ($layout !== (string) $template['layout']) {
                $violations[] = $key . ': макет «' . (string) $template['layout'] . '» неизвестен';
            }

            if (trim((string) $template['name']) === '' || trim((string) $template['description']) === '') {
                $violations[] = $key . ': пустое название или описание';
            }
        }

        self::assertSame([], $violations, 'Шаблон с неизвестной категорией или макетом');
    }

    // Самопроверка: без неё зелёный прогон мог бы означать «каталог не читается».
    public function testКаталогНеПустой(): void
    {
        self::assertGreaterThan(10, count($this->catalog()), 'Библиотека должна содержать готовые шаблоны');
    }

    /** @return array<string, array{name: string, category: string, description: string, layout: string, sections: list<array<string, mixed>>}> */
    private function catalog(): array
    {
        $root = dirname(__DIR__, 2);

        require_once $root . '/core/bootstrap.php';

        return templateLibraryCatalog();
    }
}
