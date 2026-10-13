#!/usr/bin/env bash
# Plesk Git "post deployment action": a backend frissítése minden telepítés (git pull + deploy) után.
#
# A Plesk-ben a repository beállításainál (Enable post deployment actions) ezt a sort kell megadni:
#   for d in "$PWD" /httpdocs "$HOME/httpdocs" /var/www/vhosts/vigadomozi.hu/httpdocs; do [ -f "$d/deploy/plesk/post-deploy.sh" ] && exec bash "$d/deploy/plesk/post-deploy.sh"; done
#
# Elvégzi: composer install (zárolt verziókkal), adatbázis-migrációk, Laravel gyorsítótárak újraépítése.
# A frontend (frontend/dist/cinema-booking) előre buildelve van a repóban, ahhoz itt nincs teendő.
# A backend/.env nincs a repóban: a szerveren egyszer kell létrehozni (lásd: deploy/plesk/env.production.example).
set -euo pipefail

# A Plesk jailben, szűkített környezetben futtatja: nincs PATH és alig van külső program
# (dirname, tee, grep, date, mkdir sem biztos), ezért csak bash beépített parancsokat és PHP-t használunk.
# A Plesk csak a hibakimenetet mutatja, ezért a standard kimenetet is oda irányítjuk.
exec 1>&2
trap 'echo "HIBA: a(z) $LINENO. sorban (kilépési kód: $?)"' ERR
export PATH="/usr/local/bin:/usr/bin:/bin:/opt/plesk/php/8.3/bin:${PATH:-}"

script_path="${BASH_SOURCE[0]}"
here="$(cd "${script_path%/*}" && pwd)"
root="$(cd "$here/../.." && pwd)"
backend="$root/backend"
printf '=== telepítés: %(%Y-%m-%d %H:%M:%S)T\n' -1

php_bin=""
for candidate in /opt/plesk/php/8.3/bin/php /opt/plesk/php/8.4/bin/php /opt/plesk/php/8.2/bin/php /usr/bin/php8.3 /usr/bin/php8.2 "$(command -v php || true)"; do
    if [ -n "$candidate" ] && [ -x "$candidate" ]; then
        php_bin="$candidate"
        break
    fi
done
if [ -z "$php_bin" ]; then
    echo "HIBA: nem található PHP"
    exit 1
fi
echo "PHP: $("$php_bin" -r 'echo PHP_VERSION;') ($php_bin)"

cd "$backend"

if [ ! -f .env ]; then
    echo "HIBA: a backend/.env hiányzik. Hozd létre a deploy/plesk/env.production.example alapján."
    exit 1
fi

# könyvtárak létrehozása PHP-val (a mkdir nem biztos, hogy elérhető)
"$php_bin" -r '
foreach (["storage/framework/cache/data","storage/framework/sessions","storage/framework/views","storage/logs","storage/app/public","bootstrap/cache"] as $d) { if (!is_dir($d)) { mkdir($d, 0775, true); } }
'

export COMPOSER_HOME="${COMPOSER_HOME:-$HOME/.composer}"
echo "== composer install"
"$php_bin" -d memory_limit=-1 "$root/composer.phar" install --no-dev --optimize-autoloader --no-interaction --no-progress

env_content=$'\n'"$(<.env)"
if [[ "$env_content" != *$'\nAPP_KEY=base64:'* ]]; then
    echo "== APP_KEY generálása"
    "$php_bin" artisan key:generate --force
fi

echo "== migrációk"
# a jailben nincs MySQL unix socket, ezért itt TCP-n kapcsolódunk (csak erre a parancsra érvényes)
DB_HOST=127.0.0.1 "$php_bin" artisan migrate --force

echo "== gyorsítótárak újraépítése"
"$php_bin" artisan config:clear
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan event:cache
"$php_bin" artisan storage:link 2>/dev/null || true

printf 'KÉSZ: %(%Y-%m-%d %H:%M:%S)T\n' -1
