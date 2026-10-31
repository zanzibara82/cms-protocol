-- Меню шапки и подвала: навигация сайта живёт в базе и правится на /admin/menu. Дополнение к
-- базовой схеме ядра по образцу MenuItem портала; моментов — DATETIME в UTC (updated_at без
-- created_at: у пункта меню нет даты публикации, порядок живёт в sort); IF NOT EXISTS — MySQL
-- фиксирует DDL сразу, повторное применение файла обязано проходить молча (отклонение 9).

-- Пункт меню: плоский список без дерева. slot выбирает карту (HEADER или FOOTER — перечень
-- MENU_SLOTS в core/menu.php), section — подпись колонки подвала: у шапки всегда пусто.
-- Скрытый пункт остаётся в базе: visible решает, показывать ли его посетителю, blank —
-- открывать ли адрес в новом окне. Название колонки sort, а не порталова order: ORDER —
-- reserved-слово MySQL, и без переименования каждый запрос просил бы backticks (тот же мотив,
-- что sec_key в слое страниц).
CREATE TABLE IF NOT EXISTS tbl_menu_item (
  id VARCHAR(26) NOT NULL,
  slot VARCHAR(16) NOT NULL,
  section VARCHAR(60) NOT NULL DEFAULT '',
  label VARCHAR(60) NOT NULL,
  href VARCHAR(300) NOT NULL,
  blank TINYINT(1) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  visible TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY menu_item_order (slot, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
