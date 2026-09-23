#!/bin/sh
# Creates the database used by the test suite, next to the development one.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE DATABASE ${POSTGRES_DB}_testing OWNER "$POSTGRES_USER";
SQL
