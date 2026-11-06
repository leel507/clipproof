<!--
  - SPDX-FileCopyrightText: 2026 Schauties Media
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Kleine Strich-Symbole, inline und ohne fremde Dateien. Farbe über
	currentColor, Größe über die Schriftgröße des Elternteils (1em).
-->
<template>
	<svg
		class="vr-symbol"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		stroke-width="1.8"
		stroke-linecap="round"
		stroke-linejoin="round"
		aria-hidden="true"
		focusable="false"
		v-html="PFADE[name]" />
</template>

<script setup lang="ts">
defineProps<{ name: keyof typeof PFADE }>()
</script>

<script lang="ts">
/* Eigene, einfache Formen. */
export const PFADE = {
	play: '<path d="M7 4.5v15l12-7.5z" fill="currentColor" stroke="none"/>',
	pause: '<rect x="6" y="4.5" width="4" height="15" rx="1" fill="currentColor" stroke="none"/><rect x="14" y="4.5" width="4" height="15" rx="1" fill="currentColor" stroke="none"/>',
	bildZurueck: '<path d="M6 5v14"/><path d="M18 5 9 12l9 7z" fill="currentColor"/>',
	bildVor: '<path d="M18 5v14"/><path d="M6 5l9 7-9 7z" fill="currentColor"/>',
	schleife: '<path d="M4 11V9a3 3 0 0 1 3-3h12"/><path d="m16 3 3 3-3 3"/><path d="M20 13v2a3 3 0 0 1-3 3H5"/><path d="m8 21-3-3 3-3"/>',
	laut: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6"/><path d="M18 6.5a7.5 7.5 0 0 1 0 11"/>',
	stumm: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="m16 9.5 5 5"/><path d="m21 9.5-5 5"/>',
	vollbild: '<path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>',
	stift: '<path d="M4 20l1-4.5L16.5 4a2.1 2.1 0 0 1 3 3L8 18.5z"/><path d="m14.5 6 3 3"/>',
	senden: '<path d="M4.5 12 20 4.5 15.5 20l-3.5-6.5z"/><path d="m12 13.5 8-9"/>',
	rueckgaengig: '<path d="M9 7 4 12l5 5"/><path d="M4 12h10a6 6 0 0 1 0 12h-2" transform="translate(0 -6)"/>',
	papierkorb: '<path d="M4.5 7h15"/><path d="M9.5 7V4.5h5V7"/><path d="M6.5 7l1 13h9l1-13"/>',
	schliessen: '<path d="m6 6 12 12"/><path d="M18 6 6 18"/>',
	antworten: '<path d="M10 8 5 12.5l5 4.5"/><path d="M5 12.5h9a5 5 0 0 1 5 5V19"/>',
	bild: '<rect x="3.5" y="5" width="17" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-5-5L6 19"/>',
	sprechblase: '<path d="M5 5h14a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 19 17h-8l-4.5 3.5V17H5a1.5 1.5 0 0 1-1.5-1.5v-9A1.5 1.5 0 0 1 5 5z"/>',
	haken: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
	pfeilUnten: '<path d="m7 10 5 5 5-5"/>',
	info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
	zurueck: '<path d="m14.5 6-6 6 6 6"/>',
} as const
</script>

<style scoped>
.vr-symbol {
	width: 1em;
	height: 1em;
	display: block;
	flex: none;
}
</style>
