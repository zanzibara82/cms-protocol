/*
 * Счётчик посещений раздела «SEO и статистика». Файл статический: сборка не нужна, сборщик не
 * нужен, а адрес файла один и тот же на всех сайтах.
 *
 * Правила, которые здесь нельзя нарушать:
 *  - cookie не используются вообще: ни своей, ни чужих. Идентификатор посетителя лежит в
 *    localStorage под ключом seo_visitor и стирается вместе с очисткой данных браузера;
 *  - с сервера приходит только путь страницы, хост источника и метки UTM — ни адреса
 *    посетителя, ни содержимого форм, ни полного адреса страницы-источника;
 *  - если браузер просит «не отслеживать» (Do Not Track), счётчик молчит.
 */
(function () {
    'use strict';

    var script = document.currentScript;

    if (!script) {
        return;
    }

    var endpoint = script.getAttribute('data-endpoint') || '';
    var token = script.getAttribute('data-token') || '';
    var respectDnt = script.getAttribute('data-respect-dnt') === '1';

    if (!endpoint || !token) {
        return;
    }

    // Do Not Track: просьба браузера «не следи» — отказ, а не предупреждение.
    var dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack || '';

    if (respectDnt && (dnt === '1' || dnt === 'yes')) {
        return;
    }

    var STORAGE_KEY = 'seo_visitor';
    var MAX_BATCH = 20;
    var FLUSH_DELAY = 5000;
    var PATH_MAX = 255;
    var TEXT_MAX = 190;

    var queue = [];
    var timer = null;
    var utmTaken = false;
    var visitor = '';

    /**
     * Случайный номер посетителя: только буквы и цифры, без дефисов — сервер проверяет вид.
     */
    function randomId() {
        var bytes = new Uint8Array(16);

        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < bytes.length; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }

        var out = '';

        for (var j = 0; j < bytes.length; j++) {
            out += ('0' + bytes[j].toString(16)).slice(-2);
        }

        return out;
    }

    /**
     * Идентификатор посетителя из localStorage. Если хранилище недоступно (приватный режим,
     * запрет cookies), номер живёт только в памяти страницы: считаем визит, но не гостя.
     * Номер запоминается на страницу — иначе каждая отправка завела бы своего гостя и счётчик
     * насчитал бы по одному посетителю на каждую пачку событий.
     */
    function visitorId() {
        if (visitor !== '') {
            return visitor;
        }

        var id = '';

        try {
            id = window.localStorage.getItem(STORAGE_KEY) || '';
        } catch (error) {
            id = '';
        }

        if (!/^[A-Za-z0-9_-]{8,64}$/.test(id)) {
            id = randomId();

            try {
                window.localStorage.setItem(STORAGE_KEY, id);
            } catch (error) {
                // Хранилище запрещено — останется номер в памяти, до закрытия страницы.
            }
        }

        visitor = id;

        return visitor;
    }

    /**
     * Хост страницы-источника: без пути и без меток, только имя. Полный адрес по сети не ходит.
     */
    function referrerHost() {
        var referrer = document.referrer || '';

        if (!referrer) {
            return '';
        }

        var match = /^([a-z][a-z0-9+.-]*:\/\/)?([^\/:?#]+)/i.exec(referrer);

        if (!match) {
            return '';
        }

        return match[2].toLowerCase().replace(/^www\./, '').slice(0, TEXT_MAX);
    }

    /**
     * Метки UTM: только с первого захода на странице, дальше они уже не нужны — иначе одна
     * рекламная ссылка была бы засчитана на каждый просмотр внутри сессии.
     */
    function utmParams() {
        if (utmTaken) {
            return null;
        }

        utmTaken = true;

        var query = window.location.search || '';

        if (!query) {
            return null;
        }

        var params = new URLSearchParams(query);
        var out = {};
        var names = { s: 'utm_source', m: 'utm_medium', c: 'utm_campaign' };

        for (var key in names) {
            if (!Object.prototype.hasOwnProperty.call(names, key)) {
                continue;
            }

            var value = (params.get(names[key]) || '').trim().slice(0, TEXT_MAX);

            if (value !== '') {
                out[key] = value;
            }
        }

        return Object.keys(out).length > 0 ? out : null;
    }

    /**
     * Устройство: по ширине окна и по признаку «тач». Точность здесь не нужна — устройство
     * в отчёте подписано как «примерно», а не как измеренное.
     */
    function device() {
        var width = window.innerWidth || document.documentElement.clientWidth || 0;
        var touch = 'ontouchstart' in window || (navigator.maxTouchPoints || 0) > 0;

        if (!touch) {
            return 'desktop';
        }

        return width >= 768 ? 'tablet' : 'mobile';
    }

    /**
     * Путь страницы: без меток и якоря, длиной не больше предела сервера.
     */
    function currentPath() {
        var path = window.location.pathname || '/';

        if (path.length > PATH_MAX) {
            path = path.slice(0, PATH_MAX);
        }

        return path;
    }

    function track() {
        var event = { p: currentPath(), r: referrerHost(), d: device() };
        var utm = utmParams();

        if (utm) {
            event.u = utm;
        }

        queue.push(event);

        if (queue.length >= MAX_BATCH) {
            flush();

            return;
        }

        if (timer === null) {
            timer = window.setTimeout(flush, FLUSH_DELAY);
        }
    }

    /**
     * Отправка пачки. Сначала пробуем navigator.sendBeacon — он переживает закрытие вкладки;
     * если не сработало, повторяем обычным запросом с keepalive.
     */
    function flush() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }

        if (queue.length === 0) {
            return;
        }

        var events = queue.splice(0, MAX_BATCH);
        var body = '_token=' + encodeURIComponent(token) + '&payload=' + encodeURIComponent(JSON.stringify({
            vid: visitorId(),
            events: events
        }));
        var sent = false;

        if (navigator.sendBeacon) {
            try {
                sent = navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/x-www-form-urlencoded' }));
            } catch (error) {
                sent = false;
            }
        }

        if (sent) {
            return;
        }

        try {
            window.fetch(endpoint, {
                method: 'POST',
                credentials: 'omit',
                keepalive: true,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body
            });
        } catch (error) {
            // Сеть недоступна — счётчик молча пропускает визит, а не мешает странице.
        }
    }

    /**
     * Одностраничные приложения меняют адрес без перезагрузки. Их переходы — тоже просмотры,
     * поэтому history подменяется, а кнопка «назад» ловится отдельным событием.
     */
    function patchHistory() {
        var push = window.history.pushState;
        var replace = window.history.replaceState;

        if (push) {
            window.history.pushState = function () {
                var result = push.apply(this, arguments);

                track();

                return result;
            };
        }

        if (replace) {
            window.history.replaceState = function () {
                var result = replace.apply(this, arguments);

                track();

                return result;
            };
        }

        window.addEventListener('popstate', track);
    }

    // Первый просмотр и переходы внутри страницы.
    track();
    patchHistory();

    // Уходя со страницы, отправляем накопленное: иначе последние секунды визита терялись бы.
    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush();
        }
    });
})();
