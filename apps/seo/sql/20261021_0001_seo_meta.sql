-- S1: метаданные страниц. Строка описывает один адрес сайта: заголовок и описание для
-- сниппета, канонический адрес, директивы robots, карточку соцсети и два флага —
-- «закрыт ли адрес от индексации» и «включён ли он в карту сайта». Ключ — сам адрес:
-- метаданные живут не в материале, а у пути, поэтому ими можно править любую страницу
-- раздела, не залезая в его собственные таблицы.
CREATE TABLE IF NOT EXISTS tbl_seo_meta (
    id VARCHAR(26) NOT NULL,
    path VARCHAR(255) NOT NULL,
    title VARCHAR(255) NULL,
    description VARCHAR(500) NULL,
    canonical VARCHAR(255) NULL,
    robots VARCHAR(64) NULL,
    og_title VARCHAR(255) NULL,
    og_description VARCHAR(500) NULL,
    og_image VARCHAR(255) NULL,
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    in_sitemap TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_meta_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
