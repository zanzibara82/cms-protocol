/*
 * Плавающая кнопка сохранения блоков страницы. Скрипт необязательный: без него каждая секция
 * сохраняется своей кнопкой, а плавающая остаётся неактивной. Правила простые:
 *  - состояние «есть изменения» считается по значениям полей относительно загруженной страницы;
 *  - перед отправкой каждая форма проходит браузерную проверку полей;
 *  - изменённые формы уходят по очереди через fetch с заголовком, по которому сервер отвечает
 *    JSON вместо PRG-редиректа. После успеха страница перезагружается с уведомлением.
 */
(function () {
    'use strict';

    // Свёрнутые блоки переживают сохранение: страница после отправки перезагружается, и без
    // сохранённого состояния все карточки раскрылись бы заново. Ключ привязан к адресу страницы,
    // а состояние хранится по идентификатору секции, поэтому перестановка блоков его не сбивает.
    var collapsedKey = 'cms-sections-collapsed:' + window.location.pathname;
    var collapsed = {};

    try {
        var saved = JSON.parse(window.localStorage.getItem(collapsedKey) || '{}');
        collapsed = saved && typeof saved === 'object' ? saved : {};
    } catch (error) {
        collapsed = {};
    }

    function saveCollapsed() {
        try {
            window.localStorage.setItem(collapsedKey, JSON.stringify(collapsed));
        } catch (error) {
            // Хранилище недоступно: блоки останутся раскрытыми после перезагрузки, но страница работает.
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('details[data-section-id]'), function (details) {
        var id = details.getAttribute('data-section-id');

        if (!id) {
            return;
        }

        if (collapsed[id] === true) {
            details.open = false;
        }

        details.addEventListener('toggle', function () {
            if (details.open) {
                delete collapsed[id];
            } else {
                collapsed[id] = true;
            }

            saveCollapsed();
        });
    });

    // Плюс у повторяющихся строк (карточки, вопросы-ответы, шаги): добавляет ещё одну пустую
    // строку с теми же полями и следующим индексом items[N][…]. Без скрипта кнопка скрыта,
    // а сервер по-прежнему печатает одну пустую строку для следующего сохранения.
    function clearControl(control) {
        if (control.tagName === 'SELECT') {
            control.selectedIndex = 0;

            return;
        }

        if (control.type === 'checkbox' || control.type === 'radio') {
            control.checked = false;

            return;
        }

        control.value = '';
    }

    function renameIndex(value, from, to) {
        return value.replace(new RegExp('items\\[' + from + '\\]', 'g'), 'items[' + to + ']');
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-items-add]'), function (addButton) {
        addButton.hidden = false;

        addButton.addEventListener('click', function () {
            var editor = addButton.parentNode;

            while (editor && !editor.hasAttribute('data-items-editor')) {
                editor = editor.parentNode;
            }

            if (!editor) {
                return;
            }

            var rows = editor.querySelector('[data-items-rows]');
            var source = rows ? rows.lastElementChild : null;

            if (!rows || !source) {
                return;
            }

            var nextIndex = 0;
            Array.prototype.forEach.call(rows.querySelectorAll('[data-item-row]'), function (row) {
                var index = parseInt(row.getAttribute('data-item-row') || '0', 10);

                if (index >= nextIndex) {
                    nextIndex = index + 1;
                }
            });

            var previousIndex = parseInt(source.getAttribute('data-item-row') || '0', 10);
            var row = source.cloneNode(true);
            row.setAttribute('data-item-row', String(nextIndex));

            Array.prototype.forEach.call(row.querySelectorAll('input, select, textarea'), function (control) {
                var name = control.getAttribute('name');
                var controlId = control.getAttribute('id');

                if (name) {
                    control.setAttribute('name', renameIndex(name, previousIndex, nextIndex));
                }

                if (controlId) {
                    control.setAttribute('id', renameIndex(controlId, previousIndex, nextIndex));
                }

                clearControl(control);
            });

            Array.prototype.forEach.call(row.querySelectorAll('label[for]'), function (label) {
                label.setAttribute('for', renameIndex(label.getAttribute('for') || '', previousIndex, nextIndex));
            });

            rows.appendChild(row);

            var first = row.querySelector('input, select, textarea');
            if (first) {
                first.focus({preventScroll: true});
            }

            row.scrollIntoView({block: 'nearest'});
        });
    });

    var button = document.querySelector('[data-save-button]');

    if (!button) {
        return;
    }

    var forms = Array.prototype.slice.call(document.querySelectorAll('form[data-section-form]'));

    if (forms.length === 0) {
        return;
    }

    var errorBox = document.querySelector('[data-save-error]');
    var status = document.querySelector('[data-save-status]');
    var initial = new Map();

    function controlValue(control) {
        if (control.type === 'checkbox' || control.type === 'radio') {
            return control.checked ? '1' : '0';
        }

        if (control.tagName === 'SELECT' && control.multiple) {
            return Array.prototype.map.call(control.options, function (option) {
                return option.selected ? '1' : '0';
            }).join('');
        }

        return control.value;
    }

    function controlsOf(form) {
        return Array.prototype.filter.call(form.elements, function (element) {
            if (!element.name) {
                return false;
            }

            return ['file', 'submit', 'button', 'reset'].indexOf(element.type) === -1;
        });
    }

    forms.forEach(function (form) {
        var values = new Map();

        controlsOf(form).forEach(function (control) {
            values.set(control, controlValue(control));
        });

        initial.set(form, values);
    });

    function isDirty(form) {
        var dirty = false;
        var known = initial.get(form) || new Map();

        known.forEach(function (value, control) {
            if (!dirty && controlValue(control) !== value) {
                dirty = true;
            }
        });

        if (dirty) {
            return true;
        }

        // Строка, добавленная плюсом, в снимке отсутствует: заполненное новое поле — изменение.
        var added = Array.prototype.some.call(controlsOf(form), function (control) {
            return !known.has(control) && controlValue(control) !== '';
        });

        if (added) {
            return true;
        }

        return Array.prototype.some.call(form.querySelectorAll('input[type="file"]'), function (input) {
            return input.files && input.files.length > 0;
        });
    }

    function dirtyForms() {
        return forms.filter(isDirty);
    }

    function showError(message) {
        if (!errorBox) {
            return;
        }

        errorBox.textContent = message;
        errorBox.hidden = message === '';
    }

    function refresh() {
        var dirty = dirtyForms();
        var active = dirty.length > 0;

        button.disabled = !active;
        button.setAttribute('data-dirty', active ? '1' : '0');
        button.textContent = dirty.length > 1 ? 'Сохранить (' + dirty.length + ')' : 'Сохранить';

        if (status) {
            status.textContent = active
                ? 'Есть несохранённые изменения: ' + dirty.length
                : 'Все изменения сохранены';
        }

        if (!active) {
            showError('');
        }
    }

    document.addEventListener('input', refresh, true);
    document.addEventListener('change', refresh, true);
    document.addEventListener('reset', function () {
        window.setTimeout(refresh, 0);
    }, true);

    button.addEventListener('click', function () {
        var dirty = dirtyForms();

        if (dirty.length === 0) {
            return;
        }

        showError('');
        button.disabled = true;
        button.setAttribute('data-dirty', '0');
        button.textContent = 'Сохраняем…';

        var index = 0;
        var lastNotice = '';

        function fail(message) {
            showError(message || 'Не удалось сохранить изменения — попробуйте ещё раз.');
            refresh();
        }

        function next() {
            if (index >= dirty.length) {
                window.location.href = window.location.pathname
                    + (lastNotice === '' ? '' : '?notice=' + encodeURIComponent(lastNotice));

                return;
            }

            var form = dirty[index++];

            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                fail('Проверьте поля блока — браузер отметил незаполненное.');

                return;
            }

            window.fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {'X-CMS-Section-Save': '1'}
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            }).then(function (data) {
                if (!data || data.ok !== true) {
                    fail(data && data.error ? String(data.error) : '');

                    return;
                }

                if (data.notice) {
                    lastNotice = String(data.notice);
                }

                // Первая сохранённая секция уже подняла версию материала. Следующие формы
                // уходят с новой ожидаемой версией, иначе гонка «форма 2 против формы 1»
                // выглядела бы как чужое изменение.
                if (data.version) {
                    var nextVersion = String(data.version);
                    Array.prototype.forEach.call(document.querySelectorAll('input[name="expected_version"]'), function (input) {
                        input.value = nextVersion;
                        var known = input.form ? initial.get(input.form) : null;

                        if (known && known.has(input)) {
                            known.set(input, nextVersion);
                        }
                    });
                }

                next();
            }).catch(function () {
                fail('Связь с сервером потерялась — изменения не сохранены.');
            });
        }

        next();
    });

    refresh();
})();
