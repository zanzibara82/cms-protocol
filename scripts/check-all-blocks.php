<?php

declare(strict_types=1);

// Полная проверка конструктора: страница со всеми видами блоков ядра и приложений, у каждого
// заполнены все поля, какие только умеет показывать его вид. Скрипт не меняет демо-страницы:
// работает только со своим адресом `test-vse-bloki` и глобальным блоком `test-vse-bloki-block`.
//
// По умолчанию печатает план. `--write` создаёт или обновляет тестовую страницу и выводит
// маркеры — по одному на каждый блок; по ним отдельно проверяется публичная отдача страницы:
//   php scripts/check-all-blocks.php --write
//   curl http://localhost:8080/test-vse-bloki | grep -F 'МАРКЕР'
//
// Данные пишутся через createPage()/createSection() — те же правила, что у формы конструктора.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Отказ: ' . $error->getMessage() . "\n");
    exit(1);
});

$write = array_key_exists('write', getopt('', ['write']));
$pageSlug = 'test-vse-bloki';
$blockSlug = 'test-vse-bloki-block';

/**
 * Все виды блоков: 26 ядра плюс вклады приложений через фильтр pages_block_kinds.
 *
 * @return list<string>
 */
function checkAllKinds(): array
{
    $kinds = PAGES_KINDS;

    foreach (applyFilters('pages_block_kinds', []) as $entry) {
        $kind = is_array($entry) ? (string) ($entry['kind'] ?? '') : '';

        if ($kind !== '' && !in_array($kind, $kinds, true)) {
            $kinds[] = $kind;
        }
    }

    // Вклады приложений регистрируются в их routes.php во время HTTP-запроса; в CLI этот файл не
    // выполняется, поэтому два вида из установленных разделов добавляем явно.
    foreach (['news_feed', 'learn_levels'] as $kind) {
        if (isAppActive($kind === 'news_feed' ? 'news' : 'learn') && !in_array($kind, $kinds, true)) {
            $kinds[] = $kind;
        }
    }

    return $kinds;
}

/**
 * Значение подполя строки: картинка и видео получают настоящие пути демо-ассетов, остальные —
 * короткий маркер, по которому потом видно, что поле доехало до базы и до публичной страницы.
 */
function checkItemValue(string $kind, string $key, string $marker): string
{
    if ($key === 'image') {
        return '/assets/longread/hero-student.png';
    }

    if ($key === 'video') {
        return '/assets/demo/demo-lesson.mp4';
    }

    if ($key === 'badge') {
        return 'PDF';
    }

    if ($key === 'href') {
        return '#';
    }

    return $marker . ' — поле ' . $key;
}

/**
 * Настройки вкладов приложений: их читает рендер соответствующего вида.
 *
 * @return list<string>
 */
function checkSettingsFor(string $kind): array
{
    if ($kind === 'news_feed') {
        return ['count', 'category', 'layout'];
    }

    if ($kind === 'learn_levels') {
        return ['count', 'cta_label', 'cta_href'];
    }

    if ($kind === 'global_block') {
        return ['block'];
    }

    return [];
}

/**
 * Все поля секции: общая шапка, примечание, картинка, кнопки, строки и вёрстка. Секция хранит
 * поля, которые её вид не читает, безвредно — конструктор формы их просто не показывает.
 *
 * @return array<string, mixed>
 */
function checkSectionValues(string $kind, string $marker): array
{
    $items = [];
    $itemFields = pagesKindItemFieldsOf($kind);

    for ($index = 0; $index < 3; $index++) {
        $row = [];
        foreach ($itemFields as $field) {
            $key = (string) $field['key'];
            $row[$key] = checkItemValue($kind, $key, $marker . ' ' . ($index + 1));
        }
        $items[] = $row;
    }

    $settings = [];
    if ($kind === 'news_feed') {
        $settings = ['count' => '3', 'category' => '', 'layout' => ''];
    } elseif ($kind === 'learn_levels') {
        $settings = ['count' => '3', 'cta_label' => 'Проверить уровни', 'cta_href' => '/placement-test'];
    } elseif ($kind === 'global_block') {
        $settings = ['block' => 'test-vse-bloki-block'];
    }

    $layout = '';
    if ($kind === 'float') {
        $layout = 'right';
    } elseif ($kind === 'files') {
        $layout = 'cards';
    }

    return [
        'kind' => $kind,
        'anchor' => 'check-' . $kind,
        'badge' => 'МАРКЕР ' . $marker . ' — подзаголовок',
        'title' => 'МАРКЕР ' . $marker . ' — заголовок',
        'lead_text' => 'МАРКЕР ' . $marker . ' — лид, первый абзац.' . "\n\n" . 'МАРКЕР ' . $marker . ' — лид, второй абзац.',
        'note' => 'МАРКЕР ' . $marker . ' — примечание',
        'image' => '/assets/longread/hero-student.png',
        'image_alt' => 'МАРКЕР ' . $marker . ' — описание картинки',
        'buttons' => [
            ['label' => 'МАРКЕР ' . $marker . ' — кнопка 1', 'href' => '#check-' . $kind, 'blank' => ''],
            ['label' => 'МАРКЕР ' . $marker . ' — кнопка 2', 'href' => '/', 'blank' => '1'],
        ],
        'items' => $items,
        'settings' => $settings,
        'layout' => $layout,
        'width' => 'standard',
        'width_custom' => 1200,
        'visible' => true,
    ];
}

$kinds = checkAllKinds();

if (!$write) {
    echo 'План: страница /' . $pageSlug . ', видов блоков ' . count($kinds) . ", запись не производится.\n";
    foreach ($kinds as $kind) {
        echo '  ' . $kind . ' — поля: ' . implode(', ', pagesKindFieldsOf($kind)) . "\n";
    }
    echo "Добавьте --write, чтобы создать страницу.\n";
    exit(0);
}

// Глобальный блок: у административной функции globalBlockSave() проверка прав через сессию,
// которой в CLI нет, поэтому тестовую запись вставляем тем же insert(), что использует ядро.
if (globalBlockBySlug($blockSlug, false) === null) {
    $moment = utcStamp(nowUtc());

    insert('global_block', [
        'id' => newId(),
        'slug' => $blockSlug,
        'title' => 'МАРКЕР global_block — заголовок',
        'subtitle' => 'МАРКЕР global_block — подзаголовок',
        'text' => 'МАРКЕР global_block — текст.',
        'image' => '/assets/longread/hero-student.png',
        'image_alt' => 'МАРКЕР global_block — описание картинки',
        'link' => '/',
        'link_label' => 'МАРКЕР global_block — кнопка',
        'layout' => 'media-left',
        'active' => 1,
        'created_at' => $moment,
        'updated_at' => $moment,
    ]);
}

$page = findPage($pageSlug);

if ($page === null) {
    $created = createPage([
        'title' => 'Тест: все блоки и поля',
        'slug' => $pageSlug,
        'description' => 'Проверка всех видов блоков и всех полей конструктора.',
        'status' => PAGES_STATUS_PUBLISHED,
        'noindex' => '1',
        'layout' => 'default',
    ]);

    if (!$created['ok']) {
        fwrite(STDERR, 'Отказ: ' . $created['error'] . "\n");
        exit(1);
    }

    $page = pageById($created['id']);
} else {
    // Повторный прогон обновляет только свою страницу: демо-контент и чужие адреса не трогаются.
    run('DELETE FROM ' . t('page_section') . ' WHERE page_id = ?', [(string) $page['id']]);
}

$pageId = (string) $page['id'];
$created = 0;
$failed = [];

foreach ($kinds as $kind) {
    $marker = 'МАРКЕР ' . $kind;
    $section = createSection($pageId, checkSectionValues($kind, $marker));

    if (($section['ok'] ?? false) !== true) {
        $failed[] = $kind . ': ' . (string) ($section['error'] ?? 'неизвестный отказ');

        continue;
    }

    $created++;
    echo $marker . "\n";
}

$sections = pageSections($pageId);
$missing = [];

foreach ($sections as $section) {
    $kind = (string) $section['kind'];

    if (($section['title'] ?? '') === '' || ($section['lead_text'] ?? '') === '' || ($section['note'] ?? '') === '') {
        $missing[] = $kind . ': пустая общая часть';
    }

    if ($section['buttons'] === [] || ($section['items'] === [] && pagesKindItemFieldsOf($kind) !== [])) {
        $missing[] = $kind . ': пустые кнопки или строки';
    }

    foreach (pagesKindFieldsOf($kind) as $field) {
        if (!array_key_exists($field, $section)) {
            $missing[] = $kind . ': нет поля ' . $field;
        }
    }

    foreach ($section['buttons'] as $index => $button) {
        foreach (['label', 'href', 'blank'] as $field) {
            if (!array_key_exists($field, (array) $button)) {
                $missing[] = $kind . ': кнопка ' . $index . ' без поля ' . $field;
            }
        }
    }

    foreach (pagesKindItemFieldsOf($kind) as $field) {
        $key = (string) $field['key'];
        foreach ($section['items'] as $index => $item) {
            if (!array_key_exists($key, (array) $item)) {
                $missing[] = $kind . ': строка ' . $index . ' без поля ' . $key;
            }
        }
    }

    foreach (checkSettingsFor($kind) as $key) {
        if (!array_key_exists($key, (array) $section['settings'])) {
            $missing[] = $kind . ': нет настройки ' . $key;
        }
    }
}

echo "\nСоздано блоков: " . $created . ' из ' . count($kinds) . '.';
echo ' В базе: ' . count($sections) . ".\n";

if ($failed !== [] || $missing !== []) {
    foreach (array_merge($failed, $missing) as $problem) {
        fwrite(STDERR, 'ПРОБЛЕМА: ' . $problem . "\n");
    }
    exit(1);
}

echo 'Все поля на месте. Откройте /' . $pageSlug . " и проверьте маркеры в отдаче.\n";
