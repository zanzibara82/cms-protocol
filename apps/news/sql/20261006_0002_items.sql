-- Материалы раздела «Новости», их теги и прежние адреса.
-- `body` — JSON-массив абзацев [{title, text}]; сырого HTML в материале нет, выделение
-- задаётся **звёздочками** и разбирается при выводе. Валидность JSON проверяет PHP до записи.
-- Колонка лида называется lead_text: LEAD — зарезервированное слово MySQL 8.0, без бэктиков сервер отвергает его как имя.
CREATE TABLE IF NOT EXISTS tbl_news_item (
    id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(120) NOT NULL,
    lead_text VARCHAR(300) NULL,
    cover VARCHAR(300) NULL,
    category_id VARCHAR(26) NOT NULL,
    body JSON NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    published_at DATETIME NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    seo_title VARCHAR(120) NULL,
    seo_description VARCHAR(300) NULL,
    og_image VARCHAR(300) NULL,
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    author_id VARCHAR(26) NULL,
    author_email VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY news_item_slug (slug),
    KEY news_item_status_published (status, published_at),
    KEY news_item_category_status (category_id, status, published_at),
    KEY news_item_pinned (pinned, published_at),
    CONSTRAINT fk_news_item_category FOREIGN KEY (category_id)
        REFERENCES tbl_news_category (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_news_item_tag (
    item_id VARCHAR(26) NOT NULL,
    tag_id VARCHAR(26) NOT NULL,
    PRIMARY KEY (item_id, tag_id),
    KEY news_item_tag_tag (tag_id, item_id),
    CONSTRAINT fk_news_item_tag_item FOREIGN KEY (item_id)
        REFERENCES tbl_news_item (id) ON DELETE CASCADE,
    CONSTRAINT fk_news_item_tag_tag FOREIGN KEY (tag_id)
        REFERENCES tbl_news_tag (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Прежние адреса материала: tbl_news_old_slug заменяет String[] oldSlugs из PostgreSQL,
-- потому что в MySQL поиск по JSON-массиву без индекса сканирует таблицу на каждом открытии
-- старого адреса. Лимит «не больше 10 на материал» проверяет rememberOldSlug() в PHP.
CREATE TABLE IF NOT EXISTS tbl_news_old_slug (
    slug VARCHAR(60) NOT NULL,
    item_id VARCHAR(26) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (slug),
    KEY news_old_slug_item (item_id),
    CONSTRAINT fk_news_old_slug_item FOREIGN KEY (item_id)
        REFERENCES tbl_news_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
