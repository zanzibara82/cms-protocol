# Заявки с публичных страниц и письма по ним — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** посетитель оставляет заявку на публичной странице, она пишется в базу, на почту уходит
подтверждение приёма, администратор видит заявки в `/admin/leads` и отвечает письмом из того же
раздела.

**Architecture:** правила и запись — `core/leads.php` (константы, валидация, ловушка, лимиты по
таблице попыток, чтение списка, статусы, ответ письмом); письма — `core/lead-mail.php` поверх
готового `Cms\Core\Mailer`; публичный контур — вид `lead` конструктора плюс маршрут `POST /lead`
с PRG-редиректом; админка — `GET/POST /admin/leads` с одним обработчиком `op`.

**Tech Stack:** PHP 8.3, MySQL 8 (InnoDB, utf8mb4_unicode_ci), PHPUnit (наборы `unit` / `db` /
`http` / `guard`), Tailwind v4 (существующие утилиты), стражи `tests/guard/`.

**Спецификация:** `docs/superpowers/specs/2026-11-10-leads-mailing-design.md` — предмет §1, схема
§2, правила §3, лимиты §4, форма и маршрут §5, письма §6, админка §7, уведомления §8, вердикты
§9, тесты §10. При расхождении действует спецификация.

## Глобальные ограничения

- Только PHP 8.3 и MySQL: ни одной runtime-зависимости, ни одного нового пакета.
- Все строки, комментарии и имена тестов — по-русски. Идентификаторы — по-английски.
- Разметка печатается через `e()`; сырой HTML — только переменным с пометкой `/* @safe html */`
  (страж `OutputEscapingTest`).
- Каждый контроль несёт класс `min-h-[44px]` литералом в атрибуте `class` (страж `TapTargetTest`);
  у ссылки дополнительно блочный вывод.
- Новых CSS-правил нет: разметка собирается существующими утилитами Tailwind; после правок
  разметки артефакт стилей пересобирается (`npm run build`).
- SQL пишется параметрами; имена таблиц — через `t()`; релиз идемпотентен.
- Пути POST-обработчиков — строковые литералы в `core/routes.php` (страж `CsrfCoverageTest`);
  `csrfCheck()` — первый вызов обработчика.
- Время — только `nowUtc()`/`utcStamp()`; ключи уведомлений — в словаре ядра (`NoticeKeyTest`).
- Форма работает без JavaScript (правило §6 итоговой спецификации).
- Коммит после зелёного полного гейта; пуш — только по явной команде человека.

## Карта файлов

Создаются:

| Файл | Ответственность |
| --- | --- |
| `sql/0002_leads.sql` | релиз: `tbl_lead`, `tbl_lead_attempt` |
| `core/leads.php` | константы, валидация, ловушка, лимиты, запись, чтение, статусы, ответ |
| `core/lead-mail.php` | темы, тексты и разметка писем, черновик ответа |
| `theme/sections/lead.php` | вид «Форма заявки»: обещания и форма без JavaScript |
| `core/templates/admin/leads.php` | экран «Заявки»: фильтры, поиск, список, действия |
| `tests/unit/LeadsTest.php` | чистые правила и письма |
| `tests/db/LeadsSchemaTest.php` | запись, список, статусы, лимиты, ответ на живой базе |
| `tests/http/LeadFormTest.php` | публичный `POST /lead` и вид секции |
| `tests/http/AdminLeadsTest.php` | права, список, действия, отказ ответа на телефон |

Правятся:

| Файл | Что меняется |
| --- | --- |
| `core/bootstrap.php` | подключение `leads.php` и `lead-mail.php` |
| `core/pages.php` | вид `lead` в перечнях и полях формы |
| `core/routes.php` | маршруты `POST /lead`, `GET/POST /admin/leads` |
| `core/notice.php` | четыре ключа `lead_*` |
| `admin/layout.php` | пункт «Заявки» в навигации админки |
| `tests/http/Kernel.php` | снятие `CMS_SMTP_*` из окружения подпроцесса |

## Задача 1: схема и правила

- [x] `sql/0002_leads.sql`: `tbl_lead` и `tbl_lead_attempt`, только дополняющая миграция.
- [x] `core/leads.php`: пределы полей, `LEAD_CONTACT_RE`, `LEAD_SOURCE_RE`, `leadIsEmail`,
      `leadContactHref`, `leadIsJunk`, `leadValidationError`.
- [x] `tests/unit/LeadsTest.php` — грани регулярок, ловушка, ссылка на контакт, цель редиректа.
- [x] `tests/db/LeadsSchemaTest.php` — запись, `NULL` вместо пустого сообщения, сортировка.

## Задача 2: письма

- [x] `core/lead-mail.php`: темы, `leadEmailText()` с экранированием, `leadConfirmHtml`,
      `leadReplyHtml`, `leadReplyDraft`, общий подвал.
- [x] Тесты экранирования имени и переводов строк в unit-наборе.

## Задача 3: лимиты и запись

- [x] `leadRateTooMany()` поверх `tbl_lead_attempt` (окно по `scope` и `created_at`).
- [x] `leadCreate()`: `status = NEW`, `source` через `leadSourceOrEmpty`, пустое сообщение — `NULL`.
- [x] `leadConfirmSend()`: провал письма в журнал, посетителю не показывается.

## Задача 4: публичный контур

- [x] Вид `lead` в `PAGES_KINDS`/`PAGES_KIND_NAMES`/`PAGES_KIND_FIELDS` — «Форма заявки».
- [x] `theme/sections/lead.php`: обещания слева, форма справа, скрытые `source` и `company`,
      чекбокс согласия, плашки `lead_sent`/`lead_failed`.
- [x] Маршрут `POST /lead`: CSRF, лимит по IP, валидация, ловушка, запись, письмо, PRG.
- [x] `tests/http/LeadFormTest.php` — приём, ловушка, лимит, кривой контакт, чужой токен.

## Задача 5: админка

- [x] `leadsPage()`: фильтр по статусу, поиск по имени и контакту через `escapeLike` + `ESCAPE ?`,
      25 на страницу, новые сверху.
- [x] `leadSetStatus()`, `leadDelete()`, `leadReplySend()` с лимитом 3/10 минут.
- [x] `core/templates/admin/leads.php`: вкладки, поиск, таблица, `<details>` для ответа.
- [x] `GET/POST /admin/leads` с правом `manage`, пункт «Заявки» в навигации.
- [x] `tests/http/AdminLeadsTest.php` — аноним, редактор, список, статус, удаление, ответ.

## Задача 6: стражи, документы и запись

- [x] `TapTargetTest`: все контролы новых шаблонов несут `min-h-[44px]` литералом; класс вкладок
      собирается переменной, а не помощником.
- [x] `NoIndexNoStoreSweepTest`: `/admin/leads` в плане обхода (под редактором — 403, как
      «Приложения»), объём плана 141/117/140.
- [x] `tests/http/Kernel.php`: снятие `CMS_SMTP_*` — иначе dev-контейнер отправляет настоящие
      письма и «без SMTP отказ честный» перестаёт быть правдой.
- [x] `StyleSourceTest`: артефакт стилей пересобран (`npm run build`).
- [x] PHPStan `[OK]`, cs-fixer `0 из 481`.
- [x] README (двадцать четыре вида + абзац о заявках), §17 итоговой спецификации помечен
      закрытым, запись в ledger.
