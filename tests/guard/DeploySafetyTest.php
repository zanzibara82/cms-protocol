<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Выкладка — единственное место, где ошибка стоит не сломанной страницы, а потерянных данных:
// `rsync --delete` без исключения стирает с сервера config.php с паролями, замок установщика
// и обложки, которые загрузили редакторы. Страж читает три файла выкладки и проверяет ровно то,
// что нельзя потерять при следующем запуске скрипта. Это не проверка стиля: каждый assert ниже
// отвечает на вопрос «что произойдёт на боевом сервере, если эту строку убрать».
final class DeploySafetyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testСкриптВыкладкиСуществуетИЗапускается(): void
    {
        $sh = $this->read('scripts/deploy.sh');

        self::assertStringStartsWith('#!/usr/bin/env bash', $sh, 'deploy.sh обязан запускаться сам');
        // Адрес, логин и путь только из окружения: вписанный хостинг в скрипте — это секрет в git
        // и невозможность выложить второй сайт.
        foreach (['CMS_DEPLOY_USER', 'CMS_DEPLOY_HOST', 'CMS_DEPLOY_PATH'] as $var) {
            self::assertMatchesRegularExpression(
                '/:\s*"\$\{' . preg_quote($var, '/') . ':\?/',
                $sh,
                $var . ' обязан читаться через `:?` — запуск без него должен упасть, а не выложить в пустоту'
            );
        }

        // `:?` ловит пустое значение и пропускает относительное, а его rsync считает от домашнего
        // каталога входа: --delete уйдёт чистить соседнее дерево. Проверка ведущего слэша обязана
        // стоять до вызова rsync.
        self::assertMatchesRegularExpression(
            '/case "\$CMS_DEPLOY_PATH" in\s*\n\s*\/\*\)/',
            $sh,
            'deploy.sh обязан отвергать относительный CMS_DEPLOY_PATH до вызова rsync'
        );
    }

    public function testТриКаталогаНаСервереНеУдаляются(): void
    {
        $sh = $this->read('scripts/deploy.sh');

        // --delete при этом обязателен: без него на сервере остаются удалённые в репозитории
        // PHP-файлы, и следующий прогон читает код прошлой версии.
        self::assertStringContainsString('--delete', $sh, 'выкладка обязана чистить устаревшие файлы');
        // …и ровно поэтому --delete-excluded быть не должно: он снимает защиту исключённых
        // файлов, и три строки ниже превращаются в приглашение стереть пароли и загрузки.
        self::assertStringNotContainsString('--delete-excluded', $sh, 'исключённые файлы защищены от удаления');

        // Исключение обязано быть заякорено на каталог выкладки: `--exclude uploads/` молча
        // совпадёт с любым каталогом одноимённого имени вглубь дерева.
        foreach (['/config.php', '/public/install.lock', '/public/uploads/', '/core/storage/'] as $path) {
            self::assertMatchesRegularExpression(
                '/--exclude=' . preg_quote("'$path'", '/') . '/',
                $sh,
                $path . ' обязан быть в --exclude: --delete стёр бы его с сервера'
            );
        }
    }

    public function testЗондХостингаЗакрытКлючомИУдаляетСебя(): void
    {
        $probe = $this->read('scripts/hosting-probe.php');

        // Пустая строка в репозитории — условие, а не недоработка: секретный ключ не живёт в git,
        // его вписывают в копию файла перед загрузкой. Не пустой — страж падает, и это же
        // означает, что скачанный из репозитория зонд сам по себе безопасен.
        self::assertMatchesRegularExpression('/^\$probeKey = \'\';$/m', $probe, 'в репозитории ключ обязан быть пуст');
        // Сравнение через hash_equals, а не `==`: `==` с магическими хешами пропускает запрос,
        // который никто не авторизовывал.
        self::assertStringContainsString('hash_equals(', $probe, 'ключ сравнивается без утечки по времени');
        self::assertStringContainsString('unlink(__FILE__)', $probe, 'зонд обязан удалиться после ответа');
        // Дамп всех настроек показывает пути, флаги сборки и окружение. Нужен конкретный набор
        // проверяемых значений, и он собирается вручную.
        self::assertStringNotContainsString('phpinfo(', $probe, 'зонд не отдаёт свалку настроек');
        // config.php зонд не читает: на момент проверки его может не быть, а после его создания
        // показывать что-либо из него наружу нельзя.
        self::assertStringNotContainsString("dirname(__DIR__) . '/config.php'", $probe, 'зонд не трогает боевые настройки');
    }

    public function testЗапаснаяРаскладкаЗакрываетСлужебноеДоПереписывания(): void
    {
        $htaccess = $this->read('.htaccess');

        foreach (['core', 'apps', 'sql', 'config'] as $name) {
            self::assertMatchesRegularExpression(
                '/RewriteRule[^\n]*' . $name . '[^\n]*\[F/',
                $htaccess,
                $name . ' обязан отдавать 403 в запасной раскладке'
            );
        }

        // Порядок правил — не косметика. Apache идёт по списку, и если переписывание в public/
        // стоит раньше блокировки, запрос /core/bootstrap.php совпадёт с существующим файлом,
        // условие !-f его пропустит, и исходники ядра отдадутся наружу.
        $deny = strpos($htaccess, '[F');
        $rewrite = strpos($htaccess, 'public/index.php');
        self::assertIsInt($deny);
        self::assertIsInt($rewrite);
        self::assertLessThan($rewrite, $deny, 'блокировка служебного обязана предшествовать переписыванию');

        // Тормоз повторного прохода: Apache после [L] проходит правила ещё раз с переписанным
        // путём, а %{REQUEST_URI} при этом не меняется, поэтому остановить второй проход может
        // только условие на ссылку правила. Без него /assets/app.css, уже переписанный в
        // public/assets/app.css, вторым проходом уехал бы в public/index.php — вместо стилей
        // отдалась бы разметка, и проверка адаптивности молча тестировала бы 404.
        self::assertStringContainsString('RewriteCond $1 !^public/', $htaccess);

        // RewriteBase закрепляет корень URL-пространства для per-directory переписывания:
        // Apache строит результат относительно каталога с .htaccess, и на хостинге, где дерево
        // выкладки не совпадает с корнем документ-корня, подстановка public/… склеилась бы с
        // путём к .htaccess вместо корня. Якорь на конец строки не даёт утверждению засчитать
        // упоминание директивы в комментарии вместо самой директивы.
        self::assertMatchesRegularExpression(
            '/^\s*RewriteBase\s+\/\s*$/m',
            $htaccess,
            'RewriteBase / обязателен: в per-directory контексте результат строится от каталога, '
            . 'и без этой строки подстановка public/… склеится с путём к .htaccess вместо корня'
        );
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path, $relative . ': файл выкладки обязан существовать');

        $code = file_get_contents($path);
        self::assertIsString($code);

        return $code;
    }
}
