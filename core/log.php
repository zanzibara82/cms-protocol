<?php

declare(strict_types=1);

// Журнал лежит вне документ-корня: core/storage/errors.log. Наржу не отдаётся ничего.
function logPath(): string
{
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

    $dir = dirname(logPath());
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents(logPath(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
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
