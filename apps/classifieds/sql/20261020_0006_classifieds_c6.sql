-- C6: аналитика и SEO доски. Счётчик просмотров объявления живёт рядом с объявлением:
-- views_count — сколько раз объявление открыли, views_public — разрешено ли показывать
-- это число публично (владелец решает сам), last_viewed_at — когда открывали в последний
-- раз. Просмотр засчитывается только опубликованному объявлению; владельцу и редактору
-- объявление не засчитывается, иначе счётчик врал бы с первого же дня.
ALTER TABLE tbl_classifieds_listing
    ADD COLUMN views_count INT NOT NULL DEFAULT 0 AFTER featured_until,
    ADD COLUMN views_public TINYINT(1) NOT NULL DEFAULT 1 AFTER views_count,
    ADD COLUMN last_viewed_at DATETIME NULL AFTER views_public,
    ADD INDEX classifieds_listing_views (views_count);
