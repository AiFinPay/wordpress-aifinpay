#!/usr/bin/env bash
# A private throwaway MySQL for the e2e, separate from any server on this machine: 127.0.0.1:33190, root without a password.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
data="$root/.local/mysql"
if [ ! -d "$data/mysql" ]; then
  mkdir -p "$data"
  mysqld --no-defaults --initialize-insecure --datadir="$data"
fi
exec mysqld --no-defaults --datadir="$data" --port=33190 --bind-address=127.0.0.1 --socket="$root/.local/m.sock" --mysqlx=OFF --pid-file="$root/.local/m.pid"
