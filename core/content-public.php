<?php

declare(strict_types=1);

// RELEASE 2.3: публичные страницы пользовательских типов и записей. Адреса /{type} и
// /{type}/{entry} не занимают отдельные маршруты страниц: первый сегмент разрешает corePage,
// а карточку записи ловит маршрут из двух сегментов. Показывается только опубликованное;
// сотрудник редакции видит черновик по прямому адресу как предпросмотр и получает noindex.

/**
 * Публичная выдача типа по первому сегменту. Возвращает false, если такого типа нет: тогда
 * corePage продолжает обычный разбор страницы, и старые адреса страниц работают как раньше.
 */
function contentTypePublicDispatch(string $slug): bool
{
    if ($slug === '' || !function_exists('contentTypeBySlug')) {
        return false;
    }

    $type = contentTypeBySlug($slug);

    if ($type === null) {
        return false;
    }

    $entries = contentPublishedEntriesWithData((string) $type['id']);
    $cards = [];
    $fields = contentTypeFields((string) $type['id']);

    foreach ($entries as $entry) {
        $data = contentEntryData($entry);
        $cards[] = [
            'title' => (string) $entry['title'],
            'href' => contentEntryHref($type, $entry),
            'excerpt' => contentEntryExcerpt($fields, $data),
            'published_at' => (string) ($entry['published_at'] ?? ''),
        ];
    }

    render('content-type', [
        'type' => $type,
        'cards' => $cards,
        'description' => (string) $type['description'],
    ], [
        'title' => (string) $type['name'],
        'description' => (string) $type['description'],
        // Архив типа: макет назначен у самого типа (спецификация §14, ступень «тип контента»).
        // Пустое значение означает стандартную обвязку — существующие типы не меняют вид.
        'layout' => pageLayoutResolve('', pageLayoutFromSettings(contentTypeSettings($type)), ''),
    ]);

    return true;
}

/** @return list<array<string, mixed>> */
function contentPublishedEntriesWithData(string $typeId, int $limit = 100): array
{
    if ($typeId === '' || !contentTableExists('content_entry')) {
        return [];
    }

    return all(
        'SELECT id, type_id, slug, title, status, data, seo_title, seo_description, published_at, updated_at'
        . ' FROM ' . t('content_entry')
        . ' WHERE type_id = ? AND status = ? ORDER BY published_at DESC, updated_at DESC, title LIMIT '
        . max(1, min(CONTENT_LIST_LIMIT, $limit)),
        [$typeId, CONTENT_STATUS_PUBLISHED]
    );
}

/**
 * Карточка записи /{type}/{entry}. Прежний адрес записи отвечает 301 только когда владелец
 * опубликован: черновик не выдаёт себя даже кодом переадресации.
 *
 * @param array<string, string> $params
 */
function coreContentEntryPage(array $params = []): void
{
    $typeSlug = (string) ($params['type'] ?? '');
    $entrySlug = (string) ($params['entry'] ?? '');

    $type = contentTypeBySlug($typeSlug);

    if ($type === null) {
        notFound();
    }

    $entry = contentEntryBySlug((string) $type['id'], $entrySlug);

    if ($entry === null) {
        $old = contentEntryOldSlug((string) $type['id'], $entrySlug);

        if ($old !== null && (string) $old['status'] === CONTENT_STATUS_PUBLISHED) {
            redirect(contentEntryHref($type, $old), 301);
        }

        notFound();
    }

    $published = (string) $entry['status'] === CONTENT_STATUS_PUBLISHED;

    if (!$published && !roleGrantsManage(currentUser())) {
        notFound();
    }

    $fields = contentTypeFields((string) $type['id']);
    $data = contentEntryData($entry);
    $relations = contentEntryRelations((string) $entry['id'], !$published);
    $views = [];

    foreach ($fields as $field) {
        $key = (string) $field['field_key'];
        $kind = (string) $field['kind'];
        $view = [
            'key' => $key,
            'label' => (string) $field['label'],
            'kind' => $kind,
            'value' => (string) ($data[$key] ?? ''),
            'href' => '',
            'links' => [],
        ];

        if ($kind === CONTENT_FIELD_RELATION) {
            foreach ($relations[$key] ?? [] as $row) {
                $targetType = contentTypeBySlug((string) $row['type_slug']);

                if ($targetType === null) {
                    continue;
                }

                $view['links'][] = [
                    'title' => (string) $row['title'],
                    'href' => '/' . (string) $row['type_slug'] . '/' . (string) $row['slug'],
                ];
            }
        } elseif (contentSafeHref($view['value'], $kind === CONTENT_FIELD_IMAGE)) {
            $view['href'] = $view['value'];
        }

        $views[] = $view;
    }

    $seoTitle = trim((string) $entry['seo_title']);
    $seoDescription = trim((string) $entry['seo_description']);

    if ($seoDescription === '') {
        $seoDescription = contentEntryExcerpt($fields, $data);
    }

    render('content-entry', [
        'type' => $type,
        'entry' => $entry,
        'fields' => $views,
        'relations' => $relations,
        'preview' => !$published,
    ], [
        'title' => $seoTitle !== '' ? $seoTitle : (string) $entry['title'],
        'description' => $seoDescription,
        'noindex' => !$published,
        // Приоритет §14: макет самой записи → макет типа → умолчание сайта. У записи своего поля
        // нет, поэтому первая ступень пустая: записи наследуют макет типа, и это ожидаемо —
        // отдельный макет для одной карточки каталога пока не заводится.
        'layout' => pageLayoutResolve('', pageLayoutFromSettings(contentTypeSettings($type)), ''),
    ]);
}

/** Ссылка для href: пустая строка, если значение не прошло проверку сохранения. */
function contentPublicHref(string $value, bool $imageOnly = false): string
{
    return contentSafeHref($value, $imageOnly) ? $value : '';
}
