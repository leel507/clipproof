#!/bin/sh
# Läuft im Nextcloud-Image (von tools/appstore.sh aufgerufen): Nextcloud in
# eine beschreibbare Kopie legen und die App unter /app mit Schlüssel und
# Zertifikat aus /k signieren. Ergebnis: /app/appinfo/signature.json
set -e
cp -a /usr/src/nextcloud /tmp/nc
chown -R www-data:www-data /tmp/nc /app
su -s /bin/sh www-data -c "php /tmp/nc/occ integrity:sign-app --privateKey=/k/clipproof.key --certificate=/k/clipproof.crt --path=/app"
