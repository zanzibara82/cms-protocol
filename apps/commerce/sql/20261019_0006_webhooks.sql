-- Webhooks (M6): точки, куда магазин сам толкает события заказов. Покупных цепочек
-- оплаты здесь нет — вебхук сообщает живой цикл заказа (создание и смена статуса)
-- внешнему приёмнику. История доставки не ведётся: честное слово здесь — только
-- последнее состояние посещения, а не коллекция выдуманных подсчётов.

CREATE TABLE IF NOT EXISTS tbl_commerce_webhook (
    id VARCHAR(26) NOT NULL,
    url VARCHAR(500) NOT NULL,
    secret VARCHAR(200) NOT NULL,
    active TINYINT NOT NULL DEFAULT 1,
    last_state VARCHAR(200) NULL,
    last_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
