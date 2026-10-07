# CMS на PHP: ядро и раздел «Новости» — план реализации пилота

> **For agentic workers:** REQUIRED SUB-SKILL: используйте `superpowers:subagent-driven-development` (рекомендуется)
> или `superpowers:executing-plans`, чтобы выполнять этот план шаг за шагом. Шаги помечены `- [ ]`; один шаг — одно
> действие (2–5 минут).

**Goal:** CMS на PHP 8.3 и MySQL, которая целиком работает на виртуальном хостинге (без долгоживущего процесса, без
Node, без Docker на сервере): ядро по образцу WordPress (маршрутизатор, PDO-слой, хуки, манифесты приложений,
миграции и журнал обновлений, сессии и CSRF, SMTP-клиент, sitemap и robots) плюс устанавливаемое приложение
«Новости» с полным переносом правил из `english-portal/src/apps/news`.

**Architecture:** три слоя каталогов — `core/` (ядро, его не правят приложения), `admin/` и `theme/` (каркас админки
и наша вёрстка), `apps/<id>/` (приложения: манифест, маршруты, действия, правила, шаблоны, SQL, seed). Запрос идёт
`public/index.php` → `config.php` → `core/bootstrap.php` → сессия → `core/apps.php` собирает таблицу маршрутов из
манифестов → `core/router.php` сопоставляет путь → гейты (приложение включено → путь не приватный → есть право) →
`doAction('before_route')` → контроллер или POST-обработчик → `render()` → layout → `doAction('after_route')`.
Приложения ставятся и обновляются через `/admin/apps`: `planApp()` сверяет контрольные суммы, `applyApp()` применяет
файлы одной транзакцией и пишет журнал.

**Tech Stack:** PHP 8.3 (минимум 8.1), `declare(strict_types=1)` в каждом файле, MySQL 8.0 (совместимость с 5.7),
InnoDB `utf8mb4`, ноль рантайм-зависимостей. Dev-only через composer: PHPUnit 11, PHPStan (уровень 6), PHP-CS-Fixer.
Tailwind собирается локально Node'ом, в репозитории лежит готовый `public/assets/app.css`. Локальная среда —
`docker-compose.dev.yml` (`php:8.3-cli` с расширениями, `mysql:8.0` на порту хоста 3307, `mailpit`).

**Spec:** `cms/docs/superpowers/specs/2026-10-06-php-cms-pilot-design.md` — план спорит со спецификацией, читайте обе
документации вместе. Правила раздела «Новости» заданы дважды: спецификацией 2026-10-06 (раздел 8) и её источником —
`english-portal/docs/superpowers/specs/2026-10-04-news-app-design.md` вместе с кодом `english-portal/src/apps/news`
и его тестами `english-portal/tests/news-*.test.ts`. При расхождении памятью не пользуемся: открываем TS-файл,
названный в задаче, и переносим правило из него.

## Отступления от спецификации (осознанные, каждое обосновано)

1. **Соглашение об именах функций — camelCase, имена хуков — snake_case.** Спецификация (§6.1) требует, чтобы функции
   `apps/news/lib/*.php` назывались так же, как TS-модули, — значит `visibleWhere`, `isOpen`, `createItem`,
   `resolveTagIds`. Чтобы в проекте не было двух соглашений, все наши функции camelCase (`addRoute`, `escapeLike`,
   `nowUtc`, `getOption`), а строки-имена хуков остаются snake_case (`before_route`, `sitemap_urls`) — их задаёт
   спецификация (§5.4) и они видны в шаблонах.
2. **Функциональные библиотеки подключаются явно, классы — автозагрузкой.** `add_action`-подобный API WordPress
   процедурный, а функции PHP не autoload'ятся: `core/bootstrap.php` подключает файлы ядра списком,
   `apps/news/lib/boot.php` — файлы приложения. Классов мало (`Cms\Core\Mailer`, `Cms\Core\SmtpSocket`,
   `Cms\Core\MigrationPlan`), для них работает PSR-4-автозагрузчик `Cms\Core\ → core/`.
3. **Argon2id в dev-контейне может отсутствовать.** Официальный образ `php:8.3-cli` собирается без
   `--with-password-argon2`, а пересборка ядра PHP в контейнере стоит дороже, чем польза. `hashPassword()` выбирает
   алгоритм по наличию поддержки (`PASSWORD_ARGON2ID` → иначе `PASSWORD_BCRYPT`), обе ветки покрыты юнит-тестом, а
   наличие Argon2id на хостинге проверяет зонд (задача 21, критерий §11.1). Боевой сайт получает Argon2id, если
   хостинг его собрал.
4. **Установщик «Обновить» проверяется на fixture-приложениях, а не на «Новостях».** Оба файла SQL раздела `apps/news`
   применяются при первой установке, поэтому в журнале не остаётся незаписанных релизов и кнопка «Обновить» на нём не
   появляется по определению. `appManifests()` дополнительно читает каталог из
   `config.php` (`EXTRA_APPS_DIR`, по умолчанию пусто) — тесты подставляют `tests/fixtures/apps`, где лежат
   `demo` (два файла SQL, `requiresCore 1.1.0` — на нём проверяются «Установить» и «Обновить») и `future`
   (`requiresCore 99.0.0` — на нём проверяется отказ установки, критерий §12.3). На сервере этот ключ не заполняется.
5. **В dev-контейнере у MySQL пустой пароль (`MYSQL_ALLOW_EMPTY_PASSWORD`).** Правило человека «ни один пароль не
   попадает в репозиторий» важнее удобства: база доступна только с `localhost:3307`, а боевой `config.php` создаётся
   на сервере вручную и в git не попадает.
6. **Блочные функции чтения новостей не портируются.** `blockNews`, `blockSingle`, `publishedBlockOptions`,
   `parseTags`, `categoryBlockOptions`, `tagBlockOptions`, `toBlock` из `english-portal/src/apps/news/lib/feed.ts`
   и `catalog.ts` обслуживают конструктор страниц — его в пилоте нет (спецификация §3). Задачи прямо говорят, какие
   функции переносим, а какие нет.
7. **`tbl_app_installation.id` — `VARCHAR(32)` (не 26).** Идентификатор установки равен `id` приложения из манифеста
   (`news`, `demo`), а не строке из генератора; длина 32 взята из DDL спецификации §6.2.
8. **Четыре набора тестов вместо трёх.** Спецификация §10.2 делит тесты на «правила без базы», «стражи исходников» и
   «HTTP на тестовой базе». Миграциям, PDO-слою и журналу обновлений нужна база, но не нужен веб-сервер: для них
   добавлен набор `tests/db/`. Слои из спецификации при этом сохранены — `tests/unit/` по-прежнему не трогает базу.
9. **Обновление раздела — не одна транзакция, а журнал после каждого файла.** Спецификация §6.3 описывает `applyApp()`
   как транзакцию PDO. В MySQL любой `CREATE TABLE` и `ALTER TABLE` вызывает неявный коммит, поэтому откатить уже
   созданную таблицу транзакция не может — обещание было бы невыполнимым. Вместо него строка журнала пишется сразу
   после каждого применённого файла: упав на втором файле из двух, установка оставляет первый применённым и
   записанным, а повторный запуск продолжает со второго. Чтобы повтор был безопасным, файлы SQL приложений пишутся
   через `CREATE TABLE IF NOT EXISTS`; страж задачи 11 это проверяет. Версия в `tbl_app_installation` поднимается
   один раз, после последнего файла.
10. **PHPStan 2.x вместо 1.12.** Спецификация фиксирует уровень 6, а не минор анализатора. У 1.12 на каждом прогоне
    ворот печатается баннер «это старая версия», и подавить его нечем (в `analyse --help` такого флага нет), а
    «вывод ворот без предупреждений» — общее ограничение. Код задач 2–21 на момент решения ещё не написан, поэтому
    на существующем коде апгрейд падений не даёт.
11. **`.gitattributes` с `* text=auto eol=lf`.** В спецификации его нет, но без него клон на машине с
    `core.autocrlf=true` выкладывает в рабочее дерево CRLF: у `scripts/gate.sh` и `scripts/db-reset` ломается shebang,
    а `public/.htaccess` и файлы `docker/` уходят на хостинг с лишними `\r`.
12. **Обе базы dev-контейнера создаются с явным `utf8mb4_unicode_ci`.** `MYSQL_DATABASE` отдаёт базу на серверный
    default, а это `utf8mb4_0900_ai_ci` — его не знает MySQL 5.7, который может достаться на хостинге. Поэтому базы
    создаёт `docker/mysql-init.sql`, а `command:` сервиса задаёт серверное сопоставление. Правило для задач 2+:
    каждый `CREATE TABLE` в `sql/` — с явным `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
13. **`composer.lock` в репозитории.** Спецификация о нём молчит. Lock держит версии PHPUnit, PHPStan и CS-Fixer
    одинаковыми на всех 21 задаче: без него тихо поднимется минор фиксера, и зелёные ворота задачи N падут на коде
    задачи N+1, зависимости не трогавшей. На сервере lock ничего не меняет — в `require` только `php`.
14. **Одно предупреждение PHP-CS-Fixer в воротах разрешено.** Ограничение «вывод без предупреждений» (пункт
    Global Constraints) остаётся в силе для всего, что касается нашего кода, но убрать этот текст нечем: фиксер
    печатает его безусловно, когда `PHP_MAJOR.MINOR` выше минимума из `composer.json` (образ — 8.3, минимум — 8.1),
    и не смотрит ни на `PHP_CS_FIXER_IGNORE_ENV` (флаг к тому же устарел и добавляет строку о своём устаревании),
    ни на `setUnsupportedPhpVersionAllowed()`. Понижать `config.platform.php` до 8.1 нельзя: PHPUnit 11 требует
    PHP >= 8.2, и разрешение зависимостей сломается. Реплика идёт в stderr и не влияет на код выхода, поэтому
    «зелёные ворота» означают: `php -l`, PHPStan, стиль и PHPUnit — без собственных замечаний. Все прочие строки
    предупреждений — находка, как и было.

## Global Constraints

Каждая задача обязана соблюдать эти пункты; рецензент сверяет диффы с ними.

- PHP 8.3, минимально поддерживаемая версия 8.1: никакого синтаксиса 8.4 (`new` в цепочках без скобок,
  asymmetric visibility, `array_find`). В каждом файле первая строка после `<?php` — `declare(strict_types=1);`.
- Ноль рантайм-зависимостей: `composer.json` содержит только `require-dev`. На сервере нет ни `vendor/`, ни Node.
- MySQL InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, префикс таблиц `tbl_` из `config.php`. Имя таблицы в запросе —
  только через `t('news_item')`. Перечисления — `VARCHAR` + справочник в PHP (`CHECK` в MySQL 5.7 молча
  игнорируется). Булевы — `TINYINT(1)`.
- Миграции только дополняющие: в каталогах `sql/` нет `DROP`, `TRUNCATE`, `DELETE FROM`, `ALTER … DROP COLUMN`.
- Никакого склеивания строк в SQL: только плейсхолдеры `?` или `:name`. Поиск по подстроке — через `escapeLike()`
  (экранирует `\`, `%`, `_`). Запрещены `PDO::query`, `PDO::exec` и `mysqli_*`.
- Все даты в базе — `DATETIME` в UTC. Перевод в `Europe/Moscow` только в `core/date.php`: вне этого файла запрещены
  `date(`, `->format(`, `strtotime(`, `new DateTime` (страж).
- Вывод в шаблонах — только через `e()`; запрещены `echo $` и `<?=` без `e(`, кроме блоков, помеченных комментарием
  `/* @safe html */` (разметка абзацев с `**выделением**`).
- Функция `mail()` запрещена во всём проекте; письма ходят только через `Cms\Core\Mailer`.
- IP-адрес клиента читается только из `REMOTE_ADDR`; `x-forwarded-for`, `x-real-ip`, `client-ip` не читаются нигде
  (страж).
- Приватные пути из манифестов (`/news/admin`) закрыты гейтом, попадают в `robots.txt` как `Disallow` и несут
  `<meta name="robots" content="noindex, nofollow">`.
- Все формы — `method="post"` со скрытым полем `_token`; намерение кнопки — отдельное поле `intent` или `op`; после успеха
  PRG-редирект с `?notice=<ключ>`. Каждый экран работает без JavaScript.
- Пределы полей формы читаются из `apps/news/lib/constants.php`; литерал предела в шаблоне — находка стража.
- Ключ уведомления обязан иметь кнопку, которая его вызывает, и каждая кнопка — ключ в словаре (страж
  «кнопка ↔ обработчик ↔ словарь»).
- Все строки интерфейса, комментарии и тексты ошибок — по-русски, без англицизмов и иероглифов. Идентификаторы,
  ключи, адреса и имена таблиц — латиница.
- Ни один пароль и ни одно значение боевого `config.php` не попадает в репозиторий, в отчёты и в память. Если команду
  нужно выполнить с секретом, он извлекается в переменную внутри команды и не печатается.
- Адаптивность 360…1440 обязательна для каждого экрана (`scripts/responsive-check.mjs`, задача 21).
- Ворота перед каждым коммитом: `bash scripts/gate.sh` — `php -l` по всем файлам, PHPStan уровень 6, PHP-CS-Fixer
  `--dry-run --diff`, PHPUnit полностью зелёный, вывод без предупреждений. Единственная разрешённая реплика —
  подсказка PHP-CS-Fixer о том, что образ PHP новее минимума проекта (отступление 14); всё остальное — находка.
- Коммит — от «Алексей <zanzibaradevil@gmail.com>» через переменные окружения `GIT_AUTHOR_*`/`GIT_COMMITTER_*`;
  `git config` не менять, `--amend` без просьбы человека не использовать.
- Все команды выполняются в каталоге `cms/`. Боевая база `english-portal` не трогается: ни дампов, ни миграций, ни
  записей. Задачи 18–21 Next.js-плана «Новости» остаются в силе и выполняются после пилота.
- Рабочая ветка — `main` этого репозитория (`cms/`); решение человека от 2026-10-06: пилот ведём в основном
  чекауте, отдельный worktree не создаём.

## Структура файлов

Карта того, что появится. Каждый файл — одна ответственность; задачи ниже называют их в том же виде.

```
cms/
  composer.json                  require-dev: phpunit, phpstan, php-cs-fixer
  phpunit.xml                    четыре набора тестов: unit, db, guard, http
  phpstan.neon                   уровень 6, пути core/ admin/ theme/ apps/ public/ tests/
  .php-cs-fixer.dist.php         PSR-12 + declare_strict_types
  .gitignore                     config.php, vendor/, кэш инструментов, содержимое каталога загрузок
  .gitattributes                 переносы строк LF: без них клон под core.autocrlf даёт CRLF в shebang
  docker-compose.dev.yml         php (8080), db (3307), mailpit (8025/1025)
  docker/php-dev.Dockerfile      php:8.3-cli + pdo_mysql, mbstring, gd, openssl, fileinfo, procps + composer
  docker/mysql-init.sql          создаёт cms_dev и cms_test с utf8mb4_unicode_ci
  config.sample.php              образец; боевой config.php вне репозитория
  public/
    index.php                    единственная точка входа
    setup.php                    одноразовый установщик ядра
    .htaccess                    rewrite, защитные правила, заголовки безопасности
    assets/app.css               собранный Tailwind (в репозитории)
    assets/og-default.png        фирменное превью 1200×630 (в репозитории, рисуется scripts/make-og-default.php)
    uploads/.gitkeep
  core/
    bootstrap.php                подключение библиотек ядра, автозагрузчик, TZ, обработчики ошибок
    config-loader.php            чтение config.php с проверкой обязательных ключей
    version.php                  CORE_VERSION = '1.1.0'
    hooks.php                    addAction/addFilter/doAction/applyFilters
    escape.php                   e() — единственный вывод в шаблонах
    id.php                       newId(): 26 символов, сортируемый по времени
    date.php                     единственный перевод UTC ↔ Москва и русский вывод дат
    text.php                     plural(), excerpt(), formatPrice-free: только текст
    db.php                       db(), t(), all(), one(), value(), run(), tx(), escapeLike()
    request.php                  method(), path(), query(), post(), files(), clientIp()
    response.php                 respond(), redirect(), notFound(), forbidden(), redirectWithNotice()
    ResponseFinished.php         класс-сигнал: тесты ловят его вместо exit
    router.php                   addRoute(), routeTable(), matchRoute(), dispatch()
    routes.php                   registerCoreRoutes() — /, /login, /logout, /admin, /admin/apps,
                                 /sitemap.xml, /robots.txt
    session.php                  startSession(), loginUser(), logoutUser(), sessionUserId()
    template.php                 render(), partial(), e(), разметка абзацев richText()
    notice.php                   noticeText(), noticeOfRequest()
    csrf.php                     csrfToken(), csrfField(), csrfCheck()
    form.php                     formValues(), isOn(), fieldError(), formError(), errorResult()
    auth.php                     hashPassword(), attemptLogin(), currentUser(), getEditorAccess(),
                                 loginBlocked(), clientIp-лимиты
    options.php                  getOption(), setOption()
    apps.php                     appManifests(), appInstallations(), appState(), registerAppRoutes()
    migrations.php               planApp(), applyApp(), installApp(), seedApp()
    MigrationPlan.php            класс Cms\Core\MigrationPlan (PSR-4: имя файла = имя класса)
    Mailer.php                   класс Cms\Core\Mailer
    SmtpSocket.php               класс Cms\Core\SmtpSocket (stream_socket_client, STARTTLS, AUTH LOGIN)
    seo.php                      absoluteUrl(), sitemapXml(), robotsTxt()
    log.php                      logInfo(), logWarn(), logError() в storage/errors.log
    templates/error.php          страница 500
    templates/setup.php          экран одноразового установщика
    templates/admin/summary.php  сводка /admin
    templates/admin/apps.php     раздел «Приложения»
    templates/403.php            отказ в доступе
    storage/.gitkeep             errors.log вне web-корня
  admin/
    layout.php                   каркас админки: шапка, вкладки из adminNav, уведомления
  theme/
    layout.php                   публичный layout: head, шапка, футер, кот
    login.php                    форма входа (служебный экран ядра, живёт в теме)
    home.php                     заглушка главной со ссылкой на ленту
    404.php
    partials/header.php  partials/footer.php  partials/cat.php
    news/                        переопределения шаблонов приложения (пусто до задачи 16)
  apps/news/
    manifest.php                 id, title, version 1.0.0, requiresCore 1.1.0, privatePaths, sql, adminNav, seed
    routes.php                   четыре публичных маршрута и их контроллеры (подключает registerAppRoutes())
    lib/boot.php                 порядок подключения файлов правил
    lib/constants.php            пределы полей, статусы, словари уведомлений
    lib/visibility.php           isOpen(), visibleWhere(), orderNews(), tooManyPinned()
    lib/validators.php           parseBody(), bodyToFormText(), splitBold(), parseTagList(),
                                 parsePublishedAt(), formPublishedAt(), imagePath(), parseQuery(),
                                 resolveStatus(), parseNewsForm(), NEWS_FORM_FIELDS
    lib/slug.php                 slugify(), ensureUniqueSlug()
    lib/catalog.php              рубрики и теги (порт english-portal/src/apps/news/lib/catalog.ts)
    lib/feed.php                 публичное чтение (порт feed.ts без блочных функций)
    lib/items.php                редакторские выборки и сохранение (порт items.ts)
    lib/seo.php                  seoOf(), ogImageOf(), articleHead(), newsArticleLd()
    lib/upload.php               приём файла: finfo по содержимому, ≤5 МБ, белый список расширений
    lib/admin.php                вкладки админки раздела и сборка адреса «куда вернуться»
    lib/form.php                 правила экрана формы: кнопки намерения, пределы, возврат набранного
    admin.php                    контроллеры экранов админки раздела
    actions/items.php            POST-обработчики кнопок списка (печать, снятие, закрепка, открепка)
    actions/item.php             POST-обработчик формы материала (создание и правка)
    actions/catalog.php          POST-обработчики рубрик и тегов
    templates/…                  feed.php, single.php, category.php, tag.php, admin/*.php,
                                 partials/{search,categories,list,card,pager,body}.php
    sql/20261006_0001_catalog.sql
    sql/20261006_0002_items.sql
    seed.php                     три стартовые рубрики, идемпотентно по slug
  sql/
    0001_core_baseline.sql       user, login_attempt, option, app_installation, app_migration
  scripts/
    gate.sh                      php -l + phpstan + cs-fixer + phpunit
    db-reset                     пересоздаёт ТОЛЬКО cms_test
    css                          сборка Tailwind (Node у разработчика)
    make-og-default.php          одноразовая заготовка public/assets/og-default.png (нужен GD)
    responsive-check.mjs         360…1440 через Playwright
    deploy.sh                    rsync по SSH, два варианта раскладки
    hosting-probe.php            одноразовый зонд хостинга (удаляется после проверки)
  tests/
    bootstrap.php                окружение cms_test, подключение ядра
    http/Kernel.php              поднимает php -S и ходит curl-ом
    unit/  db/  guard/  http/
    fixtures/apps/demo/          два файла SQL: на нём проверяются «Установить» и «Обновить»
    fixtures/apps/future/        requiresCore 99.0.0: на нём проверяется отказ установки
    fixtures/theme/              переопределения шаблонов для TemplateTest
  tailwind.config.js  package.json  postcss.config.js   (только для локальной сборки CSS)
```

---

# Этап A. Ядро

### Задача 1: Окружение разработки и скелет проекта

**Files:**
- Create: `docker-compose.dev.yml`, `docker/php-dev.Dockerfile`, `docker/mysql-init.sql`
- Create: `composer.json`, `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`
- Create: `config.sample.php`, `public/index.php`, `public/.htaccess`, `public/uploads/.gitkeep`,
  `core/storage/.gitkeep`, `.gitignore`, `.gitattributes`, `scripts/gate.sh`, `scripts/db-reset`
- Create: `tests/bootstrap.php`, `tests/unit/SmokeTest.php`
- Commit: `composer.lock` (его фиксирует `composer install` на шаге 8) — lock держит версии
  инструментов одинаковыми на всех задачах; в `.gitignore` его нет, поэтому коммитить надо явно.
- Test: `tests/unit/SmokeTest.php`

**Interfaces:**
- Consumes: ничего (первая задача).
- Produces: `bash scripts/gate.sh` — единственные ворота качества; контейнеры `cms-php-1` (порт 8080),
  `cms-db-1` (порт хоста 3307), `cms-mailpit-1` (8025); `cms_test` — база, которую сбрасывает `scripts/db-reset`;
  переменные окружения `CMS_DB_HOST`, `CMS_DB_PORT`, `CMS_DB_NAME`, `CMS_DB_USER`, `CMS_DB_PASS`, `CMS_SITE_URL`,
  `CMS_TABLE_PREFIX`, `CMS_EXTRA_APPS_DIR`.

- [ ] **Шаг 1: Образ PHP для разработки**

`docker/php-dev.Dockerfile`:

```dockerfile
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libonig-dev libzip-dev unzip git procps \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring gd zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
```

`openssl` и `fileinfo` в official-образе уже есть; `pdo_mysql` нужен для базы, `gd` — для проверки размеров
загружаемых картинок, `mbstring` — для транслитерации и `htmlspecialchars` в UTF-8, `zip` — Composer ставит пакеты из zip-архивов.
`procps` ставится отдельно и только в dev-образ: `pkill` нужен помощнику HTTP-тестов (задача 5), чтобы гасить
`php -S` на порту 8123; в боевом образе его нет.

- [ ] **Шаг 2: Состав окружения**

`docker-compose.dev.yml`:

```yaml
services:
  php:
    build:
      context: .
      dockerfile: docker/php-dev.Dockerfile
    container_name: cms-php-1
    working_dir: /app
    volumes:
      - .:/app
    ports:
      - '8080:8080'
    environment:
      CMS_DB_HOST: db
      CMS_DB_PORT: '3306'
      CMS_DB_NAME: cms_dev
      CMS_DB_USER: root
      CMS_DB_PASS: ''
      CMS_SITE_URL: http://localhost:8080
      CMS_TABLE_PREFIX: tbl_
      CMS_SMTP_HOST: mailpit
      CMS_SMTP_PORT: '1025'
      CMS_MAILPIT_URL: http://mailpit:8025
      # Каталог дополнительных приложений: пустая строка — значит «дополнений нет».
      CMS_EXTRA_APPS_DIR: ''
    command: php -S 0.0.0.0:8080 -t public public/index.php
    depends_on:
      db:
        condition: service_healthy

  db:
    image: mysql:8.0
    container_name: cms-db-1
    environment:
      MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'
    # Сопоставление сервера задаётся явно: базы без `COLLATE` (и дамп с хостинга) иначе получают
    # utf8mb4_0900_ai_ci от 8.0, которого MySQL 5.7 на боевом хостинге не знает.
    # cms_dev и cms_test с нужным COLLATE создаёт docker/mysql-init.sql.
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
    ports:
      - '3307:3306'
    volumes:
      - ./docker/mysql-init.sql:/docker-entrypoint-initdb.d/10-init.sql:ro
      - cms-db-data:/var/lib/mysql
    healthcheck:
      test: ['CMD', 'mysqladmin', 'ping', '-h', '127.0.0.1']
      interval: 5s
      timeout: 5s
      retries: 20

  mailpit:
    image: axllent/mailpit
    container_name: cms-mailpit-1
    ports:
      - '8025:8025'

volumes:
  cms-db-data:
```

`docker/mysql-init.sql`:

```sql
-- Сопоставление баз задаётся явно: спецификация требует utf8mb4 / utf8mb4_unicode_ci,
-- а MySQL 5.7 на боевом хостинге про utf8mb4_0900_ai_ci (default сервера 8.0) ничего не знает.
-- cms_dev раньше создавалась переменной MYSQL_DATABASE и доставала серверный default — отсюда
-- явный CREATE здесь, а не в compose.

-- Рабочая база разработчика.
CREATE DATABASE IF NOT EXISTS cms_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Вторая база нужна тестам: их сбрасывает scripts/db-reset, а cms_dev остаётся разработчику.
CREATE DATABASE IF NOT EXISTS cms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Порт хоста 3307: 5433 занят `english-portal-db-1`, 3306 — вторым проектом.

Сценарий-маршрутизатор `public/index.php` в `command` обязателен, а не только `-t public`: без него встроенный
сервер PHP сам решает, что отдавать, и любой адрес кроме существующего файла попадает в `index.php` с `REQUEST_URI`
«как есть», а `/news/rubrika` просто отдаётся как 404 от сервера. С маршрутизатором весь запрос идёт в ядро, а
статику он же пропускает правилом `PHP_SAPI === 'cli-server'` (задача 5). На Apache то же делает `public/.htaccess`.

- [ ] **Шаг 3: Инструменты разработчика**

`composer.json`:

```json
{
  "name": "purrfect/cms",
  "description": "CMS на PHP и MySQL для виртуального хостинга",
  "type": "project",
  "license": "proprietary",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.4",
    "phpstan/phpstan": "^2.0",
    "friendsofphp/php-cs-fixer": "^3.64"
  },
  "autoload": {
    "psr-4": {
      "Cms\\Core\\": "core/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Cms\\Tests\\": "tests/"
    }
  },
  "config": {
    "sort-packages": true
  }
}
```

`phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnNotice="true"
         failOnDeprecation="true"
         beStrictAboutOutputDuringTests="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/unit</directory>
    </testsuite>
    <testsuite name="db">
      <directory>tests/db</directory>
    </testsuite>
    <testsuite name="guard">
      <directory>tests/guard</directory>
    </testsuite>
    <testsuite name="http">
      <directory>tests/http</directory>
    </testsuite>
  </testsuites>
  <source>
    <include>
      <directory>core</directory>
      <directory>apps</directory>
      <directory>admin</directory>
      <directory>theme</directory>
    </include>
  </source>
</phpunit>
```

`failOnWarning`/`failOnNotice` — требование «вывод без шума» из критерия §12.19.

`phpstan.neon`:

```neon
parameters:
  level: 6
  paths:
    - core
    - admin
    - theme
    - apps
    - public
    - tests
  excludePaths:
    analyse:
      - public/assets/*
  treatPhpDocTypesAsCertain: false
```

`.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/core', __DIR__ . '/admin', __DIR__ . '/theme', __DIR__ . '/apps', __DIR__ . '/public', __DIR__ . '/tests', __DIR__ . '/scripts'])
    ->notPath('public/assets')
    ->notPath('public/setup.php.lock');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'no_trailing_whitespace' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => false,
        'blank_line_after_opening_tag' => true,
    ])
    ->setFinder($finder);
```

- [ ] **Шаг 4: Образец настроек и первая точка входа**

`config.sample.php`:

```php
<?php

declare(strict_types=1);

// Образец настроек. На сервере скопируйте в config.php (права 600) и заполните своими значениями.
// В репозиторий config.php не попадает: его держит .gitignore.
// Каждый ключ читает config-loader.php; значение из окружения используется, только если ключ пуст.

return [
    // База данных.
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'cms_dev',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'TABLE_PREFIX' => 'tbl_',

    // Адрес сайта без слэша в конце: из него строятся абсолютные ссылки OG и карты сайтов.
    'SITE_URL' => 'http://localhost:8080',
    'SITE_NAME' => 'Школа английского',

    // Почта. В пилоте письма не отправляются, но клиент настраивается сразу.
    'SMTP_HOST' => '',
    'SMTP_PORT' => 587,
    'SMTP_USER' => '',
    'SMTP_PASS' => '',
    'MAIL_FROM' => 'noreply@example.com',

    // Служебное: каталоги с дополнительными приложениями и темой (используют только тесты).
    // Ключ обязан быть в образце: config() применяет переменные CMS_* только к тем ключам,
    // которые уже есть в файле, поэтому отсутствующий ключ тест подставить не сможет.
    'EXTRA_APPS_DIR' => '',
    'EXTRA_THEME_DIR' => '',

    // Часовой пояс вывода. В базе всегда UTC.
    'TIMEZONE' => 'Europe/Moscow',
];
```

`public/index.php` (заглушка, которую задача 5 заменит маршрутизатором):

```php
<?php

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');
echo 'Ядро CMS собирается. Задача 5 подключит маршрутизатор.';
```

`public/.htaccess`:

```apache
# Всё, что не существующий файл или каталог, уходит в единственную точку входа.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

# Заголовки безопасности дублируются в bootstrap.php: не каждый хостинг даёт mod_headers.
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-Frame-Options "SAMEORIGIN"
</IfModule>
```

Здесь намеренно нет `php_flag engine off`: в корневом `.htaccess` он выключил бы PHP на всём сайте,
а точка входа `public/index.php` лежит в этом же каталоге. Отключение интерпретатора — свойство
каталога загрузок, и задача 18 добавит его в `public/uploads/.htaccess`.

`.gitignore`:

```
# Боевой конфиг с паролями в репозиторий не попадает — только образец.
/config.php

/vendor/
/node_modules/
/.php-cs-fixer.cache
/.phpunit.result.cache

# Замок установщика и временные файлы сборки.
/public/install.lock
/backup/

# Журнал ошибок: в нём тексты исключений и адреса — наружу в git он не идёт.
/core/storage/*.log

# Каталог загрузок пустой в репозитории: картинки кладут люди на сервере.
# Повторное включение обязано идти по одному имени — `git` не заходит в каталог,
# который уже исключён маской `*`.
/public/uploads/*
!/public/uploads/.gitkeep
!/public/uploads/.htaccess
```

Строка `!/public/uploads/.htaccess` нужна сразу здесь: файл защиты каталога загрузок добавляет
задача 18, и без исключения git его не покажет — на боевом сервере PHP в каталоге картинок остался бы
исполняемым.

`.gitattributes` закрепляет перенос строк LF:

```
# Перенос строк принудительно LF во всём репозитории.
# На машине разработчика включён core.autocrlf=true, и без этого правила git
# выложил бы в рабочее дерево CRLF: у scripts/gate.sh и scripts/db-reset сломался бы
# shebang, а public/.htaccess и файлы docker/ ушли бы на хостинг с лишними \r.
# Код CMS отдаётся через PHP и Apache, разностей по линиям в diff это не создаёт.
* text=auto eol=lf
```

Без этого файла клон на Windows-машине отдал бы скрипты с `\r` в конце строки, и первый же
`bash scripts/gate.sh` споткнулся бы о shebang, а не о код задачи.

Пустые каталоги, которые должны попасть в репозиторий, создаём сразу (git не хранит пустой каталог):

```bash
mkdir -p public/uploads public/assets core/storage
touch public/uploads/.gitkeep core/storage/.gitkeep
```

`public/assets/` появляется здесь, а не в задаче 4: PHPStan и `.gitignore` ссылаются на
`public/assets/*`, и без каталога первый же прогон ворот упал бы на «path not found», а не на коде.

- [ ] **Шаг 5: Ворота качества и сброс тестовой базы**

`scripts/gate.sh`:

```bash
#!/usr/bin/env bash
# Единственные ворота перед коммитом: синтаксис, статический анализ, стиль, тесты.
set -euo pipefail

run() {
  docker compose -f docker-compose.dev.yml run --rm -T php "$@"
}

echo '— синтаксис'
# Правка против первого варианта: статус `php -l` обязан доходить до ворот (поэтому `exit $status`,
# а не `|| true`), и каталогов `admin`, `theme`, `apps` на этой задаче ещё нет — `find` по их списку
# падал бы с «No such file or directory». Ищем от корня, вырезая чужое.
run bash -c 'find . -type d \( -name vendor -o -name node_modules -o -name .git \) -prune -o -name "*.php" -print0 \
  > /tmp/php-list.txt; xargs -0 -r -n1 php -l < /tmp/php-list.txt > /tmp/php-l.txt 2>&1; status=$?; \
  grep -v "No syntax errors detected" /tmp/php-l.txt || true; exit $status'

echo '— phpstan'
run vendor/bin/phpstan analyse --no-progress

echo '— стиль'
# Единственный разрешённый шум в воротах: фиксер предупреждает, что образ PHP 8.3 новее
# минимума проекта из composer.json (8.1). Снять предупреждение нечем: оно печатается
# безусловно, а флаг `PHP_CS_FIXER_IGNORE_ENV` устарел и лишь добавляет строку о своём
# устаревании. Понижать `config.platform.php` до 8.1 нельзя — PHPUnit 11 требует PHP >= 8.2,
# и разрешение зависимостей сломается. На код ворот это не влияет: предупреждение идёт в stderr.
run vendor/bin/php-cs-fixer fix --dry-run --diff

echo '— тесты'
run vendor/bin/phpunit "$@"
```

`scripts/db-reset`:

```bash
#!/usr/bin/env bash
# Пересоздаёт ТОЛЬКО cms_test. cms_dev и любая боевая база не трогаются.
set -euo pipefail

if [[ "${1:-}" == "--prod" || "${1:-}" == "--dev" ]]; then
  echo 'Отказ: скрипт сбрасывает только cms_test.' >&2
  exit 1
fi

docker compose -f docker-compose.dev.yml exec -T db \
  mysql -uroot -e "DROP DATABASE IF EXISTS cms_test; CREATE DATABASE cms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo 'cms_test пересоздана.'
```

`DROP DATABASE` здесь — не миграция: страж проверяет каталоги `sql/`, а не скрипты разработки.

- [ ] **Шаг 6: Пишем падающий тест**

`tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

// Тесты всегда работают с cms_test: имя базы выставляется до подключения ядра.
putenv('CMS_DB_NAME=cms_test');
$_ENV['CMS_DB_NAME'] = 'cms_test';

require dirname(__DIR__) . '/vendor/autoload.php';
```

`tests/unit/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testСредаОтвечаетНаВерсиюPhpНеНижеВосьмиОдин(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, '8.1.0', '>='), 'Нужен PHP 8.1 или выше');
    }

    public function testОбразецНастроекСодержитОбязательныеКлючи(): void
    {
        $config = require dirname(__DIR__, 2) . '/config.sample.php';

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'TABLE_PREFIX', 'SITE_URL', 'TIMEZONE'] as $key) {
            self::assertArrayHasKey($key, $config, "В образце нет ключа {$key}");
        }

        self::assertSame('tbl_', $config['TABLE_PREFIX']);
    }

    public function testРасширенияЯдраНаМесте(): void
    {
        // Проверка ровно того набора, без которого код ядра не работает: база, строки в UTF-8,
        // определение типа картинки по содержимому, GD для заготовки превью и TLS к почтовому серверу.
        // Список совпадает с docker/php-dev.Dockerfile: соберёшь образ без расширения — тест скажет.
        foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'openssl'] as $name) {
            self::assertTrue(extension_loaded($name), $name . ' не установлен');
        }
    }
}
```

- [ ] **Шаг 7: Красный прогон — тесты падают по причине**

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite unit`
Expected: FAIL — `Could not open input file: vendor/bin/phpunit`. Зависимостей ещё нет, и это правильное
падение: ворота умеют падать, а не молчать. Проверка ниже не «придуманный» красный шаг — она ловит реальную
половку образа, где расширений нет.

Затем: `docker compose -f docker-compose.dev.yml run --rm php composer install --no-interaction`
Эта же команда собирает образ `php-dev` (`build:` в compose), поэтому после неё расширения уже на месте.

- [ ] **Шаг 8: Собираем окружение и доводим тесты до зелёного**

Run: `docker compose -f docker-compose.dev.yml up -d --build`
Затем: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite unit`
Expected: 3/3 passing, вывод без предупреждений. Если `testРасширенияЯдраНаМесте` всё же падает — образ не
пересобран (`docker compose build --no-cache php`), а не «тест лишний».
Проверка базы: `docker compose -f docker-compose.dev.yml exec -T db mysql -uroot -e "SHOW DATABASES;"` — в списке
`cms_dev` и `cms_test`.

- [ ] **Шаг 9: Проверяем заглушку через HTTP**

Run: `curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/`
Expected: `200`.

- [ ] **Шаг 10: Коммит**

```bash
git add docker-compose.dev.yml docker/ composer.json composer.lock phpunit.xml phpstan.neon \
        .php-cs-fixer.dist.php .gitattributes config.sample.php core/ public/ scripts/ tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Окружение разработки CMS: контейнеры PHP, MySQL и mailpit, ворота качества"
```

---

### Задача 2: Загрузка ядра — настройки, идентификаторы, даты, текст, вывод

**Files:**
- Create: `core/config-loader.php`, `core/bootstrap.php`, `core/version.php`, `core/id.php`, `core/date.php`,
  `core/text.php`, `core/log.php`, `core/escape.php`
- Modify: `public/index.php`
- Test: `tests/unit/ConfigLoaderTest.php`, `tests/unit/IdTest.php`, `tests/unit/DateTest.php`,
  `tests/unit/TextTest.php`, `tests/guard/DateUsageTest.php`

**Interfaces:**
- Consumes: `config.sample.php` из задачи 1.
- Produces:
  - `config(): array` — все настройки; `configValue(string $key, mixed $default = null): mixed`;
    `CORE_VERSION = '1.1.0'` (константа в `core/version.php`);
  - `e(?string $value): string`; `newId(): string`;
  - `nowUtc(): DateTimeImmutable`, `utcStamp(DateTimeImmutable $d): string`, `fromDb(?string $raw): ?DateTimeImmutable`,
    `moscowIso(DateTimeImmutable $d): string`, `formatRuDate(?DateTimeImmutable $d): string`,
    `formatRuDateTime(?DateTimeImmutable $d): string`, `parseFormDateTime(string $raw): ?DateTimeImmutable`;
  - `plural(int $count, array $forms): string`, `excerpt(string $text, int $limit = 160): string`;
  - `logInfo(string $event, array $context = []): void`, `logWarn(...)`, `logError(...)`.

- [ ] **Шаг 1: Пишем падающие тесты идентификатора**

`tests/unit/IdTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/id.php';

final class IdTest extends TestCase
{
    public function testДлинаРовноДвадцатьШестьСимволов(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertSame(26, strlen(newId()), 'Идентификатор обязан быть 26 символов');
        }
    }

    public function testСостоитИзСимволовBase32БезОпасныхЗнаков(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', newId());
    }

    public function testИдентификаторыНеПовторяются(): void
    {
        $seen = [];
        for ($i = 0; $i < 5000; $i++) {
            $seen[newId()] = true;
        }

        self::assertCount(5000, $seen);
    }

    public function testПорядокСтрокСовпадаетПорядкомСоздания(): void
    {
        $first = newId();
        // Время в base32 занимает первые 10 символов: через 2 миллисекунды префикс обязан вырасти.
        usleep(2000);
        $second = newId();

        self::assertLessThan($second, $first, 'Строки должны сортироваться по времени создания');
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter IdTest`
Expected: FAIL — `Call to undefined function newId()`.

- [ ] **Шаг 2: Реализуем генератор**

`core/id.php`:

```php
<?php

declare(strict_types=1);

// Идентификатор — 26 символов Crockford base32: первые 10 — миллисекунды, дальше 16 случайных.
// Сортируемость по времени нужна списку материалов и журналу, а известность до вставки — форме,
// которая показывает ссылку на материал сразу после сохранения.
const ID_TIME_LENGTH = 10;
const ID_RANDOM_LENGTH = 16;
const ID_ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

function newId(): string
{
    return encodeTime((int) floor(microtime(true) * 1000)) . encodeRandom(random_bytes(ID_RANDOM_LENGTH));
}

function encodeTime(int $milliseconds): string
{
    $out = '';
    for ($i = 0; $i < ID_TIME_LENGTH; $i++) {
        $out = ID_ALPHABET[$milliseconds % 32] . $out;
        $milliseconds = intdiv($milliseconds, 32);
    }

    return $out;
}

function encodeRandom(string $bytes): string
{
    $out = '';
    for ($i = 0; $i < ID_RANDOM_LENGTH; $i++) {
        $out .= ID_ALPHABET[ord($bytes[$i]) % 32];
    }

    return $out;
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter IdTest`
Expected: PASS (4/4).

- [ ] **Шаг 3: Пишем падающие тесты дат**

`tests/unit/DateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/date.php';

final class DateTest extends TestCase
{
    public function testВБазуПишемUtc(): void
    {
        $moscow = new DateTimeImmutable('2026-10-06 09:00:00', new DateTimeZone('Europe/Moscow'));

        self::assertSame('2026-10-06 06:00:00', utcStamp($moscow));
    }

    public function testИзБазыЧитаемUtc(): void
    {
        $date = fromDb('2026-10-06 06:00:00');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame('2026-10-06T09:00:00+03:00', moscowIso($date));
    }

    public function testПустоеЗначениеИзБазыОстаётсяПустым(): void
    {
        self::assertNull(fromDb(null));
        self::assertNull(fromDb(''));
    }

    public function testПолеФормыРазбираетсяВМосковскихЧасах(): void
    {
        $date = parseFormDateTime('2026-10-06T09:00');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-10-06 06:00:00', utcStamp($date));
    }

    public function testНеверныйФорматПоляФормыДаётПустоАНеИсключение(): void
    {
        self::assertNull(parseFormDateTime('вчера в девять'));
        self::assertNull(parseFormDateTime(''));
        self::assertNull(parseFormDateTime('2026-13-45T99:99'));
    }

    public function testРусскаяДатаЧитаетсяВРодительномПадежеМесяца(): void
    {
        self::assertSame('6 октября 2026', formatRuDate(fromDb('2026-10-06 06:00:00')));
        self::assertSame('1 января 2026', formatRuDate(fromDb('2026-01-01 00:00:00')));
        self::assertSame('—', formatRuDate(null));
    }

    public function testРусскаяДатаСоВременем(): void
    {
        self::assertSame('6 октября 2026, 09:00', formatRuDateTime(fromDb('2026-10-06 06:00:00')));
        self::assertSame('—', formatRuDateTime(null));
    }

    public function testМосковскийПереносНеУезжаетНаПредыдущийДень(): void
    {
        // 23:40 по Москве — 20:40 UTC того же дня: формат обязан показать 23:40, а не вчерашнее число.
        self::assertSame('2026-10-06T23:40:00+03:00', moscowIso(fromDb('2026-10-06 20:40:00')));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter DateTest`
Expected: FAIL — функции не определены.

- [ ] **Шаг 4: Реализуем даты**

`core/date.php`:

```php
<?php

declare(strict_types=1);

// Единственное место, где UTC встречается с Москвой. В базе все моменты лежат в UTC, а читатель
// видит московское время: правило «9:00 — значит в девять утра по Москве» работает только так.
// Вне этого файла date(), ->format() и new DateTime запрещены стражем tests/guard/DateUsageTest.php.
const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';
const MOSCOW_OFFSET_SECONDS = 3 * 60 * 60;

const MONTHS_RU_GENITIVE = [
    1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

function moscowZone(): DateTimeZone
{
    return new DateTimeZone('Europe/Moscow');
}

function nowUtc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function utcStamp(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format(DB_DATETIME_FORMAT);
}

function fromDb(?string $raw): ?DateTimeImmutable
{
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat(DB_DATETIME_FORMAT, $value, new DateTimeZone('UTC'));

    return $parsed instanceof DateTimeImmutable ? $parsed : null;
}

// Москва без перехода на летнее время: +03:00 круглый год, поэтому ISO-строку собираем вручную —
// так же, как это делал moscowIso() в Next.js-портале.
function moscowIso(DateTimeImmutable $date): string
{
    return $date->setTimezone(moscowZone())->format('Y-m-d\TH:i:sP');
}

function formatRuDate(?DateTimeImmutable $date): string
{
    if ($date === null) {
        return '—';
    }

    $local = $date->setTimezone(moscowZone());

    return (int) $local->format('j') . ' ' . MONTHS_RU_GENITIVE[(int) $local->format('n')] . ' ' . $local->format('Y');
}

function formatRuDateTime(?DateTimeImmutable $date): string
{
    if ($date === null) {
        return '—';
    }

    return formatRuDate($date) . ', ' . $date->setTimezone(moscowZone())->format('H:i');
}

// Поле datetime-local приносит «2026-10-06T09:00» без пояса: время понимается как московское —
// ровно то, о чём договорён контент-менеджер, назначая публикацию «на девять утра».
function parseFormDateTime(string $raw): ?DateTimeImmutable
{
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, moscowZone())
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value, moscowZone());

    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    // createFromFormat не проверяет календарность: 2026-13-45 превратилось бы в 2027-01-45.
    if ($parsed->format('Y-m-d\TH:i') !== substr($value, 0, 16)) {
        return null;
    }

    return $parsed;
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter DateTest`
Expected: PASS (8/8). Если `2026-13-45T99:99` проходит, сравнение формата в `parseFormDateTime` не сработало —
проверьте, что сравниваете именно первые 16 символов.

- [ ] **Шаг 5: Текст, вывод, журнал и загрузка настроек**

`core/text.php` — порт `english-portal/src/core/text.ts` (только текстовые функции):

```php
<?php

declare(strict_types=1);

// Русское согласование: 1 материал, 2 материала, 5 материалов. Формы — «один / несколько / много».
function plural(int $count, array $forms): string
{
    $mod10 = $count % 10;
    $mod100 = $count % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return (string) $forms[0];
    }
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
        return (string) $forms[1];
    }

    return (string) $forms[2];
}

// Короткий текст для мест, где описание не помещается: лид карточки, описание для поисковика.
function excerpt(string $text, int $limit = 160): string
{
    $flat = trim((string) preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($flat) <= $limit) {
        return $flat;
    }

    return rtrim(mb_substr($flat, 0, $limit)) . '…';
}
```

`core/escape.php`:

```php
<?php

declare(strict_types=1);

// Весь вывод проходит через e(): шаблон, который напечатал переменную напрямую, — находка стража.
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`core/log.php`:

```php
<?php

declare(strict_types=1);

// Журнал лежит вне документ-корня: core/storage/errors.log. Наржу не отдаётся ничего.
function logPath(): string
{
    return dirname(__DIR__) . '/core/storage/errors.log';
}

function logWrite(string $level, string $event, array $context = []): void
{
    $line = json_encode(
        [
            'at' => nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'event' => $event,
            'context' => $context,
        ],
        JSON_UNESCAPED_UNICODE
    );

    $dir = dirname(logPath());
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents(logPath(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function logInfo(string $event, array $context = []): void
{
    logWrite('info', $event, $context);
}

function logWarn(string $event, array $context = []): void
{
    logWrite('warn', $event, $context);
}

function logError(string $event, array $context = []): void
{
    logWrite('error', $event, $context);
}
```

`core/version.php`:

```php
<?php

declare(strict_types=1);

// Версия ядра. Приложение устанавливается только если его requiresCore не выше этого числа.
const CORE_VERSION = '1.1.0';
```

`core/config-loader.php`:

```php
<?php

declare(strict_types=1);

// Настройки читаются один раз за запрос: на shared-хостинге нет долгоживущего процесса,
// поэтому кеш в памяти бессмыслен, а повторное чтение файла — лишний диск.
const CONFIG_REQUIRED_KEYS = [
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'TABLE_PREFIX', 'SITE_URL', 'SITE_NAME', 'TIMEZONE',
];

const CONFIG_ENV_PREFIX = 'CMS_';

function configPath(): string
{
    $override = getenv('CMS_CONFIG_FILE');

    return is_string($override) && $override !== '' ? $override : dirname(__DIR__) . '/config.php';
}

function config(): array
{
    static $loaded = null;

    if ($loaded !== null) {
        return $loaded;
    }

    $path = configPath();
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) {
        fail('config.php обязан возвращать массив настроек.');
    }

    foreach (CONFIG_REQUIRED_KEYS as $key) {
        if (!array_key_exists($key, $config) || $config[$key] === '') {
            $fromEnv = getenv(CONFIG_ENV_PREFIX . $key);
            if ($fromEnv === false || $fromEnv === '') {
                fail('В настройках нет обязательного ключа ' . $key . '.');
            }
            $config[$key] = $fromEnv;
        }
    }

    // Окружение перекрывает файл: так тесты подставляют cms_test, не трогая config.php.
    foreach (array_keys($config) as $key) {
        $fromEnv = getenv(CONFIG_ENV_PREFIX . $key);
        if ($fromEnv !== false) {
            $config[$key] = $fromEnv;
        }
    }

    $loaded = $config;

    return $loaded;
}

function configValue(string $key, mixed $default = null): mixed
{
    $config = config();

    return array_key_exists($key, $config) && $config[$key] !== '' ? $config[$key] : $default;
}

function fail(string $message): never
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не удалось запустить сайт: ' . $message;
    exit(1);
}
```

Настройки читаются один раз за запрос и кешируются в static-переменной; тесты выставляют `CMS_CONFIG_FILE` и
`CMS_DB_NAME` в `tests/bootstrap.php` — до первого вызова `config()`, поэтому сброс кеша не нужен.

- [ ] **Шаг 6: Тесты настроек, текста и загрузки**

`tests/unit/ConfigLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/date.php';
require_once dirname(__DIR__, 2) . '/core/log.php';
require_once dirname(__DIR__, 2) . '/core/config-loader.php';

final class ConfigLoaderTest extends TestCase
{
    public function testНастройкиБерутсяИзФайлаОбразца(): void
    {
        putenv('CMS_CONFIG_FILE=' . dirname(__DIR__, 2) . '/config.sample.php');

        self::assertSame('tbl_', configValue('TABLE_PREFIX'));
        self::assertSame('Europe/Moscow', configValue('TIMEZONE'));
    }

    public function testОкружениеПерекрываетФайл(): void
    {
        putenv('CMS_CONFIG_FILE=' . dirname(__DIR__, 2) . '/config.sample.php');
        putenv('CMS_DB_NAME=cms_test');

        self::assertSame('cms_test', configValue('DB_NAME'));
    }

    public function testОтсутствующийКлючОтдаётЗначениеПоУмолчанию(): void
    {
        self::assertSame('', (string) configValue('SMTP_HOST', ''));
        self::assertNull(configValue('такого_ключа_нет'));
    }
}
```

`tests/unit/TextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/text.php';
require_once dirname(__DIR__, 2) . '/core/escape.php';

final class TextTest extends TestCase
{
    public function testСклонениеЧислительного(): void
    {
        self::assertSame('материал', plural(1, ['материал', 'материала', 'материалов']));
        self::assertSame('материала', plural(3, ['материал', 'материала', 'материалов']));
        self::assertSame('материалов', plural(5, ['материал', 'материала', 'материалов']));
        self::assertSame('материалов', plural(11, ['материал', 'материала', 'материалов']));
        self::assertSame('материал', plural(21, ['материал', 'материала', 'материалов']));
        self::assertSame('материале', plural(1, ['материале', 'материалах', 'материалах']));
    }

    public function testВыдержкаОбрезаетсяПоСловамСМноготочием(): void
    {
        $text = str_repeat('слово ', 60);

        self::assertSame(161, mb_strlen(excerpt($text, 160)));
        self::assertStringEndsWith('…', excerpt($text, 160));
        self::assertSame('короткий текст', excerpt('  короткий   текст ', 160));
    }

    public function testВыводЭкранируетКавычкиИУгловыеСкобки(): void
    {
        self::assertSame('&lt;b&gt;&quot;кот&quot;&lt;/b&gt;', e('<b>"кот"</b>'));
        self::assertSame('', e(null));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite unit`
Expected: PASS — все наборы зелёные.

- [ ] **Шаг 7: Страж дат**

`tests/guard/DateUsageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Правило «в базе UTC, Москва только на краях» живёт, пока никто не может отформатировать дату
// в обход core/date.php: иначе половина экранов показала бы время на три часа раньше.
final class DateUsageTest extends TestCase
{
    private const ALLOWED = ['core/date.php'];

    private const FORBIDDEN = [
        '/\bdate\s*\(/' => 'date() — форматирование в обход core/date.php',
        '/\bstrtotime\s*\(/' => 'strtotime() — разбор даты в обход core/date.php',
        '/->\s*format\s*\(/' => '->format() — форматирование в обход core/date.php',
        '/new\s+DateTime(Immutable)?\s*\(/' => 'new DateTime — создание момента в обход core/date.php',
        '/\btime\s*\(\s*\)/' => 'time() — момент в обход core/date.php',
    ];

    public function testНиктоКромеМодуляДатНеФорматируетВремя(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $file) {
            $relative = $this->relative($file);
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $code = $this->withoutComments((string) file_get_contents($file));
            foreach (self::FORBIDDEN as $pattern => $reason) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $relative . ': ' . $reason;
                }
            }
        }

        self::assertSame([], $violations, 'Найдены места, где дата обрабатывается в обход core/date.php');
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['core', 'admin', 'theme', 'apps', 'public'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

        return $out;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }

    private function withoutComments(string $code): string
    {
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

        return (string) preg_replace('#//[^\n]*#', '', $code);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite guard`
Expected: PASS — `core/log.php` использует `->format()`; если страж на него указывает, добавьте `core/log.php`
в `ALLOWED` **только** для строки журнала и уберите `->format()` из `logWrite()`, вызвав вместо него
`utcStamp(nowUtc())`. Правильный вариант — второй: список исключений остаётся из одного файла.

Исправление `core/log.php` — тело функции заменяется целиком, сигнатура та же:

```php
function logWrite(string $level, string $event, array $context = []): void
{
    $line = json_encode(
        [
            'at' => utcStamp(nowUtc()),
            'level' => $level,
            'event' => $event,
            'context' => $context,
        ],
        JSON_UNESCAPED_UNICODE
    );
    // …остальное без изменений
}
```

- [ ] **Шаг 8: Bootstrap и новая точка входа**

`core/bootstrap.php`:

```php
<?php

declare(strict_types=1);

// Порядок подключения важен: date.php нужен log.php, config-loader.php — всем остальным.
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/escape.php';
require_once __DIR__ . '/date.php';
require_once __DIR__ . '/config-loader.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/text.php';
require_once __DIR__ . '/id.php';

// Классы ядра (Mailer, SmtpSocket, MigrationPlan) подгружаются по имени.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Cms\\Core\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Cms\\Core\\'))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

date_default_timezone_set('UTC');

// Ошибки пишем в журнал, а наружу отдаём человеческий текст без дампа стека.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (($severity & error_reporting()) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $error): void {
    logError('uncaught', ['message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    require dirname(__DIR__) . '/core/templates/error.php';
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        logError('fatal', $error);
    }
});

function securityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}
```

Правило на весь план: `core/bootstrap.php` дополняется `require_once` ровно в тот шаг, где появляется сам
файл. Семь строк выше — все файлы этой задачи; `core/hooks.php` и `core/template.php` добавит задача 4,
`core/request.php`, `core/response.php` и `core/router.php` — задача 5. Список «на вырост» означал бы
фатальную ошибку на первом же запросе: `require_once` отсутствующего файла не прощается.

`core/templates/error.php`:

```php
<?php

declare(strict_types=1);

// Экран печатает только статичную разметку: переменных здесь нет, поэтому стражу экранирования
// выводить нечего, и пометка безопасного блока этому файлу не нужна.
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Что-то сломалось</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-[#fdf6ec] text-[#2b2118]">
<main class="mx-auto max-w-xl px-4 py-20 text-center">
    <h1 class="text-2xl font-bold">Что-то сломалось</h1>
    <p class="mt-4">Мы уже записали ошибку в журнал и разберёмся. Попробуйте открыть страницу ещё раз.</p>
    <p class="mt-6"><a class="underline" href="/">На главную</a></p>
</main>
</body>
</html>
```

`public/index.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

securityHeaders();

// Маршрутизатор подключит задача 5; до неё точка входа показывает заглушку темы.
echo 'Ядро загружено. Маршрутизатор появится в задаче 5.';
```

- [ ] **Шаг 9: Тест загрузки ядра и коммит**

Добавьте в `tests/unit/ConfigLoaderTest.php`:

```php
    public function testBootstrapПодключаетсяИДаётБазовыеФункции(): void
    {
        self::assertTrue(function_exists('e'));
        self::assertTrue(function_exists('newId'));
        self::assertTrue(function_exists('nowUtc'));
        self::assertTrue(defined('CORE_VERSION'));
        self::assertSame('1.1.0', CORE_VERSION);
    }
```

`tests/bootstrap.php` дополняется подключением ядра (вместо `require vendor/autoload.php` напрямую):

```php
<?php

declare(strict_types=1);

putenv('CMS_DB_NAME=cms_test');
$_ENV['CMS_DB_NAME'] = 'cms_test';
putenv('CMS_CONFIG_FILE=' . dirname(__DIR__) . '/config.sample.php');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/core/bootstrap.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite unit`
Expected: PASS.
Run: `bash scripts/gate.sh`
Expected: зелёные `php -l`, PHPStan, стиль и тесты (стражи и HTTP-наборы пока пустые — PHPUnit это принимает).

Коммит:

```bash
git add core/ public/ tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Загрузка ядра: настройки, идентификаторы, даты в UTC, русский текст и страж дат"
```

---
