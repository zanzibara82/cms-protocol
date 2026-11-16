-- RELEASE 2.3 «Конструктор контента»: пользовательские типы записей, поля, связи, глобальные
-- блоки и переадресация прежних адресов. Файл только дополняет схему: tbl_page, tbl_page_section,
-- tbl_news_item, товары и прочие таблицы прошлых релизов не меняются, идентификаторы остаются на
-- месте. Новая сущность в истории ревизий не требует изменения tbl_content_revisions: тип и
-- идентификатор там уже строковые.
--
-- Все моменты — DATETIME в UTC. CHECK в MySQL 5.7 молча игнорируется, поэтому перечни статусов,
-- видов полей и вёрсток живут в PHP (core/content-types.php), а колонки остаются VARCHAR.
-- IF NOT EXISTS — повторное применение файла проходит молча.

-- Тип записи: «Статьи», «Сотрудники», «Курсы» — то, что редакция заводит сама. slug — первый
-- сегмент публичного адреса, name — подпись в админке. archived_at — мягкий архив: тип исчезает
-- из списков и с сайта, но данные и история остаются. Удаления строк в RELEASE 2.3 нет.
CREATE TABLE IF NOT EXISTS tbl_content_type (
  id VARCHAR(26) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(190) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY content_type_slug (slug),
  KEY content_type_sort (sort, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Поле типа. field_key неизменяем после создания: по нему лежат значения в tbl_content_entry.data.
-- kind — вид контрола (text, textarea, richtext, number, checkbox, select, image, url, date,
-- relation). options — JSON: варианты выбора или разрешённые типы для связи. archived_at —
-- мягкий архив: убранное из конструктора поле продолжает объяснять старые данные и может быть
-- возвращено тем же ключом без потери значений.
CREATE TABLE IF NOT EXISTS tbl_content_field (
  id VARCHAR(26) NOT NULL,
  type_id VARCHAR(26) NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  label VARCHAR(190) NOT NULL,
  kind VARCHAR(32) NOT NULL,
  hint VARCHAR(255) NOT NULL DEFAULT '',
  required TINYINT NOT NULL DEFAULT 0,
  options TEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY content_field_key (type_id, field_key),
  KEY content_field_sort (type_id, sort, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Запись пользовательского типа. slug уникален внутри типа: публичный адрес — /{type}/{slug}.
-- data — канонический JSON со значениями полей; связи лежат отдельной таблицей, потому что по
-- ним ищут и от них зависят права/публикация второй стороны. published_at заполняется при первой
-- публикации и не теряется при снятии с показа.
CREATE TABLE IF NOT EXISTS tbl_content_entry (
  id VARCHAR(26) NOT NULL,
  type_id VARCHAR(26) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  data LONGTEXT NOT NULL,
  seo_title VARCHAR(190) NOT NULL DEFAULT '',
  seo_description VARCHAR(300) NOT NULL DEFAULT '',
  created_by VARCHAR(26) NULL,
  updated_by VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  published_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY content_entry_slug (type_id, slug),
  KEY content_entry_status (type_id, status, published_at),
  KEY content_entry_updated (type_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связь записи с записью. field_key — ключ поля вида relation у исходного типа. Одна пара
-- (запись, поле, запись) встречается один раз; порядок задаёт sort. При сохранении записи связи
-- заменяются одной транзакцией вместе с полями — частичной связи не бывает.
CREATE TABLE IF NOT EXISTS tbl_content_entry_relation (
  id VARCHAR(26) NOT NULL,
  from_entry_id VARCHAR(26) NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  to_entry_id VARCHAR(26) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY content_relation_unique (from_entry_id, field_key, to_entry_id),
  KEY content_relation_from (from_entry_id, field_key, sort),
  KEY content_relation_to (to_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Прежние адреса типов: смена slug не ломает внешние ссылки, старый адрес отвечает 301.
-- Таблица не уникальна по slug: адрес мог перейти другому типу позже, победитель — свежая строка.
CREATE TABLE IF NOT EXISTS tbl_content_type_old_slug (
  id VARCHAR(26) NOT NULL,
  type_id VARCHAR(26) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY content_type_old_lookup (slug, created_at),
  KEY content_type_old_type (type_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Прежние адреса записей внутри типа: /{type}/{old} отвечает 301 на живой адрес. Как и у типов,
-- таблица хранит историю, а не уникальность: адрес мог быть занят другой записью позже.
CREATE TABLE IF NOT EXISTS tbl_content_entry_old_slug (
  id VARCHAR(26) NOT NULL,
  type_id VARCHAR(26) NOT NULL,
  entry_id VARCHAR(26) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY content_entry_old_lookup (type_id, slug, created_at),
  KEY content_entry_old_entry (entry_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Глобальный блок: переиспользуемый фрагмент страницы, который конструктор вставляет видом
-- «global_block» по slug. Набор полей фиксирован ядром, поэтому отдельного конструктора полей
-- у блоков нет. active = 0 — архив: блок не предлагается и не печатается, данные остаются.
CREATE TABLE IF NOT EXISTS tbl_global_block (
  id VARCHAR(26) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL,
  subtitle VARCHAR(255) NOT NULL DEFAULT '',
  text LONGTEXT NULL,
  image VARCHAR(255) NOT NULL DEFAULT '',
  image_alt VARCHAR(300) NOT NULL DEFAULT '',
  link VARCHAR(255) NOT NULL DEFAULT '',
  link_label VARCHAR(120) NOT NULL DEFAULT '',
  layout VARCHAR(32) NOT NULL DEFAULT 'text',
  active TINYINT NOT NULL DEFAULT 1,
  created_by VARCHAR(26) NULL,
  updated_by VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY global_block_slug (slug),
  KEY global_block_active (active, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
