-- Каталог магазина: рубрики, товары и связка товар-рубрика.
-- Денежные поля — DECIMAL(12,2): точность копеек обязательна для сумм заказа,.FLOAT() в расчётах допускал бы расхождение копеек.
-- Валюта хранится в товаре (CHAR(3)): до molnи разных валют корзина копирует её в заказ.
-- `lead_text` повторяет приём новостного раздела: LEAD — зарезервированное слово MySQL 8.0.
-- `body` — JSON-массив абзацев [{title, text}], сырого HTML нет; валидность проверяет PHP до записи.
CREATE TABLE IF NOT EXISTS tbl_commerce_category (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(120) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY commerce_category_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_commerce_product (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    title VARCHAR(160) NOT NULL,
    lead_text VARCHAR(300) NULL,
    cover VARCHAR(300) NULL,
    body JSON NULL,
    brand VARCHAR(120) NULL,
    barcode VARCHAR(64) NULL,
    price_retail DECIMAL(12,2) NOT NULL,
    price_promo DECIMAL(12,2) NULL,
    price_cost DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'RUB',
    stock INT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    seo_title VARCHAR(120) NULL,
    seo_description VARCHAR(300) NULL,
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY commerce_product_slug (slug),
    UNIQUE KEY commerce_product_sku (sku),
    KEY commerce_product_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_commerce_product_category (
    product_id VARCHAR(26) NOT NULL,
    category_id VARCHAR(26) NOT NULL,
    PRIMARY KEY (product_id, category_id),
    KEY commerce_product_category_category (category_id, product_id),
    CONSTRAINT fk_commerce_pc_product FOREIGN KEY (product_id)
        REFERENCES tbl_commerce_product (id) ON DELETE CASCADE,
    -- RESTRICT: у привязанных товаров рубрика не удаляется втихую; экран справочника покажет причину.
    CONSTRAINT fk_commerce_pc_category FOREIGN KEY (category_id)
        REFERENCES tbl_commerce_category (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
