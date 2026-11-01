# Личный кабинет и курсы с движком упражнений — дизайн слоя

**Дата:** 2026-11-01
**Статус:** решения штурма записаны, ждёт вычитки человеком
**Основание:** спецификация `2026-10-10-cms-final-specification.md` §17 (второй раздел
портирования: «личный кабинет и курсы с движком упражнений»); источник —
`archives/english-portal`: `prisma/schema.prisma` строки 62–291 (Level → Course → Module →
Lesson с целями/словарём/грамматикой/упражнениями и мини-тестом, Test kinds
PLACEMENT/LESSON/MODULE, ExerciseAttempt, UserProgress/UserXp/UserStreak/UserStatistics),
`src/apps/english/lib/{progress,lesson-pick,grading,lesson-award,placement,dashboard-focus}.ts`,
`src/apps/english/server/{learning,dashboard,content-actions}.ts`,
`src/lib/{constants,admin,content-guard}.ts`, API-контуры `exercises/[id]/attempt`,
`tests/[id]/{start,submit}`, `placement/{start,submit}`, `onboarding`, компоненты
`lesson-flow`/`exercise-player`/`test-runner`/`award-*`, страницы
`{dashboard,learn,lesson,module,progress,placement-test,onboarding}` и `content/{courses,lessons}`;
прецеденты CMS — приложения (`apps/news`: манифест со своим `sql/`/`seed.php`/маршрутами),
кабинет классифайдов (`apps/classifieds/cabinet.php`: открытая регистрация роли-строки
`seller`, свой гард вошедшего, `finishAppAction`), дизайн меню (`chrome`/`SETUP_FLOW`,
один POST-обработчик с `op`).

## Принятые решения штурма (перечень, чтобы не переспрашивать)

1. **Слой живёт приложением `apps/learn`** (id `learn`, название «Курсы»): учебный контур —
   раздел, а не способность сайта; выключенное приложение прячет свои ссылки (`publicNav`
   манифеста) и снимает свои адреса (гейт `app` в маршрутах — как у news). Таблицы идут
   релизом приложения `apps/learn/sql/` (механизм `planApp`/`applyApp`), начальная закладка
   — `seed.php` (исполняется при установке; обновление хранилище не трогает). Таблицы
   называются `tbl_learn_*` по конвенции приложений.
2. **Роль ученика — строка `student` в `tbl_user.role`** — ровно как `seller` у классифайдов:
   ни миграций ядра, ни реестра ролей. Открытая регистрация `/learn/register` (аноним,
   гард попыток по IP — строка в `tbl_learn_registration_attempt`), вход — штатный `/login`
   ядра; после регистрации человек уже вошёл (вставка + `loginUser()`, как у продавца).
3. **Ученические адреса открыты любому вошедшему** (student/admin/editor): свой гард
   `learnUser()` — аноним уезжает на `/login?next=…`, заблокированный — тоже (прецедент
   `classifiedsAccount()`). Пути кабинета **не помечаются `private`**: гейт приватных путей
   ядра пускает только admin/editor, а ученик — не админ. От роботов их держит `noindex`
   (ученические экраны печатаются с меткой); `privatePaths` манифеста закрывает только
   `/learn/admin*`.
4. **Профиль учёбы — таблица приложения** `tbl_learn_profile` (user_id unique):
   `onboarding_done`, `placement_done`, self_assessment, goal, daily_minutes,
   start_level_id, created_at. Ядерный `tbl_user` не расширяется: учётная карточка учёбы —
   факт раздела, и приложение честнее всего правит свои таблицы (порталово UserProfile
   уезжает сюда, к приложению).
5. **Учебная иерархия и контент урока — полным портом**: Level → Course → Module → Lesson,
   цели урока (`objectives`), словарь (`vocabulary`), грамматика с примерами (JSON),
   упражнения восьми типов и тесты трёх видов (PLACEMENT/LESSON/MODULE). MODULE-тесты
   модель поддерживает, но контент-админка их не создаёт — ровно как в портале, где
   редактора модульных тестов нет. Статусы контента DRAFT/REVIEW/PUBLISHED; ученик видит
   только цепочку опубликованных (уровни не статусные — активны всегда).
6. **Порядок — `sort`, не `order`** (то же решение, что у меню): ORDER — reserved-слово
   MySQL; чтения сортируются `sort asc, id asc` (порталово детерминизм при равных).
   Пределы по порталовым zod-схемам: title ≤ 120, description ≤ 500, sort ≤ 999,
   question ≤ 300, word ≤ 60, вопрос теста ≤ 300.
7. **Движок — чистые функции в `apps/learn/lib/`**, тестируемые unit-набором и
   переиспользуемые db/http-слоями: `learnGrade()` (порт `grading.ts` — choice по индексу;
   FILL_IN_THE_BLANK/TRANSLATION с нормализацией апострофов `’‘→'`, схлопнутых пробелов и
   конечных знаков; SENTENCE_BUILDING — каноническое совпадение строки; MATCHING — все
   пары сразу; BRAINSTORM — minCount из опций с формой множественного числа),
   `learnPublicExercise()` (публичная форма без ответа и объяснения), `learnPickExercises()`
   (порт `lesson-pick.ts` — seeded-shuffle mulberry32 на числовом хэше seed-строки, начатые
   сохраняются, резерв по одному LISTENING_CHOICE/MATCHING/BRAINSTORM/TIMED_QUIZ на урок,
   новые — от лёгкого к сложному), `learnPlacementScores()`/`learnDecideLevel()` (порт
   `placement.ts`), `learnTallySkills()`/`learnAward()` (порт `lesson-award.ts` — trophy
   gold при flawless либо accuracy ≥ 0.8/80, silver ≥ 0.6/60, иначе bronze; wins-строки,
   выбор слабого навыка с советом и сильного), `learnSkillStats()` (порт `skillStats` —
   сложение попыток тестов и упражнений по навыкам), `learnDayGreeting()`/
   `learnStreakNudge()`/`learnIsToday()` (порт `dashboard-focus.ts`; русский plural есть в
   ядре `core/text.php`). Пороги константами приложения: `LEARN_EXERCISES_PER_VISIT = 7`,
   `LEARN_XP_RULES` (LESSON_COMPLETE 20, TEST_COMPLETE 30, PERFECT_TEST_BONUS 10,
   MODULE_COMPLETE 100), пороги placement (Pre-A1 0 / A1 40 / A2 70 / B1 80 / B2 90 /
   C1 97), `LEARN_SKILL_SAMPLE_MIN = 8`; в ядро учебные тексты не уезжают.
8. **Прогресс и streak — UTC**: CMS ведёт время `nowUtc()`/`utcStamp()`, «день активности» —
   UTC-день без локальных смещений. Логика порта `progress.ts` сохраняется: повторная
   верная попытка XP не даёт (первая берёт xpReward по упражнению), streak касается один
   раз в день (сегодня — без изменений, вчера — +1, иначе сброс на 1; longest
   обновляется), `learnCompleteLesson()` идемпотентен и складывает LESSON_COMPLETE +
   TEST_COMPLETE + бонус при score = 100, а при закрытии последнего урока модуля —
   MODULE_COMPLETE; статистика — инкрементами (lessons_completed на закрытие урока,
   tests_taken на попытку теста, exercises_done на попытку упражнения).
9. **Урок — экран шагов без JS**: шаги живут GET-отметкой `?step=intro|vocab|grammar|
   exercises|test|result` (без отметки — resume как порталово `resumeState`: урок
   совершён → result; все упражнения начаты → test/result; иначе текущее упражнение).
   Урок держит один POST-обработчик на своём адресе с `op` (образец `/admin/menu`):
   `op=answer` — попытка упражнения (exercise_id + ответ), `op=test` — сдача мини-теста;
   `csrfCheck()` — первый вызов. Результат попытки — сессионный flash приложения
   (`learn_attempt`-карта: correct/revealed/matched/required/xp), PRG обратно на свой
   `?step=exercises&ex=N` печатает упражнение с разобранной попыткой; начисленный XP —
   плашкой notice. Одна CSRF-точка на экран ученику достаточна.
10. **Аудио и таймер — честная деградация без JS**: LISTENING_CHOICE печатает
    `<audio controls src>` если файл есть, иначе — speak-фразу открытым текстом
    (браузерный `speechSynthesis` не эмулируем); TIMED_QUIZ печатает «до N секунд» и живёт
    обычной формой без отсчёта — портал и без того серверно время не проверял
    (автоотправка была клиентской). Озвучка словаря урока (синтез браузера) — не
    переносится.
11. **Мини-тест и placement — все вопросы одним экраном**: без JS вопрос за вопросом не
    сдвинуть; форма печатает вопросы подряд (radio, контролы `min-h-[44px]` литералом),
    POST один (op=test для урока, отдельный POST `/placement-test` для уровня). Разбор —
    на том же экране: score, passed, XP и по каждому вопросу верно/не верно с объяснением
    (порталово details у test-runner). Placement после оценки пишет TestAttempt с
    разбивкой по навыкам, ставит `placement_done`, `start_level_id` и
    `progress.current_level_id` (порт `placement/submit`), результат — баллы, навыки и
    разбор ответов.
12. **Онбординг — один экран трёх вопросов** (самооценка, цель, минуты в день — radio из
    констант портала): POST пишет профиль (`onboarding_done`), PRG на `/placement-test`.
    Кабинет `/dashboard` гардит готовность: профиль без онбординга → `/onboarding`, без
    placement → `/placement-test` (порталово redirect).
13. **Контент-админка — `/learn/admin`** (право `login` по прецеденту news-admin):
    `/learn/admin/courses` — дерево уровней с курсами и модулями (правка строк на месте,
    действия добавления/переноса/удаления — отдельными inline-формами, один POST с `op`),
    `/learn/admin/lessons` — таблица уроков (создание/удаление), `/learn/admin/lessons/new`
    и `/learn/admin/lessons/{id}` — полная форма урока: карточка (модуль — select по
    дереву, слаг, статус), цели, слова, грамматика, упражнения (каждое своим блоком полей: тип/вопрос/
    опции/ответ/навык/сложность/xp), тест урока (вопросы своим набором полей). Сохранение
    перезаписывает подчинённые списки целиком (delete + insert в одной транзакции — порт
    `saveLesson`); порядок соседей всегда перенумеровывается 1..n, перенос между группами
    перенумеровывает обе (порт `renumberCoursesOf`/`renumberModulesOf`/`moveWithin`).
    Отказы — `finishAppAction` с человеческой причиной, как у конструктора.
14. **Сид приложения — уровни и placement-тест**: `apps/learn/seed.php` кладёт уровни
    Pre-A1…C2 и тест на уровень (30 вопросов из `content-placement.ts`); тест и вопросы —
    PUBLISHED. Курсы/модули/уроки создаёт контент админкой; день-1 `/learn` отвечает
    карточкой «Курс пока не назначен» (порталово поведение), а `/placement-test` уже
    работает.
15. **Навигация**: приложение отдаёт в шапку сайта одну ссылку (`publicNav` манифеста
    `[['/learn', 'Курсы']]`); кабинет рисует свою навигацию вкладками
    (Главная/Обучение/Прогресс) внутри ученических шаблонов — порталовы app-header и
    «Мои уроки/Мой прогресс» из подвала сюда не переносятся (подвал — пункты базы, админ
    добавит их при желании).

## Границы слоя (чего он не делает)

- Не переносит модель `Media` и загрузку файлов: аудио живёт строкой `audio_url` (можно
  полный `https://…`), хранилище файлов этому слою не нужно.
- Не эмулирует TTS браузера (`speechSynthesis`): словари урока без озвучки, фраза
  аудирования отдаётся файлом или открытым текстом.
- Не делает редактора placement-теста: вопросы теста на уровень живут от сида, админка
  правит курсы/модули/уроки (как в портале, где placement правится сидом).
- Не показывает статистику speaking/pronunciation: проценты кабинета и прогресса считают
  четыре навыка (VOCABULARY/GRAMMAR/READING/LISTENING — решение `dashboard.ts`: данных
  для честного вывода по остальным не хватает).
- Не связывает учётки разделов: XP школы не течёт в доску и магазин; заявки и рассылка —
  отдельный раздел §17.
- Не делает сертификатов, лидербордов и календаря занятий: их в источнике нет.
- Не делает правку уровня ученика «руками преподавателя»: в портале она запланирована
  словами, но админ-экрана профиля ученика нет; если появится — отдастся отдельным
  заходом.
- Не меняет права ядра: ученик не попадает в гейт приватных путей; админ-экраны несут
  `permission => 'login'` как остальная контент-админка приложений.

## 1. Схема данных приложения (релиз `apps/learn/sql/20261101_0001_learn.sql`)

```sql
CREATE TABLE IF NOT EXISTS tbl_learn_profile (
  user_id VARCHAR(26) NOT NULL,            -- внешний на tbl_user.id, без FK (стиль ядра)
  onboarding_done TINYINT(1) NOT NULL DEFAULT 0,
  placement_done TINYINT(1) NOT NULL DEFAULT 0,
  self_assessment VARCHAR(20) NOT NULL DEFAULT '',
  goal VARCHAR(20) NOT NULL DEFAULT '',
  daily_minutes INT NOT NULL DEFAULT 0,
  start_level_id VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_level (
  id VARCHAR(26) NOT NULL,
  code VARCHAR(12) NOT NULL,               -- Pre-A1 | A1 | … C2
  title VARCHAR(120) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY learn_level_code (code)
);

CREATE TABLE IF NOT EXISTS tbl_learn_course (
  id VARCHAR(26) NOT NULL,
  level_id VARCHAR(26) NOT NULL,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',   -- DRAFT | REVIEW | PUBLISHED
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY learn_course_level (level_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_module (
  id VARCHAR(26) NOT NULL,
  course_id VARCHAR(26) NOT NULL,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY learn_module_course (course_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_lesson (
  id VARCHAR(26) NOT NULL,
  module_id VARCHAR(26) NOT NULL,
  slug VARCHAR(120) NOT NULL,              -- UNIQUE; уникальность обеспечивает операция
  title VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY learn_lesson_slug (slug),
  KEY learn_lesson_module (module_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_objective (
  id VARCHAR(26) NOT NULL,
  lesson_id VARCHAR(26) NOT NULL,
  text VARCHAR(300) NOT NULL,
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY learn_objective_lesson (lesson_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_vocab (
  id VARCHAR(26) NOT NULL,
  lesson_id VARCHAR(26) NOT NULL,
  word VARCHAR(60) NOT NULL,
  translation VARCHAR(120) NOT NULL DEFAULT '',
  ipa VARCHAR(120) NOT NULL DEFAULT '',
  example_en VARCHAR(300) NOT NULL DEFAULT '',
  example_ru VARCHAR(300) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY learn_vocab_lesson (lesson_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_grammar (
  id VARCHAR(26) NOT NULL,
  lesson_id VARCHAR(26) NOT NULL,
  title VARCHAR(120) NOT NULL,
  summary VARCHAR(500) NOT NULL DEFAULT '',
  details TEXT NULL,                       -- длинный текст покажется развёрнутым
  examples MEDIUMTEXT NULL,                -- JSON: [ {en, ru?}, … ]
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY learn_grammar_lesson (lesson_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_exercise (
  id VARCHAR(26) NOT NULL,
  lesson_id VARCHAR(26) NOT NULL,
  type VARCHAR(32) NOT NULL,               -- перечень LEARN_EXERCISE_TYPES
  question VARCHAR(300) NOT NULL,
  options MEDIUMTEXT NULL,                 -- JSON по типу (пары/слова/minCount/speak…)
  correct_answer MEDIUMTEXT NOT NULL,      -- JSON канонического ответа
  explanation VARCHAR(500) NOT NULL DEFAULT '',
  difficulty INT NOT NULL DEFAULT 1,       -- 1..5
  skill VARCHAR(20) NULL,                  -- перечень LEARN_SKILLS
  xp_reward INT NOT NULL DEFAULT 5,        -- 0..100
  audio_url VARCHAR(300) NULL,             -- только LISTENING_CHOICE
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY learn_exercise_lesson (lesson_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_attempt (
  id VARCHAR(26) NOT NULL,
  user_id VARCHAR(26) NOT NULL,
  exercise_id VARCHAR(26) NOT NULL,
  is_correct TINYINT(1) NOT NULL,
  answer MEDIUMTEXT NOT NULL,              -- JSON отданного ответа
  xp_awarded INT NOT NULL DEFAULT 0,       -- факт начисления: > 0 у первой верной
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY learn_attempt_user (user_id, exercise_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_test (
  id VARCHAR(26) NOT NULL,
  kind VARCHAR(16) NOT NULL,               -- PLACEMENT | LESSON | MODULE
  lesson_id VARCHAR(26) NULL,
  module_id VARCHAR(26) NULL,
  title VARCHAR(120) NOT NULL,
  pass_score INT NOT NULL DEFAULT 70,      -- %
  status VARCHAR(16) NOT NULL DEFAULT 'PUBLISHED',
  PRIMARY KEY (id),
  UNIQUE KEY learn_test_lesson (lesson_id),
  UNIQUE KEY learn_test_module (module_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_test_question (
  id VARCHAR(26) NOT NULL,
  test_id VARCHAR(26) NOT NULL,
  skill VARCHAR(20) NOT NULL,              -- VOCABULARY | GRAMMAR | READING | LISTENING
  text VARCHAR(300) NOT NULL,
  options MEDIUMTEXT NOT NULL,             -- JSON: массив вариантов либо {speak, choices}
  correct_option_index INT NOT NULL,
  explanation VARCHAR(500) NOT NULL DEFAULT '',
  audio_url VARCHAR(300) NULL,
  passage_ref VARCHAR(60) NULL,            -- READING: ссылка на пассаж
  sort INT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY learn_test_question_test (test_id, sort)
);

CREATE TABLE IF NOT EXISTS tbl_learn_test_attempt (
  id VARCHAR(26) NOT NULL,
  user_id VARCHAR(26) NOT NULL,
  test_id VARCHAR(26) NOT NULL,
  score INT NOT NULL,                      -- %, 0..100
  vocabulary_score INT NULL,
  grammar_score INT NULL,
  reading_score INT NULL,
  listening_score INT NULL,
  passed TINYINT(1) NOT NULL,
  recommended_level_code VARCHAR(12) NULL, -- только PLACEMENT
  recommended_start_module_id VARCHAR(26) NULL,
  answers MEDIUMTEXT NOT NULL,             -- JSON: [{questionId, optionIndex, correct}, …]
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY learn_test_attempt_user (user_id, test_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_progress (
  user_id VARCHAR(26) NOT NULL,
  current_level_id VARCHAR(26) NULL,
  completed_lessons MEDIUMTEXT NOT NULL DEFAULT '[]', -- JSON [{lessonId, completedAt, testScore}]
  completed_modules MEDIUMTEXT NOT NULL DEFAULT '[]', -- JSON [{moduleId, completedAt}]
  total_study_seconds INT NOT NULL DEFAULT 0,          -- заполняется в следующих заходах
  current_module_order INT NOT NULL DEFAULT 1,
  current_lesson_order INT NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_xp (
  user_id VARCHAR(26) NOT NULL,
  total INT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_streak (
  user_id VARCHAR(26) NOT NULL,
  current_days INT NOT NULL DEFAULT 0,
  longest_days INT NOT NULL DEFAULT 0,
  last_study_at DATE NULL,                 -- UTC-день активности без времени
  PRIMARY KEY (user_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_statistics (
  user_id VARCHAR(26) NOT NULL,
  lessons_completed INT NOT NULL DEFAULT 0,
  tests_taken INT NOT NULL DEFAULT 0,
  exercises_done INT NOT NULL DEFAULT 0,
  correct_rate FLOAT NOT NULL DEFAULT 0,   -- доля верных попыток, обновляет движок
  PRIMARY KEY (user_id)
);

CREATE TABLE IF NOT EXISTS tbl_learn_registration_attempt (
  id VARCHAR(26) NOT NULL,                 -- технический суррогат порядка попыток
  ip VARCHAR(45) NOT NULL,                 -- IPv4/IPv6, как clientIp()
  email VARCHAR(120) NOT NULL DEFAULT '',
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY learn_registration_attempt_ip (ip, attempted_at)
);
```

Отличия от портала, каждое — по причине: `order` → `sort` (решение 6); ключи — VARCHAR(26)
под `newId()` без FOREIGN KEY (стиль ядра: целостность несут операции, как у меню и
конструктора); streak — DATE на UTC-день (решение 8); `tbl_learn_registration_attempt` —
свой гард открытой регистрации, то же решение, что у классифайдов (решение 2). Релиз
идемпотентен (`IF NOT EXISTS`, MySQL фиксирует DDL сразу). Чтения — с LIMIT по конвенции
стража производительности.

## 2. Библиотеки движка (`apps/learn/lib/`)

- `lib/constants.php` — канонические перечни и пороги (решение 7): `LEARN_EXERCISE_TYPES`,
  `LEARN_SKILLS` + `LEARN_SKILL_LABELS`, `LEARN_SKILL_TITLES` (выборка четырёх навыков),
  `LEARN_CONTENT_STATUS`, `LEARN_TEST_KINDS`, `LEARN_XP_RULES`, `LEARN_PLACEMENT_LEVELS`
  (пороги + counts 10/10/5/5), `LEARN_EXERCISES_PER_VISIT`, `LEARN_SKILL_SAMPLE_MIN`,
  `LEARN_ONBOARDING_SELF`/`LEARN_ONBOARDING_GOALS`/`LEARN_ONBOARDING_MINUTES`.
- `lib/grading.php` — `learnGrade(array $row, mixed $answer): array` и
  `learnPublicExercise(array $row): array`; нормализация ответов 1:1 порталу (решение 7).
- `lib/lesson-pick.php` — `learnMulberry32(int $seed): callable`, `learnPickExercises(array
  $all, array $attemptedIds, int $target, string $seed): array`.
- `lib/placement.php` — `learnPlacementScores(array $questions, array $answers, array
  $questionIds): array`, `learnDecideLevel(int $percent): string`.
- `lib/award.php` — `learnTallySkills(array $answers): array`, `learnAward(array $outcome):
  array` (trophy/wins/focus/strength), `learnSkillAdvice(string $skill): string`.
- `lib/focus.php` — `learnDayGreeting()`, `learnStreakNudge(int $days, bool $today):
  string`, `learnIsToday(?string $lastStudyAt): bool`, `learnWeakSkill()`/
  `learnStrongSkill()` с порогом `LEARN_SKILL_SAMPLE_MIN`.
- `lib/progress.php` — операции на живой базе (db-тесты): `learnAddXp(string $userId, int
  $amount)`, `learnTouchStreak(string $userId): int`, `learnRecordExerciseAttempt(array
  $params): array`, `learnCompleteLesson(array $params): array` — обе возвращают факт XP и
  флаги alreadyCompleted/moduleCompleted.
- `lib/draft.php` — порт форматов упражнений: `learnExerciseDraftToRow(array $d, int $sort):
  array` пишет `options`/`correct_answer` ровно в тех JSON-форматах, которые читает
  `learnGrade()` (MC ≥ 2 опций и индекс в диапазоне; LISTENING — speak + ≥ 2 варианта;
  TRANSLATION/FILL — ≥ 1 принимаемого; BANK — слова через пробел; MATCH — строки
  «слева = справа», ≥ 2; BRAINSTORM — ≥ 3 принимаемых + minCount; TIMED_QUIZ — ≥ 2 опций,
  лимит 5..120 сек), обратный `learnExerciseRowToDraft()`, `learnTextToGrammarExamples()`.
- `lib/validators.php` — пределы и нормализаторы форм админки/регистрации (решение 6).

## 3. Ученические маршруты (app-роуты, пути литералами)

| Маршрут | Метод | Что делает | Отметки маршрута |
| --- | --- | --- | --- |
| `/learn/register` | GET/POST | регистрация ученика (роль `student`), вход, PRG | аноним, гард попыток по IP |
| `/onboarding` | GET/POST | три вопроса → профиль → PRG на тест | вошедший (гард `learnUser()`) |
| `/placement-test` | GET/POST | тест одним экраном; POST — оценка и записи | вошедший |
| `/dashboard` | GET | кабинет: приветствие, фокус, следующий модуль | вошедший |
| `/progress` | GET | XP/стрик/статы/навыки/последние тесты | вошедший |
| `/learn` | GET | текущий курс и модули с процентами | вошедший |
| `/learn/module/{id}` | GET | уроки модуля: галочки/замки/порядок | вошедший |
| `/lesson/{slugOrId}` | GET/POST | урок шагами; POST op=answer/op=test | вошедший |
| `/learn/admin` | GET | обзор контента приложения | private, login |
| `/learn/admin/courses` | GET/POST | дерево уровней/курсов/модулей + op | private, login |
| `/learn/admin/lessons` | GET/POST | таблица уроков, удаление (op) | private, login |
| `/learn/admin/lessons/new` | GET/POST | форма нового урока | private, login |
| `/learn/admin/lessons/{id}` | GET/POST | форма правки урока | private, login |

Гард `learnUser()` стоит в шапке каждой ученической функции: аноним и заблокированный
уезжают на `/login?next=…`. Включённость приложения — собственный гард маршрутизатора
(`'app' => 'learn'`), как у news. Все ученические экраны — `noindex` (метка в `render()`),
адреса `/learn/admin*` закрыты и robots-стражей через `privatePaths`.

## 4. Движок на экранах урока

- Шапка экрана: уровень курса · «Модуль N: …» · «Урок N. Заголовок», кнопка «К модулю».
  Закрытый урок (`unlocked = false`) — карточка «Урок закрыт» со ссылкой на модуль
  (порталово поведение).
- Stepper шагов — существующая badge-разметка; теста у урока нет — пятый шаг не
  печатается.
- Введение — цели с галочками; слова — таблица слово/перевод/IPA/примеры (без озвучки);
  грамматика — развёрнутые карточки с примерами.
- Упражнения: `learnPickExercises()` при открытии (банку положено целевое число 7); блок
  отрисовки по типу — одна функция `learnRenderExercise(array $exercise, array $attempt)`
  в `apps/learn/ui/exercise.php`: choice — radio-кнопки `min-h-[44px]` литералом;
  FILL/TRANSLATION — текстовое поле; SENTENCE_BUILDING — подсказка «слова через пробел» с
  текстовым полем (без JS чипы не склеить — честная форма); MATCHING — левая колонка
  строкой, правая — select на каждую пару (форма доступна без JS); BRAINSTORM — textarea.
  Результат попытки сверху — плашка «Верно/Не верно» с XP и правильным ответом
  (flash-карта `learn_attempt`); после верной попытки поле ответа замыкается — второй
  отправки нет, кнопка «Дальше» ведёт к следующему упражнению.
- Мини-тест: форма всех вопросов подряд (radio), кнопка «Проверить»; после сдачи —
  итоговый блок (score/passed/XP и разбор вопросов с объяснениями), «К результату урока»
  ведёт GET `?step=result`. Непройденный тест допускает пересдачу (порталово «Пройти
  заново»), XP за повторную успешную сдачу не начисляется — `learnCompleteLesson()`
  идемпотентен.
- Результат урока: кубок (gold/silver/bronze — текстовая плашка с цветом успеха/нейтралью
  существующими утилитами, без SVG-компонента портала), XP за урок, короткий рассказ об
  успехах, слабый навык с советом и сильный — порталово `learnAward()`; выполненный урок
  получает ссылку «Следующий урок» модуля.

## 5. Кабинет, прогресс, онбординг, placement

- `/dashboard`: гард готовности (решение 12), приветствие по времени суток
  (`learnDayGreeting` по серверному времени), реплика о серии (`learnStreakNudge`),
  карточка «На что обратить внимание» — слабый навык с процентом, советом и ссылкой на
  непройденный урок модуля, где есть упражнения этого навыка; если слабого нет — фраза
  «Слабого места не видно…» и похвала сильному; stat-карточки XP/серии/уроки-тесты-
  упражнения; текущий модуль с процентом и кнопкой следующего урока.
- `/progress`: те же цифры без подсказок; навыки четырьмя полосами (percent/total,
  «нет данных» у пустых), история попыток тестов — таблицей, LIMIT 10 (порталово
  slice(0, 10)).
- `/onboarding`: экран трёх вопросов radio; POST пишет `tbl_learn_profile` (upsert —
  `INSERT … ON DUPLICATE KEY UPDATE`), PRG `?notice=learn_onboarded`.
- `/placement-test`: GET печатает вопросы теста на уровень; POST — пишет TestAttempt с
  разбивкой по навыкам, рекомендованным уровнем и стартовым модулем (фолбэк MVP держится:
  на рекомендованном уровне нет курса — берётся первый опубликованный курс школы), ставит
  профиль и `progress.current_level_id`; экран результата — баллы, навыки и разбор
  ответов, кнопка «В кабинет».

## 6. Контент-админка

- `/learn/admin`: обзор — счётчики уровней/курсов/модулей/уроков и ссылки на два экрана.
- `/learn/admin/courses`: уровень — строка с полями правки (code/title/sort), под ним
  его курсы с модулями; форма курса/модуля — как у пунктов меню (правка на месте,
  «выше/ниже», удаление); вместо скрытия — статусы публикаций. По требованию удаления
  курса экран показывает счётчик теряемых модулей и уроков прямо в подтверждении формы
  (порталово решение `deleteCourse`). Отказы — flash-причина (решение 13).
- `/learn/admin/lessons`: таблица уровень/модуль/урок/слов/упражнений/статус с кнопками
  «Новый урок», «Редактировать» и op-удалением; POST-обработчик держит `op=delete-lesson`.
- Форма урока (`/learn/admin/lessons/new` и `{id}`): карточка урока (модуль — select по
  дереву, слаг — уникальность как у конструктора), статус select; списки целей/слов/
  грамматики — повторяющиеся блоки полей; упражнения — блок на упражнение с типом и
  зависимыми полями (нормализатор `learnExerciseDraftToRow` держит форматы), тест урока —
  passScore 1..100 и вопросы (skill/text/options ≥ 2 варианта/correctIndex в
  диапазоне/explanation/audioUrl). Сохранение перезаписывает всё подчинённое одной
  транзакцией (решение 13). Аудио-поле — строка (существующий файл сохранение не
  стирает).
- Перестановка («выше»/«ниже») с курсом и модулем: splice + перезапись 1..n внутри своей
  группы — порталово `moveWithin`; перенос курса между уровнями перенумеровывает обе
  группы.

## 7. Тесты и стражи

- `tests/unit/LearnRulesTest.php` — чистые функции: нормализация/валидация черновиков
  (`learnExerciseDraftToRow` по всем восьми типам и кривым значениям), `learnGrade` по
  типам (апострофы, пробелы, конечные знаки, пары, brainstorm), `learnPickExercises`
  (детерминизм seed, сохранение начатых, резерв once-per-lesson, порядок от лёгкого к
  сложному), placement (разбивка по навыкам, пороги `decideLevel`), award (пороги trophy,
  tallySkills, советы), фокус (greeting/nudge/weak/strong с порогом 8).
- `tests/db/LearnTest.php` — операции на живой базе: `learnRecordExerciseAttempt` (первая
  верная начисляет xpReward, повторная верная и неверная — ноль), streak
  (сегодня/вчера/сброс/longest по UTC-дням), `learnCompleteLesson` (идемпотентность,
  раскладка XP, MODULE_COMPLETE на последнем уроке модуля), upsert профиля/progress,
  регистрация с гардом попыток по IP.
- `tests/http/LearnStudentTest.php` — ученический контур на живом ядре: аноним на
  ученические адреса → 302 `/login`; регистрация создаёт `student` и входит; онбординг
  с PRG; placement проходит и ставит профиль/progress; уроки: замки, шаги, попытка с
  flash-результатом и XP, мини-тест сдача и результат (XP, MODULE_COMPLETE на последнем
  уроке); кабинет и прогресс со своими цифрами; выключение приложения снимает адреса.
- `tests/http/LearnAdminTest.php` — контент-админка: права (аноним 302/403, редактор 200),
  CSRF-отказ («Проверка формы не прошла»), PRG-ключи, создание/перенос/удаление дерева,
  полный цикл урока (создание формы с упражнениями и мини-тестом, чтение учеником,
  правка), отказ с кривым упражнением.
- Стражи: `CsrfCoverageTest` (новые POST-литералы по шаблону addRoute), `NoticeKeyTest`
  (новые ключи `learn_registered`/`learn_onboarded`/`learn_placed`/`learn_saved`/
  `learn_moved`/`learn_deleted` в `CORE_NOTICE_TEXT`), `TapTargetTest`/
  `OutputEscapingTest`/`StyleSourceTest` (разметка ученических и админ-экранов; новых
  CSS-правил нет — артефакт пересобирается `bash scripts/css`, если появятся новые
  утилиты), `PrivatePathsRobotsTest` + `NoIndexNoStoreSourceTest` (приватность
  `/learn/admin*`, noindex ученических), `NoIndexNoStoreSweepTest` (новые GET-адреса в
  план обхода, числа обновляются), `PerformanceTest` (LIMIT во всех чтениях). Помощники
  `TestDb` переиспользуются (seedUser c ролью `student`).

## 8. Что остаётся за пределами этого захода

- Сертификаты, лидерборды, календарь занятий, «преподаватель правит уровень ученика» —
  в портале это остановилось на словах, экранов нет: отдельные заходы, если появятся.
- Загрузка медиа и генерация аудио — отдельное решение хранилища файлов, движку не
  относится.
- MODULE-тесты и их редактор — модель держит вид, контент ими в этом заходе не живёт.
- Доска/магазин/заявки с рассылкой — следующие разделы §17; кабинет им ничего не отдаёт.
- Перенос данных из PostgreSQL (сиды `content-*.ts`) — заключительный раздел §17.
