-- Битая фикстура: первое предложение валидно (проверяет частичное применение сбойного файла),
-- второе обращается к таблице, которой в сборке нет, и обязанно падать на выполнении.
CREATE TABLE IF NOT EXISTS tbl_broken_part (
  id VARCHAR(26) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO tbl_broken_ghost (id) VALUES ('g-000000000000000000000001');
