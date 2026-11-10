-- Заявки с публичных форм. Связи с таблицей страниц нет сознательно: страницу могут удалить
-- или переименовать, а обращение посетителя обязано остаться. Источник хранится строкой с адресом
-- страницы и ключом блока. Контакт открыт текстом: администратору нужно отвечать на заявку, а ключ
-- шифрования всё равно лежал бы рядом с базой (комментарий модели Lead портала дословно).
-- tbl_lead_attempt заменяет счётчик лимитов портала, который жил в памяти процесса Node: у PHP
-- процесса между запросами нет, поэтому попытки считаются по таблице. scope = 'ip:<адрес>' для
-- приёма и 'reply:<id заявки>' для ответов. Старые строки не чистятся — как tbl_login_attempt.

CREATE TABLE IF NOT EXISTS tbl_lead (
  id              VARCHAR(26)  NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  contact         VARCHAR(120) NOT NULL,
  message         TEXT         NULL,
  source          VARCHAR(120) NOT NULL DEFAULT '',
  status          VARCHAR(16)  NOT NULL DEFAULT 'NEW',
  done_at         DATETIME     NULL,
  confirm_sent_at DATETIME     NULL,
  reply_text      TEXT         NULL,
  reply_sent_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY lead_status_created (status, created_at),
  KEY lead_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lead_attempt (
  id         BIGINT       NOT NULL AUTO_INCREMENT,
  scope      VARCHAR(120) NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY lead_attempt_scope (scope, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
