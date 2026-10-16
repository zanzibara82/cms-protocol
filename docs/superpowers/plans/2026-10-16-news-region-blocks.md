# Блоки вывода новостей на страницы — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** раздел «Новости» сам выкладывает свои подборки на страницы сайта: ядро печатает точку
вывода `region()`, а приложение отвечает в неё блоками из таблицы `tbl_news_block`.

**Architecture:** ядро узнаёт только имя зоны и ничего не знает о новостях — `region('home')`
 вызывает фильтр `region_home` и печатает обёртку, если подписчик что-то вернул. Приложение
подписывает три зоны одной функцией `newsRegionHtml()`, которая читает видимые блоки своей зоны
и рисует карточки уже существующим partial'ом. Пять шаблонов (главная, лента, рубрика, тег,
материал) получают по одному вызову; пустая зона не печатает ни байта.

**Tech Stack:** PHP 8.3, MySQL 8 (InnoDB, utf8mb4_unicode_ci), PHPUnit (наборы `unit` / `db` /
`guard` / `http`), Tailwind v4 (`bash scripts/css`), стражи исходников в `tests/guard/`.

**Спецификация:** `docs/superpowers/specs/2026-10-16-news-region-blocks-design.md` — она связывает
решения 1–8, таблицу колонок §2, константы §3, читатель §4, экран §5 и критерии §6. Текст плана
спорит с деревом, спецификация — с текстом плана; при расхождении действует спецификация, а найденное
расхождение записывается вердиктом контроллера ниже.

## Глобальные ограничения

- Только PHP 8.3 и MySQL: ни одной runtime-зависимости, ни одного нового пакета.
- Все строки, комментарии и имена тестов — по-русски. Идентификаторы — по-английски.
- Разметка в шаблонах и в `core/` печатается через `e()`; сырой HTML уходит только переменной,
  названной так, как ждёт страж `OutputEscapingTest` (`$content`, `$head`, `$footer`), и помечен
  комментарием `/* @safe html */`.
- Каждый контроль админки несёт класс `min-h-[44px]` (страж `TapTargetTest`); экран обязан
  читаться на 360 px.
- Новые классы стилей объявляются в `src-css/app.css` и пересобираются `bash scripts/css` — иначе
  краснеет шаг свежести артефакта в `scripts/gate.sh` и `StyleSourceTest`.
- SQL пишется параметрами; имена таблиц — через `t()`; в файлах релизов схемы — литералы `tbl_…`.
- Каждый релиз схемы идемпотентен (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`): MySQL фиксирует
  DDL до записи в журнал, поэтому недочитанный релиз повторяется по живым таблицам.
- Коммит только так (конфиг `git` не трогать):
  `GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com git commit -m "…"`.
  Пуш — только по явной команде человека, никогда с `-u`.
- Ворота (`bash scripts/gate.sh`) не ослабляются: падает PHPStan или фиксер — чинится код или текст
  плана, правки `ignore` и подавления запрещены.
- Тест сверяется с базой, а не с кодом самого теста; проверка, которая не способна покраснеть,
  чинится мутацией, а не новым утверждением.

## Карта файлов

Создаются:

| Файл | Ответственность |
| --- | --- |
| `core/templates/region.php` | обёртка зоны: `<section class="region region-<имя>">` + сырой `$content` |
| `apps/news/sql/20261016_0003_blocks.sql` | третий релиз: таблица блоков + две заводские строки |
| `apps/news/lib/blocks.php` | читатель зоны, валидаторы полей, семь операций записи, строки экрана и цель |
| `apps/news/actions/blocks.php` | разбор намерения формы: CSRF, `match ($op)`, возврат на экран |
| `apps/news/templates/admin/blocks.php` | экран «Блоки»: три таблицы зон + форма добавления |
| `apps/news/templates/partials/block.php` | вид блока на публичной странице: заголовок + сетка карточек |
| `tests/fixtures/theme/probe-region.php` | страница-фикстура: вызывает `region('home')` внутри `render()` |
| `tests/unit/NewsBlockRulesTest.php` | чистые правила: зоны, виды, пределы поля, цель блока |
| `tests/db/NewsBlocksTest.php` | читатель и операции на живой базе |
| `tests/http/NewsBlocksAdminTest.php` | экран блоков: вход, `no-store`, все девять действий, границы форм |

Меняются:

| Файл | Что появляется |
| --- | --- |
| `core/template.php` | `region()` в конце файла, после `richText()` |
| `src-css/app.css` | правило `.region` + пересборка `public/assets/app.css` |
| `apps/news/manifest.php:10` | `version` → `1.1.0` |
| `apps/news/lib/boot.php` | `require_once __DIR__ . '/blocks.php';` после `feed.php` |
| `apps/news/lib/constants.php` | `NEWS_BLOCK_ZONES/KINDS/PER_ZONE/TARGETS`, `BLOCK_FIELDS`, `NEWS_BLOCK_NOTICE_TEXT` |
| `apps/news/lib/admin.php` | задача 8: `NEWS_BLOCK_OPS` + подписи, `newsBlockRowActions()`; задача 9: адрес экрана, `NEWS_BLOCK_TARGET_MAX`, пятая вкладка |
| `apps/news/routes.php` | задача 5: `foreach` подписки зон после :54 и удаление строки :211; задача 9: подключение `actions/blocks.php` и два литеральных маршрута экрана |
| `apps/news/admin.php` | контроллер `newsAdminBlocks()` |
| `theme/home.php`, `apps/news/templates/{feed,category,tag,single}.php` | вызовы `region()`; в `single.php` — переезд «Читайте также» |
| `tests/unit/TemplateTest.php` | шесть проверок `region()` и фикстуры-страницы |
| `tests/db/NewsDbTestCase.php` | `news_block` в сбросе, фабрика `makeBlock()` |
| `tests/db/NewsSchemaTest.php` | шесть таблиц, три релиза, версия `1.1.0`, заводские строки |
| `tests/db/NewsRegistryTest.php`, `tests/http/NewsAssemblyTest.php` | версия и число релизов; в `NewsAssemblyTest` — пятый путь в списке админских экранов |
| `tests/http/NewsPublicTest.php` | «Похожие материалы» через блок |
| `tests/http/NoIndexNoStoreSweepTest.php` | строка плана для нового адреса + три выверенных числа объёма |
| `scripts/responsive-check.mjs` | адрес экрана блоков в списке `ADMIN_PAGES` |
| `tests/guard/AppActionContractTest.php` | третий файл действий в договоре |
| `tests/guard/NewsVisibilityTest.php` | новый номер строки замыкания подписки (вердикт Р3) |
| `tests/unit/NewsAdminRulesTest.php`, `tests/unit/NewsCatalogRulesTest.php` | пять вкладок, список адресов |
| `tests/db/NewsNoticeCoverageTest.php` | покрытие словаря блоков |

## Вердикты контроллера

Расхождения спецификации с деревом, закрытые до начала исполнения. Исполнитель их не переигрывает.

**Р1 — литеральные пути в маршрутах.** Спецификация §5 ссылается на
`CsrfCoverageTest::postHandlers()`, ссылающегося метода в дереве нет: страж читает живую
`routeTable()` через `postEndpointSources()` (`tests/guard/CsrfCoverageTest.php:267`). Пути всё
равно пишутся литералами — комментарий `apps/news/routes.php:30-33` объясняет тот же мотив: путь
константой не попадает в разбор POST-адресов, и адрес исчез бы из-под проверки CSRF молча.
В тексте плана причина названа достоверно.

**Р2 — покрытие версии.** Спецификация §6 отправляет проверку версии манифеста в
`tests/db/MigrationsTest.php`; этот набор говорит только про демо-раздел. Версию читают
`tests/db/NewsSchemaTest.php:74`, `tests/db/NewsRegistryTest.php:33,44` и
`tests/http/NewsAssemblyTest.php:70` — правки ложатся туда, `MigrationsTest.php` не трогается.

**Р3 — страж видимости.** `feedScope()` уже стоит в `READ_MARKERS` и `GUARD_MARKERS`
(`tests/guard/NewsVisibilityTest.php:20-21`); требовать его добавления нечего — `blockFeedRows()`
зовёт `feedScope()` и проходит проверку как делегат, белый список не пополняется. Единственная
правка набора — номер строки замыкания подписки карты сайтов в
`testНомерСтрокиЗамыканияСчитаетсяПоИсходномуТексту` (:122): он меняется в задаче 9, потому что
задача 9 добавляет строки в `apps/news/routes.php` выше этого замыкания (подключение файла
действий плюс маршруты экрана). Число берётся из сообщения падения стража, а не из арифметики:
`stripComments()` сохраняет нумерацию строк, но точный сдвиг зависит от того, сколько строк
написал исполнитель. Задача 5 подписку ставит ниже :54 и строку 48 не трогает.

**Р4 — цель блока читается по идентификатору.** Спецификация §4 говорит разрешать цель «через
`findCategory()` и `findTag()`», но обе принимают адрес (`slug`), а в `target_id` лежит
`id`. Разрешение делается прямым `one('SELECT slug … WHERE id = ?')` внутри `blocks.php`;
`findCategory()`/`findTag()` остаются там, где их читатель не касается.

**Р5 — обёртка зоны живёт partial'ом, класс имени зоны печатается.** Спецификация §1 показывает
`class="region region-<имя>"` внутри `region()`, а §6 требует этот же класс критерием приёмки.
Место литерала в §1 неверно: `StyleSourceTest::testРазметкаНеРастётВнеОбъявленныхКаталогов` (:97-113)
краснеет на `class=` в `core/template.php` — обратный замер задачи 1 это показал. Форма класса из §1
и §6 при этом достижима и обязательна: обёртка — `core/templates/region.php` с
`class="region region-<?= e($zone) ?>"`; переменная содержимого обязана называться `$content`
(страж `OutputEscapingTest::MARKED_VARS`). `classAttributeTokens()` (:445-463) токен
`region-<?= e($zone) ?>` отбрасывает по регэкспу (:453, `continue`, а не нарушение), поэтому правило
`.region-home` в `src-css/app.css` не нужно и мёртвым правилом не считается; в артефакт собирается
утилита `.region`. Отдельного атрибута `data-zone` в этом слое нет: имя зоны человек и проверки
читают из класса, и второй носитель того же имени — расходование разметки без требования.

**Р6 — «похожие» переезжают целиком.** Старый виджет `single.php:64-73`, переменная `$related`
(:11) и вычислитель `routes.php:211` удаляются в одной задаче с вызовом `region('article', …)`.
Вызов ставится безусловно, без `if ($open)`: `feedScope()`/`visibleWhere()` не пускают на печать
ни черновик, ни материал скрытой рубрики, а две формулировки одного правила — это дыра (мотив
комментария `apps/news/admin.php:5-7`).

**Р8 — читатель блоков зовёт существующие имена ленты.** «Границы слоя» запрещают переписывать
`inCategory()`, `relatedLinks()` и `categoryFeedRows()`, а удаление `routes.php:211` (Р6) оставляет
их без вызовов из прода. Первый вариант плана добивался этого копированием тела
`categoryFeedRows()` в `blockFeedRows()`: второй экземпляр тех же правил, против которого возражает
§8.2, и три функции всё равно неживые. Решение: сигнатура `blockFeedRows()` остаётся фильтром
массивом, как в §4, — рубричный случай (он же единственный случай с исключением) отдаётся
`inCategory()` или `relatedLinks()`, а общая лента и подборка по метке строятся поверх `feedScope()`,
где уже лежат условия видимости и соединение с тегами. Три имени остаются вызываемыми из раздела, их
тесты `tests/db/NewsFeedTest.php:210-241` не правятся, а страж `NewsVisibilityTest` по-прежнему видит
в `blockFeedRows()` маркер `feedScope(`.

**Р9 — цель блока: рубрика или метка, у `related` цели нет.** Черновик плана ограничивал цель
рубрикой и разрешал её обоим видам — это расходится с §2 (`target_kind` принимает `tag`) и с §5
(`kind = related` цели не имеет вовсе). Исправлено в задачах 3, 4 и 6: `blockTargetProblem()`
принимает `category` и `tag` у `list` и отказывает любой цели у `related`; читатель же для `related`
берёт рубрику и исключение только из `$data`, поэтому ветка «похожие + цель» в проде недостигнима, а
её появление в базе (строка, положенная мимо редактора) трактуется как «похожие рубрики материала».

**Р10 — жива ли цель, решает тот же резолвер, что зовёт страницу.** Спецификация §5 отправляет
проверку цели в `categoryExists()` и «существование тега». Первое имя в дереве есть
(`apps/news/lib/catalog.php:103`, принимает идентификатор), второго имени нет: тег рождает форма
материала (`resolveTagIds()`), и спрашивать про его существование приходится по `id` напрямую.
Заводить в задачах 6 пару `categoryExists()` + новая `tagExists()` — это второе определение «цель
жива» рядом с тем местом, где её читает страница: `blockTargetSlug()` (задача 4) уже отличает
«цель есть» от «цели нет», и если бы писатель спрашивал о другом, блок мог бы записаться и молча
не напечататься. Задача 6 ставит `blockTargetExists()` поверх `blockTargetSlug()`:
пустой адрес = цели нет. Требование §5 («выбранная цель обязана существовать, иначе отказ»)
сохранено дословно; меняется механизм, а не правило, и согласование писателя с читателем становится
теоремой, а не договорённостью.

**Р11 — отказ «неизвестное намерение» берётся у справочников.** Спецификация §5 требует для
неизвестного `op` общий текст отказа и называет `catalogUnknown()`. Функция живёт в
`apps/news/actions/catalog.php:65`, а не в `lib/` — третья копия фразы в `actions/blocks.php` дала
бы три места правки одного текста, а перенос `catalogUnknown()` в `lib/admin.php` трогает
работающий код вне скоупа задачи. Файл действий блоков подключается в `routes.php` строкой ниже
`:11`, то есть после `actions/catalog.php`, и вызывает функцию только внутри замыкания на момент
POST — объявление к этому моменту гарантированно существует. Та же причина у `catalogFailure()`,
которая стоит в `lib/admin.php:239`.

**Р12 — строка с необъявленной зоной не печатается и не редактируется.** §2 допускает в
`zone` только три значения, но выборка `newsBlockRows()` идёт по строке `zone = ?`, и `adminBlockRows()`
читает все строки сразу: в базу могла попасть строка с зоной, которой перечень не знает (положенная
мимо редактора или оставшаяся после свёртки перечня). Решение: и читатель, и экран фильтруют по
`NEWS_BLOCK_ZONES`, а не по содержимому таблицы. Без этого правила строка с мёртвой зоной легла бы
в таблицу первой зоны на экране и получила бы кнопки перемещения внутри чужого порядка.

**Р13 — цель одним полем и свой потолок длины.** §5 показывает у строки редактирования цели
один контрол, а в базе лежат два столбца (`target_kind`, `target_id`). Форма отдаёт составное
значение `category:<id>`, и `parseBlockTarget()` расщепляет его по первому двоеточию — идентификатор
рубрики и метки двоеточия не содержат, поэтому splitting по `:` без лимита потерял бы хвост
значения. Потолок длины у этого поля отдельный: `NEWS_ID_MAX = 26`
(`apps/news/lib/items.php:15`) режет только идентификатор, а составное значение длиннее его на имя
вида с двоеточием — для 26 символов это 36. Введён `NEWS_BLOCK_TARGET_MAX = 64`: запас покрывает
имя вида, идентификатор и мусор, принесённый из POST, и при этом заметно меньше предела
`NEWS_ADMIN_BACK_MAX`. Значение режется этим потолком до расщепления — та же механика, что у
идентификатора в `actions/catalog.php:25`. Обрезка не способна подставить вместо принесённого
несуществующую рубрику: отсечь может только хвост длиннее 64 символов, а у урезанного значения
часть после двоеточия заведомо длиннее 26 символов, то есть не совпадёт ни с одним настоящим
идентификатором, и `blockTargetExists()` откажет.

**Р14 — строки экрана имеют точную форму, а не `array<string, mixed>`.** Черновик задачи 8 выводил
`groupBlockRows()` как `array<string, list<array<string, mixed>>>`, а задача 9 передаёт строку этого
массива в `newsBlockRowActions()`, объявленную параметром формы `array{id: string, zone: string,
kind: string, …}`. На level 6 PHPStan отказывает передать широкую форму в узкую, поэтому экран
краснел бы на каждой таблице. Аннотации `adminBlockRows()`, `groupBlockRows()` и
`newsBlockRowActions()` записаны одной и той же формой — это одно определение строки блока, а не
три. Цена: форма длинная, и её правка затрагивает три документа. Выгода: расхождение «что лежит в
строке» ловится анализатором до запуска теста.

**Р15 — план установки содержит три релиза, а не два.** (закрыт по ходу задачи 2) Шаг RED задачи 2
обещал падения только на двух строках версии, хотя `planApp()` после третьего файла обязана отдать три
неприменённых имени — без правки `NewsRegistryTest` набор `db` не зеленеет, и шаг противоречил
собственному правилу идемпотентности. Ожидание остаётся точным списком из трёх имён, а не подмножеством.

**Р16 — закладка сверяется колонка в колонку с релизом.** Рецензия задачи 2 (важная №1): проверка
заводских блоков выбирала 4 колонки из 11, поэтому порча `take_limit` (5 → 7 против §2 «по пять
материалов»), проставленная цель (`NULL, NULL` → `'category', …`, от чего читатель задачи 4 уходит из
общей ленты), `order_index` (0 → 5, от чего считается следующая позиция в задаче 6) и самые литеры `id`
оставляли ворота зелёными. Глобальное правило плана — «проверка, которая не способна покраснеть, чинится
мутацией», — здесь не выполнен. Правка в шаге f: выбираются `id, zone, kind, title, target_kind,
target_id, take_limit, visible, order_index` и ожидаются две полные строки; `created_at`/`updated_at`
не сверяются, их конкретное время — деталь закладки, а не контракт. Цена: три строки мутаций в шаге 8
обязаны окраситься, и они проверяются прогоном, а не рассуждением.

**Р17 — индекс сверяется по составу колонок, а не по одному имени.** Рецензия задачи 2 (важная №2):
комментарий проверки обещал покрытие выборки зоны, но `assertContains` по `DISTINCT INDEX_NAME` не
видит порядка колонок — `KEY (order_index, zone)`, `(zone)` и `(zone, visible)` называются как нужно
и ворота зелёны, хотя `WHERE zone = ? … ORDER BY order_index` (§2) не обслуживают. Правка в шаге f:
читаются `INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME` из `information_schema.STATISTICS`, упорядоченный
состав сравнивается `assertSame` для `news_block_zone_order → [zone, order_index]` и `PRIMARY → [id]`.
Цена: проверка становится чувствительной к перестановке колонок (мутация в шаге 8).

---

### Задача 1: Точка вывода в ядре

**Файлы:**
- Изменить: `core/template.php` (функция `region()` в конце файла, после `richText()`)
- Создать: `core/templates/region.php` (обёртка зоны)
- Изменить: `src-css/app.css` (правило `.region`) и пересобрать `public/assets/app.css`
- Создать: `tests/fixtures/theme/probe-region.php` (страница-фикстура, зовущая `region()`)
- Тест: `tests/unit/TemplateTest.php` (шесть проверок)

**Интерфейсы:**
- Потребляет: `core/hooks.php`: `addFilter()`, `applyFilters()`, `hooksReset()`;
  `core/template.php`: `partial()`, `resolveTemplate()`; `core/escape.php`: `e()`
- Производит:

```
region(string $name, array $data = []): void
```

Печатающая функция, а не строка: точка вывода стоит внутри шаблона и обязана встать в общий поток
страницы. `region()` не знает ни одного имени зоны — список живёт в приложении (решение 2
спецификации).

**Четыре правила этой задачи:**

1. **Пустая зона не печатает ни байта.** Сверка идёт по `trim()`: подписчик, вернувший перевод
   строк, не рисует полосу с линией сверху.
2. **Обёртка — одна на зону.** Содержимое нескольких подписчиков сливается фильтром и получает
   одну рамку; порядок чтения — по приоритету, как его отдаёт `addHook()` (`core/hooks.php:24-28`).
3. **Разметка живёт partial'ом, а не кодом** (вердикт Р5). `core/template.php` не печатает литерал
   `class=`, иначе краснеет `StyleSourceTest::testРазметкаНеРастётВнеОбъявленныхКаталогов`.
4. **Сырой HTML проходит неэкранированным** и помечается `/* @safe html */`: его производят шаблоны
   раздела из своих partial'ов, пользовательского ввода в нём нет. Имя переменной обязано быть
   `$content` — именно его читает `OutputEscapingTest::MARKED_VARS`.

- [ ] **Шаг 1: Написать падающие проверки `region()`**

Сначала страница-фикстура, через которую шестая проверка попадёт в полный каркас темы. Каталог
`tests/fixtures/theme` уже подключён к иерархии шаблонов настройкой `EXTRA_THEME_DIR`
(`tests/bootstrap.php:16`), и `resolveTemplate()` найдёт этот файл раньше настоящей темы
(`core/template.php:28-30`): править `theme/` ради проверки не нужно.

`tests/fixtures/theme/probe-region.php`:

```php
<?php

declare(strict_types=1);

// Фикстуре достаточно самой простой страницы: один вызов region() внутри render(), чтобы вывод зоны
// встал в строку рядом с каркасом темы. Классов здесь нет: `tests` не входит в
// StyleSourceTest::MARKUP_DIRS, и разметка отсюда в артефакт стилей не просится.
?>
<p>тело страницы</p>
<?php region('home'); ?>
```

Затем в `tests/unit/TemplateTest.php` добавить шесть методов в конец класса (подписка на хук
сбрасывается `hooksReset()` в существующем `setUp()` — на этом наборе уже держится изоляция):

```php
    public function testПустаяЗонаНеПечатаетНиБайта(): void
    {
        // Подписчика нет в сборке без раздела: страница обязана остаться ровно такой, какой была
        // до вызова. Обёртка «для пустоты» нарисовала бы посередине полосы пустую рамку.
        ob_start();
        region('home');

        self::assertSame('', (string) ob_get_clean());
    }

    public function testЗонаСПробеламиВместоСодержимогоМолчит(): void
    {
        // «Ничего не нашлось» подписчик возвращает переводом строк, и это тоже пустота:
        // решение 5 спецификации проверяется именно на границе trim(), а не на пустой строке.
        addFilter('region_home', static fn (string $html): string => "\n  \t ");

        ob_start();
        region('home');

        self::assertSame('', (string) ob_get_clean(), 'Обёртка печатается только вокруг содержимого');
    }

    public function testЗонаПечатаетОбёрткуИхСодержимоеСырьём(): void
    {
        addFilter('region_home', static fn (string $html): string => $html . '<p>один</p>');

        ob_start();
        region('home');
        $out = (string) ob_get_clean();

        self::assertStringContainsString('<section class="region region-home">', $out);
        self::assertStringContainsString('<p>один</p>', $out, 'Разметка подписчика не экранируется');
        self::assertSame(1, substr_count($out, '<section class="region region-'), 'Одна зона — одна обёртка');
    }

    public function testПодписчикПолучаетИмяЗоныИКонтекстСтраницы(): void
    {
        // Ядро не знает, зачем подписчику имя зоны и адрес материала, — но без этих двух
        // аргументов раздел не различил бы три свои точки и не собрал бы «похожие» в нужной
        // рубрике (решение 7 спецификации: третий аргумент нужен именно переезду виджета).
        addFilter('region_article', static fn (string $html, string $zone, array $data): string => $html
            . '<i>' . e($zone) . '</i><b>' . e((string) $data['slug']) . '</b>');

        ob_start();
        region('article', ['slug' => 'itog-goda']);
        $out = (string) ob_get_clean();

        self::assertStringContainsString('<i>article</i>', $out);
        self::assertStringContainsString('<b>itog-goda</b>', $out);
    }

    public function testНесколькоПодписчиковСходятсяВОднуОбёртку(): void
    {
        // Подписок у зоны может быть больше одного, и порядок обязан читаться по приоритету:
        // иначе раздел не сможет встать раньше темы, а обёрток станет две на один вызов.
        addFilter('region_home', static fn (string $html): string => $html . '<p>ранний</p>', 5);
        addFilter('region_home', static fn (string $html): string => $html . '<p>поздний</p>', 20);

        ob_start();
        region('home');
        $out = (string) ob_get_clean();

        self::assertSame(1, substr_count($out, '<section class="region region-'));
        self::assertLessThan(
            (int) strpos($out, '<p>поздний</p>'),
            (int) strpos($out, '<p>ранний</p>'),
            'Более ранний приоритет обязан читаться первым'
        );
    }

    public function testВыводЗоныПопадаетВСтрокуКоторуюВозвращаетRenderToString(): void
    {
        // Единственный канал ответа ядра — строка, собранная renderToString() (core/template.php:103),
        // и экраны отказа уходят в respond() именно ею. Печатающая точка вывода обязана донести зону
        // и туда: иначе отказный экран и любой ответ-строка вышли бы без блоков (§6 спецификации).
        addFilter('region_home', static fn (string $html): string => $html . '<p>в строке</p>');

        $html = renderToString('probe-region');

        self::assertStringContainsString('<p>тело страницы</p>', $html, 'Страница-фикстура не собрана');
        self::assertStringContainsString('<main id="main"', $html, 'Каркас темы обязан остаться целым');
        self::assertStringContainsString('<section class="region region-home">', $html);
        self::assertStringContainsString('<p>в строке</p>', $html, 'Содержимое подписчика потерялось');
    }
```

- [ ] **Шаг 2: Прогнать и убедиться, что проверки красные**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter TemplateTest`
Ожидание: шесть падений `Call to undefined function region()` — в последних двух оно приходит из
шаблона-фикстуры, куда его зовёт `render()`; остальные проверки файла — зелёные.

- [ ] **Шаг 3: Создать обёртку зоны**

`core/templates/region.php`:

```php
<?php

declare(strict_types=1);

/**
 * Обёртка точки вывода. Разметка живёт здесь, а не в коде `region()`: сборка стилей читает
 * каталог `core/templates` (страж `StyleSourceTest::MARKUP_DIRS`), а литеральный `class=` внутри
 * `core/template.php` краснеет в его же проверке «разметка не растёт вне объявленных каталогов».
 *
 * @var string $zone
 * @var string $content
 */
?>
<section class="region region-<?= e($zone) ?>">
<?= $content /* @safe html */ ?>
</section>
```

- [ ] **Шаг 4: Добавить `region()` в ядро**

В `core/template.php` после `richText()` (файл кончается на :154) дописать:

```php
// Точка вывода, которую приложение наполняет своим содержимым: ядро знает только имя зоны.
// Печатает, а не возвращает строку, — вызов стоит внутри шаблона и обязан встать в общий поток
// страницы. Пустая зона не печатает ни байта, включая обёртку: сайт без раздела не обрастает
// пустыми рамками (решение 5 спецификации).
/**
 * @param array<string, mixed> $data контекст страницы для подписчика: адрес материала, рубрика
 */
function region(string $name, array $data = []): void
{
    $html = (string) applyFilters('region_' . $name, '', $name, $data);

    if (trim($html) === '') {
        return;
    }

    echo partial('region', ['zone' => $name, 'content' => $html]);
}
```

Функция дописывается в конец файла: спецификация ссылается на `core/template.php:64-94`, `:75-77`
и `:103-109`, и правка в середине сдвинула бы эти якоря.

- [ ] **Шаг 5: Добавить правило стиля и пересобрать артефакт**

Сначала — замер до правки, чтобы цена нового правила была названа числом, а не оценкой (§6
спецификации требует снять вес артефакта до и после):

Run: `wc -c src-css/app.css public/assets/app.css`

В `src-css/app.css` после правила `section[id]` (строка :28) вставить:

```css
.region {
  border-top: 1px solid rgba(43, 33, 24, 0.1);
  margin-top: 2rem;
  padding-top: 1.5rem;
}
```

Run: `bash scripts/css`
Run после сборки: `wc -c src-css/app.css public/assets/app.css`

Ожидание: `public/assets/app.css` пересобран (шаг свежести ворот сверяет его с `cmp -s`, поэтому
пропущенная пересборка краснеет сама). Обе строки `wc -c` и разницу между ними исполнитель переносит
в отчёт задачи дословно: рост артефакта фиксируется числом в отчёте, а не новым бюджетом стража.
`AssetWeightTest` считает весом только картинки, его числа предписаны сверху, и шапка файла прямо
запрещает автору прогона менять их «чтобы пролезло» — заводить на одно правило CSS бюджет означало бы
ослабить страж.

- [ ] **Шаг 6: Прогнать зелёный результат**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l core/template.php && php -l core/templates/region.php && php -l tests/fixtures/theme/probe-region.php"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter TemplateTest`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "StyleSourceTest|OutputEscapingTest"`
Ожидание: всё зелёное, шесть новых проверок пройдены.

- [ ] **Шаг 7: Шесть обратных замеров**

Каждый — правка, затем прогон, затем откат. Замер без дословного вывода инструмента находку не
закрывает.

| Мутация | Что обязано покраснеть |
| --- | --- |
| убрать `trim()` (`if ($html === '')`) | `testЗонаСПробеламиВместоСодержимогоМолчит` (пустая зона при `trim`-проверке молчит и дальше, поэтому вторая проверка не затрагивается) |
| печатать обёртку до проверки пустоты | `testПустаяЗонаНеПечатаетНиБайта` и `testЗонаСПробеламиВместоСодержимогоМолчит` — обе попадают в один дефект «обёртка раньше содержимого», замер фиксирует две красные строки, а не одну |
| вписать `<section class="region region-…">` прямо в `region()` вместо `partial('region', …)` | `StyleSourceTest::testРазметкаНеРастётВнеОбъявленныхКаталогов` |
| добавить правило `.region` и не запустить `bash scripts/css` | шаг свежести артефакта в `bash scripts/gate.sh` + `StyleSourceTest::testКлассыРазметкиСобралисьВАртефакте` |
| убрать класс из `core/templates/region.php`, оставив правило в `src-css/app.css` | `StyleSourceTest::testКлассыИсточникаСтилейИспользуютсяВРазметке` (мёртвое правило) |
| убрать вызов `region('home')` из `tests/fixtures/theme/probe-region.php` | `testВыводЗоныПопадаетВСтрокуКоторуюВозвращаетRenderToString`, а остальные пять остаются зелёными: проверка держит именно связку точки вывода со собранной страницей, а не повторяет проверку 3 |

- [ ] **Шаг 8: Ворота**

Run: `bash scripts/gate.sh`
Ожидание: пять шагов зелёные. Падающий PHPStan или фиксер чинится кодом этого шага, а не
`ignore`-правкой.

- [ ] **Шаг 9: Коммит и отчёт**

```bash
git add core/template.php core/templates/region.php src-css/app.css public/assets/app.css tests/unit/TemplateTest.php tests/fixtures/theme/probe-region.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Ядро: точка вывода region() с обёрткой зоны и правилом стиля"
```

В отчёт: дословные строки прогонов шагов 2, 6, 7, 8 и хеш коммита.

---

### Задача 2: Третий релиз схемы и версия раздела

**Файлы:**
- Создать: `apps/news/sql/20261016_0003_blocks.sql`
- Изменить: `apps/news/manifest.php:10` (`'version' => '1.0.0'` → `'1.1.0'`)
- Изменить: `tests/db/NewsSchemaTest.php` (шесть таблиц, три релиза, версия, две новые проверки)
- Изменить: `tests/db/NewsRegistryTest.php:33,44` и `tests/http/NewsAssemblyTest.php:70-71`
- Изменить: `tests/db/NewsDbTestCase.php` (таблица блоков в сбросе и в очистке)

**Интерфейсы:**
- Потребляет: `core/apps.php`: `installApp()`, `planApp()`, `appInstallation()`; `core/db.php`:
  `t()`, `run()`, `value()`, `one()`, `all()`; `Cms\Tests\TestDb::reset()`
- Производит: таблицу `tbl_news_block` с колонками §2 спецификации и двумя заводскими строками —
  `article/related` и `home/list`. Дальше её читают `blocks.php` (задача 4) и операции (задача 6).

**Правила этого релиза:**

1. **Идемпотентность обязательна.** `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`: MySQL фиксирует
   DDL до записи в журнал, поэтому прерванный релиз повторяется по живым таблицам, и существующая
   проверка `testПовторныйПрогонВсехРелизовНеОтказывает` пройдёт по новому файлу сама.
2. **Заводские строки — в релизе, а не в `seed.php`.** `seedApp()` вызывает `seed.php` и перед
   каждым тестом, и при установке, а фабрика блоков обязана появляться ровно вместе со схемой
   (§2.4 спецификации). Из-за этого `clearNews()` в подставке тестов очищает и блоки, и следующие
   задачи заводят нужные строки сами — полагаться на закладку нельзя.
3. **Имена литералами.** В файле релиза написано `tbl_news_block`, а не префикс из `t()`: релизы
   исполняются ядром как есть, и так же, как в двух прежних файлах раздела.
4. **Внешних ключей нет.** Блок переживает удаление рубрики: цель может исчезнуть, и тогда
   читатель не печатает блок, а не роняет страницу (решение 8 спецификации).

- [ ] **Шаг 1: Переписать падающие проверки схемы**

В `tests/db/NewsSchemaTest.php`:

a) `testВсеПятьТаблицРазделаСозданы` переименовать в `testВсеШестьТаблицРазделаСозданы`; тело не
меняется, меняется только `tables()` — `existingTables()` сортирует имена, поэтому блок первый:

```php
    /** @return list<string> */
    private static function tables(): array
    {
        return [
            t('news_block'),
            t('news_category'),
            t('news_item'),
            t('news_item_tag'),
            t('news_old_slug'),
            t('news_tag'),
        ];
    }
```

b) `testЖурналПомнитОбаРелизаВПорядкеОчереди` переименовать в `testЖурналПомнитТриРелизаВПорядкеОчереди`
и расширить ожидание:

```php
        self::assertSame(
            ['20261006_0001_catalog.sql', '20261006_0002_items.sql', '20261016_0003_blocks.sql'],
            $names
        );
```

c) `testПовторныйПрогонОбоихРелизовНеОтказывает` переименовать в
`testПовторныйПрогонВсехРелизовНеОтказывает`; в его комментарии слово «обоих» заменяется на «всех»
(файлов стало три), тело остаётся дословно — оно берёт `glob` по каталогу и сверяет `rowCounts()`.

d) В `testРазделЧислитсяУстановленнымИГотовымКРаботе` (`:74`) ожидание версии → `'1.1.0'`.

e) В `dropNewsTables()` в начало списка добавить `'news_block'` — таблица должна исчезать вместе с
разделом, иначе следующий прогон `installApp()` найдёт живую таблицу.

f) Две новые проверки — в конец класса, перед `existingTables()`:

```php
    public function testЗаводскиеБлокиСтавятсяВместеСТаблицей(): void
    {
        // Закладка нужна, чтобы у только что установленного раздела главная и карточка сразу что-то
        // выводили; человек при этом видит два готовых блока и правит их, а не гадает, где они.
        // Сверяются все колонки, которые задаёт релиз: предел выборки, цель и порядок — тоже контракт
        // закладки, иначе правка `take_limit` или проставленная цель ворота не окрасят.
        // `created_at`/`updated_at` не сверяются: их конкретное время — деталь закладки, а не контракт.
        $rows = all('SELECT id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index FROM ' . t('news_block') . ' ORDER BY zone, order_index');

        self::assertSame(
            [
                [
                    'id' => 'newsblk-article',
                    'zone' => 'article',
                    'kind' => 'related',
                    'title' => 'Похожие материалы',
                    'target_kind' => null,
                    'target_id' => null,
                    'take_limit' => 5,
                    'visible' => 1,
                    'order_index' => 0,
                ],
                [
                    'id' => 'newsblk-home',
                    'zone' => 'home',
                    'kind' => 'list',
                    'title' => 'Свежие материалы',
                    'target_kind' => null,
                    'target_id' => null,
                    'take_limit' => 5,
                    'visible' => 1,
                    'order_index' => 0,
                ],
            ],
            $rows
        );
    }

    public function testИндексЗоныБлоковНаМесте(): void
    {
        // Читатель зоны выбирает `WHERE zone = ? AND visible = 1 ORDER BY order_index`, и без
        // составного индекса каждый показ страницы сканировал бы таблицу блоков целиком. Сверяется
        // состав и порядок колонок, а не только имя: `KEY (order_index, zone)` названием не отличается
        // от нужного, но зоне не служит.
        $rows = all(
            'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [(string) configValue('DB_NAME'), t('news_block')]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        self::assertSame(['id'], $columns['PRIMARY'] ?? []);
        self::assertSame(['zone', 'order_index'], $columns['news_block_zone_order'] ?? []);
    }
```

- [ ] **Шаг 2: Прогнать и убедиться, что проверки красные**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsSchemaTest`
Ожидание: падения на отсутствующей таблице (расхождение `Existing tables`, `SQLSTATE[42S02]` в двух
новых проверках, журнал помнит два релиза, версия `1.0.0`).

- [ ] **Шаг 3: Написать файл релиза**

`apps/news/sql/20261016_0003_blocks.sql`:

```sql
-- Таблица блоков вывода раздела. IF NOT EXISTS и INSERT IGNORE обязательны: MySQL фиксирует DDL
-- сразу, а журнал пишется после файла, поэтому недочитанный релиз повторяется по живым таблицам.
-- Внешних ключей нет намеренно (решение 8): блок переживает удаление своей цели.
CREATE TABLE IF NOT EXISTS tbl_news_block (
    id VARCHAR(26) NOT NULL,
    zone VARCHAR(32) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    title VARCHAR(120) NOT NULL,
    target_kind VARCHAR(16) NULL,
    target_id VARCHAR(26) NULL,
    take_limit TINYINT NOT NULL DEFAULT 5,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    order_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY news_block_zone_order (zone, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Две заводские строки: карточка материала с «похожими» и главная со свежими. Идентификаторы
-- короче обычных 26 символов newId() намеренно: они литеры релиза, а не порождение генератора,
-- и по PRIMARY KEY конфликтуют только с собой, чего `INSERT IGNORE` и не допускает.
INSERT IGNORE INTO tbl_news_block (id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index, created_at, updated_at)
    VALUES ('newsblk-article', 'article', 'related', 'Похожие материалы', NULL, NULL, 5, 1, 0, '2026-10-16 00:00:00', '2026-10-16 00:00:00');

INSERT IGNORE INTO tbl_news_block (id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index, created_at, updated_at)
    VALUES ('newsblk-home', 'home', 'list', 'Свежие материалы', NULL, NULL, 5, 1, 0, '2026-10-16 00:00:00', '2026-10-16 00:00:00');
```

- [ ] **Шаг 4: Поднять версию раздела**

`apps/news/manifest.php:10`: `'version' => '1.0.0',` → `'version' => '1.1.0',`.
Версия обязана вырасти вместе с релизом схемы — кнопка «Обновить» в реестре появляется по
расхождению `manifestVersion` и версии в `tbl_app_installation`.

- [ ] **Шаг 5: Прогнать остальные затронутые проверки**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter "NewsSchemaTest|NewsRegistryTest"`
Ожидание: `NewsSchemaTest` зелёный; `NewsRegistryTest` красный в трёх местах — две строки версии
(`:33`, `:44`) и `testПланУстановкиСодержитОбаРелиза`: `planApp()` после появления файла релиза
отдаёт три неприменённых имени вместо двух.
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsAssemblyTest`
Ожидание: красный на `'1.0.0'` (`:70`) и на `2` применённых релизах (`:71`).

В `tests/db/NewsRegistryTest.php` правятся четыре значения: `assertSame('1.0.0', (string) $manifest['version'])`
→ `'1.1.0'`, `assertSame('1.0.0', $state['manifestVersion'])` → `'1.1.0'`. Плюс переименование
`testПланУстановкиСодержитОбаРелиза` → `testПланУстановкиСодержитТриРелиза` и третий элемент
ожидания — `'20261016_0003_blocks.sql'` (список остаётся точным, а не подмножеством). Остальные
проверки файла (`requiresCore`, `dbVersion`, `state`) не трогаются.

В `tests/http/NewsAssemblyTest.php` правятся две строки: версия → `'1.1.0'`, счётчик журнала → `3`.

- [ ] **Шаг 6: Добавить таблицу блоков в тестовую подставку**

В `tests/db/NewsDbTestCase.php` — два списка и ни одного нового метода:

```php
        foreach (['news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category', 'news_block'] as $table) {
            run('DELETE FROM ' . t($table));
        }
```

в `clearNews()` (сюда), и `'news_block'` первым в список `dropNewsTables()`
(`['news_block', 'news_item_tag', 'news_old_slug', 'news_item', 'news_tag', 'news_category']`).
Комментарий над `clearNews()` дополняется одной строкой: закладка релиза после первой очистки
не возвращается (`seedApp()` сеет только рубрики), поэтому задачи 4 и 6 заводят блоки сами.

- [ ] **Шаг 7: Прогнать зелёный результат**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/manifest.php"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsAssemblyTest`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "AppSqlTest|SqlSafetyTest"`
Ожидание: всё зелёное. `AppSqlTest` и `SqlSafetyTest` обходят `apps/*/sql/*.sql` `glob`-ом, поэтому
новый файл попадает под их проверку сам, правок этих двух стражей задача не требует.

- [ ] **Шаг 8: Обратные замеры**

| Мутация | Что обязано покраснеть |
| --- | --- |
| убрать `IF NOT EXISTS` | `testПовторныйПрогонВсехРелизовНеОтказывает` (`SQLSTATE[42S01]`) |
| убрать `INSERT IGNORE` (оставить `INSERT`) | тот же тест: дублирование PRIMARY KEY |
| убрать `KEY news_block_zone_order` | `testИндексЗоныБлоковНаМесте` |
| переставить колонки: `KEY news_block_zone_order (order_index, zone)` | `testИндексЗоныБлоковНаМесте` (сравнение упорядоченного состава) |
| `take_limit` `5` → `7` в одной заводской строке | `testЗаводскиеБлокиСтавятсяВместеСТаблицей` |
| цель в одной заводской строке: `NULL, NULL` → `'category', 'newsblk-home'` | тот же тест |
| `order_index` `0` → `5` в одной заводской строке | тот же тест |
| оставить версию манифеста `1.0.0` при новом релизе | `testРазделЧислитсяУстановленнымИГотовымКРаботе`, `NewsRegistryTest`, `NewsAssemblyTest` |

В таблице восемь строк: четыре были в плане изначально (убрать `IF NOT EXISTS`, убрать
`INSERT IGNORE`, убрать `KEY`, оставить старую версию манифеста), ещё четыре добавлены по рецензии
задачи 2 (вердикты Р16 и Р17): перестановка колонок индекса и три значения заводской строки
(`take_limit`, цель, `order_index`). Проверка закладки сверяет все колонки, которые задаёт релиз,
поэтому порча этих значений обязана окраситься. Каждый прогон — ровно одна правка файла релиза,
с дословным выводом падения и последующей откачкой.

- [ ] **Шаг 9: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

```bash
git add apps/news/sql/20261016_0003_blocks.sql apps/news/manifest.php tests/db/NewsSchemaTest.php tests/db/NewsRegistryTest.php tests/db/NewsDbTestCase.php tests/http/NewsAssemblyTest.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Раздел «Новости»: релиз блоков вывода и версия 1.1.0"
```

---

### Задача 3: Константы раздела и чистые правила блока

**Файлы:**
- Изменить: `apps/news/lib/constants.php` (зоны, виды, предел на зону, поля, словарь текстов)
- Создать: `apps/news/lib/blocks.php` (пока только чистые правила: разбор полей и цели)
- Изменить: `apps/news/lib/boot.php:12` (подключение `blocks.php` сразу после `feed.php`)
- Изменить: `tests/unit/NewsConstantsTest.php:54` (словарь блоков в перечень проверяемых)
- Тест: `tests/unit/NewsBlockRulesTest.php`

**Интерфейсы:**
- Потребляет: `apps/news/lib/constants.php`: `NEWS_PER_PAGE`, `fieldLimit()`;
  `apps/news/lib/catalog.php`: `collapseSpace()`
- Производит:

```
const NEWS_BLOCK_ZONES = ['home', 'category', 'article'];
const NEWS_BLOCK_KINDS = ['list', 'related'];
const NEWS_BLOCK_TARGETS = ['category' => 'Рубрики', 'tag' => 'Теги'];
const NEWS_BLOCK_PER_ZONE = 6;
const BLOCK_FIELDS = ['title' => ['min' => 2, 'max' => 120], 'take' => ['min' => 1, 'max' => 12]];
const NEWS_BLOCK_NOTICE_TEXT = ['created', 'renamed', 'retargeted', 'limited', 'hidden',
                               'shown', 'moved', 'deleted', 'topped', 'bottomed']; // тексты — шаг 3

checkBlockFields(string $rawZone, string $rawKind, string $rawTitle, int $rawTake): array{zone: string, kind: string, title: string, take: int, error: string}
blockTargetProblem(string $kind, string $targetKind, string $targetId, bool $targetExists): string
```

Задачи 4 и 6 дописывают в этот же файл читатель и операции; имена и пределы, объявленные здесь,
больше не меняются.

**Правила этих констант:**

1. **`VARCHAR`, а не `ENUM`** (решение 8): новый вид зоны не требует правки схемы, а список
   допустимых значений живёт одной строкой в PHP и сверяется тестом с деревом ссылок.
2. **Предел числа материалов — тот же, что у ленты.** `NEWS_PER_PAGE` уже означает «сколько
   материалов человек читает на странице», и блок не имеет права просить больше: `takeLimit()`
   обрезает запрос к базе именно до этого числа, а форма, позволяющая большее, обещала бы то, что
   сервер всё равно урежет. Просьба сверх предела — отказ, а не тихое зажатие (§5 спецификации).
3. **Цель есть только у `list`, и она шире рубрики.** `list` без цели — общая лента; цель `list` —
   рубрика или метка (§2: `target_kind` принимает `category`, `tag` или `NULL`). У `related` цели нет
   вовсе (§5): этот вид уже определён как «рубрика материала за вычетом самого материала», и вторая
   настройка сделала бы из одного вида блока два разных со скрытым смыслом. Значение рубрики для
   `related` приходит из `$data` (решение 6).
4. **Правила не трогают базу.** Два этих чистых правила проверяются unit-набором без подключения к
   MySQL — так же, как правила справочников в `NewsCatalogRulesTest`.

- [ ] **Шаг 1: Написать падающий unit-тест правил**

Создать `tests/unit/NewsBlockRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Правила блока — про договор, а не про базу: какие зоны и виды вообще существуют, какой предел
// у числа материалов и когда цель блока признана. База здесь не нужна, и её нет: тест обязан
// падать на правке правила, а не на состоянии таблиц.
final class NewsBlockRulesTest extends TestCase
{
    public function testЗоныИВидыПеречисленыОднимСписком(): void
    {
        // Список зон — единственное место, где ядро и раздел договариваются об именах: читатель
        // зоны, подписка в маршрутах и форма экрана берут его отсюда.
        self::assertSame(['home', 'category', 'article'], NEWS_BLOCK_ZONES);
        self::assertSame(['list', 'related'], NEWS_BLOCK_KINDS);
        self::assertSame(6, NEWS_BLOCK_PER_ZONE, 'Больше шести блоков на зоне страница читается простынёй');
        self::assertSame(['category' => 'Рубрики', 'tag' => 'Теги'], NEWS_BLOCK_TARGETS,
            'Целью блока бывают только рубрика и метка (§2 спецификации), и этот словарь читает и проверка, и выбор формы');
    }

    public function testПоляБлокаОбъявленыДляФормыИСервера(): void
    {
        self::assertSame(2, fieldLimit(BLOCK_FIELDS, 'title', 'min'));
        self::assertSame(120, fieldLimit(BLOCK_FIELDS, 'title', 'max'));
        // Предел `take` обязан совпадать с пределом страницы: `takeLimit()` режет по NEWS_PER_PAGE,
        // и форма, позволяющая большее, обещала бы то, что сервер всё равно урежет.
        self::assertSame(NEWS_PER_PAGE, fieldLimit(BLOCK_FIELDS, 'take', 'max'));
        self::assertSame(1, fieldLimit(BLOCK_FIELDS, 'take', 'min'));
    }

    public function testРазборПолейБерётЗначенияИзСписков(): void
    {
        $checked = checkBlockFields('home', 'list', '  Свежие   материалы ', 3);

        self::assertSame('', $checked['error']);
        self::assertSame('home', $checked['zone']);
        self::assertSame('list', $checked['kind']);
        self::assertSame('Свежие материалы', $checked['title'], 'Лишние пробелы внутри и по краям схлопываются');
        self::assertSame(3, $checked['take']);
    }

    public function testНеизвестнаяЗонаИлиВидОтказываются(): void
    {
        self::assertNotSame('', checkBlockFields('sidebar', 'list', 'Заголовок', 4)['error']);
        self::assertNotSame('', checkBlockFields('home', 'carousel', 'Заголовок', 4)['error']);
    }

    public function testНазваниеБлокаПоПределу(): void
    {
        self::assertNotSame('', checkBlockFields('home', 'list', 'кот', 4)['error'], 'Двух символов мало');
        self::assertSame('', checkBlockFields('home', 'list', 'Кот', 4)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', '', 4)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', str_repeat('а', 121), 4)['error']);
    }

    public function testЧислоМатериаловВнеПределаОтказывается(): void
    {
        // Ноль и 999 — отказ, а не тихое зажатие: блок с нулём на экране выглядит поломкой,
        // а обещание больше страницы редактор не выполняет (§5 спецификации).
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', 0)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', -5)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', 999)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', NEWS_PER_PAGE + 1)['error']);

        $ok = checkBlockFields('home', 'list', 'Заголовок', 5);
        self::assertSame('', $ok['error']);
        self::assertSame(5, $ok['take'], 'Внутри предела значение сохраняется как принято');
    }

    public function testЦельРазрешенаУпискаИЗапрещенаУПохожих(): void
    {
        self::assertSame('', blockTargetProblem('list', '', '', false), 'Блок без цели — общая лента');
        self::assertSame('', blockTargetProblem('related', '', '', false), '«Похожие» берут рубрику материала');
        self::assertSame('', blockTargetProblem('list', 'category', 'id-рубрики', true));
        self::assertSame('', blockTargetProblem('list', 'tag', 'id-метки', true), 'Метка — тоже легальная цель');

        self::assertNotSame('', blockTargetProblem('list', 'category', 'нет-такой', false), 'Цель обязана существовать');
        self::assertNotSame('', blockTargetProblem('list', 'tag', 'нет-такой', false), 'Метка тоже обязана существовать');
        self::assertNotSame('', blockTargetProblem('related', 'category', 'id', true), 'У «похожих» цели нет');
        self::assertNotSame('', blockTargetProblem('related', 'tag', 'id', true), 'И меткой «похожие» не становятся');
        self::assertNotSame('', blockTargetProblem('list', 'user', 'id', true), 'Целью может быть только рубрика или метка');
        self::assertNotSame('', blockTargetProblem('list', '', 'id', false), 'Идентификатор без вида цели — обрывок формы');
    }
}
```

Там же, в `tests/unit/NewsConstantsTest.php:54`, словарь блоков добавляется в уже существующий
перечень: проверка «каждый текст кончается точкой и не содержит подстановок» обязана покрыть новый
словарь тем же кодом, которым покрыты два прежних, — иначе правило приходится на один словарь, а
остальные два живут без него.

```php
        $dictionaries = ['материал' => NEWS_NOTICE_TEXT, 'справочники' => CATALOG_NOTICE_TEXT, 'блоки' => NEWS_BLOCK_NOTICE_TEXT];
```

- [ ] **Шаг 2: Прогнать и убедиться, что тест красный**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter NewsBlockRulesTest`
Ожидание: `Undefined constant NEWS_BLOCK_ZONES`, затем `Call to undefined function checkBlockFields()`.

- [ ] **Шаг 3: Дописать константы**

В `apps/news/lib/constants.php` после `CATEGORY_FIELDS` / `TAG_FIELDS` (перед `OLD_SLUGS_MAX`)
добавить:

```php
// Точки вывода, о которых знают только «Новости»: ядро печатает вызов по имени зоны и не
// держит этот список (решение 2 спецификации). Порядок списков — порядок зон на экране «Блоки».
/** @var list<string> */
const NEWS_BLOCK_ZONES = ['home', 'category', 'article'];

// `list` — подборка материалов (свои или по рубрике), `related` — похожие из рубрики.
/** @var list<string> */
const NEWS_BLOCK_KINDS = ['list', 'related'];

// Что может быть целью блока и как этот вид называется на экране. Словарь один: его читает
// проверка цели (`blockTargetProblem()`), выбор форм цели (`blockTargetOptions()`) и подпись
// группы в `<select>`. Цели справочников и блоков совпадают намеренно (§2: `target_kind` =
// `category` / `tag` / `NULL`), и заводить второй список означало бы две правки на новую цель.
/** @var array<string, string> */
const NEWS_BLOCK_TARGETS = ['category' => 'Рубрики', 'tag' => 'Теги'];

// Шесть блоков на зону: больше — и страница превращается в простыню ссылок, а порядок строк
// перестаёт читаться без прокрутки.
const NEWS_BLOCK_PER_ZONE = 6;

// `take.max` обязан совпасть с пределом страницы: `takeLimit()` режет по NEWS_PER_PAGE, и форма,
// позволяющая больше, обещала бы то, что сервер всё равно урежет.
/** @var array<string, array<string, int>|int> */
const BLOCK_FIELDS = ['title' => ['min' => 2, 'max' => 120], 'take' => ['min' => 1, 'max' => 12]];

// Тексты успешных исходов экрана блоков. Ключи читаются плашкой по адресе, как у справочников.
/** @var array<string, string> */
const NEWS_BLOCK_NOTICE_TEXT = [
    'created' => 'Блок добавлен в конец своей зоны.',
    'renamed' => 'Заголовок блока обновлён.',
    'retargeted' => 'Цель блока обновлена: подборка берёт материалы из выбранной рубрики или метки.',
    'limited' => 'Число материалов в блоке обновлено.',
    'hidden' => 'Блок скрыт: на страницах его нет, в админке он остался и доступен снова.',
    'shown' => 'Блок снова виден на страницах.',
    'moved' => 'Порядок в зоне обновлён.',
    'deleted' => 'Блок удалён.',
    'topped' => 'Этот блок уже первый в зоне.',
    'bottomed' => 'Этот блок уже последний в зоне.',
];
```

- [ ] **Шаг 4: Создать файл правил**

`apps/news/lib/blocks.php` — заголовок и два чистых правила (задачи 4 и 6 допишут сюда читатель
и операции):

```php
<?php

declare(strict_types=1);

// Блоки вывода раздела: какие зоны бывают, сколько материалов просить и как задаётся цель.
// Файл подключается в lib/boot.php после feed.php: схлопывание пробелов берётся из
// `collapseSpace()` (lib/catalog.php), а выборка карточек — из `feedScope()` и `toCard()`
// (lib/feed.php), поэтому правило видимости ленты у блока остаётся одним и тем же.

/**
 * Разбор и проверка полей блока. Возвращает очищенные значения и текст отказа: пустая строка в
 * `error` означает «принято».
 *
 * Число материалов сверяется с тем же пределом, что держит лента (`NEWS_PER_PAGE`), но не
 * зажимается молча: просьба больше страницы или ноль — это отказ, а не тихо исправленное
 * намерение человека (§5 спецификации).
 *
 * @return array{zone: string, kind: string, title: string, take: int, error: string}
 */
function checkBlockFields(string $rawZone, string $rawKind, string $rawTitle, int $rawTake): array
{
    $zone = trim($rawZone);
    $kind = trim($rawKind);
    $title = collapseSpace($rawTitle);
    $take = $rawTake;

    if (!in_array($zone, NEWS_BLOCK_ZONES, true)) {
        return ['zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take, 'error' => 'Зоны с таким именем нет.'];
    }

    if (!in_array($kind, NEWS_BLOCK_KINDS, true)) {
        return ['zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take, 'error' => 'Такого вида блока нет.'];
    }

    if (mb_strlen($title) < fieldLimit(BLOCK_FIELDS, 'title', 'min') || mb_strlen($title) > fieldLimit(BLOCK_FIELDS, 'title', 'max')) {
        return [
            'zone' => $zone,
            'kind' => $kind,
            'title' => $title,
            'take' => $take,
            'error' => 'Название блока должно быть от ' . fieldLimit(BLOCK_FIELDS, 'title', 'min')
                . ' до ' . fieldLimit(BLOCK_FIELDS, 'title', 'max') . ' символов.',
        ];
    }

    if ($take < fieldLimit(BLOCK_FIELDS, 'take', 'min') || $take > fieldLimit(BLOCK_FIELDS, 'take', 'max')) {
        return [
            'zone' => $zone,
            'kind' => $kind,
            'title' => $title,
            'take' => $take,
            'error' => 'Число материалов должно быть от ' . fieldLimit(BLOCK_FIELDS, 'take', 'min')
                . ' до ' . fieldLimit(BLOCK_FIELDS, 'take', 'max') . '.',
        ];
    }

    return ['zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take, 'error' => ''];
}

/**
 * Проверка цели блока. Возвращает текст отказа; пустая строка — цель принята (или её нет).
 *
 * Три правила: цель бывает только у `list` (§5 спецификации), она ограничена рубрикой и меткой (§2:
 * `target_kind` = `category` / `tag` / `NULL`) и выбранная цель обязана существовать. `targetId` без
 * `targetKind` — обрывок формы (человек снял выбор, а идентификатор в POST остался), и принимать
 * его нельзя: блок получил бы висячую ссылку, которую читатель молча проглатывает.
 */
function blockTargetProblem(string $kind, string $targetKind, string $targetId, bool $targetExists): string
{
    $targetKind = trim($targetKind);
    $targetId = trim($targetId);

    if ($targetKind === '' && $targetId === '') {
        return '';
    }

    if ($kind === 'related') {
        return 'У блока «похожие материалы» цели нет: он берёт рубрику того материала, на котором лежит.';
    }

    // Легальный вид цели берётся из словаря, а не из двух сравнений: тот же словарь читает
    // `blockTargetOptions()`, и добавленная цель не обязана помнить, где её проверяли (вердикт Р13).
    if (!isset(NEWS_BLOCK_TARGETS[$targetKind]) || $targetId === '') {
        return 'Целью блока может быть только рубрика или метка.';
    }

    if (!$targetExists) {
        return 'Выбранной цели уже нет — обновите страницу.';
    }

    return '';
}
```

- [ ] **Шаг 5: Подключить файл к загрузке раздела**

В `apps/news/lib/boot.php` после строки `require_once __DIR__ . '/feed.php';` (:12) вставить:

```php
require_once __DIR__ . '/blocks.php';
```

Порядок важен: `blocks.php` вызывает `collapseSpace()` из ранее подключённого `catalog.php`, а
с задачи 4 — `feedScope()`, `toCard()` и `takeLimit()` из `feed.php`.

- [ ] **Шаг 6: Прогнать зелёный результат**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/blocks.php && php -l apps/news/lib/constants.php && php -l apps/news/lib/boot.php"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter "NewsBlockRulesTest|NewsConstantsTest|NewsCatalogRulesTest|NewsAdminRulesTest"`
Ожидание: зелёное; существующие unit-наборы раздела не задеты.

- [ ] **Шаг 7: Обратные замеры**

| Мутация | Что обязано покраснеть |
| --- | --- |
| `trim($rawZone)` → `strtolower(trim($rawZone))` и список `['Home', …]` | `testЗоныИВидыПеречисленыОднимСписком` |
| убрать ветку отказа по виду | `testНеизвестнаяЗонаИлиВидОтказываются` |
| тихое зажатие вместо отказа: `if ($take < …)` → `$take = min(max($take, 1), NEWS_PER_PAGE)` | `testЧислоМатериаловВнеПределаОтказывается` (999 и 0 принимаются) |
| снять ветку отказа для `kind === 'related'` | `testЦельРазрешенаУпискаИЗапрещенаУПохожих` (рубрика принимается «похожим») |
| снять `tag` из списка допустимых целей | те же тесты: `blockTargetProblem('list', 'tag', 'id-метки', true)` получает отказ |
| добавить `tag` в перечень целей только для `related` | тот же тест: `blockTargetProblem('related', 'tag', …)` принимается |
| убрать проверку `$targetId === ''` во второй ветке | тот же тест: идентификатор без вида цели принимается |
| убрать `$targetExists` | тот же тест: несуществующая рубрика и несуществующая метка принимаются |

- [ ] **Шаг 8: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

```bash
git add apps/news/lib/constants.php apps/news/lib/blocks.php apps/news/lib/boot.php tests/unit/NewsBlockRulesTest.php tests/unit/NewsConstantsTest.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Раздел «Новости»: константы и чистые правила блоков вывода"
```

---

### Задача 4: читатель блоков и печать региона

**Файлы:**
- Изменить: `apps/news/lib/blocks.php` (три функции читателя из §4 плюс резолвер цели — после валидаторов задачи 3)
- Создать: `apps/news/templates/partials/block.php` (вид блока: заголовок + сетка карточек)
- Создать: `tests/db/NewsBlocksTest.php`
- Изменить: `tests/db/NewsDbTestCase.php` (фабрика `makeBlock()` после `oldSlug()`)

**Интерфейсы:**
- Потребляет: `feedScope()` (`apps/news/lib/feed.php:162`), `inCategory()` (:495), `relatedLinks()` (:506),
  `toCard()` (:280), `takeLimit()` (:72), `orderNews()` (`apps/news/lib/visibility.php:43`),
  `NEWS_CARD_COLUMNS` (`feed.php:15`), `isAppActive()` (`core/apps.php:120`),
  `all()` / `value()` / `insert()` / `t()` (`core/db.php`), константы задачи 3.
- Производит:
  - `newsBlockRows(string $zone): list<array<string, mixed>>` — видимые блоки зоны по порядку.
  - `blockFeedRows(array $filter, string $excludeSlug, int $take): list<array<string, mixed>>` —
    карточки блока: рубрика — через `inCategory()` / `relatedLinks()`, общая лента и метка — через
    `feedScope()` (вердикты Р8, Р9).
  - `newsRegionHtml(string $html, string $zone, array $data = []): string` — подпись фильтра
    `region_<зона>`; её ставит на подписку задача 5.
  - `blockTargetSlug(string $targetKind, string $targetId): string` — адрес цели (рубрики или метки)
    по идентификатору; пустая строка — цели нет или её больше нет (вердикт Р7).

**Р7 — четвёртая функция читателя.** Спецификация §4 называет три функции как публичную форму
читателя. `blockTargetSlug()` — служебная: её не зовёт ни шаблон, ни подписка, она только разрешает
`target_id` в слаг (§4 требует именно слаг, потому что фильтр `feedScope()` ждёт слаг, а в базе
лежит `id` — см. Р4) и делает это для обоих видов цели. Контракт §4 от этого не меняется.

- [ ] **Шаг 1: Фабрика блока в db-подставке**

В `tests/db/NewsDbTestCase.php` после метода `oldSlug()` вставить:

```php
    /**
     * Блок выводится строкой напрямую: тест читателя обязан класть в базу ровно проверяемое
     * состояние — зону, вид, цель, предел и порядок, — а не получать его через правила формы.
     *
     * @param array<string, mixed> $overrides
     */
    protected static function makeBlock(string $zone, string $kind, string $title, array $overrides = []): string
    {
        $id = newId();
        $stamp = utcStamp(nowUtc());

        insert('news_block', array_merge([
            'id' => $id,
            'zone' => $zone,
            'kind' => $kind,
            'title' => $title,
            'target_kind' => null,
            'target_id' => null,
            'take_limit' => 5,
            'visible' => 1,
            'order_index' => 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ], $overrides));

        return $id;
    }
```

Заводские строки релиза (`newsblk-home`, `newsblk-article`) к этому моменту в базе уже удалены:
`clearNews()` чистит `news_block` на каждом тесте начиная с задачи 2, поэтому блок в зоне — всегда
руками теста.

- [ ] **Шаг 2: RED — тесты читателя**

Создать `tests/db/NewsBlocksTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Блоки вывода проверяются на живой базе: порядок зоны, предел выборки, правило видимости ленты,
// поведение «цели больше нет» и «раздел выключен» — свойства запросов, а не кода читателя.
final class NewsBlocksTest extends NewsDbTestCase
{
    public function testБлокиЗоныЧитятсяПоПорядкуИБезСкрытых(): void
    {
        self::makeBlock('home', 'list', 'Третий', ['order_index' => 30]);
        self::makeBlock('home', 'list', 'Первый', ['order_index' => 10]);
        self::makeBlock('home', 'list', 'Скрытый', ['order_index' => 20, 'visible' => 0]);
        self::makeBlock('category', 'list', 'Чужая зона', ['order_index' => 5]);

        self::assertSame(['Первый', 'Третий'], $this->titles(newsBlockRows('home')));
    }

    public function testВыборкаБлоковРежетсяПРЕДЕЛОМЗоны(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE + 2; $n++) {
            self::makeBlock('home', 'list', sprintf('Блок %02d', $n), ['order_index' => $n]);
        }

        $rows = newsBlockRows('home');

        self::assertCount(NEWS_BLOCK_PER_ZONE, $rows);
        self::assertSame('Блок 06', (string) $rows[NEWS_BLOCK_PER_ZONE - 1]['title']);
    }

    public function testBlockFeedRowsЧититПравилаВидимостиЛенты(): void
    {
        $this->dated('na-sajte', 1);
        $this->dated('chernovik', 2, ['status' => NEWS_STATUS['DRAFT']]);
        $this->dated('zhdyot-sroka', 3, ['published_at' => utcStamp(nowUtc()->add(new DateInterval('PT2H')))]);

        self::assertSame(['na-sajte'], $this->slugs(blockFeedRows([], '', 5)));
    }

    public function testBlockFeedRowsИсключаетМатериалИРежетЧисло(): void
    {
        $this->dated('m-1', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('m-2', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('m-3', 3, ['category_id' => categoryIdOf('metodika')]);

        // Порядок ленты — `published_at DESC`, значит из трёх материалов свежий m-1, затем m-2.
        self::assertSame(['m-2', 'm-3'], $this->slugs(blockFeedRows(['category' => 'metodika'], 'm-1', 2)));
    }

    public function testBlockFeedRowsОбщуюЛентуРежетПРЕДЕЛОМСтраницы(): void
    {
        for ($n = 1; $n <= NEWS_PER_PAGE + 2; $n++) {
            $this->dated(sprintf('m-%02d', $n), $n);
        }

        // Предел стоит в `takeLimit()`, а не в проверке формы: строка с завышенным числом,
        // попавшая в базу мимо редактора, не должна вытаскивать больше материалов, чем страница.
        self::assertCount(NEWS_PER_PAGE, blockFeedRows([], '', NEWS_PER_PAGE + 2));
    }

    public function testBlockFeedRowsМеткиСоединяетсяСТаблицейСвязей(): void
    {
        $tagId = resolveTagIds(['Урок чтения'])[0];

        $linked = $this->dated('s-urokom', 1);
        self::linkTag($linked, $tagId);
        $this->dated('bez-uroka', 2);

        self::assertSame(['s-urokom'], $this->slugs(blockFeedRows(['tag' => blockTargetSlug('tag', $tagId)], '', 5)));
    }

    public function testBlockFeedRowsФильтрРубрикиНеТрогаетСоседей(): void
    {
        $this->dated('v-metodike', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('v-zhizni', 2);

        self::assertSame(['v-metodike'], $this->slugs(blockFeedRows(['category' => 'metodika'], '', 5)));
    }

    public function testBlockFeedRowsСкрытойРубрикиДаётПустойСписок(): void
    {
        $this->dated('iz-postupleniya', 1, ['category_id' => categoryIdOf('postuplenie')]);
        $this->dated('v-zhizni', 2);
        run('UPDATE ' . t('news_category') . ' SET visible = 0 WHERE slug = ?', ['postuplenie']);

        self::assertSame([], $this->slugs(blockFeedRows(['category' => 'postuplenie'], '', 5)));
        self::assertSame(['v-zhizni'], $this->slugs(blockFeedRows([], '', 5)));
    }

    public function testNewsRegionHtmlПечатаетЗаголовокИКарточки(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');

        $html = newsRegionHtml('', 'home');

        self::assertStringContainsString('Свежие материалы', $html);
        self::assertStringContainsString('/news/novyy', $html);
    }

    public function testNewsRegionHtmlСохраняетВыводДругихПодписчиков(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');

        $html = newsRegionHtml('чужой вывод', 'home');

        self::assertStringStartsWith('чужой вывод', $html);
        self::assertStringContainsString('Свежие материалы', $html);
    }

    public function testNewsRegionHtmlБезБлоковВозвращаетВход(): void
    {
        $this->dated('novyy', 1);

        self::assertSame('до', newsRegionHtml('до', 'home'));
    }

    public function testNewsRegionHtmlНеПечатаетПустойБлок(): void
    {
        self::makeBlock('home', 'list', 'Свежие материалы');

        self::assertSame('', newsRegionHtml('', 'home'));
    }

    public function testДваБлокаЗоныПечатаютсяВПорядкеВыборки(): void
    {
        $this->dated('odinnadtsatyy', 1);
        self::makeBlock('home', 'list', 'Второй блок', ['order_index' => 20]);
        self::makeBlock('home', 'list', 'Первый блок', ['order_index' => 10]);

        $html = newsRegionHtml('', 'home');

        self::assertLessThan(
            (int) strpos($html, 'Второй блок'),
            (int) strpos($html, 'Первый блок'),
            'заголовки блоков идут в порядке выборки'
        );
    }

    public function testRelatedБерётРубрикуСтраницыИИсключаетЕёМатериал(): void
    {
        $this->dated('sam-material', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('sosed', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('iz-drugoy', 3);
        self::makeBlock('article', 'related', 'Похожие материалы');

        $html = newsRegionHtml('', 'article', ['slug' => 'sam-material', 'category' => 'metodika']);

        self::assertStringContainsString('/news/sosed', $html);
        self::assertStringNotContainsString('/news/sam-material', $html);
        self::assertStringNotContainsString('/news/iz-drugoy', $html);
    }

    public function testRelatedБезРубрикиСтраницыМолчит(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('article', 'related', 'Похожие материалы');

        self::assertSame('', newsRegionHtml('', 'article', []));
    }

    public function testБлокСЦельюКоторойНетНеПечатаетОбщуюЛенту(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Методика', ['target_kind' => 'category', 'target_id' => newId()]);

        self::assertSame('', newsRegionHtml('', 'home'));

        // Тот же исход у пропавшей метки: у цели двух видов, и молчать обязан любой из них.
        self::makeBlock('home', 'list', 'Урок чтения', ['target_kind' => 'tag', 'target_id' => newId(), 'order_index' => 20]);

        self::assertSame('', newsRegionHtml('', 'home'));
    }

    public function testБлокМеткиЧитаетТолькоМетку(): void
    {
        $tagId = resolveTagIds(['Урок чтения'])[0];

        $linked = $this->dated('s-urokom', 1);
        self::linkTag($linked, $tagId);
        $this->dated('bez-uroka', 2);

        self::makeBlock('category', 'list', 'Читаем вслух', ['target_kind' => 'tag', 'target_id' => $tagId]);

        $html = newsRegionHtml('', 'category');

        self::assertStringContainsString('/news/s-urokom', $html);
        self::assertStringNotContainsString('/news/bez-uroka', $html);
    }

    public function testБлокПохожихИгнорируетЦельЕслиОнаЗатесалась(): void
    {
        // Редактор такую строку не создаст (правило Р9), но строка могла попасть в базу мимо него:
        // вид `related` определён как рубрика материала, и цель не имеет права менять его смысл.
        $this->dated('sam-material', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('sosed', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('iz-drugoy', 3);
        self::makeBlock('article', 'related', 'Похожие материалы', [
            'target_kind' => 'category',
            'target_id' => categoryIdOf('postuplenie'),
        ]);

        $html = newsRegionHtml('', 'article', ['slug' => 'sam-material', 'category' => 'metodika']);

        self::assertStringContainsString('/news/sosed', $html);
        self::assertStringNotContainsString('/news/iz-drugoy', $html);
    }

    public function testБлокРубрикиЧитаетТолькоСвоюРубрику(): void
    {
        $this->dated('v-metodike', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('v-zhizni', 2);
        self::makeBlock('category', 'list', 'Только методика', [
            'target_kind' => 'category',
            'target_id' => categoryIdOf('metodika'),
        ]);

        $html = newsRegionHtml('', 'category');

        self::assertStringContainsString('/news/v-metodike', $html);
        self::assertStringNotContainsString('/news/v-zhizni', $html);
    }

    public function testNewsRegionHtmlМолчитКогдаРазделВыключен(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');
        run('UPDATE ' . t('app_installation') . " SET enabled = 0 WHERE id = 'news'");

        try {
            self::assertSame('', newsRegionHtml('', 'home'));
        } finally {
            run('UPDATE ' . t('app_installation') . " SET enabled = 1 WHERE id = 'news'");
        }
    }

    /**
     * Опубликованный материал с заданным возрастом: порядок ленты задаётся моментом публикации,
     * поэтому он подставляется в каждой вставке, а не остаётся NULL.
     *
     * @param array<string, mixed> $overrides
     */
    private function dated(string $slug, int $hoursAgo, array $overrides = []): string
    {
        return self::makeItem(categoryIdOf('zhizn-shkoly'), $slug, array_merge([
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT' . $hoursAgo . 'H'))),
        ], $overrides));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['title'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<string>
     */
    private function slugs(array $cards): array
    {
        return array_map(static fn (array $card): string => (string) $card['slug'], $cards);
    }
}
```

- [ ] **Шаг 3: Прогнать RED**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsBlocksTest`
Ожидание: ошибки «Call to undefined function newsBlockRows()» (и далее `blockFeedRows`,
`newsRegionHtml`) — тесты поставлены до читателя, красными они и должны быть.

- [ ] **Шаг 4: GREEN — читатель**

В `apps/news/lib/blocks.php` после `blockTargetProblem()` вставить:

```php
/**
 * Адрес цели блока — рубрики или метки — по её идентификатору. Пустая строка означает «цели нет»
 * или «цели уже нет»: читателю довольно и того, и другого, оба случая печатают пустую зону.
 *
 * Имя поля `target_id` держит идентификатор, а все фильтры ленты ждут адрес (§4 спецификации),
 * поэтому разрешение идёт прямым запросом к таблице цели: `findCategory()` и `findTag()` принимают
 * адрес, а не идентификатор (вердикт Р4).
 */
function blockTargetSlug(string $targetKind, string $targetId): string
{
    if ($targetId === '') {
        return '';
    }

    if ($targetKind === 'category') {
        $slug = value('SELECT slug FROM ' . t('news_category') . ' WHERE id = ?', [$targetId]);
    } elseif ($targetKind === 'tag') {
        $slug = value('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$targetId]);
    } else {
        return '';
    }

    return $slug === null ? '' : (string) $slug;
}

/**
 * Видимые блоки зоны в порядке вывода. Предел стоит и здесь, и в редакторе (задача 6):
 * строка, попавшая в базу мимо редактора, страницу не раздувает.
 *
 * @return list<array<string, mixed>>
 */
function newsBlockRows(string $zone): array
{
    return all(
        sprintf(
            'SELECT id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index'
                . ' FROM %s WHERE zone = ? AND visible = 1 ORDER BY order_index, id LIMIT %d',
            t('news_block'),
            NEWS_BLOCK_PER_ZONE
        ),
        [$zone]
    );
}

/**
 * Карточки блока — поверх уже существующих имён читателя ленты, а не вторым экземпляром правил
 * (вердикт Р8). Рубрика отдаётся `inCategory()` / `relatedLinks()`: они собирают ровно тот же
 * запрос через `categoryFeedRows()`, и исключение материала — их собственный аргумент. Общая лента
 * и подборка по метке строятся поверх `feedScope()`, где живут условия видимости материала,
 * видимости рубрики и соединение с тегами (§8.2).
 *
 * @param array{category?: string, tag?: string} $filter
 * @return list<array<string, mixed>>
 */
function blockFeedRows(array $filter, string $excludeSlug, int $take): array
{
    $category = trim((string) ($filter['category'] ?? ''));
    $tag = trim((string) ($filter['tag'] ?? ''));

    if ($category !== '' && $tag === '') {
        return $excludeSlug === ''
            ? inCategory($category, nowUtc(), $take)
            : relatedLinks($category, $excludeSlug, nowUtc(), $take);
    }

    $scope = feedScope($filter);

    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d',
        NEWS_CARD_COLUMNS,
        $scope['from'],
        $scope['where'],
        orderNews(),
        takeLimit($take)
    );

    $items = [];

    foreach (all($sql, $scope['args']) as $row) {
        $items[] = toCard($row, $scope['now']);
    }

    return $items;
}

/**
 * Подписка региона: добавляет блоки своей зоны к выводу, собранному до неё. Пустой блок не
 * печатается вовсе — заголовок «Похожие материалы» над пустым списком читатель принял бы за
 * сломанную страницу.
 *
 * @param array<string, mixed> $data
 */
function newsRegionHtml(string $html, string $zone, array $data = []): string
{
    if (!isAppActive('news')) {
        return $html;
    }

    $added = '';

    foreach (newsBlockRows($zone) as $block) {
        $kind = (string) $block['kind'];
        $take = (int) $block['take_limit'];

        if ($kind === 'related') {
            // У «похожих» цели нет (§5 спецификации): рубрика и исключение берутся со страницы,
            // и настройка блока к ним не примешивается — иначе один вид значил бы два разных.
            $category = trim((string) ($data['category'] ?? ''));
            $cards = $category === ''
                ? []
                : blockFeedRows(['category' => $category], trim((string) ($data['slug'] ?? '')), $take);
        } else {
            $targetKind = trim((string) ($block['target_kind'] ?? ''));
            $cards = [];

            if ($targetKind === '') {
                $cards = blockFeedRows([], '', $take);
            } else {
                $targetSlug = blockTargetSlug($targetKind, trim((string) ($block['target_id'] ?? '')));

                // Цель выбрана, но её больше нет (или её имя не из перечня): блок молчит, а не
                // печатает общую ленту под заголовком про несуществующую рубрику — решение 5.
                if ($targetSlug !== '') {
                    $cards = blockFeedRows([$targetKind => $targetSlug], '', $take);
                }
            }
        }

        if ($cards === []) {
            continue;
        }

        $added .= partial('news/partials/block', ['title' => (string) $block['title'], 'cards' => $cards]);
    }

    return $html . $added;
}
```

`nowUtc()` внутри `newsRegionHtml()` не нужен: момент «сейчас» берёт `feedScope()` из
`$filter['now'] ?? nowUtc()`, и тестам, которым нужна фиксированная минута, достаточно положить
`now` в фильтр.

- [ ] **Шаг 5: GREEN — вид блока**

Создать `apps/news/templates/partials/block.php`:

```php
<?php

declare(strict_types=1);

/**
 * Блок вывода на публичной странице: заголовок и сетка карточек. Карточка — тот же partial,
 * что печатает лента, поэтому вид подборки и вид списка не расъезжаются при правке одной из них.
 *
 * Классы берутся из разметки ленты и карточки: нового токена здесь нет, и артефакт стилей
 * остаётся свежим (`StyleSourceTest` проверяет, что каждый класс разметки лежит в артефакте).
 *
 * @var string $title
 * @var list<array<string, mixed>> $cards
 */
?>
<div class="space-y-4">
    <h2 class="text-xl font-semibold"><?= e($title) ?></h2>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($cards as $card): ?>
            <?= partial('news/partials/card', ['card' => $card]) /* @safe html */ ?>
        <?php endforeach; ?>
    </div>
</div>
```

- [ ] **Шаг 6: Прогнать GREEN**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/blocks.php && php -l apps/news/templates/partials/block.php"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsBlocksTest`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "StyleSourceTest|OutputEscapingTest|SqlSafetyTest"`
Run: `bash scripts/css && git status --porcelain public/assets/app.css`
Ожидание: тесты зелёные; `git status` по артефакту пустой — классы partial'а уже лежат в собранном
файле, и пересборка ничего не меняет. Если артефакт изменился, значит partial принёс класс, которого
в разметке до него не было: это находка по задаче, а не повод молча коммитить пересборку — сверить
список классов разметки со списком правил артефакта.

- [ ] **Шаг 7: Обратные замеры**

| Мутация | Что обязано покраснеть |
| --- | --- |
| убрать `AND visible = 1` из `newsBlockRows()` | `testБлокиЗоныЧитятсяПоПорядкуИБезСкрытых` |
| убрать `ORDER BY order_index, id` | тот же тест: порядок «Первый, Третий» не складывается |
| убрать `LIMIT %d` из выборки блоков | `testВыборкаБлоковРежетсяПРЕДЕЛОМЗоны` |
| `takeLimit($take)` → `$take` в `blockFeedRows()` | `testBlockFeedRowsОбщуюЛентуРежетПРЕДЕЛОМСтраницы` (14 материалов вместо двенадцати) |
| в рубричной ветке `blockFeedRows()` читать общую ленту вместо `inCategory()` | `testBlockFeedRowsФильтрРубрикиНеТрогаетСоседей` (`v-zhizni` попадает в выдачу) |
| собрать исключение вторым экземпляром запроса вместо `relatedLinks()` | этот набор не краснеет: поведение то же, и именно поэтому вердикт Р8 запрещает копию тела, а не только падение теста |
| заменить `feedScope($filter)` в общей ветке на `newsItemFrom()` и голый `visibleWhere()` | второе утверждение `testBlockFeedRowsСкрытойРубрикиДаётПустойСписок` — материал скрытой рубрики возвращается в общую выборку |
| выбросить ветку `tag` в `blockTargetSlug()` (возвращать пустую строку) | `testBlockFeedRowsМеткиСоединяетсяСТаблицейСвязей` и `testБлокМеткиЧитаетТолькоМетку` |
| в `newsRegionHtml()` читать цель у блока `related` | `testБлокПохожихИгнорируетЦельЕслиОнаЗатесалась` (подборка берётся из рубрики цели, а не материала) |
| убрать `$targetSlug !== ''` | `testБлокСЦельюКоторойНетНеПечатаетОбщуюЛенту` (оба утверждения: рубрика и метка) |
| убрать `$cards === []` | `testNewsRegionHtmlНеПечатаетПустойБлок` |
| `return $html . $added` → `return $added` | `testNewsRegionHtmlСохраняетВыводДругихПодписчиков` |
| убрать `isAppActive('news')` | `testNewsRegionHtmlМолчитКогдаРазделВыключен` |
| related без рубрики страницы брать общую ленту | `testRelatedБезРубрикиСтраницыМолчит` |
| не передавать `$data['slug']` исключением | `testRelatedБерётРубрикуСтраницыИИсключаетЕёМатериал` |

- [ ] **Шаг 8: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

```bash
git add apps/news/lib/blocks.php apps/news/templates/partials/block.php tests/db/NewsBlocksTest.php tests/db/NewsDbTestCase.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Раздел «Новости»: читатель блоков вывода и печать региона"
```

---

### Задача 5: подписка раздела на зоны и пять точек вставки

**Файлы:**
- Изменить: `apps/news/routes.php` (подписка после строки 54; удалить строку 211)
- Изменить: `theme/home.php` (вызов зоны после строки 11)
- Изменить: `apps/news/templates/feed.php` (после строки 22), `category.php` (после 30), `tag.php` (после 26)
- Изменить: `apps/news/templates/single.php` (удалить строку 11 и виджет 64-73, вставить вызов перед `</article>`)
- Изменить: `tests/http/NewsPublicTest.php` (правка 281-289 и две новые проверки)

**Интерфейсы:**
- Потребляет: `NEWS_BLOCK_ZONES` (задача 3), `newsRegionHtml()` (задача 4), `region()` (задача 1),
  `addFilter()` (`core/hooks.php:35`)
- Производит: фильтры `region_home`, `region_category`, `region_article` с подписчиком
  `newsRegionHtml`; пять вызовов `region()` в шаблонах; данные страницы для зоны `article`.

**Четыре правила этой задачи:**

1. **Подписка — одна функция на все зоны.** Цикл по `NEWS_BLOCK_ZONES` ставит `newsRegionHtml`
   на каждый фильтр `region_<зона>`: имя зоны приходит аргументом, поэтому на второе имя не
   нужно второе замыкание (решение 2 спецификации).
2. **Подписка стоит ниже закрытия фильтра карты сайтов (`routes.php:54`).** Выше — нельзя:
   строка 48 для стража `NewsVisibilityTest` единственная наводка текста падения на место в
   файле (вердикт Р3), и сдвигать её здесь значило бы краснить чужую проверку.
3. **Зона — хвост основного содержимого.** Вызов стоит после того, что нарисовало само приложение,
   и внутри того же `<section>`/`<article>`: пагинатор живёт в частичном списке
   (`apps/news/templates/partials/list.php:19`), поэтому блок на странице-списке стоит под ним.
4. **Вызов печатающий, а не возвращающий.** `region()` ставится в шаблонах страниц, а не в
   partial'ах: `partial()` собирает строку, `region()` печатает в общий поток.

- [ ] **Шаг 1: Переписать публичный http-тест (RED)**

В `tests/http/NewsPublicTest.php` заменить метод `testУМатериалаВидноПохожиеИзЕгоРубрики` (:281-289)
на три метода:

```php
    public function testУМатериалаВидноПохожиеИзЕгоРубрики(): void
    {
        // Виджет переехал в блок зоны `article`: заголовок человек задаёт на экране «Блоки», и
        // на странице он обязан прозвучать ровно один раз — второй значил бы, что старый виджет
        // не удалён (критерий 4 §6 спецификации).
        $page = Kernel::get('/news/vecher-vstrechi');

        self::assertStringContainsString('<section class="region region-article">', $page['body']);
        self::assertStringContainsString('Похожие материалы', $page['body'], 'заводской блок зоны article');
        self::assertStringContainsString('Второй вечер', $page['body']);
        self::assertStringNotContainsString('Приём в первый класс', $page['body'], 'чужая рубрика в блоке не появляется');
        self::assertStringNotContainsString('Читайте также', $page['body'], 'старый заголовок виджета снят вместе с виджетом');
        self::assertSame(1, substr_count((string) $page['body'], 'Похожие материалы'), 'один блок — одна печать заголовка');
    }

    public function testНаГлавнойБлокСвежихМатериалов(): void
    {
        // Зона `home` живёт в теме, а не в разделе: если подписка подключена только к шаблонам
        // «Новостей», эта проверка красна, и наоборот — она же доказывает, что ядро зовёт
        // приложение, а не наоборот.
        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<section class="region region-home">', $page['body']);
        self::assertStringContainsString('Свежие материалы', $page['body']);
        self::assertStringContainsString('/news/vecher-vstrechi', $page['body'], 'карточка блока ведёт на материал');
    }

    public function testЗонаБезБлоковНеПечатаетОбёртку(): void
    {
        // В зоне `category` заводских строк нет и человек их не заводил: страница-список обязана
        // остаться ровно такой, какой была до слоя (критерий 1 приёмки).
        $page = Kernel::get('/news');

        self::assertStringNotContainsString('<section class="region', $page['body']);
    }
```

- [ ] **Шаг 2: Убедиться, что тест красный**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter "NewsPublicTest::testУМатериалаВидноПохожиеИзЕгоРубрики|NewsPublicTest::testНаГлавнойБлокСвежихМатериалов|NewsPublicTest::testЗонаБезБлоковНеПечатаетОбёртку"`
Ожидание: первые два падают («Похожие материалы» и обёртки `region region-…` на страницах нет —
строки блоков
лежат в базе с задачи 2, но подписки и вызова ещё нет), третий проходит: обёртку печатать пока
некому.

- [ ] **Шаг 3: Подписать зоны в маршрутах**

В `apps/news/routes.php` после строки `}, 10);` (:54, закрытие фильтра карты сайтов) вставить:

```php

// Блоки вывода: раздел подписывает каждую свою зону одной функцией, имя зоны приходит
// аргументом — иначе на каждое имя пришлось бы по замыканию (решение 2 спецификации).
// Подписка стоит ниже фильтра карты сайтов: для стража `NewsVisibilityTest` строка того
// замыкания — единственная наводка на место в файле, и сдвигать её здесь нельзя (вердикт Р3).
// Имя переменной с префиксом области: файл исполняется внутри `registerAppRoutes()`
// (`core/apps.php:266`), и короткое `$zone` жило бы в её локальных переменных.
foreach (NEWS_BLOCK_ZONES as $regionZone) {
    addFilter('region_' . $regionZone, 'newsRegionHtml', 10);
}
```

- [ ] **Шаг 4: Перевести карточку материала на блок**

В `apps/news/routes.php` удалить строку 211 целиком:

```php
        'related' => $open ? relatedLinks((string) $item['categorySlug'], (string) $item['slug'], $now) : [],
```

`$now` в функции остаётся — её читает `findPublished($slug, $now)` (:178). В
`apps/news/templates/single.php` удалить строку 11 (` * @var list<array<string, mixed>> $related`)
и виджет 64-73, а перед закрывающим `</article>` (:74) поставить вызов:

```php
    <?php region('article', ['slug' => (string) $item['slug'], 'category' => (string) $item['categorySlug']]); ?>
</article>
```

Данные страницы передаются всегда, без `if ($open)`: вид `related` берёт рубрику материала, а
правило «черновик и материал скрытой рубрики наружу не выходят» держит `feedScope()` — вторая
формулировка того же правила в шаблоне была бы дырой (вердикт Р6).

- [ ] **Шаг 5: Поставить вызовы в четыре остальных шаблона**

В `theme/home.php` после строки 11 (ссылка «Читать новости»), внутри `<section class="py-8">`:

```php
    <?php region('home'); ?>
```

В `apps/news/templates/feed.php` после строки 22, в `apps/news/templates/category.php` после
строки 30 и в `apps/news/templates/tag.php` после строки 26 — одна и та же строка, частичный
список к этому моменту уже напечатан:

```php
    <?php region('category'); ?>
```

Три страницы-списка делят одну зону: настройка означает «страница со списком», и у человека в
редакторе один предсказуемый ответ на вопрос «куда встанет блок» (§3 спецификации).

- [ ] **Шаг 6: Прогнать GREEN**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/routes.php && php -l apps/news/templates/single.php && php -l apps/news/templates/feed.php && php -l apps/news/templates/category.php && php -l apps/news/templates/tag.php && php -l theme/home.php"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsPublicTest`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "NewsVisibilityTest|RouteHandlersTest|CsrfCoverageTest"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsBlocksTest`
Ожидание: всё зелёное; `NewsVisibilityTest` называет `{closure:48}` — подписка ниже этой строки и
номер не двигает (вердикт Р3).

- [ ] **Шаг 7: Обратные замеры**

| Мутация | Что обязано покраснеть |
| --- | --- |
| удалить `foreach` с `addFilter('region_…')` | `testУМатериалаВидноПохожиеИзЕгоРубрики` и `testНаГлавнойБлокСвежихМатериалов` — обёртки зоны нет ни на одной странице |
| снять вызов `region('article', …)` из `single.php` | `testУМатериалаВидноПохожиеИзЕгоРубрики` — заголовка блока нет |
| вернуть виджет 64-73 в `single.php` рядом с вызовом зоны | тот же тест: «Читайте также» появляется, а счётчик заголовков перестаёт быть равен одному |
| вызвать `region('home')` в `theme/home.php` с другим именем зоны | `testНаГлавнойБлокСвежихМатериалов` — подписчик `region_sidebar` не зарегистрирован, обёртки нет |
| поставить подписку выше строки 48 (перед фильтром карты сайтов) | `NewsVisibilityTest::testНомерСтрокиЗамыканияСчитаетсяПоИсходномуТексту` — замыкание уезжает на 50-ю строку |
| напечатать обёртку до проверки пустоты в `core/template.php` | `testЗонаБезБлоковНеПечатаетОбёртку` и unit-проверка задачи 1 |
| вернуть `'related' => …` в `render('news/single', …)`, не вернув виджет | тест зелёный, а PHPStan краснеет на незапрошенную переменную — строка удалена вместе с потребителем, держать её нельзя |

- [ ] **Шаг 8: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

```bash
git add apps/news/routes.php apps/news/templates/single.php apps/news/templates/feed.php apps/news/templates/category.php apps/news/templates/tag.php theme/home.php tests/http/NewsPublicTest.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Раздел «Новости»: подписка зон вывода и пять точек вставки"
```

Отчёт — в `.superpowers/sdd/part1/task-287-5-report.md`: дословный вывод трёх проверок шага 2,
номер строки замыкания из вывода `NewsVisibilityTest` на шаге 6, результаты семи обратных замеров
и список изменённых файлов.

---

### Задача 6: операции записи блоков

**Файлы:**
- Изменить: `apps/news/lib/blocks.php` (в конце файла, после читателя задачи 4: две служебные
  функции и шесть операций записи)
- Изменить: `tests/db/NewsBlocksTest.php` (секция «Операции записи»: семнадцать проверок и два
  приватных помощника)
- Изменить: `tests/db/NewsNoticeCoverageTest.php` (свойство `$blockHits`, хелпер `block()`,
  проверка достижимости семи ключей, семь блок-отказов в перечне отказов)

**Интерфейсы:**
- Потребляет: `checkBlockFields()` и `blockTargetProblem()` (задача 3), `blockTargetSlug()`
  (задача 4), `NEWS_BLOCK_ZONES` / `NEWS_BLOCK_KINDS` / `NEWS_BLOCK_PER_ZONE` / `BLOCK_FIELDS` /
  `NEWS_BLOCK_NOTICE_TEXT` (задача 3), `plural()` (`core/text.php:7`), `resolveTagIds()` и
  `categoryIdOf()` (`apps/news/lib/catalog.php`), `t()` / `one()` / `value()` / `run()` (`core/db.php`),
  `newId()` (`core/id.php`), `utcStamp()` / `nowUtc()` (`core/date.php`), `logInfo()` (`core/log.php`).
- Производит:
  - `createBlock(string $rawZone, string $rawKind, string $rawTitle, int $rawTake, string $targetKind = '', string $targetId = ''): array{ok: bool, notice: string, error: string}`
  - `renameBlock(string $id, string $rawTitle): array{ok: bool, notice: string, error: string}`
  - `retargetBlock(string $id, string $targetKind, string $targetId): array{ok: bool, notice: string, error: string}`
  - `limitBlock(string $id, int $rawTake): array{ok: bool, notice: string, error: string}`
  - `setBlockVisibility(string $id, bool $visible): array{ok: bool, notice: string, error: string}`
  - `deleteBlock(string $id): array{ok: bool, notice: string, error: string}`
  - `blockCountInZone(string $zone): int` и `blockTargetExists(string $targetKind, string $targetId): bool`
    — служебные: их зовут операции записи, а экран задачи 8 считает строки зоны по готовой выборке.
  Задача 7 добавляет седьмую операцию — `moveBlock()`; все семь возвращают ту же тройку, что операции
  справочников (`apps/news/lib/catalog.php:161-267`), и задача 9 вызывает их из файла действий.

**Пять правил этой задачи:**

1. **Проверка одна — из задачи 3.** Каждая операция начинает с `checkBlockFields()` и
   `blockTargetProblem()`, а правка заголовка не получает своего предела длины: две формулировки
   одного правила разъехались бы при первой же правке `BLOCK_FIELDS` (тот же довод, что держит
   правило видимости в одном теле — комментарий `apps/news/lib/feed.php:450-457`).
2. **Вид блока читается из строки, а не из формы.** `retargetBlock()` берёт `kind` из `SELECT`:
   форма правки цели его не отправляет, а принятый из POST позволил бы записать «похожие +
   рубрика» — противоречие, которое читатель толкует по-своему (вердикт Р9).
3. **Предел зоны считает все её строки, видимые и скрытые вместе** (§5 спецификации). Скрытый блок
   занимает место: иначе человек накопил бы десять скрытых строк, а читатель задачи 4 молча отрезал
   бы седьмую и восьмую на `LIMIT`.
4. **Новый блок становится в конец своей зоны** — `MAX(order_index) + 1` с `WHERE zone = ?`:
   плотность порядка живёт внутри зоны, и задача 7 пересобирает её стрелками тоже только в зоне.
5. **Отказ не несёт ключа уведомления.** Во всех ветках отказа `'notice' => ''`: экран по ключу
   печатает плашку «Блок добавлен», и там, где ничего не добавилось, плашки быть не должно
   (договор `NewsNoticeCoverageTest::testОтказНикогдаНеНесётКлючаУведомления`).

- [ ] **Шаг 1: RED — проверки операций**

В `tests/db/NewsBlocksTest.php` после проверок читателя дописать секцию. Две последние проверки
читают `checkBlockFields()` напрямую — это намеренно: операция обязана отвечать тем же текстом,
что и чистое правило, иначе в файле появляется второй предел.

```php
    // ── Операции записи ──────────────────────────────────

    public function testНовыйБлокПолучаетСледующийПорядокВСвоейЗоне(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        self::assertTrue(createBlock('home', 'list', 'Методика', 4)['ok']);
        self::assertTrue(createBlock('article', 'related', 'Похожие материалы', 3)['ok']);

        // Порядок плотный внутри зоны: блок «article» не сдвигает нумерацию «home» и наоборот.
        self::assertSame('1', $this->orderOf('home', 'Свежие материалы'));
        self::assertSame('2', $this->orderOf('home', 'Методика'));
        self::assertSame('1', $this->orderOf('article', 'Похожие материалы'));
        self::assertSame(
            ['Свежие материалы', 'Методика'],
            array_column(newsBlockRows('home'), 'title'),
            'новый блок в конце зоны, и читатель отдаёт его последним'
        );
    }

    public function testСедьмойБлокВЗонеОтказывается(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE; $n++) {
            self::assertTrue(createBlock('home', 'list', sprintf('Блок %02d', $n), 3)['ok'], 'Блок №' . $n . ' заводится');
        }

        $refusal = createBlock('home', 'list', 'Лишний', 3);

        self::assertFalse($refusal['ok']);
        self::assertSame('', (string) $refusal['notice'], 'Отказ не несёт ключа');
        self::assertStringContainsString((string) NEWS_BLOCK_PER_ZONE, (string) $refusal['error']);
        self::assertSame('6', $this->column('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ?', ['home']));
    }

    public function testСкрытыйБлокЗанимаетМестоВЗоне(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE; $n++) {
            self::makeBlock('home', 'list', sprintf('Скрытый %02d', $n), ['visible' => 0, 'order_index' => $n]);
        }

        // Снятый с показа блок — всё ещё настройка зоны (§5): предел считается по строкам, а не
        // по числу блоков, которые страница печатает.
        self::assertFalse(createBlock('home', 'list', 'Ещё один', 3)['ok']);
    }

    public function testПределСчитаетсяВЗонеАНеНаВсёмСайте(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE; $n++) {
            self::assertTrue(createBlock('home', 'list', sprintf('Блок %02d', $n), 3)['ok']);
        }

        // Зона `article` остаётся доступной: шесть блоков — это место в настройке одной точки
        // вывода, а не лимит строк таблицы.
        $result = createBlock('article', 'related', 'Похожие материалы', 3);
        self::assertTrue($result['ok'], 'Чужая зона не исчерпывается: ' . (string) $result['error']);
    }

    public function testБлокЗаписываетсяСЦельюРубрикиИМетки(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        $tagId = resolveTagIds(['Урок чтения'])[0];

        self::assertTrue(createBlock('category', 'list', 'Только жизнь школы', 4, 'category', $categoryId)['ok']);
        self::assertTrue(createBlock('category', 'list', 'Читаем вслух', 3, 'tag', $tagId)['ok']);

        self::assertSame('category', $this->column('SELECT target_kind FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Только жизнь школы']));
        self::assertSame($categoryId, $this->column('SELECT target_id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Только жизнь школы']));
        self::assertSame('tag', $this->column('SELECT target_kind FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Читаем вслух']));
        self::assertSame($tagId, $this->column('SELECT target_id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Читаем вслух']));
    }

    public function testБлокБезЦелиХранитNullАНеПустуюСтроку(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        // Схема (§2) объявляет обе колонки цели `NULL`: пустая строка — третье состояние, которого
        // экран задачи 8 не ожидает, и «вся лента» читается именно по NULL.
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_kind, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_id, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
    }

    public function testНесуществующаяЦельОтказывается(): void
    {
        foreach (['category', 'tag'] as $kind) {
            $result = createBlock('home', 'list', 'Методика', 4, $kind, newId());

            self::assertFalse($result['ok'], 'Цель ' . $kind . ' которой нет, не записывается');
            self::assertSame('Выбранной цели уже нет — обновите страницу.', (string) $result['error']);
        }

        self::assertSame('0', $this->column('SELECT COUNT(*) FROM ' . t('news_block')));
    }

    public function testЦельУПохожихБлокаОтказывается(): void
    {
        $result = createBlock('article', 'related', 'Похожие материалы', 5, 'category', categoryIdOf('metodika'));

        // «Похожие» берут рубрику того материала, на котором лежат (§5), и цель тут же — два
        // смысла одного вида блока.
        self::assertFalse($result['ok']);
        self::assertSame('У блока «похожие материалы» цели нет: он берёт рубрику того материала, на котором лежит.', (string) $result['error']);
    }

    public function testЧужаяЗонаИлиВидОтказываются(): void
    {
        // Строка из POST, которой нет в перечнях раздела, не записывается даже мимо редактора (§5).
        self::assertSame('Зоны с таким именем нет.', (string) createBlock('sidebar', 'list', 'Боковая колонка', 4)['error']);
        self::assertSame('Такого вида блока нет.', (string) createBlock('home', 'carousel', 'Карусель', 4)['error']);
        self::assertSame('0', $this->column('SELECT COUNT(*) FROM ' . t('news_block')));
    }

    public function testЧислоМатериаловПроверяетсяПриЗаписи(): void
    {
        // Просьба вне предела — отказ, а не тихое зажатие: человек должен увидеть, что форма
        // приняла другое число, чем он написал (§5).
        self::assertFalse(createBlock('home', 'list', 'Свежие материалы', 999)['ok']);
        self::assertFalse(createBlock('home', 'list', 'Свежие материалы', 0)['ok']);
        self::assertSame('0', $this->column('SELECT COUNT(*) FROM ' . t('news_block')));
    }

    public function testОдинаковыеЗаголовкиВЗонеЛегальны(): void
    {
        // Две «Свежие материалы» в одной зоне — легальная настройка (§5): блоки могут различаться
        // целью и пределом, а заголовок — только надпись для человека.
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 3)['ok']);

        self::assertSame('2', $this->column('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['home', 'Свежие материалы']));
    }

    public function testПравкаЗаголовкаОтказываетсяТемЖеТекстомЧтоИСоздание(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        $byForm = checkBlockFields('home', 'list', 'а', 5);
        $byOp = renameBlock($id, 'а');

        self::assertFalse($byOp['ok']);
        // Один текст на одно правило: правка заголовка не несёт своей проверки длины.
        self::assertSame((string) $byForm['error'], (string) $byOp['error']);
        self::assertSame('Свежие материалы', $this->column('SELECT title FROM ' . t('news_block') . ' WHERE id = ?', [$id]), 'отказ ничего не переписывает');
    }

    public function testСменаЦелиБерётВидИзСтроки(): void
    {
        $categoryId = categoryIdOf('metodika');
        self::makeBlock('article', 'related', 'Похожие материалы');
        $id = $this->blockIdOf('article', 'Похожие материалы');

        $result = retargetBlock($id, 'category', $categoryId);

        self::assertFalse($result['ok'], 'Рубрику «похожему» блоку не выдают');
        self::assertSame('', (string) $result['notice']);
        self::assertSame('', $this->column('SELECT target_kind FROM ' . t('news_block') . ' WHERE id = ?', [$id]), 'цель осталась пустой');
    }

    public function testСнятаяЦельОбеКолонкиСтановятсяNull(): void
    {
        self::assertTrue(createBlock('category', 'list', 'Только методика', 4, 'category', categoryIdOf('metodika'))['ok']);
        $id = $this->blockIdOf('category', 'Только методика');

        self::assertSame('retargeted', (string) retargetBlock($id, '', '')['notice']);

        // Обе колонки разом: половину цели оставить нельзя, иначе читатель задачи 4 увидел бы
        // вид без идентификатора и печатал бы общую ленту под заголовком про рубрику.
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_kind, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_id, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
    }

    public function testЧислоМатериаловПравится(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        self::assertSame('limited', (string) limitBlock($id, 3)['notice']);
        self::assertSame('3', $this->column('SELECT take_limit FROM ' . t('news_block') . ' WHERE id = ?', [$id]));

        self::assertFalse(limitBlock($id, 13)['ok'], 'за предел страницы — отказ, а не зажатие');
        self::assertSame('3', $this->column('SELECT take_limit FROM ' . t('news_block') . ' WHERE id = ?', [$id]), 'отказ не трогает принятое значение');
    }

    public function testПоказИСкрытиеБлока(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        self::assertSame('hidden', (string) setBlockVisibility($id, false)['notice']);
        self::assertSame('[]', json_encode(array_column(newsBlockRows('home'), 'title'), JSON_THROW_ON_ERROR), 'скрытый блок не участвует в выдаче');

        // Направление берётся из намерения, а не из текущего состояния: двойное «скрыть» обязано
        // оставить блок скрытым, а не показать его (в отличие от рубрики, где кнопка одна — переключатель).
        self::assertSame('hidden', (string) setBlockVisibility($id, false)['notice']);
        self::assertSame('0', $this->column('SELECT visible FROM ' . t('news_block') . ' WHERE id = ?', [$id]));

        self::assertSame('shown', (string) setBlockVisibility($id, true)['notice']);
        self::assertSame(['Свежие материалы'], array_column(newsBlockRows('home'), 'title'));
    }

    public function testУдалениеБлока(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        self::assertSame('deleted', (string) deleteBlock($id)['notice']);
        self::assertSame('', $this->column('SELECT id FROM ' . t('news_block') . ' WHERE id = ?', [$id]));

        // Блок не содержит контента, только настройку, и внешний ключ (§2, решение 8) ни за что
        // его не держит: удаление не требует подтверждения и не считает материалы.
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok'], 'освободившееся место занимает новый блок');
    }

    public function testЧужойИдентификаторОтказываетсяОднимТекстом(): void
    {
        // Пять операций правки и удаления отвечают одной строкой: человек смотрит на экран, где
        // строку уже не видно, и ему важно понять «обнови страницу», а не различать пять формулировок.
        $results = [
            renameBlock('net-takogo-identifikatora', 'Новое название'),
            retargetBlock('net-takogo-identifikatora', 'category', categoryIdOf('metodika')),
            limitBlock('net-takogo-identifikatora', 3),
            setBlockVisibility('net-takogo-identifikatora', true),
            deleteBlock('net-takogo-identifikatora'),
        ];

        foreach ($results as $index => $result) {
            self::assertFalse($result['ok'], 'Операция №' . $index . ' не удаётся');
            self::assertSame('', (string) $result['notice'], 'Операция №' . $index . ' не несёт ключа');
            self::assertSame('Блока уже нет — обновите страницу.', (string) $result['error']);
        }
    }

    private function blockIdOf(string $zone, string $title): string
    {
        return $this->column('SELECT id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', [$zone, $title]);
    }

    private function orderOf(string $zone, string $title): string
    {
        return $this->column('SELECT order_index FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', [$zone, $title]);
    }
```

Помощники `blockIdOf()` и `orderOf()` приватные и нестатические, потому что опираются на
`column()` подставки: тот же приём, что `lastCategoryId()` в `NewsNoticeCoverageTest`.

- [ ] **Шаг 2: Убедиться, что проверки красные**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsBlocksTest`
Ожидание: проверки читателя задачи 4 зелёные, новые падают на `Call to undefined function
createBlock()` (далее — `renameBlock`, `retargetBlock`, `limitBlock`, `setBlockVisibility`,
`deleteBlock`).

- [ ] **Шаг 3: GREEN — операции записи**

В `apps/news/lib/blocks.php` после читателя задачи 4:

```php
// ── Операции записи ──────────────────────────────────────

// Счётчик зоны считает и скрытые строки: предел — это место в настройке, а не число блоков на
// экране (§5 спецификации). Иначе человек накопил бы десять скрытых строк, а читатель задачи 4
// молча отрезал бы седьмую и восьмую на своём LIMIT.
function blockCountInZone(string $zone): int
{
    return (int) value('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ?', [$zone]);
}

// Жива ли цель, решает тот же резолвер, что зовёт страницу (вердикт Р10): пустой адрес означает
// «рубрики или метки уже нет», и у писателя с читателем остаётся одно определение цели.
function blockTargetExists(string $targetKind, string $targetId): bool
{
    return blockTargetSlug($targetKind, $targetId) !== '';
}

/** @return array{ok: bool, notice: string, error: string} */
function createBlock(string $rawZone, string $rawKind, string $rawTitle, int $rawTake, string $targetKind = '', string $targetId = ''): array
{
    $checked = checkBlockFields($rawZone, $rawKind, $rawTitle, $rawTake);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }

    $cleanKind = trim($targetKind);
    $cleanId = trim($targetId);

    $problem = blockTargetProblem($checked['kind'], $cleanKind, $cleanId, blockTargetExists($cleanKind, $cleanId));
    if ($problem !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $problem];
    }

    if (blockCountInZone($checked['zone']) >= NEWS_BLOCK_PER_ZONE) {
        return [
            'ok' => false,
            'notice' => '',
            'error' => 'В зоне уже ' . NEWS_BLOCK_PER_ZONE . ' '
                . plural(NEWS_BLOCK_PER_ZONE, ['блок', 'блока', 'блоков'])
                . ' — уберите лишний, чтобы добавить новый.',
        ];
    }

    // Новый блок становится в конец своей зоны: плотность 1..N держится внутри зоны, а не по
    // всей таблице (задача 7 пересобирает её стрелками тоже только в зоне).
    $order = (int) value('SELECT COALESCE(MAX(order_index), 0) FROM ' . t('news_block') . ' WHERE zone = ?', [$checked['zone']]) + 1;
    $id = newId();
    $stamp = utcStamp(nowUtc());

    run(
        'INSERT INTO ' . t('news_block')
            . ' (id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
        [
            $id,
            $checked['zone'],
            $checked['kind'],
            $checked['title'],
            $cleanKind === '' ? null : $cleanKind,
            $cleanId === '' ? null : $cleanId,
            $checked['take'],
            $order,
            $stamp,
            $stamp,
        ]
    );

    logInfo('news.block_saved', ['blockId' => $id, 'zone' => $checked['zone'], 'change' => 'created']);

    return ['ok' => true, 'notice' => 'created', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function renameBlock(string $id, string $rawTitle): array
{
    $row = one('SELECT id, zone, kind, take_limit FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    // Зона, вид и предел берутся из строки, а проверка та же, что при создании: у правки заголовка
    // нет своего предела длины (§5 спецификации).
    $checked = checkBlockFields((string) $row['zone'], (string) $row['kind'], $rawTitle, (int) $row['take_limit']);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }

    run(
        'UPDATE ' . t('news_block') . ' SET title = ?, updated_at = ? WHERE id = ?',
        [$checked['title'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone'], 'change' => 'renamed']);

    return ['ok' => true, 'notice' => 'renamed', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function retargetBlock(string $id, string $targetKind, string $targetId): array
{
    $row = one('SELECT id, zone, kind FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    $cleanKind = trim($targetKind);
    $cleanId = trim($targetId);

    // Вид читается из строки: форма правки цели его не отправляет, а взятый из POST позволил бы
    // записать «похожие + рубрика» — противоречие, которое читатель толкует по-своему (вердикт Р9).
    $problem = blockTargetProblem((string) $row['kind'], $cleanKind, $cleanId, blockTargetExists($cleanKind, $cleanId));
    if ($problem !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $problem];
    }

    // Обе колонки пишутся одним выражением: наполовину снятая цель — состояние, которого читатель
    // задачи 4 не различает (пустой `target_kind` значит «вся лента», а живой `target_id` при этом
    // просто теряется).
    run(
        'UPDATE ' . t('news_block') . ' SET target_kind = ?, target_id = ?, updated_at = ? WHERE id = ?',
        [$cleanKind === '' ? null : $cleanKind, $cleanId === '' ? null : $cleanId, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone'], 'change' => 'retargeted']);

    return ['ok' => true, 'notice' => 'retargeted', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function limitBlock(string $id, int $rawTake): array
{
    $row = one('SELECT id, zone, kind, title FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    $checked = checkBlockFields((string) $row['zone'], (string) $row['kind'], (string) $row['title'], $rawTake);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }

    run(
        'UPDATE ' . t('news_block') . ' SET take_limit = ?, updated_at = ? WHERE id = ?',
        [$checked['take'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone'], 'change' => 'limited']);

    return ['ok' => true, 'notice' => 'limited', 'error' => ''];
}

/**
 * Показ и скрытие — одна функция с направлением, а не две и не переключатель. Направление приходит
 * из намерения человека (`op` = show или hide): двойное «скрыть» обязано оставить блок скрытым,
 * тогда как переключатель показал бы его против воли.
 *
 * @return array{ok: bool, notice: string, error: string}
 */
function setBlockVisibility(string $id, bool $visible): array
{
    $row = one('SELECT id, zone FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    run(
        'UPDATE ' . t('news_block') . ' SET visible = ?, updated_at = ? WHERE id = ?',
        [$visible ? 1 : 0, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', [
        'blockId' => (string) $row['id'],
        'zone' => (string) $row['zone'],
        'change' => $visible ? 'shown' : 'hidden',
    ]);

    return ['ok' => true, 'notice' => $visible ? 'shown' : 'hidden', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function deleteBlock(string $id): array
{
    $row = one('SELECT id, zone FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    run('DELETE FROM ' . t('news_block') . ' WHERE id = ?', [(string) $row['id']]);

    logInfo('news.block_deleted', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone']]);

    return ['ok' => true, 'notice' => 'deleted', 'error' => ''];
}
```

`NEWS_BLOCK_PER_ZONE` в тексте отказа стоит числом, а не словом: человек считает строки зоны в
таблице, и «6» читается быстрее, чем «шесть». Имя функции `setBlockVisibility()` — не
`toggleBlockVisibility()`: экран шлёт два разных намерения (`show` и `hide`), и слежение за
состоянием в имени означало бы, что кнопка «Скрыть» на скрытом блоке показывает блок.

- [ ] **Шаг 4: Достижимость семи ключей словаря**

В `tests/db/NewsNoticeCoverageTest.php`: после `private array $itemHits = [];` добавить свойство,
рядом с `testОтказНикогдаНеНесётКлючаУведомления` — новый метод, в перечне отказов — семь строк,
перед `private function catalog()` — хелпер.

```php
    /** @var array<string, string> */
    private array $blockHits = [];
```

```php
    public function testКаждыйТекстБлокаДостигаетсяДействием(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        $tagId = resolveTagIds(['Пробный тег'])[0];

        $this->block(createBlock('home', 'list', 'Свежие материалы', 5), 'created');
        $id = $this->blockIdOf('home', 'Свежие материалы');

        $this->block(renameBlock($id, 'Свежие за неделю'), 'renamed', $id);
        $this->block(retargetBlock($id, 'category', $categoryId), 'retargeted', $id);
        $this->block(retargetBlock($id, 'tag', $tagId), 'retargeted', $id);
        $this->block(retargetBlock($id, '', ''), 'retargeted', $id);
        $this->block(limitBlock($id, 3), 'limited', $id);
        $this->block(setBlockVisibility($id, false), 'hidden', $id);
        $this->block(setBlockVisibility($id, true), 'shown', $id);
        $this->block(deleteBlock($id), 'deleted', $id);

        // Три ключа порядка (`moved`, `topped`, `bottomed`) приносят стрелки в задаче 7. Список
        // назван, а не оставлен пустым: если задача 6 добавит в словарь текст, который не
        // достигает ни одна её операция, проверка красна уже сейчас — мёртвый плашечный ключ не
        // должен переждать одну задачу.
        self::assertSame(
            ['moved', 'topped', 'bottomed'],
            array_values(array_diff(array_keys(NEWS_BLOCK_NOTICE_TEXT), array_keys($this->blockHits))),
            'Эти тексты блока достигаются только стрелками — они приходят в задаче 7'
        );
    }

    private function blockIdOf(string $zone, string $title): string
    {
        return $this->column('SELECT id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', [$zone, $title]);
    }

    /** @param array{ok: bool, notice: string, error: string} $result */
    private function block(array $result, string $expect, string $id = ''): void
    {
        self::assertTrue($result['ok'], $expect . ' / ' . $id . ': ' . (string) $result['error']);
        self::assertSame($expect, (string) $result['notice'], $expect . ' / ' . $id);
        self::assertArrayHasKey($expect, NEWS_BLOCK_NOTICE_TEXT, $expect . ': ключ обязан быть в словаре раздела');

        $this->blockHits[$expect] = $id;
    }
```

Семь строк в перечень `testОтказНикогдаНеНесётКлючаУведомления` (после `setItemPinned($draft, true),`):

```php
            // Блоки: тот же договор, что у справочников и материалов — отказ не называет ключ.
            createBlock('sidebar', 'list', 'Чужая зона', 5),
            createBlock('home', 'carousel', 'Чужой вид', 5),
            renameBlock('net-takogo-identifikatora', 'Новое название'),
            retargetBlock('net-takogo-identifikatora', 'category', categoryIdOf('metodika')),
            limitBlock('net-takogo-identifikatora', 3),
            setBlockVisibility('net-takogo-identifikatora', true),
            deleteBlock('net-takogo-identifikatora'),
```

- [ ] **Шаг 5: Прогнать зелёный результат**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/blocks.php"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter "NewsBlocksTest|NewsNoticeCoverageTest"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter "NewsBlockRulesTest|NewsConstantsTest"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "NewsVisibilityTest|AppSqlTest|SqlSafetyTest|NoticeKeyTest"`
Ожидание: всё зелёное. `NewsVisibilityTest` не требует правок: блок-операции читают
`t('news_block')`, `t('news_category')` и `t('news_tag')`, а его маркеры стоят на `tbl_news_item`
и `feedScope(`. `NoticeKeyTest` молчит: ни одна из семи функций не вызывает
`finishAppAction()` — это делает файл действий задачи 9.

- [ ] **Шаг 6: Обратные замеры**

| Мутация | Что обязано покраснеть |
| --- | --- |
| убрать ветку `blockCountInZone(...) >= NEWS_BLOCK_PER_ZONE` | `testСедьмойБлокВЗонеОтказывается`, `testСкрытыйБлокЗанимаетМестоВЗоне` |
| в счётчике зоны выбросить `WHERE zone = ?` | `testПределСчитаетсяВЗонеАНеНаВсёмСайте` (шестой блок `home` запрещает первый `article`) |
| в `MAX(order_index)` выбросить `WHERE zone = ?` | `testНовыйБлокПолучаетСледующийПорядокВСвоейЗоне` (блок `article` получает четвёртый порядок) |
| `renameBlock()` — своя проверка длины вместо `checkBlockFields()` | `testПравкаЗаголовкаОтказываетсяТемЖеТекстомЧтоИСоздание` (тексты расходятся) |
| `retargetBlock()` — звать `blockTargetProblem('list', …)` вместо `(string) $row['kind']` | `testСменаЦелиБерётВидИзСтроки` (рубрика записывается «похожему») |
| в `createBlock()` писать `''` вместо `null` при снятой цели | `testБлокБезЦелиХранитNullАНеПустуюСтроку` |
| в `retargetBlock()` очищать только `target_kind`, оставляя `target_id` | `testСнятаяЦельОбеКолонкиСтановятсяNull` |
| `blockTargetExists()` — всегда `true` | `testНесуществующаяЦельОтказывается` |
| `visible = 1` в `INSERT` → `visible = 0` | `testНовыйБлокПолучаетСледующийПорядокВСвоейЗоне` (читатель не отдаёт ни одного заголовка) |
| `setBlockVisibility()` — переключатель `!$row['visible']` вместо направления | `testПоказИСкрытиеБлока` (второе «скрыть» возвращает `shown`) |
| убрать ветку `if ($row === null)` в `deleteBlock()` | `testЧужойИдентификаторОтказываетсяОднимТекстом` (несуществующий блок «удаляется») |

- [ ] **Шаг 7: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

```bash
git add apps/news/lib/blocks.php tests/db/NewsBlocksTest.php tests/db/NewsNoticeCoverageTest.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Раздел «Новости»: операции записи блоков вывода"
```

Отчёт — в `.superpowers/sdd/part1/task-287-6-report.md`: дословный вывод шага 2 со всеми пятью
`Call to undefined function`, результат зелёного прогона шага 5, список одиннадцати обратных
замеров с номерами упавших проверок и номер строки `testКаждыйТекстБлокаДостигаетсяДействием` с
промежуточным списком трёх ключей.

---

### Задача 7: стрелки порядка и полное покрытие словаря

**Файлы:**
- Изменить: `apps/news/lib/blocks.php` (в конце файла, после `deleteBlock()`: седьмая операция —
  `moveBlock()`)
- Изменить: `tests/db/NewsBlocksTest.php` (секция «Порядок в зоне»: шесть проверок и одна строка в
  перечне чужих идентификаторов)
- Изменить: `tests/db/NewsNoticeCoverageTest.php` (четыре строки движения, строка отказа, финальное
  утверждение пустого списка вместо промежуточного)

**Интерфейсы:**
- Потребляет: `t()` / `one()` / `all()` / `run()` / `tx()` (`core/db.php:108` — `tx()` принимает
  `callable` и возвращает `mixed`), `utcStamp()` / `nowUtc()` (`core/date.php`), `logInfo()`
  (`core/log.php`), константы задачи 3, помощники `blockIdOf()` и `orderOf()` задачи 6.
- Производит:
  - `moveBlock(string $id, string $direction): array{ok: bool, notice: string, error: string}` —
    седьмая операция записи. `$direction` = `'up'` или `'down'`: задача 9 вызывает её из веток
    `match` с этими двумя литералами.
  - Закрытый словарь `NEWS_BLOCK_NOTICE_TEXT`: после этой задачи достижимы все десять ключей, и
    проверка покрытости не оставляет ни одного текста без действия.

**Четыре правила этой задачи:**

1. **Обход порядка идёт внутри зоны** (§5 спецификации). Без этого условия движение строки `home`
   пересобирало бы `order_index` и в зоне `article`: плотность 1..N осталась бы только там, где её
   пересобрали. Проверка `testДвижениеНеТрогаетПорядокДругойЗоны` стоит именно на этом.
2. **Скрытый блок участвует в порядке.** Во втором пропуске нет фильтра по `visible`: предел и
   плотность считаются по всем строкам зоны (правило 3 задачи 6), и стрелки двигают строку через
   скрытую соседку, а не через неё перепрыгивают. Иначе человек нажал «вверх» и получил скачок на
   две позиции.
3. **Край зоны — пометка, а не отказ.** На первой строке `up` возвращает `topped`, на последней
   `bottomed`, в обоих случаях `ok => true` и база не тронута: отказ заставлял бы экрана показывать
   плашку ошибки там, где ничего не пошло не так. Те же исходы держат рубрики
   (`apps/news/lib/catalog.php:311`).
4. **Порядок пересобирается целиком** — `tx()` и цикл по всему списку зоны, как у `moveCategory()`
   (`apps/news/lib/catalog.php:319-326`): так исправляются дубликаты и разрывы `order_index`,
   оставленные ручной правкой базы. Побочный эффект тот же, что у рубрик, — соседним строкам зоны
   обновляется `updated_at`; экран блока это поле не печатает (задача 8), поэтому расхождения в
   разметке не видно.

- [ ] **Шаг 1: RED — проверки движения**

В `tests/db/NewsBlocksTest.php` после `testЧужойИдентификаторОтказываетсяОднимТекстом` (то есть
перед приватными помощниками) дописать секцию. В первой проверке заголовки идут обратно порядку:
иначе мутация «обход по заголовку вместо `order_index`» краснит ровно те же строки, что и верный
код, и проверка ничего не доказывает.

```php
    // ── Порядок в зоне ───────────────────────────────────

    public function testСтрелкаМеняетМестамиСоседейВСвоейЗоне(): void
    {
        // Заголовки идут обратно порядку: «О школе» по алфавиту раньше «Свежие материалы», поэтому
        // обход по заголовку дал бы другую перестановку и эту проверку покраснил.
        $readToday = self::makeBlock('home', 'list', 'Читают сегодня', ['order_index' => 2]);
        self::makeBlock('home', 'list', 'Свежие материалы', ['order_index' => 1]);
        self::makeBlock('home', 'list', 'О школе', ['order_index' => 3]);

        self::assertSame('moved', (string) moveBlock($readToday, 'up')['notice']);

        self::assertSame(
            ['Читают сегодня', 'Свежие материалы', 'О школе'],
            array_column(newsBlockRows('home'), 'title'),
            'средняя строка встала первой, сосед опустился на её место'
        );
        self::assertSame('1', $this->orderOf('home', 'Читают сегодня'));
        self::assertSame('2', $this->orderOf('home', 'Свежие материалы'));
        self::assertSame('3', $this->orderOf('home', 'О школе'));
        self::assertSame(
            '3',
            $this->column('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ?', ['home']),
            'движение не создаёт и не удаляет строк'
        );
    }

    public function testДвижениеНеТрогаетПорядокДругойЗоны(): void
    {
        self::makeBlock('home', 'list', 'Первый дома', ['order_index' => 1]);
        $secondHome = self::makeBlock('home', 'list', 'Второй дома', ['order_index' => 2]);
        self::makeBlock('article', 'related', 'Первый в карточке', ['order_index' => 1]);
        self::makeBlock('article', 'related', 'Второй в карточке', ['order_index' => 2]);

        self::assertSame('moved', (string) moveBlock($secondHome, 'up')['notice']);

        // Без фильтра по зоне пересборка проходила бы по всем строкам сайта: соседняя зона получила
        // бы порядки 3 и 4 и исчезла бы из своей выборки (§5 спецификации).
        self::assertSame(
            ['Первый в карточке', 'Второй в карточке'],
            array_column(newsBlockRows('article'), 'title'),
            'зона «article» не участвует в движении зоны «home»'
        );
        self::assertSame('1', $this->orderOf('article', 'Первый в карточке'));
        self::assertSame('2', $this->orderOf('article', 'Второй в карточке'));
    }

    public function testСкрытыйБлокУчаствуетВПорядкеЗоны(): void
    {
        self::makeBlock('home', 'list', 'Видимый первый', ['order_index' => 1]);
        $hidden = self::makeBlock('home', 'list', 'Скрытый', ['order_index' => 2, 'visible' => 0]);
        $third = self::makeBlock('home', 'list', 'Третий', ['order_index' => 3]);

        self::assertSame('moved', (string) moveBlock($third, 'up')['notice']);

        // Строка зоны считает и скрытых соседей: «вверх» передвигает на одну позицию, а не
        // перепрыгивает через скрытый блок.
        self::assertSame('2', $this->orderOf('home', 'Третий'));
        self::assertSame('3', $this->orderOf('home', 'Скрытый'));
        self::assertSame($hidden, $this->blockIdOf('home', 'Скрытый'), 'скрытая строка осталась собой');

        // Печать при этом знает только про видимые: порядок считается по зоне целиком, а читатель
        // отсекает скрытое (задача 4).
        self::assertSame(['Видимый первый', 'Третий'], array_column(newsBlockRows('home'), 'title'));
    }

    public function testОдиночнаяСтрокаЗоныНеДвигается(): void
    {
        $only = self::makeBlock('home', 'list', 'Единственный', ['order_index' => 1]);

        $up = moveBlock($only, 'up');
        self::assertTrue($up['ok'], 'край зоны — не отказ');
        self::assertSame('topped', (string) $up['notice']);
        self::assertSame('', (string) $up['error']);

        $down = moveBlock($only, 'down');
        self::assertTrue($down['ok'], 'край зоны — не отказ');
        self::assertSame('bottomed', (string) $down['notice']);

        self::assertSame('1', $this->orderOf('home', 'Единственный'), 'пометка не пишет базу');
    }

    public function testКрайниеСтрокиМногойЗоныВозвращаютПометку(): void
    {
        $top = self::makeBlock('home', 'list', 'Первый', ['order_index' => 1]);
        $bottom = self::makeBlock('home', 'list', 'Последний', ['order_index' => 2]);

        self::assertSame('topped', (string) moveBlock($top, 'up')['notice']);
        self::assertSame('bottomed', (string) moveBlock($bottom, 'down')['notice']);

        // Два разных ключа за одно и то же «ничего не произошло»: вверх и вниз человек видит
        // разные плашки и понимает, к какому краю он упёрся.
        self::assertSame(['Первый', 'Последний'], array_column(newsBlockRows('home'), 'title'));
        self::assertSame('1', $this->orderOf('home', 'Первый'));
        self::assertSame('2', $this->orderOf('home', 'Последний'));
    }

    public function testДвижениеПересобираетРазреженныйПорядокПлотно(): void
    {
        // Дубликаты и разрывы оставляла ручная правка базы. Тот же довод, что у `moveCategory()`:
        // пересборка чинит их целиком, а не меняет местами две строки.
        self::makeBlock('home', 'list', 'Первый', ['order_index' => 7]);
        self::makeBlock('home', 'list', 'Второй', ['order_index' => 7]);
        $third = self::makeBlock('home', 'list', 'Третий', ['order_index' => 12]);

        self::assertSame('moved', (string) moveBlock($third, 'up')['notice']);

        $orders = array_map(
            static fn (array $row): string => (string) $row['order_index'],
            all('SELECT order_index FROM ' . t('news_block') . ' WHERE zone = ? ORDER BY order_index', ['home'])
        );

        self::assertSame(['1', '2', '3'], $orders, 'в зоне плотность 1..N без разрывов и дубликатов');
    }
```

Одной строкой больше в перечень `testЧужойИдентификаторОтказываетсяОднимТекстом` (после
`deleteBlock('net-takogo-identifikatora'),`):

```php
            moveBlock('net-takogo-identifikatora', 'up'),
```

и в его комментарии цифры правятся на шесть: «Шесть операций правки, удаления и движения отвечают
одной строкой … а не различать шесть формулировок».

- [ ] **Шаг 2: Убедиться, что проверки красные**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter "NewsBlocksTest|NewsNoticeCoverageTest"`
Ожидание: шесть новых проверок и перечень чужих идентификаторов падают на `Call to undefined
function moveBlock()`; десять проверок читателя и операций задачи 6 остаются зелёными.

- [ ] **Шаг 3: GREEN — седьмая операция**

В `apps/news/lib/blocks.php` после `deleteBlock()`:

```php
/**
 * Стрелки порядка. Механика `moveCategory()` (`apps/news/lib/catalog.php:299-331`) с одной
 * поправкой §5 спецификации: обход идёт внутри зоны, поэтому движение строки `home` не пересобирает
 * порядки зоны `article`.
 *
 * Направление приходит из намерения экрана, а не из состояния строки: `op` у ветки `match` задачи 8
 * бывает только `up` или `down`, и третья ветка здесь была бы мёртвым кодом.
 *
 * @return array{ok: bool, notice: string, error: string}
 */
function moveBlock(string $id, string $direction): array
{
    // Одна выборка отвечает и за «блок есть», и за его соседей: отдельный `one()` за идентификатором
    // добавил бы второй вопрос базе о том же состоянии.
    $rows = all('SELECT id, zone FROM ' . t('news_block') . ' ORDER BY order_index, title');

    $zone = '';

    foreach ($rows as $row) {
        if ((string) $row['id'] === $id) {
            $zone = (string) $row['zone'];
            break;
        }
    }

    if ($zone === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    $ids = [];
    $index = -1;

    foreach ($rows as $row) {
        if ((string) $row['zone'] !== $zone) {
            continue;
        }

        // Позиция считается до добавления строки: счётчик уже добавленных и есть её индекс.
        if ((string) $row['id'] === $id) {
            $index = count($ids);
        }

        $ids[] = (string) $row['id'];
    }

    $target = $direction === 'up' ? $index - 1 : $index + 1;
    if ($target < 0 || $target >= count($ids)) {
        return ['ok' => true, 'notice' => $direction === 'up' ? 'topped' : 'bottomed', 'error' => ''];
    }

    $next = $ids;
    $next[$index] = $ids[$target];
    $next[$target] = $ids[$index];

    $stamp = utcStamp(nowUtc());
    tx(static function () use ($next, $stamp): void {
        foreach ($next as $position => $blockId) {
            run(
                'UPDATE ' . t('news_block') . ' SET order_index = ?, updated_at = ? WHERE id = ?',
                [$position + 1, $stamp, $blockId]
            );
        }
    });

    logInfo('news.block_moved', ['blockId' => $id, 'zone' => $zone, 'direction' => $direction]);

    return ['ok' => true, 'notice' => 'moved', 'error' => ''];
}
```

`$index` остаётся целым и без ветки «не найден»: идентификатор найден в первом пропуске той же
выборки, значит второй пропуск обязан встретить его в списке своей зоны. Второй отказ поверх первого
добавил бы в файл состояние, которое ни одна проверка не способна вызвать, — а проверку, которая не
может покраснеть, в этом проекте чинят, а не оставляют (требование к силе проверок из §6
спецификации).

- [ ] **Шаг 4: Достижимы все десять ключей**

В `tests/db/NewsNoticeCoverageTest.php`: после `$this->block(setBlockVisibility($id, true), 'shown', $id);`
вставить движение, промежуточное утверждение задачи 6 заменить на пустой список, и в перечень
отказов после `deleteBlock('net-takogo-identifikatora'),` добавить строку движения.

```php
        $this->block(createBlock('home', 'list', 'Сосед по зоне', 4), 'created');
        $neighbour = $this->blockIdOf('home', 'Сосед по зоне');

        // Три ключа порядка: `moved` в обе стороны и пометки обоих краёв. Сосед нужен, чтобы
        // движение вообще было куда: одна строка зоны даёт только пометки.
        $this->block(moveBlock($neighbour, 'up'), 'moved', $neighbour);
        $this->block(moveBlock($neighbour, 'up'), 'topped', $neighbour);
        $this->block(moveBlock($neighbour, 'down'), 'moved', $neighbour);
        $this->block(moveBlock($neighbour, 'down'), 'bottomed', $neighbour);

        $this->block(deleteBlock($neighbour), 'deleted', $neighbour);
```

```php
        // Словарь блока закрыт: ни одного текста без действия, которое его приносит.
        self::assertSame(
            [],
            array_values(array_diff(array_keys(NEWS_BLOCK_NOTICE_TEXT), array_keys($this->blockHits))),
            'Этот текст блока не достигается ни одним действием'
        );
```

```php
            moveBlock('net-takogo-identifikatora', 'up'),
```

- [ ] **Шаг 5: Прогнать зелёный результат**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter "NewsBlocksTest|NewsNoticeCoverageTest"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "NewsVisibilityTest|NoticeKeyTest|DateUsageTest|SqlSafetyTest"`
Ожидание: всё зелёное. `NewsVisibilityTest` не правится: `moveBlock()` читает `t('news_block')`, а
его маркеры стоят на `tbl_news_item`, `newsItemFrom(` и `feedScope(`. `SqlSafetyTest` молчит: оба
запроса собраны конкатенацией с `t()` и плейсхолдерами, переменная в текст SQL не попадает.

- [ ] **Шаг 6: Обратные замеры**

| Мутация | Что обязано покраснеть |
| --- | --- |
| во втором пропуске убрать фильтр по зоне (`if ((string) $row['zone'] !== $zone) continue;`) | `testДвижениеНеТрогаетПорядокДругойЗоны` (зона `article` получает порядки 3 и 4) |
| `ORDER BY order_index, title` → `ORDER BY title` | `testСтрелкаМеняетМестамиСоседейВСвоейЗоне` (переставляются не сосед по порядку, а сосед по алфавиту) |
| во втором пропуске добавить `if ((int) $row['visible'] !== 1) continue;` (и `visible` в выборку) | `testСкрытыйБлокУчаствуетВПорядкеЗоны` (строка перепрыгивает через скрытого соседа) |
| убрать ветку `if ($target < 0 || $target >= count($ids))` | `testОдиночнаяСтрокаЗоныНеДвигается`, `testКрайниеСтрокиМногойЗоныВозвращаютПометку` (край возвращает `moved` и пишет базу) |
| в пометке направления перепутать (`'down' ? 'topped' : 'bottomed'`) | `testОдиночнаяСтрокаЗоныНеДвигается`, `testКрайниеСтрокиМногойЗоныВозвращаютПометку` |
| пересобирать только две переставленные строки вместо цикла по `$next` | `testДвижениеПересобираетРазреженныйПорядокПлотно` (разреженность 7, 7, 12 остаётся) |
| убрать ветку `if ($zone === '')` | `testЧужойИдентификаторОтказываетсяОднимТекстом` (несуществующий блок получает `topped`) |
| `logInfo('news.block_moved', …)` → `logInfo('news.block_saved', …)` | доказывается рецензией, а не тестом: ни одна проверка не читает вывод журнала (§6 спецификации, тот же приём, что у `news.category_moved`) |

- [ ] **Шаг 7: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

```bash
git add apps/news/lib/blocks.php tests/db/NewsBlocksTest.php tests/db/NewsNoticeCoverageTest.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Раздел «Новости»: стрелки порядка блоков и закрытый словарь уведомлений"
```

Отчёт — в `.superpowers/sdd/part1/task-287-7-report.md`: дословный вывод шага 2 с `Call to
undefined function moveBlock()`, результат зелёного прогона шага 5, список семи ловящихся мутаций с
номерами упавших проверок, строка `testКаждыйТекстБлокаДостигаетсяДействием` с пустым списком
ключей и дословный вывод шага 6 по каждому замеру.

---

### Задача 8: правила экрана — строки зон, составная цель и подписи действий

**Файлы:**
- Изменить: `apps/news/lib/blocks.php` (в конце файла: разбор цели из формы, строки экрана «Блоки»,
  опции выбора цели)
- Изменить: `apps/news/lib/admin.php` (в конце файла, после `catalogFailure()`: девять действий с
  подписями, подписи зон и видов, кнопки строки, раздача строк по зонам)
- Изменить: `tests/unit/NewsBlockRulesTest.php` (секция «Цель из формы»: шесть проверок)
- Изменить: `tests/unit/NewsAdminRulesTest.php` (секция «Блоки»: пять проверок и приватный
  помощник `block()`)
- Изменить: `tests/db/NewsBlocksTest.php` (секция «Строки экрана»: одиннадцать проверок)

**Интерфейсы:**
- Потребляет: `adminCategoryRows()` (`apps/news/lib/catalog.php:129`), `adminTagRows()` (:389),
  `categoryIdOf()` (:35), `createCategory()` (:161), `renameCategory()` (:194),
  `toggleCategoryVisibility()` (:251), `deleteCategory()` (:272), `resolveTagIds()` (:421),
  `NEWS_BLOCK_ZONES` / `NEWS_BLOCK_KINDS` / `NEWS_BLOCK_TARGETS` (задача 3),
  `all()` / `t()` / `value()` (`core/db.php`); в тестах — фабрику `makeBlock()` (задача 4),
  помощники `titles()` (задача 4) и `column()` (`tests/db/NewsDbTestCase.php:125`),
  `createBlock()` (задача 6).
- Производит:

```
/** @var list<string> */
const NEWS_BLOCK_OPS = ['create', 'rename', 'retarget', 'limit', 'show', 'hide', 'up', 'down', 'delete'];
const NEWS_BLOCK_OP_LABEL = ['create', 'rename', 'retarget', 'limit', 'show', 'hide',
                             'up', 'down', 'delete']; // подписи по каждому ключу — шаг 5
const NEWS_BLOCK_ZONE_LABEL = ['home' => 'На главной', 'category' => 'В рубрике', 'article' => 'В материале'];
const NEWS_BLOCK_KIND_LABEL = ['list' => 'Список материалов', 'related' => 'Похожие материалы'];

parseBlockTarget(string $raw): array{kind: string, id: string}
adminBlockRows(): list<array{id: string, zone: string, kind: string, title: string,
    take: int, visible: bool, order: int,
    target: null|array{kind: string, id: string, label: string, alive: bool}}>
blockTargetOptions(): list<array{kind: string, label: string,
    items: list<array{value: string, title: string, visible: bool}>}>
newsBlockRowActions(array $row): list<array{op: string, label: string}>
groupBlockRows(array $rows): array<string, list<array{id: string, zone: string, kind: string,
    title: string, take: int, visible: bool, order: int,
    target: null|array{kind: string, id: string, label: string, alive: bool}>>>
```

Задача 9 читает всё это в шаблоне экрана и в файле действий: `parseBlockTarget()` — единственный
канал, через который значение цели из POST попадает в писатели.

**Правила этой задачи:**

1. **Экран показывает все строки, включая скрытые.** Читатель задачи 4 отбирает `visible = 1` —
   его волнует страница. Экран, который смотрит человек, отбирает всё: снятый с сайта блок обязан
   остаться в таблице со своей кнопкой «Показать», иначе строка исчезает из админки вместе со
   способом её вернуть (§5 спецификации).
2. **Названия целей берутся у справочников.** `adminCategoryRows()` и `adminTagRows()` — те же
   выборки, что печатают экраны «Рубрики» и «Теги». Свой `SELECT title` в `blocks.php` дал бы
   экран блоков, отстающий от переименования, и второе определение того, как зовут рубрику.
3. **Цель в форме — одно составное значение** (`category:<id>`, `tag:<id>`, пустая строка — «без
   цели»), вердикт Р13. Два независимых поля позволяют отправить пару «вид — рубрика,
   идентификатор — метка», а такую пару читатель толкует по-своему. `parseBlockTarget()` только
   расщепляет по первому двоеточию; «что такое легальная цель» остаётся у `blockTargetProblem()`
   (задача 3), иначе подделанный `shop:9` молча превратился бы в «цели нет» и разворотил бы
   подборку без единого отказа.
4. **Мёртвая цель показывается, а не прячется.** Строка, у которой цели больше нет (рубрику
   удалили, данные поправили руками), остаётся в таблице с пометкой `alive = false`: читатель
   задачи 4 отдаёт по такой строке общую ленту, и редактор обязан видеть, что подборка съехала.
5. **Таблицы — только объявленные зоны, и пустая зона остаётся.** Порядок задаёт
   `NEWS_BLOCK_ZONES`, а не база: человек, который ничего ещё не настроил, обязан найти, где
   начинать. Строка с необъявленной зоной не печатается нигде (вердикт Р12).

- [ ] **Шаг 1: RED — unit-проверки разбора цели**

В `tests/unit/NewsBlockRulesTest.php` перед закрывающей скобкой класса дописать секцию:

```php
    // ── Цель из формы ────────────────────────────────────

    public function testПустаяСтрокаНеДаётНиВидаНиИдентификатора(): void
    {
        self::assertSame(['kind' => '', 'id' => ''], parseBlockTarget(''));
        self::assertSame(['kind' => '', 'id' => ''], parseBlockTarget('   '), 'Пробелы срезаются до проверки');
    }

    public function testРубрикаИМеткаРасщепляютсяПоПервомуДвоеточию(): void
    {
        self::assertSame(['kind' => 'category', 'id' => 'zhizn-shkoly'], parseBlockTarget('category:zhizn-shkoly'));
        self::assertSame(['kind' => 'tag', 'id' => 'vypusknik'], parseBlockTarget('tag:vypusknik'));
    }

    public function testЗначениеБезРазделителяНеПридумываетВид(): void
    {
        // «prosto-id» не превращается в рубрику с идентификатором «prosto-id»: вид неизвестен, и
        // проверка обязана его отвергнуть, а не выбрать первый словарь.
        self::assertSame(['kind' => '', 'id' => 'prosto-id'], parseBlockTarget('prosto-id'));
    }

    public function testДвоеточиеВИдентификатореОстаётсяВЦели(): void
    {
        // Идентификатор строит `newId()`, и двоеточия в нём нет. Разбор остаётся предсказуемым:
        // всё после первого разделителя — идентификатор, а не ещё один вид цели.
        self::assertSame(['kind' => 'tag', 'id' => 'a:b'], parseBlockTarget('tag:a:b'));
    }

    public function testЧужойВидЦелиДоходитДоПроверкиИОтказывается(): void
    {
        $parsed = parseBlockTarget('shop:9');

        self::assertSame(['kind' => 'shop', 'id' => '9'], $parsed, 'Парсер не решает, жива ли цель');
        self::assertNotSame('', blockTargetProblem('list', $parsed['kind'], $parsed['id'], true));
        self::assertNotSame('', blockTargetProblem('list', $parsed['kind'], $parsed['id'], false));
    }

    public function testНеполнаяЦельОтказывается(): void
    {
        self::assertNotSame('', blockTargetProblem('list', 'category', '', true), 'Рубрика без идентификатора — обрывок');
    }
```

- [ ] **Шаг 2: RED — unit-проверки правил экрана**

В `tests/unit/NewsAdminRulesTest.php` после приватного помощника `item()` вставить помощник, а
перед закрывающей скобкой класса — секцию:

```php
    /**
     * Строка экрана «Блоки» в том виде, как её отдаёт `adminBlockRows()` (задача 8): шаблон читает
     * готовые поля и не пересчитывает видимость сам.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function block(array $overrides = []): array
    {
        return array_merge([
            'id' => 'id-bloka',
            'zone' => 'home',
            'kind' => 'list',
            'title' => 'Свежие материалы',
            'take' => 5,
            'visible' => true,
            'order' => 0,
            'target' => null,
        ], $overrides);
    }
```

```php
    // ── Блоки вывода ─────────────────────────────────────

    public function testДействияБлоковОбещаныДевятьИУКаждогоЕстьПодпись(): void
    {
        // Порядок этого списка — порядок, в котором ветки `match` разбирают POST (задача 9), и
        // страж договора действий сверяет его с обработчиком: новое действие без ветки не проходит.
        self::assertSame(
            ['create', 'rename', 'retarget', 'limit', 'show', 'hide', 'up', 'down', 'delete'],
            NEWS_BLOCK_OPS
        );
        self::assertSame(NEWS_BLOCK_OPS, array_keys(NEWS_BLOCK_OP_LABEL), 'Действию без подписи нечего печатать');

        foreach (NEWS_BLOCK_OP_LABEL as $op => $label) {
            self::assertNotSame('', $label, (string) $op);
        }
    }

    public function testЗоныИВидыБлоковПодписаны(): void
    {
        // Подписи читают заголовки трёх таблиц и две формы. Ключи обязаны совпасть со списками
        // задачи 3: зона без подписи напечатала бы пустой заголовок таблицы.
        self::assertSame(NEWS_BLOCK_ZONES, array_keys(NEWS_BLOCK_ZONE_LABEL));
        self::assertSame(NEWS_BLOCK_KINDS, array_keys(NEWS_BLOCK_KIND_LABEL));
    }

    public function testКнопкиСтрокиБлокаЗависятОтСостоянияПоказа(): void
    {
        self::assertSame(
            [
                ['op' => 'hide', 'label' => 'Скрыть'],
                ['op' => 'up', 'label' => 'Вверх'],
                ['op' => 'down', 'label' => 'Вниз'],
                ['op' => 'delete', 'label' => 'Удалить'],
            ],
            newsBlockRowActions($this->block())
        );

        self::assertSame('show', newsBlockRowActions($this->block(['visible' => false]))[0]['op'],
            'Скрытому блоку положена кнопка «Показать», а не «Скрыть»');
    }

    public function testПравкиСтрокиНеКнопкиАПоля(): void
    {
        // Заголовок, цель и число — три формы внутри строки. Их подписи шаблон читает прямо из
        // словаря, поэтому в списке кнопок их нет: кнопка «Переименовать» рядом с полем ввода
        // означала бы два способа сделать одно и то же.
        $ops = array_column(newsBlockRowActions($this->block()), 'op');

        foreach (['rename', 'retarget', 'limit'] as $inline) {
            self::assertNotContains($inline, $ops);
        }
    }

    public function testСтрокиРаздаютсяПоЗонамВСпискеЗон(): void
    {
        $grouped = groupBlockRows([
            ['zone' => 'article', 'id' => 'a1'],
            ['zone' => 'home', 'id' => 'h1'],
            ['zone' => 'sidebar', 'id' => 'x1'],
        ]);

        // Пустая зона обязана остаться в списке со своей таблицей и формой добавления: иначе
        // человек, который ещё ничего не настроил, не нашёл бы, где начинать.
        self::assertSame(NEWS_BLOCK_ZONES, array_keys($grouped));
        self::assertSame(['h1'], array_column($grouped['home'], 'id'));
        self::assertSame(['a1'], array_column($grouped['article'], 'id'));
        self::assertSame([], $grouped['category']);
    }
```

Пятая проверка секции — `testПравкиСтрокиНеКнопкиАПоля`: она держит договор «правка заголовка,
цели и числа — это поля внутри строки, а не кнопка», который задача 9 печатает в шаблоне.

- [ ] **Шаг 3: RED — db-проверки строк экрана**

В `tests/db/NewsBlocksTest.php` перед закрывающей скобкой класса дописать секцию:

```php
    // ── Строки экрана ────────────────────────────────────

    public function testСтрокиЭкранаИдутВПорядкеЗоныАНеПоАлфавиту(): void
    {
        self::makeBlock('home', 'list', 'Январь', ['order_index' => 30]);
        self::makeBlock('home', 'list', 'Август', ['order_index' => 10]);
        self::makeBlock('category', 'list', 'Рубричный', ['order_index' => 20]);

        // Порядок экрана — тот же, что печатает страница: человек правит стрелками именно ту
        // последовательность, которую читатель отдаёт читателю.
        self::assertSame(['Рубричный', 'Август', 'Январь'], $this->titles(adminBlockRows()));
    }

    public function testЭкранПоказываетИСкрытыеСтроки(): void
    {
        self::makeBlock('home', 'list', 'Видимый', ['order_index' => 10]);
        $hiddenId = self::makeBlock('home', 'list', 'Скрытый', ['order_index' => 20, 'visible' => 0]);

        $rows = adminBlockRows();

        self::assertCount(2, $rows, 'Снятый с сайта блок обязан остаться в админке: иначе его не вернуть');
        self::assertSame($hiddenId, $rows[1]['id']);
        self::assertFalse($rows[1]['visible']);
    }

    public function testСтрокаНесётВсеПоляКоторыеЧитаетШаблон(): void
    {
        $id = self::makeBlock('article', 'related', 'Похожие чтения', ['order_index' => 4, 'take_limit' => 3]);

        self::assertSame(
            [[
                'id' => $id,
                'zone' => 'article',
                'kind' => 'related',
                'title' => 'Похожие чтения',
                'take' => 3,
                'visible' => true,
                'order' => 4,
                'target' => null,
            ]],
            adminBlockRows()
        );
    }

    public function testПодписьРубрикиСледуетЗаПереименованием(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        self::makeBlock('category', 'list', 'Из рубрики', ['target_kind' => 'category', 'target_id' => $categoryId]);

        self::assertSame('Жизнь школы', adminBlockRows()[0]['target']['label']);

        self::assertTrue(renameCategory($categoryId, 'Жизнь класса')['ok']);

        self::assertSame('Жизнь класса', adminBlockRows()[0]['target']['label'],
            'Название цели экран берёт из справочника, а не из копии в своей строке');
    }

    public function testМеткаПодписываетсяСвоейНадписью(): void
    {
        [$tagId] = resolveTagIds(['Выпускник']);
        self::makeBlock('home', 'list', 'По метке', ['target_kind' => 'tag', 'target_id' => $tagId]);

        $target = adminBlockRows()[0]['target'];

        self::assertSame('tag', $target['kind']);
        self::assertSame('Выпускник', $target['label']);
        self::assertTrue($target['alive']);
    }

    public function testБлокБезЦелиНесётПустуюЦель(): void
    {
        self::makeBlock('home', 'list', 'Вся лента');

        self::assertNull(adminBlockRows()[0]['target']);
    }

    public function testУдалённаяЦельПомечаетсяНедоступной(): void
    {
        self::assertTrue(createCategory('Праздники')['ok']);
        $categoryId = $this->column('SELECT id FROM ' . t('news_category') . ' WHERE title = ?', ['Праздники']);
        self::makeBlock('home', 'list', 'Сирота', ['target_kind' => 'category', 'target_id' => $categoryId]);

        self::assertTrue(deleteCategory($categoryId)['ok'], 'Рубрика без материалов удаляется');

        $target = adminBlockRows()[0]['target'];

        // Цели больше нет, но строка остаётся и говорит об этом (§5): читатель задачи 4 отдаёт по
        // такой строке общую ленту, и редактор обязан видеть, что подборка съехала.
        self::assertSame($categoryId, $target['id']);
        self::assertSame('', $target['label']);
        self::assertFalse($target['alive']);
    }

    public function testПолуразрушеннаяЦельНеИсчезаетСоСтроки(): void
    {
        // Ручная правка базы способна оставить вид цели без идентификатора. Такая строка
        // показывается мёртвой: молча считать её «блоком без цели» — значит спрятать поломку.
        self::makeBlock('home', 'list', 'Наполовину', ['target_kind' => 'category', 'target_id' => null]);

        $target = adminBlockRows()[0]['target'];

        self::assertIsArray($target);
        self::assertFalse($target['alive']);
    }

    public function testОпцииЦелиСоставныеИГруппированыПоСловарю(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        [$tagId] = resolveTagIds(['Выпускник']);

        $groups = blockTargetOptions();

        // Две группы в порядке NEWS_BLOCK_TARGETS: шаблон печатает из них `<optgroup>`, и третья
        // цель появилась бы в формах сама, без правки разметки.
        self::assertSame(['category', 'tag'], array_column($groups, 'kind'));
        self::assertSame(['Рубрики', 'Теги'], array_column($groups, 'label'));
        self::assertContains('category:' . $categoryId, array_column($groups[0]['items'], 'value'));
        self::assertContains('tag:' . $tagId, array_column($groups[1]['items'], 'value'));
    }

    public function testСкрытаяРубрикаОстаётсяВОпцияхЦели(): void
    {
        self::assertTrue(createCategory('Праздники')['ok']);
        $categoryId = $this->column('SELECT id FROM ' . t('news_category') . ' WHERE title = ?', ['Праздники']);
        self::assertTrue(toggleCategoryVisibility($categoryId)['ok']);

        $found = null;

        foreach (blockTargetOptions()[0]['items'] as $item) {
            if ($item['value'] === 'category:' . $categoryId) {
                $found = $item;
            }
        }

        self::assertNotNull($found, 'Снятие рубрики с показа не убирает её из выбора: блок на неё уже настроен');
        self::assertFalse($found['visible'], 'Скрытая рубрика помечена — шаблон печатает слово «скрыта»');
    }

    public function testПодделаннаяЦельНеПроходитЧерезПисателя(): void
    {
        $target = parseBlockTarget('shop:9');

        $result = createBlock('home', 'list', 'Подделка', 3, $target['kind'], $target['id']);

        self::assertFalse($result['ok']);
        self::assertSame('', $result['notice'], 'Отказ не несёт ключа уведомления');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_block')));
    }
```

- [ ] **Шаг 4: Прогнать и убедиться, что проверки красные**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter "NewsBlockRulesTest|NewsAdminRulesTest"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter NewsBlocksTest`
Ожидание: `Call to undefined function parseBlockTarget()`, `Call to undefined function adminBlockRows()`,
`Call to undefined function blockTargetOptions()`, `Undefined constant NEWS_BLOCK_OPS`,
`Undefined constant NEWS_BLOCK_ZONE_LABEL`, `Call to undefined function newsBlockRowActions()`,
`Call to undefined function groupBlockRows()`.

- [ ] **Шаг 5: GREEN — словари и правила строк в `lib/admin.php`**

В `apps/news/lib/admin.php` после `catalogFailure()` вставить:

```php
// ── Блоки вывода ─────────────────────────────────────────

// Действия экрана блоков ровно в том порядке, в котором их разбирает `match` в
// actions/blocks.php (задача 9). Страж договора действий сверяет этот список с обработчиком:
// новое действие без ветки match не проходит — тот же договор, что у рубрик и тегов.
/** @var list<string> */
const NEWS_BLOCK_OPS = ['create', 'rename', 'retarget', 'limit', 'show', 'hide', 'up', 'down', 'delete'];

/** @var array<string, string> */
const NEWS_BLOCK_OP_LABEL = [
    'create' => 'Добавить блок',
    'rename' => 'Сохранить заголовок',
    'retarget' => 'Сохранить цель',
    'limit' => 'Сохранить число',
    'show' => 'Показать',
    'hide' => 'Скрыть',
    'up' => 'Вверх',
    'down' => 'Вниз',
    'delete' => 'Удалить',
];

// Подписи зон и видов: их печатают заголовки трёх таблиц и две формы экрана. В базе и в адресе
// значение остаётся коротким (`home`, `list`) — человек читает русский текст, машина идентификатор.
/** @var array<string, string> */
const NEWS_BLOCK_ZONE_LABEL = [
    'home' => 'На главной',
    'category' => 'В рубрике',
    'article' => 'В материале',
];

/** @var array<string, string> */
const NEWS_BLOCK_KIND_LABEL = [
    'list' => 'Список материалов',
    'related' => 'Похожие материалы',
];

/**
 * Кнопки строки экрана «Блоки».
 *
 * Заголовок, цель и число правятся полями внутри строки — их подписи шаблон читает прямо из
 * NEWS_BLOCK_OP_LABEL, как у справочников. Здесь только то, что нажимается: направление показа,
 * две стрелки порядка и удаление. Направление берётся из состояния строки, а не переключается:
 * второе нажатие «Скрыть» обязано оставить блок скрытым (договор `setBlockVisibility()`, задача 6).
 * Стрелки печатаются и на первой, и на последней строке зоны: `moveBlock()` за краем возвращает
 * `topped` / `bottomed`, и отсутствие кнопки само по себе ничего человеку не объяснило бы.
 *
 * @param array{id: string, zone: string, kind: string, title: string, take: int, visible: bool, order: int, target: null|array{kind: string, id: string, label: string, alive: bool}} $row
 *
 * @return list<array{op: string, label: string}>
 */
function newsBlockRowActions(array $row): array
{
    $toggle = $row['visible'] === true ? 'hide' : 'show';

    $ops = [$toggle, 'up', 'down', 'delete'];
    $out = [];

    foreach ($ops as $op) {
        $out[] = ['op' => $op, 'label' => NEWS_BLOCK_OP_LABEL[$op]];
    }

    return $out;
}

/**
 * Раздать строки по зонам — по одной таблице на объявленную зону.
 *
 * Порядок задаёт NEWS_BLOCK_ZONES, а не база: пустая зона обязана остаться на экране со своей
 * формой добавления, иначе человек, который ничего ещё не настроил, не нашёл бы, где начинать.
 * Строка с необъявленной зоной не печатается нигде (вердикт Р12): создать её редактор не может —
 * `checkBlockFields()` отвергает чужую зону, — значит, пришла она из ручной правки базы. Отдать
 * её одной из таблиц означало бы соврать про место вывода, а уронить экран — лишить человека
 * способа эту правку увидеть и отменить.
 *
 * Форма строк повторяется дословно (вердикт Р14): шаблон экрана отдаёт эти строки в
 * `newsBlockRowActions()`, а тот объявлен параметром точной формы. Вывести `list<array<string,
 * mixed>>` означало бы спор с PHPStan на каждом экране.
 *
 * @param list<array{id: string, zone: string, kind: string, title: string, take: int, visible: bool, order: int,
 *     target: null|array{kind: string, id: string, label: string, alive: bool}}> $rows
 *
 * @return array<string, list<array{id: string, zone: string, kind: string, title: string, take: int, visible: bool, order: int,
 *     target: null|array{kind: string, id: string, label: string, alive: bool}>>>
 */
function groupBlockRows(array $rows): array
{
    /**
     * @var array<string, list<array{id: string, zone: string, kind: string, title: string, take: int, visible: bool, order: int,
     *     target: null|array{kind: string, id: string, label: string, alive: bool}}>> $byZone
     */
    $byZone = [];

    foreach (NEWS_BLOCK_ZONES as $zone) {
        $byZone[$zone] = [];
    }

    foreach ($rows as $row) {
        $zone = $row['zone'];

        if (!isset($byZone[$zone])) {
            continue;
        }

        $byZone[$zone][] = $row;
    }

    return $byZone;
}
```

- [ ] **Шаг 6: GREEN — разбор цели, строки экрана и опции в `lib/blocks.php`**

В `apps/news/lib/blocks.php` в конце файла (после `moveBlock()` задачи 7) вставить:

```php
// ── Цель и строки экрана ─────────────────────────────────

/**
 * Разбор составного значения цели из формы: `category:<id>` или `tag:<id>`; пустая строка — цели нет.
 *
 * Здесь только расщепление по первому двоеточию. Живая ли цель и бывает ли она у этого вида,
 * решает `blockTargetProblem()` (вердикт Р13): если бы неизвестный вид молча превращался в «цели
 * нет», подделанный POST `shop:9` развернул бы подборку на всю ленту без единого отказа — тихая
 * правка намерения человека.
 *
 * @return array{kind: string, id: string}
 */
function parseBlockTarget(string $raw): array
{
    $value = trim($raw);

    if ($value === '') {
        return ['kind' => '', 'id' => ''];
    }

    $at = strpos($value, ':');

    if ($at === false) {
        return ['kind' => '', 'id' => $value];
    }

    return ['kind' => substr($value, 0, $at), 'id' => substr($value, $at + 1)];
}

/**
 * Все строки таблицы блоков — то, что печатает экран «Блоки».
 *
 * В отличие от читателя задачи 4, экран не отбирает `visible = 1`: скрытый блок остаётся в
 * таблице и доступен кнопке «Показать» (правило 1). Названия целей приходят из выборок
 * справочников — тех же, что печатают экраны «Рубрики» и «Теги» (правило 2).
 *
 * @return list<array{
 *     id: string, zone: string, kind: string, title: string, take: int, visible: bool, order: int,
 *     target: null|array{kind: string, id: string, label: string, alive: bool}
 * }>
 */
function adminBlockRows(): array
{
    $rows = all(
        'SELECT id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index'
            . ' FROM ' . t('news_block')
            . ' ORDER BY order_index, title'
    );

    /** @var array<string, array<string, string>> $labels */
    $labels = ['category' => [], 'tag' => []];

    foreach (adminCategoryRows() as $categoryRow) {
        $labels['category'][$categoryRow['id']] = $categoryRow['title'];
    }

    foreach (adminTagRows() as $tagRow) {
        $labels['tag'][$tagRow['id']] = $tagRow['label'];
    }

    $out = [];

    foreach ($rows as $row) {
        $targetKind = $row['target_kind'] === null ? '' : (string) $row['target_kind'];
        $targetId = $row['target_id'] === null ? '' : (string) $row['target_id'];
        $target = null;

        if ($targetKind !== '' || $targetId !== '') {
            // Строка, у которой заполнена хоть одна колонка цели, показывается целью — живой или
            // мёртвой (правило 4): «вида нет, а идентификатор есть» и «идентификатора нет, а вид
            // есть» приходят только из порчи данных, и прятать их со строки нельзя.
            $known = isset($labels[$targetKind]);
            $target = [
                'kind' => $targetKind,
                'id' => $targetId,
                'label' => $known ? (string) ($labels[$targetKind][$targetId] ?? '') : '',
                'alive' => $known && isset($labels[$targetKind][$targetId]),
            ];
        }

        $out[] = [
            'id' => (string) $row['id'],
            'zone' => (string) $row['zone'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'take' => (int) $row['take_limit'],
            'visible' => (bool) $row['visible'],
            'order' => (int) $row['order_index'],
            'target' => $target,
        ];
    }

    return $out;
}

/**
 * Опции выбора цели — для формы добавления и для правки цели в строке.
 *
 * Значение одной опции составное (`category:42`), и это не экономия разметки: два независимых
 * поля позволяют отправить пару «вид — рубрика, идентификатор — метка» (вердикт Р13). «Без цели»
 * — отдельная опция с пустым значением, её печатает шаблон. Скрытые рубрики в списке остаются:
 * блок на такую уже настроен, и снять её с выбора означало бы потерять настройку при первой
 * же правке (правило 2).
 *
 * @return list<array{kind: string, label: string, items: list<array{value: string, title: string, visible: bool}>}>
 */
function blockTargetOptions(): array
{
    /** @var array<string, list<array{value: string, title: string, visible: bool}>> $byKind */
    $byKind = ['category' => [], 'tag' => []];

    foreach (adminCategoryRows() as $categoryRow) {
        $byKind['category'][] = [
            'value' => 'category:' . $categoryRow['id'],
            'title' => $categoryRow['title'],
            'visible' => $categoryRow['visible'],
        ];
    }

    foreach (adminTagRows() as $tagRow) {
        $byKind['tag'][] = [
            'value' => 'tag:' . $tagRow['id'],
            'title' => $tagRow['label'],
            'visible' => true,
        ];
    }

    $out = [];

    // Порядок групп и их заголовки читаются из того же словаря, что и проверка цели: добавленная
    // цель появляется в формах автоматически, и её невозможно забыть проверить.
    foreach (NEWS_BLOCK_TARGETS as $kind => $label) {
        $out[] = ['kind' => (string) $kind, 'label' => (string) $label, 'items' => $byKind[$kind]];
    }

    return $out;
}
```

`NEWS_ID_MAX` (`apps/news/lib/items.php:15`) здесь не нужен: идентификаторы цели приходят из
`parseBlockTarget()` и режутся в файле действий задачи 9, где этот предел уже стоит на месте.

- [ ] **Шаг 7: Прогнать зелёный результат**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/lib/blocks.php && php -l apps/news/lib/admin.php"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter "NewsBlockRulesTest|NewsAdminRulesTest|NewsConstantsTest"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite db --filter "NewsBlocksTest|NewsNoticeCoverageTest"`
Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "NewsVisibilityTest|AppSqlTest|SqlSafetyTest|NoticeKeyTest|TapTargetTest|StyleSourceTest"`
Ожидание: всё зелёное. Стражи исходников правок не требуют: в задаче 8 нет ни разметки, ни
маршрутов, ни `finishAppAction()` — `NEWS_BLOCK_OPS` начинает читаться файлом действий в задаче 9,
и договор действий проверяется там же. `NewsVisibilityTest` молчит: `adminBlockRows()` читает
`t('news_block')` и справочники, а её маркеры стоят на `tbl_news_item` и `feedScope(`.

- [ ] **Шаг 8: Обратные замеры**

Каждая мутация — правка `apps/news/lib/blocks.php` или `apps/news/lib/admin.php`, прогон набора,
красное падение и возврат. Ни одна не считается пройденной, если список упавших проверок пуст.

| Мутация | Что обязано покраснеть |
| --- | --- |
| в выборке `adminBlockRows()` добавить `WHERE visible = 1` | `testЭкранПоказываетИСкрытыеСтроки`, `testСтрокаНесётВсеПоляКоторыеЧитаетШаблон` |
| в `ORDER BY` оставить только `title` | `testСтрокиЭкранаИдутВПорядкеЗоныАНеПоАлфавиту` |
| в подписи цели печатать `$targetId` вместо названия из справочника | `testПодписьРубрикиСледуетЗаПереименованием`, `testМеткаПодписываетсяСвоейНадписью` |
| в `alive` не сверяться с `$labels` (всегда `true`) | `testУдалённаяЦельПомечаетсяНедоступной`, `testПолуразрушеннаяЦельНеИсчезаетСоСтроки` |
| условие `if ($targetKind !== '' || $targetId !== '')` → `&&` | `testПолуразрушеннаяЦельНеИсчезаетСоСтроки` (строка теряет цель вовсе) |
| `blockTargetOptions()` — отдавать только `visible === true` | `testСкрытаяРубрикаОстаётсяВОпцияхЦели` |
| `blockTargetOptions()` — выбросить метки (`$byKind['tag'] = []` перед сборкой) | `testОпцииЦелиСоставныеИГруппированыПоСловарю` (вторая группа ждёт `tag:<id>`) |
| в `parseBlockTarget()` резать по последнему двоеточию (`strrpos`) | `testДвоеточиеВИдентификатореОстаётсяВЦели` |
| в `parseBlockTarget()` относить значение без разделителя к виду, а не к идентификатору | `testЗначениеБезРазделителяНеПридумываетВид`, `testЧужойВидЦелиДоходитДоПроверкиИОтказывается` |
| в `parseBlockTarget()` не срезать пробелы | `testПустаяСтрокаНеДаётНиВидаНиИдентификатора` |
| в `newsBlockRowActions()` печатать `hide` независимо от состояния | `testКнопкиСтрокиБлокаЗависятОтСостоянияПоказа` |
| в `newsBlockRowActions()` добавить `rename` | `testПравкиСтрокиНеКнопкиАПоля` |
| в `groupBlockRows()` не сеять пустые зоны (`$byZone = []`) | `testСтрокиРаздаютсяПоЗонамВСпискеЗон` (ключа `category` нет) |
| в `groupBlockRows()` писать строку с чужой зоной под её собственным ключом (убрать `continue`) | `testСтрокиРаздаютсяПоЗонамВСпискеЗон` (ключей становится четыре) |
| в `NEWS_BLOCK_OPS` выбросить `retarget` | `testДействияБлоковОбещаныДевятьИУКаждогоЕстьПодпись` (в задаче 9 к этому же краснеет `AppActionContractTest`) |

- [ ] **Шаг 9: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

```bash
git add apps/news/lib/blocks.php apps/news/lib/admin.php tests/unit/NewsBlockRulesTest.php tests/unit/NewsAdminRulesTest.php tests/db/NewsBlocksTest.php
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Раздел «Новости»: правила экрана блоков — строки зон, составная цель и подписи действий"
```

Отчёт — в `.superpowers/sdd/part1/task-287-8-report.md`: дословный вывод шага 4 со всеми семью
`Call to undefined function` / `Undefined constant`, результат зелёного прогона шага 7, список
ловящихся мутаций шага 8 с номерами упавших проверок и дословный вывод ворота.

---

### Задача 9: экран «Блоки» — пять действий, три таблицы зон и стражи общей сборки

**Files:**
- Create: `apps/news/actions/blocks.php` (разбор намерения формы)
- Create: `apps/news/templates/admin/blocks.php` (экран: три таблицы по зонам)
- Create: `tests/http/NewsBlocksAdminTest.php` (экран настоящим запросом)
- Modify: `apps/news/lib/admin.php:16` (адрес экрана рядом с двумя адресами справочников), `:28` (пятый пункт вкладок), `:78` (предел значения цели)
- Modify: `apps/news/routes.php:11` (подключение файла действий), `:41` (два маршрута экрана)
- Modify: `apps/news/admin.php:163` (контроллер `newsAdminBlocks()`)
- Modify: `tests/unit/NewsAdminRulesTest.php:73-91` (пять вкладок, отметка нового экрана)
- Modify: `tests/unit/NewsCatalogRulesTest.php:14-24` (список адресов и подписей вкладок)
- Modify: `tests/guard/AppActionContractTest.php:15-18,20-32,34-63,84-88` (третий файл действий в договоре)
- Modify: `tests/http/NoIndexNoStoreSweepTest.php:41-59,185,455-460` (строка плана и три выверенных числа)
- Modify: `tests/http/NewsAssemblyTest.php:123` (пятый путь в списке админских экранов)
- Modify: `tests/guard/NewsVisibilityTest.php:122` (номер строки замыкания подписки — вердикт Р3)
- Modify: `scripts/responsive-check.mjs:138` (адрес экрана в списке `ADMIN_PAGES`)

**Interfaces:**
- Потребляет: `NEWS_BLOCK_OPS`, `NEWS_BLOCK_OP_LABEL`, `NEWS_BLOCK_ZONES`, `NEWS_BLOCK_KINDS`,
  `NEWS_BLOCK_TARGETS`, `NEWS_BLOCK_PER_ZONE`, `NEWS_BLOCK_ZONE_LABEL`, `NEWS_BLOCK_KIND_LABEL`,
  `NEWS_BLOCK_NOTICE_TEXT`, `BLOCK_FIELDS` (задачи 3 и 8); `adminBlockRows()`, `blockTargetOptions()`,
  `groupBlockRows()`, `newsBlockRowActions()`, `parseBlockTarget()` (задача 8); `createBlock()`,
  `renameBlock()`, `retargetBlock()`, `limitBlock()`, `setBlockVisibility()`, `deleteBlock()`,
  `moveBlock()` (задачи 6 и 7); `catalogFailure()`, `catalogUnknown()`, `NEWS_ID_MAX` (справочники);
  `csrfCheck()`, `postString()`, `finishAppAction()`, `noticeForAdminScreen()`, `takeAdminFlashReason()`,
  `adminFailureTitle()`, `newsAdminNav()`, `adminShell()`, `partial('admin/alert', …)`, `render()`,
  `requestPath()`, `fieldLimit()`, `plural()`, `e()`.
- Производит: `NEWS_ADMIN_BLOCKS_PATH` = `/news/admin/blocks`, `NEWS_BLOCK_TARGET_MAX` = 64,
  `newsBlocksAction(array $params = []): void`, `newsAdminBlocks(array $params = []): void`, шаблон
  `news/admin/blocks` с данными `rows` (три зоны), `targetOptions`, `titleMax`, `takeMin`, `takeMax`.
  Никакой следующий слой эти имена не потребляет: экран — последний слой #287.

**Правила задачи.**

1. **Пути маршрутов — строковые литералы, не константы** (вердикт Р1): `addRoute` с константой вместо
   литерала исчезает из-под регулярки `CsrfCoverageTest`, и POST-обработчик экрана выпал бы из проверки
   токена незаметно.
2. **`csrfCheck()` — первая операция** `newsBlocksAction()`, до чтения идентификатора и до `match`:
   по ответу «403 или нет» нельзя ничего узнать о базе, а правка до проверки токена — это исполненное
   намерение чужой формы.
3. **Действие без `op`-поля формы, а не без имени кнопки**: `core/templates/admin/apps.php:15-17`
   документирует, что имя кнопки не попадает в POST при отправке формы клавишей Enter. Каждая форма
   экрана — отдельная `<form>` со скрытыми `op` и `id`; намерение приходит полем.
4. **Порядок веток `match` = порядок `NEWS_BLOCK_OPS`** и у каждой ветки есть подпись; unknown
   намерение — `catalogFailure(catalogUnknown('блоком'))`, тот же общий текст без содержимого запроса,
   что у справочников.
5. **Ключ уведомления читается в переменную `$notice`** и только потом уходит `finishAppAction()`:
   литерал-аргумент `NoticeKeyTest` прочёл бы как ключ ядра.
6. **Форма добавления поле цели не печатает.** Экран работает без JS, а показывать выбор, который
   относится к одному из двух видов и который без скрипта не скрыть, — обещать настройку, которой нет.
   Цель ставится правкой готовой строки, и только у строки вида `list`.
7. **Мёртвая цель остаётся выбранной опцией** (`<option … selected>` вне списков справочников): иначе
   сохранение любой другой правки молча вернуло бы блоку «всю ленту» — тихая порча настройки, которую
   человек не заметил бы.
8. **Три таблицы — по одной на объявленную зону** (развилка, закрытая до начала плана: спецификация §5,
   а не карточки справочников): `order_index` плотный внутри зоны, и в общей таблице не видно,
   относительно чего человек двигает блок.
9. **Зона уезжает скрытым полем** своей формы добавления: человек стоит над той зоной, куда хочет блок.
10. **Только уже собранные классы** (`min-w-[40rem]`, `py-3`, `pl-4`, `pr-4`, `px-1.5`, `contents`,
    `grow`, `overflow-x-auto` и т. д. — они есть в `public/assets/app.css`), класс `pb-3` не использовать
    (в артефакте его нет). Новая разметка правила в `src-css/app.css` не требует, шаг 10 это проверяет.
11. **Каждая кнопка и поле экрана — зона нажатия 44 px** (`min-h-[44px]` перед конкатенацией класса),
    иначе краснеет `TapTargetTest`.
12. **Финал шаблона — один `adminShell()`** с меткой `/* @safe html */`, как у `categories.php:104`:
    весь вывод экрана идёт одной строкой, и `OutputEscapingTest` видит прикрытие.
13. **Независимость набора:** каждый тест правит блок, который создал сам, а `tearDown()` возвращает
    таблице состав релиза (две заводские строки). Иначе следующий метод застал бы чужие правки, и
    падение показывало бы не своё.
14. **Предел `NEWS_BLOCK_TARGET_MAX` — защитный, как `NEWS_ID_MAX`** (`actions/catalog.php:11-12`):
    значение цели режется до разбора. Различающей проверки у предела нет — и с обрезкой, и без неё отказ
    один («Выбранной цели уже нет — обновите страницу.»), поэтому в шаге 12 он честно помечен
    неловящейся мутацией, а не выдуманной проверкой.

- [ ] **Шаг 1: RED — пять вкладок в unit-наборах**

В `tests/unit/NewsAdminRulesTest.php` заменить метод целиком:

```php
    public function testПолосаВкладокЗнаетПятьЭкранов(): void
    {
        self::assertCount(5, NEWS_ADMIN_TABS);

        foreach (NEWS_ADMIN_TABS as $tab) {
            self::assertStringStartsWith('/news/admin', (string) $tab['href'], (string) $tab['label']);
            self::assertNotSame('', (string) $tab['label']);
        }
    }

    public function testПолосаВкладокОтмечаетЭкранБлоков(): void
    {
        $nav = newsAdminNav(NEWS_ADMIN_BLOCKS_PATH);

        self::assertSame(1, substr_count($nav, 'aria-current="page"'), 'Отмеченной бывает одна вкладка');
        self::assertStringContainsString('href="/news/admin/blocks" aria-current="page"', $nav);
        self::assertStringContainsString('>Блоки</a>', $nav);
    }
```

В `tests/unit/NewsCatalogRulesTest.php` заменить `testВкладокСталоЧетыреИОниВедутНаЭкраныРаздела`:

```php
    public function testВкладокСталоПятьИОниВедутНаЭкраныРаздела(): void
    {
        self::assertSame(
            ['/news/admin', '/news/admin/items', '/news/admin/categories', '/news/admin/tags', '/news/admin/blocks'],
            array_column(NEWS_ADMIN_TABS, 'href')
        );
        self::assertSame(
            ['Сводка', 'Материалы', 'Рубрики', 'Теги', 'Блоки'],
            array_column(NEWS_ADMIN_TABS, 'label')
        );
    }
```

- [ ] **Шаг 2: RED — экран настоящим запросом**

Создать `tests/http/NewsBlocksAdminTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Экран блоков проверяется запросами к живому серверу: ключ уведомления в адресе возврата, текст
// плашки после него, состояние строки в базе и разметка трёх зон существуют только в ответе.
//
// Каждый тест действует блоком, который создал сам, а tearDown() возвращает таблице состав релиза —
// две заводские строки. Иначе следующий метод застал бы чужие правки и падение показывало бы не своё.
final class NewsBlocksAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'bloki@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-3', 'email' => 'bloki@school.dev'];

    // Заводские строки третьего релиза: тот состав таблицы, к которому возвращается каждый тест.
    private const FACTORY_IDS = ['newsblk-home', 'newsblk-article'];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        // Материал нужен, чтобы рубрика цели была живой и непустой: без него проверка цели
        // шла бы по справочнику, которого на сайте нет.
        $created = createItem([
            'title' => 'Осенний бал',
            'slug' => 'osennij-bal',
            'lead' => 'Приглашаем на бал.',
            'body' => [['title' => '', 'text' => 'Танцы, песни и чай.']],
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'tags' => ['выпускник'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], self::EDITOR, nowUtc());

        self::assertTrue($created['ok'], (string) $created['error']);

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    protected function tearDown(): void
    {
        foreach (adminBlockRows() as $row) {
            $id = (string) $row['id'];

            if (!in_array($id, self::FACTORY_IDS, true)) {
                deleteBlock($id);
            }
        }

        parent::tearDown();
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаБлокахУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ADMIN_BLOCKS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fblocks', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапрет(): void
    {
        $response = Kernel::post(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Проверка', 'take' => '5']);

        self::assertSame(403, $response['status']);
    }

    public function testДействиеБезТокенаОтклоняетсяИНичегоНеМеняет(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'rename', 'id' => 'newsblk-home', 'title' => 'Без токена']);

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('Проверка формы не прошла', $response['body']);
        self::assertStringNotContainsString('Без токена', $this->body(NEWS_ADMIN_BLOCKS_PATH), 'Чужая форма не переименовала блок');
    }

    // ── Разметка экрана ───────────────────────────────────

    public function testЭкранБлоковЧитаетсяИНеИндексируется(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);

        self::assertStringContainsString('<title>Блоки — админка</title>', $body);
        self::assertStringContainsString('noindex, nofollow', $body);
        self::assertStringContainsString('href="/news/admin/blocks" aria-current="page"', $body);
        self::assertStringContainsString('Свежие материалы', $body);
        self::assertStringContainsString('Похожие материалы', $body);
        self::assertStringContainsString('вся лента раздела', $body, 'Блок без цели читается подписью, а не пустой клеткой');
        self::assertStringContainsString('рубрика самого материала', $body, 'У похожих материалов своей цели нет');
    }

    public function testЗоныИдутВПорядкеСловаряАФормаЦелиУстрокиСписка(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);

        $home = strpos($body, 'На главной');
        $category = strpos($body, 'В рубрике');
        $article = strpos($body, 'В материале');
        $retarget = strpos($body, 'value="retarget"');
        self::assertNotFalse($home);
        self::assertNotFalse($category);
        self::assertNotFalse($article);
        self::assertNotFalse($retarget);
        self::assertLessThan($category, $home, 'Первой печатается зона словаря — главная');
        self::assertLessThan($article, $category);

        // Порядок зон задаёт место правки: поле цели принадлежит строке «Свежие материалы» зоны
        // главной, и если условие вида в шаблоне перевёрнуто, форма цели уедет под заголовок
        // «В материале».
        self::assertLessThan($article, $retarget, 'Форма цели печатается у блока-списка, а не у похожих материалов');

        // Три таблицы — три формы добавления со своей зоной; поле цели на стартовом экране одно,
        // потому что вторая заводская строка — вид «похожие».
        self::assertSame(3, substr_count($body, 'name="zone"'), 'Зона уезжает скрытым полем каждой формы добавления');
        self::assertSame(1, substr_count($body, 'name="target"'), 'Цель правится только у строки вида «список»');
    }

    public function testФормаДобавленияНеПечатаетПолеЦели(): void
    {
        $this->login();
        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);

        $create = strpos($body, 'value="create"');
        self::assertNotFalse($create, 'Форма добавления на экране есть');
        $end = strpos($body, '</form>', $create);
        self::assertNotFalse($end, 'Форма добавления закрыта');

        self::assertStringNotContainsString('name="target"', (string) substr($body, $create, $end - $create), 'Блок создаётся без цели и правится в строке');
    }

    // ── Девять действий ───────────────────────────────────

    public function testСозданиеБлокаСтавитЕгоВКонецСвоейЗоны(): void
    {
        $this->login();
        $response = $this->create('category', 'list', 'К списку рубрик', '4');

        self::assertSame(302, $response['status']);
        self::assertSame('created', $this->noticeOf($response));

        $screen = Kernel::get((string) $response['headers']['location']);
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString(NEWS_BLOCK_NOTICE_TEXT['created'], $screen['body']);

        $row = $this->rowByTitle('К списку рубрик');
        self::assertNotNull($row);
        self::assertSame('category', (string) $row['zone']);
        self::assertSame(1, (int) $row['order'], 'Плотность порядка считается в зоне, а не по всей таблице');
        self::assertNull($row['target'], 'Созданный формой блок цели не имеет');
        self::assertTrue($row['visible']);
    }

    public function testПереименованиеЧитаетсяВБазе(): void
    {
        $this->login();
        $this->create('category', 'list', 'Первое имя', '3');
        $id = $this->idByTitle('Первое имя');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'rename', 'id' => $id, 'title' => 'Второе имя']);

        self::assertSame('renamed', $this->noticeOf($response));
        $row = $this->rowById($id);
        self::assertNotNull($row);
        self::assertSame('Второе имя', (string) $row['title']);
        self::assertStringContainsString('Второе имя', $this->body(NEWS_ADMIN_BLOCKS_PATH));
    }

    public function testСменаЦелиНаРубрикуИЧислоМатериалов(): void
    {
        $this->login();
        $this->create('category', 'list', 'Цель и число', '3');
        $id = $this->idByTitle('Цель и число');
        self::assertNotSame('', $id);
        $categoryId = categoryIdOf('zhizn-shkoly');

        $retargeted = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'category:' . $categoryId]);
        self::assertSame('retargeted', $this->noticeOf($retargeted));
        self::assertStringContainsString(NEWS_BLOCK_NOTICE_TEXT['retargeted'], Kernel::get((string) $retargeted['headers']['location'])['body']);

        $row = $this->rowById($id);
        self::assertNotNull($row);
        $target = $row['target'];
        self::assertNotNull($target);
        self::assertSame('category', (string) $target['kind']);
        self::assertSame('Жизнь школы', (string) $target['label']);
        self::assertTrue($target['alive']);
        self::assertStringContainsString('<option value="category:' . $categoryId . '" selected>', $this->body(NEWS_ADMIN_BLOCKS_PATH));

        $limited = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'limit', 'id' => $id, 'take' => '7']);
        self::assertSame('limited', $this->noticeOf($limited));
        $after = $this->rowById($id);
        self::assertNotNull($after);
        self::assertSame(7, (int) $after['take']);
    }

    public function testПоказПереключаетсяАСкрытыйБлокОстаётсяНаЭкране(): void
    {
        $this->login();
        $this->create('category', 'list', 'Виден и скрыт', '3');
        $id = $this->idByTitle('Виден и скрыт');
        self::assertNotSame('', $id);

        $hidden = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'hide', 'id' => $id]);
        self::assertSame('hidden', $this->noticeOf($hidden));
        $row = $this->rowById($id);
        self::assertNotNull($row);
        self::assertFalse($row['visible']);

        // Кнопку «Показать» ищет привязка к идентификатору именно этой строки: в разметке экрана
        // таких кнопок несколько, и общая проверка ловила бы чужую.
        self::assertStringContainsString(
            '<input type="hidden" name="op" value="show"><input type="hidden" name="id" value="' . $id . '">',
            $this->body(NEWS_ADMIN_BLOCKS_PATH),
            'Скрытой строке экран возвращает кнопку показа, а не прячет её'
        );

        $shown = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'show', 'id' => $id]);
        self::assertSame('shown', $this->noticeOf($shown));
        $back = $this->rowById($id);
        self::assertNotNull($back);
        self::assertTrue($back['visible'], 'Скрытый блок доступен снова — экран его не теряет');
    }

    public function testСтрелкиДвигаютВСвоейЗонеИКраяОтмечают(): void
    {
        $this->login();
        $this->create('category', 'list', 'Стрелки первый', '3');
        $this->create('category', 'list', 'Стрелки второй', '3');
        $first = $this->idByTitle('Стрелки первый');
        $second = $this->idByTitle('Стрелки второй');
        self::assertNotSame('', $first);
        self::assertNotSame('', $second);
        $homeOrder = $this->orderOf('newsblk-home');

        $moved = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'down', 'id' => $first]);
        self::assertSame('moved', $this->noticeOf($moved));
        $movedRow = $this->rowById($first);
        self::assertNotNull($movedRow);
        self::assertSame(2, (int) $movedRow['order'], 'Второй блок зоны отодвинулся на место первого');

        $back = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'up', 'id' => $first]);
        self::assertSame('moved', $this->noticeOf($back));

        self::assertSame('topped', $this->noticeOf($this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'up', 'id' => $first])));
        self::assertSame('bottomed', $this->noticeOf($this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'down', 'id' => $second])));

        // Движение в зоне «рубрика» не трогает порядок зоны «главная» — тот же обход с WHERE zone = ?.
        self::assertSame($homeOrder, $this->orderOf('newsblk-home'));
    }

    public function testУдалениеСнимаетСтрокуСЭкрана(): void
    {
        $this->login();
        $this->create('category', 'list', 'На удаление', '3');
        $id = $this->idByTitle('На удаление');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'delete', 'id' => $id]);

        self::assertSame('deleted', $this->noticeOf($response));
        self::assertNull($this->rowById($id));
        self::assertStringNotContainsString('На удаление', $this->body(NEWS_ADMIN_BLOCKS_PATH));
    }

    // ── Границы форм ──────────────────────────────────────

    public function testСедьмойБлокВЗонеОтказывается(): void
    {
        $this->login();
        // Зона главной держит одну заводскую строку: ещё пять заполняют предел, шестая — отказ.
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE; $n++) {
            $this->create('home', 'list', 'Заполнение ' . $n, '3');
        }

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Шестой лишних', 'take' => '3']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('В зоне уже 6 блоков', $this->failureBody($response));
    }

    public function testПодделанныеПоляОтказываютсяСвоимТекстом(): void
    {
        // Каждая пара «поле => значение» берётся из договора задачи 3..6 и печатается на экране
        // отказом: форма этих значений не рождает, поэтому POST идёт напрямую.
        /** @var list<array{why: string, fields: array<string, string>, text: string}> $cases */
        $cases = [
            [
                'why' => 'чужая зона',
                'fields' => ['op' => 'create', 'zone' => 'shop', 'kind' => 'list', 'title' => 'Зона из чужого раздела', 'take' => '3'],
                'text' => 'Зоны с таким именем нет.',
            ],
            [
                'why' => 'чужой вид',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'video', 'title' => 'Вид, которого нет', 'take' => '3'],
                'text' => 'Такого вида блока нет.',
            ],
            [
                'why' => 'заголовок короче предела',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'А', 'take' => '3'],
                'text' => 'Название блока должно быть от 2 до 120 символов.',
            ],
            [
                'why' => 'число больше предела',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Девятьсот девяносто', 'take' => '999'],
                'text' => 'Число материалов должно быть от 1 до 12.',
            ],
            [
                'why' => 'ноль вместо числа',
                'fields' => ['op' => 'create', 'zone' => 'home', 'kind' => 'list', 'title' => 'Ноль материалов', 'take' => '0'],
                'text' => 'Число материалов должно быть от 1 до 12.',
            ],
        ];

        $this->login();

        foreach ($cases as $case) {
            $response = Kernel::post(NEWS_ADMIN_BLOCKS_PATH, array_merge(['_token' => $this->token($this->body(NEWS_ADMIN_BLOCKS_PATH))], $case['fields']));

            self::assertSame(302, $response['status'], $case['why']);
            self::assertStringContainsString($case['text'], $this->failureBody($response), $case['why']);
        }
    }

    public function testПохожиеМатериалыЦелиНеИмеют(): void
    {
        $this->login();
        $this->create('category', 'related', 'Похожие для отказа', '3');
        $id = $this->idByTitle('Похожие для отказа');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'category:' . categoryIdOf('zhizn-shkoly')]);

        self::assertStringContainsString('У блока «похожие материалы» цели нет', $this->failureBody($response));
    }

    public function testЧужойВидЦелиДоходитДоПроверкиИОтказывается(): void
    {
        $this->login();
        $this->create('category', 'list', 'Цель из чужого раздела', '3');
        $id = $this->idByTitle('Цель из чужого раздела');
        self::assertNotSame('', $id);

        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'shop:9']);

        self::assertStringContainsString('Целью блока может быть только рубрика или метка.', $this->failureBody($response));
    }

    public function testНеизвестноеДействиеОтказываетсяТекстом(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'взломать', 'id' => 'newsblk-home']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('Неизвестное действие', $this->failureBody($response));
    }

    public function testБлокаУжеНетИЭтоОтказ(): void
    {
        $this->login();
        $response = $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'rename', 'id' => 'net-takogo-bloka', 'title' => 'Проверка']);

        self::assertStringContainsString('Блока уже нет — обновите страницу.', $this->failureBody($response));
    }

    // ── Помощники ─────────────────────────────────────────

    private function login(): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    /** @param array<string, string> $fields */
    private function send(string $path, array $fields): array
    {
        $page = Kernel::get($path);

        return Kernel::post($path, array_merge(['_token' => $this->token($page['body'])], $fields));
    }

    /** @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>} */
    private function create(string $zone, string $kind, string $title, string $take): array
    {
        return $this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'create', 'zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take]);
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     */
    private function noticeOf(array $response): string
    {
        $query = $this->queryOf($response);

        return (string) ($query['notice'] ?? '');
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     */
    private function failureBody(array $response): string
    {
        $location = (string) $response['headers']['location'];
        self::assertStringContainsString('notice=migration-failed', $location);
        self::assertStringNotContainsString(
            'error=',
            $location,
            'Причина отказа не должна уезжать в адрес: он остаётся в истории браузера и в access-логе'
        );

        return (string) Kernel::get($location)['body'];
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     */
    private function locationOf(array $response): string
    {
        $location = (string) ($response['headers']['location'] ?? '');

        return substr($location, strpos($location, '?') === false ? strlen($location) : (int) strpos($location, '?') + 1);
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>, redirects: list<string>} $response
     *
     * @return array<string, string>
     */
    private function queryOf(array $response): array
    {
        parse_str($this->locationOf($response), $query);

        return $query;
    }

    private function body(string $path): string
    {
        $response = Kernel::get($path);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    private function idByTitle(string $title): string
    {
        foreach (adminBlockRows() as $row) {
            if ((string) $row['title'] === $title) {
                return (string) $row['id'];
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function rowById(string $id): ?array
    {
        foreach (adminBlockRows() as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function rowByTitle(string $title): ?array
    {
        foreach (adminBlockRows() as $row) {
            if ((string) $row['title'] === $title) {
                return $row;
            }
        }

        return null;
    }

    private function orderOf(string $id): int
    {
        $row = $this->rowById($id);
        self::assertNotNull($row);

        return (int) $row['order'];
    }
}
```

Метод `testМёртваяЦельОстаётсяВСтрокеИВыбраннойОпцией` в этом листинг не входит: он пишется целиком
шагом 2а, потому что строит ситуацию тремя вызовами (создать рубрику, привязать к ней блок, удалить
рубрику) и читать его частями полагалось бы через весь файл. Идентификатор созданной рубрики тест берёт
из выборки `adminCategoryRows()` — `createCategory()` его не возвращает (§4 плана задачи 8).

- [ ] **Шаг 2а: тело проверки мёртвой цели**

```php
    public function testМёртваяЦельОстаётсяВСтрокеИВыбраннойОпцией(): void
    {
        $this->login();
        self::assertTrue(createCategory('Проба', '')['ok']);

        $categoryId = '';
        foreach (adminCategoryRows() as $row) {
            if ((string) $row['title'] === 'Проба') {
                $categoryId = (string) $row['id'];
            }
        }

        self::assertNotSame('', $categoryId, 'Рубрика для проверки создана');

        $this->create('category', 'list', 'Блок на мёртвую цель', '3');
        $id = $this->idByTitle('Блок на мёртвую цель');
        self::assertNotSame('', $id);
        self::assertSame('retargeted', $this->noticeOf($this->send(NEWS_ADMIN_BLOCKS_PATH, ['op' => 'retarget', 'id' => $id, 'target' => 'category:' . $categoryId])));

        self::assertTrue(deleteCategory($categoryId)['ok'], 'Пустая рубрика удаляется — цель блока становится мёртвой');

        $row = $this->rowById($id);
        self::assertNotNull($row, 'Блок с удалённой целью остаётся в таблице');
        $target = $row['target'];
        self::assertNotNull($target);
        self::assertFalse($target['alive'], 'Цель помечена недоступной');

        $body = $this->body(NEWS_ADMIN_BLOCKS_PATH);
        self::assertStringContainsString('цель недоступна', $body);
        self::assertStringContainsString('<option value="category:' . $categoryId . '" selected>', $body, 'Мёртвая цель остаётся выбранной — иначе правка вернула бы «всю ленту»');
    }
```

- [ ] **Шаг 3: Прогон RED**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter "NewsAdminRulesTest|NewsCatalogRulesTest"`

Ожидание: падения `Undefined constant NEWS_ADMIN_BLOCKS_PATH` (оба файла вкладок) и
`Failed asserting that actual size 4 matches expected size 5`.

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter NewsBlocksAdminTest`

Ожидание: `Undefined constant NEWS_ADMIN_BLOCKS_PATH` на первом же методе; остальные — за ним.

- [ ] **Шаг 4: GREEN — файл действий `apps/news/actions/blocks.php`**

Создать файл целиком:

```php
<?php

declare(strict_types=1);

// Действия экрана блоков вывода. CSRF проверяется первым: до проверки токена нельзя ни менять
// базу, ни узнавать по ответу, существует ли блок с таким идентификатором.
//
// Идентификатор и значение цели режутся пределами ДО разбора: чужая строка в четыреста символов
// не обязана уходить в `WHERE id = ?` и в составной ключ цели целиком. Для цели это страховка, а
// не правило (правило 14 и шаг 12): отказ при значении длиннее предела и без обрезки один —
// «Выбранной цели уже нет — обновите страницу.».
//
// Ключ уведомления читается в переменную $notice и только потом уходит finishAppAction():
// литерал-аргумент страж NoticeKeyTest прочёл бы как ключ ядра, а тексты этого экрана лежат в
// словаре раздела NEWS_BLOCK_NOTICE_TEXT.

/** @param array<string, string> $params */
function newsBlocksAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);
    $target = parseBlockTarget(mb_substr(trim(postString('target')), 0, NEWS_BLOCK_TARGET_MAX));

    // Порядок веток повторяет NEWS_BLOCK_OPS: страж договора действий сверяет их построчно, и
    // переставленная ветка краснит именем файла, а не падением экрана.
    $result = match ($op) {
        'create' => createBlock(postString('zone'), postString('kind'), postString('title'), (int) postString('take')),
        'rename' => renameBlock($id, postString('title')),
        'retarget' => retargetBlock($id, $target['kind'], $target['id']),
        'limit' => limitBlock($id, (int) postString('take')),
        'show' => setBlockVisibility($id, true),
        'hide' => setBlockVisibility($id, false),
        'up' => moveBlock($id, 'up'),
        'down' => moveBlock($id, 'down'),
        'delete' => deleteBlock($id),
        default => catalogFailure(catalogUnknown('блоком')),
    };

    $notice = (string) ($result['notice'] ?? '');

    finishAppAction($result, NEWS_ADMIN_BLOCKS_PATH, $notice);
}
```

`$target` вычисляется до `match`, а не внутри ветки `retarget`: правые части веток `match` —
вызовы, и пара `'kind' => …` внутри ветки обманула бы разбор стража, который ищет ключи веток
регуляркой `/'([a-z]+)'\s*=>/`.

- [ ] **Шаг 5: GREEN — шаблон экрана `apps/news/templates/admin/blocks.php`**

Создать файл целиком. Классы берутся только из уже собранного артефакта (правило 10), каждый
видимый контрол несёт `min-h-[44px]` в литерале атрибута (правило 11), вывод — одна строка с
пометкой (правило 12):

```php
<?php

declare(strict_types=1);

// Экран блоков вывода: по одной таблице на объявленную зону и своя форма добавления у каждой
// таблицы. Порядок внутри зоны задают стрелки, цель и число правятся полями самой строки.
//
// Формы не общие: каждая кнопка и каждое поле — отдельный POST со скрытыми `op` и `id`. Имя
// кнопки в POST не попадает при отправке формы клавишей Enter, поэтому намерение приходит полем —
// тот же договор, что у списка материалов и справочников.

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var array<string, list<array{id: string, zone: string, kind: string, title: string, take: int,
 *     visible: bool, order: int, target: null|array{kind: string, id: string, label: string, alive: bool}}>> $rows
 * @var list<array{kind: string, label: string, items: list<array{value: string, title: string, visible: bool}>}> $targetOptions
 * @var int $titleMax
 * @var int $takeMin
 * @var int $takeMax
 */

// Классы контролов выписаны литералами, которые уже лежат в артефакте стилей: экран не
// добавляет правил в src-css/app.css, и шаг 10 это сверяет.
$field = 'w-full rounded-lg border border-ink/20 px-3 py-2 text-sm';
$button = 'rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream hover:opacity-90';
$danger = 'rounded-lg border border-bad/30 px-3 py-1.5 text-sm text-bad hover:bg-bad/10';

/**
 * Значение цели строки в том же виде, в каком его отдаёт поле выбора: `category:<id>` или `tag:<id>`.
 *
 * @param null|array{kind: string, id: string, label: string, alive: bool} $target
 */
$targetValue = static function (?array $target): string {
    return $target === null ? '' : $target['kind'] . ':' . $target['id'];
};

/**
 * Поле выбора цели одной строки: «всю ленту», группы справочников и, если цель умерла, её сама.
 *
 * Мёртвая цель печатается отдельной выбранной опцией (правило 7): в списках справочников её уже
 * нет, и без этой строки сохранение любой другой правки молча вернуло бы блоку общую ленту —
 * тихая порча настройки, которую человек не заметил бы.
 *
 * @param null|array{kind: string, id: string, label: string, alive: bool} $target
 */
$targetField = static function (?array $target) use ($targetOptions, $targetValue, $field): string {
    $current = $targetValue($target);
    $out = '<select class="min-h-[44px] ' . $field . '" name="target">'
        . '<option value="">вся лента раздела</option>';

    foreach ($targetOptions as $group) {
        $out .= '<optgroup label="' . e($group['label']) . '">';

        foreach ($group['items'] as $item) {
            $out .= '<option value="' . e($item['value']) . '"'
                . ($item['value'] === $current ? ' selected' : '') . '>'
                . e($item['title'] . ($item['visible'] === true ? '' : ' (скрыта)'))
                . '</option>';
        }

        $out .= '</optgroup>';
    }

    if ($target !== null && $target['alive'] === false) {
        $dead = $targetValue($target);
        $out .= '<option value="' . e($dead) . '" selected>' . e($dead . ' — цель недоступна') . '</option>';
    }

    return $out . '</select>';
};

$tables = '';

foreach (NEWS_BLOCK_ZONES as $zone) {
    // Зона словаря, а не базы: пустая зона обязана остаться на экране со своей формой добавления,
    // иначе человек, который ничего ещё не настроил, не нашёл бы, где начинать.
    $rowsOfZone = $rows[$zone];

    // Форма добавления поле цели не печатает (правило 6): экран работает без JS, а выбор,
    // относящийся к одному из двух видов, без скрипта не скрыть.
    $create = '<form class="flex flex-wrap items-end gap-2 rounded-lg border border-ink/10 bg-white p-4" method="post" action="'
        . e(NEWS_ADMIN_BLOCKS_PATH) . '">' . csrfField()
        . '<input type="hidden" name="op" value="create">'
        . '<input type="hidden" name="zone" value="' . e($zone) . '">'
        . '<label class="grow text-sm">Название блока'
        . '<input class="min-h-[44px] ' . $field . '" type="text" name="title" required maxlength="'
        . e((string) $titleMax) . '"></label>'
        . '<label class="text-sm">Что показывать'
        . '<select class="min-h-[44px] ' . $field . '" name="kind">'
        . '<option value="list">' . e(NEWS_BLOCK_KIND_LABEL['list']) . '</option>'
        . '<option value="related">' . e(NEWS_BLOCK_KIND_LABEL['related']) . '</option>'
        . '</select></label>'
        . '<label class="text-sm">Сколько материалов'
        . '<input class="min-h-[44px] ' . $field . '" type="number" name="take" min="' . e((string) $takeMin)
        . '" max="' . e((string) $takeMax) . '" value="' . e((string) $takeMin) . '" required></label>'
        . '<button class="min-h-[44px] ' . $primary . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['create']) . '</button>'
        . '</form>';

    $body = '';

    foreach ($rowsOfZone as $row) {
        $id = (string) $row['id'];
        $kind = (string) $row['kind'];
        $target = $row['target'];

        // Цель бывает только у вида «список» (правило спецификации §2): у «похожих материалов»
        // рубрика берётся из материала, на котором они лежат, и второе поле обещало бы настройку,
        // которую сервер всё равно отверг бы.
        if ($kind === 'related') {
            $targetCell = '<p class="text-sm text-ink/70">рубрика самого материала</p>';
        } else {
            $cellText = $target === null
                ? 'вся лента раздела'
                : ($target['alive'] === true
                    ? (string) $target['label']
                    : 'цель недоступна: ' . $target['kind'] . ':' . $target['id']);

            $targetCell = '<p class="text-sm text-ink/70">' . e($cellText) . '</p>'
                . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
                . csrfField() . '<input type="hidden" name="op" value="retarget">'
                . '<input type="hidden" name="id" value="' . e($id) . '">'
                . '<label class="grow text-sm">Цель' . $targetField($target) . '</label>'
                . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['retarget']) . '</button>'
                . '</form>';
        }

        $limitCell = '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
            . csrfField() . '<input type="hidden" name="op" value="limit">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<label class="text-sm">Материалов'
            . '<input class="min-h-[44px] ' . $field . '" type="number" name="take" min="' . e((string) $takeMin)
            . '" max="' . e((string) $takeMax) . '" value="' . e((string) $row['take']) . '" required></label>'
            . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['limit']) . '</button>'
            . '</form>';

        $renameCell = '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
            . csrfField() . '<input type="hidden" name="op" value="rename">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<label class="grow text-sm">Заголовок'
            . '<input class="min-h-[44px] ' . $field . '" type="text" name="title" value="' . e((string) $row['title'])
            . '" required maxlength="' . e((string) $titleMax) . '"></label>'
            . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_BLOCK_OP_LABEL['rename']) . '</button>'
            . '</form>';

        $state = $row['visible'] === true
            ? '<span class="text-sm text-good">виден</span>'
            : '<span class="text-sm text-bad">скрыт — на страницах его нет</span>';

        $buttons = '';

        foreach (newsBlockRowActions($row) as $action) {
            $class = $action['op'] === 'delete' ? $danger : $button;
            $buttons .= '<form class="contents" method="post" action="' . e(NEWS_ADMIN_BLOCKS_PATH) . '">'
                . csrfField() . '<input type="hidden" name="op" value="' . e($action['op']) . '">'
                . '<input type="hidden" name="id" value="' . e($id) . '">'
                . '<button class="min-h-[44px] ' . $class . '" type="submit">' . e($action['label']) . '</button>'
                . '</form>';
        }

        $body .= '<tr class="border-b border-ink/10">'
            . '<th class="py-3 pr-3 pl-4 text-left font-medium">' . e((string) $row['title'])
            . '<p class="text-sm text-ink/50">' . e(NEWS_BLOCK_KIND_LABEL[$kind] ?? $kind)
            . ' · позиция ' . e((string) $row['order']) . '</p></th>'
            . '<td class="py-3 pr-3">' . $targetCell . '</td>'
            . '<td class="py-3 pr-3">' . $limitCell . '</td>'
            . '<td class="py-3 pr-3">' . $state . '</td>'
            . '<td class="py-3 pr-4 text-right">' . $renameCell
            . '<div class="flex flex-wrap justify-end gap-2">' . $buttons . '</div></td>'
            . '</tr>';
    }

    $emptyRow = $rowsOfZone === []
        ? '<tr class="border-b border-ink/10"><td class="py-3 pr-3 pl-4 text-sm text-ink/60" colspan="5">'
            . 'В этой зоне пока ни одного блока — добавьте его формой выше.</td></tr>'
        : '';

    $tables .= '<section class="space-y-3">'
        . '<h2 class="font-semibold">' . e(NEWS_BLOCK_ZONE_LABEL[$zone])
        . ' · не больше ' . e((string) NEWS_BLOCK_PER_ZONE) . '</h2>'
        . $create
        . '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[40rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-3 pl-4 font-medium">Блок</th><th class="py-2 pr-3 font-medium">Что берёт</th>'
        . '<th class="py-2 pr-3 font-medium">Сколько</th><th class="py-2 pr-3 font-medium">Состояние</th>'
        . '<th class="py-2 pr-4 text-right font-medium">Правка и кнопки</th></tr></thead><tbody>'
        . $body . $emptyRow . '</tbody></table></div></section>';
}

// Вступление не называет зоны их заголовочными словами: проверка порядка зон ищет «На главной»,
// «В рубрике» и «В материале» первым вхождением, и любое упоминание выше заголовка переставило бы
// их местами в глазах теста.
$content = $nav
    . '<p class="text-sm text-ink/70">Блок — это подборка материалов, которую раздел выводит в отмеченном месте '
    . 'страницы. Порядок задают кнопки «Вверх» и «Вниз» внутри своей зоны, а скрытый блок остаётся в '
    . 'таблице и возвращается кнопкой «Показать».</p>'
    . $tables;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Блоки', $content, $notice);
```

Три места разметки держат проверки шага 2 и изменять их нельзя без правки теста: порядок `op` и
`id` в одной строке без переноса (`testПоказПереключается…` ищет их слитно), `selected` сразу за
`value` (проверка живой цели) и ровно одно `name="target"` на стартовом экране (форма цели
печатается только у вида `list`).

- [ ] **Шаг 6: GREEN — адрес экрана, пятая вкладка, предел цели и контроллер**

В `apps/news/lib/admin.php` после строки `const NEWS_ADMIN_TAGS_PATH = '/news/admin/tags';` вставить:

```php

// Адрес экрана блоков вывода. Как и два адреса справочников, его читают вкладки, маршруты, файл
// действий и HTTP-тесты — один раз здесь, а не пятью строками в четырёх файлах.
const NEWS_ADMIN_BLOCKS_PATH = '/news/admin/blocks';
```

В `NEWS_ADMIN_TABS` после строки с «Теги» вставить пятый пункт:

```php
    ['href' => NEWS_ADMIN_BLOCKS_PATH, 'label' => 'Блоки'],
```

После `const NEWS_UNKNOWN_ACTION_MAX = 80;` вставить:

```php

// Потолок значения цели: составной ключ `category:<id>` приходит из поля формы и режется до
// разбора — тот же защитный смысл, что у NEWS_ID_MAX в действиях справочников. Различающей
// проверки у этого предела нет (правило 14 и шаг 12): он стоит как страховка, а не как правило.
const NEWS_BLOCK_TARGET_MAX = 64;
```

В `apps/news/admin.php` после `newsAdminTags()` вставить контроллер — тот же порядок полей, что у
двух справочников, и та же пара «причина отказа из сессии + заголовок из словаря ядра»:

```php

/** @param array<string, string> $params */
function newsAdminBlocks(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('news/admin/blocks', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(NEWS_BLOCK_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'rows' => groupBlockRows(adminBlockRows()),
        'targetOptions' => blockTargetOptions(),
        'titleMax' => fieldLimit(BLOCK_FIELDS, 'title', 'max'),
        'takeMin' => fieldLimit(BLOCK_FIELDS, 'take', 'min'),
        'takeMax' => fieldLimit(BLOCK_FIELDS, 'take', 'max'),
    ], ['title' => 'Блоки — админка', 'noindex' => true]);
}
```

- [ ] **Шаг 7: GREEN — подключение и маршруты экрана**

В `apps/news/routes.php` после `require_once __DIR__ . '/actions/catalog.php';` вставить:

```php
require_once __DIR__ . '/actions/blocks.php';
```

После строки `addRoute('POST', '/news/admin/tags', …);` вставить пять строк (пустая, две
строки комментария, два маршрута):

```php

// Экран блоков вывода: GET печатает три таблицы зон, POST выполняет девять действий. Пути —
// строковые литералы по той же причине, что у справочников: регулярка CsrfCoverageTest читает
// путь маршрута, и константа в этом месте спрятала бы обработчик от проверки токена.
addRoute('GET', '/news/admin/blocks', 'newsAdminBlocks', ['app' => 'news', 'private' => true, 'permission' => 'login']);
addRoute('POST', '/news/admin/blocks', 'newsBlocksAction', ['app' => 'news', 'private' => true, 'permission' => 'login']);
```

Обе вставки поднимают подписку карты сайтов на шесть строк ниже, и `NewsVisibilityTest` назовёт
новый номер строки замыкания падением — шаг 8 правит его по этому выводу.

- [ ] **Шаг 8: стражи общей сборки и адрес экрана в проверке адаптивности**

В `tests/guard/AppActionContractTest.php` константу `ACTIONS` заменить целиком:

```php
    /** @var array<string, array{ops: list<string>, default: string}> файл действий → чем он сверяется */
    private const ACTIONS = [
        'apps/news/actions/items.php' => ['ops' => ['NEWS_ROW_OP_LABEL'], 'default' => 'itemFailure'],
        'apps/news/actions/catalog.php' => ['ops' => ['NEWS_CATEGORY_OP_LABEL', 'NEWS_TAG_OP_LABEL'], 'default' => 'catalogFailure'],
        'apps/news/actions/blocks.php' => ['ops' => ['NEWS_BLOCK_OP_LABEL'], 'default' => 'catalogFailure'],
    ];
```

В `testКаждоеОбещанноеДействиеРазобрано` после проверки `catalog` добавить третий файл:

```php
        self::assertSame(
            array_keys(NEWS_BLOCK_OP_LABEL),
            $this->handledOps('apps/news/actions/blocks.php'),
            'blocks.php: кнопки экрана и match расходятся'
        );
```

В `testКаждоеРазобранноеДействиеИмеетПодпись` — два утверждения для блоков, рядом с двумя
 существующими парами:

```php
        $blocks = $this->handledOps('apps/news/actions/blocks.php');

        self::assertSame(
            [],
            array_values(array_diff($blocks, array_keys(NEWS_BLOCK_OP_LABEL))),
            'blocks.php: разобранное действие обязано иметь подпись'
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys(NEWS_BLOCK_OP_LABEL), $blocks)),
            'blocks.php: подпись без ветки match — мёртвая кнопка'
        );
```

В `testСпискиДействийСовпадаютСоСвоимиПодписями` добавить третью строку:

```php
        self::assertSame(NEWS_BLOCK_OPS, array_keys(NEWS_BLOCK_OP_LABEL));
```

В `tests/http/NoIndexNoStoreSweepTest.php` комментарий и три числа над ним replace'ом:
`21 строка таблицы GET (ядро 6, раздел 10, фикстуры 5) даёт 28 вариантов, ещё 4 адреса вне
таблицы — всего 32 запроса, из них помеченных 24 (ядро 2, раздел 14, фикстуры 5, добавки 3)` →
`22 строка таблицы GET (ядро 6, раздел 11, фикстуры 5) даёт 29 вариантов, ещё 4 адреса вне
таблицы — всего 33 запроса, из них помеченных 25 (ядро 2, раздел 15, фикстуры 5, добавки 3)`;
`VARIANTS_TOTAL = 32` → `33`; `MARKED_TOTAL = 24` → `25`; комментарий `32 − 1` → `33 − 1` и
`MARKED_ON_NOT_INSTALLED = 31` → `32`. В таблице GET после группы `'GET /news/admin/tags' => […]`
вставить:

```php
            'GET /news/admin/blocks' => [
                ['path' => '/news/admin/blocks', 'outcome' => self::MARKED, 'status' => 200],
            ],
```

В `tests/http/NewsAssemblyTest.php` список в `foreach` цикла `testАдминкаЗакрытаИндексироватьНечего`
дописать пятым адресом: `'/news/admin/blocks'`.

В `tests/guard/NewsVisibilityTest.php` номер строки замыкания заменить по выводу шага 9:
`self::assertSame(['{closure:48}'], $closures, 'Хук карты сайтов — единственное замыкание файла, его строка — 48');`
→ ожидаемое `'{closure:54}'` и текст «его строка — 54» (шесть строк шага 7 поднимают подписку с 48
на 54; если прогон назовёт другой номер, в план и в тест идёт номер из вывода, а не из этого
абзаца).

В `scripts/responsive-check.mjs` в массив `ADMIN_PAGES` после строки с `'/news/admin/tags'`
вставить:

```js
  { name: 'новости: блоки', href: '/news/admin/blocks' },
```

- [ ] **Шаг 9: Прогнать зелёный результат**

Run: `docker compose -f docker-compose.dev.yml run --rm -T php bash -c "php -l apps/news/actions/blocks.php && php -l apps/news/templates/admin/blocks.php && php -l apps/news/lib/admin.php && php -l apps/news/admin.php && php -l apps/news/routes.php"`

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite unit --filter "NewsAdminRulesTest|NewsCatalogRulesTest"`

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite http --filter "NewsBlocksAdminTest|NewsAssemblyTest|NoIndexNoStoreSweepTest"`

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "AppActionContractTest|NewsVisibilityTest|CsrfCoverageTest|NoticeKeyTest|TapTargetTest|OutputEscapingTest|StyleSourceTest"`

Ожидание: всё зелёное, кроме `NewsVisibilityTest`, который на первом прогоне краснеет с настоящим
номером строки замыкания. Этот номер — единственное, что план не мог замерить до правки дерева
(вердикт Р3): после подстановки в тест прогон повторяется, и красным в нём не остаётся ничего.

- [ ] **Шаг 10: Стили — экран не добавляет правил**

Run: `bash scripts/css && git status --porcelain public/assets/app.css`

Ожидание: вывод пуст. Непустой вывод означает, что разметка взяла класс, которого артефакт не
знал, — это нарушение правила 10, и чинится он заменой класса в шаблоне на уже собранный, а не
молчаливым коммитом пересобранного файла. Дальше проверка на месте:

Run: `docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit --testsuite guard --filter "StyleSourceTest|OutputEscapingTest|TapTargetTest"`

Ожидание: всё зелёное.

- [ ] **Шаг 11: Адаптивность экрана блоков**

Run: `node --check scripts/responsive-check.mjs`

Run: `docker compose -f docker-compose.dev.yml up -d`

Run: `npm run responsive -- --base=http://localhost:8080`

Ожидание: строки `OK` на шести экранах для всех адресов, итог `проблем: 0`; отчёт —
`report.json` во временном каталоге `cms-resp-check`. Узкий экран держит таблица внутри
`overflow-x-auto`, поэтому переполнение документа краснит не разметку таблицы, а ширину самой
оболочки: если FAIL покажет строку таблицы, правка — в шаблоне, а не в пороге скрипта.

Run: `docker compose -f docker-compose.dev.yml stop`

- [ ] **Шаг 12: Обратные замеры**

Каждая мутация вносится в рабочее дерево, прогоняется указанным набором и отменяется (`git
checkout -- <файл>`). Мутация, после которой всё остаётся зелёным, — находка: проверка этого
пункта ничего не ловит, и в отчёте она обязана быть записана как неперкрытая, а не молча пропущена.

| # | Мутация | Кто обязан покраснеть |
|---|---------|----------------------|
| М1 | убрать `csrfCheck()` из `newsBlocksAction()` | `CsrfCoverageTest` (страж) и `testДействиеБезТокенаОтклоняетсяИНичегоНеМеняет` |
| М2 | заменить путь маршрута POST на `NEWS_ADMIN_BLOCKS_PATH` | `CsrfCoverageTest` — обработчик исчезает из-под регулярки (правило 1) |
| М3 | убрать `min-h-[44px]` у поля цели в `$targetField` | `TapTargetTest` |
| М4 | напечатать `<select … name="target">` в форме добавления | `testФормаДобавленияНеПечатаетПолеЦели` и `testЗоныИдутВПорядкеСловаряАФормаЦелиУстрокиСписка` (count `name="target"` = 2) |
| М5 | удалить ветку `if ($target !== null && $target['alive'] === false)` | `testМёртваяЦельОстаётсяВСтрокеИВыбраннойОпцией` — «цель недоступна» и выбранная опция |
| М6 | поменять местами ветки `show` и `hide` в `match` | `AppActionContractTest::testКаждоеОбещанноеДействиеРазобрано` (порядок `assertSame`) |
| М7 | вырезать пятый пункт из `NEWS_ADMIN_TABS` | `testПолосаВкладокЗнаетПятьЭкранов`, `testВкладокСталоПятьИОниВедутНаЭкраныРаздела` |
| М8 | заменить `default => catalogFailure(catalogUnknown('блоком'))` на `default => ['ok' => true, 'notice' => '']` | `AppActionContractTest::testНеизвестноеДействиеОтказывается` (текст отказа) и `testНеизвестноеДействиеОтказываетсяТекстом` |
| М9 | убрать строку экрана из таблицы `NoIndexNoStoreSweepTest`, не меняя чисел | `testСвязкаNoindexNoStoreНаУстановленномСайте` — `checked` 32 вместо 33 |
| М10 | поднять `VARIANTS_TOTAL` до 33, не добавив адрес в план | тот же тест — обход запросил 32, а ждёт 33 |
| М11 | в `groupBlockRows()` убрать инициализацию пустых зон | `testЗоныИдутВПорядкеСловаряАФормаЦелиУстрокиСписка` — `name="zone"` 2 вместо 3 |
| М12 | в `newsBlockRowActions()` всегда возвращать `hide` | `testПоказПереключаетсяАСкрытыйБлокОстаётсяНаЭкране` — скрытая строка теряет кнопку «Показать» |

Неловящаяся мутация, записанная честно: убрать `mb_substr(…, 0, NEWS_BLOCK_TARGET_MAX)` из
`newsBlocksAction()` — весь набор остаётся зелёным, потому что и с обрезкой, и без неё слишком
длинное значение цели отвергается одним и тем же отказом «Выбранной цели уже нет — обновите
страницу.». Предел `NEWS_BLOCK_TARGET_MAX` (правило 14) — страховка против ухода произвольной
строки в `WHERE`, а не правило с отдельным исходом, и различающей проверки у него нет.

- [ ] **Шаг 13: Ворота, коммит и отчёт**

Run: `bash scripts/gate.sh`

Ожидание: `ВСЕ ВОРОТА ЗЕЛЁНЫЕ`. Падает PHPStan или фиксер — чинится код или текст этого шага,
правок `ignore` и подавлений не будет (глобальные ограничения).

```bash
git add apps/news/actions/blocks.php apps/news/templates/admin/blocks.php apps/news/lib/admin.php \
  apps/news/admin.php apps/news/routes.php tests/http/NewsBlocksAdminTest.php \
  tests/unit/NewsAdminRulesTest.php tests/unit/NewsCatalogRulesTest.php \
  tests/guard/AppActionContractTest.php tests/guard/NewsVisibilityTest.php \
  tests/http/NoIndexNoStoreSweepTest.php tests/http/NewsAssemblyTest.php scripts/responsive-check.mjs
GIT_AUTHOR_NAME=Алексей GIT_AUTHOR_EMAIL=zanzibaradevil@gmail.com GIT_COMMITTER_NAME=Алексей GIT_COMMITTER_EMAIL=zanzibaradevil@gmail.com \
  git commit -m "Экран «Блоки»: девять действий и три таблицы зон"
```

В отчёт: список созданных и изменённых файлов, выводы прогонов шагов 9–11 дословно, таблица
двенадцати замеров с результатом каждого, отдельной строкой — неловящаяся мутация предела цели, и
номер строки замыкания, который показал прогон (если он оказался не 54).


