#!/usr/bin/env bash
# Prova o procedimento de backup/restauração (T041) num PostgreSQL de verdade:
# migra, semeia dados, gera o dump no formato de produção (pg_dump -Fc),
# restaura num banco novo e compara contagem por tabela e checksum das
# tabelas de dados. Sai com erro se qualquer diferença aparecer.
#
#   bash scripts/verify-restore.sh
#
# Usa o docker-compose.test.yml (banco descartável); não toca em produção.
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

C="docker compose -f docker-compose.test.yml"
PSQL="$C exec -T postgres psql -U timersmit -v ON_ERROR_STOP=1 -At"
trap '$C down -v >/dev/null 2>&1' EXIT

echo "==> Subindo PostgreSQL descartável"
$C up -d --wait postgres

echo "==> Migrando e semeando o banco de origem"
$C run --rm tests sh -c "composer install --no-interaction --no-progress --prefer-dist -q \
  && php artisan migrate --force --quiet \
  && php scripts/seed-restore-check.php"

SEEDED=$($PSQL -d timersmit_test -c "select count(*) from time_entries")
if [ "${SEEDED:-0}" -lt 1000 ]; then
  echo "FALHA: a origem não foi semeada (time_entries = ${SEEDED:-0}); a comparação não provaria nada" >&2
  exit 1
fi
echo "origem com $SEEDED lançamentos"

echo "==> Gerando o backup (pg_dump -Fc)"
$C exec -T postgres pg_dump -U timersmit -Fc -f /tmp/timersmit.dump timersmit_test
$C exec -T postgres ls -l /tmp/timersmit.dump

echo "==> Restaurando num banco novo (pg_restore)"
$C exec -T postgres createdb -U timersmit timersmit_restored
START=$(date +%s)
$C exec -T postgres pg_restore -U timersmit -d timersmit_restored --no-owner --exit-on-error /tmp/timersmit.dump
echo "restauração levou $(( $(date +%s) - START )) s"

snapshot() {
  $PSQL -d "$1" <<'SQL'
select table_name || ' = ' || (xpath('/row/c/text()', query_to_xml(
  format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text
from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name;
select 'checksum time_entries = ' || coalesce(md5(string_agg(t::text, '|' order by id)), '-') from time_entries t;
select 'checksum members = ' || coalesce(md5(string_agg(t::text, '|' order by id)), '-') from members t;
select 'checksum weekly_submissions = ' || coalesce(md5(string_agg(t::text, '|' order by id)), '-') from weekly_submissions t;
select 'checksum migrations = ' || md5(string_agg(migration, '|' order by id)) from migrations;
SQL
}

echo "==> Comparando origem e restauração"
snapshot timersmit_test > /tmp/origem.txt
snapshot timersmit_restored > /tmp/restaurado.txt
if diff /tmp/origem.txt /tmp/restaurado.txt; then
  echo "OK: contagens e checksums idênticos"
  grep -E "time_entries|tenants|members" /tmp/restaurado.txt
else
  echo "FALHA: origem e restauração diferem" >&2
  exit 1
fi

echo "==> A aplicação enxerga o banco restaurado sem migrações pendentes?"
STATUS=$($C run --rm -e DB_DATABASE=timersmit_restored tests php artisan migrate:status)
echo "$STATUS" | tail -3
if echo "$STATUS" | grep -q "Pending"; then echo "FALHA: migrações pendentes" >&2; exit 1; fi
echo "OK"
