<?php

declare(strict_types=1);

// Действие кнопки строки списка. CSRF проверяется первым: до проверки токена нельзя ни менять
// базу, ни узнавать, существует ли материал.
//
// Право на переход проверяет lib/items.php, а не набор кнопок на экране: человек может послать
// op=pin черновику хоть из командной строки, и он обязан получить тот же отказ, что и тот,
// кто читает текст.
/** @param array<string, string> $params */
function newsItemsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);
    $back = newsAdminBackHref(postString('back'));

    $result = match ($op) {
        'publish' => setItemStatus($id, NEWS_STATUS['PUBLISHED']),
        'unpublish' => setItemStatus($id, NEWS_STATUS['DRAFT']),
        'pin' => setItemPinned($id, true),
        'unpin' => setItemPinned($id, false),
        default => itemFailure('Неизвестное действие со материалом.'),
    };

    // Ключ уведомления приходит из слоя данных, а не выбирается здесь: одна и та же кнопка
    // «На печать» отвечает и `published`, и `scheduled` (со сроком в будущем), и `unchanged`
    // (строка уже в этом состоянии). Подстановка ключа по `op` врала бы человеку о результате,
    // поэтому значение читается из ответа базы и передаётся вызову отдельно.
    $notice = (string) ($result['notice'] ?? '');

    finishAppAction($result, $back, $notice);
}
