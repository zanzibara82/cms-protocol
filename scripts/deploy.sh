#!/usr/bin/env bash
# Выкладка на виртуальный хостинг: один rsync по SSH вместо сотен отдельных передач (§11.2).
#
# Ни адреса, ни пароля в файле нет: хост, логин и путь читаются из переменных окружения, которые
# человек ставит в своей сессии. Пароли SSH-ключей живут в ssh-agent, боевой config.php создаётся
# на сервере вручную (§11.3) и в репозиторий не попадает.
#
# --delete без четырёх исключений ниже уничтожил бы сайт: он стёр бы с сервера config.php с
# паролями к базе, public/install.lock (его снимает только установщик, и на открытом установщике
# сайт доступен любому), обложки из public/uploads и журнал core/storage. Исключённые файлы rsync
# по умолчанию защищает от удаления, и флаг, снимающий эту защиту, здесь быть не должен — за ним
# следит страж DeploySafetyTest: цена одной лишней опции — потеря боевых данных на сервере.
set -euo pipefail

: "${CMS_DEPLOY_USER:?не задан CMS_DEPLOY_USER — логин SSH}"
: "${CMS_DEPLOY_HOST:?не задан CMS_DEPLOY_HOST — адрес сервера}"
: "${CMS_DEPLOY_PATH:?не задан CMS_DEPLOY_PATH — абсолютный каталог проекта на сервере}"

# public — документ-корень перенесён на каталог public/ (предпочтительный вариант, §11.1).
# root   — перенести нельзя: заливается вся структура, служебное закрывает корневой .htaccess.
# Набор файлов в обоих режимах одинаковый: public/index.php требует ../core/bootstrap.php, поэтому
# ядро обязано лежать рядом с public/ всегда. Режим меняет только проверку после заливки.
MODE="${CMS_DEPLOY_MODE:-public}"
case "$MODE" in
  public|root) ;;
  *) echo "CMS_DEPLOY_MODE ждёт public или root, получено: ${MODE}" >&2; exit 1 ;;
esac

RSYNC_OPTS=(-azh --delete --itemize-changes)
if [[ "${1:-}" == '--dry-run' ]]; then
  RSYNC_OPTS+=(--dry-run)
  echo 'Пробег: показываем, что изменится, на сервер не пишем.'
fi

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${CMS_DEPLOY_USER}@${CMS_DEPLOY_HOST}:${CMS_DEPLOY_PATH%/}/"
cd "$REPO"

# Dev-only файлы на сервере не нужны, а их наличие в документ-корне — это подсказка постороннему
# о сборке (phpunit.xml, composer.lock с точными версиями). sql/ остаётся: его читает setup.php.
rsync "${RSYNC_OPTS[@]}" \
  --exclude='/config.php' \
  --exclude='/public/install.lock' \
  --exclude='/public/uploads/' \
  --exclude='/core/storage/' \
  --exclude='/tests/' \
  --exclude='/docs/' \
  --exclude='/docker/' \
  --exclude='/docker-compose.dev.yml' \
  --exclude='/scripts/' \
  --exclude='/src-css/' \
  --exclude='/vendor/' \
  --exclude='/node_modules/' \
  --exclude='/composer.json' \
  --exclude='/composer.lock' \
  --exclude='/package.json' \
  --exclude='/package-lock.json' \
  --exclude='/tailwind.config.js' \
  --exclude='/postcss.config.js' \
  --exclude='/phpstan.neon' \
  --exclude='/phpunit.xml' \
  --exclude='/.php-cs-fixer.dist.php' \
  --exclude='/.php-cs-fixer.cache' \
  --exclude='/.phpunit.result.cache' \
  --exclude='/.git/' \
  --exclude='/.gitignore' \
  --exclude='/.superpowers/' \
  "$REPO/" "$DEST"

echo 'Файлы выложены.'

# --- проверка раскладки ---------------------------------------------------
# Идёт после заливки: до неё сервер показывает прошлую выкладку, и проверка отвечала бы на
# вопросы, которые к текущему состоянию не относятся.
BASE_URL="${CMS_DEPLOY_URL:-}"
if [[ -z "$BASE_URL" ]]; then
  echo 'CMS_DEPLOY_URL не задан — проверку раскладки пропускаем; шаги 2 и 5 чек-листа делают её руками.'
  exit 0
fi
BASE_URL="${BASE_URL%/}"

status_of() {
  curl -sS -o /dev/null --max-time 20 -w '%{http_code}' "${BASE_URL}$1" || echo '000'
}

fail=0

# Открытый сайт и его стили обязаны отвечать: пустой ответ означает не «ещё не установлено»,
# а сломанную раскладку.
for path in / /assets/app.css; do
  code="$(status_of "$path")"
  if [[ "$code" != '200' ]]; then
    echo "Отказ: ${path} отвечает ${code}, ждём 200." >&2
    fail=1
  fi
done

# Ни один служебный файл не должен отдаваться. 403 (закрыт корневым .htaccess, режим root) и
# 404 (каталог лежит выше документ-корня, режим public) — оба ответа правильные; 200 — это
# утекшие исходники или, что хуже, исполненный config.sample.php.
for path in /core/bootstrap.php /config.php /config.sample.php /sql/0001_core_baseline.sql /apps/news/manifest.php /composer.json /docs/deploy-checklist.md; do
  code="$(status_of "$path")"
  if [[ "$code" == '200' ]]; then
    echo "Отказ: ${path} отдаётся наружу (200). Служебные пути обязаны быть закрыты." >&2
    fail=1
  fi
done

if [[ "$fail" -ne 0 ]]; then
  echo 'Проверка раскладки не прошла: перенесите документ-корень на public/ или оставьте' >&2
  echo 'режим root с корневым .htaccess, затем повторите выкладку.' >&2
  exit 1
fi

echo 'Раскладка закрыта как надо. Дальше — шаги 3..6 docs/deploy-checklist.md.'
