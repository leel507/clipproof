#!/bin/bash
# SPDX-FileCopyrightText: 2026 Schauties Media
# SPDX-License-Identifier: AGPL-3.0-or-later
# Clipproof-Panel für Premiere Pro installieren (Doppelklick).
# Kopiert nur den eigenen Ordner „clipproof-panel“; andere Panels bleiben unberührt.
# Setzt PlayerDebugMode für CSXS 9 bis 15 (unsignierte Panels). Neue
# Premiere-Fassungen nutzen CSXS 13 und neuer; ohne den Eintrag blendet
# Premiere das Panel stillschweigend aus.
set -euo pipefail
HIER="$(cd "$(dirname "$0")" && pwd)"
ZIEL="$HOME/Library/Application Support/Adobe/CEP/extensions/clipproof-panel"
mkdir -p "$(dirname "$ZIEL")"
if [ -d "$ZIEL" ]; then
	rm -rf "$ZIEL"
fi
cp -R "$HIER/clipproof-panel" "$ZIEL"
for v in 9 10 11 12 13 14 15; do
	defaults write "com.adobe.CSXS.$v" PlayerDebugMode 1
done
echo ""
echo "Clipproof panel installed."
echo "Quit Premiere completely (Cmd+Q) and start it again, then:"
echo "Window > Extensions > Clipproof"
echo "(in some versions: Window > Extensions (Legacy))."
echo ""
echo "Clipproof-Panel installiert. Premiere ganz beenden (Cmd+Q) und neu starten,"
echo "dann Fenster > Erweiterungen > Clipproof."
echo ""
read -r -p "Press Enter to close / Enter zum Schließen. "
