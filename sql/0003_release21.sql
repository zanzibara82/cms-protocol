-- RELEASE 2.1: общие сервисы редакции. Файл только дополняет схему: существующие таблицы
-- страниц, секций и приложений не меняются, поэтому прежние данные и идентификаторы остаются
-- на месте. Все моменты — DATETIME в UTC; перечисления хранятся строками, их состав живёт в PHP.
-- IF NOT EXISTS — повторное применение файла обязано проходить молча (отклонение 9).

-- Журнал действий: кто, что и когда сделал. user_id не внешний ключ: запись журнала переживает
-- удаление пользователя, а имя почты сохраняется для читаемости. details — узкий JSON с контекстом.
CREATE TABLE IF NOT EXISTS tbl_audit_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id VARCHAR(26) NULL,
  user_email VARCHAR(190) NOT NULL DEFAULT '',
  action VARCHAR(64) NOT NULL,
  entity VARCHAR(32) NOT NULL DEFAULT '',
  entity_id VARCHAR(64) NOT NULL DEFAULT '',
  summary VARCHAR(255) NOT NULL DEFAULT '',
  details JSON NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY audit_log_created (created_at),
  KEY audit_log_entity (entity, entity_id),
  KEY audit_log_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Медиатека: одна строка на принятый файл. path — публичный относительный адрес, он же ключ
-- повторного использования. ALT хранится отдельно от секции: один файл переиспользуется многими
-- блоками, и подпись можно поправить в одном месте.
CREATE TABLE IF NOT EXISTS tbl_media (
  id VARCHAR(26) NOT NULL,
  path VARCHAR(300) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  alt VARCHAR(300) NOT NULL DEFAULT '',
  mime VARCHAR(80) NOT NULL DEFAULT '',
  width INT NOT NULL DEFAULT 0,
  height INT NOT NULL DEFAULT 0,
  bytes INT NOT NULL DEFAULT 0,
  uploaded_by VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY media_path (path),
  KEY media_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Корзина: удалённая сущность лежит целиком в payload, пока человек не восстановит или не
-- очистит её. live-таблицы при удалении освобождаются, поэтому публичные адреса не отвечают.
CREATE TABLE IF NOT EXISTS tbl_trash (
  id VARCHAR(26) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  entity_id VARCHAR(64) NOT NULL,
  parent_id VARCHAR(64) NOT NULL DEFAULT '',
  title VARCHAR(190) NOT NULL DEFAULT '',
  payload JSON NOT NULL,
  deleted_by VARCHAR(26) NULL,
  deleted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY trash_kind (kind, deleted_at),
  KEY trash_entity (kind, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Буфер обмена секций: один слот на пользователя. В базе, а не в сессии, потому что буфер
-- переживает перезапуск и проверяется тестом без поднятия сеанса.
CREATE TABLE IF NOT EXISTS tbl_clipboard (
  user_id VARCHAR(26) NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'section',
  label VARCHAR(190) NOT NULL DEFAULT '',
  payload JSON NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ссылки предпросмотра: в базе только SHA-256-хеш токена. Сырой токен показывается человеку
-- один раз при создании; утечка таблицы не даёт войти на черновик.
CREATE TABLE IF NOT EXISTS tbl_preview_token (
  id VARCHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  page_id VARCHAR(26) NOT NULL,
  created_by VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY preview_token_hash (token_hash),
  KEY preview_token_page (page_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сброс пароля: тоже только хеш токена. used_at закрывает ссылку после смены пароля.
CREATE TABLE IF NOT EXISTS tbl_password_reset (
  id VARCHAR(26) NOT NULL,
  user_id VARCHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY password_reset_hash (token_hash),
  KEY password_reset_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Попытки запроса сброса: ограничение по адресу и почте, чтобы форма не стала генератором писем.
CREATE TABLE IF NOT EXISTS tbl_password_reset_attempt (
  id BIGINT NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY password_reset_attempt_ip (ip, attempted_at),
  KEY password_reset_attempt_email (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал согласий: факт согласия на обработку данных с версией текста и источником формы.
-- Строка не удаляется вместе с заявкой: согласие — доказательство, а не свойство заявки.
CREATE TABLE IF NOT EXISTS tbl_consent_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  subject VARCHAR(190) NOT NULL DEFAULT '',
  kind VARCHAR(64) NOT NULL DEFAULT 'lead',
  text_version VARCHAR(64) NOT NULL DEFAULT '',
  source VARCHAR(190) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY consent_log_created (created_at),
  KEY consent_log_subject (subject, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
