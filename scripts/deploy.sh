#!/usr/bin/env bash
# Hostinger deployment for MLC.
#
# Entry points resolve src/bootstrap.php by climbing N dirs from their own
# location, so the app root must be the PARENT of the docroot:
#
#   ~/src  ~/bin  ~/cron  ~/database  ~/vendor  ~/logs  ~/.env   <- internals
#   ~/public_html/                                              <- docroot ONLY
#
# (public_html/…/api/x.php -> dirname x2 -> ~ -> ~/src/bootstrap.php)
#
# If the host lets you set a custom docroot pointing at the repo's
# public_html/, upload the repo anywhere, point there, put .env at the repo
# root — this script is not needed.
#
# Usage: ./scripts/deploy.sh user@host [ssh_port]

set -euo pipefail

HOST="${1:?usage: deploy.sh user@host [port]}"
PORT="${2:-22}"
DOCROOT="~/public_html"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Installing production dependencies"
cd "$ROOT"
composer install --no-dev --optimize-autoloader

echo "==> Uploading internals to ~/"
ssh -p "$PORT" "$HOST" "mkdir -p ~/src ~/bin ~/cron ~/database ~/logs"
rsync -az --delete -e "ssh -p $PORT" src "$HOST:~/"
rsync -az --delete -e "ssh -p $PORT" bin "$HOST:~/"
rsync -az --delete -e "ssh -p $PORT" cron "$HOST:~/"
rsync -az --delete -e "ssh -p $PORT" database "$HOST:~/"
rsync -az --delete -e "ssh -p $PORT" vendor "$HOST:~/"
rsync -az -e "ssh -p $PORT" composer.json "$HOST:~/"
[ -f composer.lock ] && rsync -az -e "ssh -p $PORT" composer.lock "$HOST:~/" || true

echo "==> Uploading docroot to $DOCROOT"
rsync -az --delete -e "ssh -p $PORT" public_html/ "$HOST:$DOCROOT/"

echo "==> Secrets"
if ssh -p "$PORT" "$HOST" "test -f ~/.env"; then
    echo "    ~/.env already present"
else
    echo "    !! no ~/.env on server — upload one:"
    echo "       scp -P $PORT .env $HOST:~/.env"
fi

cat <<'EOF'

Done. Post-deploy on the server:
  # First deploy only — creates all tables (drops everything!):
  php ~/bin/migrate.php init           # or import ~/database/schema.sql in phpMyAdmin

Crontab (engine timers, every minute — Hostinger cron granularity):
  * * * * * php ~/cron/tick.php >/dev/null 2>&1
  # or Hostinger URL/web cron:
  https://<host>/cron-tick.php?cron_token=<CRON_TOKEN>

Smoke check after deploy:
  bash tests/Smoke/smoke.sh https://<your-host>
EOF
