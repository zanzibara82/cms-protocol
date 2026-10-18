-- Заказы и их строки. Корзина живёт в сессии и не имеет таблицы: заказ рождается при
-- оформлении и хранит снимки строк (SKU и цена на момент покупки) — правка товара задним
-- числом не должна менять уже оформленные заказы.
-- `number` — вторая PK-строка счётчик: человек читает номер в письме и подтверждении,
-- CHAR(26)-id для него не адрес, а внутренний ключ.
-- product_id — NOT NULL с RESTRICT: удалить товар, по которому были заказы, раздел не даёт
-- (конструктор ошибок причину печатает); убрать продукт из продажи можно переключением статуса.
CREATE TABLE IF NOT EXISTS tbl_commerce_order (
    id VARCHAR(26) NOT NULL,
    number INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_name VARCHAR(160) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(32) NULL,
    address VARCHAR(300) NULL,
    comment VARCHAR(500) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'RUB',
    total DECIMAL(12,2) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'NEW',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY commerce_order_number (number),
    KEY commerce_order_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_commerce_order_item (
    id VARCHAR(26) NOT NULL,
    order_id VARCHAR(26) NOT NULL,
    product_id VARCHAR(26) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    title VARCHAR(160) NOT NULL,
    currency CHAR(3) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    qty INT NOT NULL,
    PRIMARY KEY (id),
    KEY commerce_order_item_order (order_id),
    CONSTRAINT fk_commerce_item_order FOREIGN KEY (order_id)
        REFERENCES tbl_commerce_order (id) ON DELETE CASCADE,
    CONSTRAINT fk_commerce_item_product FOREIGN KEY (product_id)
        REFERENCES tbl_commerce_product (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
