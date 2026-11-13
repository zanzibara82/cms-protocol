<?php

declare(strict_types=1);

// Медиатека: принятые файлы, подписи ALT и контроль использования. Файл физически лежит в том же
// каталоге, что и картинки конструктора, поэтому старые блоки продолжают работать без переезда.
// Удаление разрешено только когда на путь не ссылается ни одна сущность: иначе страница или товар
// остались бы с битой картинкой.

const MEDIA_PAGE_SIZE = 120;

/**
 * @return list<array<string, mixed>>
 */
function mediaRows(string $query = '', int $limit = MEDIA_PAGE_SIZE): array
{
    $q = trim($query);
    $where = '';
    $args = [];

    if ($q !== '') {
        $like = '%' . escapeLike($q) . '%';
        $where = ' WHERE path LIKE ? ESCAPE ? OR title LIKE ? ESCAPE ? OR alt LIKE ? ESCAPE ?';
        $args = [$like, '\\', $like, '\\', $like, '\\'];
    }

    $limit = max(1, min($limit, 500));

    return all(
        'SELECT id, path, title, alt, mime, width, height, bytes, uploaded_by, created_at, updated_at'
        . ' FROM ' . t('media') . $where
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . (string) $limit,
        $args
    );
}

/** @return array<string, mixed>|null */
function mediaById(string $id): ?array
{
    return one(
        'SELECT id, path, title, alt, mime, width, height, bytes, uploaded_by, created_at, updated_at'
        . ' FROM ' . t('media') . ' WHERE id = ?',
        [$id]
    );
}

/**
 * Приём файла в медиатеку. Ошибка записи убирает уже сохранённый файл: строки без файла и файла
 * без строки одинаково не должно оставаться.
 *
 * @param array<string, mixed> $file
 * @return array{ok: bool, error: string, id: string}
 */
function mediaStore(array $file, string $title = '', string $alt = ''): array
{
    $stored = pageImageStore($file);

    if (($stored['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string) ($stored['error'] ?? 'Файл не принят.'), 'id' => ''];
    }

    $href = (string) $stored['href'];
    $path = pageImageDir() . '/' . basename($href);
    $size = @getimagesize($path);
    $mime = is_array($size) ? (string) $size['mime'] : '';
    $id = newId();
    $moment = utcStamp(nowUtc());
    $user = currentUser();

    try {
        insert('media', [
            'id' => $id,
            'path' => $href,
            'title' => mb_substr(trim($title), 0, 190),
            'alt' => mb_substr(trim($alt), 0, 300),
            'mime' => mb_substr($mime, 0, 80),
            'width' => is_array($size) ? (int) $size[0] : 0,
            'height' => is_array($size) ? (int) $size[1] : 0,
            'bytes' => is_file($path) ? (int) filesize($path) : 0,
            'uploaded_by' => is_array($user) ? (string) $user['id'] : null,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
    } catch (Throwable $error) {
        pageImageDelete($href);
        logError('media.store_failed', ['path' => $href, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Файл не записался в медиатеку — повторите попытку.', 'id' => ''];
    }

    auditLog('media.upload', 'media', $id, ['path' => $href], 'Загружен файл ' . $href);

    return ['ok' => true, 'error' => '', 'id' => $id];
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function mediaUpdate(string $id, string $title, string $alt): array
{
    $media = mediaById($id);

    if ($media === null) {
        return ['ok' => false, 'error' => 'Файл исчез — обновите медиатеку.', 'notice' => ''];
    }

    run(
        'UPDATE ' . t('media') . ' SET title = ?, alt = ?, updated_at = ? WHERE id = ?',
        [mb_substr(trim($title), 0, 190), mb_substr(trim($alt), 0, 300), utcStamp(nowUtc()), $id]
    );

    auditLog('media.update', 'media', $id, ['path' => (string) $media['path']], 'Подпись файла обновлена');

    return ['ok' => true, 'error' => '', 'notice' => 'media_saved'];
}

/**
 * Где используется путь. Пустой список означает «можно удалять». Проверяются все сущности,
 * которые умеют ссылаться на картинку; таблица приложения спрашивается только если она есть.
 *
 * @return list<array{label: string, href: string}>
 */
function mediaUsage(string $path): array
{
    $usage = [];
    $like = '%' . escapeLike($path) . '%';

    $sections = all(
        'SELECT s.id, s.page_id, s.title, p.title AS page_title'
        . ' FROM ' . t('page_section') . ' AS s'
        . ' LEFT JOIN ' . t('page') . ' AS p ON p.id = s.page_id'
        . ' WHERE s.image = ? OR s.items LIKE ? ESCAPE ? OR s.settings LIKE ? ESCAPE ?'
        . ' ORDER BY s.page_id, s.sort LIMIT 50',
        [$path, $like, '\\', $like, '\\']
    );

    foreach ($sections as $section) {
        $pageId = (string) $section['page_id'];
        $pageTitle = $pageId === PAGES_HOME_ID ? 'Главная' : (string) ($section['page_title'] ?? $pageId);
        $usage[] = [
            'label' => 'Секция «' . (string) $section['title'] . '» на странице «' . $pageTitle . '»',
            'href' => $pageId === PAGES_HOME_ID ? '/admin/pages/home' : '/admin/pages/' . $pageId,
        ];
    }

    // Снимок корзины хранит секции удалённой страницы, поэтому путь из него тоже занят: удалить
    // файл сейчас — значит восстановить страницу с битой картинкой. Проверка идёт по payload,
    // потому что живых строк секций у страницы в корзине уже нет.
    if (tableExists('trash')) {
        $trashed = all(
            'SELECT id, kind, title FROM ' . t('trash') . ' WHERE payload LIKE ? ESCAPE ? ORDER BY deleted_at DESC LIMIT 20',
            [$like, '\\']
        );
        foreach ($trashed as $row) {
            $kind = (string) $row['kind'] === 'page' ? 'Страница' : 'Секция';
            $usage[] = [
                'label' => 'Корзина: ' . $kind . ' «' . (string) $row['title'] . '»',
                'href' => '/admin/trash',
            ];
        }
    }

    $options = all('SELECT option_name FROM ' . t('option') . ' WHERE option_value = ? ORDER BY option_name LIMIT 20', [$path]);
    foreach ($options as $option) {
        $usage[] = ['label' => 'Настройка сайта «' . (string) $option['option_name'] . '»', 'href' => '/admin/settings'];
    }

    if (tableExists('clipboard')) {
        $clips = all(
            'SELECT user_id, label FROM ' . t('clipboard') . ' WHERE payload LIKE ? ESCAPE ? ORDER BY updated_at DESC LIMIT 20',
            [$like, '\\']
        );
        foreach ($clips as $clip) {
            $usage[] = ['label' => 'Буфер обмена: «' . (string) $clip['label'] . '»', 'href' => '/admin/pages'];
        }
    }

    if (tableExists('news_item')) {
        $news = all(
            'SELECT id, title FROM ' . t('news_item')
            . ' WHERE cover = ? OR og_image = ? OR body LIKE ? ESCAPE ? ORDER BY updated_at DESC LIMIT 20',
            [$path, $path, $like, '\\']
        );
        foreach ($news as $item) {
            $usage[] = ['label' => 'Новость «' . (string) $item['title'] . '»', 'href' => '/news/admin/items/' . (string) $item['id']];
        }
    }

    if (tableExists('commerce_product')) {
        $products = all(
            'SELECT id, title FROM ' . t('commerce_product')
            . ' WHERE cover = ? OR body LIKE ? ESCAPE ? ORDER BY updated_at DESC LIMIT 20',
            [$path, $like, '\\']
        );
        foreach ($products as $product) {
            $usage[] = ['label' => 'Товар «' . (string) $product['title'] . '»', 'href' => '/commerce/admin/products/' . (string) $product['id']];
        }
    }

    if (tableExists('classifieds_listing')) {
        $listings = all(
            'SELECT id, title FROM ' . t('classifieds_listing')
            . ' WHERE image = ? OR body LIKE ? ESCAPE ? ORDER BY updated_at DESC LIMIT 20',
            [$path, $like, '\\']
        );
        foreach ($listings as $listing) {
            $usage[] = ['label' => 'Объявление «' . (string) $listing['title'] . '»', 'href' => '/classifieds/cabinet/' . (string) $listing['id']];
        }
    }

    if (tableExists('seo_meta')) {
        $meta = all(
            'SELECT id, path FROM ' . t('seo_meta') . ' WHERE og_image = ? ORDER BY updated_at DESC LIMIT 20',
            [$path]
        );
        foreach ($meta as $row) {
            $usage[] = ['label' => 'Метаданные страницы ' . (string) $row['path'], 'href' => '/seo/admin/' . (string) $row['id']];
        }
    }

    return $usage;
}

/**
 * @return array{ok: bool, error: string, notice: string, usage: list<array{label: string, href: string}>}
 */
function mediaDelete(string $id): array
{
    $media = mediaById($id);

    if ($media === null) {
        return ['ok' => false, 'error' => 'Файл исчез — обновите медиатеку.', 'notice' => '', 'usage' => []];
    }

    $path = (string) $media['path'];
    $usage = mediaUsage($path);

    if ($usage !== []) {
        return [
            'ok' => false,
            'error' => 'Файл используется — сначала уберите ссылки на него.',
            'notice' => '',
            'usage' => $usage,
        ];
    }

    $file = pageImageDir() . '/' . basename($path);

    if (is_file($file) && !unlink($file)) {
        return ['ok' => false, 'error' => 'Файл не удалился с диска — сообщите администратору.', 'notice' => '', 'usage' => []];
    }

    run('DELETE FROM ' . t('media') . ' WHERE id = ?', [$id]);

    auditLog('media.delete', 'media', $id, ['path' => $path], 'Файл удалён из медиатеки');

    return ['ok' => true, 'error' => '', 'notice' => 'media_deleted', 'usage' => []];
}

/**
 * Импорт файлов, лежащих в каталоге загрузок, но не попавших в медиатеку (например, загруженных
 * до её появления). Существующие строки не трогаются, неизвестные форматы пропускаются.
 *
 * @return array{ok: bool, error: string, notice: string, added: int}
 */
function mediaSync(): array
{
    $dir = pageImageDir();
    $added = 0;

    if (is_dir($dir)) {
        $files = glob($dir . '/*') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $name = basename($file);
            $path = PAGE_IMAGE_PREFIX . $name;

            if (preg_match('/^[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$/', $name) !== 1) {
                continue;
            }

            if ((int) value('SELECT COUNT(*) FROM ' . t('media') . ' WHERE path = ?', [$path]) > 0) {
                continue;
            }

            $size = @getimagesize($file);

            if (!is_array($size)) {
                continue;
            }

            $moment = utcStamp(nowUtc());

            try {
                insert('media', [
                    'id' => newId(),
                    'path' => $path,
                    'title' => '',
                    'alt' => '',
                    'mime' => (string) $size['mime'],
                    'width' => (int) $size[0],
                    'height' => (int) $size[1],
                    'bytes' => (int) filesize($file),
                    'uploaded_by' => null,
                    'created_at' => $moment,
                    'updated_at' => $moment,
                ]);
                $added++;
            } catch (Throwable $error) {
                logWarn('media.sync_row_failed', ['path' => $path, 'message' => $error->getMessage()]);
            }
        }
    }

    if ($added > 0) {
        auditLog('media.sync', 'media', '', ['added' => $added], 'В медиатеку добавлено файлов: ' . $added);
    }

    return ['ok' => true, 'error' => '', 'notice' => 'media_sync_done', 'added' => $added];
}

function mediaBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', ' ') . ' КБ';
    }

    return $bytes . ' Б';
}
