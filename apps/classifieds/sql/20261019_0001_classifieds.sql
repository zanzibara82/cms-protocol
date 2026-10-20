-- Фундамент доски объявлений: дерево категорий и объявления.
-- Дерево хранится ссылками parent_id: NULL — корень. Глубина проверяется в PHP при смене
-- родителя (нельзя завесить цикл), а выборка ветки идёт рекурсией в коде — объём справочника
-- сотен строк: читать предков на каждый узел рекурсивный CTE на этом этапе не экономит.
-- Цена — DECIMAL(12,2) и может быть NULL: объявление бывает без цены с пометкой «договорная».
-- Валюта — CHAR(3), по умолчанию рубль; много валют заведут на следующем этапе, если понадобятся.
-- Описание — обычный текст абзацев: HTML на доске нет, и JSON-представление новостей здесь
-- излишне, пока не поднят редактор с выделением (C2).
-- Статусы в C1 — DRAFT и PUBLISHED; PENDING_REVIEW, EXPIRED, ARCHIVED, SUSPENDED попадут в схему
-- вместе с модерацией (C4): колонка VARCHAR не поменяется, а статусы без кода модерации
-- только путали бы.
CREATE TABLE IF NOT EXISTS tbl_classifieds_category (
    id VARCHAR(26) NOT NULL,
    parent_id VARCHAR(26) NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(120) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY classifieds_category_slug (slug),
    KEY classifieds_category_parent (parent_id, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_classifieds_listing (
    id VARCHAR(26) NOT NULL,
    owner_id VARCHAR(26) NOT NULL,
    category_id VARCHAR(26) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(140) NOT NULL,
    body TEXT NOT NULL,
    price DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'RUB',
    negotiable TINYINT(1) NOT NULL DEFAULT 0,
    location VARCHAR(120) NULL,
    image VARCHAR(300) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    seo_title VARCHAR(120) NULL,
    seo_description VARCHAR(300) NULL,
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY classifieds_listing_slug (slug),
    KEY classifieds_listing_owner (owner_id, updated_at),
    KEY classifieds_listing_status (status, created_at),
    KEY classifieds_listing_category (category_id, status, created_at),
    CONSTRAINT fk_classifieds_listing_owner FOREIGN KEY (owner_id)
        REFERENCES tbl_user (id) ON DELETE RESTRICT,
    -- RESTRICT: у объявлений живой категории не удаляется втихую; экран справочника объяснит причину.
    CONSTRAINT fk_classifieds_listing_category FOREIGN KEY (category_id)
        REFERENCES tbl_classifieds_category (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Попытки самостоятельной регистрации продавцов: журнал держит лимит по адресу, чтобы одна
-- рассылка не завела сотни аккаунтов. Отдельная таблица, а не tbl_login_attempt: неудачный вход
-- и успешная регистрация — разные события, и общий журнал путал бы окна лимитов одно с другим.
CREATE TABLE IF NOT EXISTS tbl_classifieds_registration_attempt (
    id BIGINT NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY classifieds_registration_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
