#!/bin/bash
# SPDX-FileCopyrightText: 2026 Schauties Media
# SPDX-License-Identifier: AGPL-3.0-or-later
# Clipproof-Panel entfernen (Doppelklick). Entfernt nur den eigenen
# Ordner. PlayerDebugMode bleibt, andere unsignierte Panels brauchen ihn.
set -euo pipefail
ZIEL="$HOME/Library/Application Support/Adobe/CEP/extensions/clipproof-panel"
if [ -d "$ZIEL" ]; then
	rm -rf "$ZIEL"
	echo "Clipproof panel removed. Restart Premiere. / Clipproof-Panel entfernt. Premiere neu starten."
else
	echo "Clipproof panel was not installed. / Clipproof-Panel war nicht installiert."
fi
read -r -p "Press Enter to close / Enter zum Schließen. "
