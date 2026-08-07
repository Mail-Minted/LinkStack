#!/bin/bash
# Boot script for the Railway (or any Docker) deployment.
#
# Persistent state lives on a single volume mounted at /data:
#   /data/database.sqlite   — the whole app DB (point DB_DATABASE here)
#   /data/img               — avatar + background uploads (symlinked in
#                             place of assets/img, which the app writes
#                             to via base_path('assets/img'))
# Everything else in the container is disposable and rebuilt from git.
set -e
cd /var/www/html

PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# The INSTALLING marker is tracked in git so a fresh clone would boot into
# installer mode (open /skip route seeds admin@admin.com!). Never in prod.
rm -f INSTALLING INSTALLERLOCK

# EnvEditor and the boot-time key check read a real .env file. Seed a
# minimal one from the injected env so key:generate never rotates APP_KEY.
if [ ! -f .env ]; then
    printf 'APP_KEY=%s\n' "${APP_KEY}" > .env
fi
if [ -z "${APP_KEY}" ]; then
    echo "WARNING: APP_KEY is not set — sessions will reset on every deploy." >&2
fi

DATA=/data
mkdir -p "$DATA/img/background-img"

# Refresh the static assets baked into the image (404.png, logo.svg, …)
# onto the volume, then swap the dir for a symlink. Upload filenames are
# "<id>_<timestamp>.<ext>" so they can never collide with static files.
if [ -d assets/img ] && [ ! -L assets/img ]; then
    cp -a assets/img/. "$DATA/img/"
    rm -rf assets/img
fi
ln -sfn "$DATA/img" assets/img

DB="${DB_DATABASE:-$DATA/database.sqlite}"
FRESH=0
if [ ! -f "$DB" ]; then
    FRESH=1
    touch "$DB"
fi

# Only the writable volume and the paths the app genuinely writes to. This
# used to chown the whole of /var/www/html, handing every .php file --
# vendor/, index.php, the routes -- to the user Apache serves as, so any
# arbitrary-file-write bug escalated straight to persistent RCE. The
# narrower chown after artisan runs (below) covers the rest; this one only
# needs to let migrate create the SQLite file on the volume.
chown -R www-data:www-data "$DATA"

php artisan migrate --force
if [ "$FRESH" = "1" ]; then
    php artisan db:seed --force
fi
php artisan mm:ensure-admin

# artisan ran as root — hand mutable paths back to the web user.
#
# assets/ is in this list because the app WRITES there at render time, not
# just on upload: elements/buttons.blade.php copies favicons into
# assets/favicon/icons/, and editSite writes assets/linkstack/images/.
# Leaving it root-owned makes every bio page containing a link 500 with
# "copy(...): Permission denied" — which is exactly what happened when this
# list was first narrowed. assets/img is a symlink to $DATA/img, which is
# chowned separately above.
chown -R www-data:www-data storage bootstrap/cache config .env assets "$DATA"

# Laravel scheduler (weekly storage:reconcile) without a cron daemon.
(
    while true; do
        php artisan schedule:run >/dev/null 2>&1 || true
        sleep 60
    done
) &

# Railway's image pipeline resurrects files deleted in image layers
# (whiteouts get dropped), so the base image's disabled mpm_event links
# reappear at runtime → "More than one MPM loaded" crash. Deleting them
# here happens on the live container fs, which sticks. mod_php needs
# mpm_prefork, so that one stays.
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf

exec apache2-foreground
