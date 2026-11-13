<?php

declare(strict_types=1);

// Корзина RELEASE 2.1: удаление из живых таблиц, но не из базы. Публичные страницы и секции
// исчезают сразу (адрес отвечает 404), а payload хранит всё для восстановления. Существующие
// deletePage()/sectionDelete() остаются безвозвратными для тестов и кода, которому корзина
// действительно не нужна; экран конструктора пользуется функциями этого файла.

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function trashPage(string $id): array
{
    $page = pageById($id);

    if ($page === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'notice' => ''];
    }

    $sections = all(
        'SELECT id, page_id, sec_key, kind, layout, anchor, badge, title, lead_text, note, image, image_alt,'
        . ' buttons, items, settings, sort, visible, updated_at FROM ' . t('page_section')
        . ' WHERE page_id = ? ORDER BY sort, id LIMIT ' . PAGES_SECTIONS_LIMIT,
        [$id]
    );
    $oldSlugs = all('SELECT slug, page_id, created_at FROM ' . t('page_old_slug') . ' WHERE page_id = ? LIMIT 500', [$id]);

    $payload = json_encode(
        ['page' => $page, 'sections' => $sections, 'old_slugs' => $oldSlugs],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (!is_string($payload)) {
        return ['ok' => false, 'error' => 'Страницу не удалось поместить в корзину — повторите попытку.', 'notice' => ''];
    }

    $trashId = newId();
    $user = currentUser();

    try {
        tx(static function () use ($trashId, $id, $page, $payload, $user): void {
            insert('trash', [
                'id' => $trashId,
                'kind' => 'page',
                'entity_id' => $id,
                'parent_id' => '',
                'title' => (string) $page['title'],
                'payload' => $payload,
                'deleted_by' => is_array($user) ? (string) $user['id'] : null,
                'deleted_at' => utcStamp(nowUtc()),
            ]);
            run('DELETE FROM ' . t('page_section') . ' WHERE page_id = ?', [$id]);
            run('DELETE FROM ' . t('page_old_slug') . ' WHERE page_id = ?', [$id]);
            run('DELETE FROM ' . t('page') . ' WHERE id = ?', [$id]);
        });
    } catch (Throwable $error) {
        logError('trash.page_failed', ['id' => $id, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Страницу не удалось убрать в корзину — повторите попытку.', 'notice' => ''];
    }

    auditLog('page.trash', 'page', $id, ['slug' => (string) $page['slug']], 'Страница «' . (string) $page['title'] . '» убрана в корзину');
    logInfo('pages.page_trashed', ['id' => $id]);

    return ['ok' => true, 'error' => '', 'notice' => 'page_deleted'];
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function trashSection(string $id): array
{
    $section = sectionById($id);

    if ($section === null) {
        return ['ok' => false, 'error' => 'Секция исчезла — обновите список.', 'notice' => ''];
    }

    $payload = json_encode(['section' => $section], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return ['ok' => false, 'error' => 'Секцию не удалось поместить в корзину — повторите попытку.', 'notice' => ''];
    }

    $trashId = newId();
    $user = currentUser();

    try {
        tx(static function () use ($trashId, $id, $section, $payload, $user): void {
            insert('trash', [
                'id' => $trashId,
                'kind' => 'section',
                'entity_id' => $id,
                'parent_id' => (string) $section['page_id'],
                'title' => (string) $section['title'],
                'payload' => $payload,
                'deleted_by' => is_array($user) ? (string) $user['id'] : null,
                'deleted_at' => utcStamp(nowUtc()),
            ]);
            run('DELETE FROM ' . t('page_section') . ' WHERE id = ?', [$id]);
        });
    } catch (Throwable $error) {
        logError('trash.section_failed', ['id' => $id, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Секцию не удалось убрать в корзину — повторите попытку.', 'notice' => ''];
    }

    auditLog('section.trash', 'section', $id, ['pageId' => (string) $section['page_id']], 'Секция «' . (string) $section['title'] . '» убрана в корзину');
    logInfo('pages.section_trashed', ['id' => $id]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_deleted'];
}

/**
 * @return list<array{id: string, kind: string, rawKind: string, title: string, entity_id: string, parent_id: string, deleted_at: string}>
 */
function trashList(int $limit = 200): array
{
    $rows = [];
    $limit = max(1, min($limit, 500));

    foreach (all('SELECT id, kind, title, entity_id, parent_id, deleted_at FROM ' . t('trash') . ' ORDER BY deleted_at DESC, id DESC LIMIT ' . (string) $limit) as $row) {
        $rows[] = [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'] === 'page' ? 'Страница' : 'Секция',
            'rawKind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'entity_id' => (string) $row['entity_id'],
            'parent_id' => (string) $row['parent_id'],
            'deleted_at' => (string) $row['deleted_at'],
        ];
    }

    return $rows;
}

/** @return array<string, mixed>|null */
function trashById(string $id): ?array
{
    $row = one('SELECT id, kind, entity_id, parent_id, title, payload, deleted_at FROM ' . t('trash') . ' WHERE id = ?', [$id]);

    if ($row === null) {
        return null;
    }

    $decoded = json_decode((string) $row['payload'], true);

    if (!is_array($decoded)) {
        return null;
    }

    $row['data'] = $decoded;

    return $row;
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function trashRestore(string $id): array
{
    $trash = trashById($id);

    if ($trash === null) {
        return ['ok' => false, 'error' => 'Запись корзины не найдена — обновите список.', 'notice' => ''];
    }

    $result = (string) $trash['kind'] === 'page'
        ? trashRestorePage($trash)
        : trashRestoreSection($trash);

    if (($result['ok'] ?? false) === true) {
        run('DELETE FROM ' . t('trash') . ' WHERE id = ?', [$id]);
        auditLog('trash.restore', (string) $trash['kind'], (string) $trash['entity_id'], [], 'Восстановлено из корзины: «' . (string) $trash['title'] . '»');
    }

    return $result;
}

/**
 * @param array<string, mixed> $trash
 * @return array{ok: bool, error: string, notice: string}
 */
function trashRestorePage(array $trash): array
{
    $data = is_array($trash['data'] ?? null) ? $trash['data'] : [];
    $page = is_array($data['page'] ?? null) ? $data['page'] : null;

    if ($page === null) {
        return ['ok' => false, 'error' => 'В корзине нет данных страницы — восстановить нельзя.', 'notice' => ''];
    }

    $id = (string) ($page['id'] ?? '');

    if ($id === '' || pageById($id) !== null) {
        return ['ok' => false, 'error' => 'Страница с таким номером уже существует — обновите корзину.', 'notice' => ''];
    }

    $slug = (string) ($page['slug'] ?? '');

    if (pageSlugTaken($slug)) {
        return ['ok' => false, 'error' => 'Адрес «' . $slug . '» уже занят другой страницей — освободите его и повторите.', 'notice' => ''];
    }

    $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
    $oldSlugs = is_array($data['old_slugs'] ?? null) ? $data['old_slugs'] : [];

    try {
        tx(static function () use ($page, $sections, $oldSlugs): void {
            insert('page', [
                'id' => (string) $page['id'],
                'slug' => (string) $page['slug'],
                'title' => (string) $page['title'],
                'description' => (string) $page['description'],
                'status' => (string) $page['status'],
                'noindex' => (int) $page['noindex'],
                'created_at' => (string) $page['created_at'],
                'updated_at' => (string) $page['updated_at'],
            ]);

            foreach ($sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                insert('page_section', trashSectionRow($section));
            }

            foreach ($oldSlugs as $old) {
                if (!is_array($old) || (string) ($old['slug'] ?? '') === '') {
                    continue;
                }
                // История адреса могла достаться новой владелице: уникальный ключ важнее старой
                // записи, восстанавливать конфликтующую строку нельзя.
                if ((int) value('SELECT COUNT(*) FROM ' . t('page_old_slug') . ' WHERE slug = ?', [(string) $old['slug']]) > 0) {
                    continue;
                }
                insert('page_old_slug', [
                    'slug' => (string) $old['slug'],
                    'page_id' => (string) $page['id'],
                    'created_at' => (string) $old['created_at'],
                ]);
            }
        });
    } catch (Throwable $error) {
        logError('trash.restore_page_failed', ['id' => (string) $page['id'], 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Страница не восстановилась — повторите попытку.', 'notice' => ''];
    }

    logInfo('pages.page_restored', ['id' => $id, 'slug' => $slug]);

    return ['ok' => true, 'error' => '', 'notice' => 'trash_restored'];
}

/**
 * @param array<string, mixed> $trash
 * @return array{ok: bool, error: string, notice: string}
 */
function trashRestoreSection(array $trash): array
{
    $data = is_array($trash['data'] ?? null) ? $trash['data'] : [];
    $section = is_array($data['section'] ?? null) ? $data['section'] : null;

    if ($section === null) {
        return ['ok' => false, 'error' => 'В корзине нет данных секции — восстановить нельзя.', 'notice' => ''];
    }

    $id = (string) ($section['id'] ?? '');
    $pageId = (string) ($section['page_id'] ?? '');

    if ($id === '' || sectionById($id) !== null) {
        return ['ok' => false, 'error' => 'Секция с таким номером уже существует — обновите корзину.', 'notice' => ''];
    }

    if ($pageId !== PAGES_HOME_ID && pageById($pageId) === null) {
        return ['ok' => false, 'error' => 'Страница секции больше не существует — сначала восстановите её.', 'notice' => ''];
    }

    $key = (string) ($section['sec_key'] ?? '');
    if (pageSecKeyTaken($pageId, $key)) {
        $key = ensureUniqueSlug($key !== '' ? $key : 'sekcija', static fn (string $candidate): bool => pageSecKeyTaken($pageId, $candidate), 'sekcija');
        $section['sec_key'] = $key;
    }

    $section['sort'] = (int) value('SELECT COALESCE(MAX(sort), -1) + 1 FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]);

    try {
        insert('page_section', trashSectionRow($section));
    } catch (Throwable $error) {
        logError('trash.restore_section_failed', ['id' => $id, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Секция не восстановилась — повторите попытку.', 'notice' => ''];
    }

    logInfo('pages.section_restored', ['id' => $id, 'pageId' => $pageId]);

    return ['ok' => true, 'error' => '', 'notice' => 'trash_restored'];
}

/**
 * Строка tbl_page_section из снимка секции. JSON-поля принимаются в обоих видах: sectionById()
 * отдаёт их разобранными массивами, а снимок из базы — строками. Без этой развилки массивы
 * приводились бы к строке «Array» и секция теряла свои списки при восстановлении.
 *
 * @param array<string, mixed> $section
 * @return array<string, mixed>
 */
function trashSectionRow(array $section): array
{
    return [
        'id' => (string) $section['id'],
        'page_id' => (string) $section['page_id'],
        'sec_key' => (string) $section['sec_key'],
        'kind' => (string) $section['kind'],
        'layout' => (string) $section['layout'],
        'anchor' => (string) $section['anchor'],
        'badge' => (string) $section['badge'],
        'title' => (string) $section['title'],
        'lead_text' => (string) $section['lead_text'],
        'note' => (string) $section['note'],
        'image' => (string) $section['image'],
        'image_alt' => (string) $section['image_alt'],
        'buttons' => trashJsonField($section['buttons'] ?? []),
        'items' => trashJsonField($section['items'] ?? []),
        'settings' => trashJsonField($section['settings'] ?? []),
        'sort' => (int) $section['sort'],
        'visible' => (int) $section['visible'],
        'updated_at' => (string) $section['updated_at'],
    ];
}

// JSON-поле секции в виде строки для базы: массив кодируется, готовая строка переносится как есть.
function trashJsonField(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }

    $encoded = json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : '[]';
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function trashPurge(string $id): array
{
    $deleted = run('DELETE FROM ' . t('trash') . ' WHERE id = ?', [$id]);

    if ($deleted === 0) {
        return ['ok' => false, 'error' => 'Запись корзины не найдена — обновите список.', 'notice' => ''];
    }

    auditLog('trash.purge', 'trash', $id, [], 'Запись удалена из корзины безвозвратно');

    return ['ok' => true, 'error' => '', 'notice' => 'trash_purged'];
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function trashEmpty(): array
{
    $count = (int) value('SELECT COUNT(*) FROM ' . t('trash'));
    run('DELETE FROM ' . t('trash'));
    auditLog('trash.empty', 'trash', '', ['count' => $count], 'Корзина очищена: ' . $count);

    return ['ok' => true, 'error' => '', 'notice' => 'trash_emptied'];
}
