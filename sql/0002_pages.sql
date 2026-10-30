-- Слой страниц: главная «/» и адресные страницы собираются секциями. Дополнение к базовой схеме
-- ядра: все моменты — DATETIME в UTC, перевод в московское время делает core/date.php; перечисления
-- (status, kind, layout) — VARCHAR с перечнем в PHP (core/pages.php), CHECK в MySQL 5.7 молча
-- игнорируется; IF NOT EXISTS — MySQL фиксирует DDL сразу, повторное применение файла обязано
-- проходить молча (отклонение 9).

-- Публичная страница: slug задаёт адрес, status решает, показывать ли её посетителю, noindex —
-- просить ли поисковик её не индексировать. Статус — строка из перечня PAGES_STATUSES: DRAFT виден
-- только редактору (предпросмотр прямым адресом), PUBLISHED — всем.
CREATE TABLE IF NOT EXISTS tbl_page (
  id VARCHAR(26) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(200) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
  noindex TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY page_slug (slug),
  KEY page_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Секция страницы — строка: kind выбирает вёрстку, поля держат тексты, items/buttons — повторяющиеся
-- блоки. Порядок задаёт sort, скрытая секция остаётся в базе. page_id = '' — секция главной «/»,
-- иначе секция принадлежит странице: уникальный ключ (page_id, sec_key) с NULL пропускал бы
-- дубликаты ключей главной (MySQL считает NULL всегда разным), поэтому пустая строка вместо NULL.
-- Колонка sec_key, а не key: KEY — reserved-слово MySQL, и каждый запрос с ним просил бы backticks.
-- JSON NOT NULL (всегда '[]'/'{}'): шаблон не ветвится на NULL, а пустая секция честнее «нет значения».
CREATE TABLE IF NOT EXISTS tbl_page_section (
  id VARCHAR(26) NOT NULL,
  page_id VARCHAR(26) NOT NULL,
  sec_key VARCHAR(40) NOT NULL,
  kind VARCHAR(40) NOT NULL,
  layout VARCHAR(20) NOT NULL DEFAULT '',
  anchor VARCHAR(40) NOT NULL DEFAULT '',
  badge VARCHAR(80) NOT NULL DEFAULT '',
  title VARCHAR(160) NOT NULL DEFAULT '',
  -- lead_text, а не lead: LEAD — зарезервированное слово MySQL 8.0, образец — релиз новостей.
  lead_text TEXT NOT NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  image VARCHAR(200) NOT NULL DEFAULT '',
  image_alt VARCHAR(160) NOT NULL DEFAULT '',
  buttons JSON NOT NULL,
  items JSON NOT NULL,
  settings JSON NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  visible TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY page_section_key (page_id, sec_key),
  KEY page_section_order (page_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Прежние адреса страниц: строкой, а не JSON-массивом из PostgreSQL — история адресов показывается
-- списком, и уникальный ключ slug отвечает за «адрес один». Образец — tbl_news_old_slug.
CREATE TABLE IF NOT EXISTS tbl_page_old_slug (
  slug VARCHAR(80) NOT NULL,
  page_id VARCHAR(26) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (slug),
  KEY page_old_slug_page (page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
