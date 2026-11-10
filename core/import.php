<?php

declare(strict_types=1);

// Правила переноса данных портала из PostgreSQL в MySQL (спецификация
// docs/superpowers/specs/2026-11-11-postgres-import-design.md, раздел 4).
//
// Здесь только чистые функции: ни чтения файлов, ни записи в базу. Оболочкой служит
// scripts/import-portal.php, который лежит вне области стражей, поэтому всё, что обязано
// быть под стражей, живёт именно тут: разбор ролей, моментов, JSON, путей картинок и строки
// секции страницы.
//
// Идентификаторы cuid портала переносятся как есть (раздел 3): на них держатся JSON-связи
// прогресса ({lessonId}) и ответов теста ({questionId}), поэтому пересоздание id
// потребовало бы переписать все JSON-поля без всякой пользы.

// Роли портала: STUDENT | CONTENT_MANAGER | ADMIN. В CMS перечень уже (ROLES ядра),
// поэтому редактор контента получает editor, а ученик — student.
const IMPORT_ROLES = [
    'ADMIN' => 'admin',
    'CONTENT_MANAGER' => 'editor',
    'STUDENT' => 'student',
];

// Виды секций портала, которых в ядре нет. levels закрыт вкладом курсов (learn_levels),
// demo — механика раздела «Курсы»; при переносе demo становится галереей, потому что его
// содержимое это кадры, а levels переносится как есть и не печатается ядром (вердикт 9.4).
const IMPORT_SECTION_KIND_MAP = ['demo' => 'gallery'];

// Куда копируются картинки портала: у CMS их отдаёт public/assets/longread/.
const IMPORT_ASSET_PREFIX_FROM = '/longread/';
const IMPORT_ASSET_PREFIX_TO = '/assets/longread/';

/**
 * Роль в понятиях CMS. Неизвестная роль — отказ, а не тихая подстановка student: молча
 * понизить администратора страшнее, чем остановить перенос.
 */
function importRole(mixed $raw): string
{
    $code = trim((string) (is_scalar($raw) ? $raw : ''));

    if (!isset(IMPORT_ROLES[$code])) {
        throw new InvalidArgumentException('Неизвестная роль портала: «' . $code . '»');
    }

    return IMPORT_ROLES[$code];
}

/**
 * Момент PostgreSQL → строка DATETIME UTC ('2026-10-01T05:35:04.744' → '2026-10-01 05:35:04').
 * Пустое и мусор дают null: колонки в CMS допускают NULL, а выдуманная дата «сейчас» исказила
 * бы историю. Дробные доли секунды отбрасываются — колонки секундной точности.
 */
function importStamp(mixed $raw): ?string
{
    $value = trim((string) (is_scalar($raw) ? $raw : ''));

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/', $value, $m) !== 1) {
        return null;
    }

    [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6];
}

/**
 * День PostgreSQL → строка DATE ('2026-10-04T05:34:30.775' → '2026-10-04') для колонки
 * последнего занятия в серии: время суток там не хранится.
 */
function importDay(mixed $raw): ?string
{
    $stamp = importStamp($raw);

    return $stamp === null ? null : substr($stamp, 0, 10);
}

/** Приводит значение-перечисление из JSON к строке: null и скаляры читаются, остальное — пусто. */
function importScalar(mixed $raw): string
{
    return is_scalar($raw) ? trim((string) $raw) : '';
}

/**
 * Список в JSON-колонку CMS (JSON NOT NULL): у портала пустое значение это null, а у нас
 * всегда '[]' — шаблон не ветвится на NULL и не печатает пустой цикл дважды.
 *
 * @param mixed $raw список строк из jsonb (или уже строка JSON)
 */
function importJsonList(mixed $raw): string
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($raw)) {
        $raw = [];
    }

    return json_encode(array_values(array_filter($raw, static fn (mixed $row): bool => is_array($row) || is_scalar($row))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

/** Объект в JSON-колонку CMS: у настроек вклада пустое значение — '{}', а не '[]'. */
function importJsonObject(mixed $raw): string
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    return is_array($raw) ? (json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') : '{}';
}

/**
 * Имя пользователя: у CMS колонка обязательна, а у портала имя живёт в отдельной таблице
 * профиля и может отсутствовать. При пустом имени берётся локальная часть почты — человек
 * видит в админке осмысленную подпись, а не пустое место.
 */
function importUserName(mixed $name, string $email): string
{
    $clean = trim((string) (is_scalar($name) ? $name : ''));

    if ($clean === '') {
        $at = mb_strpos($email, '@');
        $clean = $at === false || $at === 0 ? $email : mb_substr($email, 0, $at);
    }

    return mb_substr($clean, 0, 120);
}

/**
 * Путь картинки: портал кладёт картинки лендинга в /longread/, CMS отдаёт переехавшие файлы из
 * /assets/longread/. Чужой адрес (http…), чужой раздел и пустое значение остаются как есть:
 * переписывать чужой путь значит подставлять ссылку, которой в CMS нет.
 */
function importAssetPath(mixed $raw): string
{
    $value = trim((string) (is_scalar($raw) ? $raw : ''));

    if (!str_starts_with($value, IMPORT_ASSET_PREFIX_FROM)) {
        return $value;
    }

    return IMPORT_ASSET_PREFIX_TO . substr($value, mb_strlen(IMPORT_ASSET_PREFIX_FROM));
}

/**
 * Строка секции лендинга портала → строка tbl_page_section.
 *
 * Отличия двух схем: pageId NULL у портала значит «блок главной», в CMS это page_id = ''
 * (пустая строка, а не NULL: уникальный ключ (page_id, sec_key) с NULL пропускал бы дубликаты);
 * key → sec_key (KEY — зарезервированное слово MySQL); lead → lead_text (LEAD тоже).
 *
 * Колонки gallery в CMS нет: кадры это те же items с полем image, поэтому gallery дописывается
 * в конец items с title из text (подпись кадра в CMS — title). Терять кадры нельзя, а порядок
 * «сначала карточки, потом кадры» читается естественно.
 *
 * @param array<string, mixed> $row одна строка LandingSection
 * @param string $id идентификатор новой строки
 * @param string $stamp момент правки в UTC
 * @return array<string, mixed>
 */
function importSectionRow(array $row, string $id, string $stamp): array
{
    $items = $row['items'] ?? null;
    if (is_string($items)) {
        $decoded = json_decode($items, true);
        $items = is_array($decoded) ? $decoded : [];
    }
    $items = is_array($items) ? array_values($items) : [];

    $kind = importScalar($row['kind'] ?? '');
    $kind = IMPORT_SECTION_KIND_MAP[$kind] ?? $kind;

    $gallery = $row['gallery'] ?? null;
    if (is_string($gallery)) {
        $decoded = json_decode($gallery, true);
        $gallery = is_array($decoded) ? $decoded : [];
    }

    // Пути картинок переписываются и внутри items: слайд карусели, карточка уровня и баннер
    // хранят картинку в строке, а не в колонке. Без этого слайд остался бы без image и вид
    // напечатал бы пустое место, хотя данные на месте.
    // Переменная цикла названа $item, а не $row: параметр функции — строка секции, и
    // перезапись его элементом списка подменила бы key/kind/pages элемента вместо секции.
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        foreach (['image', 'video', 'poster'] as $field) {
            if (array_key_exists($field, $item)) {
                $item[$field] = importAssetPath($item[$field]);
            }
        }

        $items[$index] = importItemFields($kind, $item);
    }

    foreach (is_array($gallery) ? $gallery : [] as $frame) {
        if (!is_array($frame)) {
            continue;
        }

        $image = importAssetPath($frame['image'] ?? '');
        $caption = importScalar($frame['text'] ?? '') !== '' ? importScalar($frame['text']) : importScalar($frame['title'] ?? '');

        if ($image === '' && $caption === '') {
            continue;
        }

        $items[] = ['image' => $image, 'title' => $caption];
    }

    $pageId = importScalar($row['pageId'] ?? '');

    return [
        'id' => $id,
        'page_id' => $pageId,
        'sec_key' => mb_substr(importScalar($row['key'] ?? '') !== '' ? importScalar($row['key']) : 'block', 0, 40),
        'kind' => mb_substr($kind, 0, 40),
        'layout' => mb_substr(importScalar($row['layout'] ?? ''), 0, 20),
        'anchor' => mb_substr(importScalar($row['anchor'] ?? ''), 0, 40),
        'badge' => mb_substr(importScalar($row['badge'] ?? ''), 0, 80),
        'title' => mb_substr(importScalar($row['title'] ?? ''), 0, 160),
        'lead_text' => is_scalar($row['lead'] ?? '') ? (string) ($row['lead'] ?? '') : '',
        'note' => mb_substr(importScalar($row['note'] ?? ''), 0, 200),
        'image' => mb_substr(importAssetPath($row['image'] ?? ''), 0, 200),
        'image_alt' => mb_substr(importScalar($row['imageAlt'] ?? ''), 0, 160),
        'buttons' => importJsonList($row['buttons'] ?? null),
        'items' => importJsonList($items),
        'settings' => importJsonObject($row['settings'] ?? null),
        'sort' => (int) (is_numeric($row['order'] ?? null) ? $row['order'] : 0),
        'visible' => in_array($row['visible'] ?? false, [true, 1, '1', 't', 'true'], true) ? 1 : 0,
        'updated_at' => $stamp,
    ];
}

/**
 * Запись строки переноса в таблицу CMS с идемпотентностью.
 *
 * Повторный запуск обязан быть безопасным: перенос идёт в живую базу, где после первого прогона
 * уже живут правки администратора. Поэтому строка с существующим id не перезаписывается, а
 * сверяется: совпала — «уже есть», разошлась — «в базе другое значение» и запись в отчёт
 * «Требуют внимания». Затирать правку, сделанную в CMS после переноса, нельзя: это потеря работы
 * человека, а не «повторная синхронизация».
 *
 * @param string $table имя таблицы без префикса
 * @param array<string, mixed> $row строка для вставки (id обязателен)
 * @param array{read: int, written: int, skipped: array<string, int>} $stat
 * @return bool истина, если строку записали
 */
function importUpsert(string $table, array $row, array &$stat): bool
{
    $stat['read']++;

    $id = (string) ($row['id'] ?? '');
    if ($id === '') {
        $stat['skipped']['без идентификатора'] = ($stat['skipped']['без идентификатора'] ?? 0) + 1;

        return false;
    }

    // Явный список колонок вместо SELECT *: страж PerformanceTest запрещает звёздочку в
    // продакшене, а для сверки нужны ровно те колонки, которые перенос собирается записать.
    $columns = array_keys($row);
    foreach ($columns as $column) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $column) !== 1) {
            throw new InvalidArgumentException('Недопустимое имя колонки: ' . (string) $column);
        }
    }

    $existing = one(
        'SELECT ' . implode(', ', $columns) . ' FROM ' . t($table) . ' WHERE id = ?',
        [$id]
    );

    if ($existing !== null) {
        $same = true;
        foreach ($row as $column => $value) {
            // JSON-колонки сравниваются по нормализованному значению: json_encode не обязан
            // сохранять порядок ключей источника, и побайтовое сравнение объявляло бы
            // «другое значение» у одинаковых по смыслу строк.
            $current = $existing[$column] ?? '';
            if (importJsonNormalized($current) !== importJsonNormalized($value)) {
                $same = false;
                break;
            }
        }

        $reason = $same ? 'уже есть' : 'в базе другое значение';
        $stat['skipped'][$reason] = ($stat['skipped'][$reason] ?? 0) + 1;

        return false;
    }

    insert($table, $row);
    $stat['written']++;

    return true;
}

/**
 * Родитель строки найден? Строка, чьего родителя в источнике нет (урок без модуля, вопрос без
 * теста), не переносится: связь потеряна в самом источнике, и выдумывать родителя нельзя —
 * такая строка попадёт в отчёт «Требуют внимания» и станет видимой правкой.
 *
 * Проверка идёт по набору идентификаторов ИСТОЧНИКА, а не запросом в базу: в режиме проверки
 * (без --write) база пуста, и любой запрос в неё объявил бы сиротами все строки — отчёт показал бы
 * 2482 ложные находки вместо честной картины. Набор собирает importIds() из той же выгрузки.
 *
 * @param array<string, true> $knownIds идентификаторы родительской таблицы из источника
 * @param array{read: int, written: int, skipped: array<string, int>} $stat
 */
function importOrphans(array $knownIds, mixed $parentId, array &$stat): bool
{
    $id = trim((string) (is_scalar($parentId) ? $parentId : ''));

    if ($id === '') {
        return false;
    }

    if (isset($knownIds[$id]) === false) {
        $stat['skipped']['родитель не найден'] = ($stat['skipped']['родитель не найден'] ?? 0) + 1;

        return false;
    }

    return true;
}

/**
 * Поля строки под вид блока ядра: у портала и CMS одна и та же мысль иногда лежит в разных
 * ключах строки, и без перекладки вид печатался бы пустым, хотя данные на месте.
 *
 * Известные расхождения (сверено с шаблонами theme/sections/*):
 * - «Цепочка шагов»: подпись шага у портала в text, у ядра — в title;
 * - «Видео»: ссылка на файл у портала в items[].video (или text), у ядра — в video.
 *
 * Остальные виды совпадают по ключам: title, text, note, image, href, code, lines.
 *
 * @param array<string, mixed> $item строка items
 * @return array<string, mixed>
 */
function importItemFields(string $kind, array $item): array
{
    if ($kind === 'chain') {
        $title = importScalar($item['title'] ?? '');
        $text = importScalar($item['text'] ?? '');

        if ($title === '' && $text !== '') {
            $item['title'] = $text;
            unset($item['text']);
        }

        return $item;
    }

    if ($kind === 'video') {
        $video = importScalar($item['video'] ?? '');

        // Поле video в портале почти всегда пустое, а ссылка на файл лежит в text подсказки
        // («…или файл сайта, например /videos/promo.mp4»). Берётся только то, что похоже на
        // путь файла видео: подсказку с чужим доменом плеер всё равно не примет.
        if ($video === '') {
            $candidate = importVideoFromText(importScalar($item['text'] ?? ''));
            if ($candidate !== '') {
                $item['video'] = $candidate;
            }
        }

        return $item;
    }

    return $item;
}

/**
 * Путь видео из текста подсказки: последний фрагмент, начинающийся с «/» и кончающийся
 * видео-расширением. Пусто — подсказки без файла (у портала там адрес площадки).
 */
function importVideoFromText(string $text): string
{
    if (preg_match('~(/[\w./-]+\.(?:mp4|webm|ogv|ogg))~i', $text, $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

/**
 * Значение колонки для сравнения при повторном переносе. JSON нормализуется с сортировкой ключей
 * и одинаковыми флагами: у источника jsonb порядок ключей свой, у нас — порядок вставки, а
 * смысл один и тот же. NULL и пустая строка считаются одним значением: колонка могла получить
 * '' там, где источник даёт NULL, и это не расхождение.
 */
function importJsonNormalized(mixed $value): string
{
    $text = trim((string) (is_scalar($value) ? $value : ''));

    if ($text === '' || $text === 'null') {
        return '';
    }

    $decoded = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $text;
    }

    return importJsonSortKeys($decoded);
}

/** Рекурсивно сортирует ключи массива: сравнение JSON не должно зависеть от порядка. */
function importJsonSortKeys(mixed $value): string
{
    if (is_array($value)) {
        $isList = array_is_list($value);
        if (!$isList) {
            ksort($value);
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = ($isList ? '' : (string) $key . ':') . importJsonSortKeys($item);
        }

        return '[' . implode(',', $parts) . ']';
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
}

/**
 * Набор идентификаторов таблицы источника: по нему importOrphans() судит сирот, не заглядывая
 * в базу. Так режим проверки (без --write) показывает ту же картину, что и запись, — иначе
 * пустая база объявила бы сиротами все строки.
 *
 * @param list<array<string, mixed>> $rows строки выгрузки
 * @return array<string, true>
 */
function importIds(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['id'] ?? ''));

        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    return $ids;
}

/**
 * Счётчик отчёта: «прочитано / перенесено / пропущено» и причины пропуска списком.
 * Одна строка на таблицу — человек читает отчёт глазами и обязан видеть, что именно потеряно.
 *
 * @param array{read: int, written: int, skipped: array<string, int>} $stat
 */
function importStatLine(string $table, array $stat): string
{
    $skipped = 0;
    $reasons = [];
    foreach ($stat['skipped'] as $reason => $count) {
        $skipped += $count;
        $reasons[] = $reason . ' ' . $count;
    }

    $tail = $reasons === [] ? '—' : implode(', ', $reasons);

    return sprintf('%s: прочитано %d, перенесено %d, пропущено %d (%s)', $table, $stat['read'], $stat['written'], $skipped, $tail);
}
