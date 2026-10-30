<?php

declare(strict_types=1);

// Правила слоя страниц: адреса, виды секций, пределы полей. Операции записи и публичный рендер
// добираются следующими задачами — каждая правка входит в ядро только с красным тестом, а у
// читателей и записи он db/http.

// Пределы адреса: колонка tbl_page.slug держит 80 символов, константа читается и формой, и
// проверкой, чтобы подпись поля не расходилась с серверной границей.
const PAGES_SLUG_MIN = 3;
const PAGES_SLUG_MAX = 80;

// Статусы страницы: DRAFT виден только редактору с правом manage (предпросмотр прямым адресом),
// PUBLISHED — всем. Перечень живёт в PHP: CHECK в MySQL 5.7 молча игнорируется (см. шапку релиза).
const PAGES_STATUS_DRAFT = 'DRAFT';
const PAGES_STATUS_PUBLISHED = 'PUBLISHED';

/** @var list<string> */
const PAGES_STATUSES = [PAGES_STATUS_DRAFT, PAGES_STATUS_PUBLISHED];

// Секции главной живут без строки tbl_page (решение 2 штурма): уникальный ключ считает NULL
// всегда разным, поэтому главная — пустая строка вместо NULL.
const PAGES_HOME_ID = '';

/**
 * Первые сегменты, которые страница занять не может: за ними стоят точные маршруты ядра и
 * разделов, а точный маршрут всегда выигрывает у /{slug} по построению сортировки роутера —
 * страница с таким адресом была бы недоступна. Список сверяется стражем PagesSlugGuardTest
 * с фактическими префиксами в файлах routes.php приложений.
 *
 * @var list<string>
 */
const PAGES_RESERVED_SLUGS = ['admin', 'login', 'logout', 'news', 'shop', 'commerce', 'classifieds', 'seo', 'assets', 'uploads'];

// Виды секций первого захода (решение 3 штурма): каркас + семь видов, остальные добором пачками.
// Чужой вид ядро не рисует само — уходит фильтру pages_block_<kind> (вклад приложения).
/** @var list<string> */
const PAGES_KINDS = ['hero', 'band', 'cards', 'steps', 'prose', 'cta', 'faq'];

// Подписи видов в конструкторе: человек выбирает «Большой первый экран», а не «hero».
/** @var array<string, string> */
const PAGES_KIND_NAMES = [
    'hero' => 'Большой первый экран',
    'band' => 'Полоса с текстом',
    'cards' => 'Карточки',
    'steps' => 'Шаги',
    'prose' => 'Текстовый блок',
    'cta' => 'Призыв к действию',
    'faq' => 'Вопросы и ответы',
];

// Пределы полей слоя: их читают и подписи форм, и серверные проверки, чтобы подпись поля не
// расходилась с проверкой (образец — NEWS_FIELDS). Ключи повторяют поля формы: title — заголовок
// страницы (tbl_page.title), sectionTitle — заголовок секции (tbl_page_section.title); числовые
// записи — пределы списков, парные — min/max строк.
/** @var array<string, array<string, int>|int> */
const PAGES_FIELDS = [
    'title' => ['min' => 3, 'max' => 120],
    'description' => ['max' => 200],
    'badge' => ['max' => 80],
    'sectionTitle' => ['max' => 160],
    'leadText' => ['max' => 1000],
    'note' => ['max' => 200],
    'anchor' => ['max' => 40],
    'image' => ['max' => 200],
    'imageAlt' => ['max' => 160],
    'buttonLabel' => ['max' => 60],
    'itemTitle' => ['max' => 120],
    'itemText' => ['max' => 600],
    'items' => 40,
    'buttons' => 4,
];

// Предел секций на одну страницу: конструктор проверяет до вставки и перечитывает при гонке
// (§3 спецификации); у news-блоков предел был в 6 на зону, здесь экран объёмнее.
const PAGES_SECTIONS_LIMIT = 40;

/**
 * План адреса страницы: что положить в базу и что сказать человеку. Форма та же, что у
 * newsSlugPlan: пустое поле адреса — «соберите из заголовка», коллизия решается нумерацией;
 * вписанный руками адрес не нумеруется — человек указал конкретную ссылку, и молча заменить её
 * значит сохранить страницу не туда, куда он просил.
 *
 * Занятость приносит замыкание вызывающего: правило не ходит в базу, поэтому его одинаково
 * проверяют конструктор, обработчик и тест без подключения.
 *
 * @param callable(string): bool $exists занят ли адрес-кандидат другой страницей
 * @return array{slug: string, error: string}
 */
function pageSlugPlan(string $typedSlug, string $title, callable $exists): array
{
    $typed = slugify($typedSlug, PAGES_SLUG_MAX);

    if ($typed !== '') {
        // Занятость служебного сегмента проверяется до длины: короткие адреса «seo» или «shop»
        // человек может вписать целиком, и отказ обязан называть причину — служебный раздел,
        // а не формальную длину.
        if (in_array($typed, PAGES_RESERVED_SLUGS, true)) {
            return [
                'slug' => '',
                'error' => 'Адрес «' . $typed . '» занят служебным разделом — выберите другой.',
            ];
        }

        if (mb_strlen($typed) < PAGES_SLUG_MIN) {
            return [
                'slug' => '',
                'error' => 'Адрес короче ' . PAGES_SLUG_MIN . ' символов — по нему страницу открывают по ссылке.',
            ];
        }

        if ($exists($typed) === true) {
            return ['slug' => '', 'error' => 'По такому адресу уже есть страница — придумайте другой.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = slugify($title, PAGES_SLUG_MAX);

    if (mb_strlen($base) < PAGES_SLUG_MIN) {
        return [
            'slug' => '',
            'error' => 'Адрес не получился: из такого заголовка его не собрать ('
                . PAGES_SLUG_MIN . ' символов минимум). Впишите адрес сами.',
        ];
    }

    return ['slug' => ensureUniqueSlug($base, $exists, 'stranitsa'), 'error' => ''];
}

/**
 * Отказ при переполнении предела секций: отличим от гонок уникальных ключей, чтобы вызывающий
 * не переписывал «занято» на «предел» — это разные причины с разными советами человеку.
 */
final class PagesLimitReached extends \RuntimeException
{
}

/** Занятость адреса страницы: excludeId — правка своей страницы свой адрес не считает занятием. */
function pageSlugTaken(string $slug, string $excludeId = ''): bool
{
    $sql = 'SELECT COUNT(*) FROM ' . t('page') . ' WHERE slug = ?' . ($excludeId !== '' ? ' AND id <> ?' : '');

    return (int) value($sql, $excludeId !== '' ? [$slug, $excludeId] : [$slug]) > 0;
}

/** Занятость ключа секции в пределах одной страницы (решение 2 штурма). */
function pageSecKeyTaken(string $pageId, string $secKey): bool
{
    return (int) value(
        'SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ? AND sec_key = ?',
        [$pageId, $secKey]
    ) > 0;
}

/**
 * Списки секции едут в JSON-колонки с юникодом; пустой список — маркер вида ('[]' или '{}'),
 * а не «null»: шаблон не ветвится на NULL (образец — body материала новостей).
 *
 * @param array<mixed> $rows
 */
function pagesJson(array $rows, string $empty): string
{
    if ($rows === []) {
        return $empty;
    }

    return json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * JSON-колонки секции читаются массивами: шаблон и конструктор не занимаются разбором. Битого
 * JSON в базе не бывает (пишем сами), поэтому битое значение отдаётся пустым списком, а не
 * исключением посреди рендера.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function pagesDecodeRow(array $row): array
{
    $row['buttons'] = json_decode((string) $row['buttons'], true) ?? [];
    $row['items'] = json_decode((string) $row['items'], true) ?? [];
    $row['settings'] = json_decode((string) $row['settings'], true) ?? [];

    return $row;
}

// ---------------------------------------------------------------------------
// Читатели
// ---------------------------------------------------------------------------

/** Страница по адресу, любой статус: решение «показывать ли» принимает рендер, а не читатель.
 * @return array<string, mixed>|null
 */
function findPage(string $slug): ?array
{
    return one(
        'SELECT id, slug, title, description, status, noindex, created_at, updated_at FROM ' . t('page') . ' WHERE slug = ?',
        [$slug]
    );
}

/** Страница по идентификатору, любой статус: правки и конструктор читают по id.
 * @return array<string, mixed>|null
 */
function pageById(string $id): ?array
{
    return one(
        'SELECT id, slug, title, description, status, noindex, created_at, updated_at FROM ' . t('page') . ' WHERE id = ?',
        [$id]
    );
}

/** Секция по идентификатору, JSON-списки разобраны.
 * @return array<string, mixed>|null
 */
function sectionById(string $id): ?array
{
    $row = one(
        'SELECT id, page_id, sec_key, kind, layout, anchor, badge, title, lead_text, note, image,'
        . ' image_alt, buttons, items, settings, sort, visible, updated_at FROM ' . t('page_section') . ' WHERE id = ?',
        [$id]
    );

    return $row === null ? null : pagesDecodeRow($row);
}

/**
 * Секции страницы по порядку. onlyVisible — для публичного рендера; конструктору нужны и скрытые.
 *
 * @return list<array<string, mixed>>
 */
function pageSections(string $pageId, bool $onlyVisible = false): array
{
    $sql = 'SELECT id, page_id, sec_key, kind, layout, anchor, badge, title, lead_text, note, image,'
        . ' image_alt, buttons, items, settings, sort, visible, updated_at FROM ' . t('page_section')
        . ' WHERE page_id = ?' . ($onlyVisible ? ' AND visible = ?' : '')
        . ' ORDER BY sort ASC, id ASC';

    $rows = all($sql, $onlyVisible ? [$pageId, 1] : [$pageId]);

    return array_map(static fn (array $row): array => pagesDecodeRow($row), $rows);
}

/** Секции главной — те же строки с page_id = '' (решение 2 штурма).
 * @return list<array<string, mixed>>
 */
function homeSections(bool $onlyVisible = false): array
{
    return pageSections(PAGES_HOME_ID, $onlyVisible);
}

/** Опубликованные и индексируемые адреса для карты сайтов: черновики и noindex не идут.
 * @return list<string>
 */
function publishedPageSlugs(): array
{
    return array_map(
        static fn (array $row): string => (string) $row['slug'],
        all(
            'SELECT slug FROM ' . t('page') . ' WHERE status = ? AND noindex = ? ORDER BY slug ASC',
            [PAGES_STATUS_PUBLISHED, 0]
        )
    );
}

/**
 * Владелец прежнего адреса — страница, с которой адрес уехал: история живёт у текущего
 * владельца (решение 7 штурма, образец — tbl_news_old_slug).
 *
 * @return array<string, mixed>|null
 */
function pageByOldSlug(string $slug): ?array
{
    return one(
        'SELECT p.id, p.slug, p.status FROM ' . t('page_old_slug') . ' os'
        . ' JOIN ' . t('page') . ' p ON p.id = os.page_id WHERE os.slug = ?',
        [$slug]
    );
}

// ---------------------------------------------------------------------------
// Публичный рендер
// ---------------------------------------------------------------------------

/**
 * Диспетчер секции: известный вид рисует шаблон ядра (theme/sections/<kind>.php), чужой уходит
 * вкладу приложения по фильтру pages_block_<kind> — приложению отдаются настройки секции и её
 * шапка, обратно — готовый HTML. Пустой ответ вклада означает «секция не печатается вовсе»:
 * пустое место честнее чужого блока (образец — вклады портала).
 *
 * @param array<string, mixed> $section строка секции с разобранными JSON-списками
 */
function sectionHtml(array $section): string
{
    $kind = (string) $section['kind'];

    if (in_array($kind, PAGES_KINDS, true)) {
        return partial('sections/' . $kind, ['section' => $section]);
    }

    $head = [
        'anchor' => (string) $section['anchor'],
        'badge' => (string) $section['badge'],
        'title' => (string) $section['title'],
        'lead' => (string) $section['lead_text'],
        'note' => (string) $section['note'],
    ];

    return (string) applyFilters(
        'pages_block_' . $kind,
        '',
        is_array($section['settings'] ?? null) ? $section['settings'] : [],
        $head
    );
}

// ---------------------------------------------------------------------------
// Помощники рендера секций
// ---------------------------------------------------------------------------

/**
 * Лид секции — абзацы через двойной перенос; пустые отрезки и одиночные переносы вокруг текста
 * не создают пустых <p>: шаблон печатает список как есть.
 *
 * @return list<string>
 */
function pagesParagraphs(string $lead): array
{
    $paragraphs = array_map(
        static fn (string $part): string => trim($part),
        explode("\n\n", $lead)
    );

    return array_values(array_filter($paragraphs, static fn (string $part): bool => $part !== ''));
}

/**
 * Кнопки секции: пустые подписи и адреса отбрасываются — кнопка без цели не печатается вовсе,
 * blank приводится к булеву, чтобы шаблон не ветвился на «0»/«1»/null из JSON.
 *
 * @return list<array{label: string, href: string, blank: bool}>
 */
function pagesButtonsOf(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $buttons = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string) ($row['label'] ?? ''));
        $href = trim((string) ($row['href'] ?? ''));

        if ($label === '' || $href === '') {
            continue;
        }

        $buttons[] = [
            'label' => $label,
            'href' => $href,
            'blank' => in_array($row['blank'] ?? '', ['1', 1, true], true),
        ];
    }

    return $buttons;
}

/**
 * Повторяющиеся items секции: битые строки отбрасываются, шаблон получает список массивов
 * и не ветвится на «не массив» посреди цикла.
 *
 * @return list<array<string, mixed>>
 */
function pagesItemsOf(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    /** @var list<array<string, mixed>> $items */
    $items = [];

    foreach ($raw as $row) {
        if (is_array($row)) {
            $items[] = $row;
        }
    }

    return $items;
}

/**
 * Разметка FAQPage для секции «частые вопросы»: собираются только полные пары вопрос-ответ —
 * поисковик выбрасывает страницу из богатых результатов при битых парах. JSON_HEX_TAG экранирует
 * «<»/«>», поэтому «</script>» в тексте ответа не разрывает script-блок. Без полных пар — пустая
 * строка: разметки нет вовсе, а не пустой script. Список приходит нормализованным (pagesItemsOf).
 *
 * @param list<array<string, mixed>> $items
 */
function pagesFaqLd(array $items): string
{
    $entries = [];
    foreach ($items as $row) {
        $question = trim((string) ($row['title'] ?? ''));
        $answer = trim((string) ($row['text'] ?? ''));

        if ($question === '' || $answer === '') {
            continue;
        }

        $entries[] = [
            '@type' => 'Question',
            'name' => $question,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
        ];
    }

    if ($entries === []) {
        return '';
    }

    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $entries,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

    return '<script type="application/ld+json">' . $json . '</script>';
}

/**
 * Фрагмент в <head> от секции (JSON-LD FAQPage). Секция рендерится раньше вывода головы:
 * render() сначала собирает контент в буфер и лишь затем печатает layout с doAction('page_head'),
 * поэтому подписка, вставленная из шаблона секции, успевает встать до срабатывания хука.
 * Пустой фрагмент — молчание: секция без полных пар не добавляет в head ничего.
 */
function pagesHeadFragment(string $html): void
{
    if (trim($html) === '') {
        return;
    }

    addAction('page_head', static function () use ($html): void {
        echo $html; /* @safe html */
    });
}

/**
 * Строки повторяющихся полей формы (кнопки, items): индексы строк и имена полей приходят из
 * разметки (buttons[0][label]), PHP собирает их в массив. Пустые строки отбрасываются целиком —
 * «стереть кнопку» значит «очистить её поля», а пустые списки едут в JSON маркерами вида.
 *
 * @param list<string> $keys имена полей строки, которые едут в список
 * @return list<array<string, string>>
 */
function pagesFormRows(mixed $raw, array $keys): array
{
    if (!is_array($raw)) {
        return [];
    }

    $rows = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }

        $clean = [];
        $filled = false;
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $filled = true;
            }
            $clean[$key] = $value;
        }

        if ($filled) {
            $rows[] = $clean;
        }
    }

    return $rows;
}

/**
 * Перечень видов для селекта конструктора: семь видов ядра плюс вклады приложений, которые
 * отвечают на pages_block_kinds (задача вклада живых новостей). Название вида человек выбирает
 * по подписи, а не по технической метке; поля настроек вклада конструктор рисует формой из
 * этого же ответа — значения ядро хранит как есть и смысла их не понимает.
 *
 * @return list<array{kind: string, name: string, fields: list<array{key: string, label: string, hint: string}>}>
 */
function pagesKindOptions(): array
{
    $options = [];
    $taken = [];
    // Имена видов ядра держатся рядом с перечнем (PAGES_KIND_NAMES): PHPStan знает, что они
    // синхронны, поэтому без «??» — вид без подписи был бы рассинхроном констант.
    foreach (PAGES_KINDS as $kind) {
        $options[] = ['kind' => $kind, 'name' => PAGES_KIND_NAMES[$kind], 'fields' => []];
        $taken[] = $kind;
    }

    foreach (applyFilters('pages_block_kinds', []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $kind = (string) ($entry['kind'] ?? '');
        $name = (string) ($entry['name'] ?? '');

        if ($kind === '' || in_array($kind, $taken, true)) {
            continue;
        }

        $options[] = [
            'kind' => $kind,
            'name' => $name !== '' ? $name : $kind,
            'fields' => pagesFieldsOf($entry['fields'] ?? null),
        ];
        $taken[] = $kind;
    }

    return $options;
}

/**
 * Поля настроек вклада из описания приложения: ключ обязателен, подпись и подсказка приводятся
 * к строке — битые записи описания не роняют конструктор, а пропускаются.
 *
 * @return list<array{key: string, label: string, hint: string}>
 */
function pagesFieldsOf(mixed $raw): array
{
    $fields = [];

    foreach (is_array($raw) ? $raw : [] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $key = (string) ($entry['key'] ?? '');

        if ($key === '') {
            continue;
        }

        $fields[] = [
            'key' => $key,
            'label' => (string) ($entry['label'] ?? ''),
            'hint' => (string) ($entry['hint'] ?? ''),
        ];
    }

    return $fields;
}

/**
 * Настройки вклада из формы: пары ключ-значение едут в JSON как есть — ядро хранит, не понимает
 * (решение 4 штурма). Значения-не-скаляры (вложенные массивы подделанного POST) отбрасываются:
 * вклад получает то, что рисует форма, а не что угодно; смысл значений проверяет вклад.
 *
 * @return array<string, string>
 */
function pagesSettingsOf(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $out = [];

    foreach ($raw as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            $out[(string) $key] = trim((string) $value);
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Операции записи
// ---------------------------------------------------------------------------

/**
 * Создание страницы. Адрес приходит готовым из pageSlugPlan(): проверку занятости и зарезервированных
 * сегментов делает форма; здесь ловится только гонка вставки — соперник мог закоммититься между
 * проверкой и записью.
 *
 * @param array<string, mixed> $values поля формы: title, slug, description, status, noindex
 * @return array{ok: bool, error: string, id: string, slug: string, notice: string}
 */
function createPage(array $values, ?DateTimeImmutable $now = null): array
{
    $slug = trim((string) ($values['slug'] ?? ''));

    if ($slug === '') {
        // Пустой адрес без причины — молчаливая страница на «/»: отказ честнее.
        return ['ok' => false, 'error' => 'Адрес страницы не собран — впишите адрес сами.', 'id' => '', 'slug' => '', 'notice' => ''];
    }

    $id = newId();
    $moment = utcStamp($now ?? nowUtc());

    try {
        insert('page', [
            'id' => $id,
            'slug' => $slug,
            'title' => trim((string) ($values['title'] ?? '')),
            'description' => trim((string) ($values['description'] ?? '')),
            'status' => (string) ($values['status'] ?? PAGES_STATUS_DRAFT),
            'noindex' => (string) ($values['noindex'] ?? '0') === '1' ? 1 : 0,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'error' => duplicateReason(
                $error,
                static fn (): string => pageSlugTaken($slug) ? 'По такому адресу уже есть страница — придумайте другой.' : '',
                'Страница не сохранилась — повторите попытку.'
            ),
            'id' => '',
            'slug' => '',
            'notice' => '',
        ];
    }

    logInfo('pages.page_created', ['id' => $id, 'slug' => $slug]);

    return ['ok' => true, 'error' => '', 'id' => $id, 'slug' => $slug, 'notice' => 'page_created'];
}

/**
 * Правка страницы. Смена адреса — одна транзакция: страница и запись прежнего адреса (образец —
 * saveOldSlugs новостей). Запись «адрес → эта страница» переписывает чужую историю того же адреса:
 * адрес один, и его история живёт у текущего владельца.
 *
 * @param array<string, mixed> $values поля формы: title, slug, description, status, noindex
 * @return array{ok: bool, error: string, slug: string, notice: string}
 */
function updatePage(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $previous = pageById($id);

    if ($previous === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'slug' => '', 'notice' => ''];
    }

    $slug = trim((string) ($values['slug'] ?? ''));

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес страницы не собран — впишите адрес сами.', 'slug' => '', 'notice' => ''];
    }

    $previousSlug = (string) $previous['slug'];
    $slugChanged = $slug !== $previousSlug;
    $moment = utcStamp($now ?? nowUtc());

    try {
        tx(static function () use ($id, $values, $slug, $moment, $slugChanged, $previousSlug): void {
            run(
                'UPDATE ' . t('page') . ' SET slug = ?, title = ?, description = ?, status = ?, noindex = ?, updated_at = ? WHERE id = ?',
                [
                    $slug,
                    trim((string) ($values['title'] ?? '')),
                    trim((string) ($values['description'] ?? '')),
                    (string) ($values['status'] ?? PAGES_STATUS_DRAFT),
                    (string) ($values['noindex'] ?? '0') === '1' ? 1 : 0,
                    $moment,
                    $id,
                ]
            );

            if ($slugChanged) {
                run('DELETE FROM ' . t('page_old_slug') . ' WHERE slug = ?', [$previousSlug]);
                insert('page_old_slug', ['slug' => $previousSlug, 'page_id' => $id, 'created_at' => $moment]);
            }
        });
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'error' => duplicateReason(
                $error,
                static fn (): string => pageSlugTaken($slug, $id) ? 'По такому адресу уже есть страница — придумайте другой.' : '',
                'Страница не сохранилась — повторите попытку.'
            ),
            'slug' => $previousSlug,
            'notice' => '',
        ];
    }

    logInfo('pages.page_updated', ['id' => $id, 'slug' => $slug]);

    return ['ok' => true, 'error' => '', 'slug' => $slug, 'notice' => 'page_saved'];
}

/** Удаление страницы уносит её секции и историю адресов — одной транзакцией.
 * @return array{ok: bool, error: string, notice: string}
 */
function deletePage(string $id): array
{
    if (pageById($id) === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'notice' => ''];
    }

    tx(static function () use ($id): void {
        run('DELETE FROM ' . t('page_section') . ' WHERE page_id = ?', [$id]);
        run('DELETE FROM ' . t('page_old_slug') . ' WHERE page_id = ?', [$id]);
        run('DELETE FROM ' . t('page') . ' WHERE id = ?', [$id]);
    });

    logInfo('pages.page_deleted', ['id' => $id]);

    return ['ok' => true, 'error' => '', 'notice' => 'page_deleted'];
}

/**
 * Приведение флага видимости формы (чекбокс '0'/'1' или булево из кода) к колонке.
 */
function pagesVisibleOf(mixed $raw): int
{
    if (is_bool($raw)) {
        return $raw ? 1 : 0;
    }

    return (string) $raw === '0' ? 0 : 1;
}

/**
 * Добавление секции. Якорь, заданный человеком, и есть ключ секции: дубликат ловится на вставке
 * и называется занятым якорем (авто-ключ из заголовка при коллизии нумеруется молча — человек
 * ничего не просил руками). Предел секций перечитывается внутри транзакции: две открытые формы
 * могли обе пройти проверку до вставки. Порядок — перечитывание max(sort)+1.
 *
 * @param array<string, mixed> $values поля секции: kind, anchor, badge, title, lead_text, note,
 *        image, image_alt, buttons, items, settings, visible
 * @return array{ok: bool, error: string, id: string, notice: string}
 */
function createSection(string $pageId, array $values, ?DateTimeImmutable $now = null): array
{
    // Главная живёт без строки tbl_page, поэтому проверка существования — только у адресных.
    if ($pageId !== PAGES_HOME_ID && pageById($pageId) === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'id' => '', 'notice' => ''];
    }

    $anchor = slugify((string) ($values['anchor'] ?? ''), 40);
    $fromAnchor = $anchor !== '';
    $title = trim((string) ($values['title'] ?? ''));
    $base = $fromAnchor ? $anchor : slugify($title !== '' ? $title : (string) ($values['kind'] ?? ''), 40);
    $secKey = $fromAnchor
        ? $base
        : ensureUniqueSlug($base, static fn (string $candidate): bool => pageSecKeyTaken($pageId, $candidate), 'sekcija');

    $id = newId();
    $moment = utcStamp($now ?? nowUtc());

    try {
        tx(static function () use ($pageId, $values, $id, $secKey, $anchor, $title, $moment): void {
            $count = (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]);

            if ($count >= PAGES_SECTIONS_LIMIT) {
                throw new PagesLimitReached(
                    'Секций на странице не больше ' . PAGES_SECTIONS_LIMIT . ' — объедините блоки или заведите новую страницу.'
                );
            }

            insert('page_section', [
                'id' => $id,
                'page_id' => $pageId,
                'sec_key' => $secKey,
                'kind' => (string) ($values['kind'] ?? ''),
                'layout' => (string) ($values['layout'] ?? ''),
                'anchor' => $anchor,
                'badge' => trim((string) ($values['badge'] ?? '')),
                'title' => $title,
                'lead_text' => (string) ($values['lead_text'] ?? ''),
                'note' => trim((string) ($values['note'] ?? '')),
                'image' => (string) ($values['image'] ?? ''),
                'image_alt' => (string) ($values['image_alt'] ?? ''),
                'buttons' => pagesJson(is_array($values['buttons'] ?? null) ? $values['buttons'] : [], '[]'),
                'items' => pagesJson(is_array($values['items'] ?? null) ? $values['items'] : [], '[]'),
                'settings' => pagesJson(is_array($values['settings'] ?? null) ? $values['settings'] : [], '{}'),
                'sort' => (int) value('SELECT COALESCE(MAX(sort), -1) + 1 FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]),
                'visible' => pagesVisibleOf($values['visible'] ?? 1),
                'updated_at' => $moment,
            ]);
        });
    } catch (Throwable $error) {
        if ($error instanceof PagesLimitReached) {
            return ['ok' => false, 'error' => $error->getMessage(), 'id' => '', 'notice' => ''];
        }

        return [
            'ok' => false,
            'error' => duplicateReason(
                $error,
                static fn (): string => $fromAnchor && pageSecKeyTaken($pageId, $secKey)
                    ? 'Якорь «' . $secKey . '» уже занят другой секцией — выберите другой.'
                    : '',
                'Ключ секции уже занят — повторите сохранение.'
            ),
            'id' => '',
            'notice' => '',
        ];
    }

    logInfo('pages.section_created', ['id' => $id, 'pageId' => $pageId, 'kind' => (string) ($values['kind'] ?? '')]);

    return ['ok' => true, 'error' => '', 'id' => $id, 'notice' => 'section_created'];
}

/**
 * Правка полей секции. Ключ секции заморожен с момента создания: он внутренний идентификатор,
 * а человек правит поля и якорь.
 *
 * @param array<string, mixed> $values поля секции — те же, что у createSection
 * @return array{ok: bool, error: string, notice: string}
 */
function updateSection(string $sectionId, array $values, ?DateTimeImmutable $now = null): array
{
    $previous = sectionById($sectionId);

    if ($previous === null) {
        return ['ok' => false, 'error' => 'Секция исчезла — обновите список.', 'notice' => ''];
    }

    $moment = utcStamp($now ?? nowUtc());

    run(
        'UPDATE ' . t('page_section') . ' SET kind = ?, layout = ?, anchor = ?, badge = ?, title = ?, lead_text = ?,'
        . ' note = ?, image = ?, image_alt = ?, buttons = ?, items = ?, settings = ?, visible = ?, updated_at = ? WHERE id = ?',
        [
            (string) ($values['kind'] ?? ''),
            (string) ($values['layout'] ?? ''),
            slugify((string) ($values['anchor'] ?? ''), 40),
            trim((string) ($values['badge'] ?? '')),
            trim((string) ($values['title'] ?? '')),
            (string) ($values['lead_text'] ?? ''),
            trim((string) ($values['note'] ?? '')),
            (string) ($values['image'] ?? ''),
            (string) ($values['image_alt'] ?? ''),
            pagesJson(is_array($values['buttons'] ?? null) ? $values['buttons'] : [], '[]'),
            pagesJson(is_array($values['items'] ?? null) ? $values['items'] : [], '[]'),
            pagesJson(is_array($values['settings'] ?? null) ? $values['settings'] : [], '{}'),
            pagesVisibleOf($values['visible'] ?? 1),
            $moment,
            $sectionId,
        ]
    );

    logInfo('pages.section_updated', ['id' => $sectionId]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_saved'];
}

/**
 * Перестановка секции: обмен sort с ближайшим соседом в транзакции.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function sectionMove(string $sectionId, string $direction): array
{
    if (!in_array($direction, ['up', 'down'], true)) {
        return ['ok' => false, 'error' => 'Направление перестановки — «вверх» или «вниз».', 'notice' => ''];
    }

    $row = sectionById($sectionId);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Секция исчезла — обновите список.', 'notice' => ''];
    }

    $up = $direction === 'up';
    $neighbor = one(
        'SELECT id, sort FROM ' . t('page_section') . ' WHERE page_id = ? AND sort ' . ($up ? '<' : '>')
        . ' ? ORDER BY sort ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1',
        [(string) $row['page_id'], (int) $row['sort']]
    );

    if ($neighbor === null) {
        return ['ok' => false, 'error' => 'Секция уже крайняя.', 'notice' => ''];
    }

    $neighborId = (string) $neighbor['id'];
    $sort = (int) $row['sort'];
    $neighborSort = (int) $neighbor['sort'];

    tx(static function () use ($sectionId, $neighborId, $sort, $neighborSort): void {
        run('UPDATE ' . t('page_section') . ' SET sort = ? WHERE id = ?', [$neighborSort, $sectionId]);
        run('UPDATE ' . t('page_section') . ' SET sort = ? WHERE id = ?', [$sort, $neighborId]);
    });

    logInfo('pages.section_moved', ['id' => $sectionId, 'direction' => $direction]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_moved'];
}

/**
 * Видимость секции: скрытая остаётся в базе и видна конструктору.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function sectionToggle(string $sectionId, bool $visible): array
{
    $changed = run(
        'UPDATE ' . t('page_section') . ' SET visible = ?, updated_at = ? WHERE id = ?',
        [$visible ? 1 : 0, utcStamp(nowUtc()), $sectionId]
    );

    if ($changed === 0) {
        return ['ok' => false, 'error' => 'Секция исчезла — обновите список.', 'notice' => ''];
    }

    logInfo('pages.section_toggled', ['id' => $sectionId, 'visible' => $visible ? 1 : 0]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_saved'];
}

/** Удаление секции: строка уходит целиком, порядок остальных не трогается.
 * @return array{ok: bool, error: string, notice: string}
 */
function sectionDelete(string $sectionId): array
{
    $deleted = run('DELETE FROM ' . t('page_section') . ' WHERE id = ?', [$sectionId]);

    if ($deleted === 0) {
        return ['ok' => false, 'error' => 'Секция исчезла — обновите список.', 'notice' => ''];
    }

    logInfo('pages.section_deleted', ['id' => $sectionId]);

    return ['ok' => true, 'error' => '', 'notice' => 'section_deleted'];
}
