<?php

declare(strict_types=1);

// RELEASE 2.2: планировщик редакции. Запускается по расписанию хостинга:
//   php bin/cron.php
// Порядок: базовая ревизия старых материалов → публикации по расписанию → напоминания о задачах
// → ежедневная уборка. Публичного HTTP-маршрута нет: снаружи планировщик не дёргается.
// Повторный запуск безопасен: публикации захватываются транзакцией, уборка идемпотентна.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Отказ планировщика: ' . $error->getMessage() . "\n");
    exit(1);
});

$options = getopt('', ['dry-run', 'baseline', 'schedules', 'tasks', 'cleanup', 'help']);

if (array_key_exists('help', $options)) {
    fwrite(STDOUT, "Использование: php bin/cron.php [--dry-run] [--baseline] [--schedules] [--tasks] [--cleanup]\n");
    exit(0);
}

$dryRun = array_key_exists('dry-run', $options);
$selected = array_intersect_key($options, array_flip(['baseline', 'schedules', 'tasks', 'cleanup']));
$runAll = $selected === [];
$run = static fn (string $name): bool => $runAll || array_key_exists($name, $selected);

// Один планировщик за раз: два параллельных запуска не должны дважды повторять упавшую
// публикацию. flock снимается сам при завершении процесса.
$lockPath = sys_get_temp_dir() . '/novacms-cron-' . substr(hash('sha256', __DIR__), 0, 12) . '.lock';
$lock = fopen($lockPath, 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Планировщик уже выполняется другим процессом.\n");
    exit(1);
}

$report = [
    'ok' => true,
    'dry_run' => $dryRun,
    'started_at' => gmdate('c'),
    'baseline' => null,
    'schedules' => null,
    'task_notifications' => null,
    'maintenance' => null,
];

try {
    if (!contentRevisionTablesReady()) {
        $report['ok'] = false;
        $report['error'] = 'Таблицы RELEASE 2.2 не найдены — примените sql/0004_release22.sql.';
    } else {
        if ($run('baseline')) {
            $report['baseline'] = $dryRun ? ['created' => 0, 'skipped' => 0, 'dry_run' => true] : contentRevisionBaselineAll();
        }

        if ($run('schedules')) {
            $report['schedules'] = scheduledPublicationRun(50, null, $dryRun);
        }

        if ($run('tasks')) {
            $report['task_notifications'] = $dryRun ? 0 : scheduledTaskDueNotifications();
        }

        if ($run('cleanup') && !$dryRun) {
            $report['maintenance'] = scheduledMaintenance();
        }
    }
} catch (Throwable $error) {
    $report['ok'] = false;
    $report['error'] = $error->getMessage();
    logError('cron.failed', ['message' => $error->getMessage()]);
}

flock($lock, LOCK_UN);
fclose($lock);

fwrite(STDOUT, (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

exit(($report['ok'] ?? false) === true ? 0 : 1);
