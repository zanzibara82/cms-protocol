<?php

declare(strict_types=1);

// Одноразовый зонд хостинга (§11.1, шаг 1). Отвечает один раз и удаляет себя: файл, оставшийся
// на сервере, — это готовый разведчик: «здесь PHP такой-то версии, порты наружу открыты, лимиты
// такие». Поэтому ключ, пустой в репозитории, и самоудаление.
//
// Ключ вписывают не в этот файл, а в его копию перед загрузкой (шаг 1 docs/deploy-checklist.md):
// рабочая копия проекта остаётся чистой, секрет в git не попадает, страж DeploySafetyTest следит,
// чтобы пустая строка здесь осталась пустой.
//
// Никакого дампа всех настроек интерпретатора: там пути, флаги сборки и переменные окружения,
// а проверяющему нужен набор конкретных значений. Зонд не читает config.php — на момент проверки
// его может не быть, а после его создания показывать что-либо из боевых настроек нельзя.
$probeKey = '';

// Второй способ задать ключ — переменная окружения хостинга: не всякая панель даёт править файл
// перед загрузкой, а секрет в окружении живёт там же, где и сам сайт. Значения ключа в git нет
// ни здесь, ни там: в репозитории строка `$probeKey` пуста, и скачанный из репозитория зонд
// отвечает 403 на любой запрос.
$probeKeyFromEnv = getenv('CMS_PROBE_KEY');
if (is_string($probeKeyFromEnv) && $probeKeyFromEnv !== '') {
    $probeKey = $probeKeyFromEnv;
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($probeKey === '' || !hash_equals($probeKey, (string) ($_GET['k'] ?? ''))) {
    http_response_code(403);
    echo "Ключ не задан или не совпал. Зонд отвечает только на запрос /probe.php?k=<ключ>.\n";

    return;
}

/** @var list<string> $lines */
$lines = [];

// Формат одна строка на проверку: итог читает человек в терминале, а не парсер, и [!] должен
// быть виден глазом без разбора JSON.
$line = static function (string $name, string $value, bool $ok, string $hint = '') use (&$lines): void {
    $lines[] = sprintf('%s %-26s %s%s', $ok ? '[+]' : '[!]', $name, $value, $hint === '' ? '' : ' — ' . $hint);
};

$toBytes = static function (string $raw): int {
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    $num = (int) $raw;
    $last = strtolower(substr($raw, -1));
    if ($last === 'g') {
        return $num * 1024 * 1024 * 1024;
    }
    if ($last === 'm') {
        return $num * 1024 * 1024;
    }
    if ($last === 'k') {
        return $num * 1024;
    }

    return $num;
};

// 1. Версия PHP. 8.3 на хостинге лучше, но минимально поддерживаемая — 8.1 (Global Constraints).
$line('php', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), 'нужно не ниже 8.1');

// 1a. Обновление безопасности PHP. 21 октября 2026 года вышло 8.3.36: CVE-2026-109618 и
// CVE-2026-109619 — повреждение состояния движка при аварийном выходе, заявлена возможность
// удалённого выполнения кода без аутентификации. Частичных мер нет: обновление движка —
// единственный способ закрыть дыру, поэтому старая версия на хостинге — открытая уязвимость.
// Список безопасных версий повторяет core/version.php: зонд на хостинг едет один, без ядра,
// и разойтись им нельзя — страж DeploySafetyTest сверяет оба списка.
$patched = ['8.1' => null, '8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
$branch = substr(PHP_VERSION, 0, 3);
$safe = $patched[$branch] ?? null;
if ($branch === '8.1') {
    $line('php-безопасность', PHP_VERSION . ' — поддержка ветки закончилась', false, 'исправлений для 8.1 не будет, нужен 8.2.35 или новее');
} elseif ($safe === null) {
    $line('php-безопасность', PHP_VERSION . ' — ветки нет в списке известных', true, 'данных о ней у нас нет');
} elseif (version_compare(PHP_VERSION, $safe, '>=')) {
    $line('php-безопасность', PHP_VERSION . ' — обновление безопасности установлено', true);
} else {
    $line('php-безопасность', PHP_VERSION . ' — обновление безопасности не установлено', false, 'нужно не ниже ' . $safe . ' (CVE-2026-109618, CVE-2026-109619)');
}

// 2. Расширения. Список — §4 спецификации; json входит в ядро с PHP 8.0, но проверяем: на
// хостинге его могли выключить при сборке.
foreach (['pdo_mysql', 'mbstring', 'gd', 'openssl', 'fileinfo', 'json'] as $ext) {
    $line('расширение ' . $ext, extension_loaded($ext) ? 'есть' : 'нет', extension_loaded($ext));
}

// 3. Argon2id. Отсутствие не ломает сайт: hashPassword() в core/auth.php выбирает алгоритм по
// поддержке (§13, отступление 3), но знать это надо до переноса пользователей.
$argon = defined('PASSWORD_ARGON2ID');
$line('argon2id', $argon ? 'есть' : 'нет', $argon, $argon ? '' : 'пароли будут bcrypt');

// 4. Лимиты загрузки. Обложка ≤ 5 МБ (apps/news/lib/constants.php), multipart добавляет к телу
// запроса ещё килобайты, поэтому 8 МБ — нижняя граница, а post_max_size обязан быть не меньше
// upload_max_filesize: иначе файл в 5 МБ при post 2 МБ отклоняется молча, до PHP.
$upload = $toBytes((string) ini_get('upload_max_filesize'));
$post = $toBytes((string) ini_get('post_max_size'));
$line('upload_max_filesize', (string) ini_get('upload_max_filesize'), $upload >= 8 * 1024 * 1024, 'нужно не меньше 8M');
$line('post_max_size', (string) ini_get('post_max_size'), $post >= $upload, 'должен быть не меньше upload_max_filesize');
$line('memory_limit', (string) ini_get('memory_limit'), true, '-1 — без лимита');
$line('max_execution_time', (string) ini_get('max_execution_time'), true);

// 5. Сокеты. Почта ходит через stream_socket_client (core/SmtpSocket.php), функция обязана быть
// живой даже при выключённом allow_url_fopen.
$line('stream_socket_client', function_exists('stream_socket_client') ? 'есть' : 'нет', function_exists('stream_socket_client'));

// 6. Каталог сессий. core/session.php пишет файлы сессий в save_path; на shared-хостинге его
// иногда переназначают на каталог без прав на запись, и вход молча не работает.
$savePath = (string) ini_get('session.save_path');
// Модификаторы вида «2;0700;/tmp» — первые две части числовые, путь в последней.
$parts = array_values(array_filter(explode(';', $savePath), static fn (string $p): bool => $p !== ''));
$path = count($parts) > 0 ? (string) end($parts) : sys_get_temp_dir();
$line('session.save_path', $path, is_dir($path) && is_writable($path), 'нет каталога или нет прав на запись');

// 7. Исходящий порт почтового сервера (§11.1). Проверяется только когда адрес передан явно:
// без него зонд не должен никуда стучаться — он чужой сервер, а не наш.
$smtpHost = (string) ($_GET['smtp'] ?? '');
$smtpPort = (int) ($_GET['port'] ?? 587);
if ($smtpHost !== '' && preg_match('/^[a-z0-9._-]+$/i', $smtpHost) === 1 && $smtpPort >= 1 && $smtpPort <= 65535) {
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 6);
    $line(
        'порт ' . $smtpPort . ' → ' . $smtpHost,
        $socket === false ? 'закрыт: ' . $errstr : 'открыт',
        $socket !== false,
        'закрыт — письма через 465 или почтовый ящик домена (§13)'
    );
    if ($socket !== false) {
        fclose($socket);
    }
} else {
    $line('порт 587 наружу', 'не проверено', true, 'передайте ?smtp=хост&port=587');
}

// 8. Cron. В пилоте не нужен (§3: рассылок и отложенных задач нет), но знать о нём надо до
// следующего раздела — заявки и курсы его потребуют. shell_exec часто выключен в disable_functions,
// и «нет функции» здесь честно отделяется от «нет crontab».
$disabled = strtolower((string) ini_get('disable_functions'));
if (!function_exists('shell_exec') || str_contains($disabled, 'shell_exec')) {
    $line('cron', 'не проверено', true, 'shell_exec выключен — узнаём в панели хостинга');
} else {
    $found = trim((string) @shell_exec('command -v crontab 2>/dev/null'));
    $line('cron', $found === '' ? 'нет' : $found, $found !== '', 'в пилоте не нужен');
}

// 9. Документ-корень. Отвечаем сами, потому что перенос корня — вопрос к панели хостинга, а этот
// файл отвечает на него без панели: если корень уже указывает на каталог с зондом, значит перенести
// можно и вариант public/ рабочий.
$docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$here = realpath(__DIR__);
if ($docRoot !== false && $here !== false && $docRoot === $here) {
    $line('документ-корень', $docRoot, true, 'уже указывает на public/ — вариант public/ рабочий');
} else {
    $line(
        'документ-корень',
        ($docRoot === false ? '?' : $docRoot) . ' vs ' . ($here === false ? '?' : $here),
        false,
        'корень не на public/ — просим перенести или кладём весь проект с корневым .htaccess (режим root)'
    );
}

// Самоудаление. На Linux файл уже прочитан интерпретатором, поэтому удаление во время исполнения
// безопасно; на Windows (локальная проверка) блокировка файла может мешать — строка ниже говорит,
// удалось ли, и человек доснимает файл руками.
$removed = @unlink(__FILE__);
$line('зонд удалил себя', $removed ? 'да' : 'нет', $removed, $removed ? '' : 'удалите файл вручную, он больше не нужен');

echo implode("\n", $lines) . "\n";
