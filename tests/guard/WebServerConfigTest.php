<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Барьеры уровня веб-сервера живут в `public/.htaccess` и не проверяются ни одним HTTP-тестом:
// харнес поднятия сайта — `php -S`, который этот файл не читает вовсе. Значит единственная сила
// у этих правил — текстовая: страж сверяет, что директивы на месте и что они переживут хостинг
// без нужного модуля, а не обвалят весь сайт неизвестной директивой.
final class WebServerConfigTest extends TestCase
{
    private const ROOT_CONFIG = 'public/.htaccess';

    public function testКорневойКонфигДержитВсеБарьеры(): void
    {
        $path = dirname(__DIR__, 2) . '/' . self::ROOT_CONFIG;

        self::assertFileExists($path, 'Корневого .htaccess нет: на хостинге не останется ни одного из барьеров');
        $config = (string) file_get_contents($path);
        self::assertNotSame('', trim($config), 'Корневой .htaccess пуст');

        self::assertSame(
            [],
            $this->violations($config, self::ROOT_CONFIG),
            'Каждый барьер корневой конфигурации обязан быть на месте и в форме, живой на хостинге'
        );
    }

    // Пустой конфиг обязан выдать все четыре находки: если проверка на пустом файле молчит,
    // она молчит и на испорченном, а зелёный результат ничего не значит.
    public function testПустойКонфигНеПроходитНиОднуПроверку(): void
    {
        self::assertSame(
            [
                self::ROOT_CONFIG . ': нет Options -Indexes — содержимое каталогов отдаётся списком',
                self::ROOT_CONFIG . ': замок установщика не переведён в 404 — его текст может уйти наружу',
                self::ROOT_CONFIG . ': нет отказа с обёрткой mod_authz_core и запасной веткой — неизвестная директива роняет сайт 500-м',
                self::ROOT_CONFIG . ': нет редиректа http на https — первый заход даёт сессию, в которую Secure-кука не ляжет',
            ],
            $this->violations('', self::ROOT_CONFIG),
            'Количество находок на пустом файле не равно числу проверяемых барьеров'
        );
    }

    // Проверка обязана видеть отсутствие каждого барьера по отдельности: мутации корректного
    // образца дают ровно свою находку, а не пустой список и не букет чужих.
    public function testКаждыйОтсутствующийБарьерНазванСвоейНаходкой(): void
    {
        $correct = $this->correctSample();
        self::assertSame([], $this->violations($correct, 'probe'), 'Проверка ложно сработала на корректный образец');

        self::assertSame(
            ['probe: нет Options -Indexes — содержимое каталогов отдаётся списком'],
            $this->violations($this->without($correct, "Options -Indexes\n"), 'probe'),
            'Отсутствующий Options -Indexes не найден'
        );

        self::assertSame(
            ['probe: замок установщика не переведён в 404 — его текст может уйти наружу'],
            $this->violations($this->without($correct, '    RewriteRule ^install\.lock$ - [R=404,L]' . "\n"), 'probe'),
            'Отсутствующее правило 404 для замка не найдено'
        );

        self::assertSame(
            ['probe: нет отказа с обёрткой mod_authz_core и запасной веткой — неизвестная директива роняет сайт 500-м'],
            $this->violations($this->without(
                $correct,
                "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
                    . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n",
                "    Require all denied\n"
            ), 'probe'),
            'Голый Require без обёртки и запасной ветки пропущен'
        );

        self::assertSame(
            ['probe: нет отказа с обёрткой mod_authz_core и запасной веткой — неизвестная директива роняет сайт 500-м'],
            $this->violations($this->without(
                $correct,
                "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
            ), 'probe'),
            'Обёртка без запасной ветки для хостинга 2.2 пропущена'
        );

        self::assertSame(
            ['probe: нет редиректа http на https — первый заход даёт сессию, в которую Secure-кука не ляжет'],
            $this->violations($this->without(
                $correct,
                "    RewriteCond %{HTTPS} !=on\n"
                    . '    RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1)(:[0-9]+)?$ [NC]' . "\n"
                    . '    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]' . "\n"
            ), 'probe'),
            'Отсутствующий HTTPS-редирект не найден'
        );

        self::assertSame(
            ['probe: редирект на https не исключает localhost и 127.0.0.1 — локальная приёмка глохнет'],
            $this->violations($this->without($correct, 'localhost|127\.0\.0\.1', 'example\.com'), 'probe'),
            'Редирект без исключения локального хоста пропущен'
        );

        self::assertSame(
            ['probe: конфигурация опирается на подставляемый заголовок x_forwarded_proto — решение о протоколе берётся извне'],
            $this->violations($this->without(
                $correct,
                "    RewriteCond %{HTTPS} !=on\n",
                "    RewriteCond %{HTTP:X-Forwarded-Proto} !https\n    RewriteCond %{HTTPS} !=on\n"
            ), 'probe'),
            'Условие по X-Forwarded-Proto пропущено'
        );
    }

    /**
     * Находки в тексте конфигурации: каждый барьер — отдельной проверкой.
     *
     * @return list<string>
     */
    private function violations(string $config, string $relative): array
    {
        $found = [];

        if (preg_match('/^\s*Options\b[^\n]*-Indexes/m', $config) !== 1) {
            $found[] = $relative . ': нет Options -Indexes — содержимое каталогов отдаётся списком';
        }

        if (preg_match('/^\s*RewriteRule\s+\S*install\S*\s+-\s+\[[^\]]*R=404/im', $config) !== 1) {
            $found[] = $relative . ': замок установщика не переведён в 404 — его текст может уйти наружу';
        }

        if (! $this->hasDeniedWithFallback($config)) {
            $found[] = $relative . ': нет отказа с обёрткой mod_authz_core и запасной веткой — неизвестная директива роняет сайт 500-м';
        }

        $hasRedirect = preg_match('/^\s*RewriteRule\s+\^\s+https:\/\//mi', $config) === 1
            && preg_match('/^\s*RewriteCond\s+%\{HTTPS\}/mi', $config) === 1;

        if ($hasRedirect === false) {
            $found[] = $relative . ': нет редиректа http на https — первый заход даёт сессию, в которую Secure-кука не ляжет';
        } elseif (preg_match('/^\s*RewriteCond\s+%\{HTTP_HOST\}\s+!.*localhost.*127/mi', $config) !== 1) {
            $found[] = $relative . ': редирект на https не исключает localhost и 127.0.0.1 — локальная приёмка глохнет';
        }

        $normalized = str_replace('-', '_', strtolower($config));
        foreach (['x_forwarded_proto', 'x_forwarded_for', 'x_real_ip', 'client_ip'] as $key) {
            if (str_contains($normalized, $key)) {
                $found[] = $relative . ': конфигурация опирается на подставляемый заголовок ' . $key
                    . ' — решение о протоколе берётся извне';
            }
        }

        return $found;
    }

    // Отказ обязан быть и обёрнут, и подстрахован: «Require all denied» без обёртки — это 500
    // на весь сайт там, где нет mod_authz_core, а обёртка без запасной ветки — открытый файл
    // там, где apache 2.2 не знает ни той, ни другой директивы.
    private function hasDeniedWithFallback(string $config): bool
    {
        $insideModern = false;
        $bareRequires = 0;

        foreach (preg_split('/\R/', $config) ?: [] as $line) {
            $line = (string) $line;

            if (preg_match('/<IfModule\s+mod_authz_core\.c>/', $line) === 1) {
                $insideModern = true;

                continue;
            }

            if (preg_match('/<\/IfModule>/', $line) === 1) {
                $insideModern = false;

                continue;
            }

            if (preg_match('/^\s*Require\b/', $line) === 1 && ! $insideModern) {
                ++$bareRequires;
            }
        }

        if ($bareRequires > 0) {
            return false;
        }

        return preg_match('/<IfModule\s+mod_authz_core\.c>.*Require\s+all\s+denied.*<\/IfModule>/s', $config) === 1
            && preg_match('/<IfModule\s+!mod_authz_core\.c>.*Deny\s+from\s+all.*<\/IfModule>/s', $config) === 1;
    }

    /**
     * Мутация образца: отсутствие фрагмента — это ошибка в самом тесте, а не зелёный прогон,
     * поэтому оно поднимается здесь, а не уезжает в молчаливое совпадение с нулём замен.
     */
    private function without(string $sample, string $needle, string $replacement = ''): string
    {
        if (! str_contains($sample, $needle)) {
            self::fail('Образец конфигурации не содержит фрагмента для мутации: ' . $needle);
        }

        return str_replace($needle, $replacement, $sample);
    }

    // Корректный образец: тот же набор барьеров, что предписан корневому файлу, но в изолированном
    // тексте. Нужен, чтобы каждая мутация красила ровно одну проверку, а не ноль из-за того,
    // что образец сам по себе неверен.
    private function correctSample(): string
    {
        return <<<'HTACCESS'
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^install\.lock$ - [R=404,L]
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1)(:[0-9]+)?$ [NC]
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
<Files "install.lock">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</Files>
HTACCESS;
    }
}
