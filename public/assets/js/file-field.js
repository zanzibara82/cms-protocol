/*
 * Показ имени выбранного файла рядом с кнопкой-иконкой.
 *
 * Скрипт необязательный: без него кнопка выбирает файл и форма отправляется — человек просто не
 * видит имени до отправки. Поведение живёт в файле, а не в разметке, потому что CSP сайта
 * (`script-src 'self'`) запрещает инлайновый код.
 *
 * Строку-плейсхолдер печатает partials/file-picker.php: у медиатеки, прайс-листа и формы
 * объявления путь задаёт сервер, поэтому текстовой строки пути там нет, и без плейсхолдера поле
 * было ниже соседнего. Имя файла подставляется в неё же и обрезается по длине: длинное имя с
 * путём не должно раздвигать строку.
 */
(function () {
    'use strict';

    var LIMIT = 60;

    function shorten(names) {
        var text = names.join(', ');

        if (text.length <= LIMIT) {
            return text;
        }

        return text.slice(0, LIMIT - 1) + '…';
    }

    Array.prototype.forEach.call(document.querySelectorAll('input[type="file"]'), function (input) {
        var id = input.getAttribute('id');

        if (!id) {
            return;
        }

        // Строка-плейсхолдер стоит рядом с кнопкой в том же ряду: ищем внутри общего контейнера.
        var row = input.parentNode;
        var placeholder = row ? row.querySelector('[data-file-placeholder]') : null;

        if (!placeholder) {
            return;
        }

        input.addEventListener('change', function () {
            var files = input.files;

            if (!files || files.length === 0) {
                placeholder.textContent = input.hasAttribute('multiple') ? 'Файлы не выбраны' : 'Файл не выбран';

                return;
            }

            var names = [];

            Array.prototype.forEach.call(files, function (file) {
                names.push(String(file.name));
            });

            placeholder.textContent = shorten(names);
        });
    });
})();
