<?php

declare(strict_types=1);

/**
 * Экран «404 и редиректы» раздела «SEO и статистика»: журнал несуществующих адресов, поиск
 * целевой страницы и правила переходов.
 *
 * Методика подписана прямо на экране: в журнале лежит адрес без строки запроса (иначе в журнал
 * уехали бы чувствительные параметры) и хост страницы-источника без полного адреса. Адреса
 * посетителей не пишутся никуда.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $misses
 * @var list<array<string, mixed>> $redirects
 * @var list<array{path: string, title: string}> $candidates
 * @var string $query
 * @var array{from: string, to: string, code: int} $form
 */
$code301 = (int) $form['code'] === 301;
$code302 = (int) $form['code'] === 302;

// Поиск целевой страницы: форма без токена — она ничего не меняет, а токен в адресной строке
// только засорял бы её.
$searchForm = '<form class="flex flex-wrap items-end gap-2" method="get" action="/seo/admin/errors">'
    . '<label class="block grow" for="seo_search">'
    . '<span class="text-sm text-ink/60">Найти страницу назначения</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2"'
    . ' id="seo_search" name="q" maxlength="190" value="' . e($query) . '" placeholder="classifieds">'
    . '</label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . 'Найти</button>'
    . '</form>';

$candidatesHtml = '';

if ($candidates !== []) {
    $items = '';

    foreach ($candidates as $candidate) {
        $items .= '<li class="flex flex-wrap items-center gap-2">'
            . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
            . ' href="/seo/admin/errors?to=' . rawurlencode((string) $candidate['path']) . '">'
            . e((string) $candidate['path']) . '</a>'
            . '<span class="text-sm text-ink/60">' . e((string) $candidate['title']) . '</span>'
            . '</li>';
    }

    $candidatesHtml = '<ul class="space-y-1">' . $items . '</ul>';
} elseif ($query !== '') {
    $candidatesHtml = '<p class="rounded-lg border border-ink/10 bg-cream p-4 text-sm text-ink/70">'
        . 'Ничего не нашлось. Ищите по карте сайта и справочнику метаданных — это наши собственные адреса.</p>';
}

$searchBlock = '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Куда переводить</h2>'
    . $searchForm
    . $candidatesHtml
    . '</section>';

// Форма правила: адрес, с которого уходят, адрес, на который приходят, и код ответа.
$redirectForm = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/errors">'
    . csrfField()
    . '<input type="hidden" name="op" value="redirect_create">'
    . '<label class="block" for="seo_from">'
    . '<span class="text-sm text-ink/60">С какого адреса переводить</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2"'
    . ' id="seo_from" name="from" maxlength="255" placeholder="/staryy-adres"'
    . ' value="' . e((string) $form['from']) . '">'
    . '</label>'
    . '<label class="block" for="seo_to">'
    . '<span class="text-sm text-ink/60">На какой адрес переводить</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2"'
    . ' id="seo_to" name="to" maxlength="255" placeholder="/novyy-adres"'
    . ' value="' . e((string) $form['to']) . '">'
    . '</label>'
    . '<label class="block" for="seo_code">'
    . '<span class="text-sm text-ink/60">Код ответа</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="seo_code" name="code">'
    . '<option value="301"' . ($code301 ? ' selected' : '') . '>301 — переехали навсегда</option>'
    . '<option value="302"' . ($code302 ? ' selected' : '') . '>302 — временно</option>'
    . '</select>'
    . '</label>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . 'Сохранить правило</button>'
    . '</div></form>';

// Журнал: адрес, сколько раз приходили, откуда, когда впервые и когда в последний раз.
$missRows = '';

foreach ($misses as $miss) {
    $path = (string) ($miss['path'] ?? '');
    $referrer = trim((string) ($miss['referrer_host'] ?? ''));

    $missRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="/seo/admin/errors?from=' . rawurlencode($path) . '">' . e($path) . '</a></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm tabular-nums">' . e((string) ($miss['hits'] ?? 0)) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">'
        . e($referrer === '' ? 'прямой заход' : $referrer) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) ($miss['last_seen_at'] ?? '')) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) ($miss['first_seen_at'] ?? '')) . '</td>'
        . '</tr>';
}

$missTable = $missRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Журнал пуст: несуществующих адресов не запрашивали.</p>'
    : '<div class="overflow-x-auto"><table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Адрес</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Обращений</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Источник</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Последний раз</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Первый раз</th>'
        . '</tr></thead>'
        . '<tbody>' . $missRows . '</tbody></table></div>';

// Правила переходов: откуда, куда, код и кнопка удаления.
$redirectRows = '';

foreach ($redirects as $redirect) {
    $redirectRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) ($redirect['from_path'] ?? '')) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm">' . e((string) ($redirect['to_path'] ?? '')) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 text-sm text-ink/70">' . e((string) ($redirect['code'] ?? '')) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . '<form method="post" action="/seo/admin/errors">'
        . csrfField()
        . '<input type="hidden" name="op" value="redirect_delete">'
        . '<input type="hidden" name="id" value="' . e((string) ($redirect['id'] ?? '')) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form></td>'
        . '</tr>';
}

$redirectTable = $redirectRows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Правил перехода пока нет.</p>'
    : '<div class="overflow-x-auto"><table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">С адреса</th>'
        . '<th class="px-2 py-2 text-left font-semibold">На адрес</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Код</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $redirectRows . '</tbody></table></div>';

$clearForm = '<form method="post" action="/seo/admin/errors">'
    . csrfField()
    . '<input type="hidden" name="op" value="errors_clear">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
    . 'Очистить журнал</button></form>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">404 и редиректы</h1>'
    . '<p class="text-sm text-ink/70">Журнал показывает, по каким несуществующим адресам приходят. '
    . 'Правило перехода отвечает на такой адрес сам: адрес с правилом больше не попадает в журнал, '
    . 'а прежние его обращения остаются видны — так видно, сколько трафика он приносил.</p>'
    . '</header>'
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Журнал 404</h2>'
    . $missTable
    . '<div class="flex flex-wrap gap-2">' . $clearForm . '</div>'
    . '<p class="text-xs text-ink/60">В журнале лежит адрес без строки запроса (иначе сюда уехали бы '
    . 'чувствительные параметры) и хост страницы-источника без полного адреса. Адреса посетителей '
    . 'не пишутся никуда. Журнал хранит не больше 2000 адресов: самые старые строки срезаются.</p>'
    . '</section>'
    . $searchBlock
    . $redirectForm
    . '<section class="space-y-2">'
    . '<h2 class="text-lg font-semibold">Правила перехода</h2>'
    . $redirectTable
    . '<p class="text-xs text-ink/60">Правило не может вести сам на себя и не может замыкать цепочку: '
    . 'цепочка длиннее пяти переходов — это ошибочная настройка, а не переезд. Цель перехода — '
    . 'адрес нашего сайта: путь от корня, чужой домен правилом не заводится.</p>'
    . '</section>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('404 и редиректы', $content, $notice);
