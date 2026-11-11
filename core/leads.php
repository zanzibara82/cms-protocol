<?php

declare(strict_types=1);

// Заявки с публичных форм: правила, лимиты, чтение и запись. Источник истины — english-portal
// src/lib/leads.ts, src/server/leads.ts и src/server/lead-mail.ts; расхождения записаны в
// спецификации 2026-11-10-leads-mailing-design.md. Форма живёт без JavaScript, поэтому вместо
// счётчика в памяти процесса портала лимиты считаются по таблице tbl_lead_attempt.

// Телефон или почта в одном поле: школы связываются по-разному. «mailto:…» внутри поля — не
// почта, а заготовка для ссылки, и принимать её незачем.
const LEAD_CONTACT_RE = '/^(?:[\d+][\d\s().-]{4,23}|[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[a-z]{2,12})$/i';

// Источник — путь этого сайта без схемы и чужого домена: строку показывают в админке, и держать
// в базе готовую ссылку нельзя. Вложенность ограничена четырьмя уровнями, необязательные query и
// якорь — как в портале.
const LEAD_SOURCE_RE = '/^\/(?:[^\s\/?#]+\/){0,4}[^\s\/?#]{1,80}(?:\?[^\s#]{0,40})?(?:#[^\s\/?#]{1,40})?$/';

const LEAD_STATUS_NEW = 'NEW';
const LEAD_STATUS_DONE = 'DONE';

/** @var array<string, string> */
const LEAD_STATUS_LABELS = [
    LEAD_STATUS_NEW => 'Новая',
    LEAD_STATUS_DONE => 'Обработанная',
];

const LEAD_NAME_MIN = 2;
const LEAD_NAME_MAX = 80;
const LEAD_CONTACT_MIN = 5;
const LEAD_CONTACT_MAX = 120;
const LEAD_MESSAGE_MAX = 1000;
const LEAD_SOURCE_MAX = 120;

const LEAD_REPLY_MIN = 10;
const LEAD_REPLY_MAX = 2000;

// Приём с одного адреса — не чаще пяти в час (портал tooManyRequests('lead-ip:…', 5, 1 час)).
const LEAD_RATE_LIMIT = 5;
const LEAD_RATE_WINDOW_MINUTES = 60;
// Ответ на заявку — не чаще трёх за десять минут (портал tooManyRequests('lead-reply:…', 3, 10 мин)).
const LEAD_REPLY_LIMIT = 3;
const LEAD_REPLY_WINDOW_MINUTES = 10;

const LEADS_PAGE_SIZE = 25;

/**
 * @param array<string, mixed> $data
 */
function leadIsJunk(array $data): bool
{
    return trim((string) ($data['company'] ?? '')) !== '';
}

function leadIsEmail(string $contact): bool
{
    $value = trim($contact);

    return str_contains($value, '@') && preg_match(LEAD_CONTACT_RE, $value) === 1;
}

// Ссылка на контакт в списке администратора: почту и телефон можно взять сразу. Старую или
// нестандартную строку ссылкой не делаем — поле пришло из формы.
function leadContactHref(string $contact): ?string
{
    $value = trim($contact);

    if (preg_match(LEAD_CONTACT_RE, $value) !== 1) {
        return null;
    }

    if (str_contains($value, '@')) {
        return 'mailto:' . $value;
    }

    $digits = (string) preg_replace('/[^\d+]/', '', $value);

    return preg_match('/^(\+?\d{10,15}|\+7\d{10,11})$/', $digits) === 1 ? 'tel:' . $digits : null;
}

function leadSourceOrEmpty(mixed $raw): string
{
    $value = trim((string) $raw);

    return $value !== '' && preg_match(LEAD_SOURCE_RE, $value) === 1 ? $value : '';
}

/**
 * Первая человеческая ошибка формы заявки; пустая строка — заявка годная. Порядок проверок:
 * имя, контакт, сообщение, согласие. Согласие проверяется и здесь, а не только атрибутом
 * `required`: прямой POST мимо браузера не должен создавать заявку без отметки.
 *
 * @param array<string, mixed> $data
 */
function leadValidationError(array $data): string
{
    $name = trim((string) ($data['name'] ?? ''));
    $length = mb_strlen($name);

    if ($length < LEAD_NAME_MIN) {
        return 'Напишите, как к вам обращаться.';
    }

    if ($length > LEAD_NAME_MAX) {
        return 'Имя длиннее ' . LEAD_NAME_MAX . ' символов.';
    }

    $contact = trim((string) ($data['contact'] ?? ''));
    $length = mb_strlen($contact);

    if ($length < LEAD_CONTACT_MIN) {
        return 'Нужен телефон или почта.';
    }

    if ($length > LEAD_CONTACT_MAX) {
        return 'Контакт длиннее ' . LEAD_CONTACT_MAX . ' символов.';
    }

    if (preg_match(LEAD_CONTACT_RE, $contact) !== 1) {
        return 'Оставьте телефон или почту — по ним сможем ответить.';
    }

    $message = trim((string) ($data['message'] ?? ''));

    if (mb_strlen($message) > LEAD_MESSAGE_MAX) {
        return 'Слишком длинное сообщение.';
    }

    // Согласие — не украшение формы: без него заявку не принимаем, даже если браузерная
    // проверка обойдена. isOn() понимает значения, которые шлют чекбоксы: 1, on, true.
    if (!isOn($data['consent'] ?? '')) {
        return 'Отметьте согласие на обработку персональных данных.';
    }

    return '';
}

/**
 * Лимит «проверил и записал»: счётчик портала увеличивался на каждом вызове и возвращал отказ,
 * когда число попыток переваливало за предел. Здесь то же самое таблицей: вызов старше окна или
 * первый — пишет строку и разрешает, вызов сверх предела — отказывает до записи.
 */
function leadRateTooMany(string $scope, int $limit, int $windowMinutes, ?DateTimeImmutable $now = null): bool
{
    $moment = $now ?? nowUtc();
    $since = utcStamp($moment->sub(new DateInterval('PT' . $windowMinutes . 'M')));
    $count = (int) value(
        'SELECT COUNT(*) FROM ' . t('lead_attempt') . ' WHERE scope = ? AND created_at > ?',
        [$scope, $since]
    );

    if ($count >= $limit) {
        return true;
    }

    insert('lead_attempt', ['scope' => $scope, 'created_at' => utcStamp($moment)]);

    return false;
}

/**
 * @param array<string, mixed> $data
 * @return array{ok: bool, error: string}
 */
function leadValidationResult(array $data): array
{
    $error = leadValidationError($data);

    return $error === '' ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => $error];
}

/**
 * Запись заявки. source уже нормализован leadSourceOrEmpty(), message без текста — NULL, а не
 * пустая строка (так строку легче отличить от «сообщения нет» в списке).
 *
 * @param array<string, mixed> $data
 * @return array{ok: bool, error: string, id: string}
 */
function leadCreate(array $data, ?DateTimeImmutable $now = null): array
{
    $id = newId();
    $message = trim((string) ($data['message'] ?? ''));

    try {
        insert('lead', [
            'id' => $id,
            'name' => trim((string) ($data['name'] ?? '')),
            'contact' => trim((string) ($data['contact'] ?? '')),
            'message' => $message !== '' ? $message : null,
            'source' => (string) ($data['source'] ?? ''),
            'status' => LEAD_STATUS_NEW,
            'created_at' => utcStamp($now ?? nowUtc()),
        ]);
    } catch (Throwable $error) {
        logError('lead.create_failed', ['reason' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Заявка не сохранилась — попробуйте ещё раз.', 'id' => ''];
    }

    logInfo('lead.created', ['id' => $id]);

    return ['ok' => true, 'error' => '', 'id' => $id];
}

/** @return array<string, mixed>|null */
function leadRow(string $id): ?array
{
    return one(
        'SELECT id, name, contact, message, source, status, done_at, confirm_sent_at, reply_text, reply_sent_at, created_at'
        . ' FROM ' . t('lead') . ' WHERE id = ?',
        [$id]
    );
}

/**
 * Список админки: фильтр по статусу, поиск по имени и контакту, 25 на страницу, новые сверху.
 * Поиск ходит через escapeLike() и ESCAPE — спецсимволы ищутся как текст, как в справочниках
 * новостей. status не NEW и не DONE (например ALL) фильтра не добавляет.
 *
 * @return array{rows: list<array<string, mixed>>, total: int, pages: int}
 */
function leadsPage(string $status, string $query, int $page): array
{
    $where = [];
    $args = [];

    if ($status === LEAD_STATUS_NEW || $status === LEAD_STATUS_DONE) {
        $where[] = 'status = ?';
        $args[] = $status;
    }

    $q = trim($query);

    if ($q !== '') {
        $like = '%' . escapeLike($q) . '%';
        // Знак снятия объявляем связываемым параметром, как в новостях и на доске: литерал
        // обратного слэша в тексте SQL живёт только в одном режиме (`ESCAPE '\\'` ломается при
        // `NO_BACKSLASH_ESCAPES`, `ESCAPE '\'` — в режиме по умолчанию), а бинд даёт ровно один
        // слэш в обоих. Без объявления поиск по «100%» ищет уже не то.
        $where[] = '(name LIKE ? ESCAPE ? OR contact LIKE ? ESCAPE ?)';
        $args[] = $like;
        $args[] = '\\';
        $args[] = $like;
        $args[] = '\\';
    }

    $tail = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $total = (int) value('SELECT COUNT(*) FROM ' . t('lead') . $tail, $args);
    $pages = max(1, (int) ceil($total / LEADS_PAGE_SIZE));
    $current = min(max(1, $page), $pages);

    $rows = all(
        'SELECT id, name, contact, message, source, status, done_at, confirm_sent_at, reply_text, reply_sent_at, created_at'
        . ' FROM ' . t('lead') . $tail
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . LEADS_PAGE_SIZE . ' OFFSET ' . (($current - 1) * LEADS_PAGE_SIZE),
        $args
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
}

/** @return array{ok: bool, error: string} */
function leadSetStatus(string $id, string $status, ?DateTimeImmutable $now = null): array
{
    if (!in_array($status, [LEAD_STATUS_NEW, LEAD_STATUS_DONE], true)) {
        return ['ok' => false, 'error' => 'Неизвестный статус заявки.'];
    }

    if (leadRow($id) === null) {
        return ['ok' => false, 'error' => 'Заявка не найдена — обновите страницу.'];
    }

    $doneAt = $status === LEAD_STATUS_DONE ? utcStamp($now ?? nowUtc()) : null;
    run('UPDATE ' . t('lead') . ' SET status = ?, done_at = ? WHERE id = ?', [$status, $doneAt, $id]);
    logInfo('lead.status_saved', ['id' => $id, 'status' => $status]);

    return ['ok' => true, 'error' => ''];
}

/** @return array{ok: bool, error: string} */
function leadDelete(string $id): array
{
    $deleted = run('DELETE FROM ' . t('lead') . ' WHERE id = ?', [$id]);

    if ($deleted === 0) {
        return ['ok' => false, 'error' => 'Заявка не найдена — обновите страницу.'];
    }

    logInfo('lead.deleted', ['id' => $id]);

    return ['ok' => true, 'error' => ''];
}

/**
 * Отправка письма по заявке через ядро. Транспорт передаётся замыканием, чтобы тесты подменяли
 * SMTP-контур, не трогая настройки окружения.
 *
 * @param array<string, mixed> $message
 * @return array{ok: bool, error: string}
 */
function leadSend(array $message, ?callable $deliver = null): array
{
    $mailer = new \Cms\Core\Mailer($deliver);

    return $mailer->send($message);
}

/**
 * Подтверждение приёма. Провал не доходит до посетителя: обращение уже в базе, администратор его
 * видит, поэтому функция ничего не бросает, а пишет причину в журнал. Письмо уходит только на почту.
 */
function leadConfirmSend(string $id, string $name, string $contact, ?callable $send = null, ?DateTimeImmutable $now = null): void
{
    if (!leadIsEmail($contact)) {
        return;
    }

    $send = $send ?? static fn (array $message): array => leadSend($message);
    $result = $send([
        'to' => trim($contact),
        'subject' => leadConfirmSubject(),
        'text' => leadConfirmText($name),
        'html' => leadConfirmHtml($name),
    ]);

    if (($result['ok'] ?? false) === true) {
        run('UPDATE ' . t('lead') . ' SET confirm_sent_at = ? WHERE id = ?', [utcStamp($now ?? nowUtc()), $id]);
        logInfo('lead.confirm_sent', ['id' => $id]);

        return;
    }

    logError('lead.confirm_failed', ['id' => $id, 'reason' => (string) ($result['error'] ?? '')]);
}

/**
 * Ответ администратора письмом. Возвращает текст ошибки либо ok; на успех пишет последний текст,
 * время отправки и переводит заявку в обработанную — повторное письмо правят, а не пишут с нуля.
 *
 * @return array{ok: bool, error: string}
 */
function leadReplySend(string $id, string $text, string $actor, ?callable $send = null, ?DateTimeImmutable $now = null): array
{
    if (leadRateTooMany('reply:' . $id, LEAD_REPLY_LIMIT, LEAD_REPLY_WINDOW_MINUTES, $now)) {
        return ['ok' => false, 'error' => 'По этой заявке письма уже уходили — подождите десять минут.'];
    }

    $clean = trim($text);
    $length = mb_strlen($clean);

    if ($length < LEAD_REPLY_MIN) {
        return ['ok' => false, 'error' => 'Ответ слишком короткий — напишите хотя бы ' . LEAD_REPLY_MIN . ' символов.'];
    }

    if ($length > LEAD_REPLY_MAX) {
        return ['ok' => false, 'error' => 'Ответ длиннее ' . LEAD_REPLY_MAX . ' символов.'];
    }

    $lead = leadRow($id);

    if ($lead === null) {
        return ['ok' => false, 'error' => 'Заявка не найдена — обновите страницу.'];
    }

    if (!leadIsEmail((string) $lead['contact'])) {
        return ['ok' => false, 'error' => 'Письмо некуда отправить: в заявке оставлен телефон. Позвоните или напишите в мессенджер.'];
    }

    $send = $send ?? static fn (array $message): array => leadSend($message);
    $result = $send([
        'to' => trim((string) $lead['contact']),
        'subject' => leadReplySubject(),
        'text' => leadReplyText((string) $lead['name'], $clean),
        'html' => leadReplyHtml((string) $lead['name'], $clean),
    ]);

    if (($result['ok'] ?? false) !== true) {
        logError('lead.reply_failed', ['id' => $id, 'actor' => $actor, 'reason' => (string) ($result['error'] ?? '')]);

        return ['ok' => false, 'error' => 'Письмо не ушло — почта отключена или не настроена. Проверьте настройки SMTP и попробуйте ещё раз.'];
    }

    $moment = utcStamp($now ?? nowUtc());
    run(
        'UPDATE ' . t('lead') . ' SET reply_text = ?, reply_sent_at = ?, status = ?, done_at = ? WHERE id = ?',
        [$clean, $moment, LEAD_STATUS_DONE, $moment, $id]
    );
    logInfo('lead.reply_sent', ['id' => $id, 'actor' => $actor]);

    return ['ok' => true, 'error' => ''];
}
