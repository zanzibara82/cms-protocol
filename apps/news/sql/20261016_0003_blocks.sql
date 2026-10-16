-- Таблица блоков вывода раздела. IF NOT EXISTS и INSERT IGNORE обязательны: MySQL фиксирует DDL
-- сразу, а журнал пишется после файла, поэтому недочитанный релиз повторяется по живым таблицам.
-- Внешних ключей нет намеренно (решение 8): блок переживает удаление своей цели.
CREATE TABLE IF NOT EXISTS tbl_news_block (
    id VARCHAR(26) NOT NULL,
    zone VARCHAR(32) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    title VARCHAR(120) NOT NULL,
    target_kind VARCHAR(16) NULL,
    target_id VARCHAR(26) NULL,
    take_limit TINYINT NOT NULL DEFAULT 5,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    order_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY news_block_zone_order (zone, order_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Две заводские строки: карточка материала с «похожими» и главная со свежими. Идентификаторы
-- короче обычных 26 символов newId() намеренно: они литеры релиза, а не порождение генератора,
-- и по PRIMARY KEY конфликтуют только с собой, чего `INSERT IGNORE` и не допускает.
INSERT IGNORE INTO tbl_news_block (id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index, created_at, updated_at)
    VALUES ('newsblk-article', 'article', 'related', 'Похожие материалы', NULL, NULL, 5, 1, 0, '2026-10-16 00:00:00', '2026-10-16 00:00:00');

INSERT IGNORE INTO tbl_news_block (id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index, created_at, updated_at)
    VALUES ('newsblk-home', 'home', 'list', 'Свежие материалы', NULL, NULL, 5, 1, 0, '2026-10-16 00:00:00', '2026-10-16 00:00:00');
