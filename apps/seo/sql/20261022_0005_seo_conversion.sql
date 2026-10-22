-- S5: конверсии, воронки и контентная аналитика (спецификация §5 «Конверсии и события»).
-- Цель — правило, по которому посещение считается конверсией: просмотр адреса по шаблону
-- (точное совпадение или префикс), опционально с разрезом по источнику и устройству.
-- Конверсия считается один раз на сессию и цель — защита от повторов при обновлении страницы.
CREATE TABLE IF NOT EXISTS tbl_seo_goal (
    id VARCHAR(26) NOT NULL,
    name VARCHAR(190) NOT NULL,
    type VARCHAR(16) NOT NULL DEFAULT 'page_view',
    pattern VARCHAR(255) NOT NULL,
    match_type VARCHAR(16) NOT NULL DEFAULT 'prefix',
    source VARCHAR(32) NULL,
    device VARCHAR(16) NULL,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_goal_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Конверсия: посещение, подходящее под цель. Одна строка = одна конверсия (одна на сессию и цель).
CREATE TABLE IF NOT EXISTS tbl_seo_conversion (
    id VARCHAR(26) NOT NULL,
    goal_id VARCHAR(26) NOT NULL,
    session_id VARCHAR(26) NOT NULL,
    visitor_id VARCHAR(32) NOT NULL,
    path VARCHAR(255) NOT NULL,
    day DATE NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_conversion_goal_session (goal_id, session_id),
    KEY seo_conversion_goal_day (goal_id, day),
    KEY seo_conversion_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Агрегат конверсий по целям за день для быстрых отчётов.
CREATE TABLE IF NOT EXISTS tbl_seo_conversion_day (
    goal_id VARCHAR(26) NOT NULL,
    day DATE NOT NULL,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (goal_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Воронка: цепочка шагов, посетитель проходит их по порядку.
CREATE TABLE IF NOT EXISTS tbl_seo_funnel (
    id VARCHAR(26) NOT NULL,
    name VARCHAR(190) NOT NULL,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY seo_funnel_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_seo_funnel_step (
    id VARCHAR(26) NOT NULL,
    funnel_id VARCHAR(26) NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    pattern VARCHAR(255) NOT NULL,
    match_type VARCHAR(16) NOT NULL DEFAULT 'prefix',
    PRIMARY KEY (id),
    KEY seo_funnel_step_funnel (funnel_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
