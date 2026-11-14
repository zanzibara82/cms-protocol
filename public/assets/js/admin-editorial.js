/*
 * RELEASE 2.2: редактор страницы — автосохранение полей, локальный черновик, блокировка и
 * присутствие. Скрипт необязательный: без него форма сохраняется обычной кнопкой, а сервер
 * всё равно проверяет версию и права.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-editorial-form]');
    var panel = document.querySelector('[data-editorial-panel]');

    if (!form || !panel) {
        return;
    }

    var type = panel.getAttribute('data-content-type') || 'page';
    var id = panel.getAttribute('data-content-id') || '';
    var endpoint = panel.getAttribute('data-autosave-url') || '/admin/editorial';
    var delay = parseInt(panel.getAttribute('data-autosave-delay') || '5000', 10);
    var canEdit = panel.getAttribute('data-can-edit') === '1';
    var version = parseInt(panel.getAttribute('data-content-version') || '0', 10) || 0;
    var status = panel.querySelector('[data-editorial-status]');
    var lockBox = panel.querySelector('[data-editorial-lock]');
    var conflictBox = panel.querySelector('[data-editorial-conflict]');
    var draftBox = panel.querySelector('[data-editorial-draft]');
    var draftText = panel.querySelector('[data-editorial-draft-text]');
    var tokenInput = form.querySelector('input[name="_token"]');
    var token = tokenInput ? tokenInput.value : '';
    var storageKey = 'cms-editorial-draft:' + type + ':' + id;
    var fields = ['title', 'slug', 'description', 'status', 'noindex'];
    var initial = currentValues();
    var timer = null;
    var draftTimer = null;
    var saving = false;
    var lockToken = '';
    var lockedOut = false;
    var conflict = false;
    var heartbeat = null;
    var lastKey = '';
    var lastSerialized = '';

    function control(name) {
        return form.elements.namedItem(name);
    }

    function currentValues() {
        var values = {};

        fields.forEach(function (name) {
            var field = control(name);

            if (!field) {
                values[name] = '';
                return;
            }

            values[name] = field.type === 'checkbox' ? (field.checked ? '1' : '0') : String(field.value);
        });

        return values;
    }

    function serialized(values) {
        return JSON.stringify(values);
    }

    function setStatus(text, bad) {
        if (!status) {
            return;
        }

        status.textContent = text;
        status.className = 'text-sm ' + (bad ? 'text-bad' : 'text-ink/60');
    }

    function setVersion(next) {
        version = parseInt(String(next), 10) || 0;
        panel.setAttribute('data-content-version', String(version));
        Array.prototype.forEach.call(form.querySelectorAll('input[name="expected_version"]'), function (input) {
            input.value = String(version);
        });
    }

    function randomKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }

        return String(Date.now()) + Math.random().toString(16).slice(2);
    }

    function post(op, extra) {
        var body = new URLSearchParams();
        body.set('_token', token);
        body.set('format', 'json');
        body.set('op', op);
        body.set('type', type);
        body.set('id', id);

        Object.keys(extra || {}).forEach(function (key) {
            body.set(key, String(extra[key]));
        });

        return window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CMS-Editorial': '1'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return {ok: false, error: 'Сервер вернул неожиданный ответ (HTTP ' + response.status + ').'};
            }).then(function (data) {
                data.status = response.status;

                return data;
            });
        });
    }

    function saveDraft(values) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify({
                values: values,
                savedAt: Date.now()
            }));
        } catch (error) {
            // Хранилище недоступно: автосохранение продолжит работать, локального черновика не будет.
        }
    }

    function clearDraft() {
        try {
            window.localStorage.removeItem(storageKey);
        } catch (error) {
            // Ничего не поделать — черновик останется до следующей успешной отправки.
        }
    }

    function dirty() {
        return serialized(currentValues()) !== serialized(initial);
    }

    function scheduleAutosave() {
        if (!canEdit || lockedOut || conflict) {
            return;
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            autosave();
        }, Math.max(1000, delay));
    }

    function autosave() {
        if (saving || !canEdit || lockedOut || conflict || !dirty()) {
            return;
        }

        var values = currentValues();
        var payload = serialized(values);

        if (payload !== lastSerialized) {
            lastSerialized = payload;
            lastKey = randomKey();
        }

        saving = true;
        setStatus('Сохраняем…', false);

        post('autosave-page', {
            expected_version: version,
            idempotency_key: lastKey,
            title: values.title,
            slug: values.slug,
            description: values.description,
            status: values.status,
            noindex: values.noindex
        }).then(function (data) {
            saving = false;

            if (data && data.ok === true) {
                if (data.version) {
                    setVersion(data.version);
                }

                initial = currentValues();
                conflict = false;

                if (conflictBox) {
                    conflictBox.hidden = true;
                }

                clearDraft();
                setStatus('Сохранено ' + new Date().toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'}), false);

                var keyInput = control('idempotency_key');

                if (keyInput) {
                    keyInput.value = '';
                }

                return;
            }

            if (data && data.status === 409) {
                conflict = true;
                setStatus('Конфликт версий', true);

                if (conflictBox) {
                    conflictBox.hidden = false;
                    conflictBox.innerHTML = '';

                    var link = document.createElement('a');
                    link.href = '/admin/editorial?' + new URLSearchParams({type: type, id: id, view: 'diff'}).toString();
                    link.textContent = 'Сравнить версии';
                    link.className = 'underline underline-offset-4';
                    conflictBox.appendChild(document.createTextNode((data.error || 'Материал изменился в другой вкладке.') + ' '));
                    conflictBox.appendChild(link);
                }

                return;
            }

            setStatus(data && data.error ? String(data.error) : 'Не удалось сохранить черновик', true);
            scheduleAutosave();
        }).catch(function () {
            saving = false;
            setStatus('Связь потеряна — черновик сохранён локально', true);
            scheduleAutosave();
        });
    }

    function acquireLock() {
        if (!canEdit) {
            if (lockBox) {
                lockBox.textContent = 'Только просмотр';
            }

            return;
        }

        post('lock-acquire', {}).then(function (data) {
            if (data && data.ok === true) {
                lockToken = data.token || '';

                if (lockBox) {
                    lockBox.textContent = 'Блокировка ваша';
                }

                heartbeat = window.setInterval(function () {
                    if (lockToken === '') {
                        return;
                    }

                    post('lock-heartbeat', {token: lockToken}).then(function (beat) {
                        if (!beat || beat.ok !== true) {
                            lockedOut = true;
                            setStatus(beat && beat.error ? String(beat.error) : 'Блокировка потеряна', true);
                        }
                    });

                    post('presence', {});
                }, 25000);

                post('presence', {});

                return;
            }

            lockedOut = true;

            if (lockBox) {
                lockBox.textContent = data && data.error ? String(data.error) : 'Материал занят другим редактором';
                lockBox.className = 'text-bad';
            }

            setStatus('Автосохранение приостановлено: материал занят', true);
        }).catch(function () {
            if (lockBox) {
                lockBox.textContent = 'Не удалось проверить блокировку';
            }
        });
    }

    function releaseLock() {
        if (lockToken === '') {
            return;
        }

        var body = new URLSearchParams();
        body.set('_token', token);
        body.set('format', 'json');
        body.set('op', 'lock-release');
        body.set('type', type);
        body.set('id', id);
        body.set('token', lockToken);

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, body);
        }
    }

    form.addEventListener('input', function () {
        if (!canEdit || lockedOut || conflict) {
            return;
        }

        setStatus(dirty() ? 'Есть несохранённые изменения' : 'Изменений нет', false);
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(function () {
            var values = currentValues();

            if (serialized(values) !== serialized(initial)) {
                saveDraft(values);
            }
        }, 500);
        scheduleAutosave();
    });

    form.addEventListener('change', function () {
        scheduleAutosave();
    });

    form.addEventListener('submit', function () {
        window.clearTimeout(timer);

        var keyInput = control('idempotency_key');

        if (keyInput) {
            keyInput.value = randomKey();
        }
    });

    // Локальный черновик: если он отличается от серверного состояния, предлагаем восстановить.
    try {
        var raw = window.localStorage.getItem(storageKey);

        if (raw) {
            var draft = JSON.parse(raw);

            if (draft && draft.values && serialized(draft.values) !== serialized(initial) && draftBox) {
                draftBox.classList.remove('hidden');
                draftBox.removeAttribute('hidden');

                if (draftText) {
                    draftText.textContent = 'Сохранён ' + new Date(draft.savedAt || Date.now()).toLocaleString('ru-RU')
                        + '. Он содержит более свежие правки, чем сервер.';
                }

                var restore = panel.querySelector('[data-editorial-draft-restore]');
                var discard = panel.querySelector('[data-editorial-draft-discard]');

                if (restore) {
                    restore.addEventListener('click', function () {
                        fields.forEach(function (name) {
                            var field = control(name);

                            if (!field || !(name in draft.values)) {
                                return;
                            }

                            if (field.type === 'checkbox') {
                                field.checked = draft.values[name] === '1';
                            } else {
                                field.value = draft.values[name];
                            }
                        });

                        draftBox.classList.add('hidden');
                        draftBox.setAttribute('hidden', '');
                        setStatus('Черновик восстановлен — сохраняем…', false);
                        scheduleAutosave();
                    });
                }

                if (discard) {
                    discard.addEventListener('click', function () {
                        clearDraft();
                        draftBox.classList.add('hidden');
                        draftBox.setAttribute('hidden', '');
                        setStatus('Локальный черновик отброшен', false);
                    });
                }
            } else {
                clearDraft();
            }
        }
    } catch (error) {
        // Повреждённая запись не должна мешать редактору: просто забываем её.
        clearDraft();
    }

    acquireLock();

    window.addEventListener('pagehide', releaseLock);
})();
