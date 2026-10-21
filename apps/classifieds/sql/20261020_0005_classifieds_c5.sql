-- C5: продвижение. Услуги платные и продаются заказами магазина: кабинет создаёт заказ
-- (tbl_commerce_order), а после оплаты магазину он же включает услугу. «Поднятие» не трогает
-- дату создания: boosted_at ведёт ленты, created_at остаётся честной датой публикации.
ALTER TABLE tbl_classifieds_listing
    ADD COLUMN boosted_at DATETIME NULL AFTER expires_at,
    ADD COLUMN featured_until DATETIME NULL AFTER boosted_at,
    ADD INDEX classifieds_listing_boosted (boosted_at);

-- Покупка услуги: статус повторяет путь заказа — NEW ждёт оплату, ACTIVE применён,
-- CANCELLED — заказ отменён до оплаты. Ссылка на заказ без FK: магазин может быть
-- не установлен или уйти из сборки, а история продвижений должна остаться читаемой.
CREATE TABLE IF NOT EXISTS tbl_classifieds_promotion (
    id VARCHAR(26) NOT NULL,
    listing_id VARCHAR(26) NOT NULL,
    user_id VARCHAR(26) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    order_id VARCHAR(26) NULL,
    order_number INT NULL,
    amount DECIMAL(12,2) NULL,
    currency CHAR(3) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'NEW',
    created_at DATETIME NOT NULL,
    applied_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY classifieds_promotion_listing (listing_id),
    KEY classifieds_promotion_queue (status, created_at),
    CONSTRAINT fk_classifieds_promotion_listing FOREIGN KEY (listing_id)
        REFERENCES tbl_classifieds_listing (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_promotion_user FOREIGN KEY (user_id)
        REFERENCES tbl_user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
