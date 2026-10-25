<?php

declare(strict_types=1);

/**
 * Экран ответа формы создания ключа: секрет печатается ровно один раз, дальше его вернёт
 * только новый ключ. Экран открывается через POST — обновление страницы покажет тот же
 * секрет без повторного создания, и это осознанный размен: секрет нужно успеть скопировать,
 * а не потерять при случайном обновлении.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array{ok: bool, error: string, key: string, id: string} $created
 */

$secret = e($created['key']);

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Ключ создан</h1>'
    . '<p class="text-sm text-ink/70">Скопируйте ключ сейчас: этот экран — единственное место, где он '
    . 'показывается. В базе лежит только хеш, поэтому вернуться к ключу невозможно — либо он в вашей '
    . 'конфигурации, либо отзыв и новый.</p>'
    . '</header>'
    . '<div class="space-y-4 rounded-xl border border-ink/10 bg-white p-6">'
    . '<h2 class="text-lg font-semibold">Ключ</h2>'
    . '<code class="block break-all rounded-lg border border-ink/10 bg-cream p-4 font-mono text-sm">'
    . $secret
    . '</code>'
    . '<p class="text-sm text-ink/60">В читателе отчётов ключ передаётся заголовком.'
    . '<br><code class="font-mono text-xs">Authorization: Bearer &lt;ключ&gt;</code></p>'
    . '<p class="text-sm text-ink/60">Адреса: /seo/api/v1/summary, /seo/api/v1/pages,'
    . ' /seo/api/v1/sources, /seo/api/v1/goals</p>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
    . ' href="/seo/admin/api">Вернуться к списку</a>'
    . '</div>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Ключ создан', $content, $notice);
