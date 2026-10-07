-- Сопоставление баз задаётся явно: спецификация требует utf8mb4 / utf8mb4_unicode_ci,
-- а MySQL 5.7 на боевом хостинге про utf8mb4_0900_ai_ci (default сервера 8.0) ничего не знает.
-- cms_dev раньше создавалась переменной MYSQL_DATABASE и доставала серверный default — отсюда
-- явный CREATE здесь, а не в compose.

-- Рабочая база разработчика.
CREATE DATABASE IF NOT EXISTS cms_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Вторая база нужна тестам: их сбрасывает scripts/db-reset, а cms_dev остаётся разработчику.
CREATE DATABASE IF NOT EXISTS cms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
