<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{kind: string, name: string}> $kinds */
/** @var list<array<string, mixed>> $sections */
/** @var array{label: string, updated_at: string, section: array<string, mixed>}|null $clipboard */
/** @var int $editorialVersion */

// Блоки главной: секции живут с page_id = '' и рисуются на «/» без строки страницы.
$sectionsCard = partial('admin/pages-sections', ['sections' => $sections, 'target' => '/admin/pages/home', 'kinds' => $kinds, 'version' => $editorialVersion]);

$clipboardCard = '';

if ($clipboard !== null) {
    $clipboardCard = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold">Буфер обмена</h2>'
        . '<p class="mt-1.5 text-sm text-ink/70">Скопирована секция «' . e((string) $clipboard['label']) . '» ('
        . e(formatRuDateTime(fromDb((string) $clipboard['updated_at']))) . ').</p>'
        . '<div class="mt-3 flex flex-wrap items-center gap-2">'
        . '<form method="post" action="/admin/pages/home">' . csrfField()
        . '<input type="hidden" name="op" value="paste-section">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Вставить секцию на главную</button>'
        . '</form>'
        . '<form method="post" action="/admin/pages/home">' . csrfField()
        . '<input type="hidden" name="op" value="clear-clipboard">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Отменить копию</button>'
        . '</form>'
        . '</div></section>';
}

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Блоки главной', $alert . $clipboardCard . $sectionsCard, $notice);
