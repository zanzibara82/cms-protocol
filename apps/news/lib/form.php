<?php

declare(strict_types=1);

// Правила экрана формы материала: подписи кнопок и значения полей. База здесь не нужна, поэтому
// правила живут отдельно от контроллера и проверяются unit-набором — так же, как полоса вкладок
// и адрес возврата в lib/admin.php.

// Адрес создания. Правка — `/news/admin/items/{id}`, то есть ключ `editHref` строки `itemForForm()`:
// создание и правка различаются адресом, а не полем формы.
const NEWS_ITEM_NEW_PATH = '/news/admin/items/new';

/**
 * Две кнопки формы. В разметке черновик стоит первым: браузер нажимает первую кнопку при Enter в
 * текстовом поле, и первой кнопкой выпуска форма выкладывала бы недописанный материал на сайт.
 * `order-first` в шаблоне возвращает вид из плана — кнопка выпуска слева.
 *
 * `tone` — имя, а не класс: шаблон сам решает, каким классом его раскрасить, как это делает
 * `$opClass()` в списке материалов.
 *
 * @return list<array{intent: string, label: string, tone: string}>
 */
function newsFormButtons(string $status): array
{
    $onSite = $status === NEWS_STATUS['PUBLISHED'];

    return [
        [
            'intent' => 'draft',
            'label' => $onSite ? 'Вернуть в черновики' : 'Сохранить черновик',
            'tone' => $onSite ? 'danger' : 'plain',
        ],
        [
            'intent' => 'publish',
            'label' => $onSite ? 'Сохранить' : 'На печать',
            'tone' => $onSite ? 'plain' : 'primary',
        ],
    ];
}

/**
 * Что показать в полях. Приоритет у того, что человек вписал: отказ обязан вернуть набранный текст,
 * а не то, что лежит в базе (§8.3).
 *
 * @param array<string, mixed> $item строка `itemForForm()`; пустой массив — новый материал
 * @param array<string, string> $raw поля, вернувшиеся из POST
 *
 * @return array<string, string>
 */
function newsFormValues(array $item, array $raw): array
{
    if ($raw !== []) {
        return formValues($raw, NEWS_FORM_FIELDS);
    }

    $out = [];

    foreach (NEWS_FORM_FIELDS as $field) {
        $out[$field] = match ($field) {
            // Флаги возвращаются в том же виде, в котором их отдаёт браузер, — иначе проверка
            // длины увидит одно, а чекбокс другое.
            'pinned', 'noindex' => ($item[$field] ?? false) === true ? 'on' : '',
            // textarea читает текст абзацев, а не JSON: `bodyText` собран `bodyToFormText()`.
            'body' => (string) ($item['bodyText'] ?? ''),
            default => (string) ($item[$field] ?? ''),
        };
    }

    return $out;
}
