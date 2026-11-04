<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var list<array{lesson: array{id: string, module_id: string, slug: string, title: string, description: string, status: string, sort: int}, module: array{id: string, course_id: string, title: string, description: string, status: string, sort: int}|null, course: array{id: string, level_id: string, title: string, description: string, status: string, sort: int}|null, level: array{id: string, code: string, title: string, sort: int}|null}> $rows
 */

$statusChip = static function (string $status): string {
    return match ($status) {
        'PUBLISHED' => 'rounded-lg bg-good/10 px-2 py-0.5 text-xs font-medium text-good',
        'REVIEW' => 'rounded-lg border border-accent/30 bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent',
        default => 'rounded-lg border border-ink/20 px-2 py-0.5 text-xs font-medium text-ink/70',
    };
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'PUBLISHED' => 'Опубликовано',
        'REVIEW' => 'На проверке',
        default => 'Черновик',
    };
};

$rowsHtml = '';
foreach ($rows as $row) {
    $lesson = $row['lesson'];
    $module = $row['module'];
    $course = $row['course'];
    $level = $row['level'];

    $path = $level !== null && $course !== null && $module !== null
        ? e((string) $level['code']) . ' › ' . e((string) $course['title']) . ' › ' . e((string) $module['title'])
        : '—';

    $buttons = '<form class="inline" method="post" action="/learn/admin/lessons">' . csrfField()
        . '<input type="hidden" name="op" value="delete-lesson">'
        . '<input type="hidden" name="item" value="' . e((string) $lesson['id']) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-bad/30 bg-white px-2.5 py-1 text-xs font-medium text-bad hover:bg-bad/10" type="submit" onclick="return confirm(\'Удалить уроку «' . e((string) $lesson['title']) . '» со всеми целями, словами, грамматикой, упражнениями и тестом? Это нельзя отменить.\')">Удалить</button></form>';

    $buttons .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 text-sm hover:bg-ink/5" href="/learn/admin/lessons/'
        . e((string) $lesson['id']) . '">Править</a>';

    $rowsHtml .= '<li class="rounded-lg border border-ink/10 bg-white p-3">'
        . '<div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">'
        . '<a class="inline-flex min-h-[44px] items-center font-medium hover:underline" href="/lesson/'
        . e((string) $lesson['slug']) . '">' . e((string) $lesson['title']) . '</a>'
        . '<span class="' . e($statusChip((string) $lesson['status'])) . '">' . e($statusLabel((string) $lesson['status'])) . '</span>'
        . '</div>'
        . '<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink/60">'
        . '<span>Путь: ' . $path . '</span>'
        . '<span>Адрес: /lesson/' . e((string) $lesson['slug']) . '</span>'
        . '</div>'
        . '<div class="mt-2 flex flex-wrap gap-2">' . $buttons . '</div></li>';
}

$empty = '<div class="rounded-lg border border-ink/10 bg-white py-10 text-center text-ink/60">'
    . '<p class="text-sm">Уроков пока нет.</p>'
    . '<p class="mt-2"><a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-ink" href="/learn/admin/lessons/new">Создать первый урок</a></p></div>';

$content = '<p class="text-sm text-ink/60">Уроки — плоский список с путём по уровню, курсу и модулю. '
    . 'Править можно в форме урока, удалить — кнопкой строки.</p>'
    . ($rowsHtml === '' ? $empty : '<ul class="space-y-2">' . $rowsHtml . '</ul>')
    . '<p class="mt-4"><a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:opacity-90" href="/learn/admin/lessons/new">Новый урок</a></p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo adminShell('Уроки — админка', $content, $notice);
