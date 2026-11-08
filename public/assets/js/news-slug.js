/*
 * Адрес материала подставляется из заголовка, пока человек не начал править его сам.
 * Транслит повторяет core/text.php::slugify(): тот же алфавит, те же разделители и тот же
 * предел длины. Сервер всё равно чистит поле перед записью, поэтому скрипт — подсказка,
 * а не источник истины.
 */
(function () {
    'use strict';

    var title = document.getElementById('f-title');
    var slug = document.getElementById('f-slug');

    if (!title || !slug) {
        return;
    }

    var TRANSLIT = {
        'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo',
        'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
        'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
        'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch', 'ъ': '',
        'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya'
    };

    function slugify(value, max) {
        var out = '';
        var chars = Array.from(value.trim().toLowerCase());

        for (var i = 0; i < chars.length; i++) {
            var char = chars[i];

            if (Object.prototype.hasOwnProperty.call(TRANSLIT, char)) {
                out += TRANSLIT[char];
            } else if (/[a-z0-9]/.test(char)) {
                out += char;
            } else if (/[\s\-_.\/]/.test(char)) {
                out += '-';
            }
        }

        out = out.replace(/-+/g, '-').replace(/^-+|-+$/g, '');

        return out.slice(0, max).replace(/-+$/g, '');
    }

    var max = slug.maxLength > 0 ? slug.maxLength : 80;
    var manual = slug.value !== '';

    slug.addEventListener('input', function () {
        manual = slug.value !== '';
    });

    title.addEventListener('input', function () {
        if (manual) {
            return;
        }

        slug.value = slugify(title.value, max);
    });

    slug.addEventListener('blur', function () {
        slug.value = slugify(slug.value, max);

        if (slug.value === '') {
            slug.value = slugify(title.value, max);
        }

        manual = slug.value !== '';
    });

    if (!manual) {
        slug.value = slugify(title.value, max);
    }
})();
