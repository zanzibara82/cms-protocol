-- Поставщики и прайс-листы (M5): справочник источников товара и обмен ценами файлом
-- CSV. Поставщик — словарная единица раздела: свои строки описания не обязаны жить
-- без названия, а удаление источника с привязанными вариантами раздел запрещает,
-- чтобы прайс-лист не потерял скоуп.

CREATE TABLE IF NOT EXISTS tbl_commerce_supplier (
    id VARCHAR(26) NOT NULL,
    title VARCHAR(160) NOT NULL,
    note VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ссылка варианта на поставщика: цена и остаток едут прайс-листом своего источника.
-- Поставщик — не история: правка справочника не должна ломать строки заказа, поэтому
-- внешнего ключа здесь нет. Удаление поставщика раздел запрещает, пока у него есть
-- варианты — колонка не остаётся с висячей ссылкой.
ALTER TABLE tbl_commerce_variant
    ADD COLUMN supplier_id VARCHAR(26) NULL,
    ADD KEY commerce_variant_supplier (supplier_id);
