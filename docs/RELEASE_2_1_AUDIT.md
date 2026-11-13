# RELEASE 2.1 — аудит и план внедрения

Дата: 13 ноября 2026. Состояние дерева: `main`, коммит `6df8e48` (RELEASE 2.1).
Полный набор: 1831 тест, 10 510 утверждений, 1 пропуск на PHP 8.3.36; 1831 тест,
10 503 утверждения, 4 пропуска на PHP 8.4.27 и 8.5.12. Все три прогона зелёные; пропуски —
проверки возможностей окружения (SMTP-заглушка и расширения PHP), а не отключённые проверки
продукта.

## 1. Что уже есть в ядре

| Слой | Фактическое состояние | Точка расширения |
|---|---|---|
| Маршруты | `core/router.php`, статические пути раньше параметрических, гейт `private`/`permission` | `addRoute()` |
| Страницы | `tbl_page`, `tbl_page_section`, `tbl_page_old_slug`; 26 видов секций; JSON-поля `buttons`, `items`, `settings` | `core/pages.php` |
| Публичный рендер | `/{slug}`, главная из секций с `page_id = ''`, предпросмотр черновика редактором | `core/routes.php`, `theme/page.php` |
| Загрузка картинок | проверка по содержимому, серверное имя, `/uploads/images/`, запрет исполнения через `.htaccess` | `core/upload.php` |
| Пользователи | `tbl_user`, роли `admin`/`editor`, Argon2id, блокировка, `session_version` | `core/auth.php` |
| Сессии и CSRF | cookie-only сессия, `csrfCheck()` первым в POST, стражи исходников | `core/session.php`, `core/csrf.php` |
| Миграции | журнал `tbl_app_migration`, контрольные суммы, запрет удаления в SQL-файлах | `core/migrations.php` |
| Настройки | `tbl_option`, кеш на запрос | `core/options.php` |
| SEO | `/sitemap.xml` (`urlset`), `/robots.txt`, абсолютные адреса из `SITE_URL`, фильтр `sitemap_urls` | `core/seo.php` |
| Заявки | `tbl_lead`, лимиты, подтверждение и ответ письмом, согласие уже проверяется в `leadValidationError()` | `core/leads.php` |
| Почта | `Cms\Core\Mailer`, SMTP через `SmtpSocket`, подмена транспорта замыканием | `core/Mailer.php` |
| Админка | вкладки ядра и приложений, `adminShell()`, flash-причины отказов | `admin/layout.php` |
| Приложения | Новости, магазин, объявления, курсы, SEO; собственные SQL и маршруты | `apps/*` |

### Ограничения, которые нельзя нарушать

1. `tests/guard/SqlSafetyTest.php` запрещает в SQL-файлах `DROP`, `DELETE`, `TRUNCATE`,
   `RENAME TABLE` и `DROP COLUMN`. Новые миграции только создают таблицы и индексы.
2. `tests/guard/PerformanceTest.php` требует `LIMIT` у однострочных `all('SELECT …')` и
   запрещает `SELECT *` в продакшен-коде.
3. `tests/guard/CsrfCoverageTest.php` требует `csrfCheck()` до первого обращения к базе в
   каждом POST-обработчике; формы обязаны нести `csrfField()` внутри себя.
4. `tests/guard/NoticeKeyTest.php` требует, чтобы каждый ключ `CORE_NOTICE_TEXT` передавался
   литералом через `redirectWithNotice()`/`noticeText()`/`finishAppAction()`.
5. `tests/guard/DateUsageTest.php` запрещает `->format()` вне `core/date.php`.
6. `tests/http/NoIndexNoStoreSweepTest.php` перечисляет **каждый GET-маршрут** в плане обхода
   и закрепляет точное число вариантов. Новые GET-маршруты обязаны попасть в план.
7. `tests/guard/OutputEscapingTest.php` требует `e()` или явную пометку `/* @safe html */`.
8. `tbl_page.slug` уникален; ключ секции уникален в пределах страницы (`page_id, sec_key`).

## 2. Чего в проекте нет

- Корзины и восстановления: `deletePage()`/`sectionDelete()` удаляют строки безвозвратно.
- Дублирования страниц и секций, буфера обмена.
- Предпросмотра по одноразовой/временной ссылке: черновик виден только вошедшему редактору.
- Сброса пароля: есть только вход и смена пароля администратором при установке.
- Журнала действий (Audit Log).
- Медиатеки: файл знает только путь, ALT живёт в секции, повторный выбор файла вручную.
- RSS/Atom.
- Sitemap Index: `/sitemap.xml` — единственный `urlset`.
- Сквозного поиска по разделам.
- Массовых операций в списках.
- Журнала согласий: согласие проверяется, но факт согласия нигде не хранится.

## 3. Решение RELEASE 2.1

Все новые сущности — **дополнительные таблицы**, существующие таблицы и формат JSON-секций
не меняются. Это позволяет откатить релиз удалением новых маршрутов и файлов без потери
контента; таблицы можно оставить (они не влияют на работу старых модулей).

### 3.1. Схема (`sql/0003_release21.sql`)

| Таблица | Назначение | Ключевые поля |
|---|---|---|
| `tbl_audit_log` | журнал действий | `user_id`, `user_email`, `action`, `entity`, `entity_id`, `summary`, `details`, `ip`, `created_at` |
| `tbl_media` | медиатека | `path` (уникальный), `title`, `alt`, `mime`, `bytes`, `width`, `height`, `uploaded_by` |
| `tbl_trash` | корзина страниц и секций | `kind`, `entity_id`, `parent_id`, `title`, `payload` (JSON-снимок), `deleted_by`, `deleted_at` |
| `tbl_clipboard` | буфер обмена секций на пользователя | `user_id` (PK), `kind`, `label`, `payload`, `updated_at` |
| `tbl_preview_token` | ссылки предпросмотра | `page_id`, `token_hash` (SHA-256, уникальный), `expires_at`, `revoked_at`, `created_by` |
| `tbl_password_reset` | одноразовые токены сброса | `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at` |
| `tbl_password_reset_attempt` | лимит запросов сброса | `ip`, `email`, `attempted_at` |
| `tbl_consent_log` | журнал согласий | `subject`, `kind`, `text_version`, `source`, `ip`, `user_agent`, `created_at` |

Токены хранятся только хешами. Сырой токен показывается один раз: в ссылке письма либо в
редиректе после создания ссылки предпросмотра.

### 3.2. Модули

| Файл | Ответственность |
|---|---|
| `core/audit.php` | запись и чтение журнала; запись не ломает основное действие |
| `core/trash.php` | перемещение страницы/секции в корзину, список, восстановление, окончательное удаление |
| `core/duplicate.php` | дублирование страницы/секции, persistent-буфер обмена секций |
| `core/media.php` | регистрация файла, ALT/название, поиск, контроль использования, безопасное удаление |
| `core/preview.php` | выпуск/отзыв ссылки, проверка хеша и срока |
| `core/password-reset.php` | запрос, проверка, смена пароля, отзыв сессий |
| `core/consent.php` | запись согласия и выборка журнала |
| `core/search.php` | сквозной поиск по доступным таблицам с `LIKE … ESCAPE` |
| `core/feeds.php` | RSS 2.0 и Atom, автоподстановка ссылок в `<head>` |
| `core/seo.php` | дополнение: Sitemap Index и части карты сайта |

### 3.3. Маршруты

Публичные:

- `GET/POST /forgot-password` — запрос сброса.
- `GET/POST /reset-password/{token}` — форма и смена пароля.
- `GET /preview/{token}` — предпросмотр по временной ссылке (`sessionless`, `no-store`).
- `GET /feed.xml`, `GET /feed.atom` — RSS 2.0 и Atom.
- `GET /sitemap-index.xml`, `GET /sitemap/{part}` — индекс и части карты сайта.

Админские:

- `GET/POST /admin/trash` — корзина и действия.
- `GET/POST /admin/media` — медиатека.
- `GET /admin/audit` — журнал (только администратор).
- `GET /admin/consents`, `GET /admin/consents/export` — журнал согласий (только администратор).
- `GET /admin/search` — сквозной поиск (редактор и администратор), `format=json` — JSON;
  раздел «Заявки» внутри выдачи дополнительно требует права управления.
- `POST /admin/pages` и `POST /admin/pages/{id}` — новые `op`: `bulk-*`, `duplicate-page`,
  `duplicate-section`, `copy-section`, `paste-section`, `preview-page`, `preview-revoke`.

### 3.4. Права и безопасность

- Корзина, медиатека и конструктор — право `login` (администратор и редактор).
- Журнал действий и журнал согласий — право `manage` (только администратор). Поиск открыт
  редактору, но заявки с контактами людей показывает только при праве `manage`.
- CSRF проверяется первым во всех POST.
- Предпросмотр: токен ≥ 32 случайных байт, в базе SHA-256, срок 7 дней, отзыв,
  `X-Robots-Tag: noindex, nofollow` и `Cache-Control: no-store`.
- Сброс пароля: ответ всегда одинаковый, лимит 5 запросов на IP за 60 минут, ссылка действует
  60 минут, одноразовость, `session_version + 1` после смены.
- Медиатека: только jpg/png/webp по содержимому, имя генерирует сервер, удаление запрещено,
  пока файл используется в контенте, буфере обмена или снимке корзины.
- Согласия: записываются после успешной проверки формы заявки, вместе с источником, IP,
  User-Agent и версией политики.

### 3.5. Тесты

- `tests/db/Release21Test.php` — схема, аудит, корзина, дублирование, буфер обмена.
- `tests/db/MediaLibraryTest.php` — ALT, использование, запрет удаления, безопасное удаление.
- `tests/db/PasswordResetTest.php` — хеш токена, срок, одноразовость, смена пароля.
- `tests/db/ConsentTest.php` — журнал согласий из заявки.
- `tests/db/GlobalSearchTest.php` — поиск, экранирование `%`/`_`, отсутствующие таблицы.
- `tests/db/FeedItemsTest.php` — запланированная новость не попадает в ленты до срока.
- `tests/unit/FeedsTest.php` — RSS 2.0/Atom, экранирование, порядок.
- `tests/unit/SitemapIndexTest.php` — индекс и части.
- `tests/http/Release21HttpTest.php` — UI, права, CSRF, предпросмотр, сброс, поиск, корзина,
  медиатека, массовые операции.
- `tests/http/NoIndexNoStoreSweepTest.php` — все новые GET-маршруты и точные счётчики.
- Полный прогон: 1831 тест, 10 510 утверждений, 1 пропуск на PHP 8.3.36; 1831 тест,
  10 503 утверждения, 4 пропуска на PHP 8.4.27 и 8.5.12. Все прогоны зелёные.

### 3.6. Обновление существующей установки и откат

Порядок обновления:

1. Снять резервную копию базы: `CMS_BACKUP_MYSQLDUMP=/usr/bin/mysqldump bash scripts/backup.sh`
   (или `mysqldump --single-transaction` вручную). Без копии обновление не начинать.
2. Выложить файлы сборки, не перезаписывая `config.php` и `public/uploads/`.
3. Открыть `/admin/apps` → «Обновить ядро». Применится `sql/0003_release21.sql`; файл только
   создаёт новые таблицы (`CREATE TABLE IF NOT EXISTS`) и не меняет страницы, секции, меню,
   пользователей и таблицы приложений.
4. Проверить `/feed.xml`, `/feed.atom`, `/sitemap-index.xml`, `/sitemap/1`, `/admin/trash`,
   `/admin/media`, `/admin/audit` (администратор), `/admin/search`, сброс пароля на тестовой
   учётной записи и ссылку предпросмотра черновика.
5. Убедиться, что прежние адреса работают: `/sitemap.xml` остаётся `urlset`, старые страницы и
   разделы открываются без изменений.

Проверено на локальном стенде 13 ноября 2026 (PHP 8.3.36): `/feed.xml`, `/feed.atom`,
`/sitemap-index.xml`, `/sitemap/1`, `/sitemap.xml`, `/robots.txt` отвечают 200; RSS содержит 21
запись (11 страниц и 10 новостей) с экранированием, `guid` и датами; индекс ссылается на
`/sitemap/1`; `/sitemap.xml` остался `urlset` из 50 адресов; `/forgot-password` — 200; админские
экраны анонимом — 302 на `/login`; несуществующий токен предпросмотра — 404 с
`X-Robots-Tag: noindex, nofollow` и `Cache-Control: no-store`.

Откат:

1. Вернуть файлы предыдущего коммита (`git worktree add ../old <commit>` и повторная выкладка
   `CMS_DEPLOY_PATH=… bash ../old/scripts/deploy.sh`) — маршруты, шаблоны и модули 2.1 исчезнут.
2. Новые таблицы можно оставить: старый код их не читает, данные контента в них не входят.
   Если их нужно удалить, сделать это вручную после резервной копии: `DROP TABLE` для
   `tbl_audit_log`, `tbl_media`, `tbl_trash`, `tbl_clipboard`, `tbl_preview_token`,
   `tbl_password_reset`, `tbl_password_reset_attempt`, `tbl_consent_log`.
3. Токены предпросмотра и сброса истекают сами; после отката файлов их маршруты перестают
   существовать. Пароли, изменённые через сброс, остаются изменёнными — это ожидаемо.
