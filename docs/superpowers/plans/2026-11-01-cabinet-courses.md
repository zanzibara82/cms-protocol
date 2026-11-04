# Личный кабинет и курсы с движком упражнений — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ученик регистрируется сам, проходит онбординг и тест на уровень, получает курс и
двигается по модулям уроками-шагами (цели, слова, грамматика, упражнения, мини-тест) с XP,
процентами и серией занятий; контент (уровни, курсы, модули, уроки) правится в
`/learn/admin`; выключенный раздел снимает свои адреса и ссылку сам.

**Architecture:** приложение `apps/learn` (id `learn`, название «Курсы»): манифест со своим
релизом SQL (18 таблиц `tbl_learn_*`) и `seed.php` (уровни Pre-A1…C2 + placement-тест из 30
вопросов). Роль ученика — строка `student` в `tbl_user.role`; ученические адреса открыты
любому вошедшему через гард `learnUser()` (не `private`), от роботов — `noindex`;
`privatePaths` манифеста закрывает только `/learn/admin*`. Движок — чистые функции в
`apps/learn/lib/` (grading, lesson-pick, placement, award, focus, draft, validators),
прогресс и серия — операции на живой базе в UTC-днях. Урок — экран шагов без JS
(`?step=intro|vocab|grammar|exercises|test|result`) с одним POST-обработчиком на своём
адресе (`op=answer|op=test`, `csrfCheck()` первым); результат попытки — flash-карта +
PRG. Кабинет `/dashboard`, прогресс `/progress`, онбординг и placement — формами без JS;
контент-админка — один POST с `op` на экране («/learn/admin/courses», таблица уроков),
сохранение урока перезаписывает подчинённые списки одной транзакцией.

**Tech Stack:** PHP 8.3, MySQL 8 (InnoDB, utf8mb4_unicode_ci), PHPUnit (наборы `unit` / `db` /
`http` / `guard`), Tailwind v4 (только существующие утилиты — без новых CSS-правил), стражи
`tests/guard/`.

**Спецификация:** `docs/superpowers/specs/2026-11-01-cabinet-courses-design.md` — решения
1–15 штурма, границы, схема §1, библиотеки §2, маршруты §3, экраны §4–§6, тесты §7.
При расхождении действует спецификация, найденное расхождение записывается вердиктом
контроллера в ledger.

## Глобальные ограничения

- Только PHP 8.3 и MySQL: ни одной runtime-зависимости, ни одного нового пакета.
- Все строки, комментарии и имена тестов — по-русски. Идентификаторы — по-английски.
- Разметка печатается через `e()`; сырой HTML — только переменным `$content`/`$head`/`$footer`
  с пометкой `/* @safe html */` (страж `OutputEscapingTest`).
- Каждый интерактивный контроль — `min-h-[44px]` литералом; чекбоксы — `size-11`
  (страж `TapTargetTest`). Ученические экраны печатаются с `noindex` (стражи
  `NoIndexNoStoreSourceTest`, `NoIndexNoStoreSweepTest`).
- Новых CSS-правил нет: экраны собираются существующими утилитами Tailwind; если появляются
  новые классы-утилиты — сборка `bash scripts/css`, артефакт `public/assets/app.css`
  коммитится.
- SQL пишется параметрами; имена таблиц — через `t()`; в релизе схемы — литералы `tbl_…`
  (стражи `AppSqlTest`, `SqlSafetyTest`); релиз идемпотентен (`CREATE TABLE IF NOT EXISTS`);
  чтения — с LIMIT (страж `PerformanceTest`).
- Пути POST-обработчиков — строковые литералы в `addRoute` (страж `CsrfCoverageTest`);
  `csrfCheck()` — первый вызов каждого POST-обработчика.
- Время — только `nowUtc()`/`utcStamp()`, дни активности — UTC-дни; ключи уведомлений
  (`learn_registered`, `learn_onboarded`, `learn_placed`, `learn_saved`, `learn_moved`,
  `learn_deleted`) — в `CORE_NOTICE_TEXT` (страж `NoticeKeyTest`).
- Учебные пороги, тексты и механики — константами и функциями приложения; в ядро учебная
  логика не уезжает.
- Коммит после зелёного полного гейта каждой задачи; пуш следом (режим владельца).

## Карта файлов

Создаются:

| Файл | Ответственность |
| --- | --- |
| `apps/learn/manifest.php` | манифест: id, название, версия, `publicNav` `/learn`, `privatePaths` `/learn/admin`, `sql`, `seed` |
| `apps/learn/sql/20261101_0001_learn.sql` | релиз: 18 таблиц учебного контура по §1 спецификации |
| `apps/learn/seed.php` | уровни Pre-A1…C2 и placement-тест (30 вопросов, PUBLISHED) |
| `apps/learn/routes.php` | маршруты приложения: ученические, кабинет, контент-админка (литеральные пути, отметки `app`/`private`) |
| `apps/learn/access.php` | гард `learnUser()`, регистрация ученика с гардом попыток по IP |
| `apps/learn/lib/constants.php` | перечни и пороги: типы упражнений, навыки, статусы, виды тестов, XP, пороги placement, онбординг |
| `apps/learn/lib/validators.php` | пределы и нормализаторы форм (регистрация, черновики, тесты) |
| `apps/learn/lib/draft.php` | форматы `options`/`correct_answer` по типам, черновики упражнений и примеров грамматики |
| `apps/learn/lib/grading.php` | `learnGrade()`, `learnPublicExercise()` |
| `apps/learn/lib/lesson-pick.php` | `learnMulberry32()`, `learnPickExercises()` |
| `apps/learn/lib/placement.php` | `learnPlacementScores()`, `learnDecideLevel()` |
| `apps/learn/lib/award.php` | `learnTallySkills()`, `learnAward()`, советы навыка |
| `apps/learn/lib/focus.php` | приветствие, реплики серии, слабый/сильный навык |
| `apps/learn/lib/progress.php` | попытки, XP, серия, закрытие урока/модуля — операции на живой базе |
| `apps/learn/student.php` | ученические страницы: `/learn`, `/learn/module/{id}`, `/lesson/{slugOrId}` (GET-шаги + POST op) |
| `apps/learn/ui/exercise.php` | отрисовка упражнения/попытки по типу |
| `apps/learn/cabinet.php` | `/dashboard`, `/progress`, `/onboarding`, `/placement-test` (GET/POST) |
| `apps/learn/admin.php` | `/learn/admin`, `/learn/admin/courses`, `/learn/admin/lessons` (+ форма урока) — POST с `op` |
| `tests/unit/LearnRulesTest.php` | чистые правила: константы, валидаторы, черновики, проверка ответов, подбор, placement, награды, фокус |
| `tests/db/LearnTest.php` | применение релиза, сид, попытки, серия, закрытие урока, регистрации |
| `tests/http/LearnStudentTest.php` | ученический контур на живом ядре |
| `tests/http/LearnAdminTest.php` | права, CSRF, PRG-ключи, отказы, полный цикл урока |

Правятся:

| Файл | Что меняется |
| --- | --- |
| `core/notice.php` | ключи `learn_*` в `CORE_NOTICE_TEXT` |
| `tests/guard/NoIndexNoStoreSweepTest.php` | план обхода: новые GET-адреса, числа, строка хроники |
| стражи | по месту: `PrivatePathsRobotsTest`, `TapTargetTest`, `OutputEscapingTest`, `PerformanceTest`, `AppSqlTest`/`SqlSafetyTest` |

## Задача 1: релиз SQL, манифест, сид, константы и чистый движок

- [x] Красный: `tests/db/LearnTest.php` — релиз применяется `planApp`/`applyApp`, создаёт
      18 таблиц `tbl_learn_*` идемпотентно; `seed.php` наполняет уровни Pre-A1…C2 по порядку
      и placement-тест с 30 вопросами (композиция по навыкам 10/10/5/5, PUBLISHED);
      `tests/unit/LearnRulesTest.php` — константы (пороги XP 20/30/10/100, пороги
      placement Pre-A1 0/A1 40/A2 70/B1 80/B2 90/C1 97, `EXERCISES_PER_VISIT = 7`,
      `SKILL_SAMPLE_MIN = 8`, перечни типов/навыков/статусов), пределы форм, форматы
      черновиков по всем восьми типам (`learnExerciseDraftToRow`/`learnExerciseRowToDraft`),
      разбор ответов (`learnGrade`: апострофы, пробелы, конечные знаки, пары, brainstorm,
      timed), публичная форма упражнения, подбор (`learnPickExercises`: детерминизм seed,
      сохранение начатых, резерв раз в урок, порядок), placement-оценки и выбор уровня,
      награды и фокус-подсказки.

## Задача 2: прогресс и серия на живой базе

- [x] Красный: `tests/db/LearnTest.php` — `learnRecordExerciseAttempt` (первая верная
      начисляет `xp_reward`, повторная верная и неверная — ноль, попытка записана),
      `learnAddXp`, `learnTouchStreak` (сегодня — без изменений, вчера — +1, пропуск —
      сброс на 1, longest растёт; UTC-дни), `learnCompleteLesson` (идемпотентность,
      LESSON_COMPLETE + TEST_COMPLETE + бонус при score = 100, MODULE_COMPLETE на
      последнем уроке модуля), статистика инкрементами, upsert профиля и progress.

## Задача 3: чтение ученического контура

- [x] Красный: `tests/http/LearnStudentTest.php` (первая пачка) — аноним на ученические
      адреса → 302 `/login?next=…`; `/learn` без курса — карточка «Курс пока не назначен»;
      публикуется курс через db-помощники: дерево модулей с процентами; модуль со своим
      списком уроков и галочками; урок открытым шагом `?step=` (цели, слова, грамматика,
      упражнение по типу, мини-тест — все вопросы), resume без отметки; уроки
      следующих модулей закрыты, отдельный экран «Урок закрыт»; все экраны с `noindex`.

## Задача 4: POST-жизнь урока

- [x] Красный: `tests/http/LearnStudentTest.php` (вторая пачка) — POST `op=answer` на
      адресе урока с `csrfCheck()`: верная попытка — flash-карта и плашка XP, повторная
      верная — без XP; неверная — разбор с правильным ответом; POST `op=test` — сдача
      мини-теста: итоговая разметка (score/passed/XP/разбор вопросов), пересдача без
      двойного XP, закрытие модуля на последнем уроке; отказ CSRF — 403 «Проверка формы
      не прошла».

## Задача 5: кабинет, прогресс, вход и placement

- [x] Красный: `tests/http/LearnStudentTest.php` (третья пачка) — регистрация `/learn/register`
      (создаёт `student`, входит, гард попыток по IP, отказы кривых полей), онбординг
      `/onboarding` (пишет профиль, PRG `?notice=learn_onboarded`), `/placement-test`
      (GET — тест, POST — TestAttempt с разбивкой по навыкам, профиль и progress
      обновлены, результат с разбором), `/dashboard` (гард редиректов на онбординг/placement,
      приветствие, фокус-карточка, серийные реплики), `/progress` (XP, серия, четыре
      навыка, история тестов LIMIT 10); выключение приложения снимает адреса и ссылку шапки.

## Задача 6: контент-админка

- [ ] Красный: `tests/http/LearnAdminTest.php` — права (аноним 302, ученик без права — 403,
      редактор 200, `private` и no-store), `/learn/admin/courses` — один POST с `op`:
      создание/правка уровней, курсов, модулей, перестановка с перенумерацией, удаление
      курса со счётчиком теряемых и отказом без подтверждения; `/learn/admin/lessons` +
      форма урока (карточка, цели, слова, грамматика, упражнения, мини-тест): создание,
      сохранение перезаписью подчинённых списков одной транзакцией, правка двойным чтением,
      отказ кривого упражнения с человеческой причиной, удаление урока; PRG-ключи
      `learn_saved`/`learn_moved`/`learn_deleted`, flash-причины отказов; опубликованное
      видно ученику, REVIEW/DRAFT — нет.

## Задача 7: стражи, Sweep, полный гейт и запись

- [ ] Стражи: `NoticeKeyTest` (ключи `learn_*`), `CsrfCoverageTest` (новые POST-литералы),
      `TapTargetTest`, `OutputEscapingTest`, `StyleSourceTest`, `AppSqlTest`/`SqlSafetyTest`,
      `PerformanceTest` (LIMIT в новых чтениях), `PrivatePathsRobotsTest` +
      `NoIndexNoStoreSourceTest` (приватность `/learn/admin*`, noindex ученических),
      `NoIndexNoStoreSweepTest` (план обхода, числа, хроника).
- [ ] Полный гейт через Git Bash зелёный; прирост тестов назван.
- [ ] Ledger-запись в `cms/.superpowers/sdd/part1/progress.md`: решения, красные и зелёные
      прогоны по пачкам, вердикты, коммит и пуш.
