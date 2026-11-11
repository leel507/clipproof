#!/bin/bash
# SPDX-FileCopyrightText: 2026 Schauties Media
# SPDX-License-Identifier: AGPL-3.0-or-later
# Signiertes Paket für den Nextcloud App Store.
#   tools/appstore.sh <ordner mit clipproof.key und clipproof.crt>
# Baut das Paket (tools/paket.sh, mit Prüfungen), signiert den Code
# (appinfo/signature.json, im Nextcloud-Image, nichts wird installiert),
# packt neu und legt die Archiv-Signatur für das Hochladen in den Store ab:
#   dist/clipproof-<version>.tar.gz, .sha256, .sig
# Der private Schlüssel bleibt im angegebenen Ordner; ausgegeben wird nur
# die Signatur, die öffentlich ist.
set -euo pipefail
export PATH="$HOME/.docker/bin:$PATH"
cd "$(dirname "$0")/.."
SCHLUESSEL=$(cd "${1:?Ordner mit clipproof.key und clipproof.crt fehlt}" && pwd)
for d in clipproof.key clipproof.crt; do [ -f "$SCHLUESSEL/$d" ] || { echo "Fehlt: $SCHLUESSEL/$d"; exit 1; }; done
openssl x509 -in "$SCHLUESSEL/clipproof.crt" -noout -subject | grep -q "CN *= *clipproof" || { echo "Zertifikat ist nicht für clipproof ausgestellt"; exit 1; }
NC=nextcloud:34.0.5-apache@sha256:5cd0788a5c7e0374256939dd86fefa34160ae03730cb28246d16939eacfd4c08
VERSION=$(sed -nE 's#.*<version>([^<]+)</version>.*#\1#p' appinfo/info.xml)
DATEI="dist/clipproof-$VERSION.tar.gz"

tools/paket.sh | grep -vE '^  clipproof/'
echo "== Code signieren"
rm -rf dist/sign && mkdir -p dist/sign
tar -xzf "$DATEI" -C dist/sign
docker run --rm -v "$PWD/dist/sign/clipproof:/app" -v "$SCHLUESSEL:/k:ro" -v "$PWD/tools:/t:ro" \
	--entrypoint sh $NC /t/signieren-im-container.sh 2>&1 | grep -v "not installed"
echo "== Neu packen"
COPYFILE_DISABLE=1 tar --no-xattrs -czf "$DATEI" -C dist/sign clipproof
rm -rf dist/sign
( cd dist && shasum -a 256 "clipproof-$VERSION.tar.gz" > "clipproof-$VERSION.tar.gz.sha256" )
openssl dgst -sha512 -sign "$SCHLUESSEL/clipproof.key" "$DATEI" | openssl base64 -A > "$DATEI.sig"
tar -tzf "$DATEI" | grep -q "clipproof/appinfo/signature.json" && echo "  signature.json im Paket"
echo "== Fertig: $DATEI"
cat "$DATEI.sha256"
echo "Signatur für den App Store (dist/clipproof-$VERSION.tar.gz.sig):"
cat "$DATEI.sig"; echo
