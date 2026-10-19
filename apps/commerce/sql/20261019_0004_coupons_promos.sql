-- Скидки доски M4: купоны и промо-правила. Купон вводится покупателем на корзине,
-- правило работает само и всегда применяет лучший исход. Заказ хранит скидки своей
-- сделки целиком: слова купона и названия правила остаются в строке заказа даже после
-- удаления деревянных записей — история продаж читаема и без них.
-- value — размер скидки: проценты (0..100) или фиксированная сумма в валюте заказа.
CREATE TABLE IF NOT EXISTS tbl_commerce_coupon (
    id VARCHAR(26) NOT NULL,
    code VARCHAR(40) NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'PERCENT',
    value DECIMAL(12,2) NOT NULL,
    min_total DECIMAL(12,2) NULL,
    expires_at DATETIME NULL,
    max_uses INT NULL,
    used_count INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY commerce_coupon_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Промо-правило: автоматическая скидка на корзине с периодом действия. Начало и конец
-- по отдельности необязательны: правило может ждать своего часа или работать бессрочно.
CREATE TABLE IF NOT EXISTS tbl_commerce_promo (
    id VARCHAR(26) NOT NULL,
    title VARCHAR(160) NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'PERCENT',
    value DECIMAL(12,2) NOT NULL,
    min_total DECIMAL(12,2) NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Скидки заказа: код купона и название правила — снимок сделки словами, суммы скидки
-- отдельными колонками, чтобы витрина и письмо собирали итог из честных частей.
ALTER TABLE tbl_commerce_order
    ADD COLUMN coupon_code VARCHAR(40) NULL,
    ADD COLUMN coupon_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN promo_title VARCHAR(160) NULL,
    ADD COLUMN promo_discount DECIMAL(12,2) NOT NULL DEFAULT 0;
