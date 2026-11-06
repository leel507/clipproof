#!/bin/bash
# SPDX-FileCopyrightText: 2026 Schauties Media
# SPDX-License-Identifier: AGPL-3.0-or-later
# Schnürt das Premiere-Panel (E10): dist/clipproof-panel-<version>.zip
# mit Install.command, Uninstall.command und README.txt.
# Prüft vorher host.jsx auf ES3 und die Tests.
set -euo pipefail
export PATH="$HOME/.docker/bin:$PATH"
cd "$(dirname "$0")/.."
NODE=node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1
VERSION=$(sed -nE 's/.*ExtensionBundleVersion="([^"]+)".*/\1/p' premiere-panel/CSXS/manifest.xml)

echo "== Prüfen"
# Manifest muss gültiges XML sein, sonst verwirft Premiere das Panel stumm
# (0.1.1/0.1.2: zwei Bindestriche in einem Kommentar).
xmllint --noout premiere-panel/CSXS/manifest.xml || { echo "Manifest ist kein gültiges XML. Abbruch."; exit 1; }
echo "Manifest gültig"
docker run --rm -v "$PWD:/app:ro" -w /app $NODE node -e "require('acorn').parse(require('fs').readFileSync('premiere-panel/jsx/host.jsx','utf8'),{ecmaVersion:3}); console.log('host.jsx ES3 ok')"
docker run --rm -v "$PWD:/app:ro" -w /app $NODE sh -c 'node --check premiere-panel/js/panel.js && node --check premiere-panel/js/abgleich.js && node --check premiere-panel/js/sprache.js && echo "Panel-Syntax ok"'
docker run --rm -v "$PWD:/app" -w /app $NODE npx vitest run --dir tests/js 2>&1 | grep -E 'Tests '

echo "== Schnüren"
STUFE=dist/panel-stufe
rm -rf "$STUFE"
mkdir -p "$STUFE/Clipproof Panel/clipproof-panel"
cp -R premiere-panel/CSXS premiere-panel/css premiere-panel/js premiere-panel/jsx premiere-panel/index.html "$STUFE/Clipproof Panel/clipproof-panel/"
cp premiere-panel/installer/* "$STUFE/Clipproof Panel/"
DATEI="$PWD/dist/clipproof-panel-$VERSION.zip"
rm -f "$DATEI"
( cd "$STUFE" && COPYFILE_DISABLE=1 zip -qrX "$DATEI" "Clipproof Panel" )
rm -rf "$STUFE"
( cd dist && shasum -a 256 "clipproof-panel-$VERSION.zip" > "clipproof-panel-$VERSION.zip.sha256" )
echo "$DATEI"
unzip -l "$DATEI" | sed 's#^#  #'
