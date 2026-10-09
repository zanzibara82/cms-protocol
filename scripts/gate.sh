#!/usr/bin/env bash
# Единственные ворота перед коммитом: свежесть стилей, синтаксис, статический анализ, стиль, тесты.
set -euo pipefail

run() {
  docker compose -f docker-compose.dev.yml run --rm -T php "$@"
}

echo '— свежесть стилей'
# Единственный шаг ворот, который живёт на хосте, а не в контейнере: образ php:8.3-cli Node не
# ставит, а сборщик стилей (Tailwind CLI) лежит в node_modules разработчика. Шаг ничего не
# переписывает в репозитории: он собирает в временный каталог и сравнивает побайтово, поэтому
# зелёный прогон безопасен, а красный означает ровно одно — public/assets/app.css отстал от
# разметки или от src-css/app.css. Сканируется тот же набор каталогов, что и при обычной сборке:
# список @source задан в src-css/app.css, а не здесь.
if [ ! -x node_modules/.bin/tailwindcss ]; then
  echo 'Нет node_modules/.bin/tailwindcss: выполни `npm ci` или `bash scripts/css` — сверять артефакт нечем.' >&2
  exit 1
fi
css_tmp=$(mktemp -d)
trap 'rm -rf "$css_tmp"' EXIT
if node_modules/.bin/tailwindcss -i ./src-css/app.css -o "$css_tmp/app.css" --minify >"$css_tmp/build.log" 2>&1; then
  if ! cmp -s "$css_tmp/app.css" public/assets/app.css; then
    echo 'Собранный стилевой файл не совпадает с public/assets/app.css: пересобери (`bash scripts/css`) и закоммити результат.' >&2
    # Минифицированный артефакт — одна строка, поэтому различия показываются по сегментам правил.
    diff --unified=0 <(tr ';' '\n' < public/assets/app.css) <(tr ';' '\n' < "$css_tmp/app.css") | head -40 || true
    exit 1
  fi
  # Сборка, которая прошла, но что-то сказала, — находка: до этой проверки весь вывод шага
  # выбрасывался вместе с временным каталогом, и предупреждение нового Tailwind (устаревшая
  # директива, не найденный источник, нечитаемый файл) исчезало молча, оставив ворота зелёными.
  # Чистый вывод сборки — пара строк про версию и время, порогу там не срабатывать.
  if grep -qiE 'warn|error|cannot|not found' "$css_tmp/build.log"; then
    echo 'Сборка стилей прошла без падения, но с предупреждениями — это находка (вывод без предупреждений):' >&2
    cat "$css_tmp/build.log" >&2
    exit 1
  fi
else
  echo 'Сборка стилей упала:' >&2
  cat "$css_tmp/build.log" >&2
  exit 1
fi

echo '— синтаксис'
# Правка против первого варианта: статус `php -l` обязан доходить до ворот (поэтому `exit $status`,
# а не `|| true`). Прежняя фраза «каталогов `admin`, `theme`, `apps` на этой задаче ещё нет»
# устарела: все три каталога в дереве уже есть, и шаг никогда от их списка не зависел — `find`
# идёт от корня и вырезает только `vendor`, `node_modules` и `.git`, поэтому новый каталог с PHP
# не требует правки этого шага.
#
# Статус самого `find` захватывается явно, а пустой список — это падение ворот: `xargs -r` при нулевом
# вводе возвращает 0, и шаг «проверяет» ноль файлов, выглядя зелёным. Так тихое самоисключение
# стражи не может повториться после переезда дерева PHP или сбоя прав доступа.
#
# Пятый заявленный шаг ворот («вывод тестов без предупреждений») живёт не здесь, а в флагах
# `phpunit.xml`: `failOnWarning`, `failOnNotice` и `failOnDeprecation` валят прогон на любом
# предупреждении, notice или устаревании, а `beStrictAboutOutputDuringTests` в паре с
# `failOnRisky` не даёт тесту напечатать текст вместо проверки — сам по себе этот флаг лишь
# помечает тест рискованным, и прогон остаётся зелёным. Читатель ворот должен искать их там,
# а не добавлять заглушку в этот файл.
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
