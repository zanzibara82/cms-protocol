<?php

declare(strict_types=1);

/**
 * Экран «Источник поиска»: как подключён официальный источник данных. Экран доступен только
 * администратору — здесь лежит клиентский секрет.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array<string, string> $providers
 * @var string $provider
 * @var string $site
 * @var string $clientId
 * @var string $redirectUri
 * @var bool $secretSet
 * @var string $state
 * @var string $connectedAt
 * @var string $authorizeUrl
 * @var array<string, string> $errors
 */

$providerOptions = '';
foreach ($providers as $value => $label) {
    $providerOptions .= '<option value="' . e((string) $value) . '"' . ($provider === $value ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

$errorHtml = '';
foreach ($errors as $message) {
    $errorHtml .= '<p class="mt-1 text-sm text-bad">' . e($message) . '</p>';
}

$stateText = match ($state) {
    'connected' => 'Источник подключён, токен получен'
        . ($connectedAt !== '' ? ' (' . e($connectedAt) . ')' : '') . '.',
    'configured' => 'Настройки заведены, но входа ещё не было.',
    default => 'Источник не настроен.',
};

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/search/source">'
    . csrfField()
    . '<label class="block" for="search_provider">'
    . '<span class="text-sm text-ink/60">Источник</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="search_provider" name="provider">'
    . $providerOptions . '</select>'
    . '</label>'
    . '<label class="block" for="search_site">'
    . '<span class="text-sm text-ink/60">Сайт в поисковике</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="search_site" name="site"'
    . ' value="' . e($site) . '" placeholder="https://example.com/ или sc-domain:example.com">'
    . '<span class="mt-1 block text-sm text-ink/60">Так адрес выглядит в панели поисковика. Другие формы источник не принимает.</span>'
    . '</label>'
    . '<label class="block" for="search_client_id">'
    . '<span class="text-sm text-ink/60">Идентификатор клиента</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="text" id="search_client_id" name="client_id"'
    . ' value="' . e($clientId) . '">'
    . '<span class="mt-1 block text-sm text-ink/60">У тестового источника клиента нет: он ничего не выдаёт и никуда не ходит.</span>'
    . '</label>'
    . '<label class="block" for="search_client_secret">'
    . '<span class="text-sm text-ink/60">Клиентский секрет</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" type="password" id="search_client_secret" name="client_secret"'
    . ' value="" autocomplete="new-password">'
    . '<span class="mt-1 block text-sm text-ink/60">'
    . ($secretSet ? 'Секрет задан и на экран обратно не выводится.' : 'Секрет не задан.')
    . ' Оставьте поле пустым, чтобы прежний секрет сохранился: показать его второй раз мы не можем.</span>'
    . '</label>'
    . '<label class="block" for="search_redirect">'
    . '<span class="text-sm text-ink/60">Адрес возврата</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" type="text" id="search_redirect" name="redirect"'
    . ' value="' . e($redirectUri) . '" readonly>'
    . '<span class="mt-1 block text-sm text-ink/60">Этот адрес нужно указать в панели источника — в списке разрешённых '
    . 'адресов возврата. Он приходит сюда, а не из строки запроса: иначе код ушёл бы туда, куда указал бы кто-то другой.</span>'
    . '</label>'
    . ($errorHtml === '' ? '' : '<div>' . $errorHtml . '</div>')
    . '<div class="flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' type="submit" name="op" value="save">Сохранить</button>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
    . ' type="submit" name="op" value="authorize">Получить ссылку для входа</button>'
    . '</div>'
    . '</form>';

$disconnectHtml = '<form class="space-y-3 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/seo/admin/search/source">'
    . csrfField()
    . '<input type="hidden" name="op" value="disconnect">'
    . '<h2 class="text-lg font-semibold">Отключение источника</h2>'
    . '<p class="text-sm text-ink/70">Удаляются токены и сохранённый секрет. Импортированные данные остаются: '
    . 'они уже в базе и относятся к прошлым дням.</p>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
    . ' type="submit">Отключить источник</button>'
    . '</form>';

$content = $nav
    . '<header class="space-y-2">'
    . '<h1 class="text-2xl font-bold">Источник поиска</h1>'
    . '<p class="text-sm text-ink/70">Здесь подключается официальный источник данных: Google Search Console отдаёт '
    . 'запросы, показы, клики, CTR и позиции по нашему сайту. Без подключения этих данных нет, и подставлять '
    . 'свою статистику вместо данных поисковика мы не будем.</p>'
    . '</header>'
    . '<p class="rounded-lg border border-ink/10 bg-cream p-4 text-sm text-ink/70">Состояние: ' . $stateText . '</p>'
    . $formHtml
    . $disconnectHtml
    . '<p class="text-xs text-ink/60">Клиентский секрет и токены хранятся в базе сайта и на экран не выводятся. '
    . 'Доступ к базе — доступ к интеграции: тот, кто может читать таблицы, может и пользоваться токеном. '
    . 'Токен выдаётся источником только на чтение и только для нашего сайта.</p>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Источник поиска', $content, $notice);
