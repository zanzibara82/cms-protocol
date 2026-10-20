<?php

declare(strict_types=1);

// Кабинет продавца: открытая регистрация по e-mail, список своих объявлений и формы
// создания/правки. Маршруты кабинета не помечены `private`, потому что гейт ядра пускает
// в приватные пути только admin/editor, а продавец — роль seller. Поэтому вход проверяет
// каждая функция сама: аноним уезжает на штатный /login с глубинной ссылкой.

/**
 * Вошедший продавец. Заблокированный аккаунт кабинетом не пользуется: блокировка ставится
 * из ядра, и повторять её проверку в каждом действии незачем.
 *
 * @return array<string, mixed>|null
 */
function classifiedsAccount(): ?array
{
    $user = currentUser();

    if ($user === null || $user['blocked_at'] !== null) {
        return null;
    }

    return $user;
}

/** Отправка анонима на штатный вход ядра: после входа человек вернётся в кабинет.
 * @return array<string, mixed>
 */
function classifiedsRequireAccount(): array
{
    $user = classifiedsAccount();

    if ($user === null) {
        redirect('/login?next=' . rawurlencode('/classifieds/cabinet'));
    }

    return $user;
}

/** Форма объявления из строки базы: поля приводятся к тому же виду, что и при POST.
 * @param array<string, mixed> $listing
 * @return array{title: string, slug: string, category: string, body: string, price: string, negotiable: int, location: string, status: string, seo_title: string, seo_description: string, noindex: int, image: string, attrs: array<string, string>}
 */
function classifiedsFormFromListing(array $listing): array
{
    return [
        'title' => (string) $listing['title'],
        'slug' => (string) $listing['slug'],
        'category' => (string) $listing['category_id'],
        'body' => (string) $listing['body'],
        'price' => $listing['price'] === null ? '' : number_format((float) $listing['price'], 2, '.', ''),
        'negotiable' => $listing['negotiable'] ? 1 : 0,
        'location' => (string) ($listing['location'] ?? ''),
        'status' => (string) $listing['status'],
        'seo_title' => (string) ($listing['seo_title'] ?? ''),
        'seo_description' => (string) ($listing['seo_description'] ?? ''),
        'noindex' => $listing['noindex'] ? 1 : 0,
        'image' => (string) ($listing['image'] ?? ''),
        'attrs' => classifiedsListingAttributeFormValues((string) $listing['id']),
    ];
}

/**
 * Набранное из POST в строках формы. Цена не переводится в число: поле может быть
 * мусором, и показывать человеку свою же опечатку честнее, чем молча стереть.
 *
 * @param array<string, mixed> $post
 * @return array{title: string, slug: string, category: string, body: string, price: string, negotiable: int, location: string, status: string, seo_title: string, seo_description: string, noindex: int, image: string, attrs: array<string, string>}
 */
function classifiedsFormFromPost(array $post): array
{
    $raw = $post['attrs'] ?? null;
    $attrs = [];

    if (is_array($raw)) {
        foreach ($raw as $id => $value) {
            if (is_scalar($value)) {
                $attrs[(string) $id] = trim((string) $value);
            }
        }
    }

    return [
        'title' => trim((string) ($post['title'] ?? '')),
        'slug' => trim((string) ($post['slug'] ?? '')),
        'category' => trim((string) ($post['category'] ?? '')),
        'body' => (string) ($post['body'] ?? ''),
        'price' => trim((string) ($post['price'] ?? '')),
        'negotiable' => ($post['negotiable'] ?? '') === '1' ? 1 : 0,
        'location' => trim((string) ($post['location'] ?? '')),
        'status' => strtoupper(trim((string) ($post['status'] ?? ''))) === 'PUBLISHED' ? 'PUBLISHED' : 'DRAFT',
        'seo_title' => trim((string) ($post['seo_title'] ?? '')),
        'seo_description' => trim((string) ($post['seo_description'] ?? '')),
        'noindex' => ($post['noindex'] ?? '') === '1' ? 1 : 0,
        'image' => '',
        'attrs' => $attrs,
    ];
}

/** @param array<string, string> $params */
function classifiedsCabinetPage(array $params = []): void
{
    $user = classifiedsRequireAccount();

    render('classifieds/cabinet', [
        'listings' => classifiedsOwnerListings((string) $user['id']),
        'notice' => (string) (noticeOfRequest(CLASSIFIEDS_NOTICE_TEXT) ?? ''),
        'name' => (string) $user['name'],
    ], ['title' => 'Мои объявления — доска', 'noindex' => true]);
}

/** @param array<string, string> $params */
function classifiedsRegisterPage(array $params = []): void
{
    if (classifiedsAccount() !== null) {
        redirect('/classifieds/cabinet');
    }

    render('classifieds/register', [
        'errors' => [],
        'form' => ['email' => '', 'name' => ''],
    ], ['title' => 'Регистрация продавца — доска', 'noindex' => true]);
}

/**
 * Регистрация продавца: почта и пароль. Попытки пишутся в собственный журнал и считаются
 * по IP за час — рассылка не заведёт сотни аккаунтов, а живой человек не упрётся.
 *
 * @param array<string, string> $params
 */
function classifiedsRegisterAction(array $params = []): void
{
    csrfCheck();

    if (classifiedsAccount() !== null) {
        redirect('/classifieds/cabinet');
    }

    $email = mb_strtolower(trim(postString('email')));
    $name = trim(postString('name'));
    $password = postString('password');
    $ip = clientIp();
    $errors = [];

    if ($email === '') {
        $errors['email'] = 'Почта пустая — без неё не восстановить доступ.';
    } elseif (mb_strlen($email) > CLASSIFIEDS_EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Это не похоже на почту — пример: ivan@example.ru.';
    }

    if ($name === '') {
        $errors['name'] = 'Напишите имя — его увидят покупатели.';
    } elseif (mb_strlen($name) > CLASSIFIEDS_NAME_MAX) {
        $errors['name'] = 'Имя длиннее ' . CLASSIFIEDS_NAME_MAX . ' символов — сократите.';
    }

    if (mb_strlen($password) < CLASSIFIEDS_PASSWORD_MIN) {
        $errors['password'] = 'Пароль короче ' . CLASSIFIEDS_PASSWORD_MIN . ' символов — так проще подобрать.';
    }

    if ($errors === [] && classifiedsRegistrationBlocked($ip)) {
        $errors['email'] = 'Слишком много попыток регистрации с этого адреса — подождите час.';
    }

    if ($errors === [] && one('SELECT id FROM ' . t('user') . ' WHERE email = ?', [$email]) !== null) {
        $errors['email'] = 'Такая почта уже зарегистрирована — войдите или восстановите пароль.';
    }

    if ($errors === []) {
        classifiedsRecordRegistrationAttempt($email, $ip);
    }

    if ($errors !== []) {
        render('classifieds/register', [
            'errors' => $errors,
            'form' => ['email' => $email, 'name' => $name],
        ], ['title' => 'Регистрация продавца — доска', 'noindex' => true]);

        return;
    }

    $id = newId();
    insert('user', [
        'id' => $id,
        'email' => $email,
        'password_hash' => hashPassword($password),
        'name' => $name,
        'role' => 'seller',
        'session_version' => 0,
        'created_at' => utcStamp(nowUtc()),
    ]);
    logInfo('classifieds.seller_registered', ['userId' => $id]);

    // Сразу входим тем же контрактом, что ядро: строку читаем после вставки, чтобы номер
    // версии сессии пришёл из базы, а не из головы.
    $row = one('SELECT id, email, name, role, session_version FROM ' . t('user') . ' WHERE id = ?', [$id]);
    if ($row !== null) {
        loginUser($row);
    }

    $created = ['ok' => true, 'error' => '', 'notice' => 'account_created'];
    $notice = (string) $created['notice'];
    finishAppAction($created, '/classifieds/cabinet', $notice);
}

/** Просит ли журнал попыток притормозить: не больше лимита за окно по адресу. */
function classifiedsRegistrationBlocked(string $ip, ?DateTimeImmutable $now = null): bool
{
    $moment = $now ?? nowUtc();

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_registration_attempt') . ' WHERE ip = ? AND attempted_at > ?',
        [$ip, utcStamp($moment->sub(new DateInterval('PT' . CLASSIFIEDS_REGISTER_WINDOW . 'M')))]
    ) >= CLASSIFIEDS_REGISTER_LIMIT_PER_IP;
}

function classifiedsRecordRegistrationAttempt(string $email, string $ip, ?DateTimeImmutable $now = null): void
{
    insert('classifieds_registration_attempt', [
        'email' => $email,
        'ip' => $ip,
        'attempted_at' => utcStamp($now ?? nowUtc()),
    ]);
}

/** @param array<string, string> $params */
function classifiedsListingNewPage(array $params = []): void
{
    classifiedsRequireAccount();

    $source = isset($_GET['category']) ? $_GET : [];
    $form = $source === [] ? [
        'title' => '',
        'slug' => '',
        'category' => '',
        'body' => '',
        'price' => '',
        'negotiable' => 0,
        'location' => '',
        'status' => 'DRAFT',
        'seo_title' => '',
        'seo_description' => '',
        'noindex' => 0,
        'image' => '',
        'attrs' => [],
    ] : classifiedsFormFromPost($source);
    $attributes = classifiedsCategoryById((string) $form['category']) !== null
        ? classifiedsAttributesForCategory((string) $form['category'])
        : [];

    render('classifieds/listing-form', [
        'mode' => 'new',
        'listing' => null,
        'form' => $form,
        'errors' => [],
        'categories' => classifiedsCategoryAll(),
        'attributes' => $attributes,
        'gallery' => [],
        'action' => '/classifieds/cabinet/new',
    ], ['title' => 'Новое объявление — доска', 'noindex' => true]);
}

/** @param array<string, string> $params */
function classifiedsListingEditPage(array $params = []): void
{
    $user = classifiedsRequireAccount();
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $listing = classifiedsListingById($id);

    if ($listing === null || (string) $listing['owner_id'] !== (string) $user['id']) {
        notFound();
    }

    $form = classifiedsFormFromListing($listing);

    if (isset($_GET['category'])) {
        $form['category'] = trim((string) $_GET['category']);
    }

    $attributes = classifiedsCategoryById((string) $form['category']) !== null
        ? classifiedsAttributesForCategory((string) $form['category'])
        : [];

    render('classifieds/listing-form', [
        'mode' => 'edit',
        'listing' => $listing,
        'form' => $form,
        'errors' => [],
        'categories' => classifiedsCategoryAll(),
        'attributes' => $attributes,
        'gallery' => classifiedsListingImages($id),
        'action' => '/classifieds/cabinet/' . rawurlencode($id),
    ], ['title' => 'Правка объявления — доска', 'noindex' => true]);
}

/**
 * Сохранение формы: создание и правка отличаются наличием параметра адреса.
 *
 * @param array<string, string> $params
 */
function classifiedsListingSaveAction(array $params = []): void
{
    csrfCheck();
    $user = classifiedsRequireAccount();
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $listing = null;

    if ($id !== '') {
        $listing = classifiedsListingById($id);

        if ($listing === null || (string) $listing['owner_id'] !== (string) $user['id']) {
            finishAppAction(['ok' => false, 'error' => 'Объявления уже нет — обновите список.'], '/classifieds/cabinet', '');

            return;
        }
    }

    $check = classifiedsValidateListing($_POST, $listing === null ? '' : (string) $listing['id']);
    $attributeCheck = classifiedsValidateListingAttributes($_POST, (string) $check['values']['category']);
    $check['errors'] = array_merge($check['errors'], $attributeCheck['errors']);
    $action = $listing === null ? '/classifieds/cabinet/new' : '/classifieds/cabinet/' . rawurlencode($id);
    $values = $check['values'];
    $values['attributes'] = $attributeCheck['values'];

    // Новый файл проверяется после полей: если форма с ошибками, тратить запись на диск незачем.
    $uploaded = null;
    $file = uploadedFile('image');

    if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && $check['errors'] === []) {
        $stored = classifiedsImageStore($file);

        if (!$stored['ok']) {
            $check['errors']['image'] = $stored['error'];
        } else {
            $uploaded = $stored['href'];
        }
    }

    $galleryExisting = $listing === null ? [] : classifiedsListingImages((string) $listing['id']);
    $removeGallery = $_POST['remove_gallery'] ?? [];
    $removeGallery = is_array($removeGallery) ? array_map('strval', $removeGallery) : [];
    $keptGallery = [];
    $removedGalleryFiles = [];

    foreach ($galleryExisting as $image) {
        if (in_array((string) $image['id'], $removeGallery, true)) {
            $removedGalleryFiles[] = (string) $image['href'];
        } else {
            $keptGallery[] = (string) $image['href'];
        }
    }

    $uploadedGallery = [];

    if ($check['errors'] === []) {
        $storedGallery = classifiedsGalleryUpload('images', max(0, CLASSIFIEDS_GALLERY_MAX - count($keptGallery)));

        if (!$storedGallery['ok']) {
            $check['errors']['gallery'] = $storedGallery['error'];
        } else {
            $uploadedGallery = $storedGallery['hrefs'];
        }
    }

    $values['gallery'] = array_merge($keptGallery, $uploadedGallery);

    if ($check['errors'] !== []) {
        if ($uploaded !== null) {
            classifiedsImageDelete($uploaded);
        }

        foreach ($uploadedGallery as $href) {
            classifiedsImageDelete($href);
        }

        $form = classifiedsFormFromPost($_POST);
        $attributes = classifiedsCategoryById((string) $form['category']) !== null
            ? classifiedsAttributesForCategory((string) $form['category'])
            : [];

        render('classifieds/listing-form', [
            'mode' => $listing === null ? 'new' : 'edit',
            'listing' => $listing,
            'form' => $form,
            'errors' => $check['errors'],
            'categories' => classifiedsCategoryAll(),
            'attributes' => $attributes,
            'gallery' => $galleryExisting,
            'action' => $action,
        ], ['title' => 'Форма объявления — доска', 'noindex' => true]);

        return;
    }

    if ($uploaded !== null) {
        $values['image'] = $uploaded;
    } elseif ($listing !== null && postString('remove_image') === '1') {
        $values['image'] = '';
    } elseif ($listing !== null) {
        $values['image'] = (string) ($listing['image'] ?? '');
    }

    if ($listing === null) {
        $saved = classifiedsCreateListing((string) $user['id'], $values);
    } else {
        $saved = classifiedsUpdateListing($id, $values);
    }

    if (($saved['ok'] ?? false) !== true) {
        if ($uploaded !== null) {
            classifiedsImageDelete($uploaded);
        }

        foreach ($uploadedGallery as $href) {
            classifiedsImageDelete($href);
        }

        finishAppAction($saved, $action, '');

        return;
    }

    // Старые файлы уходят только после успешной записи новых ссылок: до этого они ещё
    // нужны странице объявления, если запись в базу не прошла.
    if ($listing !== null && $uploaded !== null && (string) ($listing['image'] ?? '') !== '') {
        classifiedsImageDelete((string) $listing['image']);
    }

    if ($listing !== null && postString('remove_image') === '1' && (string) ($listing['image'] ?? '') !== '') {
        classifiedsImageDelete((string) $listing['image']);
    }

    foreach ($removedGalleryFiles as $href) {
        classifiedsImageDelete($href);
    }

    $notice = (string) ($saved['notice'] ?? '');
    finishAppAction($saved, '/classifieds/cabinet', $notice);
}

/**
 * Действия над своим объявлением: опубликовать, снять, удалить. Владение проверяет
 * setStatus/delete по owner_id — второй источник правды в кабинете не нужен.
 *
 * @param array<string, string> $params
 */
function classifiedsListingAction(array $params = []): void
{
    csrfCheck();
    $user = classifiedsRequireAccount();
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $op = postString('op');

    $result = match ($op) {
        'publish' => classifiedsListingSetStatus($id, (string) $user['id'], 'PUBLISHED'),
        'hide' => classifiedsListingSetStatus($id, (string) $user['id'], 'DRAFT'),
        'delete' => classifiedsListingDelete($id, (string) $user['id']),
        default => ['ok' => false, 'error' => 'Неизвестное действие с объявлением.', 'notice' => ''],
    };

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/classifieds/cabinet', $notice);
}
