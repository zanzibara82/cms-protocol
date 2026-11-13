<?php

declare(strict_types=1);

/**
 * Поле картинки: текстовая строка пути и кнопка выбора файла с иконкой папки.
 *
 * Почему партиал, а не набор классов в шаблонах: разметка обязана лежать в каталоге, который
 * читает сборка стилей (StyleSourceTest), иначе классы не попадут в артефакт. Иконка папки
 * повторялась в трёх шаблонах копией, и в SEO-экранах пропала — общий помощник закрывает это
 * место раз и навсегда.
 *
 * Нативное поле файла скрыто (sr-only): рисунок и подпись кнопки задаёт label, зону нажатия
 * 44 px несёт он же. Без скрипта кнопка работает: label с for открывает выбор файла сам.
 *
 * Две формы: с текстовой строкой пути (конструктор страниц, SEO-экраны) и только кнопкой
 * (медиатека, где путь задаёт сервер). Пустое имя поля пути = только кнопка — иначе скрытая
 * строка совпала бы по имени с полем названия и перетёрла бы его.
 *
 * @var string $name имя поля пути (то, что видит сервер); пусто — только кнопка выбора файла
 * @var string $fileKey имя поля файла (`image_file`, `cover_file`, …)
 * @var string $value текущий путь
 * @var string $label подпись над полем, уже экранированная вызывающим, если из данных
 * @var string $hint подсказка под строкой пути
 * @var string $inputClass класс текстовой строки (по умолчанию — как у полей админки)
 * @var string $buttonTitle что делает кнопка (для title и sr-only)
 * @var string $preview готовое превью от adminImagePreview() или пустая строка
 * @var int $maxLength предел длины пути, 0 — без ограничения
 * @var string $accept какие файлы принимать (по умолчанию картинки)
 * @var bool $required нативная проверка браузера: без файла форма не уходит
 */
$name = (string) ($name ?? 'image');
$fileKey = (string) ($fileKey ?? 'image_file');
$value = (string) ($value ?? '');
$label = (string) ($label ?? 'Картинка');
$hint = (string) ($hint ?? '');
$inputClass = (string) ($inputClass ?? 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm');
$buttonTitle = (string) ($buttonTitle ?? 'Выбрать картинку с компьютера');
$preview = (string) ($preview ?? '');
$maxLength = (int) ($maxLength ?? 0);
$accept = (string) ($accept ?? 'image/jpeg,image/png,image/webp');
$required = (bool) ($required ?? false);
$max = $maxLength > 0 ? ' maxlength="' . e((string) $maxLength) . '"' : '';
$fieldId = 'file-' . ($name !== '' ? $name : $fileKey) . '-' . $fileKey;

// Строка пути нужна только там, где человек задаёт адрес картинки текстом. В медиатеке путь
// решает сервер, поэтому остаётся одна кнопка — и имя поля названия не перетирается.
$textInput = $name === '' ? '' : '<input class="min-h-[44px] min-w-0 flex-1 ' . e($inputClass) . '" id="' . e($fieldId . '-text') . '"'
    . ' type="text" name="' . e($name) . '" value="' . e($value) . '"' . $max . '>';

// for подписи указывает на существующий контрол: со строкой пути это текстовая строка, без неё
// подпись не нужна вовсе — доступное имя даёт label-кнопка, и второй label с тем же for создал бы
// два имени у одного контрола (браузер объявляет оба, читалка экрана — тоже).
$caption = $name === ''
    ? '<span class="block text-sm"><span class="block font-medium">' . $label . '</span></span>'
    : '<label class="block text-sm" for="' . e($fieldId . '-text') . '"><span class="block font-medium">' . $label . '</span></label>';

// Вложенных <label> быть не может: внешний label-обёртка и label-кнопка внутри него дают
// недопустимую разметку и путают браузер. Поэтому подпись и строка с полем лежат рядом.
$content = '<div class="space-y-2">' . $preview
    . $caption
    . '<div class="flex items-start gap-2">'
    . $textInput
    . '<input class="sr-only" id="' . e($fieldId) . '" type="file" name="' . e($fileKey) . '"'
    . ' accept="' . e($accept) . '"' . ($required ? ' required' : '') . '>'
    . '<label class="inline-flex min-h-[44px] min-w-[44px] shrink-0 cursor-pointer items-center justify-center rounded-lg border border-ink/20 bg-white hover:bg-ink/5"'
    . ' for="' . e($fieldId) . '" title="' . e($buttonTitle) . '">'
    . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    . '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
    . '</svg>'
    . '<span class="sr-only">' . e($buttonTitle) . '</span></label>'
    . '</div>'
    . ($hint !== '' ? '<span class="block text-ink/60">' . $hint . '</span>' : '')
    . '</div>';
?>
<?= $content /* @safe html */ ?>
