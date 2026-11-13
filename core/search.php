<?php

declare(strict_types=1);

// Сквозной поиск редакции: одна строка — результаты по страницам, материалам, товарам,
// объявлениям, урокам, медиафайлам и (только администратору) заявкам. Таблицы приложений
// спрашиваются через tableExists(): на голой установке раздела ещё нет, и это не ошибка.
// Поиск идёт подготовленным запросом с escapeLike() и явным ESCAPE: «100%» ищется как текст.

const SEARCH_MIN = 2;
const SEARCH_MAX = 100;
const SEARCH_PER_SOURCE = 10;

/**
 * @return array{groups: list<array{title: string, items: list<array{title: string, subtitle: string, href: string, date: string}>}>, total: int, query: string}
 */
function globalSearch(string $query): array
{
    $q = mb_substr(trim($query), 0, SEARCH_MAX);
    $result = ['groups' => [], 'total' => 0, 'query' => $q];

    if (mb_strlen($q) < SEARCH_MIN) {
        return $result;
    }

    $like = '%' . escapeLike($q) . '%';
    $escape = '\\';

    $pages = all(
        'SELECT id, slug, title, description, status, updated_at FROM ' . t('page')
        . ' WHERE title LIKE ? ESCAPE ? OR slug LIKE ? ESCAPE ? OR description LIKE ? ESCAPE ?'
        . ' ORDER BY updated_at DESC LIMIT ' . SEARCH_PER_SOURCE,
        [$like, $escape, $like, $escape, $like, $escape]
    );
    $items = [];
    foreach ($pages as $row) {
        $items[] = [
            'title' => (string) $row['title'],
            'subtitle' => '/' . (string) $row['slug'] . ' · ' . ((string) $row['status'] === PAGES_STATUS_PUBLISHED ? 'опубликовано' : 'черновик'),
            'href' => '/admin/pages/' . (string) $row['id'],
            'date' => (string) $row['updated_at'],
        ];
    }
    searchAddGroup($result, 'Страницы', $items);

    if (tableExists('news_item')) {
        $news = all(
            'SELECT id, slug, title, lead_text, status, published_at, updated_at FROM ' . t('news_item')
            . ' WHERE title LIKE ? ESCAPE ? OR slug LIKE ? ESCAPE ? OR lead_text LIKE ? ESCAPE ?'
            . ' ORDER BY updated_at DESC LIMIT ' . SEARCH_PER_SOURCE,
            [$like, $escape, $like, $escape, $like, $escape]
        );
        $items = [];
        foreach ($news as $row) {
            $items[] = [
                'title' => (string) $row['title'],
                'subtitle' => '/news/' . (string) $row['slug'] . ' · ' . ((string) $row['status'] === 'PUBLISHED' ? 'опубликовано' : 'черновик'),
                'href' => '/news/admin/items/' . (string) $row['id'],
                'date' => (string) ($row['published_at'] ?? $row['updated_at']),
            ];
        }
        searchAddGroup($result, 'Новости', $items);
    }

    if (tableExists('commerce_product')) {
        $products = all(
            'SELECT id, slug, title, sku, status, updated_at FROM ' . t('commerce_product')
            . ' WHERE title LIKE ? ESCAPE ? OR slug LIKE ? ESCAPE ? OR sku LIKE ? ESCAPE ?'
            . ' ORDER BY updated_at DESC LIMIT ' . SEARCH_PER_SOURCE,
            [$like, $escape, $like, $escape, $like, $escape]
        );
        $items = [];
        foreach ($products as $row) {
            $items[] = [
                'title' => (string) $row['title'],
                'subtitle' => 'Артикул ' . (string) $row['sku'] . ' · /shop/' . (string) $row['slug'],
                'href' => '/commerce/admin/products/' . (string) $row['id'],
                'date' => (string) $row['updated_at'],
            ];
        }
        searchAddGroup($result, 'Товары', $items);
    }

    if (tableExists('classifieds_listing')) {
        $listings = all(
            'SELECT id, slug, title, status, updated_at FROM ' . t('classifieds_listing')
            . ' WHERE title LIKE ? ESCAPE ? OR slug LIKE ? ESCAPE ? OR body LIKE ? ESCAPE ?'
            . ' ORDER BY updated_at DESC LIMIT ' . SEARCH_PER_SOURCE,
            [$like, $escape, $like, $escape, $like, $escape]
        );
        $items = [];
        foreach ($listings as $row) {
            $items[] = [
                'title' => (string) $row['title'],
                'subtitle' => '/classifieds/' . (string) $row['slug'] . ' · ' . ((string) $row['status'] === 'PUBLISHED' ? 'опубликовано' : (string) $row['status']),
                'href' => '/classifieds/cabinet/' . (string) $row['id'],
                'date' => (string) $row['updated_at'],
            ];
        }
        searchAddGroup($result, 'Объявления', $items);
    }

    if (tableExists('learn_lesson')) {
        $lessons = all(
            'SELECT id, slug, title, description, status FROM ' . t('learn_lesson')
            . ' WHERE title LIKE ? ESCAPE ? OR slug LIKE ? ESCAPE ? OR description LIKE ? ESCAPE ?'
            . ' ORDER BY sort, id LIMIT ' . SEARCH_PER_SOURCE,
            [$like, $escape, $like, $escape, $like, $escape]
        );
        $items = [];
        foreach ($lessons as $row) {
            $items[] = [
                'title' => (string) $row['title'],
                'subtitle' => '/lesson/' . (string) $row['slug'] . ' · ' . ((string) $row['status'] === 'PUBLISHED' ? 'опубликован' : 'черновик'),
                'href' => '/learn/admin/lessons/' . (string) $row['id'],
                'date' => '',
            ];
        }
        searchAddGroup($result, 'Уроки', $items);
    }

    if (tableExists('media')) {
        $media = all(
            'SELECT id, path, title, alt, created_at FROM ' . t('media')
            . ' WHERE title LIKE ? ESCAPE ? OR alt LIKE ? ESCAPE ? OR path LIKE ? ESCAPE ?'
            . ' ORDER BY created_at DESC LIMIT ' . SEARCH_PER_SOURCE,
            [$like, $escape, $like, $escape, $like, $escape]
        );
        $items = [];
        foreach ($media as $row) {
            $items[] = [
                'title' => (string) ($row['title'] !== '' ? $row['title'] : basename((string) $row['path'])),
                'subtitle' => (string) $row['path'],
                'href' => '/admin/media?q=' . rawurlencode((string) $row['path']),
                'date' => (string) $row['created_at'],
            ];
        }
        searchAddGroup($result, 'Медиафайлы', $items);
    }

    // Заявки — личные данные. Право manage проверяется здесь, а не только на маршруте: экран
    // поиска открыт редактору, но контакты людей ему не показываются.
    if (tableExists('lead') && roleGrantsManage(currentUser())) {
        $leads = all(
            'SELECT id, name, contact, message, status, created_at FROM ' . t('lead')
            . ' WHERE name LIKE ? ESCAPE ? OR contact LIKE ? ESCAPE ? OR message LIKE ? ESCAPE ?'
            . ' ORDER BY created_at DESC LIMIT ' . SEARCH_PER_SOURCE,
            [$like, $escape, $like, $escape, $like, $escape]
        );
        $items = [];
        foreach ($leads as $row) {
            $items[] = [
                'title' => (string) $row['name'],
                'subtitle' => (string) $row['contact'] . ' · ' . (string) $row['status'],
                'href' => '/admin/leads?q=' . rawurlencode((string) $row['contact']),
                'date' => (string) $row['created_at'],
            ];
        }
        searchAddGroup($result, 'Заявки', $items);
    }

    return $result;
}

/**
 * @param array{groups: list<array{title: string, items: list<array{title: string, subtitle: string, href: string, date: string}>}>, total: int, query: string} $result
 * @param list<array{title: string, subtitle: string, href: string, date: string}> $items
 */
function searchAddGroup(array &$result, string $title, array $items): void
{
    if ($items === []) {
        return;
    }

    $result['groups'][] = ['title' => $title, 'items' => $items];
    $result['total'] += count($items);
}
