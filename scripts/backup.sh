#!/usr/bin/env bash
# Резервная копия базы сайта — раздел «Мониторинг и бэкапы» в docs/deploy-checklist.md.
#
# Зачем скрипт, а не строка в чек-листе: «mysqldump должен создаваться регулярно и храниться
# вне сервера» руками не выполняется — вспоминают о нём в день аварии. Скрипт ставится в cron
# на сервере и делает то же самое каждый раз: снимает копию, проверяет её целостность и убирает
# старые, оставляя последние N.
#
# Пароль базы не попадает ни в аргументы, ни в список процессов, ни в отчёт: mysqldump читает
# его из временного файла прав 600 (`--defaults-extra-file`), а файл удаляется по выходу.
# Значения берутся из того же config.php, что и у сайта, — второго набора настроек нет.
#
# Использование:
#   bash scripts/backup.sh [каталог-копий]
#
# Каталог по умолчанию — backup/ в корне проекта. Он обязан лежать вне документ-корня: копия
# в public/ отдавалась бы любому по прямой ссылке. Каталог уже исключён из репозитория
# (`.gitignore`, строка `/backup/`): внутри те же персональные данные, что и в сайте.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$REPO/backup}"

# Сколько копий держать. Значение проверяется числом: опечатка в переменной окружения не должна
# превращаться в «удалить всё» или в «не удалять ничего и забить диск».
KEEP="${CMS_BACKUP_KEEP:-7}"
if ! [[ "$KEEP" =~ ^[0-9]+$ ]]; then
  echo "Отказ: CMS_BACKUP_KEEP ждёт целое число копий, получено: ${KEEP}" >&2
  exit 1
fi

# Копия внутри документ-корня — это утечка всей базы по прямой ссылке: public/ отдаётся
# веб-сервером как есть, и первый же обходчик карты сайтов скачает дамп вместе с почтами
# и хешами паролей.
#
# Путь сравнивается абсолютным, а не строкой из аргумента: проверка по образцу `*/public/*`
# пропускала относительный `public/backups` (в нём нет слэша перед словом public), и копия
# ложилась ровно туда, куда нельзя. Каталог создаётся заранее и берётся через `cd … && pwd` —
# так `..` и относительные формы схлопываются до сравнения, а не после.
mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"

case "$OUT_DIR/" in
  "$REPO/public/"*)
    rmdir --ignore-fail-on-non-empty "$OUT_DIR" 2>/dev/null || true
    echo "Отказ: каталог копий внутри документ-корня (${OUT_DIR})." >&2
    echo 'Копия обязана лежать вне public/ — иначе она отдаётся наружу по прямой ссылке.' >&2
    exit 1
    ;;
esac

# Инструмент вынесен в переменную: на хостинге mysqldump иногда лежит не в PATH, и вписать
# полный путь в копию скрипта дешевле, чем искать причину отказа. Имени в PATH достаточно
# в обычном случае.
MYSQLDUMP="${CMS_BACKUP_MYSQLDUMP:-mysqldump}"
if ! command -v "$MYSQLDUMP" >/dev/null 2>&1; then
  echo "Отказ: ${MYSQLDUMP} не найден. Копию снимать нечем." >&2
  echo 'Укажите путь: CMS_BACKUP_MYSQLDUMP=/usr/bin/mysqldump bash scripts/backup.sh' >&2
  exit 1
fi

CONFIG_FILE="${CMS_CONFIG_FILE:-$REPO/config.php}"
if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "Отказ: нет файла настроек ${CONFIG_FILE} — читать параметры базы неоткуда." >&2
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo 'Отказ: php не найден. Настройки базы читает ядро сайта, а не копия его значений в скрипте.' >&2
  exit 1
fi

# Временный файл настроек для mysqldump: права 600 с момента создания, удаление — по любому
# выходу, включая отказ. `mktemp` создаёт файл с правами 600 сам, `chmod` ниже — страховка
# от чужого umask в окружении cron.
defaults="$(mktemp "${TMPDIR:-/tmp}/cms-backup.XXXXXXXX")"
cleanup() {
  rm -f -- "$defaults"
}
trap cleanup EXIT

# Настройки читает ядро сайта: второй разбор config.php в bash разошёлся бы с ним на первой же
# правке формата. Наружу печатается только имя базы — оно не секрет и нужно аргументом.
db_name="$(CMS_BACKUP_REPO="$REPO" CMS_BACKUP_DEFAULTS="$defaults" CMS_BACKUP_CONFIG="$CONFIG_FILE" php <<'PHP'
<?php

declare(strict_types=1);

$repo = (string) getenv('CMS_BACKUP_REPO');
$target = (string) getenv('CMS_BACKUP_DEFAULTS');
$configFile = (string) getenv('CMS_BACKUP_CONFIG');

if (getenv('CMS_CONFIG_FILE') === false || getenv('CMS_CONFIG_FILE') === '') {
    putenv('CMS_CONFIG_FILE=' . $configFile);
}

// Ошибки ядра печатают страницу для браузера и зовут exit сами: в cron нужен короткий отказ,
// а не разметка в почте администратора.
require $repo . '/core/config-loader.php';

$config = config();

// Значения в файле `[client]` экранируются по правилам my.cnf: обратный слэш и двойная кавычка.
$quote = static function (mixed $value): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';
};

$lines = ['[client]', 'host=' . $quote($config['DB_HOST']), 'port=' . $quote($config['DB_PORT'])];

// Пользователь и пароль пишутся только заполненными: пустой `password=""` в my.cnf означает
// «пароль не задан», и mysqldump отказал бы в доступе при пустом пароле в настройках.
foreach (['user' => 'DB_USER', 'password' => 'DB_PASS'] as $key => $setting) {
    if ((string) $config[$setting] !== '') {
        $lines[] = $key . '=' . $quote($config[$setting]);
    }
}

// Права 600 выставляются через umask до записи: файл с паролем не должен существовать даже
// мгновение доступным другим пользователям сервера.
$previous = umask(0077);
$written = file_put_contents($target, implode("\n", $lines) . "\n");
umask($previous);

if ($written === false) {
    fwrite(STDERR, "Отказ: не удалось записать временный файл настроек.\n");
    exit(1);
}

@chmod($target, 0600);

echo (string) $config['DB_NAME'];
PHP
)"

if [[ -z "$db_name" ]]; then
  echo 'Отказ: имя базы не прочиталось из настроек — копию снимать не с чего.' >&2
  exit 1
fi

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$OUT_DIR/cms-${stamp}.sql"
partial="$OUT_DIR/.cms-${stamp}.sql.part"

echo "Копия базы ${db_name} → ${target}"

# --single-transaction: согласованный снимок InnoDB без блокировки таблиц, сайт продолжает
# работать. --no-tablespaces: на MySQL 5.7.6+ без него нужны права PROCESS, которых на shared
# -хостинге обычно нет. Процедур и событий в проекте нет, поэтому --routines и --events не
# запрашиваются: лишнее право PROCESS/EVENT — причина отказа на чужом хостинге.
if ! "$MYSQLDUMP" \
  --defaults-extra-file="$defaults" \
  --single-transaction \
  --quick \
  --skip-lock-tables \
  --no-tablespaces \
  --default-character-set=utf8mb4 \
  -- "$db_name" > "$partial"; then
  rm -f -- "$partial"
  echo 'Отказ: mysqldump завершился с ошибкой. Копия не создана, прежние копии не тронуты.' >&2
  exit 1
fi

# Проверка целостности: mysqldump пишет строку-итог последней строкой файла. Её отсутствие
# означает оборванную копию — обрыв сети, кончившееся место, снятый процесс. Такой файл опаснее
# отсутствующего: он выглядит как бэкап и обнаруживается только при восстановлении.
if ! grep -q '^-- Dump completed' "$partial"; then
  rm -f -- "$partial"
  echo 'Отказ: копия оборвана (нет строки «Dump completed»). Файл удалён, прежние копии не тронуты.' >&2
  exit 1
fi

tables="$(grep -c '^CREATE TABLE' "$partial" || true)"
if [[ "$tables" -eq 0 ]]; then
  rm -f -- "$partial"
  echo 'Отказ: в копии нет ни одной таблицы — это копия пустой базы или чужой базы.' >&2
  exit 1
fi

# Права 600 и до переноса, и после: копия содержит те же данные, что и база.
chmod 0600 "$partial"
mv -f -- "$partial" "$target"

echo "Готово: таблиц ${tables}, размер $(wc -c < "$target" | tr -d ' ') байт."

# Ротация: остаются последние KEEP копий. Ноль означает «не удалять ничего» — так на сервере,
# где старые копии убирает не скрипт, а отдельная выгрузка в хранилище.
if [[ "$KEEP" -gt 0 ]]; then
  removed=0
  while IFS= read -r old; do
    [[ -z "$old" ]] && continue
    rm -f -- "$old"
    removed=$((removed + 1))
  done < <(ls -1t "$OUT_DIR"/cms-*.sql 2>/dev/null | tail -n "+$((KEEP + 1))")

  if [[ "$removed" -gt 0 ]]; then
    echo "Удалено старых копий: ${removed} (оставлено ${KEEP})."
  fi
fi

echo 'Напоминание: копия на том же сервере — не резерв. Выгрузите её туда, где сайт её не достанет.'
