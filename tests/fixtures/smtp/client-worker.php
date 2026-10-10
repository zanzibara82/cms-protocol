<?php

declare(strict_types=1);

// Дочерний процесс SMTP-разговора для tests/unit/SmtpSocketTest.php: настоящий клиент ядра
// блокируется на чтении ответа, поэтому однопоточный тест не может одновременно печатать ему
// ответы и ждать его следующую команду. Клиент живёт здесь, сервером и судьёй остаётся тест.
//
// Аргументы командной строки: хост, порт, пользователь, пароль, режим (open или deliver),
// путь к файлу письма. Ровно одна строка в стандартный вывод: «OK» либо «ERR <текст отказа>».

require dirname(__DIR__, 3) . '/core/bootstrap.php';

$host = (string) ($argv[1] ?? '');
$port = (int) ($argv[2] ?? 0);
$user = (string) ($argv[3] ?? '');
$pass = (string) ($argv[4] ?? '');
$mode = (string) ($argv[5] ?? 'open');
$messageFile = (string) ($argv[6] ?? '');

$socket = new \Cms\Core\SmtpSocket($host, $port, $user, $pass, 3.0);
$result = 'OK';

try {
    $socket->open();

    if ($mode === 'deliver') {
        if (!is_file($messageFile)) {
            throw new \RuntimeException('файл письма не найден по указанному пути.');
        }

        $socket->deliver((string) file_get_contents($messageFile));
    }
} catch (\Throwable $error) {
    $result = 'ERR ' . $error->getMessage();
}

$socket->close();

echo $result;
