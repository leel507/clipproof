#!/bin/sh
# SPDX-FileCopyrightText: 2026 Schauties Media
# SPDX-License-Identifier: AGPL-3.0-or-later
# Probe für Freigabe, Benachrichtigung und Zugriffsrechte in einem lokalen
# Nextcloud-Container (läuft im Container, Anmeldung
# über die Umgebungsvariablen, nichts Geheimes wird ausgegeben).
#   docker exec -u www-data <nextcloud-container> sh /tmp/probe-zugang.sh <review-id>
set -eu
ID=$1
B="http://localhost/ocs/v2.php/apps/clipproof/api/v1"
A="$NEXTCLOUD_ADMIN_USER:$NEXTCLOUD_ADMIN_PASSWORD"
H="OCS-APIRequest: true"
feld() { php -r '$d=json_decode(stream_get_contents(STDIN),true)["ocs"]["data"]; echo json_encode(array_intersect_key($d, array_flip(explode(",", $argv[1]))), JSON_UNESCAPED_UNICODE);' "$1"; }
echo "Benachrichtigungen von clipproof: $(curl -s -u "$A" -H "$H" 'http://localhost/ocs/v2.php/apps/notifications/api/v2/notifications?format=json' | php -r '$d=json_decode(stream_get_contents(STDIN),true)["ocs"]["data"]; foreach ($d as $n) if ($n["app"]==="clipproof") echo $n["subject"], " | ", $n["message"], "\n";')"
echo "Freigeben:    $(curl -s -u "$A" -H "$H" -X PUT -d freigegeben=1 "$B/reviews/$ID/freigabe?format=json" | feld freigegebenAm,ablaufAm,archiviert)"
echo "Anrede du:    $(curl -s -u "$A" -H "$H" -X PUT -d anrede=du "$B/reviews/$ID/anrede?format=json" | feld anrede)"
echo "Zurücknehmen: $(curl -s -u "$A" -H "$H" -X PUT -d freigegeben=0 "$B/reviews/$ID/freigabe?format=json" | feld freigegebenAm,ablaufAm)"
echo "Anrede falsch: $(curl -s -o /dev/null -w '%{http_code}' -u "$A" -H "$H" -X PUT -d anrede=ihr "$B/reviews/$ID/anrede?format=json")"
