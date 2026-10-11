<?php

declare(strict_types=1);

// Контроллеры экранов админки раздела. Право на вход проверяет гейт маршрута (permission => 'login'),
// поэтому повторной проверки доступа здесь нет: она была бы второй копией того же правила, а
// расхождение между двумя формулировками — это дыра.
//
// Один `nowUtc()` на экран: вкладки, закрепление и «ещё не время» разбираются по одному моменту,
// иначе счётчики разошлись бы между собой на границе срока.
//
// Счётчик «на сайте» — отдельный (`onSiteItemCount()`): вкладка «Опубликованные» считает строки
// по статусу в базе и держит материал снятой с показа рубрики, а на сайте его нет.

/** @param array<string, string> $params */
function newsAdminSummary(array $params = []): void
{
    $now = nowUtc();
    $counts = adminItemCounts($now);
    $pinned = pinnedVisibleCount($now);
    $alertReason = takeAdminFlashReason();

    render('news/admin/summary', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(NEWS_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'counts' => $counts,
        'onSite' => onSiteItemCount($now),
        'pinned' => $pinned,
        'waiting' => $counts['scheduled'],
    ], ['title' => 'Новости — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function newsAdminItems(array $params = []): void
{
    $filters = parseAdminFilters([
        'status' => (string) queryString('status', ''),
        'cat' => (string) queryString('cat', ''),
        'q' => (string) queryString('q', ''),
        'page' => (string) queryString('page', '1'),
    ]);

    $now = nowUtc();
    $alertReason = takeAdminFlashReason();

    render('news/admin/items', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(NEWS_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'filters' => $filters,
        'listing' => adminItemPage($filters, $now),
        'counts' => adminItemCounts($now),
        'onSite' => onSiteItemCount($now),
        'pinned' => pinnedVisibleCount($now),
        'categories' => categoryOptions(),
    ], ['title' => 'Материалы — админка', 'noindex' => true]);
}
