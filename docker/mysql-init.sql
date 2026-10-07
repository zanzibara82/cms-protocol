-- Вторая база нужна тестам: их сбрасывает scripts/db-reset, а cms_dev остаётся разработчику.
CREATE DATABASE IF NOT EXISTS cms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
