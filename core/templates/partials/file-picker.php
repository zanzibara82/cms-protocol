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
 * @var bool $multiple выбирать несколько файлов сразу (галерея объявления)
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
$multiple = (bool) ($multiple ?? false);
$max = $maxLength > 0 ? ' maxlength="' . e((string) $maxLength) . '"' : '';
$fieldId = 'file-' . ($name !== '' ? $name : $fileKey) . '-' . $fileKey;

// Строка пути нужна только там, где человек задаёт адрес картинки текстом. В медиатеке путь
// решает сервер, поэтому строки пути нет — но пустое место рядом с кнопкой оставлять нельзя:
// поле выглядело иначе, чем соседнее «Название», и было ниже его вдвое.
//
// Рамку несёт ВСЯ строка, а кнопка стоит внутри неё. Пока кнопка была снаружи, рамка поля была
// уже соседнего: 413 px против 465 — ровно кнопка (44) и зазор (8). Подпись накрывает строку
// целиком, поэтому зона нажатия — 44 px по высоте и вся ширина по горизонтали: промахнуться по
// иконке нельзя, а без скрипта кнопка работает (label открывает выбор файла сам).
// Имя выбранного файла подставляет /assets/js/file-field.js: CSP запрещает инлайновый код.
//
// Иконка нарисована прямо в подписи, а не подставлена переменной: страж FilePickerIconTest
// проверяет исходник шаблона и за переменной svg не видит — «кнопка без иконки» ложно сработала
// бы на исправной разметке.

// Рамка общей строки-плейсхолдера: высота задана самой обёртке (h-11 = 44 px вместе с рамкой,
// box-sizing: border-box), а внутренние элементы высоту не навязывают. Иначе 44 px содержимого
// плюс два пикселя рамки давали 46 — на два выше соседних полей.
$rowClass = 'mt-1.5 flex h-11 w-full min-w-0 items-center gap-2 rounded-lg border border-ink/20 bg-white pl-3 pr-1 text-sm';

// Форма без строки пути: подпись накрывает всю строку и открывает выбор файла — промахнуться по
// иконке нельзя. Внутри подписи нет других контролов, поэтому разметка допустимая. Иконка
// нарисована здесь, а не переменной: страж проверяет исходник и за переменной svg не видит.
$placeholderRow = '<label class="' . $rowClass . ' cursor-pointer" for="' . e($fieldId) . '">'
    . '<span class="min-w-0 flex-1 truncate text-ink/60" data-file-placeholder>'
    . ($multiple ? 'Файлы не выбраны' : 'Файл не выбран') . '</span>'
    . '<span class="inline-flex h-9 w-11 shrink-0 items-center justify-center text-ink/70" aria-hidden="true">'
    . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none">'
    . '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
    . '</svg></span>'
    . '</label>';

// Форма со строкой пути: рамку несёт само поле ввода, а кнопка лежит поверх него справа
// (absolute внутри relative-обёртки, у поля — pr-12 под неё). Так высота поля равна соседним
// (min-h-[44px] вместе с рамкой), тогда как рамка на обёртке давала 46 px: 44 содержимого плюс
// два пикселя её собственной рамки.
$textRow = '<div class="relative mt-1.5">'
    . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white pl-3 pr-12 py-2 text-sm"'
    . ' id="' . e($fieldId . '-text') . '" type="text" name="' . e($name) . '" value="' . e($value) . '"' . $max . '>'
    . '<label class="absolute right-1 top-1/2 inline-flex min-h-[44px] min-w-[44px] -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-ink/70 hover:bg-ink/5"'
    . ' for="' . e($fieldId) . '" title="' . e($buttonTitle) . '">'
    . '<span class="inline-flex min-h-[44px] min-w-[44px] shrink-0 items-center justify-center" aria-hidden="true">'
    . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none">'
    . '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
    . '</svg></span>'
    . '<span class="sr-only">' . e($buttonTitle) . '</span></label>'
    . '</div>';

// for подписи указывает на существующий контрол: со строкой пути это текстовая строка, без неё
// подпись не нужна вовсе — доступное имя даёт label-кнопка, и второй label с тем же for создал бы
// два имени у одного контрола (браузер объявляет оба, читалка экрана — тоже).
$caption = $name === ''
    ? '<span class="block text-sm"><span class="block font-medium">' . $label . '</span></span>'
    : '<label class="block text-sm" for="' . e($fieldId . '-text') . '"><span class="block font-medium">' . $label . '</span></label>';

// Вложенных <label> быть не может: подпись-кнопка внутри другой подписи даёт недопустимую
// разметку и путает браузер. Поэтому подпись и строка с полем лежат рядом.
// Отступы заданы явно, а не обёрткой space-y-2: она давала 8 px там, где у обычных полей
// mt-1.5 — 6 px, и строка картинки отставала от соседних полей на 3 px. В строке карточек поля
// стоят рядом, и такая разница видна как разъехавшиеся подписи.
$content = '<div>' . ($preview !== '' ? '<div class="mb-2">' . $preview . '</div>' : '')
    . $caption
    . '<input class="sr-only" id="' . e($fieldId) . '" type="file" name="' . e($fileKey) . '"'
    . ' accept="' . e($accept) . '"' . ($multiple ? ' multiple' : '') . ($required ? ' required' : '') . '>'
    . ($name === '' ? $placeholderRow : $textRow)
    . ($hint !== '' ? '<span class="mt-1.5 block text-ink/60">' . $hint . '</span>' : '')
    . '</div>';
?>
<?= $content /* @safe html */ ?>
