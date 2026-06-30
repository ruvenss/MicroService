#!/bin/bash
# Runs once on first container init (empty data dir). Creates a separate test
# database mirroring the app DB name with a _test suffix, and grants the app
# user full access to it. Respects whatever names are set in .env.
set -euo pipefail

TEST_DB="${MYSQL_DATABASE}_test"

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${TEST_DB}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "[init] Ensured test database '${TEST_DB}' and granted '${MYSQL_USER}'."
