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
#
# Статус самого `find` захватывается явно, а пустой список — это падение ворот: `xargs -r` при нулевом
# вводе возвращает 0, и шаг «проверяет» ноль файлов, выглядя зелёным. Так тихое самоисключение
# стражи не может повториться после переезда дерева PHP или сбоя прав доступа.
#
# Пятый заявленный шаг ворот («вывод тестов без предупреждений») живёт не здесь, а в флагах
# `phpunit.xml` (`failOnWarning` и `displayDetailsOnTestsThatTriggerWarnings`): читатель ворот
# должен искать его там, а не добавлять заглушку в этот файл.
run bash -c 'find . -type d \( -name vendor -o -name node_modules -o -name .git \) -prune -o -name "*.php" -print0 \
  > /tmp/php-list.txt; find_status=$?; \
  if [ $find_status -ne 0 ]; then echo "Поиск PHP-файлов упал с кодом $find_status — список не построен, проверять нечего" >&2; exit $find_status; fi; \
  files=$(tr -d -c "\0" < /tmp/php-list.txt | wc -c); \
  if [ "$files" -eq 0 ]; then echo "Ни одного PHP-файла не найдено: шаг синтаксиса проверил ноль файлов, ворота не считаются пройденными" >&2; exit 1; fi; \
  echo "проверяемых файлов: $files"; \
  xargs -0 -r -n1 php -l < /tmp/php-list.txt > /tmp/php-l.txt 2>&1; status=$?; \
  grep -v "No syntax errors detected" /tmp/php-l.txt || true; exit $status'

echo '— phpstan'
run vendor/bin/phpstan analyse --no-progress

echo '— стиль'
# Единственный разрешённый шум в воротах: фиксер предупреждает, что образ PHP 8.3 новее
# минимума проекта из composer.json (8.1). Снять предупреждение нечем: оно печатается
# безусловно, а флаг `PHP_CS_FIXER_IGNORE_ENV` устарел и лишь добавляет строку о своём
# устаревании. Понижать `config.platform.php` до 8.1 нельзя — PHPUnit 11 требует PHP >= 8.2,
# и разрешение зависимостей сломается. На код ворот это не влияет: предупреждение идёт в stderr.
run vendor/bin/php-cs-fixer fix --dry-run --diff

echo '— тесты'
# Аргументы воротам не пробрасываются нарочно: с `"$@"` вызов вида
# `bash scripts/gate.sh --filter Что-то` гонял бы четверть набора, phpstan и фиксер при этом
# зеленели, и коммит выглядел проверенным полным прогоном. Ворота не имеют переключателя силы:
# здесь всегда весь PHPUnit. Отдельные наборы запускают прямыми командами контейнера
# (`docker compose -f docker-compose.dev.yml run --rm -T php vendor/bin/phpunit tests/guard`).
run vendor/bin/phpunit
