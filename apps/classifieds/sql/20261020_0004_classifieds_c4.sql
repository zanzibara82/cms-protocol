-- C4: модерация, жалобы, переписка и уведомления.
-- Проверка объявления идёт после отправки: продавец присылает запись на проверку, а
-- редактор публикует или отклоняет её с причиной. Причина хранится рядом со статусом,
-- чтобы кабинет показал её без отдельного журнала.
ALTER TABLE tbl_classifieds_listing
    ADD COLUMN moderation_note VARCHAR(500) NULL AFTER expires_at,
    ADD COLUMN submitted_at DATETIME NULL AFTER moderation_note,
    ADD KEY classifieds_listing_review (status, submitted_at);

-- Жалоба хранит автора и решение редактора. Повторная жалоба того же человека на то же
-- объявление не создаётся (проверка в коде), а закрытая остаётся историей.
CREATE TABLE IF NOT EXISTS tbl_classifieds_report (
    id VARCHAR(26) NOT NULL,
    listing_id VARCHAR(26) NOT NULL,
    reporter_id VARCHAR(26) NOT NULL,
    reason VARCHAR(40) NOT NULL,
    comment TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'NEW',
    resolver_id VARCHAR(26) NULL,
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY classifieds_report_status (status, created_at),
    KEY classifieds_report_listing (listing_id),
    KEY classifieds_report_reporter (reporter_id, created_at),
    CONSTRAINT fk_classifieds_report_listing FOREIGN KEY (listing_id)
        REFERENCES tbl_classifieds_listing (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_report_reporter FOREIGN KEY (reporter_id)
        REFERENCES tbl_user (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_report_resolver FOREIGN KEY (resolver_id)
        REFERENCES tbl_user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переписка привязана к объявлению: покупатель пишет владельцу, владелец отвечает
-- тому же покупателю. Отдельной таблицы тем нет — пара участников и объявление и есть тема.
CREATE TABLE IF NOT EXISTS tbl_classifieds_message (
    id VARCHAR(26) NOT NULL,
    listing_id VARCHAR(26) NOT NULL,
    sender_id VARCHAR(26) NOT NULL,
    recipient_id VARCHAR(26) NOT NULL,
    body TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY classifieds_message_thread (listing_id, sender_id, recipient_id, created_at),
    KEY classifieds_message_inbox (recipient_id, read_at, created_at),
    CONSTRAINT fk_classifieds_message_listing FOREIGN KEY (listing_id)
        REFERENCES tbl_classifieds_listing (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_message_sender FOREIGN KEY (sender_id)
        REFERENCES tbl_user (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_message_recipient FOREIGN KEY (recipient_id)
        REFERENCES tbl_user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Уведомление — короткая запись для колокольчика: сообщение, решение модерации или
-- ответ на жалобу. Ссылки на объявление и сообщение необязательны и гаснут вместе с ними.
CREATE TABLE IF NOT EXISTS tbl_classifieds_notification (
    id VARCHAR(26) NOT NULL,
    user_id VARCHAR(26) NOT NULL,
    kind VARCHAR(30) NOT NULL,
    listing_id VARCHAR(26) NULL,
    message_id VARCHAR(26) NULL,
    body VARCHAR(500) NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY classifieds_notification_unread (user_id, read_at, created_at),
    CONSTRAINT fk_classifieds_notification_user FOREIGN KEY (user_id)
        REFERENCES tbl_user (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_notification_listing FOREIGN KEY (listing_id)
        REFERENCES tbl_classifieds_listing (id) ON DELETE SET NULL,
    CONSTRAINT fk_classifieds_notification_message FOREIGN KEY (message_id)
        REFERENCES tbl_classifieds_message (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
