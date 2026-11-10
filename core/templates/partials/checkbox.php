<?php

declare(strict_types=1);

/**
 * Чекбокс с подписью: поле скрыто (sr-only), квадратик нарисован соседним span, зону нажатия
 * 44 px несёт подпись. Приём взят из админки (adminCheckbox в admin/layout.php), здесь он
 * доступен теме и приложениям — публичная форма заявки повторяла ошибку, от которой помощника
 * не было.
 *
 * Почему не нативный чекбокс: TapTargetTest требует от поля не меньше 44 px, но min-h-[44px] на
 * нативном чекбоксе растягивает само поле (20×44), а браузер рисует квадратик по центру этой
 * высоты — при подписи по верхнему краю флажок уезжает на 14 px ниже текста. Замер на стенде и
 * разбор — в tests/guard/CheckboxAlignTest.php.
 *
 * Партиал, а не функция в core/form.php: разметка обязана лежать в каталоге, который читает
 * сборка стилей (StyleSourceTest), иначе её классы не попадут в артефакт.
 *
 * @var string $name
 * @var string $label уже экранирован вызывающим, если пришёл из данных
 * @var string $value
 * @var bool $checked
 * @var bool $required нативная проверка браузера: согласие без отметки не уходит
 */
$name = (string) ($name ?? '');
$label = (string) ($label ?? '');
$value = (string) ($value ?? '1');
$checked = (bool) ($checked ?? false);
$required = (bool) ($required ?? false);

$content = '<label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm select-none">'
    . '<input class="peer sr-only" type="checkbox" name="' . e($name) . '" value="' . e($value) . '"'
    . ($checked ? ' checked' : '') . ($required ? ' required' : '') . '>'
    . '<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded border border-ink/20'
    . ' bg-white text-transparent peer-checked:bg-ink peer-checked:text-cream'
    . ' peer-focus-visible:ring-2 peer-focus-visible:ring-accent" aria-hidden="true">'
    . '<svg width="12" height="12" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
    . '<path d="M4 10.5 8 14.5 16 5.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>'
    . '</svg></span>'
    . '<span>' . $label . '</span>'
    . '</label>';
?>
<?= $content /* @safe html */ ?>
