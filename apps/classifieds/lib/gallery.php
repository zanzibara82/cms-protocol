<?php

declare(strict_types=1);

// Дополнительные снимки объявления. Главный кадр живёт полем listing.image, галерея —
// строками tbl_classifieds_listing_image. Файлы принимаются теми же правилами, что и главный:
// сюда не попадает ни один формат, который не читается как jpg/png/webp.

/**
 * @return list<array{id: string, listing_id: string, href: string, sort_index: int}>
 */
function classifiedsListingImages(string $listingId): array
{
    $rows = all(
        'SELECT id, listing_id, href, sort_index FROM ' . t('classifieds_listing_image')
        . ' WHERE listing_id = ? ORDER BY sort_index, id',
        [$listingId]
    );

    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'listing_id' => (string) $row['listing_id'],
            'href' => (string) $row['href'],
            'sort_index' => (int) $row['sort_index'],
        ];
    }

    return $out;
}

/**
 * @return list<string>
 */
function classifiedsListingGalleryHrefs(string $listingId): array
{
    return array_map(
        static fn (array $image): string => (string) $image['href'],
        classifiedsListingImages($listingId)
    );
}

/**
 * Полная замена галереи с сохранением порядка: форма присылает готовый список адресов.
 *
 * @param list<string> $hrefs
 */
function classifiedsReplaceListingImages(string $listingId, array $hrefs, ?DateTimeImmutable $now = null): void
{
    run('DELETE FROM ' . t('classifieds_listing_image') . ' WHERE listing_id = ?', [$listingId]);
    $moment = utcStamp($now ?? nowUtc());
    $index = 0;

    foreach ($hrefs as $href) {
        $href = trim($href);

        if ($href === '') {
            continue;
        }

        insert('classifieds_listing_image', [
            'id' => newId(),
            'listing_id' => $listingId,
            'href' => $href,
            'sort_index' => $index,
            'created_at' => $moment,
        ]);
        $index++;
    }
}

/**
 * Поля файлов `images[]` в список записей `$_FILES`. Пустые строки формы PHP присылает
 * отдельными ошибками NO_FILE — они отбрасываются здесь же.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsUploadedFiles(string $key): array
{
    $files = $_FILES[$key] ?? null;

    if (!is_array($files) || !isset($files['name'])) {
        return [];
    }

    if (!is_array($files['name'])) {
        return (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
    }

    $out = [];
    foreach (array_keys($files['name']) as $index) {
        $file = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];

        if ((int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $out[] = $file;
        }
    }

    return $out;
}

/**
 * Приём новых снимков галереи: сначала проверяем число, потом пишем на диск. Если один файл
 * отвергнут, уже записанные удаляются — форма не оставляет сирот в загрузках.
 *
 * @param string $key поле формы
 * @param int $room сколько ещё снимков влезет
 * @return array{ok: bool, hrefs: list<string>, error: string}
 */
function classifiedsGalleryUpload(string $key, int $room): array
{
    $files = classifiedsUploadedFiles($key);

    if ($files === []) {
        return ['ok' => true, 'hrefs' => [], 'error' => ''];
    }

    if (count($files) > $room) {
        return [
            'ok' => false,
            'hrefs' => [],
            'error' => 'В галерею влезает ещё ' . max(0, $room) . ' ' . plural(max(0, $room), ['снимок', 'снимка', 'снимков']) . ' — лишние не добавлены.',
        ];
    }

    $hrefs = [];

    foreach ($files as $file) {
        $stored = classifiedsImageStore($file);

        if (!$stored['ok']) {
            foreach ($hrefs as $href) {
                classifiedsImageDelete($href);
            }

            return ['ok' => false, 'hrefs' => [], 'error' => $stored['error']];
        }

        $hrefs[] = $stored['href'];
    }

    return ['ok' => true, 'hrefs' => $hrefs, 'error' => ''];
}
