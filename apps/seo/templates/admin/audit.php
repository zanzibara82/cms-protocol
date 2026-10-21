<?php

declare(strict_types=1);

/**
 * Экран «Аудит» раздела «SEO и статистика»: находки по нашим данным, их важность и история.
 *
 * Методика подписана прямо на экране: проверка идёт по справочнику метаданных и по карте
 * сайта, внешний обход сайта не делается, а содержимое чужих разделов не разбирается. Находка
 * честно живёт с историей: первое и последнее обнаружение, число повторов и отметка об
 * исправлении.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $issues
 * @var array{critical: int, warning: int, recommendation: int, open: int, resolved: int} $summary
 * @var array<string, string> $severities
 */
$severityClass = static function (string $severity): string {
    if ($severity === 'critical') {
        return 'bg-bad/10 border-bad/30 text-bad';
    }

    if ($severity === 'warning') {
        return 'bg-accent/10 border-accent text-amber-700';
    }

    return 'bg-ink/5 border-ink/20 text-ink/70';
};

$card = static function (string $label, int $value): string {
    return '<div class="rounded-xl border border-ink/10 bg-white p-4">'
        . '<p class="text-sm text-ink/60">' . e($label) . '</p>'
        . '<p class="mt-1 text-2xl font-bold">' . e((string) $value) . '</p>'
        . '</div>';
};

$cards = '<div class="grid gap-3 sm:grid-cols-3">'
    . $card('Критично', $summary['critical'])
    . $card('Предупреждений', $summary['warning'])
    . $card('Рекомендаций', $summary['recommendation'])
    . '</div>';

$runForm = '<form method="post" action="/seo/admin/audit">'
    . csrfField()
    . '<input type="hidden" name="op" value="audit_run">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . 'Проверить заново</button></form>';

$rows = '';

foreach ($issues as $issue) {
    $severity = (string) ($issue['severity'] ?? '');
    $status = (string) ($issue['status'] ?? '');
    $resolved = $status !== 'open';
    $seen = (int) ($issue['seen_count'] ?? 0);

    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . '<span class="inline-flex items-center rounded-lg border px-2 py-1 text-xs ' . $severityClass($severity) . '">'
        . e($severities[$severity] ?? $severity) . '</span></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) ($issue['path'] ?? '')) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">'
        . '<p class="font-medium">' . e((string) ($issue['title'] ?? '')) . '</p>'
        . '<p class="mt-1 text-ink/70">' . e((string) ($issue['description'] ?? '')) . '</p>'
        . '<p class="mt-1 text-ink/70">Что делать: ' . e((string) ($issue['recommendation'] ?? '')) . '</p></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . '<p>' . e($resolved ? 'Исправлено' : 'Открыто') . ', замечено ' . e((string) $seen) . ' раз</p>'
        . '<p class="mt-1">первый раз: ' . e((string) ($issue['first_seen_at'] ?? '')) . '</p>'
        . '<p class="mt-1">последний раз: ' . e((string) ($issue['last_seen_at'] ?? '')) . '</p>'
        . ($resolved
            ? '<p class="mt-1">исправлено: ' . e((string) ($issue['fixed_at'] ?? '')) . '</p>'
            : '')
        . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Находок пока нет — '
        . 'проверка не запускалась или всё в порядке.</p>'
    : '<div class="overflow-x-auto"><table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Важность</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Адрес</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Замечание</th>'
        . '<th class="px-2 py-2 text-left font-semibold">История</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Аудит</h1>'
    . '<p class="text-sm text-ink/70">Проверка идёт по нашим данным: справочник метаданных и карта сайта. '
    . 'Внешний обход сайта не делается, содержимое чужих разделов не разбирается — замечание о чужой '
    . 'странице было бы догадкой, а не проверкой.</p>'
    . '</header>'
    . $cards
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Проверка</h2>'
    . '<div class="flex flex-wrap gap-2">' . $runForm . '</div>'
    . '<p class="text-xs text-ink/60">Открытых замечаний: ' . e((string) $summary['open'])
    . ', исправленных: ' . e((string) $summary['resolved'])
    . '. Проверка ничего не меняет на сайте — она только пересчитывает замечания по данным, которые уже есть, '
    . 'и обновляет их историю.</p>'
    . '</section>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Замечания</h2>'
    . $table
    . '</section>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Аудит', $content, $notice);
