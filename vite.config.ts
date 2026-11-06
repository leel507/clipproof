/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'

// Baut nach js/clipproof-<eintrag>.mjs. CSS wird ins JavaScript
// eingebettet, damit die Kundenseite mit einer einzigen Datei auskommt.
//
// js/ wird nicht geleert: Der Entwicklungscontainer bindet den Ordner
// ein, und ein gelöschter und neu angelegter Ordner wäre für ihn leer.
export default createAppConfig({
	kunde: 'src/kunde/main.ts',
	besitzer: 'src/besitzer/main.ts',
	dateien: 'src/dateien/main.ts',
}, {
	// Jeder Einstieg bekommt sein eigenes CSS. Mit „true“ landete alles
	// nur in clipproof-besitzer.mjs, die Kundenseite blieb ungestaltet.
	inlineCSS: { relativeCSSInjection: true },
	emptyOutputDirectory: false,
	config: {
		build: {
			emptyOutDir: false,
			cssCodeSplit: true,
		},
	},
})
