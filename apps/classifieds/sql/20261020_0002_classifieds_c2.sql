-- C2 доски: галерея объявления, характеристики категорий и значения характеристик.
-- Галерея — дополнительные снимки к главному полю listing.image: главный кадр остаётся
-- в объявлении (карточка витрины не делает лишний JOIN), а снимки хранятся строками со своим
-- порядком. Удаление объявления уносит снимки каскадом, файлы с диска убирает кабинет.
-- Характеристики принадлежат категории и наследуются её потомками: у «Авто» поле «Год» видно
-- и в «Авто / Запчасти». key_slug — устойчивое имя для формы, не зависит от подписи.
-- option_list — варианты списка по одному в строке: отдельная таблица вариантов на этом этапе
-- не нужна, а TEXT переживёт правку набора без миграции.
-- Значение хранится в двух колонках: текст/список — value_text, число/флаг — value_number.
-- Составной первичный ключ не даёт объявлению два значения одной характеристики.
CREATE TABLE IF NOT EXISTS tbl_classifieds_listing_image (
    id VARCHAR(26) NOT NULL,
    listing_id VARCHAR(26) NOT NULL,
    href VARCHAR(300) NOT NULL,
    sort_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY classifieds_image_listing (listing_id, sort_index),
    CONSTRAINT fk_classifieds_image_listing FOREIGN KEY (listing_id)
        REFERENCES tbl_classifieds_listing (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_classifieds_attribute (
    id VARCHAR(26) NOT NULL,
    category_id VARCHAR(26) NOT NULL,
    key_slug VARCHAR(40) NOT NULL,
    title VARCHAR(120) NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'text',
    unit VARCHAR(20) NULL,
    option_list TEXT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    filterable TINYINT(1) NOT NULL DEFAULT 1,
    order_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY classifieds_attribute_key (category_id, key_slug),
    KEY classifieds_attribute_category (category_id, order_index),
    CONSTRAINT fk_classifieds_attribute_category FOREIGN KEY (category_id)
        REFERENCES tbl_classifieds_category (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_classifieds_listing_attribute (
    listing_id VARCHAR(26) NOT NULL,
    attribute_id VARCHAR(26) NOT NULL,
    value_text VARCHAR(255) NULL,
    value_number DECIMAL(12,2) NULL,
    PRIMARY KEY (listing_id, attribute_id),
    KEY classifieds_value_text (attribute_id, value_text),
    KEY classifieds_value_number (attribute_id, value_number),
    CONSTRAINT fk_classifieds_value_listing FOREIGN KEY (listing_id)
        REFERENCES tbl_classifieds_listing (id) ON DELETE CASCADE,
    CONSTRAINT fk_classifieds_value_attribute FOREIGN KEY (attribute_id)
        REFERENCES tbl_classifieds_attribute (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
