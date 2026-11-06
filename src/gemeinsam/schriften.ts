/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Schrift der App aus dem App-Ordner fonts/ (Lizenz liegt daneben):
 * Archivo (OFL) als variable Schrift, Titel in breiter Form. Ohne Netz
 * zu fremden Servern.
 */
import { generateFilePath } from '@nextcloud/router'

let geladen = false

export function schriftenLaden(): void {
	if (geladen) return
	geladen = true
	const url = (datei: string) => generateFilePath('clipproof', 'fonts', datei)
	const stil = document.createElement('style')
	stil.textContent = `
@font-face {
	font-family: "Archivo VR";
	src: url("${url('archivo-var.woff2')}") format("woff2");
	font-weight: 100 900;
	font-stretch: 62% 125%;
	font-display: swap;
}`
	document.head.appendChild(stil)
}
