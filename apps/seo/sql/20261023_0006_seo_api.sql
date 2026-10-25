-- S6: API-ключи машинного чтения агрегатов (спецификация §18 «API и расширяемость»).
-- Ключ — пароль доступа к отчётам: хранится только хеш, полный ключ показывается один раз
-- при создании (клауза о secrets в README). Префикс нужен людям — чтобы отличать ключи
-- в списке, не раскрывая их целиком.
CREATE TABLE IF NOT EXISTS tbl_seo_api_key (
    id VARCHAR(26) NOT NULL,
    label VARCHAR(190) NOT NULL,
    key_prefix VARCHAR(12) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_api_key_hash (key_hash),
    KEY seo_api_key_label (label(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
