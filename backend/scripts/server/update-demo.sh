#!/usr/bin/env bash
# Updates the hosted demo (/var/www/divawaste-textile, port 8088) to the latest main, after a backup.
#
#   cd /var/www/divawaste-textile && git fetch origin \
#     && git show origin/main:backend/scripts/server/update-demo.sh > /tmp/update-demo.sh && bash /tmp/update-demo.sh
#
# Run as root. Before it, from the PC (optional, each is used when present):
#   - the built screens in /var/www/divawaste-textile/web-new   (scp -r dist\frontend\browser root@SERVER:/var/www/divawaste-textile/web-new)
#   - the Pantone files in /root/pantones                         (scp -r ...\pantones-master root@SERVER:/root/pantones)
#
# Steps: 1 backup (database, .env, uploaded files, screens, nginx, commit) → 2 code (git pull, composer, migrate)
# → 3 settings (.env: automatic approval, DAVI) → 4 data (ANGed lists, Pantone) → 5 screens → 6 nginx (/guides, longer
# timeout for the assistant) → 7 restart → 8 checks. It stops at the first error; the backup folder is printed first.
set -euo pipefail

APP=/var/www/divawaste-textile
API=$APP/backend
SITE=/etc/nginx/sites-available/divawaste-textile
WORKER=divawaste-textile-worker
STAMP=$(date +%Y-%m-%d-%H%M)
BACKUP=/root/backups/divawaste-textile/$STAMP
say() { printf '\n\033[1;32m== %s\033[0m\n' "$*"; }
setenv() { if grep -q "^$1=" "$API/.env"; then sed -i "s|^$1=.*|$1=$2|" "$API/.env"; else echo "$1=$2" >> "$API/.env"; fi; }
envget() { grep -E "^$1=" "$API/.env" | tail -1 | cut -d= -f2- | tr -d '"'; }

[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
cd "$APP"

say "1/8 Backup in $BACKUP"
mkdir -p "$BACKUP"
chmod 700 /root/backups
git rev-parse HEAD > "$BACKUP/commit.txt"
git status --porcelain > "$BACKUP/git-status.txt" || true
DB=$(envget DB_DATABASE)
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 "$DB" | gzip > "$BACKUP/$DB.sql.gz"
cp "$API/.env" "$BACKUP/backend.env"
tar -czf "$BACKUP/storage-app.tar.gz" -C "$API/storage" app
[ -d "$APP/web" ] && tar -czf "$BACKUP/web.tar.gz" -C "$APP" web
cp "$SITE" "$BACKUP/nginx-site.conf"
ls -lh "$BACKUP"

say "2/8 Code: git pull, composer, migrations"
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Files of the repository were changed on the server (list in $BACKUP/git-status.txt). Nothing was updated."; exit 1
fi
git pull --ff-only origin main
cd "$API"
# Lot labels draw their QR code with GD; the application requires it (composer refuses to install without it).
php -m | grep -qi '^gd$' || { apt-get install -y php8.3-gd >/dev/null && systemctl reload php8.3-fpm; }
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan migrate --force

say "3/8 Settings (.env)"
# Testing: a registration's email counts as confirmed at once (no link to click; invitations give their link back)
# and the account is approved at the same time. false on both brings back the email link and Diva Software's review.
setenv AUTO_VERIFY_EMAILS true
setenv AUTO_APPROVE_SIGNUPS true
# The assistant runs on the PC and reaches this server through an SSH tunnel (davi/serve-server.ps1) on 127.0.0.1:8040.
setenv DAVI_URL http://127.0.0.1:8040
if [ -z "$(envget DAVI_KEY)" ]; then
  setenv DAVI_KEY "$(openssl rand -hex 32)"
  NEW_DAVI_KEY=1
fi
echo "MAIL_MAILER=$(envget MAIL_MAILER)  MAIL_HOST=$(envget MAIL_HOST)  MAIL_FROM_ADDRESS=$(envget MAIL_FROM_ADDRESS)"

say "4/8 Data: ANGed lists, Pantone"
php artisan registry:import-anged
if [ -d /root/pantones ]; then
  DIR=/root/pantones; [ -d /root/pantones/pantones-master ] && DIR=/root/pantones/pantones-master
  php artisan colors:import "$DIR" --source=brettapeters-pantones
else
  echo "No /root/pantones folder: the colour catalogue is left as it is."
fi

say "5/8 Screens"
if [ -d "$APP/web-new" ] && [ -f "$APP/web-new/index.html" ]; then
  rm -rf "$APP/web.previous"
  [ -d "$APP/web" ] && mv "$APP/web" "$APP/web.previous"
  mv "$APP/web-new" "$APP/web"
  echo "New screens in place (the previous ones in web.previous and in the backup)."
else
  echo "No $APP/web-new/index.html: the screens are left as they are."
fi

say "6/8 nginx: /guides and the assistant's timeout"
if ! grep -q "location ^~ /guides/" "$SITE"; then
  sed -i "0,/client_max_body_size[^;]*;/s||&\n\n    # The user guides (PDF), straight from the repository.\n    location ^~ /guides/ {\n        alias $APP/docs/guides/;\n        index index.html;\n    }\n    location = /guides { return 301 /guides/; }|" "$SITE"
fi
if ! grep -q "fastcgi_read_timeout" "$SITE"; then
  # An assistant turn may take up to 4 minutes (the API waits 240 s for it); nginx stops at 60 s by default.
  sed -i "0,/fastcgi_pass unix:\/run\/php\/php8.3-fpm.sock;/s||&\n        fastcgi_read_timeout 300;|" "$SITE"
fi
if nginx -t; then
  systemctl reload nginx
else
  echo "nginx refused the new configuration: the previous one is put back."; cp "$BACKUP/nginx-site.conf" "$SITE"; nginx -t && systemctl reload nginx; exit 1
fi

say "7/8 Caches, workers, scheduler"
cd "$API"
php artisan optimize:clear >/dev/null
php artisan config:cache
php artisan route:cache
php artisan event:cache
chown -R www-data:www-data "$API/storage" "$API/bootstrap/cache"
systemctl reload php8.3-fpm
supervisorctl restart "$WORKER" || echo "Worker $WORKER not found in supervisor: emails stay queued until a worker runs."
if ! crontab -u www-data -l 2>/dev/null | grep -q "$API/artisan schedule:run"; then
  (crontab -u www-data -l 2>/dev/null; echo "* * * * * cd $API && php artisan schedule:run >> /dev/null 2>&1") | crontab -u www-data -
  echo "Scheduler added to www-data's crontab."
fi

say "8/8 Checks"
PORT=$(grep -oE "listen +[0-9]+" "$SITE" | head -1 | grep -oE "[0-9]+")
printf 'API      /up       : '; curl -s -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:$PORT/up"
printf 'Screens  /         : '; curl -s -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:$PORT/"
printf 'Guides   /guides/  : '; curl -s -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:$PORT/guides/"
printf 'ANGed entries      : '; php artisan tinker --execute='echo DB::table("regulatory_operators")->count();'; echo
printf 'Pantone colours    : '; php artisan tinker --execute='echo App\Modules\Catalog\Support\Colors::catalogue()->count();'; echo
git -C "$APP" log --oneline -1

echo
echo "Backup: $BACKUP"
echo "To go back: see backend/docs/OPERATIONS.md, section \"Restore the demo from a backup\"."
if [ "${NEW_DAVI_KEY:-0}" = 1 ]; then
  echo
  echo "A new DAVI_KEY was created. Give it to serve-server.ps1 on the PC the first time it asks:"
  echo "  $(envget DAVI_KEY)"
fi
echo "Test email:  cd $API && php artisan mail:test your.address@example.com"
