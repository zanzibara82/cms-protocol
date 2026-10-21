-- C6: вебхуки доски. Приёмник подписан на одно событие (публикация, отклонение, снятие
-- с публикации, новое сообщение продавцу) и получает POST с JSON-телом и подписью
-- HMAC-SHA256. Ссылка на объявление или пользователя намеренно без внешнего ключа: событие
-- живёт своей историей и переживает удаление объявления.
CREATE TABLE IF NOT EXISTS tbl_classifieds_webhook (
    id VARCHAR(26) NOT NULL,
    event VARCHAR(32) NOT NULL,
    url VARCHAR(500) NOT NULL,
    secret VARCHAR(200) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_state VARCHAR(200) NULL,
    last_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY classifieds_webhook_event (event, active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
