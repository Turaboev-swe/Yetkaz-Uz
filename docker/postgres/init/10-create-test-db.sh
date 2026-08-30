#!/bin/bash
# Postgres konteyneri birinchi marta ko'tarilganda ishga tushadi.
# Test uchun alohida baza yaratadi (phpunit.xml => DB_DATABASE=yetkaz_test).
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    SELECT 'CREATE DATABASE yetkaz_test OWNER ${POSTGRES_USER}'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'yetkaz_test')\gexec
EOSQL

# PostGIS / pg_trgm / unaccent kengaytmalarini ikkala bazada ham oldindan yoqamiz
# (migratsiyalar ham buni qiladi, lekin bu tezroq va superuser huquqi shu yerda bor).
for db in "$POSTGRES_DB" yetkaz_test; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<-EOSQL
        CREATE EXTENSION IF NOT EXISTS postgis;
        CREATE EXTENSION IF NOT EXISTS pg_trgm;
        CREATE EXTENSION IF NOT EXISTS unaccent;
EOSQL
done
