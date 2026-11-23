<?php

declare(strict_types=1);

// Обмен темой между сайтами: выгрузка настроек в JSON и проверка ввезённого файла.
//
// Почему JSON, а не ZIP с файлами темы. Архив — это распаковка чужого содержимого: Zip Slip
// (запись по `../`), символические ссылки, права файлов, типы содержимого и место, куда всё это
// легло. Перенос оформления не требует файлов вовсе: манифест, палитра и типографика — данные.
// Поэтому здесь нет ни одной операции записи на диск, и защищаться от путей негде.
//
// Пользовательский PHP в перенос не входит по той же причине: тема — код на сервере, а ввозить
// код через форму нельзя. Файл несёт только то, что программа умеет применить списком.
//
// Проверки стоят на входе и на выходе: выгруженный файл обязан проходить проверку ввоза, иначе
// перенос между сайтами не работал бы вовсе (круг «выгрузил → ввезено» ломается молча).

/** Метка формата: чужой JSON не должен «примениться» как тема. */
const THEME_TRANSFER_FORMAT = 'cms-theme';

/** Версия формата. Растёт, когда меняется смысл полей, а не при каждой правке. */
const THEME_TRANSFER_VERSION = 1;

/**
 * Предел размера файла. Настройки темы — это несколько килобайт; мегабайтный JSON означает либо
 * чужой файл, либо попытку занять память разбором.
 */
const THEME_TRANSFER_MAX_BYTES = 262144;

/**
 * Проверка ввезённого файла. Возвращает разобранный перенос с ключом `error`: пустая строка
 * означает «файл годен». Ошибка — значение, а не исключение: экран показывает причину.
 *
 * @return array{error: string, payload: array{manifest: array<string, string>, colors: array<string, string>, fonts: array<string, int>}}
 */
function themeTransferValidate(string $json): array
{
    $empty = ['error' => '', 'payload' => ['manifest' => [], 'colors' => [], 'fonts' => []]];

    // Размер проверяется до разбора: разбирать мегабайт незачем, если файл всё равно отклонят.
    if (strlen($json) > THEME_TRANSFER_MAX_BYTES) {
        return ['error' => 'Размер файла больше предела ' . (int) (THEME_TRANSFER_MAX_BYTES / 1024) . ' КБ', 'payload' => $empty['payload']];
    }

    try {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['error' => 'Файл не разбирается как JSON: ' . $e->getMessage(), 'payload' => $empty['payload']];
    }

    if (!is_array($decoded)) {
        return ['error' => 'Файл обязан быть объектом JSON', 'payload' => $empty['payload']];
    }

    if (($decoded['format'] ?? null) !== THEME_TRANSFER_FORMAT) {
        return ['error' => 'Неизвестный формат файла: ожидается ' . THEME_TRANSFER_FORMAT, 'payload' => $empty['payload']];
    }

    $manifest = is_array($decoded['manifest'] ?? null) ? $decoded['manifest'] : [];
    $parsed = themeManifestParse((string) json_encode($manifest));

    if ($parsed['error'] !== '') {
        // Манифест проверяется тем же правилом, что и при чтении каталога: одна проверка на
        // два входа (файл на диске и ввезённый файл), иначе правила разошлись бы молча.
        return ['error' => 'Манифест не годен: ' . $parsed['error'], 'payload' => $empty['payload']];
    }

    // Цвета и размеры проходят те же правила, что и форма оформления: негодное значение
    // отбрасывается, а не отклоняет файл целиком — терять остальные настройки из-за одной
    // опечатки хуже, чем применить их без неё.
    $colors = [];
    $rawColors = is_array($decoded['colors'] ?? null) ? $decoded['colors'] : [];

    foreach ($rawColors as $key => $value) {
        if (!is_string($key) || !array_key_exists($key, DESIGN_COLOR_DEFAULTS)) {
            // Неизвестный ключ палитры — не цвет темы: такой переменной в CSS не существует.
            continue;
        }

        $color = designColorOf($value);

        if ($color !== null) {
            $colors[$key] = $color;
        }
    }

    $fonts = [];
    $rawFonts = is_array($decoded['fonts'] ?? null) ? $decoded['fonts'] : [];

    foreach ($rawFonts as $key => $value) {
        if (!is_string($key) || !array_key_exists($key, DESIGN_FONT_DEFAULTS)) {
            continue;
        }

        // Ноль и пустое значение означают «как в теме»: ключ не переносится вовсе, иначе
        // переменная печаталась бы с минимальным размером вместо CSS темы.
        if (is_numeric($value) && (int) $value > 0) {
            $fonts[$key] = designSizeOf($key, $value);
        }
    }

    return [
        'error' => '',
        'payload' => [
            // Только известные поля манифеста: перенос не должен нести `hooks`, `routes` или
            // что-то ещё, чего программа не применяет. Иначе ввоз стал бы настройкой сайта.
            'manifest' => [
                'slug' => $parsed['slug'],
                'name' => $parsed['name'],
                'version' => $parsed['version'],
                'description' => $parsed['description'],
                'author' => $parsed['author'],
            ],
            'colors' => $colors,
            'fonts' => $fonts,
        ],
    ];
}

/**
 * Выгрузка темы файлом. Формат тот же, что принимает themeTransferValidate(): круг
 * «выгрузил → ввезено» обязан сходиться, иначе перенос между сайтами не работает.
 *
 * @param array<string, string> $manifest slug, name, version, description, author
 * @param array<string, string> $colors
 * @param array<string, int> $fonts
 */
function themeTransferJson(array $manifest, array $colors, array $fonts): string
{
    return (string) json_encode([
        'format' => THEME_TRANSFER_FORMAT,
        'version' => THEME_TRANSFER_VERSION,
        'manifest' => [
            'slug' => (string) ($manifest['slug'] ?? ''),
            'name' => (string) ($manifest['name'] ?? ''),
            'version' => (string) ($manifest['version'] ?? ''),
            'description' => (string) ($manifest['description'] ?? ''),
            'author' => (string) ($manifest['author'] ?? ''),
        ],
        'colors' => $colors,
        'fonts' => $fonts,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Имя файла выгрузки: slug темы и версия, чтобы два файла не перетёрли друг друга. */
function themeTransferFilename(string $slug, string $version): string
{
    $safeSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?? '';
    $safeVersion = preg_replace('/[^0-9.]/', '', $version) ?? '';

    return 'theme-' . ($safeSlug !== '' ? $safeSlug : 'export') . ($safeVersion !== '' ? '-' . $safeVersion : '') . '.json';
}
