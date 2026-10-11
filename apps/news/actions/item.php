<?php

declare(strict_types=1);

// Сохранение материала из формы. CSRF первым: до проверки токена нельзя ни узнавать, существует ли
// строка, ни трогать диск.
//
// Успех уходит PRG-редиректом, отказ остаётся на этом же адресе ответом 200: редирект потерял бы
// набранный текст, а §8.3 требует вернуть его в форму.
/** @param array<string, string> $params */
function newsItemSaveAction(array $params = []): void
{
    csrfCheck();

    $access = getEditorAccess();

    if ($access['kind'] !== 'granted') {
        // Гейт маршрута отвечает 403 анониму; здесь та же проверка держит смену роли и блокировку
        // посреди открытой формы.
        forbidden();
    }

    $editor = $access['editor'];
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, NEWS_ID_MAX);
    $back = newsAdminBackHref(postString('back'));
    $item = $id === '' ? [] : (array) itemForForm($id);

    if ($id !== '' && $item === []) {
        // Строку удалили, пока форма была открыта: тот же канал отказа, что у кнопок списка.
        finishAppAction(itemFailure('Материала уже нет — обновите страницу.'), $back, '');
    }

    // Статус решает нажатая кнопка. Скрытое поле `status` приходит раньше кнопки, и его чтение
    // вернуло бы прошлый статус, а не выбранное действие (NEWS_STATUS_BY_INTENT, §8.3).
    $raw = formValues(postAll(), NEWS_FORM_FIELDS);
    $raw['status'] = resolveStatus(postString('intent'), $raw['status']);

    $parsed = parseNewsForm($raw);

    if (isset($parsed['errors'])) {
        renderNewsItemForm($item, $raw, $parsed['errors'], $back);

        return;
    }

    $data = $parsed['data'];
    $file = uploadedFile('cover_file');
    $href = '';

    if ($file !== null) {
        $stored = newsImageStore($file);

        if (($stored['ok'] ?? false) !== true) {
            renderNewsItemForm($item, $raw, ['cover' => (string) $stored['error']], $back);

            return;
        }

        // Присланный файл важнее текстового поля: сервер решил имя и формат, а путь из `cover`
        // человек мог вписать старый.
        $href = (string) $stored['href'];
        $data['cover'] = $href;
    }

    $result = $id === '' ? createItem($data, $editor) : updateItem($id, $data, $editor);

    if (($result['ok'] ?? false) !== true) {
        if ($href !== '') {
            // Файл уже на диске, но на него никто не сослался: удалить дешевле, чем оставить
            // осиротевшую картинку, которую никто не найдёт.
            newsImageDelete($href);
        }

        renderNewsItemForm($item, $raw, [], $back, (string) $result['error']);

        return;
    }

    // Ключ уведомления приходит из слоя данных, а не выбирается здесь: значение читается в
    // переменную и только потом уходит вызову — так же, как в `newsItemsAction()`. Ключ-литерал в
    // аргументе страж `NoticeKeyTest` прочёл бы как ключ ядра, а этот ключ живёт в словаре
    // раздела (`NEWS_NOTICE_TEXT`), не в `CORE_NOTICE_TEXT`.
    $notice = (string) ($result['notice'] ?? '');

    redirectWithNotice($back, $notice);
}
