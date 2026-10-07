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
  > /tmp/php-list.txt 2>&1; xargs -0 -n1 php -l < /tmp/php-list.txt > /tmp/php-l.txt 2>&1; status=$?; \
  grep -v "No syntax errors detected" /tmp/php-l.txt || true; exit $status'

echo '— phpstan'
run vendor/bin/phpstan analyse --no-progress

echo '— стиль'
run vendor/bin/php-cs-fixer fix --dry-run --diff

echo '— тесты'
run vendor/bin/phpunit "$@"
