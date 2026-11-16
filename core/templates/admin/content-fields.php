<?php

declare(strict_types=1);

// Поля записи: вид поля определяет разметку, но имя формы всегда fields[ключ] — домен читает именно
// его. При отказе обработчик подставляет введённые значения обратно, поэтому человек видит то, что
// набирал, а не пустую карточку. Разметка собирается в $content и печатается один раз под пометкой:
// все значения внутри уже прошли e()/partial()/adminCheckbox().

/** @var list<array<string, mixed>> $fields */
/** @var array<string, string> $data */
/** @var array<string, list<string>> $relationIds */
/** @var array<string, string> $errors */

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$content = '';

foreach ($fields as $field) {
    $key = (string) $field['field_key'];
    $kind = (string) $field['kind'];
    $label = (string) $field['label'];
    $hint = (string) $field['hint'];
    $value = (string) ($data[$key] ?? '');
    $required = (int) $field['required'] === 1;
    $error = (string) ($errors[$key] ?? '');
    $name = 'fields[' . $key . ']';
    $requiredMark = $required ? ' <span class="text-bad">*</span>' : '';
    $star = $required ? ' required' : '';

    $content .= '<div class="min-w-0 rounded-lg border border-ink/10 bg-cream/40 p-3">';

    if ($kind === CONTENT_FIELD_CHECKBOX) {
        $content .= adminCheckbox($name, $label . ($required ? ' *' : ''), '1', $value === '1');
    } elseif ($kind === CONTENT_FIELD_IMAGE) {
        $content .= partial('partials/file-picker', [
            'name' => $name,
            'fileKey' => 'field_files[' . $key . ']',
            'value' => $value,
            'label' => e($label) . $requiredMark,
            'hint' => e($hint),
            'preview' => adminImagePreview($value, $label),
            'inputClass' => $input,
            'maxLength' => CONTENT_FIELD_LIMITS[CONTENT_FIELD_IMAGE],
        ]);
    } else {
        $content .= '<label class="block text-sm"><span class="block font-medium">' . e($label) . $requiredMark . '</span>';

        if ($kind === CONTENT_FIELD_TEXTAREA || $kind === CONTENT_FIELD_RICHTEXT) {
            $content .= '<textarea class="min-h-[44px] ' . e($input) . '" rows="' . ($kind === CONTENT_FIELD_RICHTEXT ? '8' : '4') . '"'
                . ' name="' . e($name) . '"' . $star . '>' . e($value) . '</textarea>';
        } elseif ($kind === CONTENT_FIELD_SELECT) {
            $content .= '<select class="min-h-[44px] ' . e($input) . '" name="' . e($name) . '"' . $star . '>';
            $choices = contentFieldOptions($field)['choices'];

            if ($value !== '' && !in_array($value, $choices, true)) {
                $content .= '<option value="' . e($value) . '" selected>' . e($value) . ' (вариант исчез)</option>';
            }

            foreach ($choices as $choice) {
                $selected = $choice === $value ? ' selected' : '';
                $content .= '<option value="' . e($choice) . '"' . $selected . '>' . e($choice) . '</option>';
            }
            $content .= '</select>';
        } elseif ($kind === CONTENT_FIELD_RELATION) {
            $selectedIds = $relationIds[$key] ?? [];
            $candidates = contentRelationCandidates($field);
            $content .= '<select class="min-h-[44px] ' . e($input) . '" multiple size="5" name="' . e($name) . '[]"' . $star . '>';

            foreach ($candidates as $candidate) {
                $candidateId = (string) $candidate['id'];
                $selected = in_array($candidateId, $selectedIds, true) ? ' selected' : '';
                $content .= '<option value="' . e($candidateId) . '"' . $selected . '>'
                    . e((string) $candidate['title']) . ' — ' . e((string) $candidate['type_name'])
                    . ' (' . e(CONTENT_STATUS_LABELS[(string) $candidate['status']] ?? (string) $candidate['status']) . ')</option>';
            }
            $content .= '</select>';
        } elseif ($kind === CONTENT_FIELD_DATE) {
            $content .= '<input class="min-h-[44px] ' . e($input) . '" type="date" name="' . e($name) . '" value="' . e($value) . '"' . $star . '>';
        } else {
            $type = $kind === CONTENT_FIELD_NUMBER ? 'text' : ($kind === CONTENT_FIELD_URL ? 'url' : 'text');
            $mode = $kind === CONTENT_FIELD_NUMBER ? ' inputmode="decimal"' : '';
            $content .= '<input class="min-h-[44px] ' . e($input) . '" type="' . e($type) . '"' . $mode
                . ' name="' . e($name) . '" value="' . e($value) . '"' . $star . '>';
        }

        if ($hint !== '') {
            $content .= '<span class="mt-1.5 block text-ink/60">' . e($hint) . '</span>';
        }
        $content .= '</label>';
    }

    if ($kind === CONTENT_FIELD_RICHTEXT) {
        $content .= '<p class="mt-1.5 text-xs text-ink/60">Абзацы разделяйте пустой строкой, выделение — **звёздочками**.</p>';
    }

    if ($error !== '') {
        $content .= '<p class="mt-1.5 text-sm text-bad" role="alert">' . e($error) . '</p>';
    }

    $content .= '</div>';
}

/* @safe html */ echo $content;
