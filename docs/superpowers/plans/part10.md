### Задача 21: Выкладка на хостинг — зонд, деплой, запасная раскладка, адаптивность и приёмка

**Файлы:**
- Создать: `tests/guard/DeploySafetyTest.php`
- Создать: `scripts/deploy.sh`
- Создать: `.htaccess` (корневой — запасной вариант раскладки, §11.1 пункт 1 и §13)
- Создать: `scripts/hosting-probe.php`
- Создать: `scripts/responsive-check.mjs`
- Создать: `docs/deploy-checklist.md`
- Изменить: `package.json` (один npm-скрипт `responsive`; новых зависимостей нет — см. шаг 6)

Карта файлов в `docs/superpowers/plans/part1.md` пополняется двумя новыми путями отдельным
коммитом контроллера: тексты планов правит только он (Global Constraints), поэтому исполнителю
задачи этот файл не трогать.

**Порт:** `english-portal/scripts/responsive-check.mjs` (270 строк, уже обкатан на портале).
Источник истины по приёму — спецификация §11 (деплой, шесть шагов) и §12 (двадцать критериев
готовности). Чужой код переносится с заменой двух вещей: вход NextAuth на вход через куку
сессии PHP и список страниц портала на список страниц этой CMS.

**Интерфейсы:**
- Потребляет:
  - `core/session.php`: имя куки `cms_session` (задача 6); `core/csrf.php`: поле `_token` в форме
    входа и в `$_SESSION` (задача 7); `theme/login.php`: поля `email` и `password`
  - `core/routes.php`: `GET/POST /login`, успешный вход — 302 на `/admin`
  - `core/seo.php`: `robotsTxt()` отдаёт строку `Sitemap: <абсолютный адрес>/sitemap.xml`
    (задача 9), `newsSitemapUrls()` наполняет карту адресами раздела (задача 20)
  - `public/.htaccess`, `public/uploads/.htaccess` (задачи 1 и 18) — в запасной раскладке они
    работают без изменений, потому что запросы каталога `public/` Apache обрабатывает их же
    правилами
  - `scripts/gate.sh`, `scripts/db-reset`, `bash scripts/css` (задачи 1 и 5) — прогон ворот и
    сборка стилей перед выкладкой
  - системный Chrome и протокол его отладки через порт — проверка адаптивности работает без
    внешних пакетов Node, поэтому `npm install` не нужен (шаг 6)
- Даёт:
  - `bash scripts/deploy.sh [--dry-run]` — выкладка одним `rsync` из переменных `CMS_DEPLOY_USER`,
    `CMS_DEPLOY_HOST`, `CMS_DEPLOY_PATH`, `CMS_DEPLOY_MODE` (`public` | `root`), с проверкой
    раскладки по `CMS_DEPLOY_URL`;
  - `scripts/hosting-probe.php` — одноразовый зонд: девять групп проверяемых фактов о хостинге,
    доступ по ключу и самоудаление после ответа;
  - `node scripts/responsive-check.mjs --base=http://localhost:8080 [--shots]` — проверка
    адаптивности 360…1440, код возврата 1 при любом переполнении;
  - `.htaccess` в корне проекта — закрытие служебных каталогов и переписывание в `public/`;
  - `docs/deploy-checklist.md` — шаги §11 и двадцать критериев §12 в порядке их выполнения.

**Границы задачи.** Здесь нет ни одного изменения в `core/` и `apps/`: приёмка §12 обязана
проверять то, что уже собрано задачами 1–20, а не доделывать их. Если проверка находит дефект —
он чинится в этой же задаче только когда чинка короче самой проверки (например, `table` в
админке без `overflow-x`); всё остальное — отдельная правка с коммитом, названным по разделу,
чтобы страж задачи 20 не начал спорить с диффом.

**Что сюда не входит:** перенос данных из PostgreSQL (`english-portal`) и переключение домена —
это §14 «Что после пилота», там же курсы, доска и магазин. Боевая база портала не трогается
(Global Constraints).

---

- [ ] **Шаг 1: Написать падающий страж выкладки**

Создать `tests/guard/DeploySafetyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Выкладка — единственное место, где ошибка стоит не сломанной страницы, а потерянных данных:
// `rsync --delete` без исключения стирает с сервера config.php с паролями, замок установщика
// и обложки, которые загрузили редакторы. Страж читает три файла выкладки и проверяет ровно то,
// что нельзя потерять при следующем запуске скрипта. Это не проверка стиля: каждый assert ниже
// отвечает на вопрос «что произойдёт на боевом сервере, если эту строку убрать».
final class DeploySafetyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testСкриптВыкладкиСуществуетИЗапускается(): void
    {
        $sh = $this->read('scripts/deploy.sh');

        self::assertStringStartsWith('#!/usr/bin/env bash', $sh, 'deploy.sh обязан запускаться сам');
        // Адрес, логин и путь только из окружения: вписанный хостинг в скрипте — это секрет в git
        // и невозможность выложить второй сайт.
        foreach (['CMS_DEPLOY_USER', 'CMS_DEPLOY_HOST', 'CMS_DEPLOY_PATH'] as $var) {
            self::assertMatchesRegularExpression(
                '/:\s*"\$\{' . preg_quote($var, '/') . ':\?/',
                $sh,
                $var . ' обязан читаться через `:?` — запуск без него должен упасть, а не выложить в пустоту'
            );
        }

        // `:?` ловит пустое значение и пропускает относительное, а его rsync считает от домашнего
        // каталога входа: --delete уйдёт чистить соседнее дерево. Проверка ведущего слэша обязана
        // стоять до вызова rsync.
        self::assertMatchesRegularExpression(
            '/case "\$CMS_DEPLOY_PATH" in\s*\n\s*\/\*\)/',
            $sh,
            'deploy.sh обязан отвергать относительный CMS_DEPLOY_PATH до вызова rsync'
        );
    }

    public function testТриКаталогаНаСервереНеУдаляются(): void
    {
        $sh = $this->read('scripts/deploy.sh');

        // --delete при этом обязателен: без него на сервере остаются удалённые в репозитории
        // PHP-файлы, и следующий прогон читает код прошлой версии.
        self::assertStringContainsString('--delete', $sh, 'выкладка обязана чистить устаревшие файлы');
        // …и ровно поэтому --delete-excluded быть не должно: он снимает защиту исключённых
        // файлов, и три строки ниже превращаются в приглашение стереть пароли и загрузки.
        self::assertStringNotContainsString('--delete-excluded', $sh, 'исключённые файлы защищены от удаления');

        // Исключение обязано быть заякорено на каталог выкладки: `--exclude uploads/` молча
        // совпадёт с любым каталогом одноимённого имени вглубь дерева.
        foreach (['/config.php', '/public/install.lock', '/public/uploads/', '/core/storage/'] as $path) {
            self::assertMatchesRegularExpression(
                '/--exclude=' . preg_quote("'$path'", '/') . '/',
                $sh,
                $path . ' обязан быть в --exclude: --delete стёр бы его с сервера'
            );
        }
    }

    public function testЗондХостингаЗакрытКлючомИУдаляетСебя(): void
    {
        $probe = $this->read('scripts/hosting-probe.php');

        // Пустая строка в репозитории — условие, а не недоработка: секретный ключ не живёт в git,
        // его вписывают в копию файла перед загрузкой. Не пустой — страж падает, и это же
        // означает, что скачанный из репозитория зонд сам по себе безопасен.
        self::assertMatchesRegularExpression('/^\$probeKey = \'\';$/m', $probe, 'в репозитории ключ обязан быть пуст');
        // Сравнение через hash_equals, а не `==`: `==` с магическими хешами пропускает запрос,
        // который никто не авторизовывал.
        self::assertStringContainsString('hash_equals(', $probe, 'ключ сравнивается без утечки по времени');
        self::assertStringContainsString('unlink(__FILE__)', $probe, 'зонд обязан удалиться после ответа');
        // Дамп всех настроек показывает пути, флаги сборки и окружение. Нужен конкретный набор
        // проверяемых значений, и он собирается вручную.
        self::assertStringNotContainsString('phpinfo(', $probe, 'зонд не отдаёт свалку настроек');
        // config.php зонд не читает: на момент проверки его может не быть, а после его создания
        // показывать что-либо из него наружу нельзя.
        self::assertStringNotContainsString("dirname(__DIR__) . '/config.php'", $probe, 'зонд не трогает боевые настройки');
    }

    public function testЗапаснаяРаскладкаЗакрываетСлужебноеДоПереписывания(): void
    {
        $htaccess = $this->read('.htaccess');

        foreach (['core', 'apps', 'sql', 'config'] as $name) {
            self::assertMatchesRegularExpression(
                '/RewriteRule[^\n]*' . $name . '[^\n]*\[F/',
                $htaccess,
                $name . ' обязан отдавать 403 в запасной раскладке'
            );
        }

        // Порядок правил — не косметика. Apache идёт по списку, и если переписывание в public/
        // стоит раньше блокировки, запрос /core/bootstrap.php совпадёт с существующим файлом,
        // условие !-f его пропустит, и исходники ядра отдадутся наружу.
        $deny = strpos($htaccess, '[F');
        $rewrite = strpos($htaccess, 'public/index.php');
        self::assertIsInt($deny);
        self::assertIsInt($rewrite);
        self::assertLessThan($rewrite, $deny, 'блокировка служебного обязана предшествовать переписыванию');

        // Тормоз повторного прохода: Apache после [L] проходит правила ещё раз с переписанным
        // путём, а %{REQUEST_URI} при этом не меняется, поэтому остановить второй проход может
        // только условие на ссылку правила. Без него /assets/app.css, уже переписанный в
        // public/assets/app.css, вторым проходом уехал бы в public/index.php — вместо стилей
        // отдалась бы разметка, и проверка адаптивности молча тестировала бы 404.
        self::assertStringContainsString('RewriteCond $1 !^public/', $htaccess);
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path, $relative . ': файл выкладки обязан существовать');

        $code = file_get_contents($path);
        self::assertIsString($code);

        return $code;
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter DeploySafetyTest`
Expected: FAIL — «scripts/deploy.sh: файл выкладки обязан существовать». Файлы появятся в шагах 2–5;
тест падает на первом же отсутствующем, и это правильный провал: он значит, что страж читает
файлы, а не молчит на пустом дереве.

- [ ] **Шаг 2: Скрипт выкладки**

Создать `scripts/deploy.sh`:

```bash
#!/usr/bin/env bash
# Выкладка на виртуальный хостинг: один rsync по SSH вместо сотен отдельных передач (§11.2).
#
# Ни адреса, ни пароля в файле нет: хост, логин и путь читаются из переменных окружения, которые
# человек ставит в своей сессии. Пароли SSH-ключей живут в ssh-agent, боевой config.php создаётся
# на сервере вручную (§11.3) и в репозиторий не попадает.
#
# --delete без четырёх исключений ниже уничтожил бы сайт: он стёр бы с сервера config.php с
# паролями к базе, public/install.lock (его снимает только установщик, и на открытом установщике
# сайт доступен любому), обложки из public/uploads и журнал core/storage. Исключённые файлы rsync
# по умолчанию защищает от удаления, и флаг, снимающий эту защиту, здесь быть не должен — за ним
# следит страж DeploySafetyTest: цена одной лишней опции — потеря боевых данных на сервере.
set -euo pipefail

: "${CMS_DEPLOY_USER:?не задан CMS_DEPLOY_USER — логин SSH}"
: "${CMS_DEPLOY_HOST:?не задан CMS_DEPLOY_HOST — адрес сервера}"
: "${CMS_DEPLOY_PATH:?не задан CMS_DEPLOY_PATH — абсолютный каталог проекта на сервере}"

# `:?` ловит пустое значение, но не ловит относительное: rsync считает его от домашнего каталога
# входа, и --delete уезжает чистить не тот каталог. Опечатка без ведущего слэша дороже любого
# другого сбоя выкладки — она молча ломает соседнее дерево на сервере.
case "$CMS_DEPLOY_PATH" in
  /*) ;;
  *) echo "CMS_DEPLOY_PATH ждёт абсолютный каталог, получено: ${CMS_DEPLOY_PATH}" >&2; exit 1 ;;
esac

# public — документ-корень перенесён на каталог public/ (предпочтительный вариант, §11.1).
# root   — перенести нельзя: заливается вся структура, служебное закрывает корневой .htaccess.
# Набор файлов в обоих режимах одинаковый: public/index.php требует ../core/bootstrap.php, поэтому
# ядро обязано лежать рядом с public/ всегда. Режим меняет только проверку после заливки.
MODE="${CMS_DEPLOY_MODE:-public}"
case "$MODE" in
  public|root) ;;
  *) echo "CMS_DEPLOY_MODE ждёт public или root, получено: ${MODE}" >&2; exit 1 ;;
esac

RSYNC_OPTS=(-azh --delete --itemize-changes)
if [[ "${1:-}" == '--dry-run' ]]; then
  RSYNC_OPTS+=(--dry-run)
  echo 'Пробег: показываем, что изменится, на сервер не пишем.'
fi

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${CMS_DEPLOY_USER}@${CMS_DEPLOY_HOST}:${CMS_DEPLOY_PATH%/}/"
cd "$REPO"

# Dev-only файлы на сервере не нужны, а их наличие в документ-корне — это подсказка постороннему
# о сборке (phpunit.xml, composer.lock с точными версиями). sql/ остаётся: его читает setup.php.
rsync "${RSYNC_OPTS[@]}" \
  --exclude='/config.php' \
  --exclude='/public/install.lock' \
  --exclude='/public/uploads/' \
  --exclude='/core/storage/' \
  --exclude='/tests/' \
  --exclude='/docs/' \
  --exclude='/docker/' \
  --exclude='/docker-compose.dev.yml' \
  --exclude='/scripts/' \
  --exclude='/src-css/' \
  --exclude='/vendor/' \
  --exclude='/node_modules/' \
  --exclude='/composer.json' \
  --exclude='/composer.lock' \
  --exclude='/package.json' \
  --exclude='/package-lock.json' \
  --exclude='/tailwind.config.js' \
  --exclude='/postcss.config.js' \
  --exclude='/phpstan.neon' \
  --exclude='/phpunit.xml' \
  --exclude='/.php-cs-fixer.dist.php' \
  --exclude='/.php-cs-fixer.cache' \
  --exclude='/.phpunit.result.cache' \
  --exclude='/.git/' \
  --exclude='/.gitignore' \
  --exclude='/.superpowers/' \
  "$REPO/" "$DEST"

echo 'Файлы выложены.'

# --- проверка раскладки ---------------------------------------------------
# Идёт после заливки: до неё сервер показывает прошлую выкладку, и проверка отвечала бы на
# вопросы, которые к текущему состоянию не относятся.
BASE_URL="${CMS_DEPLOY_URL:-}"
if [[ -z "$BASE_URL" ]]; then
  echo 'CMS_DEPLOY_URL не задан — проверку раскладки пропускаем; шаги 2 и 5 чек-листа делают её руками.'
  exit 0
fi
BASE_URL="${BASE_URL%/}"

status_of() {
  curl -sS -o /dev/null --max-time 20 -w '%{http_code}' "${BASE_URL}$1" || echo '000'
}

fail=0

# Открытый сайт и его стили обязаны отвечать: пустой ответ означает не «ещё не установлено»,
# а сломанную раскладку.
for path in / /assets/app.css; do
  code="$(status_of "$path")"
  if [[ "$code" != '200' ]]; then
    echo "Отказ: ${path} отвечает ${code}, ждём 200." >&2
    fail=1
  fi
done

# Ни один служебный файл не должен отдаваться. 403 (закрыт корневым .htaccess, режим root) и
# 404 (каталог лежит выше документ-корня, режим public) — оба ответа правильные; 200 — это
# утекшие исходники или, что хуже, исполненный config.sample.php.
for path in /core/bootstrap.php /config.php /config.sample.php /sql/0001_core_baseline.sql /apps/news/manifest.php /composer.json /docs/deploy-checklist.md; do
  code="$(status_of "$path")"
  if [[ "$code" == '200' ]]; then
    echo "Отказ: ${path} отдаётся наружу (200). Служебные пути обязаны быть закрыты." >&2
    fail=1
  fi
done

if [[ "$fail" -ne 0 ]]; then
  echo 'Проверка раскладки не прошла: перенесите документ-корень на public/ или оставьте' >&2
  echo 'режим root с корневым .htaccess, затем повторите выкладку.' >&2
  exit 1
fi

echo 'Раскладка закрыта как надо. Дальше — шаги 3..6 docs/deploy-checklist.md.'
```

Run: `bash -n scripts/deploy.sh && chmod +x scripts/deploy.sh`
Expected: пусто (синтаксис Bash цел), файл стал исполняемым.

`bash -n` — единственная проверка, которая доступна без сервера: запускать скрипт «на живую» в
этом шаге нельзя, он пишет в чужой каталог по SSH.

- [ ] **Шаг 3: Запасной вариант раскладки**

Создать `.htaccess` в корне проекта:

```apache
# Запасная раскладка (§11.1 пункт 1, риск §13): хостинг не даёт перенести документ-корень на
# каталог public/. Тогда в документ-корень кладется вся структура, и этот файл делает две вещи:
# закрывает служебные каталоги и переправляет остальное в public/.
#
# В основной раскладке (документ-корень = public/) файл лежит НАД корнем, Apache его не читает и
# не мешает. Поэтому он в репозитории постоянно, а не в отдельной ветке «для плохого хостинга».
#
# Порядок важен: блокировка идёт ДО переписывания. Apache идёт по списку правил и останавливается
# на [F]; если первым поставить переписывание, запрос /core/bootstrap.php совпадёт с
# существующим файлом, условие !-f его пропустит, и наружу уйдёт исходник ядра — а при mod_php
# ещё и исполнится.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    RewriteRule ^(admin|apps|core|docker|scripts|sql|tests|docs|theme|vendor|node_modules)(/|$) - [F,L]
    RewriteRule ^config(\.sample)?\.php$ - [F,L]
    RewriteRule ^(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml|phpstan\.neon)$ - [F,L]
    RewriteRule (^|/)\.(git|env|superpowers) - [F,L]

    # Существующий файл или каталог внутри public/ отдаём как есть: стили, картинки из загрузок,
    # setup.php. Ссылка `$1` берётся из шаблона правила, а не из %{REQUEST_URI}: во втором
    # сидит строка запроса, и `/assets/app.css?v=2` не совпал бы ни с каким файлом — стилям
    # вместо выдачи достался бы index.php.
    RewriteCond $1 !^public/
    RewriteCond %{DOCUMENT_ROOT}/public/$1 -f
    RewriteRule ^(.*)$ public/$1 [L]

    RewriteCond $1 !^public/
    RewriteCond %{DOCUMENT_ROOT}/public/$1 -d
    RewriteRule ^(.*)$ public/$1 [L]

    # Всё остальное — в единственную точку входа. Публичные адреса (/news, /sitemap.xml,
    # /news/c/zadachi) файлов не имеют, поэтому до index.php доходят ровно один раз.
    #
    # Тормозом повторного прохода служит именно `$1 !^public/`, а не проверка %{REQUEST_URI}:
    # после [L] Apache проходит список правил ещё раз, уже с переписанным путём, но REQUEST_URI
    # при внутреннем переписывании не меняется — и условие на него второй проход не остановило бы.
    # Цена этой ошибки — не цикл, а подмена: запрос /assets/app.css в первом проходе находится как
    # файл и переписывается в public/assets/app.css, во втором проходе без условия `$1` попал бы на
    # это правило и стал бы public/index.php — вместо стилей человек получил бы разметку.
    #
    # Благодаря тому же неизменному REQUEST_URI маршрутизатор видит исходный адрес (/news), а не
    # /public/index.php — на этом держится requestPath() из задачи 4.
    RewriteCond $1 !^public/
    RewriteRule ^(.*)$ public/index.php [L,QSA]
</IfModule>

# Служебные заголовки дублируют public/.htaccess: в этой раскладке запрос идёт от корня, и
# заголовки public/.htaccess к нему не применятся, если ответ собрал public/index.php после
# переписывания.
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-Frame-Options "SAMEORIGIN"
</IfModule>
```

Два места в этом файле — не аккуратность, а единственные рабочие варианты:

- Условия «это файл» и «это каталог» записаны двумя отдельными правилами, а не одним через `[OR]`:
  `[OR]` связывает только соседние пары условий, поэтому условие `!^public/` приходится дублировать
  в каждом правиле, и в одном оно потеряло бы вторую половину. Ошибка в эту сторону стоит цикл
  переписываний и 500 на весь сайт.
- `RewriteBase /` обязателен: в per-directory контексте Apache строит результат относительно
  каталога, и на хостинге с подкаталогом документ-корня без этой строки `public/…` склеится с
  путём к `.htaccess` вместо корня.

Этот файл руками на стенде не проверяется: под `php -S` Apache-модуля нет, и проверка
настоящего Apache — шаг 5 чек-листа `docs/deploy-checklist.md`, где сервер уже есть.

- [ ] **Шаг 4: Одноразовый зонд хостинга**

Создать `scripts/hosting-probe.php`:

```php
<?php

declare(strict_types=1);

// Одноразовый зонд хостинга (§11.1, шаг 1). Отвечает один раз и удаляет себя: файл, оставшийся
// на сервере, — это готовый разведчик: «здесь PHP такой-то версии, порты наружу открыты, лимиты
// такие». Поэтому ключ, пустой в репозитории, и самоудаление.
//
// Ключ вписывают не в этот файл, а в его копию перед загрузкой (шаг 1 docs/deploy-checklist.md):
// рабочая копия проекта остаётся чистой, секрет в git не попадает, страж DeploySafetyTest следит,
// чтобы пустая строка здесь осталась пустой.
//
// Никакого дампа всех настроек интерпретатора: там пути, флаги сборки и переменные окружения,
// а проверяющему нужен набор конкретных значений. Зонд не читает config.php — на момент проверки
// его может не быть, а после его создания показывать что-либо из боевых настроек нельзя.
$probeKey = '';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($probeKey === '' || !hash_equals($probeKey, (string) ($_GET['k'] ?? ''))) {
    http_response_code(403);
    echo "Ключ не задан или не совпал. Зонд отвечает только на запрос /probe.php?k=<ключ>.\n";

    return;
}

/** @var list<string> $lines */
$lines = [];

// Формат одна строка на проверку: итог читает человек в терминале, а не парсер, и [!] должен
// быть виден глазом без разбора JSON.
$line = static function (string $name, string $value, bool $ok, string $hint = '') use (&$lines): void {
    $lines[] = sprintf('%s %-26s %s%s', $ok ? '[+]' : '[!]', $name, $value, $hint === '' ? '' : ' — ' . $hint);
};

$toBytes = static function (string $raw): int {
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    $num = (int) $raw;
    $last = strtolower(substr($raw, -1));
    if ($last === 'g') {
        return $num * 1024 * 1024 * 1024;
    }
    if ($last === 'm') {
        return $num * 1024 * 1024;
    }
    if ($last === 'k') {
        return $num * 1024;
    }

    return $num;
};

// 1. Версия PHP. 8.3 на хостинге лучше, но минимально поддерживаемая — 8.1 (Global Constraints).
$line('php', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), 'нужно не ниже 8.1');

// 2. Расширения. Список — §4 спецификации; json входит в ядро с PHP 8.0, но проверяем: на
// хостинге его могли выключить при сборке.
foreach (['pdo_mysql', 'mbstring', 'gd', 'openssl', 'fileinfo', 'json'] as $ext) {
    $line('расширение ' . $ext, extension_loaded($ext) ? 'есть' : 'нет', extension_loaded($ext));
}

// 3. Argon2id. Отсутствие не ломает сайт: hashPassword() в core/auth.php выбирает алгоритм по
// поддержке (§13, отступление 3), но знать это надо до переноса пользователей.
$argon = defined('PASSWORD_ARGON2ID');
$line('argon2id', $argon ? 'есть' : 'нет', $argon, $argon ? '' : 'пароли будут bcrypt');

// 4. Лимиты загрузки. Обложка ≤ 5 МБ (apps/news/lib/constants.php), multipart добавляет к телу
// запроса ещё килобайты, поэтому 8 МБ — нижняя граница, а post_max_size обязан быть не меньше
// upload_max_filesize: иначе файл в 5 МБ при post 2 МБ отклоняется молча, до PHP.
$upload = $toBytes((string) ini_get('upload_max_filesize'));
$post = $toBytes((string) ini_get('post_max_size'));
$line('upload_max_filesize', (string) ini_get('upload_max_filesize'), $upload >= 8 * 1024 * 1024, 'нужно не меньше 8M');
$line('post_max_size', (string) ini_get('post_max_size'), $post >= $upload, 'должен быть не меньше upload_max_filesize');
$line('memory_limit', (string) ini_get('memory_limit'), true, '-1 — без лимита');
$line('max_execution_time', (string) ini_get('max_execution_time'), true);

// 5. Сокеты. Почта ходит через stream_socket_client (core/SmtpSocket.php), функция обязана быть
// живой даже при выключённом allow_url_fopen.
$line('stream_socket_client', function_exists('stream_socket_client') ? 'есть' : 'нет', function_exists('stream_socket_client'));

// 6. Каталог сессий. core/session.php пишет файлы сессий в save_path; на shared-хостинге его
// иногда переназначают на каталог без прав на запись, и вход молча не работает.
$savePath = (string) ini_get('session.save_path');
// Модификаторы вида «2;0700;/tmp» — первые две части числовые, путь в последней.
$parts = array_values(array_filter(explode(';', $savePath), static fn (string $p): bool => $p !== ''));
$path = count($parts) > 0 ? (string) end($parts) : sys_get_temp_dir();
$line('session.save_path', $path, is_dir($path) && is_writable($path), 'нет каталога или нет прав на запись');

// 7. Исходящий порт почтового сервера (§11.1). Проверяется только когда адрес передан явно:
// без него зонд не должен никуда стучаться — он чужой сервер, а не наш.
$smtpHost = (string) ($_GET['smtp'] ?? '');
$smtpPort = (int) ($_GET['port'] ?? 587);
if ($smtpHost !== '' && preg_match('/^[a-z0-9._-]+$/i', $smtpHost) === 1 && $smtpPort >= 1 && $smtpPort <= 65535) {
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 6);
    $line(
        'порт ' . $smtpPort . ' → ' . $smtpHost,
        $socket === false ? 'закрыт: ' . $errstr : 'открыт',
        $socket !== false,
        'закрыт — письма через 465 или почтовый ящик домена (§13)'
    );
    if ($socket !== false) {
        fclose($socket);
    }
} else {
    $line('порт 587 наружу', 'не проверено', true, 'передайте ?smtp=хост&port=587');
}

// 8. Cron. В пилоте не нужен (§3: рассылок и отложенных задач нет), но знать о нём надо до
// следующего раздела — заявки и курсы его потребуют. shell_exec часто выключен в disable_functions,
// и «нет функции» здесь честно отделяется от «нет crontab».
$disabled = strtolower((string) ini_get('disable_functions'));
if (!function_exists('shell_exec') || str_contains($disabled, 'shell_exec')) {
    $line('cron', 'не проверено', true, 'shell_exec выключен — узнаём в панели хостинга');
} else {
    $found = trim((string) @shell_exec('command -v crontab 2>/dev/null'));
    $line('cron', $found === '' ? 'нет' : $found, $found !== '', 'в пилоте не нужен');
}

// 9. Документ-корень. Отвечаем сами, потому что перенос корня — вопрос к панели хостинга, а этот
// файл отвечает на него без панели: если корень уже указывает на каталог с зондом, значит перенести
// можно и вариант public/ рабочий.
$docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$here = realpath(__DIR__);
if ($docRoot !== false && $here !== false && $docRoot === $here) {
    $line('документ-корень', $docRoot, true, 'уже указывает на public/ — вариант public/ рабочий');
} else {
    $line(
        'документ-корень',
        ($docRoot === false ? '?' : $docRoot) . ' vs ' . ($here === false ? '?' : $here),
        false,
        'корень не на public/ — просим перенести или кладём весь проект с корневым .htaccess (режим root)'
    );
}

// Самоудаление. На Linux файл уже прочитан интерпретатором, поэтому удаление во время исполнения
// безопасно; на Windows (локальная проверка) блокировка файла может мешать — строка ниже говорит,
// удалось ли, и человек доснимает файл руками.
$removed = @unlink(__FILE__);
$line('зонд удалил себя', $removed ? 'да' : 'нет', $removed, $removed ? '' : 'удалите файл вручную, он больше не нужен');

echo implode("\n", $lines) . "\n";
```

Run: `docker compose -f docker-compose.dev.yml run --rm -T php php -l scripts/hosting-probe.php`
Expected: `No syntax errors detected`. Проверка синтаксиса здесь — не единственная: `phpstan.neon`
включает путь `scripts` (строка 13, там же объяснение — хостеровые скрипты исполняют разрушительные
действия на выкладке), поэтому зонд проходит и анализ ошибок типов уровня 6. Значит, в его тексте
запрещены вызовы неопределённых функций, работа с неопределёнными индексами массива и любые
нетипизированные возвраты — как в коде сайта. `php -l` в воротах идёт `find`-ом по всему дереву и
до `scripts/` доходит тоже.

**Как зонд ставят и снимают** (полный текст — шаг 1 `docs/deploy-checklist.md`): копия файла с
ключом кладётся в `public/` под именем `probe.php`, ответ читается по HTTPS, файл удаляется сам.
В репозитории зонд лежит в `scripts/` и потому на сервер не попадает: `deploy.sh` исключает
`/scripts/`, а зонд загружают отдельно и осознанно.

- [ ] **Шаг 5: Прогнать страж**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter DeploySafetyTest`
Expected: PASS — 4 проверки.

Если падает `testЗапаснаяРаскладкаЗакрываетСлужебноеДоПереписывания`, смотреть не на регистр
правил, а на порядок: `[F` обязан встретиться в файле раньше строки с `public/index.php`.

- [ ] **Шаг 6: npm — скрипт без новой зависимости**

В `package.json` добавить один скрипт `responsive` и больше ничего не менять (файл создан
задачей 4 — в `part2.md` он в её границах: перечень файлов строки 718, содержимое строки 1423;
`devDependencies` читаются только разработчиком, на сервере Node нет):

```json
{
  "name": "cms-css",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "tailwindcss -i ./src-css/app.css -o ./public/assets/app.css --minify",
    "watch": "tailwindcss -i ./src-css/app.css -o ./public/assets/app.css --watch",
    "responsive": "node scripts/responsive-check.mjs"
  },
  "devDependencies": {
    "@tailwindcss/cli": "^4.1.0",
    "tailwindcss": "^4.1.0"
  }
}
```

Новых пакетов нет, и `npm install` не запускается. Проверка адаптивности говорит с тем Chrome,
который уже стоит у разработчика, по протоколу отладки через порт (шаг 7): библиотека-драйвер для
этого не нужна, а установка пакетов из реестра на машине исполнителя может быть запрещена
политикой окружения — тогда предписанный `npm install` сделал бы шаг непроходимым.
`package-lock.json` остаётся таким, каким его оставила задача 4, и в коммит не добавляется:
меняться в нём нечему.

Run: `npm pkg get scripts`
Expected: три ключа — `build`, `watch`, `responsive`.

Run: `npm pkg get devDependencies`
Expected: только `@tailwindcss/cli` и `tailwindcss` — новых пакетов не появилось.

- [ ] **Шаг 7: Проверка адаптивности**

Создать `scripts/responsive-check.mjs` (порт `english-portal/scripts/responsive-check.mjs`):

```js
// Проверка адаптивности 360…1440 (критерий §12.18, требование Global Constraints).
// Переполнение ищет на собранном стенде: поднимает headless Chrome с портом отладки, открывает
// страницы по протоколу отладки Chrome на шести экранах, ищет горизонтальное переполнение и
// пишет отчёт во временный каталог.
//
// Запуск (Node нужен только разработчику, внешних пакетов нет):
//   1) поднять стенд на тестовой базе — команды шага 8 этой задачи
//   2) npm run responsive -- --base=http://localhost:8080 --shots
//
// Вход нужен экранам админки: это не клики, а два запроса — GET формы (из неё берётся `_token`,
// задача 7) и POST с полями email/password. Поведение кнопок проверяют руками на шаге 11, здесь
// важна раскладка.
import { mkdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawn } from 'node:child_process'

const args = process.argv.slice(2)
const BASE = (args.find((a) => a.startsWith('--base=')) ?? '--base=http://localhost:8080').split('=')[1]
const SHOTS = args.includes('--shots')
const OUT = join(tmpdir(), 'cms-resp-check')

// Системный Chrome. Переменная CHROME_PATH перекрывает путь — на другой машине или Linux.
const CHROME =
  process.env.CHROME_PATH ??
  (process.platform === 'win32'
    ? 'C:/Program Files/Google/Chrome/Application/chrome.exe'
    : '/usr/bin/google-chrome')

// Порт отладки занят только на время прогона: Chrome поднимается здесь же и здесь же убивается.
const PORT = 9333

const VIEWPORTS = [
  { name: '360', w: 360, h: 640 },
  { name: '390', w: 390, h: 844 },
  { name: '768', w: 768, h: 1024 },
  { name: '1024', w: 1024, h: 768 },
  { name: '1280', w: 1280, h: 800 },
  { name: '1440', w: 1440, h: 900 },
]

// Тестовые учётные данные установщика из шага 8 (cms_test), не боевой аккаунт.
const EMAIL = process.env.RESP_EMAIL ?? 'admin@school.dev'
const PASSWORD = process.env.RESP_PASSWORD ?? 'длинный-тестовый-пароль'
const SESSION_COOKIE = 'cms_session'

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// Возвращает значение куки сессии или null: если вход не вышел, проверка не обрывается, а говорит
// об этом и берёт публичные адреса. Молчаливый пропуск админки — это «проверено 30 комбинаций»,
// когда надо было 78.
//
// Куки переносятся вручную: вход делается двумя запросами, а не страницей браузера, потому что
// Chrome поднимается позже и живёт в своём профиле. Токен CSRF лежит в `$_SESSION` (задача 7),
// поэтому кука из ответа на GET обязана уйти в POST. loginUser() вызывает
// session_regenerate_id(true) (задача 6), значит настоящая кука сессии приезжает в ответе на
// POST; это 302, и при `redirect: 'manual'` Node отдаёт его заголовки как есть — Set-Cookie
// читается. Решение принимает наличие куки, а не статус ответа.
async function loginCookie() {
  const jar = new Map()
  const keep = (res) => {
    for (const c of res.headers.getSetCookie() ?? []) {
      const [pair] = c.split(';')
      const i = pair.indexOf('=')
      jar.set(pair.slice(0, i).trim(), pair.slice(i + 1))
    }
  }
  const form = await fetch(`${BASE}/login`)
  keep(form)
  const token = /name="_token" value="([^"]+)"/.exec(await form.text())?.[1]
  if (!token) {
    console.error('на /login нет поля _token — вход невозможен, проверяются только публичные адреса')
    return null
  }
  const sent = await fetch(`${BASE}/login`, {
    method: 'POST',
    redirect: 'manual',
    headers: {
      'content-type': 'application/x-www-form-urlencoded',
      cookie: [...jar].map(([k, v]) => `${k}=${v}`).join('; '),
    },
    body: new URLSearchParams({ email: EMAIL, password: PASSWORD, _token: token }),
  })
  keep(sent)
  const value = jar.get(SESSION_COOKIE)
  if (!value) {
    console.error(
      `не удалось войти как ${EMAIL}: куки ${SESSION_COOKIE} нет — проверяются только публичные адреса`,
    )
    return null
  }
  return value
}

// Публичные адреса раздела берём из карты сайтов: материалы живут в базе, и выписать их руками
// значит проверить ленту и молча пропустить карточку с лонгридом. Ссылку на карту даёт robots.txt
// (§9), поэтому скрипт не должен знать, какие приложения на сайте включены.
async function publicPages() {
  const fixed = [
    { name: 'главная', href: '/' },
    { name: 'вход', href: '/login' },
    { name: 'лента новостей', href: '/news' },
    { name: 'лента с поиском', href: '/news?q=бал' },
    { name: 'нет такой страницы', href: '/net-takogo-adresa' },
  ]
  let fromMap = []
  try {
    const robots = await (await fetch(`${BASE}/robots.txt`)).text()
    const maps = [...robots.matchAll(/^Sitemap:\s*(\S+)/gim)].map((m) => m[1])
    for (const url of maps) {
      const res = await fetch(url)
      if (!res.ok) continue
      const paths = [...(await res.text()).matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]).pathname)
      fromMap.push(...paths)
    }
  } catch {
    console.error('robots.txt или карта сайтов не ответили — проверяются только фиксированные адреса')
  }
  fromMap = [...new Set(fromMap)].filter((p) => !fixed.some((f) => f.href === p)).slice(0, 12)
  if (!fromMap.length) console.error('карта сайтов не дала адресов — карточка материала не проверена')
  return [...fixed, ...fromMap.map((p) => ({ name: `карта:${p.slice(1, 20)}`, href: p }))]
}

// Экраны под входом. Их в карте сайтов нет по определению (§7: приватные пути закрыты от индекса),
// поэтому они выписаны здесь. Правка материала (/news/admin/items/<id>) отдельно не проверяется:
// её отдаёт тот же шаблон item.php, что и создание (задача 18), — различаются только значения в
// полях и кнопка удаления, а раскладка идентична. Один настоящий адрес правки человек открывает
// руками на шаге 11 этой задачи.
const ADMIN_PAGES = [
  { name: 'сводка ядра', href: '/admin' },
  { name: 'приложения', href: '/admin/apps' },
  { name: 'новости: сводка', href: '/news/admin' },
  { name: 'новости: материалы', href: '/news/admin/items' },
  { name: 'новости: поиск по тексту', href: '/news/admin/items?q=бал' },
  { name: 'новости: вкладка черновиков', href: '/news/admin/items?status=DRAFT' },
  { name: 'новости: форма', href: '/news/admin/items/new' },
  { name: 'новости: рубрики', href: '/news/admin/categories' },
  { name: 'новости: теги', href: '/news/admin/tags' },
]

// Chrome поднимается отдельным процессом и открывает порт отладки не мгновенно: адрес сокета
// берётся из его же списка целей, с попытками первые тридцать секунд.
async function findWsUrl() {
  for (let i = 0; i < 60; i++) {
    try {
      const list = await fetch(`http://127.0.0.1:${PORT}/json/list`).then((r) => r.json())
      const page = list.find((t) => t.type === 'page')
      if (page) return page.webSocketDebuggerUrl
    } catch {
      // Chrome ещё поднимается
    }
    await sleep(500)
  }
  throw new Error('Chrome не поднялся на порту ' + PORT)
}

// Обёртка над сокетом протокола отладки: счётчик сообщений, таблица ожидающих ответов и ожидание
// события загрузки страницы. Встроенный в Node `WebSocket` подходит как есть — пакет не нужен.
function connect(url) {
  const ws = new WebSocket(url)
  let id = 0
  const pending = new Map()
  const loadWaiters = []
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data)
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id)
      pending.delete(msg.id)
      if (msg.error) reject(new Error(JSON.stringify(msg.error)))
      else resolve(msg.result)
    } else if (msg.method === 'Page.loadEventFired') {
      loadWaiters.forEach((fn) => fn())
    }
  })
  const open = new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }))
  return {
    ws,
    open,
    send(method, params = {}) {
      return new Promise((resolve, reject) => {
        const n = ++id
        pending.set(n, { resolve, reject })
        ws.send(JSON.stringify({ id: n, method, params }))
      })
    },
    // Пятнадцать секунд — потолок ожидания загрузки одного экрана: дальше проба мерит то, что
    // успело построиться, и страница попадает в отчёт как есть.
    onLoad() {
      return new Promise((resolve) => {
        loadWaiters.push(resolve)
        setTimeout(resolve, 15000)
      })
    },
  }
}

// Проба измеряет то, что посетитель действительно видит. Выражение — строка: его исполняет
// Runtime.evaluate внутри страницы, а не Node.
//
// Свёрнутый <details> Chrome раскладывает, но не показывает (content-visibility: hidden): его
// строки оказываются за экраном там, где прокладки нет, и проба посчитала бы их лишними. Перед
// замером блоки раскрываются, оставшиеся свёрнутые не учитываются.
//
// Переполнение — это не только scrollWidth документа: элемент правее экрана внутри
// [overflow-x: auto] прокручивается и не ломает страницу, поэтому такие места считаются
// правильными (таблицы справочников в админке живут именно так, §8.5).
const PROBE = `(() => {
  const vw = window.innerWidth
  const scrollable = (el) => {
    for (let p = el.parentElement; p; p = p.parentElement) {
      const ox = getComputedStyle(p).overflowX
      if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') return true
    }
    return false
  }
  const hiddenInClosedDetails = (el) => {
    const d = el.closest('details')
    return d !== null && !d.open
  }
  const bad = []
  document.querySelectorAll('body *').forEach((el) => {
    const r = el.getBoundingClientRect()
    if (!r.width) return
    if (r.right > vw + 1.5) {
      if (scrollable(el) || hiddenInClosedDetails(el)) return
      const cls = typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\\s+/)[0] : ''
      bad.push({ sel: el.tagName.toLowerCase() + cls, right: Math.round(r.right), w: Math.round(r.width) })
    }
  })
  const uniq = []
  bad.sort((a, b) => b.right - a.right).forEach((b) => {
    if (uniq.length < 6 && !uniq.some((u) => u.sel === b.sel)) uniq.push(b)
  })
  return {
    scrollWidth: document.documentElement.scrollWidth,
    vw,
    url: location.pathname,
    title: document.title,
    offenders: uniq,
  }
})()`

// Имя файла под скриншот: адреса из карты сайтов дают имена вида «карта:/news/osennij-bal», а
// двоеточие и слэш Windows в имени не принимает — прогон на машине разработчика падал бы на
// записи файла, уже отмерив все экраны. Отчёт (report.json) при этом пишет полные имена.
function safeName(name) {
  return name.replace(/[^a-zA-Zа-яА-Я0-9._-]+/g, '_')
}

async function main() {
  mkdirSync(OUT, { recursive: true })
  // Кука получается до подъёма Chrome: она кладётся в браузер командой протокола, а не ответом
  // сервера, поэтому её значение должно быть известно к моменту подключения.
  const cookie = await loginCookie()
  const proc = spawn(
    CHROME,
    [
      '--headless=new',
      `--remote-debugging-port=${PORT}`,
      '--remote-allow-origins=*',
      `--user-data-dir=${join(OUT, 'profile')}`,
      '--no-first-run',
      '--disable-gpu',
      'about:blank',
    ],
    { stdio: 'ignore' },
  )
  const report = []
  const ws = connect(await findWsUrl())
  try {
    await ws.open
    await ws.send('Page.enable')
    await ws.send('Network.enable')

    // Кука кладётся в браузер командой протокола, а не ответом сервера, поэтому поставить её
    // можно в любой момент прогона. Ставится она ПОСЛЕ замера публичных адресов: под живой
    // сессией маршрут /login редиректит в /admin, и форма входа — экран, с которого начинается
    // путь любого посетителя, — осталась бы без замера раскладки вообще.
    const setSessionCookie = () =>
      ws.send('Network.setCookie', {
        name: SESSION_COOKIE,
        value: cookie,
        domain: new URL(BASE).hostname,
        path: '/',
        httpOnly: true,
        sameSite: 'Lax',
      })

    const measure = async (list) => {
      for (const vp of VIEWPORTS) {
        // Метка mobile меняет то, как Chrome применяет media-запросы: на узких экранах часть
        // раскладок живёт именно в них, и их надо просить как мобильный вьюпорт, а не сжимать
        // десктопный.
        await ws.send('Emulation.setDeviceMetricsOverride', {
          width: vp.w,
          height: vp.h,
          deviceScaleFactor: 1,
          mobile: vp.w < 500,
        })
        for (const item of list) {
          const wait = ws.onLoad()
          await ws.send('Page.navigate', { url: BASE + item.href })
          await wait
          await sleep(900)
          await ws.send('Runtime.evaluate', {
            expression: `document.querySelectorAll('details').forEach((d) => { d.open = true })`,
          })
          await sleep(200)
          const { result } = await ws.send('Runtime.evaluate', { expression: PROBE, returnByValue: true })
          const row = { page: item.name, href: item.href, vp: vp.name, ...result.value }
          if (SHOTS && (vp.name === '360' || vp.name === '1440')) {
            const shot = await ws.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
            writeFileSync(join(OUT, `${safeName(item.name)}-${vp.name}.png`), Buffer.from(shot.data, 'base64'))
          }
          report.push(row)
          const ok = row.offenders.length === 0 && row.scrollWidth <= row.vw + 1
          const redirected = row.url !== item.href.split('?')[0] ? ` → ${row.url}` : ''
          console.log(
            `${ok ? 'OK  ' : 'FAIL'} ${item.name.padEnd(24)} ${vp.name.padStart(4)}  scroll=${row.scrollWidth}${redirected}  ` +
              row.offenders.map((o) => `${o.sel}→${o.right}`).join(' | ').slice(0, 200),
          )
        }
      }
    }

    // RESP_PAGES задаёт список вручную — так проверяют адреса с id из базы (форма правки
    // материала, заявка): их скрипт сам выдумать не может. В этом режиме кука ставится сразу,
    // потому что список явно просит экраны под входом.
    const explicit = process.env.RESP_PAGES ? JSON.parse(process.env.RESP_PAGES) : null
    if (explicit !== null) {
      if (!Array.isArray(explicit) || explicit.length === 0) {
        throw new Error('RESP_PAGES задан, но пуст: проверять нечего')
      }
      if (cookie) await setSessionCookie()
      await measure(explicit)
    } else {
      const guest = await publicPages()
      if (!guest.length) throw new Error('публичных адресов нет: карта сайтов пуста — проверять нечего')
      await measure(guest)
      if (cookie) {
        await setSessionCookie()
        await measure(ADMIN_PAGES)
      }
    }
  } finally {
    // Chrome обязан умереть даже при падении прогона: живой процесс держит порт и профиль, и
    // следующий запуск начнётся с ошибки.
    ws.ws.close()
    proc.kill()
  }

  writeFileSync(join(OUT, 'report.json'), JSON.stringify(report, null, 2))
  const fails = report.filter((r) => r.offenders.length || r.scrollWidth > Number(r.vp) + 1)
  console.log(`\nОтчёт: ${join(OUT, 'report.json')}`)
  console.log(`Проверено ${report.length} комбинаций, проблем: ${fails.length}`)
  // Код возврата нужен gate-скрипту и человеку: надпись «проблем: 3» сама себя не остановит.
  if (fails.length) process.exitCode = 1
}

main().catch((e) => {
  console.error(e)
  process.exit(1)
})
```

Run: `node --check scripts/responsive-check.mjs`
Expected: пусто — синтаксис ES-модуля цел.

- [ ] **Шаг 8: Собрать стенд и прогнать проверку**

```bash
bash scripts/css
docker compose -f docker-compose.dev.yml up -d db
bash scripts/db-reset
rm -f public/install.lock
docker compose -f docker-compose.dev.yml run --rm -T --service-ports -e CMS_DB_NAME=cms_test php php -S 0.0.0.0:8080 -t public public/index.php
# следующим окном, когда стенд отвечает: ядро ставится формой http://localhost:8080/setup.php
docker compose -f docker-compose.dev.yml run --rm -T -e CMS_DB_NAME=cms_test php php -r 'require "config.php"; require "core/bootstrap.php"; installApp("news");'
```

Четыре вещи в этом блоке — условие, чтобы он запустился (повторяют шаг 9 задачи 17, отличаются
первой строкой):

- `bash scripts/css` до сборки стенда: без `public/assets/app.css` проверка адаптивности бессмысленна —
  страница откроется без стилей, переполнения не найдёт и доложит «проблем: 0». Это самый
  коварный зелёный прогон из всех, что есть в плане.
- `rm -f public/install.lock`: замок остаётся от предыдущего прогона установщика, и без снятия
  второй заход получил бы 404 вместо формы.
- `-e CMS_DB_NAME=cms_test` в обеих командах блока: боевая и dev базы не трогаются — форма ставит
  ядро в ту базу, которую видит контейнер стенда.
- `--service-ports` у `run`: `run` не публикует порты сам, за это отвечает именно этот флаг
  (`-P` — флаг `up`, и `run` его не понимает). У сервиса `php` в `docker-compose.dev.yml`
  заявлен `127.0.0.1:8080:8080`, поэтому без флага стенд с хоста не виден: браузер упрётся
  в пустой порт, и непонятно, код виноват или команда. `php -S` обязан получить
  скрипт-роутер `public/index.php` — иначе статика и `/setup.php` не отвечают.

После того как стенд поднят, но до прогона — два материала через экран `/news/admin/items/new` и
«На печать»: один с заголовком на пределе длины, второй с длинным именем рубрики и закреплённый.
Предел берётся из `NEWS_FORM_MAX['title']` в `apps/news/lib/validators.php` — сейчас это 120
символов; материал с более длинным заголовком форма не сохранит, и попытка взять число «с запасом»
кончается отказом, который легко принять за дефект проверки. Смысл не в конкретном числе, а в том,
что текст доходит до предела поля.
Это не наполнение демо-контентом: пустой список на экране 360 px ничего не ломает по построению,
и проверка на нём всегда зелёная. Строка таблицы справочника с длинным текстом — ровно то, что
критерий §12.18 обязан застать на узком экране, и адрес карточки появляется в карте сайтов только
после публикации (задача 20).

Прогону нужен системный Chrome: по умолчанию берётся путь Windows
`C:/Program Files/Google/Chrome/Application/chrome.exe`, на другой машине или в Linux его
перекрывает переменная `CHROME_PATH`. Порт отладки 9333 обязан быть свободен: живой Chrome от
прошлого прогона держит и порт, и профиль, и новый запуск начнётся с ошибки.

В другом окне, пока стенд поднят:

```bash
npm run responsive -- --base=http://localhost:8080
```

Expected: строка `OK`/`FAIL` на каждую комбинацию адреса и экрана, в конце
`Проверено N комбинаций, проблем: 0`. Нижняя граница `N` — 84: пять фиксированных публичных
адресов и девять экранов админки, умноженные на шесть экранов. Меньше 84 означает, что вход не
вышел и админка не проверена; больше — карта сайтов дала адреса материалов и рубрик. Число само
по себе ничего не значит: зелёным прогон считается только при `проблем: 0`, и иначе раскладку
чинят в шаблонах (`max-w-full`, `min-w-0`, `overflow-x-auto` на таблице справочника, перенос
вкладок) и повторяют прогон. Без зелёного прогона критерий §12.18 не закрыт, а задача 21 — ровно
он.

Отдельно смотреть на строку адреса «вход»: она обязана показывать `/login` без стрелки редиректа.
Публичные адреса меряются до установки куки именно для этого — под живой сессией маршрут входа
редиректит в `/admin`, и раскладка формы входа осталась бы непроверенной ни на одном вьюпорте, а
прогон всё равно был бы зелёным. Строка `вход … → /admin` означает, что порядок замера сломан.

Два места, где прогон обязан честно сказать про себя:

- `не удалось войти как admin@school.dev: куки cms_session нет` — тогда админка не проверена, и
  считать приёмку закрытой нельзя;
  причина почти всегда в пароле (`RESP_PASSWORD`) или в незакрытом замке установщика;
- `карта сайтов не дала адресов` — на стенде нет ни одного опубликованного материала; карточку
  материала проверяют следующим шагом руками, прежде чем ставить оценку «зелёный».

- [ ] **Шаг 9: Чек-лист выкладки и приёмки**

Создать `docs/deploy-checklist.md`:

````markdown
# Выкладка пилота на виртуальный хостинг

Порядок шагов — спецификация §11. Строки приёмки — §12, двадцать пунктов; напротив каждой
указано, чем она проверяется, потому что «проверено глазами» и «есть тест» — это разные
обещания.

Ни один пароль не попадает в файл, в отчёт и в память: значения читаются из переменных сессии,
боевой `config.php` создаётся на сервере и в git не лежит.

## Переменные своей сессии

```bash
export CMS_DEPLOY_USER='логин SSH'
export CMS_DEPLOY_HOST='адрес сервера'
export CMS_DEPLOY_PATH='/home/логин/cms'          # абсолютный путь проекта
export CMS_DEPLOY_MODE='public'                    # или root, если корень не переносится
export CMS_DEPLOY_URL='https://домен-школы.рф'    # для проверки раскладки
```

`bash scripts/deploy.sh --dry-run` — пробег без записей; первый настоящий запуск делают только
после него.

## Шаг 1. Зонд хостинга (§11.1)

```bash
KEY="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
BUILD="$(mktemp -d)"
sed "s/^\\\$probeKey = '';/\$probeKey = '${KEY}';/" scripts/hosting-probe.php > "$BUILD/probe.php"
grep -q "probeKey = '${KEY}'" "$BUILD/probe.php" || { echo 'ключ не подставился — на разборе формата sed'; exit 1; }
scp "$BUILD/probe.php" "$CMS_DEPLOY_USER@$CMS_DEPLOY_HOST:$CMS_DEPLOY_PATH/public/probe.php"
curl -sS "https://домен-школы.рф/probe.php?k=${KEY}&smtp=smtp.хостинга&port=587"
rm -rf "$BUILD"
```

Зонд отвечает один раз и удаляет себя. Последняя строка ответа — удалился ли; если `нет`, файл
снимают руками (`ssh … rm public/probe.php`) и не оставляют: он отвечает на запросы о сервере.

У ключа два источника, и это надо знать до пробега: вписанное в копию файла значение перекрывается
переменной окружения хостинга `CMS_PROBE_KEY`, если она задана и не пуста. Переменная нужна там,
где панель не даёт подставить значение в файл перед загрузкой. Если же она задана случайно или
осталась от прошлой проверки, `curl` получит 403 при формально верном ключе — выглядит как поломка
зонда, а на деле ключ просто другой. Поэтому перед пробегом проверяют, что в окружении сайта такой
переменной нет, либо берут ключ из неё и не правят файл.

Что должно быть в ответе: `[+]` напротив PHP ≥ 8.1, шести расширений, `stream_socket_client` и
`session.save_path`; `[!]` напротив document-корня — ожидаемо, если корень ещё не переносили, и
тогда режим `root` с корневым `.htaccess`. `[-]` напротив argon2id — не стопор: bcrypt уже
настроен в `core/auth.php`.

## Шаг 2. Перенос документ-корня

В панели хостинга: корень домена → `<CMS_DEPLOY_PATH>/public`. Если переноса нет —
`CMS_DEPLOY_MODE=root`, и тогда проверка раскладки обязательна (шаг 5): закрытые `core/`, `apps/`,
`sql/`, `config.php`.

## Шаг 3. Файлы

```bash
bash scripts/gate.sh
bash scripts/css
bash scripts/deploy.sh
```

Ворота перед выкладкой — не формальность: `git status` обязан быть пустым, иначе на сервер уйдёт
то, чего нет в репозитории.

## Шаг 4. Настройки и установщик (§11.3, §11.4)

На сервере: `cp config.sample.php config.php`, заполнить `DB_*`, `SITE_URL` (без слэша в конце),
`SITE_NAME`, `SMTP_*` (в пилоте письма не ходят — поля можно оставить пустыми), поставить права
`600`. Затем в браузере:

1. `https://домен/setup.php` — таблицы ядра и первый администратор;
2. `https://домен/admin/apps` → «Новости» → «Установить»;
3. повторный заход на `setup.php` обязан ответить 404 (замок).

## Шаг 5. Проверка на месте (§11.5)

```bash
bash scripts/deploy.sh            # проверка раскладки по CMS_DEPLOY_URL входит сюда
curl -sS https://домен/robots.txt
curl -sS https://домен/sitemap.xml | head -20
```

В базе (`tbl_app_migration` — ровно две строки установки, `tbl_app_installation` — `version`
`1.0.0`), в браузере — `/news`, `/news/admin` анонимом (403 или редирект на `/login`), три
стартовые рубрики, HTTPS и `secure`-куки.

## Шаг 6. Приёмка §12 на хостинге

Повторить пункты 1–18 тем же способом, что и на локальном стенде: тесты не переносим (на сервере
`tests/` нет), проверяем экранами. Пункты 19–20 — это ворота и SMTP-заглушка, они на хостинге не
воспроизводятся по определению: почтовый клиент проверен на mailpit в Docker (задача 10), а на
боевом сервере в пилоте письма не отправляются (§3).

| № §12 | Что проверяем | Чем |
|---|---|---|
| 1 | `setup.php` создаёт ядро и администратора, после замка — 404 | экран, шаг 4; `tests/http/SetupTest.php` |
| 2 | «Новости» не установлены → «Установить» → таблицы, две строки журнала, `version`, три рубрики | экран `/admin/apps`, шаг 5; `tests/db/AppsRegistryTest.php` |
| 3 | `requiresCore` выше ядра — отказ человеческим текстом, таблиц нет | fixture `future`; `tests/db/MigrationsTest.php` |
| 4 | повтор установки не дублирует журнал; «Обновить» только при версии ниже манифеста | fixture `demo`; `tests/db/MigrationsTest.php` |
| 5 | аноним на `/news/admin/**` — 403 или редирект, страницы с `noindex`, `robots.txt` с `Disallow` | экран + `curl`; `tests/http/NewsAdminTest.php` |
| 6 | редактор видит сводку со счётчиками и «закреплено N из M» | экран `/news/admin`; `tests/http/NewsAdminTest.php` |
| 7 | черновик без текста проходит, на печать без текста — отказ и в форме, и кнопкой | экран; `tests/db/NewsItemsTest.php` |
| 8 | занятый адрес — отказ с возвратом набранного | экран правки; `tests/unit/NewsFormRulesTest.php` |
| 9 | публикация → материал в ленте, в рубрике, на странице тега и в карте сайтов | экран + `curl /sitemap.xml`; `tests/db/NewsSitemapTest.php` |
| 10 | снятие с публикации убирает материал, срок сохраняет | экран; `tests/db/NewsItemsTest.php` |
| 11 | закреплённый — первым в ленте и в рубрике | экран; `tests/db/NewsFeedTest.php` |
| 12 | смена адреса → 301 со старого, старый помнится, лимит 10 | экран + `curl -I`; `tests/db/NewsItemsTest.php` |
| 13 | материал «ждёт срока» не виден до срока и виден во вкладке | экран; `tests/unit/NewsPublishRulesTest.php` |
| 14 | рубрика: переименование не меняет адрес, описание видно, скрытие убирает из ленты, удаление непустой отказывает, порядок кнопками | экран `/news/admin/categories`; `tests/db/CatalogTest.php` |
| 15 | тег: переименование сохраняет адрес, удаление с материалами отказывает, создать нельзя | экран `/news/admin/tags`; `tests/db/CatalogTest.php` |
| 16 | поиск работает с первого ввода, `%` и `_` ищутся как текст | экран; `tests/unit/EscapeLikeTest.php`, `tests/db/NewsItemsTest.php` |
| 17 | все формы работают без JavaScript | отключить JS в браузере и пройти путь «вход → черновик → печать → рубрики»; `tests/http/NewsAdminTest.php` ходит без JS по построению |
| 18 | адаптивность 360…1440 | `npm run responsive` (шаг 7 этой задачи) |
| 19 | ворота качества | `bash scripts/gate.sh` |
| 20 | то же на хостинге; письма не отправляются, клиент проверен на заглушке | шаги 1–6 этого файла; `tests/http/SmtpIntegrationTest.php` |

## Откат

Боевая база не пересоздаётся никогда: миграции раздела дополняющие (Global Constraints), а
`scripts/db-reset` трогает только `cms_test`. Откат выкладки файлов — повторный `rsync` из коммита
прошлой версии (`git worktree add ../old <commit>` и `CMS_DEPLOY_PATH=… bash ../old/cms/scripts/deploy.sh`).
````

Проверить документ глазами: в нём нет ни одного адреса и ни одного пароля боевого сервера, только
имена переменных и заглушки `домен-школы.рф`, `логин SSH`.

- [ ] **Шаг 10: Карта файлов плана — правка контроллера**

Карту файлов в `docs/superpowers/plans/part1.md` правит контроллер своим отдельным коммитом:
тексты планов исполнителю задачи не принадлежат (Global Constraints). Исполнитель этот файл на
запись не открывает и в коммит задачи не кладёт; вместо этого он перечисляет в отчёте
расхождения карты с собранным деревом, а контроллер сверяет их со списком ниже и вносит правку.

В карте файлов (раздел «Структура файлов») не хватает того, что появилось в задаче 21. Три скрипта
выкладки (`deploy.sh`, `hosting-probe.php`, `responsive-check.mjs`) там уже перечислены в блоке
`scripts/`, поэтому карта расходится с деревом в четырёх строках: две новые и две устаревшие.

Номера строк карты здесь сознательно не пишутся: карта растёт с каждой задачей, и номер устаревает
к моменту чтения — в первом варианте этого шага все четыре номера уехали на три строки вниз, и
исполнитель искал не то место. Якорь — текст строки.

Первое. После строки `.gitignore` — новая строка запасной раскладки (описание выравнивается в ту
же колонку, что и у соседних строк карты):

```
  .htaccess                      запасная раскладка: закрытие служебных каталогов, rewrite в public/
```

Второе. Перед строкой `  tests/` — новый блок каталога документации:

```
  docs/
    deploy-checklist.md          шаги §11 и приёмка §12 на хостинге
```

Третье. Строка `phpstan.neon`: в карте перечислены пути `core/ admin/ theme/ apps/ public/ tests/`,
а конфиг с фикс-раунда задач 8 и 16 включает ещё `scripts`. Дописать этот путь: читатель, который
по карте решит, что хостеровые скрипты никто не анализирует, напишет зонд без типов.

Четвёртое. Строка `responsive-check.mjs` описана как «360…1440 через Playwright». Внешнего пакета
нет (шаг 6), проверку гоняет системный Chrome через протокол его отладки — описание заменить, иначе
карта противоречит шагам 6 и 7 этого же плана.

Карта в начале плана — обещание, что появится; расхождение с ней означает, что рецензент задачи
искал файл не там.

- [ ] **Шаг 11: Ворота, руками и коммит**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit`
Expected: PASS — четыре набора; в `guard` добавился `DeploySafetyTest`.

Run: `bash scripts/gate.sh`
Expected: зелёные все четыре проверки. `scripts/hosting-probe.php` попадает в `php -l`, в PHPStan
(`phpstan.neon` включает путь `scripts`) и в PHP-CS-Fixer (finder включает `scripts/`): файл написан
по PSR-12, одиночные кавычки и `declare(strict_types=1)` уже в нём, поэтому фиксер не должен найти
что переформатировать. Если уровень 6 найдёт в зонде нетипизированное чтение из `$_SERVER` или
неопределённый индекс — это правка текста зонда, а не основание вычёркивать `scripts` из анализа.

Run: `node --check scripts/responsive-check.mjs && npm run responsive -- --base=http://localhost:8080`
Expected: `проблем: 0`.

Проверить руками (то, что скриптом не меряется):

- в браузере с отключённым JavaScript пройти путь «вход → черновик → «На печать» → скрытие
  рубрики» — критерий §12.17;
- на узком экране (360) в форме материала поле обложки не вылезает за экран, а в списке
  справочника таблица прокручивается, а не режет колонки;
- `/sitemap.xml` на собранном стенде содержит те адреса, которые скрипт взял из карты, — иначе
  «проблем: 0» означает «проверяли нечего».

Run:
```bash
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
git add scripts/deploy.sh scripts/hosting-probe.php scripts/responsive-check.mjs .htaccess \
  docs/deploy-checklist.md package.json tests/guard/DeploySafetyTest.php
git status --short
git commit -m "Выкладка пилота: зонд хостинга, rsync со защитой данных, проверка адаптивности и чек-лист приёмки"
```

`package-lock.json` в коммите не нужен: новых пакетов задача не добавляет (шаг 6), и файл фиксации
версий остаётся таким, каким его оставила задача 4. `node_modules/` в `.gitignore` (задача 1),
поэтому из Node-артефактов в коммит не попадает ничего. Тексты планов (`docs/superpowers/plans/**`)
в коммит задачи тоже не кладут: их правит контроллер своим отдельным коммитом — см. шаг 10.
Перед `git add` список путей сверяется с `git status --short`: в индекс обязан попасть ровно он.

---

## Конец плана

Задач — 21, файлов — 10 частей этого документа. Пилот готов, когда выполнены §12 спецификации, а
это ровно шаги 8–11 последней задачи: локальный стенд зелёный в тестах, в воротах и в
адаптивности; выкладка на хостинг повторяет те же проверки экранами.

Что план не закрывает, потому что это не пилот (§14 спецификации): конструктор страниц из блоков и
живые блоки новостей, курсы и личный кабинет, доска объявлений, магазин, заявки и рассылка,
перенос данных из PostgreSQL и переключение домена. Задачи 18–21 Next.js-плана «Новостей»
(`english-portal`) остаются в силе и выполняются после этого пилота (Global Constraints).

Перед слиянием — общая рецензия всей ветки (`superpowers:requesting-code-review`): смотреть не
диффы задач, а три вещи, которые одной задачей не видны — что `apps/news/lib/*.php` действительно
повторяют правила `english-portal/src/apps/news/lib/*.ts`, а не пересказывают их по памяти; что
ни один из шести гейтов маршрутизации не объезжается новым маршрутом; что ни один пароль не
попал в репозиторий, в отчёты и в чек-лист.
