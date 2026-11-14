-- RELEASE 2.2 «Editorial Pro»: ревизии, совместная работа, согласование, комментарии,
-- уведомления и отложенная публикация. Файл только дополняет схему: таблицы страниц, секций,
-- новостей и товаров не меняются, поэтому существующие данные и идентификаторы остаются на месте.
-- Все моменты — DATETIME в UTC. Тип и идентификатор контента хранятся строками: новая сущность
-- не требует менять схему. IF NOT EXISTS — повторное применение файла проходит молча.

-- Полный снимок материала на момент сохранения. payload — канонический JSON, checksum — SHA-256
-- от него. Версия уникальна внутри пары (тип, объект), idempotency_key защищает автосохранение и
-- повтор запроса от второй одинаковой ревизии. parent_id — предыдущая ревизия цепочки.
CREATE TABLE IF NOT EXISTS tbl_content_revisions (
  id VARCHAR(26) NOT NULL,
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  version INT NOT NULL,
  parent_id VARCHAR(26) NULL,
  author_id VARCHAR(26) NULL,
  author_email VARCHAR(190) NOT NULL DEFAULT '',
  operation VARCHAR(24) NOT NULL DEFAULT 'manual',
  source VARCHAR(32) NOT NULL DEFAULT 'editor',
  comment VARCHAR(500) NOT NULL DEFAULT '',
  checksum CHAR(64) NOT NULL,
  idempotency_key VARCHAR(80) NULL,
  payload LONGTEXT NOT NULL,
  payload_bytes INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY content_revision_version (content_type, content_id, version),
  UNIQUE KEY content_revision_idempotency (content_type, content_id, idempotency_key),
  KEY content_revision_list (content_type, content_id, created_at),
  KEY content_revision_operation (content_type, content_id, operation),
  KEY content_revision_author (author_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Голова материала: номер актуальной версии и последняя ревизия. Это маркер актуальности для
-- оптимистической блокировки; строка блокируется SELECT ... FOR UPDATE внутри транзакции записи.
CREATE TABLE IF NOT EXISTS tbl_content_heads (
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  version INT NOT NULL DEFAULT 0,
  revision_id VARCHAR(26) NULL,
  updated_by VARCHAR(26) NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (content_type, content_id),
  KEY content_head_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Временная блокировка материала. Одна строка на материал: эксклюзивный режим первого релиза.
-- Срок действия продлевает heartbeat; истёкшая блокировка считается свободной и может быть
-- перехвачена. token — секрет вкладки: продлить и снять блокировку может только её владелец
-- (или администратор принудительно).
CREATE TABLE IF NOT EXISTS tbl_editor_locks (
  id VARCHAR(26) NOT NULL,
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(26) NOT NULL,
  session_id VARCHAR(128) NOT NULL,
  token CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  heartbeat_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY editor_lock_content (content_type, content_id),
  KEY editor_lock_expires (expires_at),
  KEY editor_lock_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кто сейчас открыл материал. presence обновляется heartbeat-ом и не даёт прав: только показывает
-- активных редакторов. Строка на сессию, поэтому две вкладки одного человека видны как одна запись
-- при чтении по пользователю.
CREATE TABLE IF NOT EXISTS tbl_editor_presence (
  id VARCHAR(26) NOT NULL,
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(26) NOT NULL,
  session_id VARCHAR(128) NOT NULL,
  entered_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY editor_presence_session (content_type, content_id, session_id),
  KEY editor_presence_seen (content_type, content_id, last_seen_at),
  KEY editor_presence_user (user_id, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Редакционное состояние материала. Отдельно от публичного статуса страницы/новости: публичный
-- статус отвечает «видно ли посетителю», редакционный — «на какой стадии согласования материал».
CREATE TABLE IF NOT EXISTS tbl_editorial_states (
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  previous_status VARCHAR(24) NOT NULL DEFAULT '',
  submitted_by VARCHAR(26) NULL,
  submitted_at DATETIME NULL,
  approved_by VARCHAR(26) NULL,
  approved_at DATETIME NULL,
  published_at DATETIME NULL,
  scheduled_for DATETIME NULL,
  updated_by VARCHAR(26) NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (content_type, content_id),
  KEY editorial_state_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Внутренние комментарии. section_id пуст для комментария ко всему материалу. parent_id — ответ.
-- mentions — JSON-массив идентификаторов упомянутых сотрудников. resolved_at закрывает ветку.
CREATE TABLE IF NOT EXISTS tbl_editorial_comments (
  id VARCHAR(26) NOT NULL,
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  section_id VARCHAR(26) NOT NULL DEFAULT '',
  parent_id VARCHAR(26) NULL,
  author_id VARCHAR(26) NULL,
  author_email VARCHAR(190) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  mentions JSON NULL,
  resolved_by VARCHAR(26) NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY editorial_comment_content (content_type, content_id, created_at),
  KEY editorial_comment_parent (parent_id),
  KEY editorial_comment_section (content_type, content_id, section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Задачи редактору: кто назначил, кому, срок, состояние и комментарий.
CREATE TABLE IF NOT EXISTS tbl_editorial_tasks (
  id VARCHAR(26) NOT NULL,
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  comment VARCHAR(1000) NOT NULL DEFAULT '',
  assignee_id VARCHAR(26) NULL,
  assignee_email VARCHAR(190) NOT NULL DEFAULT '',
  due_at DATETIME NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  created_by VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY editorial_task_content (content_type, content_id, status, due_at),
  KEY editorial_task_assignee (assignee_id, status, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Центр уведомлений. url — внутренний адрес перехода; внешние адреса не принимаются.
CREATE TABLE IF NOT EXISTS tbl_editorial_notifications (
  id VARCHAR(26) NOT NULL,
  user_id VARCHAR(26) NOT NULL,
  kind VARCHAR(40) NOT NULL,
  content_type VARCHAR(32) NOT NULL DEFAULT '',
  content_id VARCHAR(64) NOT NULL DEFAULT '',
  title VARCHAR(190) NOT NULL DEFAULT '',
  body VARCHAR(500) NOT NULL DEFAULT '',
  url VARCHAR(300) NOT NULL DEFAULT '',
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY editorial_notification_user (user_id, read_at, created_at),
  KEY editorial_notification_kind (user_id, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отложенные публикации. action — publish или unpublish. Планировщик забирает строки со статусом
-- pending, у которых наступил срок (или next_attempt_at после ошибки). attempts и last_error
-- образуют журнал повторных попыток; payload хранит контекст запуска.
CREATE TABLE IF NOT EXISTS tbl_scheduled_publications (
  id VARCHAR(26) NOT NULL,
  content_type VARCHAR(32) NOT NULL,
  content_id VARCHAR(64) NOT NULL,
  action VARCHAR(16) NOT NULL DEFAULT 'publish',
  publish_at DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow',
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  payload JSON NULL,
  created_by VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY scheduled_publication_due (status, publish_at),
  KEY scheduled_publication_content (content_type, content_id, status),
  KEY scheduled_publication_retry (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ограничение частоты редакционных API: окно по ключу (пользователь + операция + адрес).
-- Строки старше окна удаляются пачками при проверке.
CREATE TABLE IF NOT EXISTS tbl_editorial_rates (
  id BIGINT NOT NULL AUTO_INCREMENT,
  bucket VARCHAR(120) NOT NULL,
  user_id VARCHAR(26) NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY editorial_rate_bucket (bucket, created_at),
  KEY editorial_rate_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
