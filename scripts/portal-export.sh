#!/usr/bin/env bash
# Выгружает дамп портала PostgreSQL в JSON-строки для scripts/import-portal.php.
#
# Зачем так: в PHP-контейнере CMS расширения pgsql нет и не будет (рантайм-зависимостей
# у проекта нет, а перенос — одноразовая операция на машине разработчика, а не на хостинге).
# Поэтому дамп разворачивается во временную базу postgres:17, а дальше каждая таблица
# выгружается одной командой psql в формате «одна строка JSON на запись».
#
# Использование:
#   bash scripts/portal-export.sh <дамп> [каталог-выгрузки]
#
# Каталог по умолчанию — dump/. В репозиторий он не попадает: внутри персональные данные
# (почты и хеши паролей), поэтому строка `dump/` лежит в .gitignore.
set -euo pipefail

dump_file="${1:-}"
out_dir="${2:-dump}"

if [[ -z "$dump_file" ]]; then
  echo 'Отказ: укажите путь к дампу PostgreSQL (custom-формат pg_dump).' >&2
  echo 'Пример: bash scripts/portal-export.sh ../archives/backups/portal-before-cms-core.dump dump' >&2
  exit 1
fi

if [[ ! -f "$dump_file" ]]; then
  echo "Отказ: файл дампа не найден: $dump_file" >&2
  exit 1
fi

container='cms-portal-export'
database='portal'

cleanup() {
  docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo 'Поднимаю временную базу PostgreSQL…'
docker rm -f "$container" >/dev/null 2>&1 || true
docker run -d --name "$container" -e POSTGRES_PASSWORD=temporary -e POSTGRES_DB="$database" postgres:17 >/dev/null

for _ in $(seq 1 60); do
  if docker exec "$container" pg_isready -U postgres -d "$database" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

absolute_dump="$(cd "$(dirname "$dump_file")" && pwd)/$(basename "$dump_file")"
docker cp "$absolute_dump" "$container:/tmp/portal.dump" >/dev/null

echo 'Разворачиваю дамп…'
docker exec "$container" pg_restore -U postgres -d "$database" --no-owner --no-privileges /tmp/portal.dump

mkdir -p "$out_dir"

# Список таблиц берётся из самой базы, а не из памяти: пропущенная таблица означала бы
# молча не перенесённые данные.
tables="$(docker exec "$container" psql -U postgres -d "$database" -t -A -c \
  "select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name")"

while IFS= read -r table; do
  [[ -z "$table" ]] && continue
  echo "  $table"
  docker exec "$container" psql -U postgres -d "$database" -t -A -c \
    "select row_to_json(t) from (select * from \"$table\") t" > "$out_dir/$table.jsonl"
done <<< "$tables"

echo "Готово: выгрузка в $out_dir"
echo 'Перенос: php scripts/import-portal.php --dump='"$out_dir"' (без --write — только проверка)'
