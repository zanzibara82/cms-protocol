-- C3: кабинеты и избранное.
-- Срок жизни объявления хранится в самом объявлении: продление сдвигает expires_at,
-- а ленты и карточка показывают только неистёкшие записи. NULL означает «без срока»
-- для старых записей и черновиков, чтобы миграция не прятала уже опубликованное.
ALTER TABLE tbl_classifieds_listing
    ADD COLUMN expires_at DATETIME NULL AFTER status,
    ADD KEY classifieds_listing_expiry (expires_at);

-- Публичный профиль продавца отделён от tbl_user: ядро не должно знать о полях доски.
-- Телефон показывается только при show_phone = 1, почта наружу не выводится вообще.
CREATE TABLE IF NOT EXISTS tbl_classifieds_seller_profile (
    user_id VARCHAR(26) NOT NULL,
    phone VARCHAR(40) NULL,
    city VARCHAR(120) NULL,
    about TEXT NULL,
    show_phone TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_classifieds_profile_user FOREIGN KEY (user_id)
        REFERENCES tbl_user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Избранное привязано к аккаунту: гость сначала входит, иначе список не переживёт смену
-- браузера. Каскад на объявление убирает отметку вместе с удалённой записью.
CREATE TABLE IF NOT EXISTS tbl_classifieds_favorite (
    user_id VARCHAR(26) NOT NULL,
    listing_id VARCHAR(26) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, listing_id),
    KEY classifieds_favorite_listing (listing_id),
    KEY classifieds_favorite_recent (user_id, created_at),
    CONSTRAINT fk_classifieds_favorite_user FOREIGN KEY (user_id)
        REFERENCES tbl_user (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_favorite_listing FOREIGN KEY (listing_id)
        REFERENCES tbl_classifieds_listing (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
