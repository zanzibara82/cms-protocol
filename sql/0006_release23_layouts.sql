-- RELEASE 2.3 (часть B): макет страницы и ширина секции. Файл только дополняет схему: строки
-- tbl_page и tbl_page_section остаются на месте, значения существующих колонок не меняются.
--
-- Макет задаёт каркас (шапка, подвал, боковые панели) — до этого релиза каркас был один на весь
-- сайт. Ширина секции управляет контейнером внутри неё: standard, wide, full, custom.
--
-- Оба значения — VARCHAR с перечнем в PHP (core/page-layout.php): CHECK в MySQL 5.7 молча
-- игнорируется, поэтому границы держат правило и форма, а не колонка.
--
-- Это первый ALTER в схеме ядра. Повторяемость обеспечивает журнал миграций, а не сам файл:
-- applyApp() пишет строку tbl_app_migration сразу после применения файла, поэтому второй прогон
-- его уже не видит. Другой канал — setupApplyBaseline(): он применяет базовый файл и берёт
-- остальные через planApp('core'), то есть тоже по журналу. Тестовая база собирается тем же
-- журналом (tests/TestDb.php), иначе установщик в прогоне применял бы файлы вторично и падал
-- с 1060 «Duplicate column name». Держит CoreMigrationIdempotencyTest.

ALTER TABLE tbl_page
  ADD COLUMN layout VARCHAR(32) NOT NULL DEFAULT 'default' AFTER noindex;

ALTER TABLE tbl_page_section
  ADD COLUMN width VARCHAR(16) NOT NULL DEFAULT 'standard' AFTER layout,
  ADD COLUMN width_custom INT NOT NULL DEFAULT 1200 AFTER width;
