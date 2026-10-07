-- Базовая схема ядра. Все моменты — DATETIME в UTC; перевод в московское время делает core/date.php.
-- Перечисления — VARCHAR: CHECK в MySQL 5.7 молча игнорируется, поэтому список ролей живёт в PHP.
-- IF NOT EXISTS: MySQL фиксирует DDL сразу, поэтому повторное применение файла обязано проходить молча
-- (отклонение 9). Страж AppSqlTest проверяет правило по файлам приложений, здесь оно то же.

CREATE TABLE IF NOT EXISTS tbl_user (
  id VARCHAR(26) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'editor',
  session_version INT NOT NULL DEFAULT 0,
  blocked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_login_attempt (
  id BIGINT NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY attempt_email (email, attempted_at),
  KEY attempt_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_option (
  option_name VARCHAR(64) NOT NULL,
  option_value TEXT NULL,
  autoload TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (option_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_app_installation (
  id VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  installed_at DATETIME NOT NULL,
  version VARCHAR(16) NOT NULL DEFAULT '0.0.0',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_app_migration (
  id VARCHAR(26) NOT NULL,
  app VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY migration_app_name (app, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
