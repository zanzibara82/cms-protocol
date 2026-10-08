<?php

declare(strict_types=1);

// Журнал лежит вне документ-корня: core/storage/errors.log. Наружу не отдаётся ничего.
// Тест может переопределить путь переменной CMS_LOG_PATH, чтобы не трогать настоящий каталог ядра.
function logPath(): string
{
    $override = getenv('CMS_LOG_PATH');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return dirname(__DIR__) . '/core/storage/errors.log';
}

/** @param array<string, mixed> $context */
function logWrite(string $level, string $event, array $context = []): void
{
    $line = json_encode(
        [
            'at' => utcStamp(nowUtc()),
            'level' => $level,
            'event' => $event,
            'context' => $context,
        ],
        JSON_UNESCAPED_UNICODE
    );

    // Журнал — второстепенная операция: сбой записи не должен обрывать запрос. bootstrap.php
    // превращает любой E_WARNING в исключение, поэтому каталог проверяем до записи, а сами вызовы
    // гасим через «@» — не через глобальный error_reporting, чтобы не глушить чужие предупреждения.
    $path = logPath();
    $dir = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    if (!is_writable($dir) || (is_file($path) && !is_writable($path))) {
        return;
    }

    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** @param array<string, mixed> $context */
function logInfo(string $event, array $context = []): void
{
    logWrite('info', $event, $context);
}

/** @param array<string, mixed> $context */
function logWarn(string $event, array $context = []): void
{
    logWrite('warn', $event, $context);
}

/** @param array<string, mixed> $context */
function logError(string $event, array $context = []): void
{
    logWrite('error', $event, $context);
}
