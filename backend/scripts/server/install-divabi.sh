#!/usr/bin/env bash
# Installs or updates DivaBI, the engine of custom views ("Vues personnalisées"), beside the hosted demo.
#
# From the PC, the DivaBI code as a git bundle (DivaBI has no remote repository):
#   git -C C:\Projects\divabi bundle create C:\Users\DELL\Downloads\divabi.bundle main
#   scp C:\Users\DELL\Downloads\divabi.bundle root@SERVER:/root/divabi.bundle
#
# On the server, as root (update-demo.sh also runs it on every update once DivaBI is installed):
#   bash /var/www/divawaste-textile/backend/scripts/server/install-divabi.sh
#
# Steps: 1 checks (root, Python 3.11+) → 2 code (/opt/divabi from /root/divabi.bundle, when given) → 3 Python
# environment → 4 shared secret (DIVABI_KEY in DivaWaste's .env) → 5 package (tables custom views may read, from the
# live schema) → 6 read-only database account (SELECT on those tables only) → 7 DivaBI's .env → 8 checks against the
# live database (isolation, guards) → 9 service → 10 nginx /divabi/ → 11 DivaWaste caches → 12 final checks.
# It stops at the first error: the checks of step 8 must pass before the service starts or nginx routes to it.
set -euo pipefail

APP=/var/www/divawaste-textile
API=$APP/backend
SITE=/etc/nginx/sites-available/divawaste-textile
CODE=/opt/divabi
DATA=/var/lib/divabi
BUNDLE=/root/divabi.bundle
PORT=8050
DB_USER=divabi_ro
say() { printf '\n\033[1;32m== %s\033[0m\n' "$*"; }
setenv() { if grep -q "^$1=" "$API/.env"; then sed -i "s|^$1=.*|$1=$2|" "$API/.env"; else echo "$1=$2" >> "$API/.env"; fi; }
# Empty when the variable is not there (never an error: the script stops on errors).
envget() { { grep -E "^$1=" "$API/.env" || true; } | tail -1 | cut -d= -f2- | tr -d '"'; }

say "1/12 Checks"
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
[ -f "$API/.env" ] || { echo "No $API/.env: install the demo first."; exit 1; }
PY=""
for candidate in python3.13 python3.12 python3.11 python3; do
  if command -v "$candidate" >/dev/null && "$candidate" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)'; then PY=$(command -v "$candidate"); break; fi
done
if [ -z "$PY" ]; then
  echo "Python 3.11 or later is needed: installing python3.11."
  apt-get install -y python3.11 python3.11-venv >/dev/null
  PY=$(command -v python3.11)
fi
"$PY" -m venv --help >/dev/null 2>&1 || apt-get install -y "$(basename "$PY")-venv" >/dev/null
echo "Python: $PY ($("$PY" --version))"

say "2/12 Code in $CODE"
if [ -f "$BUNDLE" ]; then
  git bundle verify "$BUNDLE" >/dev/null
  if [ -d "$CODE/.git" ]; then
    if [ -n "$(git -C "$CODE" status --porcelain --untracked-files=no)" ]; then
      echo "Files of $CODE were changed on the server: nothing was updated."; exit 1
    fi
    git -C "$CODE" fetch -q "$BUNDLE" main
    git -C "$CODE" merge -q --ff-only FETCH_HEAD
  else
    git clone -q --branch main "$BUNDLE" "$CODE"
  fi
  mv "$BUNDLE" "$BUNDLE.installed"
elif [ ! -d "$CODE/.git" ]; then
  echo "No $BUNDLE and no $CODE: copy the bundle first (see the top of this script)."; exit 1
else
  echo "No new bundle: the code stays as it is."
fi
git -C "$CODE" log --oneline -1
id divabi >/dev/null 2>&1 || useradd --system --home-dir "$DATA" --shell /usr/sbin/nologin divabi
mkdir -p "$DATA/applications/divawaste"
chown -R divabi:divabi "$DATA"
chmod 750 "$DATA"

say "3/12 Python environment"
[ -x "$CODE/.venv/bin/python" ] || "$PY" -m venv "$CODE/.venv"
"$CODE/.venv/bin/pip" install -q --upgrade pip
"$CODE/.venv/bin/pip" install -q -r "$CODE/requirements.txt"

say "4/12 Shared secret (DIVABI_KEY in DivaWaste's .env)"
setenv DIVABI_URL /divabi
if [ "$(envget DIVABI_KEY | wc -c)" -lt 33 ]; then
  setenv DIVABI_KEY "$(openssl rand -hex 32)"
  echo "A new DIVABI_KEY was created."
else
  echo "DIVABI_KEY kept."
fi
SECRET=$(envget DIVABI_KEY)

say "5/12 Package: what custom views may read, from the live schema"
cd "$API"
php artisan config:clear >/dev/null
PACKAGE=$DATA/applications/divawaste/package.json
php artisan divabi:package "$PACKAGE"
chown divabi:divabi "$PACKAGE"
TABLES=$("$CODE/.venv/bin/python" -c 'import json, sys; print(" ".join(sorted(t for t, e in json.load(open(sys.argv[1]))["entities"].items() if e.get("access") == "allow")))' "$PACKAGE")
echo "$(echo "$TABLES" | wc -w) readable tables."

say "6/12 Read-only database account $DB_USER"
DB=$(envget DB_DATABASE)
DB_PORT=$(envget DB_PORT); DB_PORT=${DB_PORT:-3306}
case "$(envget DB_HOST)" in 127.0.0.1|localhost|"") ;; *) echo "DB_HOST is $(envget DB_HOST): this script only sets up a database on this server."; exit 1 ;; esac
VERSION=$(mysql -N -e "SELECT VERSION()")
case "$VERSION" in
  *[Mm]aria[Dd][Bb]*) FLAVOR=mariadb ;;
  *) FLAVOR=mysql
     # MySQL 8 signs accounts in with caching_sha2_password: the Python driver needs this to answer it.
     "$CODE/.venv/bin/pip" install -q cryptography ;;
esac
# The password stays the same from one run to the next (it is kept in DivaBI's .env, readable by root and DivaBI only).
PASSWORD=""
[ -f "$CODE/.env" ] && PASSWORD=$(sed -nE "s|^DIVABI_DIVAWASTE_DSN=mysql://$DB_USER:([0-9a-f]+)@.*|\1|p" "$CODE/.env")
[ -n "$PASSWORD" ] || PASSWORD=$(openssl rand -hex 24)
{
  for host in 127.0.0.1 localhost; do
    echo "CREATE USER IF NOT EXISTS '$DB_USER'@'$host' IDENTIFIED BY '$PASSWORD';"
    echo "ALTER USER '$DB_USER'@'$host' IDENTIFIED BY '$PASSWORD';"
    # Only the tables the package opens: the users, their tokens and the audit trail stay out of reach of the account itself.
    echo "REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$DB_USER'@'$host';"
    for table in $TABLES; do echo "GRANT SELECT ON \`$DB\`.\`$table\` TO '$DB_USER'@'$host';"; done
  done
  echo "FLUSH PRIVILEGES;"
} | mysql
echo "$DB_USER: SELECT on $(echo "$TABLES" | wc -w) tables of $DB ($FLAVOR)."

say "7/12 DivaBI settings ($CODE/.env)"
umask 077
cat > "$CODE/.env" <<EOF
# Written by install-divabi.sh: run it again rather than editing this file.
DIVABI_DIVAWASTE_DSN=mysql://$DB_USER:$PASSWORD@127.0.0.1:$DB_PORT/$DB?flavor=$FLAVOR
DIVABI_DIVAWASTE_SECRET=$SECRET
DIVABI_HOST=127.0.0.1
DIVABI_PORT=$PORT
DIVABI_BASE_PATH=/divabi
DIVABI_APPS_DIR=$DATA/applications
DIVABI_METADATA_DB=$DATA/divabi.sqlite3
EOF
umask 022
chown root:divabi "$CODE/.env"
chmod 640 "$CODE/.env"

say "8/12 Checks against the live database (read-only)"
cd "$CODE"
runuser -u divabi -- "$CODE/.venv/bin/python" -m divabi migrate
runuser -u divabi -- "$CODE/.venv/bin/python" -m divabi check divawaste
if ! runuser -u divabi -- "$CODE/.venv/bin/python" -m divabi verify divawaste; then
  echo "DivaBI's checks failed (above): the service is not started and nginx does not route to it."; exit 1
fi

say "9/12 Service divabi"
cat > /etc/systemd/system/divabi.service <<EOF
[Unit]
Description=DivaBI (custom views of DivaWaste)
After=network.target mariadb.service mysql.service

[Service]
User=divabi
Group=divabi
WorkingDirectory=$CODE
ExecStart=$CODE/.venv/bin/python -m divabi serve
Restart=on-failure
RestartSec=3
MemoryMax=600M
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
PrivateTmp=true
ReadWritePaths=$DATA

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable -q divabi
systemctl restart divabi
for _ in $(seq 1 20); do curl -fs "http://127.0.0.1:$PORT/divabi/health" >/dev/null && break; sleep 1; done
curl -fs "http://127.0.0.1:$PORT/divabi/health" >/dev/null || { journalctl -u divabi -n 30 --no-pager; echo "DivaBI did not start."; exit 1; }

say "10/12 nginx: /divabi/ to DivaBI"
if ! grep -q "location ^~ /divabi/" "$SITE"; then
  cp "$SITE" "/root/nginx-site.before-divabi.conf"
  sed -i "0,/client_max_body_size[^;]*;/s||&\n\n    # Custom views: DivaBI, a separate service (install-divabi.sh), on the same origin as the application.\n    location ^~ /divabi/ {\n        proxy_pass http://127.0.0.1:$PORT;\n        proxy_set_header Host \$host;\n        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n        proxy_set_header X-Forwarded-Proto \$scheme;\n        proxy_read_timeout 60s;\n    }|" "$SITE"
  if nginx -t; then
    systemctl reload nginx
  else
    echo "nginx refused the new configuration: the previous one is put back."; cp /root/nginx-site.before-divabi.conf "$SITE"; nginx -t && systemctl reload nginx; exit 1
  fi
else
  echo "Already there."
fi

say "11/12 DivaWaste caches (the new key)"
cd "$API"
php artisan config:cache >/dev/null
systemctl reload php8.3-fpm

say "12/12 Final checks"
SITE_PORT=$(grep -oE "listen +[0-9]+" "$SITE" | head -1 | grep -oE "[0-9]+")
printf 'DivaBI  /divabi/health (direct)      : '; curl -s -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:$PORT/divabi/health"
printf 'DivaBI  /divabi/static/divabi.js     : '; curl -s -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:$SITE_PORT/divabi/static/divabi.js"
printf 'API     /api/v1/custom-views/session : '; curl -s -o /dev/null -w '%{http_code} (401 = asks for a session, as it should)\n' -H 'Accept: application/json' "http://127.0.0.1:$SITE_PORT/api/v1/custom-views/session"
printf 'Key configured on the API side       : '; php artisan tinker --execute='echo App\Modules\CustomViews\Support\DivaBiToken::configured() ? "yes" : "NO";'; echo
echo
echo "Done. Custom views: menu « Vues personnalisées » (client administrators and environment managers build them;"
echo "management viewers open them). Logs: journalctl -u divabi -f"
