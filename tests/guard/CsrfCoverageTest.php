<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Каждый POST-обработчик обязан начинать с проверки токена. Страж читает таблицу маршрутов и
// ищет вызов csrfCheck() в теле функции-обработчика.
final class CsrfCoverageTest extends TestCase
{
    public function testВсеPostОбработчикиПроверяютТокен(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = $this->handlerSources($root);
        $violations = [];

        foreach ($this->postHandlers($root) as $handler) {
            $body = $sources[$handler] ?? null;
            if ($body === null) {
                $violations[] = $handler . ': обработчик не найден в исходниках';
                continue;
            }
            if (!str_contains($body, 'csrfCheck()')) {
                $violations[] = $handler . ': POST без csrfCheck()';
            }
        }

        self::assertSame([], $violations, 'Найдены POST-обработчики без проверки CSRF');
    }

    public function testКаждаяФормаНесётСкрытоеПолеТокена(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->templates($root) as $file) {
            $code = (string) file_get_contents($file);
            $forms = preg_match_all('/<form\b[^>]*method="post"[^>]*>/i', $code, $matches);
            if ($forms === 0) {
                continue;
            }
            // Форма может быть одна, а поле — общим partial(); считаем вхождения csrfField().
            $fields = preg_match_all('/csrfField\(\)/', $code);
            if ($fields < $forms) {
                $violations[] = $this->relative($root, $file) . ': форм ' . $forms . ', полей токена ' . $fields;
            }
        }

        self::assertSame([], $violations, 'Форма method="post" обязана нести csrfField()');
    }

    /** @return list<string> */
    private function postHandlers(string $root): array
    {
        $out = [];
        $pattern = '/addRoute\(\s*\'POST\'\s*,\s*\'([^\']+)\'\s*,\s*\'([a-zA-Z0-9_\\\\:]+)\'/';

        foreach (['core/routes.php', 'apps/news/routes.php'] as $file) {
            if (!is_file($root . '/' . $file)) {
                continue;
            }
            preg_match_all($pattern, (string) file_get_contents($root . '/' . $file), $matches);
            foreach ($matches[2] as $handler) {
                $out[] = $handler;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, string> */
    private function handlerSources(string $root): array
    {
        $out = [];
        foreach (['core', 'apps', 'admin'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if (!$item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($item->getPathname());
                preg_match_all('/function\s+([a-zA-Z0-9_\\\\]+)\s*\(/', $code, $matches);
                foreach ($matches[1] as $name) {
                    $out[$name] = $this->functionBody($code, $name);
                }
            }
        }

        return $out;
    }

    private function functionBody(string $code, string $name): string
    {
        $start = strpos($code, 'function ' . $name . '(');
        if ($start === false) {
            return '';
        }
        $open = strpos($code, '{', $start);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($code);
        for ($i = $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        return substr($code, $open);
    }

    /** @return list<string> */
    private function templates(string $root): array
    {
        $out = [];
        foreach (['theme', 'admin', 'apps'] as $dir) {
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

    private function relative(string $root, string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}
