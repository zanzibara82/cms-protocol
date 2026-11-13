<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var list<array{level: array{id: string, code: string, title: string, sort: int}, courses: list<array{course: array{id: string, level_id: string, title: string, description: string, status: string, sort: int}, modules: list<array{id: string, course_id: string, title: string, description: string, status: string, sort: int}>}>}> $tree
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

$treeHtml = '';
foreach ($tree as $levelEntry) {
    $level = $levelEntry['level'];
    $levelHtml = '<section class="rounded-lg border border-ink/10 bg-white p-4 mb-4">'
        . '<h2 class="font-semibold text-ink">Уровень ' . e((string) $level['code']) . ': ' . e((string) $level['title'])
        . ' <span class="' . e($statusChip('PUBLISHED')) . '">уровень</span></h2>';

    foreach ($levelEntry['courses'] as $courseEntry) {
        $course = $courseEntry['course'];
        $courseHtml = '<div class="mt-2 border-t border-ink/10 pt-2">'
            . '<div class="flex flex-wrap items-baseline justify-between gap-2">'
            . '<span class="text-sm font-medium text-ink">' . e((string) $course['title']) . '</span>'
            . '<span class="' . e($statusChip((string) $course['status'])) . '">' . e($statusLabel((string) $course['status'])) . '</span>'
            . '</div>'
            . '<p class="mt-1 text-sm text-ink/60">' . e((string) $course['description']) . '</p>';

        if ($courseEntry['modules'] !== []) {
            $courseHtml .= '<ul class="mt-2 space-y-1">';
            foreach ($courseEntry['modules'] as $module) {
                $courseHtml .= '<li class="flex flex-wrap items-baseline justify-between gap-2 text-sm border-b border-ink/5 pb-1 last:border-0">'
                    . '<span class="text-ink">' . e((string) $module['title']) . '</span>'
                    . '<span class="' . e($statusChip((string) $module['status'])) . '">' . e($statusLabel((string) $module['status'])) . '</span>'
                    . '</li>';
            }
            $courseHtml .= '</ul>';
        } else {
            $courseHtml .= '<p class="mt-1 text-sm text-ink/50">Модулей пока нет — добавьте новый ниже.</p>';
        }

        $courseHtml .= '</div>';
        $levelHtml .= $courseHtml;
    }

    $treeHtml .= $levelHtml . '</section>';
}

$content = '<p class="text-sm text-ink/60">Уровни — строки, под каждым — курсы, под курсом — модули. '
    . 'Действия — отдельными формами на строку: создание, правка, перестановка («выше/ниже»), удаление.</p>'
    . $treeHtml
    . '<h2 class="mt-6 font-semibold">Добавить уровень</h2>'
    . '<form class="mt-2 space-y-2 rounded-lg border border-ink/10 bg-white p-3" method="post" action="/learn/admin/courses">'
    . csrfField()
    . '<input type="hidden" name="op" value="create-level">'
    . '<div class="grid gap-2 sm:grid-cols-3">'
    . '<div class="space-y-1.5"><label class="block text-sm font-medium" for="f-code">Код</label>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm font-mono" id="f-code" name="code" maxlength="12" placeholder="A1" required></div>'
    . '<div class="space-y-1.5"><label class="block text-sm font-medium" for="f-title">Название</label>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" id="f-title" name="title" maxlength="120" placeholder="Начальный" required></div>'
    . '<div class="space-y-1.5"><label class="block text-sm font-medium" for="f-sort">Порядок</label>'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" id="f-sort" name="sort" type="number" value="0" required></div>'
    . '</div>'
    . '<button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:opacity-90" type="submit">Создать уровень</button>'
    . '</form>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo adminShell('Уровни и курсы — админка', $content, $notice);
