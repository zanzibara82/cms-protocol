<?php

declare(strict_types=1);

// Проверка входящих данных раздела. Сообщения читают люди, поэтому они по-русски и рядом с тем
// полем, которое не прошло. Zod из Next.js-портала заменён явными проверками: у ядра нет
// рантайм-зависимостей, а пределы берутся из тех же словарей, что читает форма.

// Имена полей формы — один список: при отказе в него возвращают введённый текст, а сервер не
// должен гадать, что пришло из браузера. Список читает formValues() из core/form.php, поэтому
// отдельная newsFormValues() разделу не нужна.
/** @var list<string> */
const NEWS_FORM_FIELDS = [
    'title', 'slug', 'lead', 'cover', 'categoryId', 'body', 'tags', 'status',
    'publishedAt', 'pinned', 'seoTitle', 'seoDescription', 'ogImage', 'noindex',
];

// Пределы строк формы. Предела нет у двух полей: `body` режет parseBody() по абзацам, а `slug`
// обрезает slugify() до NEWS_SLUG_MAX — иначе одна и та же граница жила бы в двух местах.
// `categoryId` ужат до 26 символов против 64 в Next.js-портале: столько занимает строковый
// идентификатор из core/id.php, и более длинное значение не найдёт рубрику.
/** @var array<string, int> */
const NEWS_FORM_MAX = [
    'title' => 120,
    'lead' => 300,
    'cover' => 300,
    'categoryId' => 26,
    'tags' => 600,
    'status' => 16,
    'publishedAt' => 32,
    'pinned' => 8,
    'seoTitle' => 120,
    'seoDescription' => 300,
    'ogImage' => 300,
    'noindex' => 8,
];

// Тексты отказа «длиннее предела». Число не вписано руками: оно подставляется из NEWS_FORM_MAX,
// чтобы предел менялся в одном месте и подпись поля не разъезжалась с проверкой. Там, где текст
// в Next.js-портале писал человек, он перенесён дословно.
/** @var array<string, string> */
const NEWS_FORM_TOO_LONG = [
    'title' => 'Заголовок длиннее %d символов.',
    'lead' => 'Лид длиннее %d символов.',
    'cover' => 'Путь к обложке длиннее %d символов.',
    'categoryId' => 'Рубрика выбрана неверно.',
    'tags' => 'Список тегов слишком длинный — оставьте самые важные.',
    'status' => 'Статус материала неизвестен.',
    'publishedAt' => 'Срок публикации нужно выбрать в поле даты и времени — например, 04.10.2026 09:00.',
    'pinned' => 'Закрепление передано неверно.',
    'seoTitle' => 'Title длиннее %d символов.',
    'seoDescription' => 'Description длиннее %d символов.',
    'ogImage' => 'Путь к картинке для анонса длиннее %d символов.',
    'noindex' => 'Запрет индексации передан неверно.',
];

// Кнопки формы уходят отдельным именем `intent`: скрытое поле с текущим статусом приходит первым,
// и чтение `status` вернуло бы его, а не то, что человек нажал.
/** @var array<string, string> */
const NEWS_STATUS_BY_INTENT = ['draft' => NEWS_STATUS['DRAFT'], 'publish' => NEWS_STATUS['PUBLISHED']];

function newsTooLongText(string $field): string
{
    $template = NEWS_FORM_TOO_LONG[$field] ?? '';

    if ($template === '') {
        return 'Значение поля слишком длинное.';
    }

    return str_contains($template, '%d') ? sprintf($template, NEWS_FORM_MAX[$field] ?? 0) : $template;
}

/**
 * Абзац — блок строк, отделённый пустой строкой. Первая строка вида «## Заголовок» задаёт
 * заголовок абзаца: так контент-менеджер видит структуру в обычном textarea, а на странице
 * собирается вёрстка, знакомая по блоку «Абзацы».
 *
 * @return list<array{title: string, text: string}>
 */
function parseBody(string $raw): array
{
    $source = str_replace(["\r\n", "\r"], "\n", $raw);
    $out = [];
    $limit = fieldLimit(NEWS_FIELDS, 'paragraphs');
    $titleMax = fieldLimit(NEWS_FIELDS, 'paragraphTitle', 'max');
    $textMax = fieldLimit(NEWS_FIELDS, 'paragraphText', 'max');

    foreach (preg_split('/\n{2,}/', $source) ?: [] as $block) {
        $lines = explode("\n", (string) $block);

        // Пустые строки в начале блока — следствие того, как человек раскатил текст: они не должны
        // мешать увидеть заголовок абзаца.
        while ($lines !== [] && trim((string) $lines[0]) === '') {
            array_shift($lines);
        }

        $title = '';

        if ($lines !== [] && str_starts_with(trim((string) $lines[0]), '## ')) {
            $line = mb_substr(trim((string) array_shift($lines)), 3);
            $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $line)), 0, $titleMax);
        }

        $joined = (string) preg_replace('/[ \t]+$/m', '', implode("\n", $lines));
        $text = mb_substr(trim($joined), 0, $textMax);

        if ($title === '' && $text === '') {
            continue;
        }

        $out[] = ['title' => $title, 'text' => $text];

        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/**
 * Обратное превращение — для формы: сохранённый JSON возвращается в textarea тем же текстом,
 * из которого он сложился.
 */
function bodyToFormText(mixed $body): string
{
    $blocks = [];

    foreach (is_array($body) ? $body : [] as $raw) {
        $paragraph = is_array($raw) ? $raw : [];
        $title = trim((string) ($paragraph['title'] ?? ''));
        $text = trim((string) ($paragraph['text'] ?? ''));

        if ($title !== '' && $text !== '') {
            $blocks[] = '## ' . $title . "\n" . $text;
        } elseif ($title !== '' || $text !== '') {
            $blocks[] = $title !== '' ? $title : $text;
        }
    }

    return implode("\n\n", $blocks);
}

/**
 * Выделение в тексте задаётся **звёздочками** — сырого HTML в материале нет, поэтому шаблону
 * нужен список частей, а не готовая разметка (печатает его richText() из core/template.php).
 *
 * @return list<array{text: string, strong: bool}>
 */
function splitBold(string $text): array
{
    $out = [];
    $index = 0;

    foreach (preg_split('/\*\*(.+?)\*\*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
        if ($part !== '') {
            $out[] = ['text' => $part, 'strong' => $index % 2 === 1];
        }

        $index++;
    }

    return $out;
}

/**
 * Теги заводятся прямо из формы материала, поэтому список чистится здесь: лишний пробел или
 * повтор не должны превращаться в новый тег в справочнике. Регистр сохраняется как ввёл человек,
 * а сравнивается в нижнем.
 *
 * @return list<string>
 */
function parseTagList(string $raw): array
{
    $seen = [];
    $out = [];
    $min = fieldLimit(TAG_FIELDS, 'label', 'min');
    $max = fieldLimit(TAG_FIELDS, 'label', 'max');
    $perItem = fieldLimit(TAG_FIELDS, 'perItem');

    foreach (explode(',', $raw) as $part) {
        $label = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $part)), 0, $max);

        if (mb_strlen($label) < $min) {
            continue;
        }

        $key = mb_strtolower($label);

        if (in_array($key, $seen, true)) {
            continue;
        }

        $seen[] = $key;
        $out[] = $label;

        if (count($out) >= $perItem) {
            break;
        }
    }

    return $out;
}

/**
 * Поле datetime-local приносит «2026-10-06T09:00» без часового пояса: время понимается как
 * московское — ровно то, о чём договорён контент-менеджер, назначая публикацию «на девять утра».
 * Разбор делает parseFormDateTime() из core/date.php, здесь остаётся проверка формата поля.
 *
 * @return array{date: DateTimeImmutable|null}|array{error: string}
 */
function parsePublishedAt(string $raw): array
{
    $value = trim($raw);

    if ($value === '') {
        return ['date' => null];
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
        return ['error' => newsTooLongText('publishedAt')];
    }

    $date = parseFormDateTime($value);

    if ($date === null) {
        return ['error' => 'Такого момента не бывает — проверьте дату и время.'];
    }

    return ['date' => $date];
}

/**
 * Обратная сторона parsePublishedAt(): сохранённый момент превращается в значение поля
 * datetime-local. Те же московские часы, что читают openGraph и JSON-LD, — иначе редактор открыл
 * бы форму со временем, которое не совпадает с тем, что стоит на сайте.
 */
function formPublishedAt(?DateTimeImmutable $date): string
{
    return $date === null ? '' : substr(moscowIso($date), 0, 16);
}

// Обложка и картинка анонса — только путь внутри сайта: чужой адрес не пройдёт ни политику CSP
// страницы, ни проверку загрузки, а `//host/path` браузер читает как чужой хост.
function imagePath(string $raw): ?string
{
    $value = mb_substr(trim($raw), 0, fieldLimit(NEWS_FIELDS, 'image', 'max'));

    if ($value === '' || !str_starts_with($value, '/') || str_starts_with($value, '//')) {
        return null;
    }

    return $value;
}

function parseQuery(?string $value): string
{
    return mb_substr(trim((string) $value), 0, SEARCH_MAX_LENGTH);
}

function resolveStatus(string $intent, string $fallback): string
{
    $pressed = NEWS_STATUS_BY_INTENT[$intent] ?? '';

    if ($pressed !== '') {
        return $pressed;
    }

    return in_array($fallback, NEWS_STATUS, true) ? $fallback : NEWS_STATUS['DRAFT'];
}

/**
 * Ошибки возвращаются в порядке NEWS_FORM_FIELDS: форма печатает их сверху вниз, а не в порядке
 * проверок.
 *
 * @param array<string, string> $errors
 * @return array<string, string>
 */
function newsFormErrors(array $errors): array
{
    $out = [];

    foreach (NEWS_FORM_FIELDS as $field) {
        if (($errors[$field] ?? '') !== '') {
            $out[$field] = (string) ($errors[$field] ?? '');
        }
    }

    return $out;
}

/**
 * Одна точка входа для формы материала: пределы полей, абзацы, теги, срок и картинки разбираются
 * здесь, а ошибки возвращаются по именам полей.
 *
 * @param array<string, mixed> $raw
 * @return array{data: array<string, mixed>}|array{errors: array<string, string>}
 */
function parseNewsForm(array $raw): array
{
    $values = [];

    foreach (NEWS_FORM_FIELDS as $field) {
        $value = (string) ($raw[$field] ?? '');

        // `body` не приводится к одной строке и не обрезается: структуру абзацев задают переводы
        // строк, их разбирает parseBody() со своими пределами.
        $values[$field] = $field === 'body' ? $value : trim($value);
    }

    // В заголовке и лиде переноса не бывает: они и в карточке, и в поисковике одной строкой.
    $values['title'] = trim((string) preg_replace('/\s+/u', ' ', $values['title']));
    $values['lead'] = trim((string) preg_replace('/\s+/u', ' ', $values['lead']));
    // Адрес чистится тем же транслитом, что и заголовок: slugify() приводит пробелы к «-»,
    // отбрасывает знаки препинания и обрезает до NEWS_SLUG_MAX.
    $values['slug'] = slugify($values['slug']);

    $errors = [];

    foreach (NEWS_FORM_FIELDS as $field) {
        $max = NEWS_FORM_MAX[$field] ?? 0;

        if ($max > 0 && mb_strlen($values[$field]) > $max) {
            $errors[$field] = newsTooLongText($field);
        }
    }

    $titleMin = fieldLimit(NEWS_FIELDS, 'title', 'min');

    if (mb_strlen($values['title']) < $titleMin) {
        $errors['title'] = 'Заголовок короче ' . $titleMin . ' символов — по нему новость находят в ленте и в поиске.';
    }

    if ($values['categoryId'] === '') {
        $errors['categoryId'] = 'Выберите рубрику: без неё новость не попадёт ни в один раздел.';
    }

    // Адрес может остаться пустым: тогда его соберут из заголовка (newsSlugPlan). Но если человек
    // вписал свой, он обязан читаться как ссылка и не занимать служебный сегмент раздела.
    if ($values['slug'] !== '' && mb_strlen($values['slug']) < NEWS_SLUG_MIN) {
        $errors['slug'] = 'Адрес короче ' . NEWS_SLUG_MIN . ' символов — по нему материал открывают по ссылке.';
    }

    if (in_array($values['slug'], NEWS_RESERVED_SLUGS, true)) {
        $errors['slug'] = 'Этот адрес занят разделом новостей — выберите другой.';
    }

    $published = parsePublishedAt($values['publishedAt']);

    if (isset($published['error'])) {
        $errors['publishedAt'] = (string) $published['error'];
    }

    if ($errors !== []) {
        return ['errors' => newsFormErrors($errors)];
    }

    return [
        'data' => [
            'title' => $values['title'],
            'slug' => $values['slug'],
            'lead' => $values['lead'] !== '' ? $values['lead'] : null,
            'cover' => imagePath($values['cover']),
            'categoryId' => $values['categoryId'],
            'body' => parseBody($values['body']),
            'tags' => parseTagList((string) ($raw['tags'] ?? '')),
            'status' => resolveStatus('', $values['status']),
            'publishedAt' => $published['date'] ?? null,
            'pinned' => isOn((string) ($raw['pinned'] ?? '')),
            'seoTitle' => $values['seoTitle'],
            'seoDescription' => $values['seoDescription'],
            'ogImage' => imagePath($values['ogImage']),
            'noindex' => isOn((string) ($raw['noindex'] ?? '')),
        ],
    ];
}
