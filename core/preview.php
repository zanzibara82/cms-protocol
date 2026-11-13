<?php

declare(strict_types=1);

// Безопасный предпросмотр черновика по ссылке. В базе лежит только SHA-256-хеш токена: ссылка
// показывается человеку один раз, а утечка таблицы не открывает черновик. Ссылка живёт неделю,
// отзывается вручную и не даёт править страницу — только читать её текущий вид.

const PREVIEW_TOKEN_TTL_DAYS = 7;

/**
 * @return array{ok: bool, error: string, id: string, token: string, url: string, expires: string}
 */
function previewTokenCreate(string $pageId, string $userId): array
{
    if (pageById($pageId) === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'id' => '', 'token' => '', 'url' => '', 'expires' => ''];
    }

    $raw = bin2hex(random_bytes(32));
    $id = newId();
    $now = nowUtc();
    $expires = shiftDays($now, PREVIEW_TOKEN_TTL_DAYS);

    insert('preview_token', [
        'id' => $id,
        'token_hash' => hash('sha256', $raw),
        'page_id' => $pageId,
        'created_by' => $userId !== '' ? $userId : null,
        'created_at' => utcStamp($now),
        'expires_at' => utcStamp($expires),
        'revoked_at' => null,
    ]);

    auditLog('preview.create', 'page', $pageId, ['tokenId' => $id], 'Создана ссылка предпросмотра');

    return [
        'ok' => true,
        'error' => '',
        'id' => $id,
        'token' => $raw,
        'url' => '/preview/' . $raw,
        'expires' => utcStamp($expires),
    ];
}

/**
 * @return array<string, mixed>|null строка ссылки вместе с page_id
 */
function previewTokenResolve(string $raw): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
        return null;
    }

    $row = one(
        'SELECT id, page_id, expires_at, revoked_at FROM ' . t('preview_token') . ' WHERE token_hash = ?',
        [hash('sha256', $raw)]
    );

    if ($row === null || $row['revoked_at'] !== null) {
        return null;
    }

    if ((string) $row['expires_at'] <= utcStamp(nowUtc())) {
        return null;
    }

    return $row;
}

/**
 * @return list<array{id: string, created_at: string, expires_at: string, revoked_at: string}>
 */
function previewTokensForPage(string $pageId): array
{
    $rows = [];

    foreach (all(
        'SELECT id, created_at, expires_at, revoked_at FROM ' . t('preview_token')
        . ' WHERE page_id = ? ORDER BY created_at DESC LIMIT 20',
        [$pageId]
    ) as $row) {
        $rows[] = [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'expires_at' => (string) $row['expires_at'],
            'revoked_at' => (string) ($row['revoked_at'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function previewTokenRevoke(string $id, string $pageId): array
{
    $changed = run(
        'UPDATE ' . t('preview_token') . ' SET revoked_at = ? WHERE id = ? AND page_id = ? AND revoked_at IS NULL',
        [utcStamp(nowUtc()), $id, $pageId]
    );

    if ($changed === 0) {
        return ['ok' => false, 'error' => 'Ссылка не найдена или уже отозвана.', 'notice' => ''];
    }

    auditLog('preview.revoke', 'page', $pageId, ['tokenId' => $id], 'Ссылка предпросмотра отозвана');

    return ['ok' => true, 'error' => '', 'notice' => 'preview_revoked'];
}

// Сырая ссылка показывается один раз: после PRG она едет через сессию и стирается при чтении.
function previewFlashSet(string $url): void
{
    startSession();
    $_SESSION['preview_link'] = $url;
}

function previewFlashTake(): string
{
    startSession();
    $url = $_SESSION['preview_link'] ?? '';
    unset($_SESSION['preview_link']);

    return is_string($url) ? $url : '';
}
