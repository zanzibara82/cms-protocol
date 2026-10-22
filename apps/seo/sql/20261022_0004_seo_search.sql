-- S4: поисковая аналитика из официальных API (спецификация §9). Две таблицы: подключение
-- (адрес сайта в поисковике и выданный токен) и импортированные строки (запросы, показы,
-- клики, CTR, позиция).
--
-- Отличие от своей статистики (S2): эти данные отдал чужой API, и лежат они в отдельной таблице.
-- Своя аналитика и данные поисковика не смешиваются ни в базе, ни на экране.
--
-- Секреты: клиентский секрет и токены лежат в базе и никогда не печатаются на экран обратно.
-- Доступ к базе — доступ к интеграции: об этом сказано прямо на экране подключения.
CREATE TABLE IF NOT EXISTS tbl_seo_search_connection (
    id VARCHAR(26) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    site_url VARCHAR(190) NOT NULL,
    client_id VARCHAR(190) NOT NULL DEFAULT '',
    client_secret VARCHAR(255) NOT NULL DEFAULT '',
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    scope VARCHAR(255) NOT NULL DEFAULT '',
    expires_at DATETIME NULL,
    connected_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_search_connection_provider_site (provider, site_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Строка импорта: день, измерение и счётчики. Ключ строки — отпечаток набора (источник, сайт,
-- день, измерения), потому что длинный набор текстовых колонок в уникальный индекс не влезает:
-- в utf8mb4 одна колонка в 255 символов занимает килобайт, а лимит ключа — 3072 байта.
CREATE TABLE IF NOT EXISTS tbl_seo_search_row (
    id VARCHAR(26) NOT NULL,
    row_key CHAR(40) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    site_url VARCHAR(190) NOT NULL,
    day DATE NOT NULL,
    query VARCHAR(255) NOT NULL DEFAULT '',
    page VARCHAR(255) NOT NULL DEFAULT '',
    country VARCHAR(8) NOT NULL DEFAULT '',
    device VARCHAR(16) NOT NULL DEFAULT '',
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    impressions INT UNSIGNED NOT NULL DEFAULT 0,
    ctr DECIMAL(8,5) NOT NULL DEFAULT 0,
    position DECIMAL(8,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_search_row_key (row_key),
    KEY seo_search_row_day (day),
    KEY seo_search_row_query (query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
