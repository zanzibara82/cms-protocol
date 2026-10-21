<?php

declare(strict_types=1);

/**
 * Экран «Настройки статистики»: что собираем, сколько храним и как считаем сессию. Рядом —
 * очистка сырья старше срока хранения; агрегаты при этом остаются, иначе счётчики за прошлые
 * дни исчезли бы вместе с сырьём.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array{base: string, days: int, source: string} $filter
 * @var array{enabled: bool, timeout: int, retention: int, respectDnt: bool} $settings
 * @var array{visits: int, sessions: int} $purge
 */

$enabledChecked = $settings['enabled'];
$dntChecked = $settings['respectDnt'];

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/settings">'
    . csrfField()
    . '<input type="hidden" name="op" value="stats_settings">'
    . '<label class="flex items-start gap-3">'
    . '<input type="checkbox" name="enabled" value="1"' . ($enabledChecked ? ' checked' : '')
    . ' class="min-h-[44px] size-11 rounded border border-ink/20 bg-white">'
    . '<span class="text-sm">Собирать статистику: без этого счётчик не подключается к страницам, '
    . 'а приёмник отвечает «выключено».</span>'
    . '</label>'
    . '<label class="block" for="stats_timeout">'
    . '<span class="text-sm text-ink/60">Сессия — окно бездействия, минут</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="number"'
    . ' id="stats_timeout" name="timeout" min="' . e((string) SEO_STATS_TIMEOUT_MIN) . '"'
    . ' max="' . e((string) SEO_STATS_TIMEOUT_MAX) . '" value="' . e((string) $settings['timeout']) . '">'
    . '<span class="mt-1 block text-sm text-ink/60">Допустимо от ' . e((string) SEO_STATS_TIMEOUT_MIN)
    . ' до ' . e((string) SEO_STATS_TIMEOUT_MAX) . ' минут.</span>'
    . '</label>'
    . '<label class="block" for="stats_retention">'
    . '<span class="text-sm text-ink/60">Сколько дней хранить сырьё</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="number"'
    . ' id="stats_retention" name="retention" min="' . e((string) SEO_STATS_RETENTION_MIN) . '"'
    . ' max="' . e((string) SEO_STATS_RETENTION_MAX) . '" value="' . e((string) $settings['retention']) . '">'
    . '<span class="mt-1 block text-sm text-ink/60">Допустимо от ' . e((string) SEO_STATS_RETENTION_MIN)
    . ' до ' . e((string) SEO_STATS_RETENTION_MAX) . ' дней. Старые записи удаляются при очистке.</span>'
    . '</label>'
    . '<label class="flex items-start gap-3">'
    . '<input type="checkbox" name="respect_dnt" value="1"' . ($dntChecked ? ' checked' : '')
    . ' class="min-h-[44px] size-11 rounded border border-ink/20 bg-white">'
    . '<span class="text-sm">Уважать «не отслеживать» (Do Not Track): браузер просит не следить — '
    . 'значит, и мы не следим.</span>'
    . '</label>'
    . '<div class="mt-1">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' type="submit">Сохранить настройки</button>'
    . '</div></form>';

$purgeHtml = '<form class="space-y-3 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/settings">'
    . csrfField()
    . '<input type="hidden" name="op" value="stats_purge">'
    . '<h2 class="text-lg font-semibold">Очистка сырья</h2>'
    . '<p class="text-sm text-ink/70">Удаляются события и сессии старше ' . e((string) $settings['retention'])
    . ' дней: сейчас это ' . e((string) $purge['visits']) . ' событий и ' . e((string) $purge['sessions'])
    . ' сессий. Счётчики за прошлые дни при этом не меняются — они живут в агрегатах.</p>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
    . ' type="submit">Очистить старые записи</button>'
    . '</form>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Настройки статистики</h1>'
    . '<p class="text-sm text-ink/70">Счётчик считает собственные посещения сайта: cookie не используются, '
    . 'адрес посетителя не хранится, содержимое форм не собирается. Идентификатор посетителя — подпись '
    . 'случайного номера из localStorage, и она не переживает очистку браузера.</p>'
    . '</header>'
    . $formHtml
    . $purgeHtml;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Настройки статистики', $content, $notice);
