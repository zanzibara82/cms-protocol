#!/usr/bin/env bash
# Единственные ворота перед коммитом: синтаксис, статический анализ, стиль, тесты.
set -euo pipefail

run() {
  docker compose -f docker-compose.dev.yml run --rm -T php "$@"
}

echo '— синтаксис'
# Правка против первого варианта: статус `php -l` обязан доходить до ворот (поэтому `exit $status`,
# а не `|| true`), и каталогов `admin`, `theme`, `apps` на этой задаче ещё нет — `find` по их списку
# падал бы с «No such file or directory». Ищем от корня, вырезая чужое.
run bash -c 'find . -type d \( -name vendor -o -name node_modules -o -name .git \) -prune -o -name "*.php" -print0 \
  > /tmp/php-list.txt; xargs -0 -r -n1 php -l < /tmp/php-list.txt > /tmp/php-l.txt 2>&1; status=$?; \
  grep -v "No syntax errors detected" /tmp/php-l.txt || true; exit $status'

echo '— phpstan'
run vendor/bin/phpstan analyse --no-progress

echo '— стиль'
# Окружение передаётся контейнеру запуска, а не берётся из config.platform.php:
# фиксер смотрит на реальный PHP_VERSION образа (8.3) и предупреждает, что он новее
# поддерживаемого. Понижать platform до 8.1 нельзя — PHPUnit 11 требует PHP >= 8.2,
# и разрешение зависимостей сломается.
docker compose -f docker-compose.dev.yml run --rm -T -e PHP_CS_FIXER_IGNORE_ENV=1 php \
  vendor/bin/php-cs-fixer fix --dry-run --diff

echo '— тесты'
run vendor/bin/phpunit "$@"
