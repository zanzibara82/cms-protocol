-- S2: статистика посещений. Сырьё — каждое принятое событие: какой адрес открыли, из какого
-- источника пришли, на каком устройстве и когда. Агрегаты — те же числа, свёрнутые за день
-- (с разрезом по адресу и источнику) и за час (с разрезом по источнику): панель читает их,
-- а не сырьё, поэтому обзор не деградирует с ростом трафика.
--
-- Приватность: cookie не используется, адрес посетителя не хранится, содержимое форм не
-- собирается. Идентификатор посетителя — подпись случайного номера из localStorage, а не
-- личность; подпись устойчива, поэтому по ней считаются уникальные посетители за период.
CREATE TABLE IF NOT EXISTS tbl_seo_visit (
    id VARCHAR(26) NOT NULL,
    session_id VARCHAR(26) NOT NULL,
    visitor_id VARCHAR(32) NOT NULL,
    path VARCHAR(255) NOT NULL,
    source VARCHAR(32) NOT NULL,
    referrer_host VARCHAR(190) NULL,
    utm_source VARCHAR(190) NULL,
    utm_medium VARCHAR(190) NULL,
    utm_campaign VARCHAR(190) NULL,
    device VARCHAR(16) NOT NULL,
    day DATE NOT NULL,
    hour TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY seo_visit_day_source_visitor (day, source, visitor_id),
    KEY seo_visit_visitor_time (visitor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сессия — окно бездействия, а не визит: последняя страница, с которой ушёл посетитель,
-- нужна для счёта выходов, а первый адрес — для посадочных.
CREATE TABLE IF NOT EXISTS tbl_seo_session (
    id VARCHAR(26) NOT NULL,
    visitor_id VARCHAR(32) NOT NULL,
    source VARCHAR(32) NOT NULL,
    landing_path VARCHAR(255) NOT NULL,
    last_path VARCHAR(255) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    day DATE NOT NULL,
    PRIMARY KEY (id),
    KEY seo_session_visitor_time (visitor_id, last_seen_at),
    KEY seo_session_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Агрегат за день: адрес и источник. Просмотры и сессии сходятся с числом в сырье.
CREATE TABLE IF NOT EXISTS tbl_seo_stat_day (
    day DATE NOT NULL,
    path VARCHAR(255) NOT NULL,
    source VARCHAR(32) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, path, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Агрегат за час: источник без адреса. Показывает, когда site приходит трафик.
CREATE TABLE IF NOT EXISTS tbl_seo_stat_hour (
    day DATE NOT NULL,
    hour TINYINT UNSIGNED NOT NULL,
    source VARCHAR(32) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, hour, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
