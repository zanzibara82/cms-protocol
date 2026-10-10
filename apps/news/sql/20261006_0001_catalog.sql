-- Каталог раздела «Новости»: рубрики и теги.
-- IF NOT EXISTS обязателен: MySQL фиксирует DDL сразу, поэтому журнал пишется после каждого
-- файла, а не одним оборотом (отклонение 9 плана).
CREATE TABLE IF NOT EXISTS tbl_news_category (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(60) NOT NULL,
    description VARCHAR(300) NULL,
    order_index INT NOT NULL DEFAULT 0,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY news_category_slug (slug),
    UNIQUE KEY news_category_title (title),
    KEY news_category_order (order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_news_tag (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    label VARCHAR(40) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY news_tag_slug (slug),
    UNIQUE KEY news_tag_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
