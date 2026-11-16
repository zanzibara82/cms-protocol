<?php

declare(strict_types=1);

/**
 * Карточка записи пользовательского типа. Значения полей уже проверены при сохранении, но на
 * печать всё равно идут через e() —richText() собирает разметку только из абзацев с выделением.
 *
 * @var array<string, mixed> $type
 * @var array<string, mixed> $entry
 * @var list<array{key: string, label: string, kind: string, value: string, href: string, links: list<array{title: string, href: string}>}> $fields
 * @var array<string, list<array<string, mixed>>> $relations
 * @var bool $preview
 */

$content = '';
foreach ($fields as $field) {
    $kind = (string) $field['kind'];
    $value = (string) $field['value'];
    $label = (string) $field['label'];

    if ($kind === CONTENT_FIELD_CHECKBOX) {
        $value = $value === '1' ? 'Да' : 'Нет';
    }

    if ($kind === CONTENT_FIELD_RELATION) {
        $links = '';
        foreach ($field['links'] as $link) {
            $links .= '<li><a class="inline-flex min-h-[44px] items-center hover:underline" href="'
                . e((string) $link['href']) . '">' . e((string) $link['title']) . '</a></li>';
        }

        if ($links !== '') {
            $content .= '<section class="border-b border-ink/10 py-4">'
                . '<h2 class="text-sm font-medium text-ink/60">' . e($label) . '</h2>'
                . '<ul class="mt-1">' . $links . '</ul></section>';
        }

        continue;
    }

    if ($value === '') {
        continue;
    }

    $content .= '<section class="border-b border-ink/10 py-4">'
        . '<h2 class="text-sm font-medium text-ink/60">' . e($label) . '</h2>';

    if ($kind === CONTENT_FIELD_RICHTEXT) {
        $content .= '<div class="mt-2 leading-relaxed break-words">' . richText($value) . '</div>';
    } elseif ($kind === CONTENT_FIELD_IMAGE && (string) $field['href'] !== '') {
        $content .= '<p class="mt-2"><img class="max-h-96 w-auto max-w-full rounded-lg" src="'
            . e((string) $field['href']) . '" alt="' . e($label) . '" loading="lazy"></p>';
    } elseif ((string) $field['href'] !== '') {
        $content .= '<p class="mt-2 break-words"><a class="inline-flex min-h-[44px] items-center hover:underline" href="'
            . e((string) $field['href']) . '">' . e($value) . '</a></p>';
    } elseif ($kind === CONTENT_FIELD_TEXTAREA) {
        $content .= '<p class="mt-2 whitespace-pre-line break-words">' . e($value) . '</p>';
    } else {
        $content .= '<p class="mt-2 break-words">' . e($value) . '</p>';
    }

    $content .= '</section>';
}
?>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
    <?= partial('partials/breadcrumbs', ['items' => [ /* @safe html */
        ['label' => 'Главная', 'href' => '/'],
        ['label' => (string) $type['name'], 'href' => '/' . (string) $type['slug']],
        ['label' => (string) $entry['title'], 'href' => ''],
    ]]) ?>
    <?php if ($preview): ?>
        <p class="mt-4 text-sm font-semibold uppercase tracking-wide text-ink/60">Предпросмотр черновика</p>
    <?php endif; ?>
    <h1 class="mt-2 text-3xl font-bold"><?= e((string) $entry['title']) ?></h1>
    <?php if ((string) $entry['published_at'] !== ''): ?>
        <p class="mt-2 text-sm text-ink/50"><?= e(formatRuDateTime(fromDb((string) $entry['published_at']))) ?></p>
    <?php endif; ?>
    <div class="mt-4"><?= $content /* @safe html */ ?></div>
</div>
