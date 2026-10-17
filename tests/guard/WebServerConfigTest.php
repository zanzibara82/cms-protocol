<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Барьеры уровня веб-сервера живут в `public/.htaccess` и не проверяются ни одним HTTP-тестом:
// общий помощник поднятия сайта — `php -S`, который этот файл не читает вовсе. Значит единственная сила
// у этих правил — текстовая: страж сверяет, что директивы на месте и что они переживут хостинг
// без нужного модуля, а не обвалят весь сайт неизвестной директивой.
final class WebServerConfigTest extends TestCase
{
    private const ROOT_CONFIG = 'public/.htaccess';

    private const UPLOADS_CONFIG = 'public/uploads/.htaccess';

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

    // Каталог загрузок — единственное место, куда ложится то, что принесли люди. Проверяется тем же
    // механизмом, что и корень: голый «Require» без обёртки роняет сайт 500-м, а отказ, который не
    // накрывает php-расширения, оставляет исполняемый файл по адресу картинки.
    public function testКаталогЗагрузокДержитСвоиБарьеры(): void
    {
        $path = dirname(__DIR__, 2) . '/' . self::UPLOADS_CONFIG;

        self::assertFileExists($path, 'У каталога загрузок нет .htaccess: PHP в нём исполняется');

        $config = (string) file_get_contents($path);
        self::assertSame(
            [],
            $this->uploadsViolations($config, self::UPLOADS_CONFIG),
            'Барьеры каталога загрузок обязаны быть на месте и в форме, живой на хостинге'
        );
    }

    // Пустой конфиг обязан выдать все пять находок: если проверка на пустом файле молчит,
    // она молчит и на испорченном, а зелёный результат ничего не значит.
    public function testПустойКонфигНеПроходитНиОднуПроверку(): void
    {
        self::assertSame(
            [
                self::ROOT_CONFIG . ': нет Options -Indexes — содержимое каталогов отдаётся списком',
                self::ROOT_CONFIG . ': нет DirectoryIndex index.php — запрос / отдаёт конфигурация хостинга, без директивы главная отвечает 403 или 404',
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
            ['probe: нет DirectoryIndex index.php — запрос / отдаёт конфигурация хостинга, без директивы главная отвечает 403 или 404'],
            $this->violations($this->without($correct, "    DirectoryIndex index.php\n", "    DirectoryIndex index.html\n"), 'probe'),
            'DirectoryIndex с чужим значением вместо index.php не найден'
        );

        self::assertSame(
            ['probe: DirectoryIndex стоит вне обёртки IfModule mod_dir.c — хостинг без модуля падает 500-м на неизвестной директиве'],
            $this->violations($this->without(
                $correct,
                "<IfModule mod_dir.c>\n    DirectoryIndex index.php\n</IfModule>\n",
                "    DirectoryIndex index.php\n"
            ), 'probe'),
            'DirectoryIndex вне обёртки mod_dir.c пропущен'
        );

        self::assertSame(
            ['probe: замок установщика не переведён в 404 — его текст может уйти наружу'],
            $this->violations($this->without($correct, '    RewriteRule ^install\.lock$ - [R=404,L,NC]' . "\n"), 'probe'),
            'Отсутствующее правило 404 для замка не найдено'
        );

        self::assertSame(
            ['probe: правило 404 для замка без [NC] — запрос /INSTALL.LOCK на файловой системе без регистра минует его'],
            $this->violations($this->without($correct, '[R=404,L,NC]', '[R=404,L]'), 'probe'),
            'Правило 404 без [NC] пропущено'
        );

        self::assertSame(
            ['probe: отказ по имени файла замка чувствителен к регистру — точное имя в блоке минует /INSTALL.LOCK'],
            $this->violations(
                $this->without($correct, '<FilesMatch "(?i)^install\.lock$">', '<Files "install.lock">')
                    . "\n" . '</Files>',
                'probe'
            ),
            'Блок <Files> с точным именем пропущен'
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
            ['probe: правила mod_rewrite стоят вне обёртки IfModule mod_rewrite.c — хостинг без модуля падает 500-м на неизвестной директиве'],
            $this->violations($this->without($correct, "<IfModule mod_rewrite.c>\n", ''), 'probe'),
            'Голые директивы mod_rewrite пропущены'
        );

        self::assertSame(
            ['probe: нет редиректа http на https — первый заход даёт сессию, в которую Secure-кука не ляжет'],
            $this->violations($this->without(
                $correct,
                "    RewriteCond %{HTTPS} !=on\n"
                    . "    RewriteCond %{HTTP_HOST} !^\$\n"
                    . '    RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1)(:[0-9]+)?$ [NC]' . "\n"
                    . "    RewriteCond %{HTTP_HOST} !^[0-9.]+(:[0-9]+)?\$\n"
                    . "    RewriteCond %{REQUEST_URI} !^/\\.well-known\n"
                    . '    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=302,L]' . "\n"
            ), 'probe'),
            'Отсутствующий HTTPS-редирект не найден'
        );

        self::assertSame(
            ['probe: редирект на https не исключает localhost и 127.0.0.1 — локальная приёмка глохнет'],
            $this->violations($this->without($correct, 'localhost|127\.0\.0\.1', 'example\.com'), 'probe'),
            'Редирект без исключения локального хоста пропущен'
        );

        self::assertSame(
            ['probe: редирект на https не исключает доступ по голому IP — браузер кэширует редирект, а сертификат на адрес не выпускают'],
            $this->violations($this->without($correct, "    RewriteCond %{HTTP_HOST} !^[0-9.]+(:[0-9]+)?\$\n"), 'probe'),
            'Редирект без исключения голого IP пропущен'
        );

        self::assertSame(
            ['probe: редирект на https не исключает пустой Host — запрос HTTP/1.0 получает битый Location'],
            $this->violations($this->without($correct, "    RewriteCond %{HTTP_HOST} !^\$\n"), 'probe'),
            'Редирект без условия непустого Host пропущен'
        );

        self::assertSame(
            ['probe: редирект на https не оставляет /.well-known на http — домен проверяют по http-01 до того, как https работает'],
            $this->violations($this->without($correct, "    RewriteCond %{REQUEST_URI} !^/\\.well-known\n"), 'probe'),
            'Редирект без исключения пути проверки домена пропущен'
        );

        self::assertSame(
            ['probe: редирект на https отдаёт 301 — постоянный кэш браузера не отозвать, а сертификат на хостинге появляется позже сайта'],
            $this->violations($this->without($correct, '[R=302,L]', '[R=301,L]'), 'probe'),
            'Постоянный редирект пропущен'
        );

        self::assertSame(
            ['probe: редирект на https стоит после front-controller — правило с флагом L заканчивает обработку, и http-запрос уходит в index.php без редиректа'],
            $this->violations($this->without(
                $correct,
                "    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=302,L]\n"
                    . "    RewriteCond %{REQUEST_FILENAME} !-f\n"
                    . "    RewriteCond %{REQUEST_FILENAME} !-d\n"
                    . "    RewriteRule ^ index.php [L]\n",
                "    RewriteCond %{REQUEST_FILENAME} !-f\n"
                    . "    RewriteCond %{REQUEST_FILENAME} !-d\n"
                    . "    RewriteRule ^ index.php [L]\n"
                    . "    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=302,L]\n"
            ), 'probe'),
            'Редирект после front-controller пропущен'
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

    // Значение имеет не первая строка, а весь список: Apache складывает несколько директив
    // DirectoryIndex одного контекста в общий список, а ключевое слово disabled сбрасывает уже
    // накопленное (https://httpd.apache.org/docs/2.4/mod/mod_dir.html#directoryindex). Поэтому
    // проверка обязана ходить по всем директивам — иначе «disabled» после нашей строки оставляет
    // страж зелёным, хотя запрос «/» уже ничего не отдаёт. Заодно и терпимость к форме: пробел
    // перед «>» и закрывающий тег без регистра конфигурация допустимы, а краснить на них живой
    // конфиг значит приучать человека не верить стражу.
    public function testНесколькоДирективИндексаСчитываютсяОднимСписком(): void
    {
        $correct = $this->correctSample();

        self::assertSame(
            ['probe: DirectoryIndex обнулила директива disabled — список mod_dir пуст, и запрос / отвечает 403 или 404, как без директивы'],
            $this->violations($this->without(
                $correct,
                "    DirectoryIndex index.php\n",
                "    DirectoryIndex index.php\n    DirectoryIndex disabled\n"
            ), 'probe'),
            'Сброшенный список индексов пропущен'
        );

        self::assertSame(
            [],
            $this->violations($this->without(
                $correct,
                "    DirectoryIndex index.php\n",
                "    DirectoryIndex index.html\n    DirectoryIndex index.php\n"
            ), 'probe'),
            'index.php назван второй директивой: mod_dir его найдёт, и краснить нельзя'
        );

        self::assertSame(
            [],
            $this->violations($this->without($correct, '<IfModule mod_dir.c>', '<IfModule mod_dir.c >'), 'probe'),
            'Открывающий тег с пробелом перед «>» — конфигурация живая, краснить нельзя'
        );

        self::assertSame(
            ['probe: DirectoryIndex стоит вне обёртки IfModule mod_dir.c — хостинг без модуля падает 500-м на неизвестной директиве'],
            $this->violations($this->without(
                $correct,
                "<IfModule mod_dir.c>\n    DirectoryIndex index.php\n</IfModule>\n",
                "<IfModule mod_dir.c>\n</ifmodule>\n    DirectoryIndex index.php\n"
            ), 'probe'),
            'Директива после закрывающего тега без регистра пропущена'
        );
    }

    // Apache принимает и LF, и CRLF, а `.gitattributes` гарантирует LF только на обычном пути:
    // редактор или оператор может положить CRLF-версию файла в дерево руками, и именно тогда
    // страж нужен больше всего. Проверка идёт по всему набору читателей, а не по одной директиве:
    // только так видно, что барьер целиком не зависит от переноса строк.
    public function testОбразецСПереносомСтрокиCrlfПроходитВсеПроверки(): void
    {
        $crlf = str_replace("\n", "\r\n", $this->correctSample());

        self::assertSame([], $this->violations($crlf, 'probe'), 'Корректный образец с CRLF дал находки');
        self::assertSame([], $this->uploadsViolations($crlf, 'probe'), 'Корректный образец с CRLF дал находки в проверке каталога загрузок');

        // Отрицательная ветка переноса: снятый барьер каталога загрузок обязан найтись и на LF, и на
        // CRLF. Два утверждения выше проверяют только пустой список, а он остаётся зелёным и тогда,
        // когда читатель слепнет на переносе. Барьер снимается блоком целиком, вместе со строкой
        // `FilesMatch`: якорь по слову отказа снял бы оба блока образца разом.
        $phpBarrier = '<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phar)$">' . "\n"
            . '    <IfModule mod_authz_core.c>' . "\n"
            . '        Require all denied' . "\n"
            . '    </IfModule>' . "\n"
            . '    <IfModule !mod_authz_core.c>' . "\n"
            . '        Order allow,deny' . "\n"
            . '        Deny from all' . "\n"
            . '    </IfModule>' . "\n"
            . '</FilesMatch>';
        $withoutPhpBarrier = $this->without($this->correctSample(), $phpBarrier);

        self::assertSame(
            ['probe: отказ не накрывает php-расширения — файл, переименованный в .php, остаётся исполняемым'],
            $this->uploadsViolations($withoutPhpBarrier, 'probe'),
            'Снятый барьер отказа по php-расширениям не найден на LF'
        );

        self::assertSame(
            ['probe: отказ не накрывает php-расширения — файл, переименованный в .php, остаётся исполняемым'],
            $this->uploadsViolations(str_replace("\n", "\r\n", $withoutPhpBarrier), 'probe'),
            'Снятый барьер отказа по php-расширениям не найден на CRLF'
        );
    }

    /**
     * Находки в тексте конфигурации: каждый барьер — отдельной проверкой.
     *
     * @return list<string>
     */
    private function violations(string $config, string $relative): array
    {
        // Читатели заякорены на конце строки, а PCRE без модификатора `D` не ставит `$` перед
        // CRLF: нормализуем перенос один раз на входе, и все проверки этой функции видят LF.
        // `offset()` сравнивает только относительный порядок двух правил в одном и том же тексте,
        // номера строк и позиции в сообщениях не выдаются; `withoutForwardedHeaders()` ищет
        // подстроки ключей, которые через перенос не пишутся. Смысл обоих после нормализации не
        // меняется. Та же строка нормализации стоит на входе `uploadsViolations()`.
        $config = str_replace("\r\n", "\n", $config);

        $found = [];

        if (preg_match('/^\s*Options\b[^\n]*-Indexes/m', $config) !== 1) {
            $found[] = $relative . ': нет Options -Indexes — содержимое каталогов отдаётся списком';
        }

        // Список индексов читается всеми директивами сразу: mod_dir складывает несколько
        // DirectoryIndex одного контекста в общий список, а disabled сбрасывает накопленное.
        $indexes = $this->directoryIndexLists($config);
        $named = in_array('index.php', $indexes['named'], true);
        $survives = in_array('index.php', $indexes['effective'], true);

        if (! $named) {
            $found[] = $relative . ': нет DirectoryIndex index.php — запрос / отдаёт конфигурация хостинга,'
                . ' без директивы главная отвечает 403 или 404';
        }

        if ($named && ! $survives) {
            $found[] = $relative . ': DirectoryIndex обнулила директива disabled — список mod_dir пуст,'
                . ' и запрос / отвечает 403 или 404, как без директивы';
        }

        if ($this->hasDirectoryIndexOutsideDirWrapper($config)) {
            $found[] = $relative . ': DirectoryIndex стоит вне обёртки IfModule mod_dir.c'
                . ' — хостинг без модуля падает 500-м на неизвестной директиве';
        }

        if ($this->hasRewriteOutsideWrapper($config)) {
            $found[] = $relative . ': правила mod_rewrite стоят вне обёртки IfModule mod_rewrite.c'
                . ' — хостинг без модуля падает 500-м на неизвестной директиве';
        }

        $lockRule = $this->line($config, '/^\s*RewriteRule\s+\S*install\S*\s+-\s+\[[^\]]*R=404[^\]]*\]/mi');

        if ($lockRule === null) {
            $found[] = $relative . ': замок установщика не переведён в 404 — его текст может уйти наружу';
        } elseif ($this->find($lockRule, '/\bNC\b/i') === null) {
            $found[] = $relative . ': правило 404 для замка без [NC] — запрос /INSTALL.LOCK'
                . ' на файловой системе без регистра минует его';
        }

        $denyBlock = $this->line($config, '/<(?:Files|FilesMatch)\s+"?[^>\n]*install[^>\n]*>/i');

        if ($denyBlock !== null && $this->find($denyBlock, '/\(\?i\)/') === null) {
            $found[] = $relative . ': отказ по имени файла замка чувствителен к регистру —'
                . ' точное имя в блоке минует /INSTALL.LOCK';
        }

        if (! $this->hasDeniedWithFallback($config)) {
            $found[] = $relative . ': нет отказа с обёрткой mod_authz_core и запасной веткой — неизвестная директива роняет сайт 500-м';
        }

        $redirectRule = $this->line($config, '/^\s*RewriteRule\s+\^\s+https:\/\/[^\n]*/mi');
        $hasRedirect = $redirectRule !== null && $this->find($config, '/^\s*RewriteCond\s+%\{HTTPS\}/mi') !== null;

        if ($hasRedirect === false) {
            $found[] = $relative . ': нет редиректа http на https — первый заход даёт сессию, в которую Secure-кука не ляжет';

            return $this->withoutForwardedHeaders($found, $config, $relative);
        }

        foreach ($this->missingRedirectExclusions($config) as $reason) {
            $found[] = $relative . ': ' . $reason;
        }

        if ($this->find($redirectRule, '/R=301\b/i') !== null) {
            $found[] = $relative . ': редирект на https отдаёт 301 — постоянный кэш браузера не отозвать,'
                . ' а сертификат на хостинге появляется позже сайта';
        }

        $redirectAt = $this->offset($config, '/^\s*RewriteRule\s+\^\s+https:\/\/[^\n]*/mi');
        $frontAt = $this->offset($config, '/^\s*RewriteRule\s+\^\s+index\.php[^\n]*/mi');

        if ($redirectAt !== null && $frontAt !== null && $frontAt < $redirectAt) {
            $found[] = $relative . ': редирект на https стоит после front-controller — правило с флагом L'
                . ' заканчивает обработку, и http-запрос уходит в index.php без редиректа';
        }

        return $this->withoutForwardedHeaders($found, $config, $relative);
    }

    /** @return list<string> */
    private function uploadsViolations(string $config, string $relative): array
    {
        // Три нынешних читателя переноса не боятся: `[^\n]*` захватывает `\r`, а заякоренных на конце
        // строки здесь нет. Следующий барьер этой функции, заякоренный на `$`, стал бы слепнуть на
        // CRLF, а тест этого бы не заметил — нормализация на входе снимает весь класс таких ошибок.
        $config = str_replace("\r\n", "\n", $config);

        $found = [];

        if (preg_match('/^\s*Options\b[^\n]*-Indexes/m', $config) !== 1) {
            $found[] = $relative . ': нет Options -Indexes — содержимое каталога отдаётся списком';
        }

        if (! $this->hasDeniedWithFallback($config)) {
            $found[] = $relative . ': нет отказа с обёрткой mod_authz_core и запасной веткой — неизвестная директива роняет сайт 500-м';
        }

        if (preg_match('/<FilesMatch[^>]*php/i', $config) !== 1) {
            $found[] = $relative . ': отказ не накрывает php-расширения — файл, переименованный в .php, остаётся исполняемым';
        }

        return $found;
    }

    // Редирект обязан пропускать три рода адресов, иначе он ломает то, без чего сайт не живёт:
    // локальную приёмку без сертификата, доступ по голому IP (сертификата на адрес нет вовсе,
    // а 301 браузер кэширует навсегда) и путь проверки домена — /.well-known, куда сертификат
    // выпускается по http до того, как https начинает работать. Пустой Host — это запрос
    // HTTP/1.0 без заголовка Host: редирект на «https://%{HTTP_HOST}…» даёт битый Location.
    /** @return list<string> */
    private function missingRedirectExclusions(string $config): array
    {
        $missing = [];

        if ($this->find($config, '/^\s*RewriteCond\s+%\{HTTP_HOST\}\s+!.*localhost.*127/mi') === null) {
            $missing[] = 'редирект на https не исключает localhost и 127.0.0.1 — локальная приёмка глохнет';
        }

        if ($this->find($config, '/^\s*RewriteCond\s+%\{HTTP_HOST\}\s+!\^\[0-9/mi') === null) {
            $missing[] = 'редирект на https не исключает доступ по голому IP —'
                . ' браузер кэширует редирект, а сертификат на адрес не выпускают';
        }

        if ($this->find($config, '/^\s*RewriteCond\s+%\{HTTP_HOST\}\s+!\^\$[ \t]*$/mi') === null) {
            $missing[] = 'редирект на https не исключает пустой Host — запрос HTTP/1.0 получает битый Location';
        }

        if ($this->find($config, '/^\s*RewriteCond\s+%\{REQUEST_URI\}\s+![^\n]*well-known/mi') === null) {
            $missing[] = 'редирект на https не оставляет /.well-known на http —'
                . ' домен проверяют по http-01 до того, как https работает';
        }

        return $missing;
    }

    /**
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function withoutForwardedHeaders(array $found, string $config, string $relative): array
    {
        $normalized = str_replace('-', '_', strtolower($config));

        foreach (['x_forwarded_proto', 'x_forwarded_for', 'x_real_ip', 'client_ip'] as $key) {
            if (str_contains($normalized, $key)) {
                $found[] = $relative . ': конфигурация опирается на подставляемый заголовок ' . $key
                    . ' — решение о протоколе берётся извне';
            }
        }

        return $found;
    }

    // Директивы mod_rewrite вне обёртки — это 500 на весь сайт там, где модуль не включён:
    // Apache считает неизвестную директиву ошибкой конфигурации, а не пропускает блок. Обёртка
    // ищется по тексту, а не по отступам: перенос строки внутрь блока ничего не меняет.
    private function hasRewriteOutsideWrapper(string $config): bool
    {
        $inside = false;

        // Строки режутся по переводу строки, а не по `\R`: без модификатора `u` PCRE считает
        // переводом и байт 0x85, а он входит в кириллическую «х». Директива, стоящая в строке после
        // русского текста, оказалась бы во втором фрагменте и не была бы найдена.
        foreach (explode("\n", $config) as $line) {
            if ($this->find($line, '/<IfModule\s+mod_rewrite\.c>/i') !== null) {
                $inside = true;

                continue;
            }

            if ($this->find($line, '/<\/IfModule>/i') !== null) {
                $inside = false;

                continue;
            }

            if ($this->find($line, '/^\s*Rewrite(?:Engine|Cond|Rule)\b/') !== null && ! $inside) {
                return true;
            }
        }

        return false;
    }

    /**
     * Имена из всех директив DirectoryIndex: `named` — что названо хоть раз, `effective` — что
     * дошло до конца списка так, как его собирает mod_dir.
     *
     * Смысл нужен, а не форма одной строки: несколько директив одного контекста складываются в
     * общий список, а ключевое слово `disabled` сбрасывает уже накопленное
     * (https://httpd.apache.org/docs/2.4/mod/mod_dir.html#directoryindex). Значит директива
     * `index.html` до нашей — не поломка (индекс по-прежнему находится), а `disabled` после неё —
     * поломка, хотя наша строка на месте и первая.
     *
     * @return array{effective: list<string>, named: list<string>}
     */
    private function directoryIndexLists(string $config): array
    {
        $effective = [];
        $named = [];

        if (preg_match_all('/^[ \t]*DirectoryIndex[ \t]+([^\r\n]*)$/mi', $config, $matches) < 1) {
            return ['effective' => $effective, 'named' => $named];
        }

        $bodies = $matches[1] ?? [];

        if (! is_array($bodies)) {
            return ['effective' => $effective, 'named' => $named];
        }

        foreach ($bodies as $body) {
            if (! is_string($body)) {
                continue;
            }

            $words = preg_split('/[ \t]+/', trim($body, " \t\r\n"), -1, PREG_SPLIT_NO_EMPTY);

            if (! is_array($words)) {
                continue;
            }

            foreach ($words as $word) {
                if (strcasecmp($word, 'disabled') === 0) {
                    $effective = [];

                    continue;
                }

                $effective[] = $word;
                $named[] = $word;
            }
        }

        return ['effective' => $effective, 'named' => $named];
    }

    // DirectoryIndex обязан стоять внутри обёртки mod_dir.c по той же причине, что и правила
    // mod_rewrite: на хостинге без модуля неизвестная директива обваливает весь сайт 500-м ещё на
    // разборе конфигурации. Обёртка ищется по тексту, а не по отступам, и ведёт себя как
    // hasRewriteOutsideWrapper: флаг «внутри» ставится только на открытии mod_dir.c и снимается
    // любым закрывающим </IfModule>. Регистр тега и пробел перед «>» Apache допускает, поэтому
    // образец читается без чувства регистра: иначе страж краснит живой конфиг и его начинают
    // игнорировать.
    private function hasDirectoryIndexOutsideDirWrapper(string $config): bool
    {
        $inside = false;

        foreach (explode("\n", $config) as $line) {
            if ($this->find($line, '/<IfModule\s+mod_dir\.c\s*>/i') !== null) {
                $inside = true;

                continue;
            }

            if ($this->find($line, '/<\/IfModule>/i') !== null) {
                $inside = false;

                continue;
            }

            if ($this->find($line, '/^\s*DirectoryIndex\b/i') !== null && ! $inside) {
                return true;
            }
        }

        return false;
    }

    /**
     * Первая строка, подходящая под образец, или null, если такой нет.
     */
    private function line(string $config, string $pattern): ?string
    {
        return $this->find($config, $pattern);
    }

    /** @return ?string */
    private function find(string $subject, string $pattern): ?string
    {
        if (preg_match($pattern, $subject, $matches) !== 1) {
            return null;
        }

        $matched = $matches[0] ?? null;

        return is_string($matched) ? $matched : null;
    }

    // Смещение первого совпадения в тексте: нужно, чтобы сравнить порядок двух правил.
    private function offset(string $config, string $pattern): ?int
    {
        if (preg_match($pattern, $config, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $matched = $matches[0] ?? null;

        if (! is_array($matched) || ! isset($matched[1]) || ! is_int($matched[1])) {
            return null;
        }

        return $matched[1];
    }

    // Отказ обязан быть и обёрнут, и подстрахован: «Require all denied» без обёртки — это 500
    // на весь сайт там, где нет mod_authz_core, а обёртка без запасной ветки — открытый файл
    // там, где apache 2.2 не знает ни той, ни другой директивы.
    private function hasDeniedWithFallback(string $config): bool
    {
        $insideModern = false;
        $bareRequires = 0;

        // Разбор строк тот же, что в hasRewriteOutsideWrapper: `\R` без `u` режет кириллицу.
        foreach (explode("\n", $config) as $line) {
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

    // Закрывающий тег в нижнем регистре: Apache допускает любой регистр тегов IfModule, и страж
    // обязан видеть обёртку независимо от него. Синтетический вход из описания кандидата #301:
    // обёртка открывается и сразу закрывается в нижнем регистре, а правило стоит после неё.
    public function testЗакрывающийТегВНижнемРегистреСнимаетОбёрткуRewrite(): void
    {
        $config = "<IfModule mod_rewrite.c>\n</ifmodule>\nRewriteEngine On\n";

        self::assertTrue(
            $this->hasRewriteOutsideWrapper($config),
            'Закрывающий тег </ifmodule> не снял обёртку — RewriteEngine считается защищённым'
        );
    }

    // Корректный образец: тот же набор барьеров, что предписан корневому файлу, но в изолированном
    // тексте. Нужен, чтобы каждая мутация красила ровно одну проверку, а не ноль из-за того,
    // что образец сам по себе неверен. В конце стоит блок отказа для php-расширений из каталога
    // загрузок: образец обязан проходить весь набор проверок стража, а не только корневую его часть.
    private function correctSample(): string
    {
        return <<<'HTACCESS'
Options -Indexes
<IfModule mod_dir.c>
    DirectoryIndex index.php
</IfModule>
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^install\.lock$ - [R=404,L,NC]
    RewriteCond %{HTTPS} !=on
    RewriteCond %{HTTP_HOST} !^$
    RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1)(:[0-9]+)?$ [NC]
    RewriteCond %{HTTP_HOST} !^[0-9.]+(:[0-9]+)?$
    RewriteCond %{REQUEST_URI} !^/\.well-known
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=302,L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
<FilesMatch "(?i)^install\.lock$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phar)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
HTACCESS;
    }
}
