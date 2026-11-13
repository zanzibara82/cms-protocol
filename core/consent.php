<?php

declare(strict_types=1);

// Журнал согласий. Запись — доказательство, поэтому она пишется отдельной строкой и не удаляется
// вместе с заявкой. Версия текста фиксируется в момент согласия: правка формулировки не должна
// задним числом менять то, на что человек согласился.

const CONSENT_TEXT_VERSION = '2026-11-12';
const CONSENT_PAGE_SIZE = 100;

function consentRecord(string $subject, string $kind = 'lead', string $source = '', string $version = CONSENT_TEXT_VERSION): void
{
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    try {
        insert('consent_log', [
            'subject' => mb_substr(trim($subject), 0, 190),
            'kind' => mb_substr($kind, 0, 64),
            'text_version' => mb_substr($version, 0, 64),
            'source' => mb_substr(trim($source), 0, 190),
            'ip' => mb_substr(clientIp(), 0, 45),
            'user_agent' => mb_substr($agent, 0, 255),
            'created_at' => utcStamp(nowUtc()),
        ]);
    } catch (Throwable $error) {
        // Согласие уже проверено формой; сбой журнала не отменяет принятую заявку.
        logWarn('consent.write_failed', ['kind' => $kind, 'message' => $error->getMessage()]);
    }
}

/**
 * @return array{rows: list<array<string, mixed>>, total: int, pages: int}
 */
function consentPage(int $page, string $query = ''): array
{
    $where = '';
    $args = [];
    $q = trim($query);

    if ($q !== '') {
        $like = '%' . escapeLike($q) . '%';
        $where = ' WHERE subject LIKE ? ESCAPE ? OR source LIKE ? ESCAPE ? OR kind LIKE ? ESCAPE ?';
        $args = [$like, '\\', $like, '\\', $like, '\\'];
    }

    $total = (int) value('SELECT COUNT(*) FROM ' . t('consent_log') . $where, $args);
    $pages = max(1, (int) ceil($total / CONSENT_PAGE_SIZE));
    $current = min(max(1, $page), $pages);

    $rows = all(
        'SELECT id, subject, kind, text_version, source, ip, user_agent, created_at'
        . ' FROM ' . t('consent_log') . $where
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . CONSENT_PAGE_SIZE . ' OFFSET ' . (($current - 1) * CONSENT_PAGE_SIZE),
        $args
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
}

/** @return list<array<string, mixed>> */
function consentExportRows(string $query = ''): array
{
    $q = trim($query);
    $where = '';
    $args = [];

    if ($q !== '') {
        $like = '%' . escapeLike($q) . '%';
        $where = ' WHERE subject LIKE ? ESCAPE ? OR source LIKE ? ESCAPE ? OR kind LIKE ? ESCAPE ?';
        $args = [$like, '\\', $like, '\\', $like, '\\'];
    }

    return all(
        'SELECT id, subject, kind, text_version, source, ip, user_agent, created_at'
        . ' FROM ' . t('consent_log') . $where
        . ' ORDER BY created_at DESC, id DESC LIMIT 10000',
        $args
    );
}

function consentCsv(string $query = ''): string
{
    $handle = fopen('php://temp', 'r+');

    if ($handle === false) {
        return '';
    }

    fputcsv($handle, ['id', 'subject', 'kind', 'text_version', 'source', 'ip', 'user_agent', 'created_at'], ',', '"', '');

    foreach (consentExportRows($query) as $row) {
        $cells = [];
        foreach (['id', 'subject', 'kind', 'text_version', 'source', 'ip', 'user_agent', 'created_at'] as $key) {
            $value = (string) ($row[$key] ?? '');
            // Табличный редактор не должен исполнить текст человека как формулу.
            if (preg_match('/^[=+\-@]/', $value) === 1) {
                $value = "'" . $value;
            }
            $cells[] = $value;
        }
        fputcsv($handle, $cells, ',', '"', '');
    }

    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
}
