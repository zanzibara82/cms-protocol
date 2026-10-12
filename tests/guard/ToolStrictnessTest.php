<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Страж строгости самих инструментов (пункт M-15 широкой рецензии ветки): ворота ровно так сильны,
// как их настройки. Стереть `level: 6` в `phpstan.neon`, выключить флаг в `phpunit.xml` или вырезать
// шаг из `scripts/gate.sh` сегодня нечем: прогон остаётся зелёным, а дерево под ним перестаёт
// проверяться. Поэтому этот страж читает три файла конфигурации как текст, ничего не запускает
// и не зависит от того, установлен ли PHP или помощники в окружении: отсутствие шага или флага
// видно по самому файлу. Каждый assert отвечает на вопрос «что начнёт проходить мимо ворот, если
// эту строку убрать».
final class ToolStrictnessTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    // Минимальный уровень `phpstan.neon`: ниже — это ослабление анализа типов, а не настройка.
    private const PHPSTAN_MIN_LEVEL = 6;

    // Флаги строгости `phpunit.xml`, списанные дословно из файла: каждый закрывает свой шум
    // прогона — предупреждение, notice, устаревание, рискованный тест или печать вместо проверки.
    private const PHPUNIT_STRICT_FLAGS = [
        'failOnWarning' => 'true',
        'failOnNotice' => 'true',
        'failOnDeprecation' => 'true',
        'failOnRisky' => 'true',
        'beStrictAboutOutputDuringTests' => 'true',
    ];

    // Наборы тестов, заявленные в `phpunit.xml`: список имеет право расти и не имеет права
    // сокращаться или терять имя — иначе проворот набора остаётся незамеченным.
    private const PHPUNIT_SUITES = ['unit', 'db', 'guard', 'http'];

    // Пять шагов ворот и их вызовы — по вызову инструмента, а не по тексту комментария:
    // комментарий переписать дешевле, чем снести шаг, и проверка по нему обнулилась бы молча.
    private const GATE_STEPS = [
        'свежесть стилей' => 'node_modules/.bin/tailwindcss',
        'синтаксис' => 'php -l',
        'статический анализ' => 'vendor/bin/phpstan',
        'стиль' => 'vendor/bin/php-cs-fixer',
        'тесты' => 'vendor/bin/phpunit',
    ];

    public function testPhpstanУровеньНеНижеШести(): void
    {
        $neon = $this->read('phpstan.neon');

        $matched = [];
        if (preg_match('/^\s*level:\s*(\d+)/m', $neon, $matched) !== 1) {
            self::fail('В `phpstan.neon` не найдена строка `level` — уровень анализа обязан быть задан явно, молчаливое значение по умолчанию недопустимо');
        }

        $level = (int) $matched[1];
        self::assertGreaterThanOrEqual(
            self::PHPSTAN_MIN_LEVEL,
            $level,
            'В `phpstan.neon` уровень анализа опущен до ' . $level . ': требуется не ниже ' . self::PHPSTAN_MIN_LEVEL
        );
    }

    public function testPhpstanБезСекцийПодавленияОшибок(): void
    {
        $neon = $this->read('phpstan.neon');

        $suppressions = [];
        foreach (['ignore_errors', 'ignore'] as $key) {
            if (preg_match('/^\s*' . $key . '\s*:/mi', $neon) === 1) {
                $suppressions[] = $key;
            }
        }

        // `excludePaths` здесь не считается: он исключает из анализа файлы, о которых файл
        // открыто объясняет решение, а `ignore_errors` подавляет ошибки в проверяемом коде —
        // это и есть поблажка, запрещённая правилом «ворота не ослаблять».
        self::assertSame(
            [],
            $suppressions,
            'В `phpstan.neon` найдена секция подавления ошибок (' . implode(', ', $suppressions) . '): поблажки в анализе запрещены'
        );
    }

    public function testPhpunitФлагиСтрогостиСохранены(): void
    {
        $xml = $this->read('phpunit.xml');

        $missing = [];
        foreach (self::PHPUNIT_STRICT_FLAGS as $flag => $value) {
            if (preg_match('/\b' . $flag . '="' . $value . '"/', $xml) !== 1) {
                $missing[] = $flag . '="' . $value . '"';
            }
        }

        self::assertSame(
            [],
            $missing,
            'В `phpunit.xml` пропали флаги строгости: ' . implode(', ', $missing) . ' — без них прогон зеленеет на тесте, который ничего не проверяет'
        );
    }

    public function testPhpunitНаборыНеСокращаются(): void
    {
        $xml = $this->read('phpunit.xml');

        $matched = [];
        if (preg_match_all('/<testsuite name="([^"]+)"/', $xml, $matched) === false) {
            self::fail('Разбор списка наборов в `phpunit.xml` не состоялся');
        }

        /** @var list<string> $names */
        $names = array_map('strval', $matched[1]);

        self::assertGreaterThanOrEqual(
            count(self::PHPUNIT_SUITES),
            count($names),
            'Наборов тестов стало меньше четырёх: в `phpunit.xml` найдено только ' . implode(', ', $names)
        );

        $lost = [];
        foreach (self::PHPUNIT_SUITES as $suite) {
            if (!in_array($suite, $names, true)) {
                $lost[] = $suite;
            }
        }

        self::assertSame(
            [],
            $lost,
            'Из `phpunit.xml` исчез набор (наборы): ' . implode(', ', $lost) . ' — прогон такого набора не идёт, и это не видно по зелёному результату'
        );
    }

    public function testВоротаСодержатВсеПятьШагов(): void
    {
        $gate = $this->read('scripts/gate.sh');

        // Комментарии вырезаются до поиска вызовов: шаг обязан стоять командой, а не упоминанием.
        $code = (string) preg_replace('/^\s*#.*$/m', '', $gate);

        $missing = [];
        foreach (self::GATE_STEPS as $name => $call) {
            if (!str_contains($code, $call)) {
                $missing[] = 'шаг «' . $name . '» (вызов `' . $call . '` не найден)';
            }
        }

        self::assertSame(
            [],
            $missing,
            'В `scripts/gate.sh` не найдены шаги ворот по их вызовам: ' . implode('; ', $missing)
        );
    }

    // Собственный список стража — тоже цель атаки: урезанный эталон green-прогона ничего не
    // доказывает, поэтому проверяется полнота самого эталона.
    public function testЭталонСтражаНеУрезан(): void
    {
        self::assertCount(5, self::PHPUNIT_STRICT_FLAGS, 'Список флагов строгости в самом страже урезан');
        self::assertCount(4, self::PHPUNIT_SUITES, 'Список наборов в самом страже урезан');
        self::assertCount(5, self::GATE_STEPS, 'Список шагов ворот в самом страже урезан');
        self::assertSame(6, self::PHPSTAN_MIN_LEVEL, 'Минимальный уровень в самом страже понижен');
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path, $relative . ': файл настройки инструментов обязан существовать');

        $code = file_get_contents($path);
        self::assertIsString($code);

        return $code;
    }
}
