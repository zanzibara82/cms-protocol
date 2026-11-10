<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

/**
 * Наполнитель демонстрационным контентом (`scripts/seed-demo-content.php`) — не часть продукта, но
 * его содержимое живёт на стенде и показывается людям. У него две ловушки, обе тихие:
 *
 * 1) Вид конструктора добавили, а витрину не обновили — страница «все виды блоков» молча перестаёт
 *    показывать новый вид, и человек решает, что его нет вовсе. Поэтому перечень видов сверяется с
 *    PAGES_KINDS и с вкладами приложений целиком, а не по памяти автора наполнителя.
 * 2) Путь к картинке или файлу вписан руками — опечатка видна только глазами на странице, а
 *    страница при этом отвечает 200. Поэтому каждый путь `/assets/…` обязан существовать в public/.
 *
 * Читается текст скрипта, а не его выполнение: наполнитель пишет в базу и по построению не может
 * исполняться внутри прогона.
 */
final class DemoContentTest extends TestCase
{
    private const SCRIPT = 'scripts/seed-demo-content.php';

    private function script(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::SCRIPT;
        self::assertFileExists($path, self::SCRIPT . ' обязан существовать: он наполняет стенд');

        $code = file_get_contents($path);
        self::assertIsString($code);

        return $code;
    }

    public function testВитринаСодержитКаждыйВидЯдра(): void
    {
        $code = $this->script();
        $missing = [];

        foreach (PAGES_KINDS as $kind) {
            // Вид ищется строкой значения поля: `'kind' => 'hero'`. Подпись вида в тексте пояснения
            // не засчитывается — она рядом с полем не стоит, и пропуск вида остался бы незамеченным.
            if (preg_match("/'kind' => '" . preg_quote($kind, '/') . "'/", $code) !== 1) {
                $missing[] = $kind;
            }
        }

        self::assertSame(
            [],
            $missing,
            'витрина обязана показывать каждый вид конструктора: пропущенные — '
            . implode(', ', $missing) . '. Добавили вид — добавьте блок в demoVitrinaSections()'
        );
    }

    public function testВитринаСодержитВкладыПриложений(): void
    {
        $code = $this->script();

        // Вклады берутся из подписок приложений, а не из перечня ядра: ядро про них не знает, но на
        // стенде они печатаются теми же секциями, и «все виды» без них — неправда.
        //
        // Читаются маршруты приложений, а не ответ фильтра: фильтры подписаны только в рабочем
        // процессе (`registerAppRoutes()`), а стражи живут без базы — вызов фильтра вернул бы
        // пустой список, и проверка зеленела бы на пустоте. Константа вида разворачивается в
        // значение: имя вида в проде задаёт она, и совпадать с наполнителем обязан он.
        $kinds = $this->appBlockKinds();

        self::assertNotEmpty($kinds, 'приложения обязаны подписывать свои вклады в конструктор: сверять нечего');

        $missing = [];
        foreach ($kinds as $kind) {
            if (preg_match("/'kind' => '" . preg_quote($kind, '/') . "'/", $code) !== 1) {
                $missing[] = $kind;
            }
        }

        self::assertSame([], $missing, 'витрина обязана показывать вклады приложений: пропущенные — ' . implode(', ', $missing));
    }

    /**
     * Имена видов, которые приложения подписывают в конструктор: `addFilter('pages_block_' . КОНСТАНТА)`
     * в их routes.php, значение константы разворачивается через constant().
     *
     * @return list<string>
     */
    private function appBlockKinds(): array
    {
        $kinds = [];

        foreach (glob(dirname(__DIR__, 2) . '/apps/*/routes.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all("/addFilter\('pages_block_' \. ([A-Z_]+)/", $code, $matches);

            foreach ($matches[1] as $constant) {
                // Константа не определена — это чужая сборка без приложения: такой вклад в текущем
                // прогоне не печатается вовсе, и требовать его от наполнителя нечего.
                if (!defined($constant)) {
                    continue;
                }

                $value = constant($constant);

                if (is_string($value) && $value !== '') {
                    $kinds[] = $value;
                }
            }
        }

        return array_values(array_unique($kinds));
    }

    public function testКлючиСекцийВитриныУникальны(): void
    {
        // Ключ секции — её якорь и её «имя» в базе. Два одинаковых ключа в одном наборе означают,
        // что вторая секция пропускается молча: повторный запуск узнаёт её по ключу как «уже есть».
        foreach (['demoHomeSections', 'demoVitrinaSections'] as $function) {
            $keys = $this->sectionKeys($this->script(), $function);

            self::assertNotEmpty($keys, $function . ' обязана объявлять секции');
            self::assertSame(
                array_values(array_unique($keys)),
                $keys,
                'в ' . $function . ' повторяются ключи секций: ' . implode(', ', $this->duplicates($keys))
            );
        }
    }

    public function testКаждыйПутьАссетаСуществует(): void
    {
        $code = $this->script();
        $public = dirname(__DIR__, 2) . '/public';

        preg_match_all("#'(/assets/[^'?]*)'#", $code, $matches);

        $paths = array_values(array_unique($matches[1]));
        self::assertNotEmpty($paths, 'наполнитель обязан ссылаться на картинки и файлы сайта');

        $missing = [];
        foreach ($paths as $relative) {
            if (!is_file($public . $relative)) {
                $missing[] = $relative;
            }
        }

        self::assertSame(
            [],
            $missing,
            'пути ассетов в наполнителе обязаны существовать в public/: иначе блок печатается пустым '
            . 'или с битой картинкой, а страница при этом отвечает 200 — ' . implode(', ', $missing)
        );
    }

    public function testНаполнительПоУмолчаниюНичегоНеПишет(): void
    {
        $code = $this->script();

        // Запись включается только флагом --write: запуск по невнимательности не должен переписывать
        // страницы стенда. Проверяется чтение флага и ветка пропуска в demoSeed().
        self::assertStringContainsString("getopt('', ['write', 'reset', 'target:'])", $code, 'флаг --write обязан читаться');
        self::assertMatchesRegularExpression(
            '/if \(!\$write\) \{/',
            $code,
            'ветка «только показать» обязана существовать: наполнитель пишет в базу лишь по --write'
        );

        // Существующие секции не перезаписываются: повторный прогон узнаёт свои ключи и пропускает.
        self::assertStringContainsString('isset($existingKeys[$section[\'key\']])', $code, 'повторный прогон обязан пропускать уже созданные секции');
    }

    /**
     * Ключи секций одной функции: строки вида `demoSection('kljuch', [` до закрывающей скобки набора.
     *
     * @return list<string>
     */
    private function sectionKeys(string $code, string $function): array
    {
        $start = strpos($code, 'function ' . $function . '(): array');

        if ($start === false) {
            return [];
        }

        // Конец набора — следующий `function ` верхнего уровня: наборы идут подряд, и границу иначе
        // не найти (внутри строк бывают любые символы).
        $end = strpos($code, "\nfunction ", $start + 1);
        $body = substr($code, $start, $end === false ? null : $end - $start);

        preg_match_all("/demoSection\('([a-z0-9-]+)'/", $body, $matches);

        return $matches[1];
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function duplicates(array $keys): array
    {
        $seen = [];
        $twice = [];

        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                $twice[$key] = true;
            }

            $seen[$key] = true;
        }

        return array_keys($twice);
    }
}
