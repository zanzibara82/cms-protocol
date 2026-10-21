-- S3: мониторинг и аудит. Журнал 404 — адреса, которых нет, с числом обращений; редиректы
-- 301/302 — правила перевода старых адресов на новые; находки аудита — замечания к метаданным
-- с историей; разметка Schema.org — то, что печатается в голове страницы.
--
-- Приватность: в журнал 404 попадает только адрес без строки запроса (иначе в журнал уехали бы
-- чувствительные параметры) и хост страницы-источника без полного адреса. Адрес посетителя не
-- пишется никуда, и сам журнал отдаётся только редактору.
CREATE TABLE IF NOT EXISTS tbl_seo_404 (
    id VARCHAR(26) NOT NULL,
    path VARCHAR(255) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    referrer_host VARCHAR(190) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_404_path (path),
    KEY seo_404_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Редирект: один адрес — одно правило. Код 301 означает «переехали навсегда», 302 — «временно».
CREATE TABLE IF NOT EXISTS tbl_seo_redirect (
    id VARCHAR(26) NOT NULL,
    from_path VARCHAR(255) NOT NULL,
    to_path VARCHAR(255) NOT NULL,
    code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_redirect_from (from_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Находка аудита: тип проверки, адрес, важность, описание и рекомендация. История — это
-- первое и последнее обнаружение, счётчик повторов и отметка об исправлении.
CREATE TABLE IF NOT EXISTS tbl_seo_audit_issue (
    id VARCHAR(26) NOT NULL,
    code VARCHAR(64) NOT NULL,
    path VARCHAR(255) NOT NULL,
    severity VARCHAR(16) NOT NULL,
    title VARCHAR(190) NOT NULL,
    description VARCHAR(500) NOT NULL,
    recommendation VARCHAR(500) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    seen_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    fixed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_audit_issue_code_path (code, path),
    KEY seo_audit_issue_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Разметка Schema.org по адресу страницы: тип объекта и его поля. Одна страница — одна
-- разметка: две разные разметки одного адреса читались бы как две сущности.
CREATE TABLE IF NOT EXISTS tbl_seo_schema (
    id VARCHAR(26) NOT NULL,
    path VARCHAR(255) NOT NULL,
    schema_type VARCHAR(32) NOT NULL,
    name VARCHAR(190) NOT NULL,
    description VARCHAR(500) NULL,
    image VARCHAR(255) NULL,
    author VARCHAR(190) NULL,
    date_published VARCHAR(32) NULL,
    date_modified VARCHAR(32) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_schema_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
