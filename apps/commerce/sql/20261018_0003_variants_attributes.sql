-- Варианты товара и характеристики рубрик (M2) по решению этого этапа. Дополнительно:
-- старые данные магазина M1 остаются валидными — товар без вариантов и без атрибутов
-- продаётся как раньше. Вариант несёт свой SKU/цену/остаток; строка заказа хранит
-- ссылку на вариант для возврата остатка при отмене (колонка variant_id в конце файла).
CREATE TABLE IF NOT EXISTS tbl_commerce_variant (
    id VARCHAR(26) NOT NULL,
    product_id VARCHAR(26) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    title VARCHAR(160) NOT NULL,
    price_retail DECIMAL(12,2) NOT NULL,
    price_promo DECIMAL(12,2) NULL,
    stock INT NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY commerce_variant_sku (sku),
    KEY commerce_variant_product (product_id, position),
    CONSTRAINT fk_commerce_variant_product FOREIGN KEY (product_id)
        REFERENCES tbl_commerce_product (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Характеристика рубрики: слад-ключ фильтра в адресе витрины, тип CHOICE (список)
-- или NUMBER (число с диапазоном), unit — необязательная единица измерения.
CREATE TABLE IF NOT EXISTS tbl_commerce_attribute (
    id VARCHAR(26) NOT NULL,
    category_id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    label VARCHAR(120) NOT NULL,
    type VARCHAR(16) NOT NULL DEFAULT 'CHOICE',
    unit VARCHAR(32) NULL,
    order_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY commerce_attribute_slug (slug),
    KEY commerce_attribute_category (category_id, order_index),
    CONSTRAINT fk_commerce_attribute_category FOREIGN KEY (category_id)
        REFERENCES tbl_commerce_category (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Значение CHOICE-характеристики: справочник задаётся рубрикой, но дополняется
-- автоматически при сохранении товара с новым значением — сведения не отграничены.
CREATE TABLE IF NOT EXISTS tbl_commerce_attribute_choice (
    id VARCHAR(26) NOT NULL,
    attribute_id VARCHAR(26) NOT NULL,
    value VARCHAR(300) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY commerce_attribute_choice_value (attribute_id, value),
    KEY commerce_attribute_choice_attribute (attribute_id, order_index),
    CONSTRAINT fk_commerce_choice_attribute FOREIGN KEY (attribute_id)
        REFERENCES tbl_commerce_attribute (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Значение характеристики на товаре: одно значение на характеристику. Обе ссылки
-- CASCADE: значение не живёт ни без товара, ни без характеристики, а сам товар
-- от заказов защищён RESTRICT-связкой строк заказа — каскад сюда не дотягивается.
-- value_num — нормализованное число NUMBER-характеристики: фильтр диапазоном идёт по
-- колонке, а не по строковому значению.
CREATE TABLE IF NOT EXISTS tbl_commerce_product_attribute (
    product_id VARCHAR(26) NOT NULL,
    attribute_id VARCHAR(26) NOT NULL,
    value VARCHAR(300) NOT NULL,
    value_num DECIMAL(12,3) NULL,
    PRIMARY KEY (product_id, attribute_id),
    KEY commerce_pa_attribute (attribute_id, product_id),
    CONSTRAINT fk_commerce_pa_product FOREIGN KEY (product_id)
        REFERENCES tbl_commerce_product (id) ON DELETE CASCADE,
    CONSTRAINT fk_commerce_pa_attribute FOREIGN KEY (attribute_id)
        REFERENCES tbl_commerce_attribute (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ссылка на вариант в строке заказа: отмена заказа возвращает остаток варианту, а не
-- родительскому товару. Без внешнего ключа — строки заказа это снимок сделки, и
-- удаление варианта не должно дёргать историю продаж; удаление варианта магазин
-- запрещает, пока вариант фигурирует в заказах.
ALTER TABLE tbl_commerce_order_item
    ADD COLUMN variant_id VARCHAR(26) NULL,
    ADD KEY commerce_order_item_variant (variant_id);
