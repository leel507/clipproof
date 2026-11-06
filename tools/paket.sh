#!/bin/bash
# SPDX-FileCopyrightText: 2026 Schauties Media
# SPDX-License-Identifier: AGPL-3.0-or-later
# Schnürt das Paket für den Nextcloud App Store und eigene Installationen.
#   tools/paket.sh
# Ergebnis: dist/clipproof-<version>.tar.gz und .sha256
# Enthält nur, was die App zur Laufzeit braucht: appinfo, lib, templates,
# js (ohne Quellkarten), fonts (mit Lizenzen), img, l10n, Lizenztexte. Keine Tests, Werkzeuge, Quellen, Abhängigkeiten.
# Prüft vorher Tests und PHP-8.3-Verträglichkeit; bricht bei Fehlern ab.
set -euo pipefail
export PATH="$HOME/.docker/bin:$PATH"
cd "$(dirname "$0")/.."

PHP=php:8.3-cli-alpine@sha256:a7e67cd11bc8b6d8c893bf36d69a92f56b59c2d8d70015af9e3eff03e199333a
NODE=node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1
VERSION=$(sed -nE 's#.*<version>([^<]+)</version>.*#\1#p' appinfo/info.xml)

echo "== Prüfen"
docker run --rm -v "$PWD:/app:ro" -w /app $PHP php vendor/bin/phpunit -c phpunit.xml --do-not-cache-result | tail -1
docker run --rm -v "$PWD:/app:ro" -w /app $PHP vendor/bin/phpcs --standard=phpcs.xml -q
docker run --rm -v "$PWD:/app" -w /app $NODE npx vitest run --dir tests/js 2>&1 | grep -E 'Tests '
echo "== Bauen"
# Nur die Dateien in js/ leeren, nicht den Ordner: Der Entwicklungscontainer
# bindet ihn ein. Sonst wanderten alte Chunks mit veralteten Hashes ins Paket.
find js -maxdepth 1 -type f -delete
docker run --rm -v "$PWD:/app" -w /app $NODE npm run build 2>&1 | grep -E 'built'

echo "== Schnüren"
ZIEL=dist/stage/clipproof
rm -rf dist/stage
mkdir -p "$ZIEL/js"
cp -R appinfo lib templates fonts img "$ZIEL/"
mkdir -p "$ZIEL/l10n"
cp l10n/*.js l10n/*.json "$ZIEL/l10n/"
cp LICENSE README.md CHANGELOG.md THIRD-PARTY-NOTICES.md "$ZIEL/"
cp js/*.mjs js/*.license "$ZIEL/js/"
DATEI="dist/clipproof-$VERSION.tar.gz"
COPYFILE_DISABLE=1 tar --no-xattrs -czf "$DATEI" -C dist/stage clipproof
rm -rf dist/stage
( cd dist && shasum -a 256 "clipproof-$VERSION.tar.gz" > "clipproof-$VERSION.tar.gz.sha256" )
echo "$DATEI ($(du -h "$DATEI" | cut -f1))"
cat "dist/clipproof-$VERSION.tar.gz.sha256"
tar -tzf "$DATEI" | sed 's#^#  #' | head -60
