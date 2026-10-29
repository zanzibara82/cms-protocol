-- Единственный файл фикстуры «brokenseed»: полностью валиден и применяется до сида, поэтому
-- срыв случается ровно на seedApp() — могли бы догадаться, что ветка сбоя закладки настоящая.
-- Таблица отделена от демо-фикстуры, чтобы наборы не пересекались.
CREATE TABLE IF NOT EXISTS tbl_brokenseed_note (
  id VARCHAR(26) NOT NULL,
  title VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
