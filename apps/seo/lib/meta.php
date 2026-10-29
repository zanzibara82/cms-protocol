<?php

declare(strict_types=1);

// Метаданные страниц (S1). Строка описывает один адрес сайта: заголовок и описание для
// сниппета, канонический адрес, директивы robots, карточку соцсети и два флага — закрыт ли
// адрес от индексации и входит ли он в карту сайта. Ключ строки — сам адрес: метаданные
// принадлежат пути, а не материалу, поэтому ими можно править любую страницу раздела, не
// залезая в его собственные таблицы.
//
// Правило раздела: он умеет делать страницу строже (закрыть от индексации, задать каноник),
// но никогда — мягче. Страница, помеченная ядром (админка, вход, кабинет), остаётся закрытой:
// снятая метка сломала бы и запрет хранения ответа, который ставит ветка noindex.

// Директивы robots, которые вправе выбрать человек. Свой набор в поле не пускаем: опечатка
// в директиве молча делает страницу открытой для индексации — цена ошибки тут несимметрична.
const SEO_META_ROBOTS_OPTIONS = [
    '' => 'Как у страницы',
    'index, follow' => 'Индексировать, ссылкам следовать',
    'index, nofollow' => 'Индексировать, ссылкам не следовать',
    'noindex' => 'Не индексировать',
    'noindex, follow' => 'Не индексировать, ссылкам следовать',
    'noindex, nofollow' => 'Не индексировать, ссылкам не следовать',
];

const SEO_META_PATH_MAX = 255;
const SEO_META_TITLE_MAX = 255;
const SEO_META_DESCRIPTION_MAX = 500;

// Рекомендации, а не ограничения: длинный заголовок поисковик обрежет сам, и обрезанный
// заголовок лучше длинного, чем отказ принимать правку.
const SEO_META_TITLE_RECOMMENDED = 60;
const SEO_META_DESCRIPTION_RECOMMENDED = 160;

/**
 * Нормализованный адрес страницы: с ведущим слэшем, без хвостового и без дублей слэшей.
 * Корневой адрес остаётся «/».
 */
function seoMetaPath(string $path): string
{
    $clean = trim($path);

    if ($clean === '') {
        return '';
    }

    $clean = (string) preg_replace('#/+#', '/', $clean);

    if (!str_starts_with($clean, '/')) {
        $clean = '/' . $clean;
    }

    if ($clean !== '/') {
        $clean = rtrim($clean, '/');
    }

    return $clean === '' ? '/' : $clean;
}

/**
 * Строка метаданных по адресу. Адрес ищется нормализованным: «/news/» и «/news» — одна
 * страница, и две строки на один адрес завели бы метаданные в разные стороны.
 *
 * @return array<string, mixed>|null
 */
function seoMetaRow(string $path): ?array
{
    $clean = seoMetaPath($path);

    if ($clean === '') {
        return null;
    }

    return one(
        'SELECT id, path, title, description, canonical, robots, og_title, og_description, og_image,'
        . ' noindex, in_sitemap, created_at, updated_at FROM ' . t('seo_meta') . ' WHERE path = ?',
        [$clean]
    );
}

/**
 * Строка метаданных по идентификатору: форма правки открывается по номеру записи, а не по
 * адресу страницы.
 *
 * @return array<string, mixed>|null
 */
function seoMetaById(string $id): ?array
{
    return one(
        'SELECT id, path, title, description, canonical, robots, og_title, og_description, og_image,'
        . ' noindex, in_sitemap, created_at, updated_at FROM ' . t('seo_meta') . ' WHERE id = ?',
        [$id]
    );
}

/**
 * Все строки метаданных: адрес по алфавиту, чтобы список читался как справочник.
 *
 * @return list<array<string, mixed>>
 */
function seoMetaList(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT id, path, title, description, canonical, robots, og_title, og_description, og_image,'
        . ' noindex, in_sitemap, created_at, updated_at FROM ' . t('seo_meta') . ' ORDER BY path'
    );

    return $rows;
}

/**
 * Проверка полей формы. Возвращает ошибки по именам полей и нормализованные значения.
 * Ограничения на длину — пределы колонок: обрезанный наполовину заголовок хуже отказа.
 *
 * @param array<string, mixed> $post
 * @return array{errors: array<string, string>, values: array{path: string, title: string, description: string, canonical: string, robots: string, og_title: string, og_description: string, og_image: string, noindex: int, in_sitemap: int}}
 */
function seoMetaValidate(array $post): array
{
    $errors = [];

    $rawPath = trim((string) ($post['path'] ?? ''));
    $path = seoMetaPath($rawPath);
    $title = trim((string) ($post['title'] ?? ''));
    $description = trim((string) ($post['description'] ?? ''));
    $canonical = trim((string) ($post['canonical'] ?? ''));
    $robots = trim((string) ($post['robots'] ?? ''));
    $ogTitle = trim((string) ($post['og_title'] ?? ''));
    $ogDescription = trim((string) ($post['og_description'] ?? ''));
    $ogImage = trim((string) ($post['og_image'] ?? ''));
    $noindex = ($post['noindex'] ?? '') === '1' ? 1 : 0;
    $inSitemap = ($post['in_sitemap'] ?? '') === '1' ? 1 : 0;

    if ($rawPath === '') {
        $errors['path'] = 'Укажите адрес страницы — без него метаданные некуда положить.';
    } elseif (str_contains($rawPath, '?') || str_contains($rawPath, '#')) {
        $errors['path'] = 'Адрес указывается без параметров и якоря: «?» и «#» в адрес не входят.';
    } elseif (preg_match('/\s/u', $rawPath) === 1) {
        $errors['path'] = 'В адресе не должно быть пробелов.';
    } elseif (!str_starts_with($rawPath, '/')) {
        $errors['path'] = 'Адрес начинается с «/» — так он выглядит в строке браузера.';
    } elseif (mb_strlen($path) > SEO_META_PATH_MAX) {
        $errors['path'] = 'Адрес длиннее ' . SEO_META_PATH_MAX . ' знаков.';
    }

    if (mb_strlen($title) > SEO_META_TITLE_MAX) {
        $errors['title'] = 'Заголовок длиннее ' . SEO_META_TITLE_MAX . ' знаков.';
    }

    if (mb_strlen($description) > SEO_META_DESCRIPTION_MAX) {
        $errors['description'] = 'Описание длиннее ' . SEO_META_DESCRIPTION_MAX . ' знаков.';
    }

    if ($canonical !== '' && !seoMetaIsOurAddress($canonical)) {
        $errors['canonical'] = 'Канонический адрес должен вести на наш сайт: путь от корня или полный адрес нашего домена.';
    }

    if (!isset(SEO_META_ROBOTS_OPTIONS[$robots])) {
        $errors['robots'] = 'Выберите директиву из списка: своя строка в поле молча сделает страницу открытой.';
    }

    if (mb_strlen($ogTitle) > SEO_META_TITLE_MAX) {
        $errors['og_title'] = 'Заголовок карточки длиннее ' . SEO_META_TITLE_MAX . ' знаков.';
    }

    if (mb_strlen($ogDescription) > SEO_META_DESCRIPTION_MAX) {
        $errors['og_description'] = 'Описание карточки длиннее ' . SEO_META_DESCRIPTION_MAX . ' знаков.';
    }

    if ($ogImage !== '' && !seoMetaIsOurAddress($ogImage)) {
        $errors['og_image'] = 'Картинка карточки должна лежать на нашем сайте: путь от корня или полный адрес нашего домена.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'path' => $path,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $robots,
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'og_image' => $ogImage,
            'noindex' => $noindex,
            'in_sitemap' => $inSitemap,
        ],
    ];
}

/**
 * Адрес ли это нашего сайта: путь от корня или полный адрес нашего домена. Чужой домен в
 * канонике или в картинке означает, что страница объявляет себя чужой.
 */
function seoMetaIsOurAddress(string $address): bool
{
    $clean = trim($address);

    if ($clean === '') {
        return false;
    }

    if (str_starts_with($clean, '/')) {
        return true;
    }

    $base = rtrim((string) configValue('SITE_URL', ''), '/');

    if ($base === '') {
        return false;
    }

    return str_starts_with($clean, $base . '/') || $clean === $base;
}

/**
 * Запись метаданных: новая строка или правка существующей. Адрес уникален — вторая строка
 * на тот же путь заменила бы первую молча, поэтому чужая занимает адрес, и об этом говорят.
 *
 * @param array{path: string, title: string, description: string, canonical: string, robots: string, og_title: string, og_description: string, og_image: string, noindex: int, in_sitemap: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function seoMetaSave(array $values, string $id = ''): array
{
    $path = seoMetaPath((string) $values['path']);

    if ($path === '') {
        return ['ok' => false, 'error' => 'Укажите адрес страницы.', 'notice' => ''];
    }

    $taken = one('SELECT id FROM ' . t('seo_meta') . ' WHERE path = ?', [$path]);

    if ($taken !== null && (string) $taken['id'] !== $id) {
        return ['ok' => false, 'error' => 'Такой адрес уже есть в метаданных — правьте ту строку.', 'notice' => ''];
    }

    $moment = utcStamp(nowUtc());

    if ($id === '') {
        // Select-then-insert гонится в отказ двумя одновременными сохранениями: оба прочли
        // «нет строки», и проигравший INSERT получает отказ уникального ключа. Обещание
        // раздела «чужой адрес занимает адрес, и об этом говорят» держится и в гонке:
        // проигравшему возвращается та же причина, что и у спокойной проверки.
        try {
            insert('seo_meta', [
                'id' => newId(),
                'path' => $path,
                'title' => $values['title'] !== '' ? $values['title'] : null,
                'description' => $values['description'] !== '' ? $values['description'] : null,
                'canonical' => $values['canonical'] !== '' ? $values['canonical'] : null,
                'robots' => $values['robots'] !== '' ? $values['robots'] : null,
                'og_title' => $values['og_title'] !== '' ? $values['og_title'] : null,
                'og_description' => $values['og_description'] !== '' ? $values['og_description'] : null,
                'og_image' => $values['og_image'] !== '' ? $values['og_image'] : null,
                'noindex' => $values['noindex'],
                'in_sitemap' => $values['in_sitemap'],
                'created_at' => $moment,
                'updated_at' => $moment,
            ]);
        } catch (Throwable $insertError) {
            if (!duplicateKeyException($insertError)) {
                throw $insertError;
            }

            return ['ok' => false, 'error' => 'Такой адрес уже есть в метаданных — правьте ту строку.', 'notice' => ''];
        }

        return ['ok' => true, 'error' => '', 'notice' => 'meta_saved'];
    }

    // Правка меняет адрес: правка и чужая новая строка могут претендовать на один путь.
    try {
        run(
            'UPDATE ' . t('seo_meta')
            . ' SET path = ?, title = ?, description = ?, canonical = ?, robots = ?, og_title = ?,'
            . ' og_description = ?, og_image = ?, noindex = ?, in_sitemap = ?, updated_at = ? WHERE id = ?',
            [
                $path,
                $values['title'] !== '' ? $values['title'] : null,
                $values['description'] !== '' ? $values['description'] : null,
                $values['canonical'] !== '' ? $values['canonical'] : null,
                $values['robots'] !== '' ? $values['robots'] : null,
                $values['og_title'] !== '' ? $values['og_title'] : null,
                $values['og_description'] !== '' ? $values['og_description'] : null,
                $values['og_image'] !== '' ? $values['og_image'] : null,
                $values['noindex'],
                $values['in_sitemap'],
                $moment,
                $id,
            ]
        );
    } catch (Throwable $updateError) {
        if (!duplicateKeyException($updateError)) {
            throw $updateError;
        }

        return ['ok' => false, 'error' => 'Такой адрес уже есть в метаданных — правьте ту строку.', 'notice' => ''];
    }

    return ['ok' => true, 'error' => '', 'notice' => 'meta_saved'];
}

/**
 * Удаление строки метаданных. Страница при этом возвращается к своим собственным
 * заголовку и описанию — исчезают только правки человека.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function seoMetaDelete(string $id): array
{
    $row = seoMetaById($id);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Такой записи уже нет — обновите список.', 'notice' => ''];
    }

    run('DELETE FROM ' . t('seo_meta') . ' WHERE id = ?', [$id]);

    return ['ok' => true, 'error' => '', 'notice' => 'meta_deleted'];
}

/**
 * Метка для соцсети: атрибут name или property — разные вещи, путать их нельзя.
 */
function seoMetaTag(string $name, string $content, bool $property = false): string
{
    return sprintf('<meta %s="%s" content="%s">', $property ? 'property' : 'name', e($name), e($content));
}

/**
 * Теги страницы: канонический адрес, карточка соцсети и карточка твиттера. Пустые значения
 * не печатаются: тег с пустым content хуже отсутствующего тега — он читается как заявка.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $meta заголовок и описание страницы после правок
 */
function seoMetaHeadTags(array $row, array $meta = []): string
{
    $path = seoMetaPath((string) ($row['path'] ?? ''));

    if ($path === '') {
        return '';
    }

    $canonical = trim((string) ($row['canonical'] ?? ''));
    $canonicalUrl = $canonical === '' ? absoluteUrl($path) : seoMetaAbsoluteAddress($canonical);
    $title = trim((string) ($row['og_title'] ?? ''));

    if ($title === '') {
        $title = trim((string) ($row['title'] ?? ''));
    }

    if ($title === '') {
        $title = trim((string) ($meta['title'] ?? ''));
    }

    $description = trim((string) ($row['og_description'] ?? ''));

    if ($description === '') {
        $description = trim((string) ($row['description'] ?? ''));
    }

    if ($description === '') {
        $description = trim((string) ($meta['description'] ?? ''));
    }

    $image = trim((string) ($row['og_image'] ?? ''));

    $tags = [
        '<link rel="canonical" href="' . e($canonicalUrl) . '">',
        seoMetaTag('og:type', 'website', true),
        seoMetaTag('og:url', absoluteUrl($path), true),
        seoMetaTag('og:locale', 'ru_RU', true),
    ];

    if ($title !== '') {
        $tags[] = seoMetaTag('og:title', $title, true);
        $tags[] = seoMetaTag('twitter:title', $title);
    }

    if ($description !== '') {
        $tags[] = seoMetaTag('og:description', $description, true);
        $tags[] = seoMetaTag('twitter:description', $description);
    }

    if ($image !== '') {
        $imageUrl = seoMetaAbsoluteAddress($image);
        $tags[] = seoMetaTag('og:image', $imageUrl, true);
        $tags[] = seoMetaTag('twitter:image', $imageUrl);
        $tags[] = seoMetaTag('twitter:card', 'summary_large_image');
    } else {
        $tags[] = seoMetaTag('twitter:card', 'summary');
    }

    return implode("\n", $tags);
}

/**
 * Полный адрес: путь от корня дополняется доменом сайта, готовый адрес не трогается.
 */
function seoMetaAbsoluteAddress(string $address): string
{
    $clean = trim($address);

    if ($clean === '') {
        return '';
    }

    if (str_starts_with($clean, '/')) {
        return absoluteUrl($clean);
    }

    return $clean;
}

/**
 * Фильтр page_meta: страница получает сохранённые заголовок, описание и каноник, а закрытый
 * адрес — метку запрета индексации. Раздел выключен — страница остаётся как была.
 *
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function seoMetaPageMeta(array $meta, string $template = '', array $data = []): array
{
    if (!isAppActive('seo')) {
        return $meta;
    }

    $row = seoMetaRow(requestPath());

    if ($row === null) {
        return $meta;
    }

    $title = trim((string) ($row['title'] ?? ''));

    if ($title !== '') {
        $meta['title'] = $title;
    }

    $description = trim((string) ($row['description'] ?? ''));

    if ($description !== '') {
        $meta['description'] = $description;
    }

    // Своя директива ставится только странице, которая и так открыта: закрытую ядром
    // страницу «index, follow» открыла бы вопреки её назначению.
    $robots = trim((string) ($row['robots'] ?? ''));
    $noindex = (bool) ($meta['noindex'] ?? false);

    if (!$noindex && $robots !== '') {
        $meta['robots'] = $robots;

        if (str_contains(strtolower($robots), 'noindex')) {
            $meta['noindex'] = true;
        }
    }

    if ((int) ($row['noindex'] ?? 0) === 1) {
        $meta['noindex'] = true;
    }

    $head = seoMetaHeadTags($row, $meta);

    if ($head !== '') {
        $meta['head'] = (string) ($meta['head'] ?? '') . "\n" . $head;
    }

    return $meta;
}

/**
 * Фильтр sitemap_urls: в карту сайта попадают адреса, которые человек назвал сам и оставил
 * открытыми. Проверку адреса ядро делает само: чужой домен и приватный путь в карту не
 * попадут.
 *
 * @param list<array<string, mixed>> $urls
 * @return list<array<string, mixed>>
 */
function seoMetaSitemapUrls(array $urls): array
{
    if (!isAppActive('seo')) {
        return $urls;
    }

    foreach (seoMetaList() as $row) {
        if ((int) ($row['in_sitemap'] ?? 0) !== 1) {
            continue;
        }

        if ((int) ($row['noindex'] ?? 0) === 1) {
            continue;
        }

        $robots = strtolower(trim((string) ($row['robots'] ?? '')));

        if (str_contains($robots, 'noindex')) {
            continue;
        }

        $path = seoMetaPath((string) ($row['path'] ?? ''));

        if ($path === '') {
            continue;
        }

        $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

        $entry = ['loc' => $path];

        if ($updated !== null) {
            $entry['lastmod'] = substr(moscowIso($updated), 0, 10);
        }

        $urls[] = $entry;
    }

    return $urls;
}
