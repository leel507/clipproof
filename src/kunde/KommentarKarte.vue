<!--
  - SPDX-FileCopyrightText: 2026 Schauties Media
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Eine Anmerkung in der Seitenleiste: Kopf mit Name und Zeit, Timecode
	zum Anspringen, Text. Ein Klick auf die Karte springt zum Bild.
-->
<template>
	<article
		class="vr-kk"
		:class="{ 'vr-kk--antwort': antwort, 'vr-kk--gewaehlt': ausgewaehlt, 'vr-kk--entwurf': kommentar.entwurf }"
		@click="$emit('springen')">
		<div class="vr-kk-kopf">
			<span class="vr-kk-avatar" :class="{ 'vr-kk-avatar--besitzer': besitzer }" aria-hidden="true">{{ initial }}</span>
			<span class="vr-kk-autor">{{ besitzer ? t.vonBesitzer : kommentar.autor }}</span>
			<span class="vr-kk-zeit">{{ zeitpunkt }}</span>
			<span v-if="nummer" class="vr-kk-nr">#{{ nummer }}</span>
		</div>
		<div class="vr-kk-inhalt">
			<p class="vr-kk-text">
				<button v-if="!antwort" type="button" class="vr-kk-tc vr-tc" :aria-label="t.springen(kommentar.timecode)" @click.stop="$emit('springen')">{{ kommentar.timecode }}</button>
				<span v-if="kommentar.zeichnung" class="vr-kk-zeichen" :title="t.mitZeichnung"><Symbol name="stift" /></span>
				{{ kommentar.text }}
			</p>
			<p v-if="kommentar.erledigt && !antwort" class="vr-kk-bearbeitet" :title="t.bearbeitetHinweis">
				<Symbol name="haken" />{{ t.bearbeitet }}
			</p>
			<div class="vr-kk-fuss">
				<span v-if="kommentar.entwurf" class="vr-kk-entwurf">{{ t.entwurf }}</span>
				<button v-if="darfAntworten" type="button" class="vr-kk-link" @click.stop="$emit('antworten')">{{ t.antworten }}</button>
				<button type="button" class="vr-kk-link" @click.stop="$emit('bild')">{{ t.alsBild }}</button>
			</div>
		</div>
	</article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { getCanonicalLocale } from '@nextcloud/l10n'
import Symbol from '../gemeinsam/Symbol.vue'
import type { Kommentar } from '../gemeinsam/typen'
import { zeitpunkt as zeitpunktText } from '../lib/zeitpunkt'
import type { Texte } from './texte'

const props = defineProps<{
	kommentar: Kommentar
	antwort: boolean
	ausgewaehlt: boolean
	darfAntworten: boolean
	nummer?: number
	t: Texte
}>()
defineEmits<{
	(e: 'springen'): void
	(e: 'antworten'): void
	(e: 'bild'): void
}>()

const besitzer = computed(() => props.kommentar.autorTyp === 'besitzer')
const initial = computed(() => ((besitzer.value ? props.t.vonBesitzer : props.kommentar.autor).trim()[0] ?? '?').toUpperCase())

/** „vor 5 Minuten“, „heute 14:02“, sonst Datum; in der Sprache der Seite. */
const zeitpunkt = computed(() => zeitpunktText(props.kommentar.abgesendetAm ?? props.kommentar.erstelltAm, getCanonicalLocale()))
</script>

<style scoped>
.vr-kk {
	padding: 12px 16px;
	border-left: 2px solid transparent;
	cursor: pointer;
	transition: background var(--vr-schnell) var(--vr-ease);
}
.vr-kk:hover {
	background: var(--vr-flaeche-2);
}
.vr-kk--gewaehlt {
	background: var(--vr-flaeche-2);
	border-left-color: var(--vr-tc);
}
.vr-kk--antwort {
	padding-left: 52px;
	padding-top: 6px;
}
.vr-kk-kopf {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}
.vr-kk-avatar {
	flex: none;
	width: 26px;
	height: 26px;
	border-radius: 50%;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: var(--vr-flaeche-3);
	color: var(--vr-text);
	font-size: 12px;
	font-weight: 600;
}
.vr-kk-avatar--besitzer {
	background: var(--vr-akzent-tief);
	color: #fff;
}
.vr-kk--antwort .vr-kk-avatar {
	width: 22px;
	height: 22px;
	font-size: 11px;
}
.vr-kk-autor {
	font-weight: 600;
	font-size: 13.5px;
	color: var(--vr-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.vr-kk-zeit {
	font-size: 12px;
	color: var(--vr-text-3);
	white-space: nowrap;
}
.vr-kk-nr {
	margin-left: auto;
	font-size: 12px;
	color: var(--vr-text-3);
}
.vr-kk-inhalt {
	padding-left: 34px;
}
.vr-kk--antwort .vr-kk-inhalt {
	padding-left: 30px;
}
.vr-kk-text {
	margin: 4px 0 0;
	color: var(--vr-text);
	font-size: 14px;
	line-height: 1.5;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
.vr-kk-tc {
	display: inline-block;
	margin-right: 6px;
	padding: 0 6px;
	border: 0;
	border-radius: 4px;
	background: var(--vr-tc-grund);
	color: var(--vr-tc);
	font-size: 12.5px;
	font-weight: 600;
	line-height: 20px;
	vertical-align: 1px;
}
.vr-kk-tc:hover {
	background: rgba(255, 201, 74, .22);
}
.vr-kk-zeichen {
	display: inline-flex;
	vertical-align: -2px;
	margin-right: 6px;
	color: var(--vr-text-2);
	font-size: 14px;
}
.vr-kk-fuss {
	display: flex;
	gap: 14px;
	margin-top: 6px;
	opacity: 0;
	transition: opacity var(--vr-schnell) var(--vr-ease);
}
.vr-kk:hover .vr-kk-fuss,
.vr-kk--gewaehlt .vr-kk-fuss,
.vr-kk:focus-within .vr-kk-fuss,
.vr-kk--entwurf .vr-kk-fuss {
	opacity: 1;
}
@media (hover: none) {
	.vr-kk-fuss {
		opacity: 1;
	}
}
.vr-kk-link {
	padding: 0;
	border: 0;
	background: none;
	color: var(--vr-text-2);
	font-size: 12.5px;
	font-weight: 500;
}
@media (pointer: coarse) {
	.vr-kk-link {
		min-height: 32px;
		padding: 0 4px;
	}
}
.vr-kk-link:hover {
	color: var(--vr-text);
	text-decoration: underline;
	text-underline-offset: 3px;
}
.vr-kk-bearbeitet {
	margin: 6px 0 0;
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	font-weight: 500;
	color: var(--vr-text-2);
}
.vr-kk-bearbeitet :deep(svg) {
	width: 13px;
	height: 13px;
	color: var(--vr-ok);
}
.vr-kk-entwurf {
	font-size: 12px;
	font-weight: 500;
	color: var(--vr-tc);
}
</style>
