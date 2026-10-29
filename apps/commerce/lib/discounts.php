<?php

declare(strict_types=1);

// Скидки (M4): купоны — кодом с корзины, промо-правила — сами по себе. Общая механика
// одна: размер скидки (процент или фиксированная сумма), порог корзины, периоды.
// Проверки купона идут по свежей строке базы в момент сделки — снятый, истёкший или
// исчерпанный купон скидки не даёт, и причина отказа печатается человеку словами.
//
// Слова сделки дублируются в заказе: код купона и название правила хранятся строкой
// заказа, и история продаж читается даже после удаления их таблиц.

/** Штамп дня из проверенной формы: валидатор уже разобрал дату для базы. */
function commerceDayStamp(string $raw, string $edge): ?string
{
    $moment = commerceParseDayField($raw, $edge);

    return $moment !== null ? utcStamp($moment) : null;
}

/**
 * Дата формы «ГГГГ-ММ-ДД» в момент начала или конца суток по Москве. Разбор — только
 * через core/date.php: приложение не строит моменты руками, страж DateUsageTest
 * следит за этим же.
 */
function commerceParseDayField(string $raw, string $edge): ?DateTimeImmutable
{
    return parseMoscowDay($raw, $edge);
}

/**
 * Деньги формы: «12 500,50» → float. Общий разбор корзинного порога и размеров скидок.
 */
function commerceParseMoneyField(string $raw): ?float
{
    $raw = str_replace(' ', '', $raw);

    if (!preg_match('/^\d+([.,]\d{1,2})?$/', $raw)) {
        return null;
    }

    $amount = (float) str_replace(',', '.', $raw);

    return $amount > 0 && $amount <= COMMERCE_PRICE_MAX ? $amount : null;
}

/**
 * Тело скидочной формы, общее купонам и правилам: тип и размер скидки, порог корзины.
 * Причины отказа дописываются в общий словарь формы and возвращаются вместе с телом.
 *
 * @param array<string, string> $post
 * @param array<string, string> $errors
 * @return array{errors: array<string, string>, kind: string, value: ?float, min_total: ?float}
 */
function commerceParseDiscountCore(array $post, array $errors): array
{
    $kind = strtoupper(trim((string) ($post['kind'] ?? 'PERCENT')));
    $valueRaw = trim((string) ($post['value'] ?? ''));
    $minRaw = trim((string) ($post['min_total'] ?? ''));

    if (!in_array($kind, COMMERCE_DISCOUNT_KINDS, true)) {
        $errors['kind'] = 'Тип скидки выбирается из списка.';
        $value = null;
    } else {
        $value = commerceParseMoneyField($valueRaw);

        if ($value === null) {
            $errors['value'] = $valueRaw === ''
                ? 'Укажите размер скидки.'
                : 'Размер скидки — число больше нуля, до ' . number_format(COMMERCE_PRICE_MAX, 2, ',', ' ') . '.';
        } elseif ($kind === 'PERCENT' && $value > 100) {
            $errors['value'] = 'Процент не бывает больше ста.';
        }
    }

    $minTotal = null;

    if ($minRaw !== '') {
        $minTotal = commerceParseMoneyField($minRaw);

        if ($minTotal === null) {
            $errors['min_total'] = 'Порог корзины не разобран: число больше нуля, до '
                . number_format(COMMERCE_PRICE_MAX, 2, ',', ' ') . '.';
        }
    }

    return [
        'errors' => $errors,
        'kind' => in_array($kind, COMMERCE_DISCOUNT_KINDS, true) ? $kind : 'PERCENT',
        'value' => $value,
        'min_total' => $minTotal,
    ];
}

/**
 * Куки-скидки сессии: код купона живёт в корзине между экранами.
 */
function commerceSessionCouponCode(): string
{
    startSession();
    $code = $_SESSION[COMMERCE_SESSION_COUPON] ?? '';

    return is_string($code) ? $code : '';
}

function commerceSessionCouponSet(string $code): void
{
    startSession();
    $_SESSION[COMMERCE_SESSION_COUPON] = mb_substr(mb_strtoupper(trim($code)), 0, COMMERCE_COUPON_CODE_MAX);
}

function commerceSessionCouponDrop(): void
{
    startSession();
    unset($_SESSION[COMMERCE_SESSION_COUPON]);
}

// ---------------------------------------------------------------------------
// Купоны
// ---------------------------------------------------------------------------

/**
 * Разбор POST формы купона. Код приводится к верхнему регистру: коллация базы сравнивает
 * коды без учёта регистра, а человек одинаково набирает код и с шапкой, и без.
 *
 * @param array<string, string> $post
 * @param callable(string): bool $codeTaken код уже занят другим купоном
 * @return array{errors: array<string, string>, values: array{code: string, kind: string, value: float, min_total: ?float, expires: string, max_uses: ?int, active: int}}
 */
function commerceValidateCoupon(array $post, callable $codeTaken): array
{
    $errors = [];
    $code = mb_strtoupper(trim((string) ($post['code'] ?? '')));
    $active = isOn($post['active'] ?? '') ? 1 : 0;

    if (!preg_match('/^[A-Z0-9-]{3,64}$/', $code) || mb_strlen($code) > COMMERCE_COUPON_CODE_MAX) {
        $errors['code'] = 'Код купона — из латиницы, цифр и дефиса, от 3 до '
            . COMMERCE_COUPON_CODE_MAX . ' знаков.';
    } elseif ($codeTaken($code)) {
        $errors['code'] = 'Код уже занят другим купоном.';
    }

    $core = commerceParseDiscountCore($post, $errors);
    $errors = (array) $core['errors'];
    $value = (float) ($core['value'] ?? 0.0);

    $expiresRaw = trim((string) ($post['expires'] ?? ''));
    $expires = '';

    if ($expiresRaw !== '') {
        if (commerceParseDayField($expiresRaw, 'end') === null) {
            $errors['expires'] = 'Дата не разобрана: нужен формат ГГГГ-ММ-ДД, например 2026-12-31.';
        } else {
            $expires = $expiresRaw;
        }
    }

    $maxUses = null;
    $maxRaw = trim((string) ($post['max_uses'] ?? ''));

    if ($maxRaw !== '') {
        if (!ctype_digit($maxRaw) || (int) $maxRaw < 1 || (int) $maxRaw > 99999) {
            $errors['max_uses'] = 'Лимит визитов — целое число от 1 до 99999.';
        } else {
            $maxUses = (int) $maxRaw;
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'code' => $code,
            'kind' => $core['kind'],
            'value' => $value,
            'min_total' => $core['min_total'],
            'expires' => $expires,
            'max_uses' => $maxUses,
            'active' => $active,
        ],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function commerceCouponsAll(): array
{
    $rows = all(
        'SELECT id, code, kind, value, min_total, expires_at, max_uses, used_count, active, created_at'
        . ' FROM ' . t('commerce_coupon') . ' ORDER BY created_at DESC, code'
    );

    /** @var list<array<string, mixed>> */
    return $rows;
}

/** @return array<string, mixed>|null */
function commerceCouponById(string $id): ?array
{
    $row = one(
        'SELECT id, code, kind, value, min_total, expires_at, max_uses, used_count, active'
        . ' FROM ' . t('commerce_coupon') . ' WHERE id = ?',
        [$id]
    );

    /** @var array<string, mixed>|null */
    return $row;
}

/** @return array<string, mixed>|null */
function commerceCouponByCode(string $code): ?array
{
    $row = one(
        'SELECT id, code, kind, value, min_total, expires_at, max_uses, used_count, active'
        . ' FROM ' . t('commerce_coupon') . ' WHERE code = ?',
        [mb_substr(trim($code), 0, COMMERCE_COUPON_CODE_MAX)]
    );

    /** @var array<string, mixed>|null */
    return $row;
}

/** Код занят другим купоном: сравнение без регистра, как в базе (CI-коллация). */
function commerceCouponCodeTaken(string $code, string $exceptId = ''): bool
{
    $value = mb_substr(trim(mb_strtoupper($code)), 0, COMMERCE_COUPON_CODE_MAX);

    if ($value === '') {
        return false;
    }

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('commerce_coupon') . ' WHERE code = ? AND id <> ?',
        [$value, $exceptId]
    ) > 0;
}

/**
 * Создать купон. Дата истечения — конец набранного дня: купон работает весь день,
 * выписанный на бланке.
 *
 * @param array{code: string, kind: string, value: float, min_total: ?float, expires: string, max_uses: ?int, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCreateCoupon(array $values, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $id = newId();

    // Проверка «код свободен» в форме и INSERT — руки двух одновременных сохранений: если
    // параллельное сохранение заняло код первым, наш INSERT получает отказ уникального
    // ключа. Человеку возвращается та же причина, что и у спокойной проверки кода, а не
    // HTTP 500 чужого PDOException.
    try {
        insert('commerce_coupon', [
            'id' => $id,
            'code' => $values['code'],
            'kind' => $values['kind'],
            'value' => $values['value'],
            'min_total' => $values['min_total'],
            'expires_at' => $values['expires'] !== '' ? commerceDayStamp($values['expires'], 'end') : null,
            'max_uses' => $values['max_uses'],
            'used_count' => 0,
            'active' => $values['active'],
            'created_at' => utcStamp($moment),
            'updated_at' => utcStamp($moment),
        ]);
    } catch (Throwable $insertError) {
        return [
            'ok' => false,
            'error' => duplicateReason($insertError, 'Код уже занят другим купоном — предложите свой.'),
            'notice' => '',
        ];
    }

    logInfo('commerce.coupon_created', ['couponId' => $id, 'code' => $values['code'], 'kind' => $values['kind']]);

    return ['ok' => true, 'error' => '', 'notice' => 'coupon_saved'];
}

/**
 * @param array{code: string, kind: string, value: float, min_total: ?float, expires: string, max_uses: ?int, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceUpdateCoupon(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $coupon = commerceCouponById($id);

    if ($coupon === null) {
        return ['ok' => false, 'error' => 'Купона уже нет — обновите список.', 'notice' => ''];
    }

    // Правка и чужое сохранение могут претендовать на один код: проигравший UPDATE получает
    // отказ уникального ключа, и человек видит ту же причину, что и у спокойной проверки.
    try {
        run(
            'UPDATE ' . t('commerce_coupon') . ' SET code = ?, kind = ?, value = ?, min_total = ?,'
            . ' expires_at = ?, max_uses = ?, active = ?, updated_at = ? WHERE id = ?',
            [
                $values['code'],
                $values['kind'],
                $values['value'],
                $values['min_total'],
                $values['expires'] !== '' ? commerceDayStamp($values['expires'], 'end') : null,
                $values['max_uses'],
                $values['active'],
                utcStamp($now ?? nowUtc()),
                $id,
            ]
        );
    } catch (Throwable $updateError) {
        return [
            'ok' => false,
            'error' => duplicateReason($updateError, 'Код уже занят другим купоном — предложите свой.'),
            'notice' => '',
        ];
    }

    logInfo('commerce.coupon_updated', ['couponId' => $id, 'code' => $values['code']]);

    return ['ok' => true, 'error' => '', 'notice' => 'coupon_saved'];
}

/**
 * Удалить купон. Заказы дублируют код строкой своей сделки, так что даже купон с визитами
 * уходит безопасно: история показывает, что произошло, а списания уже не врёт.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceDeleteCoupon(string $id): array
{
    $coupon = commerceCouponById($id);

    if ($coupon === null) {
        return ['ok' => false, 'error' => 'Купона уже нет.', 'notice' => ''];
    }

    run('DELETE FROM ' . t('commerce_coupon') . ' WHERE id = ?', [$id]);
    logInfo('commerce.coupon_deleted', ['couponId' => $id, 'code' => (string) $coupon['code']]);

    return ['ok' => true, 'error' => '', 'notice' => 'coupon_deleted'];
}

/**
 * Полка купона: пригоден ли код к сделке. Причины называются словами — экран корзины
 * печает их рядом с формой, и человек понимает, что делать (убрать код или докупить).
 *
 * @param array<string, mixed>|null $coupon
 * @return array{ok: bool, error: string}
 */
function commerceCouponUsable(?array $coupon, int $subtotalCents, string $currency, ?DateTimeImmutable $now = null): array
{
    if ($coupon === null) {
        return ['ok' => false, 'error' => 'Купона с таким кодом нет.'];
    }

    if ((int) $coupon['active'] !== 1) {
        return ['ok' => false, 'error' => 'Купон снят с продаж.'];
    }

    $moment = $now ?? nowUtc();
    $expires = fromDb(is_string($coupon['expires_at'] ?? null) ? (string) $coupon['expires_at'] : null);

    if ($expires !== null && $moment > $expires) {
        return ['ok' => false, 'error' => 'Купон истёк.'];
    }

    $maxUses = $coupon['max_uses'] !== null && $coupon['max_uses'] !== '' ? (int) $coupon['max_uses'] : null;

    if ($maxUses !== null && (int) $coupon['used_count'] >= $maxUses) {
        return ['ok' => false, 'error' => 'Купон исчерпан: его визиты уже потрачены.'];
    }

    if ($coupon['min_total'] !== null && $coupon['min_total'] !== '') {
        $minCents = commerceCents((float) $coupon['min_total']);

        if ($subtotalCents < $minCents) {
            return [
                'ok' => false,
                'error' => 'Купон работает от корзины на ' . commerceFormatMoney($minCents, $currency) . ' и больше.',
            ];
        }
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * Размер скидки купона копейками. Фиксированная скидка не даёт корзине уйти в минус:
 * сумма Купона не превышает подытог.
 *
 * @param array<string, mixed> $coupon
 */
function commerceCouponDiscountCents(array $coupon, int $subtotalCents): int
{
    $discount = (string) $coupon['kind'] === 'PERCENT'
        ? (int) round($subtotalCents * (float) $coupon['value'] / 100.0)
        : commerceCents((float) $coupon['value']);

    return min($discount, $subtotalCents);
}

/**
 * Сгорание визита купона внутри транзакции оформления. Проверка полки и списание идут
 * одним UPDATE ... WHERE: два заказа одновременно не уведут последний визит.
 *
 * @return array{ok: bool, error: string, coupon: array<string, mixed>|null}
 */
function commerceConsumeCoupon(string $code, string $currency, int $subtotalCents, DateTimeImmutable $moment): array
{
    $coupon = commerceCouponByCode($code);
    $usable = commerceCouponUsable($coupon, $subtotalCents, $currency, $moment);

    if (!$usable['ok']) {
        return ['ok' => false, 'error' => $usable['error'], 'coupon' => null];
    }

    $burned = run(
        'UPDATE ' . t('commerce_coupon') . ' SET used_count = used_count + 1'
        . ' WHERE id = ? AND active = 1 AND (expires_at IS NULL OR expires_at >= ?)'
        . ' AND (max_uses IS NULL OR used_count < max_uses)',
        [$coupon['id'], utcStamp($moment)]
    );

    if ($burned !== 1) {
        return ['ok' => false, 'error' => 'Купон только что исчерпал свои визиты.', 'coupon' => null];
    }

    return ['ok' => true, 'error' => '', 'coupon' => $coupon];
}

/** Оплата отменённого заказа возвращает купону сгоревший визит — сделка не состоялась. */
function commerceReturnCouponCount(string $code): void
{
    if (trim($code) === '') {
        return;
    }

    run(
        'UPDATE ' . t('commerce_coupon') . ' SET used_count = GREATEST(used_count - 1, 0) WHERE code = ?',
        [$code]
    );
}

// ---------------------------------------------------------------------------
// Промо-правила
// ---------------------------------------------------------------------------

/**
 * Разбор POST формы промо-правила. Периоды по дням, порог — как у купонов; границы
 * проверяются парой: правило не заканчивается раньше, чем начинается.
 *
 * @param array<string, string> $post
 * @return array{errors: array<string, string>, values: array{title: string, kind: string, value: float, min_total: ?float, starts: string, ends: string, active: int}}
 */
function commerceValidatePromo(array $post): array
{
    $errors = [];
    $title = str_replace("\r", '', trim((string) ($post['title'] ?? '')));
    $active = isOn($post['active'] ?? '') ? 1 : 0;

    if ($title === '') {
        $errors['title'] = 'Укажите название правила — покупатель увидит его в корзине.';
    } elseif (mb_strlen($title) > COMMERCE_TITLE_MAX) {
        $errors['title'] = 'Название не длиннее ' . COMMERCE_TITLE_MAX . ' символов.';
    }

    $core = commerceParseDiscountCore($post, $errors);
    $errors = (array) $core['errors'];
    $value = (float) ($core['value'] ?? 0.0);

    $dates = [];

    foreach (['starts' => 'start', 'ends' => 'end'] as $field => $edge) {
        $raw = trim((string) ($post[$field] ?? ''));
        $dates[$field] = '';

        if ($raw === '') {
            continue;
        }

        if (commerceParseDayField($raw, $edge) === null) {
            $errors[$field] = 'Дата не разобрана: нужен формат ГГГГ-ММ-ДД, например 2026-11-30.';
        } else {
            $dates[$field] = $raw;
        }
    }

    // Дата с отказом в $dates не попадает — пустое поле до парной проверки не доходит.
    if ($dates['starts'] !== '' && $dates['ends'] !== ''
        && (int) commerceParseDayField($dates['starts'], 'start')->getTimestamp() > (int) commerceParseDayField($dates['ends'], 'end')->getTimestamp()
    ) {
        $errors['ends'] = 'Правило не заканчивается раньше, чем начинается.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'title' => $title,
            'kind' => $core['kind'],
            'value' => $value,
            'min_total' => $core['min_total'],
            'starts' => $dates['starts'],
            'ends' => $dates['ends'],
            'active' => $active,
        ],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function commercePromosAll(): array
{
    $rows = all(
        'SELECT id, title, kind, value, min_total, starts_at, ends_at, active, created_at'
        . ' FROM ' . t('commerce_promo') . ' ORDER BY created_at DESC, title'
    );

    /** @var list<array<string, mixed>> */
    return $rows;
}

/** @return array<string, mixed>|null */
function commercePromoById(string $id): ?array
{
    $row = one(
        'SELECT id, title, kind, value, min_total, starts_at, ends_at, active'
        . ' FROM ' . t('commerce_promo') . ' WHERE id = ?',
        [$id]
    );

    /** @var array<string, mixed>|null */
    return $row;
}

/**
 * @param array{title: string, kind: string, value: float, min_total: ?float, starts: string, ends: string, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCreatePromo(array $values, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $id = newId();

    insert('commerce_promo', [
        'id' => $id,
        'title' => $values['title'],
        'kind' => $values['kind'],
        'value' => $values['value'],
        'min_total' => $values['min_total'],
        'starts_at' => $values['starts'] !== '' ? commerceDayStamp($values['starts'], 'start') : null,
        'ends_at' => $values['ends'] !== '' ? commerceDayStamp($values['ends'], 'end') : null,
        'active' => $values['active'],
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('commerce.promo_created', ['promoId' => $id, 'title' => $values['title']]);

    return ['ok' => true, 'error' => '', 'notice' => 'promo_saved'];
}

/**
 * @param array{title: string, kind: string, value: float, min_total: ?float, starts: string, ends: string, active: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceUpdatePromo(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $promo = commercePromoById($id);

    if ($promo === null) {
        return ['ok' => false, 'error' => 'Правила уже нет — обновите список.', 'notice' => ''];
    }

    run(
        'UPDATE ' . t('commerce_promo') . ' SET title = ?, kind = ?, value = ?, min_total = ?,'
        . ' starts_at = ?, ends_at = ?, active = ?, updated_at = ? WHERE id = ?',
        [
            $values['title'],
            $values['kind'],
            $values['value'],
            $values['min_total'],
            $values['starts'] !== '' ? commerceDayStamp($values['starts'], 'start') : null,
            $values['ends'] !== '' ? commerceDayStamp($values['ends'], 'end') : null,
            $values['active'],
            utcStamp($now ?? nowUtc()),
            $id,
        ]
    );

    logInfo('commerce.promo_updated', ['promoId' => $id, 'title' => $values['title']]);

    return ['ok' => true, 'error' => '', 'notice' => 'promo_saved'];
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceDeletePromo(string $id): array
{
    $promo = commercePromoById($id);

    if ($promo === null) {
        return ['ok' => false, 'error' => 'Правила уже нет.', 'notice' => ''];
    }

    run('DELETE FROM ' . t('commerce_promo') . ' WHERE id = ?', [$id]);
    logInfo('commerce.promo_deleted', ['promoId' => $id, 'title' => (string) $promo['title']]);

    return ['ok' => true, 'error' => '', 'notice' => 'promo_deleted'];
}

/**
 * Правила, работающие прямо сейчас: включённые и внутри своих периодов. Границы дня
 * честные — правило начинается с 00:00 своего дня и живёт до окна его последнего дня.
 *
 * @return list<array<string, mixed>>
 */
function commerceActivePromos(?DateTimeImmutable $now = null): array
{
    $moment = utcStamp($now ?? nowUtc());

    $rows = all(
        'SELECT id, title, kind, value, min_total FROM ' . t('commerce_promo')
        . ' WHERE active = 1 AND (starts_at IS NULL OR starts_at <= ?)'
        . ' AND (ends_at IS NULL OR ends_at >= ?)'
        . ' ORDER BY value, id',
        [$moment, $moment]
    );

    /** @var list<array<string, mixed>> */
    return $rows;
}

/**
 * Лучшее правило подытога корзины: из работающих правил приходит скидка с самым
 * крупным итогом. Правило с непокрытым порогом молчит — автоскидка не та вещь,
 * о которой читателю корзины стоит спрашивать.
 *
 * @param list<array<string, mixed>> $active
 * @return array{title: string, discount_cents: int}|null
 */
function commerceBestPromoDiscount(array $active, int $subtotalCents): ?array
{
    $best = null;

    foreach ($active as $rule) {
        $minCents = (int) ($rule['min_total'] !== null && $rule['min_total'] !== '' ? commerceCents((float) $rule['min_total']) : 0);

        if ($minCents > $subtotalCents) {
            continue;
        }

        $discount = (string) $rule['kind'] === 'PERCENT'
            ? (int) round($subtotalCents * (float) $rule['value'] / 100.0)
            : min(commerceCents((float) $rule['value']), $subtotalCents);

        if ($discount <= 0) {
            continue;
        }

        if ($best === null || $discount > $best['discount_cents']) {
            $best = ['title' => (string) $rule['title'], 'discount_cents' => $discount];
        }
    }

    /** @var array{title: string, discount_cents: int}|null */
    return $best;
}
