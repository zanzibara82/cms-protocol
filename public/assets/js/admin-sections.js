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

        initial.get(form).forEach(function (value, control) {
            if (!dirty && controlValue(control) !== value) {
                dirty = true;
            }
        });

        if (dirty) {
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

                next();
            }).catch(function () {
                fail('Связь с сервером потерялась — изменения не сохранены.');
            });
        }

        next();
    });

    refresh();
})();
