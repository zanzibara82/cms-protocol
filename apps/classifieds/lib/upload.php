<?php

declare(strict_types=1);

// Приём главного изображения объявления. Всё решение о том, что можно сохранить, живёт
// здесь: код загрузки, предел размера и формат по содержимому. Расширение берётся из
// распознанного MIME, а не из имени файла, которое пишет человек.

const CLASSIFIEDS_UPLOAD_PREFIX = '/uploads/images/';

const CLASSIFIEDS_IMAGE_MAX_MB = 5;
const CLASSIFIEDS_IMAGE_MAX_BYTES = CLASSIFIEDS_IMAGE_MAX_MB * 1024 * 1024;

// Соответствие MIME → расширение. Белого списка расширений недостаточно: имя пришедшего
// файла пишет человек, а расширение нужно то, которым файл читается.
/** @var array<string, string> */
const CLASSIFIEDS_IMAGE_MIME_EXT = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function classifiedsUploadDir(): string
{
    return dirname(__DIR__, 3) . '/public/uploads/images';
}

/**
 * Тексты отказов по константам PHP. Человеку нужен совет, а не имя константы.
 */
function classifiedsImageRefusalOf(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Картинка больше разрешения сервера — уменьшите её.',
        UPLOAD_ERR_PARTIAL => 'Картинка дошла не целиком — попробуйте ещё раз.',
        UPLOAD_ERR_NO_FILE => 'Файл не пришёл — выберите его ещё раз.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Серверу некуда принять файл — сообщите администратору.',
        UPLOAD_ERR_EXTENSION => 'Загрузку остановила настройка сервера — сообщите администратору.',
        default => 'Картинку не удалось принять — попробуйте ещё раз.',
    };
}

/**
 * Проверка присланного файла: код, размер, формат по содержимому. На диск ничего не пишется.
 *
 * @param array<string, mixed> $file запись `$_FILES`
 * @return array{ext: string, error: string}
 */
function classifiedsImageInspect(array $file): array
{
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code !== UPLOAD_ERR_OK) {
        return ['ext' => '', 'error' => classifiedsImageRefusalOf($code)];
    }

    if ((int) ($file['size'] ?? 0) > CLASSIFIEDS_IMAGE_MAX_BYTES) {
        return ['ext' => '', 'error' => 'Картинка больше ' . CLASSIFIEDS_IMAGE_MAX_MB . ' МБ — уменьшите её перед загрузкой.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');

    if ($tmp === '' || !is_file($tmp)) {
        return ['ext' => '', 'error' => 'Файл не дошёл до сервера — попробуйте ещё раз.'];
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $ext = CLASSIFIEDS_IMAGE_MIME_EXT[$mime] ?? '';

    if ($ext === '') {
        return ['ext' => '', 'error' => 'Картинкой может быть только jpg, png или webp — этот файл читается как другой формат.'];
    }

    return ['ext' => $ext, 'error' => ''];
}

/**
 * Адрес будущего файла. Имя генерирует `newId()`: присланное имя не участвует, поэтому «.php»
 * в нём не может ни во что вылиться, а два одинаковых файла не перетирают друг друга.
 */
function classifiedsImageHrefOf(string $ext): string
{
    return CLASSIFIEDS_UPLOAD_PREFIX . newId() . '.' . $ext;
}

/**
 * Перенос файла в каталог загрузок. `is_uploaded_file()` обязателен: он единственный
 * отличает настоящий multipart-запрос от пути, который кто-то подставил в `$_FILES` руками.
 *
 * @param array<string, mixed> $file запись `$_FILES`
 * @return array{ok: bool, href: string, error: string}
 */
function classifiedsImageStore(array $file): array
{
    $check = classifiedsImageInspect($file);

    if ($check['error'] !== '') {
        return ['ok' => false, 'href' => '', 'error' => $check['error']];
    }

    $dir = classifiedsUploadDir();

    if (!is_dir($dir) && !mkdir($dir, 02775, true)) {
        return ['ok' => false, 'href' => '', 'error' => 'Каталог загрузок недоступен — сообщите администратору.'];
    }

    $href = classifiedsImageHrefOf($check['ext']);
    $tmp = (string) ($file['tmp_name'] ?? '');

    if (!is_uploaded_file($tmp) || !move_uploaded_file($tmp, $dir . '/' . basename($href))) {
        return ['ok' => false, 'href' => '', 'error' => 'Картинку не удалось сохранить на сервере — попробуйте ещё раз.'];
    }

    return ['ok' => true, 'href' => $href, 'error' => ''];
}

/**
 * Удаление картинки, на которую никто не сослался. Работает только внутри каталога загрузок:
 * адрес, который туда не попадает, — не наш адрес, и трогать его нельзя.
 */
function classifiedsImageDelete(string $href): void
{
    if ($href === '' || !str_starts_with($href, CLASSIFIEDS_UPLOAD_PREFIX) || str_contains($href, '..')) {
        return;
    }

    $path = classifiedsUploadDir() . '/' . basename($href);

    if (is_file($path)) {
        unlink($path);
    }
}
