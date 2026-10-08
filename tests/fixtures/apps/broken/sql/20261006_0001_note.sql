-- Первый файл фикстуры «broken»: полностью валиден, применяется до сбойного файла 20261006_0002_broken.sql
-- и попадает в журнал миграций. Таблица отделена от демо-фикстуры, чтобы наборы не пересекались.
CREATE TABLE IF NOT EXISTS tbl_broken_note (
  id VARCHAR(26) NOT NULL,
  title VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
