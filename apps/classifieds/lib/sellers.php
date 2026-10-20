<?php

declare(strict_types=1);

/**
 * @return array{phone: string, city: string, about: string, show_phone: bool}|null
 */
function classifiedsSellerProfile(string $userId): ?array
{
    $row = one(
        'SELECT phone, city, about, show_phone FROM ' . t('classifieds_seller_profile') . ' WHERE user_id = ?',
        [$userId]
    );

    if ($row === null) {
        return null;
    }

    return [
        'phone' => (string) ($row['phone'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'about' => (string) ($row['about'] ?? ''),
        'show_phone' => (int) ($row['show_phone'] ?? 1) === 1,
    ];
}

/**
 * @param array<string, mixed> $user
 * @param array{phone: string, city: string, about: string, show_phone: bool}|null $profile
 * @return array{name: string, phone: string, city: string, about: string, show_phone: int}
 */
function classifiedsSellerForm(array $user, ?array $profile): array
{
    return [
        'name' => (string) ($user['name'] ?? ''),
        'phone' => (string) ($profile['phone'] ?? ''),
        'city' => (string) ($profile['city'] ?? ''),
        'about' => (string) ($profile['about'] ?? ''),
        'show_phone' => $profile === null || $profile['show_phone'] ? 1 : 0,
    ];
}

/**
 * @param array<string, string> $post
 * @return array{errors: array<string, string>, values: array{name: string, phone: string, city: string, about: string, show_phone: int}}
 */
function classifiedsValidateSellerProfile(array $post): array
{
    $errors = [];
    $name = trim((string) ($post['name'] ?? ''));
    $phone = trim((string) ($post['phone'] ?? ''));
    $city = trim((string) ($post['city'] ?? ''));
    $about = trim((string) ($post['about'] ?? ''));
    $showPhone = ($post['show_phone'] ?? '') === '1' ? 1 : 0;

    if ($name === '') {
        $errors['name'] = 'Напишите имя — его увидят покупатели.';
    } elseif (mb_strlen($name) > CLASSIFIEDS_NAME_MAX) {
        $errors['name'] = 'Имя длиннее ' . CLASSIFIEDS_NAME_MAX . ' символов — сократите.';
    }

    if (mb_strlen($phone) > CLASSIFIEDS_PHONE_MAX) {
        $errors['phone'] = 'Телефон длиннее ' . CLASSIFIEDS_PHONE_MAX . ' символов.';
    }

    if (mb_strlen($city) > CLASSIFIEDS_CITY_MAX) {
        $errors['city'] = 'Город длиннее ' . CLASSIFIEDS_CITY_MAX . ' символов — сократите.';
    }

    if (mb_strlen($about) > CLASSIFIEDS_ABOUT_MAX) {
        $errors['about'] = 'Описание длиннее ' . CLASSIFIEDS_ABOUT_MAX . ' символов — сократите.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'name' => $name,
            'phone' => $phone,
            'city' => $city,
            'about' => $about,
            'show_phone' => $showPhone,
        ],
    ];
}

/**
 * @param array{name: string, phone: string, city: string, about: string, show_phone: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsSaveSellerProfile(string $userId, array $values, ?DateTimeImmutable $now = null): array
{
    $moment = utcStamp($now ?? nowUtc());
    $profile = classifiedsSellerProfile($userId);

    tx(static function () use ($userId, $values, $profile, $moment): void {
        run(
            'UPDATE ' . t('user') . ' SET name = ? WHERE id = ?',
            [$values['name'], $userId]
        );

        $fields = [
            'phone' => $values['phone'] !== '' ? $values['phone'] : null,
            'city' => $values['city'] !== '' ? $values['city'] : null,
            'about' => $values['about'] !== '' ? $values['about'] : null,
            'show_phone' => $values['show_phone'],
            'updated_at' => $moment,
        ];

        if ($profile === null) {
            insert('classifieds_seller_profile', ['user_id' => $userId] + $fields + ['created_at' => $moment]);
        } else {
            run(
                'UPDATE ' . t('classifieds_seller_profile')
                . ' SET phone = ?, city = ?, about = ?, show_phone = ?, updated_at = ? WHERE user_id = ?',
                [$fields['phone'], $fields['city'], $fields['about'], $fields['show_phone'], $moment, $userId]
            );
        }
    });

    logInfo('classifieds.profile_saved', ['userId' => $userId]);

    return ['ok' => true, 'error' => '', 'notice' => 'profile_saved'];
}

/**
 * Публичная карточка продавца. Заблокированный или удалённый пользователь не раскрывается,
 * почта наружу не выводится даже владельцу профиля.
 *
 * @return array{id: string, name: string, city: string, phone: string, about: string, show_phone: bool, member_since: ?DateTimeImmutable}|null
 */
function classifiedsSellerPublic(string $userId): ?array
{
    $user = one(
        'SELECT id, name, created_at, blocked_at FROM ' . t('user') . ' WHERE id = ?',
        [$userId]
    );

    if ($user === null || $user['blocked_at'] !== null) {
        return null;
    }

    $profile = classifiedsSellerProfile($userId);
    $phone = (string) ($profile['phone'] ?? '');
    $showPhone = $profile !== null && $profile['show_phone'] && $phone !== '';

    return [
        'id' => (string) $user['id'],
        'name' => (string) $user['name'],
        'city' => (string) ($profile['city'] ?? ''),
        'phone' => $showPhone ? $phone : '',
        'about' => (string) ($profile['about'] ?? ''),
        'show_phone' => $showPhone,
        'member_since' => fromDb((string) $user['created_at']),
    ];
}

function classifiedsSellerHref(string $userId): string
{
    return '/classifieds/seller/' . rawurlencode($userId);
}
