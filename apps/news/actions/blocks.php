<?php

declare(strict_types=1);

// Действия экрана блоков вывода. CSRF проверяется первым: до проверки токена нельзя ни менять
// базу, ни узнавать по ответу, существует ли блок с таким идентификатором.
//
// Идентификатор и значение цели режутся пределами ДО разбора: чужая строка в четыреста символов
// не обязана уходить в `WHERE id = ?` и в составной ключ цели целиком. Для цели это страховка, а
// не правило (правило 14 и шаг 12): отказ при значении длиннее предела и без обрезки один —
// «Выбранной цели уже нет — обновите страницу.».
//
// Ключ уведомления читается в переменную $notice и только потом уходит finishAppAction():
// литерал-аргумент страж NoticeKeyTest прочёл бы как ключ ядра, а тексты этого экрана лежат в
// словаре раздела NEWS_BLOCK_NOTICE_TEXT.

/** @param array<string, string> $params */
function newsBlocksAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);
    $target = parseBlockTarget(mb_substr(trim(postString('target')), 0, NEWS_BLOCK_TARGET_MAX));

    // Порядок веток повторяет NEWS_BLOCK_OPS: страж договора действий сверяет их построчно, и
    // переставленная ветка краснит именем файла, а не падением экрана.
    $result = match ($op) {
        'create' => createBlock(postString('zone'), postString('kind'), postString('title'), (int) postString('take')),
        'rename' => renameBlock($id, postString('title')),
        'retarget' => retargetBlock($id, $target['kind'], $target['id']),
        'limit' => limitBlock($id, (int) postString('take')),
        'show' => setBlockVisibility($id, true),
        'hide' => setBlockVisibility($id, false),
        'up' => moveBlock($id, 'up'),
        'down' => moveBlock($id, 'down'),
        'delete' => deleteBlock($id),
        default => catalogFailure(catalogUnknown('блоком')),
    };

    $notice = (string) ($result['notice'] ?? '');

    finishAppAction($result, NEWS_ADMIN_BLOCKS_PATH, $notice);
}
