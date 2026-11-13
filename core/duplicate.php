<?php

declare(strict_types=1);

// Дублирование страниц и секций и буфер обмена секций. Копия — самостоятельная запись: новые
// идентификаторы, новые ключи секций, статус DRAFT у страницы. Никакая копия не наследует
// историю адресов и не публикуется сама.

/**
 * @return array{ok: bool, error: string, notice: string, id: string, slug: string}
 */
function duplicatePage(string $id): array
{
    $page = pageById($id);

    if ($page === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'notice' => '', 'id' => '', 'slug' => ''];
    }

    $base = mb_substr((string) $page['slug'] . '-copy', 0, 70);
    $slug = ensureUniqueSlug($base, static fn (string $candidate): bool => pageSlugTaken($candidate), 'copy');
    $newPageId = newId();
    $moment = utcStamp(nowUtc());
    $title = mb_substr('Копия — ' . (string) $page['title'], 0, 120);

    try {
        tx(static function () use ($page, $newPageId, $slug, $moment, $title): void {
            insert('page', [
                'id' => $newPageId,
                'slug' => $slug,
                'title' => $title,
                'description' => (string) $page['description'],
                'status' => PAGES_STATUS_DRAFT,
                'noindex' => (int) $page['noindex'],
                'created_at' => $moment,
                'updated_at' => $moment,
            ]);

            $sections = all(
                'SELECT id, page_id, sec_key, kind, layout, anchor, badge, title, lead_text, note, image, image_alt,'
                . ' buttons, items, settings, sort, visible, updated_at FROM ' . t('page_section')
                . ' WHERE page_id = ? ORDER BY sort, id LIMIT ' . PAGES_SECTIONS_LIMIT,
                [(string) $page['id']]
            );

            foreach ($sections as $section) {
                $section['id'] = newId();
                $section['page_id'] = $newPageId;
                $section['updated_at'] = $moment;
                insert('page_section', trashSectionRow($section));
            }
        });
    } catch (Throwable $error) {
        logError('pages.duplicate_failed', ['id' => $id, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Копия не создалась — повторите попытку.', 'notice' => '', 'id' => '', 'slug' => ''];
    }

    auditLog('page.duplicate', 'page', $newPageId, ['source' => $id], 'Создана копия страницы «' . (string) $page['title'] . '»');
    logInfo('pages.page_duplicated', ['source' => $id, 'id' => $newPageId]);

    return ['ok' => true, 'error' => '', 'notice' => 'page_duplicated', 'id' => $newPageId, 'slug' => $slug];
}

/**
 * @return array{ok: bool, error: string, notice: string, id: string}
 */
function duplicateSection(string $id): array
{
    $section = sectionById($id);

    if ($section === null) {
        return ['ok' => false, 'error' => 'Секция исчезла — обновите список.', 'notice' => '', 'id' => ''];
    }

    $pageId = (string) $section['page_id'];
    $newId = newId();
    $moment = utcStamp(nowUtc());
    $key = ensureUniqueSlug(
        (string) $section['sec_key'],
        static fn (string $candidate): bool => pageSecKeyTaken($pageId, $candidate),
        'sekcija'
    );

    $copy = $section;
    $copy['id'] = $newId;
    $copy['sec_key'] = $key;
    $copy['title'] = mb_substr((string) $section['title'] . ' (копия)', 0, 160);
    $copy['sort'] = (int) value('SELECT COALESCE(MAX(sort), -1) + 1 FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]);
    $copy['updated_at'] = $moment;

    try {
        insert('page_section', trashSectionRow($copy));
    } catch (Throwable $error) {
        logError('pages.section_duplicate_failed', ['id' => $id, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Копия секции не создалась — повторите попытку.', 'notice' => '', 'id' => ''];
    }

    auditLog('section.duplicate', 'section', $newId, ['source' => $id, 'pageId' => $pageId], 'Создана копия секции «' . (string) $section['title'] . '»');
    logInfo('pages.section_duplicated', ['source' => $id, 'id' => $newId]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_duplicated', 'id' => $newId];
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function clipboardCopy(string $userId, string $sectionId): array
{
    $section = sectionById($sectionId);

    if ($section === null) {
        return ['ok' => false, 'error' => 'Секция исчезла — обновите список.', 'notice' => ''];
    }

    $payload = json_encode(['section' => $section], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return ['ok' => false, 'error' => 'Секция не скопировалась — повторите попытку.', 'notice' => ''];
    }

    try {
        tx(static function () use ($userId, $section, $payload): void {
            run('DELETE FROM ' . t('clipboard') . ' WHERE user_id = ?', [$userId]);
            insert('clipboard', [
                'user_id' => $userId,
                'kind' => 'section',
                'label' => mb_substr((string) $section['title'], 0, 190),
                'payload' => $payload,
                'updated_at' => utcStamp(nowUtc()),
            ]);
        });
    } catch (Throwable $error) {
        logError('clipboard.copy_failed', ['id' => $sectionId, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Секция не скопировалась — повторите попытку.', 'notice' => ''];
    }

    auditLog('section.copy', 'section', $sectionId, [], 'Секция «' . (string) $section['title'] . '» скопирована в буфер');
    logInfo('pages.section_copied', ['id' => $sectionId]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_copied'];
}

/** @return array{label: string, updated_at: string, section: array<string, mixed>}|null */
function clipboardGet(string $userId): ?array
{
    $row = one('SELECT label, payload, updated_at FROM ' . t('clipboard') . ' WHERE user_id = ?', [$userId]);

    if ($row === null) {
        return null;
    }

    $decoded = json_decode((string) $row['payload'], true);
    $section = is_array($decoded) && is_array($decoded['section'] ?? null) ? $decoded['section'] : null;

    if ($section === null) {
        return null;
    }

    return [
        'label' => (string) $row['label'],
        'updated_at' => (string) $row['updated_at'],
        'section' => $section,
    ];
}

/**
 * @return array{ok: bool, error: string, notice: string, id: string}
 */
function clipboardPaste(string $userId, string $pageId): array
{
    if ($pageId !== PAGES_HOME_ID && pageById($pageId) === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'notice' => '', 'id' => ''];
    }

    $clip = clipboardGet($userId);

    if ($clip === null) {
        return ['ok' => false, 'error' => 'Буфер пуст — сначала скопируйте секцию.', 'notice' => '', 'id' => ''];
    }

    $section = $clip['section'];
    $newId = newId();
    $key = ensureUniqueSlug(
        (string) ($section['sec_key'] ?? 'sekcija'),
        static fn (string $candidate): bool => pageSecKeyTaken($pageId, $candidate),
        'sekcija'
    );

    $row = trashSectionRow($section);
    $row['id'] = $newId;
    $row['page_id'] = $pageId;
    $row['sec_key'] = $key;
    $row['sort'] = (int) value('SELECT COALESCE(MAX(sort), -1) + 1 FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]);
    $row['updated_at'] = utcStamp(nowUtc());

    $count = (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]);

    if ($count >= PAGES_SECTIONS_LIMIT) {
        return [
            'ok' => false,
            'error' => 'Секций на странице не больше ' . PAGES_SECTIONS_LIMIT . ' — объедините блоки или заведите новую страницу.',
            'notice' => '',
            'id' => '',
        ];
    }

    try {
        insert('page_section', $row);
    } catch (Throwable $error) {
        logError('clipboard.paste_failed', ['pageId' => $pageId, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Секция не вставилась — повторите попытку.', 'notice' => '', 'id' => ''];
    }

    auditLog('section.paste', 'section', $newId, ['pageId' => $pageId], 'Секция «' . $clip['label'] . '» вставлена из буфера');
    logInfo('pages.section_pasted', ['id' => $newId, 'pageId' => $pageId]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_pasted', 'id' => $newId];
}
