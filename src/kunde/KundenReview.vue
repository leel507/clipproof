<!--
  - SPDX-FileCopyrightText: 2026 Schauties Media
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Kundenseite im Aufbau von Frame.io: oben eine
	schmale Kopfzeile, links der Player über die volle Höhe, rechts eine
	feste Spalte mit den Anmerkungen und unten darin das Eingabefeld mit
	dem Timecode. Enter sendet die Anmerkung sofort an den Besitzer.
	Lässt sich in eingestellte Seiten einbetten; keine externen Ressourcen.
	Texte stehen in texte.ts, Übersetzungen in l10n/.
-->
<template>
	<div class="vr-app vr-thema">
		<p v-if="fehler" class="vr-mitte">{{ fehler }}</p>
		<p v-else-if="!daten" class="vr-mitte vr-leise">{{ t.laden }}</p>
		<template v-else>
			<header class="vr-kopf">
				<h1 class="vr-titel">{{ daten.titel }}</h1>
				<label v-if="daten.versionen.length > 1" class="vr-version">
					<span class="vr-unsichtbar">{{ t.version }}</span>
					<select v-model="versionId">
						<option v-for="v in daten.versionen" :key="v.id" :value="v.id">{{ t.version }} {{ v.nummer }}</option>
					</select>
					<Symbol name="pfeilUnten" class="vr-version-pfeil" />
				</label>
				<span v-else class="vr-version-fest">{{ t.version }} {{ version?.nummer }}</span>
				<span class="vr-marke-name">{{ t.vonBesitzer }}</span>
			</header>

			<main class="vr-haupt">
				<div class="vr-spieler">
					<VideoPlayer
						v-if="version"
						ref="player"
						:key="version.id"
						:version="version"
						:marker="marker"
						:aktiver-marker="ausgewaehlt"
						:hinweis-firefox="t.firefox"
						:lade-fehler="t.videoFehlt"
						:erneuern="adresseErneuern"
						:neu-laden-text="t.neuLaden"
						@frame="aktuellerFrame = $event"
						@marker="markerGewaehlt">
						<template #ueberlagerung>
							<Zeichenebene
								:zeichnung="angezeigteZeichnung"
								:aktiv="zeichnenAktiv"
								:farbe="farbe"
								@aenderung="zeichnung = $event"
								@fertig="nachStrich" />
						</template>
						<template v-if="!gesperrt" #werkzeuge>
							<button type="button" class="vr-markieren" :aria-pressed="zeichnenAktiv" :title="`${zeichnenAktiv ? t.zeichnenAus : t.zeichnen} (M)`" @click="zeichnenUmschalten">
								<Symbol name="stift" />
								<span class="vr-markieren-text">{{ zeichnenAktiv ? t.fertig : t.markieren }}</span>
							</button>
						</template>
						<template #buehne>
							<div v-if="zeichnenAktiv" class="vr-zeichen-hinweis">{{ t.zeichnenHinweis }}</div>
						</template>
					</VideoPlayer>
				</div>

				<aside class="vr-spalte" :aria-label="t.liste">
					<div class="vr-spalte-kopf">
						<h2>{{ t.liste }}</h2>
						<span v-if="hauptAnzahl > 0" class="vr-zahl">{{ hauptAnzahl }}</span>
					</div>

					<button v-if="eigeneEntwuerfe > 0 && !gesperrt" type="button" class="vr-entwuerfe" :disabled="sendet" @click="entwuerfeSenden">
						{{ t.entwuerfeSenden(eigeneEntwuerfe) }}
					</button>

					<div ref="liste" class="vr-liste">
						<div v-if="faeden.length === 0" class="vr-leer">
							<span class="vr-leer-symbol"><Symbol name="sprechblase" /></span>
							<strong>{{ t.leer }}</strong>
							<span v-if="!gesperrt">{{ t.leerHinweis }}</span>
						</div>
						<ol v-else>
							<li v-for="(faden, i) in faeden" :key="faden.haupt.id" :data-kommentar="faden.haupt.id">
								<KommentarKarte
									v-for="k in [faden.haupt, ...faden.antworten]"
									:key="k.id"
									:kommentar="k"
									:antwort="k.parentId !== null"
									:nummer="k.parentId === null ? i + 1 : undefined"
									:ausgewaehlt="ausgewaehlt === k.id"
									:darf-antworten="!gesperrt && k.parentId === null && !k.entwurf"
									:t="t"
									@springen="zeigen(k)"
									@antworten="antworten(k)"
									@bild="bildSpeichern(k)" />
							</li>
						</ol>
					</div>

					<p v-if="gesperrt" class="vr-gesperrt">{{ t.gesperrt }}</p>
					<form v-else-if="version" class="vr-eingabe" @submit.prevent="senden">
						<div v-if="antwortAuf" class="vr-antwort-auf">
							<Symbol name="antworten" />
							<span>{{ t.antwortAn(antwortAuf.autorTyp === 'besitzer' ? t.vonBesitzer : antwortAuf.autor) }}</span>
							<button type="button" class="vr-ik vr-ik--klein" :aria-label="t.abbrechen" @click="antwortAuf = null"><Symbol name="schliessen" /></button>
						</div>
						<div class="vr-feld" :class="{ 'vr-feld--fokus': fokus }">
							<span class="vr-feld-tc vr-tc" :title="t.anmerkungBei(eingabeTimecode)">{{ eingabeTimecode }}</span>
							<textarea
								ref="feld"
								v-model="text"
								rows="1"
								maxlength="5000"
								:placeholder="antwortAuf ? t.antwortFeld : t.anmerkungFeld"
								:aria-label="antwortAuf ? t.antwortFeld : t.anmerkungBei(eingabeTimecode)"
								@focus="fokus = true; player?.anhalten()"
								@blur="fokus = false"
								@input="hoeheAnpassen"
								@keydown="feldTaste" />
						</div>
						<div class="vr-werkzeuge">
							<button type="button" class="vr-ik" :aria-pressed="zeichnenAktiv" :aria-label="zeichnenAktiv ? t.zeichnenAus : t.zeichnen" :title="zeichnenAktiv ? t.zeichnenAus : t.zeichnen" @click="zeichnenUmschalten">
								<Symbol name="stift" />
							</button>
							<template v-if="zeichnenAktiv || zeichnung">
								<span class="vr-farben" role="radiogroup" :aria-label="t.zeichnen">
									<button
										v-for="f in farben"
										:key="f.wert"
										type="button"
										role="radio"
										class="vr-farbe"
										:aria-checked="farbe === f.wert"
										:aria-label="t.farbe(f.name)"
										:title="f.name"
										:style="{ '--farbe': f.wert }"
										@click="farbeWaehlen(f.wert)" />
								</span>
								<button type="button" class="vr-ik" :disabled="!zeichnung" :aria-label="t.rueckgaengig" :title="t.rueckgaengig" @click="strichZurueck"><Symbol name="rueckgaengig" /></button>
								<button type="button" class="vr-ik" :disabled="!zeichnung" :aria-label="t.zeichnungLoeschen" :title="t.zeichnungLoeschen" @click="zeichnungVerwerfen"><Symbol name="papierkorb" /></button>
							</template>
							<span v-else class="vr-enter">{{ t.enterHinweis }}</span>
							<button type="submit" class="vr-senden" :disabled="!kannSenden || sendet" :aria-label="t.senden" :title="t.senden">
								<Symbol name="senden" />
							</button>
						</div>
						<p v-if="formFehler" class="vr-fehler" role="alert">{{ formFehler }}</p>
					</form>
				</aside>
			</main>

			<Transition name="vr-toast">
				<div v-if="toast" class="vr-toast" role="status"><Symbol name="haken" />{{ toast }}</div>
			</Transition>

			<Transition name="vr-dialog">
				<div v-if="namenDialog" class="vr-dialog-grund" @keydown.esc="namenDialog = false" @click.self="namenDialog = false">
					<form class="vr-dialog" role="dialog" aria-modal="true" aria-labelledby="vr-name-titel" @submit.prevent="anmelden">
						<h2 id="vr-name-titel">{{ t.nameFrage }}</h2>
						<p>{{ t.nameErklaerung }}</p>
						<label for="vr-name" class="vr-unsichtbar">{{ t.nameFeld }}</label>
						<input id="vr-name" ref="nameFeld" v-model="name" :placeholder="t.nameFeld" autocomplete="name" maxlength="100" required>
						<p class="vr-dialog-folge">{{ t.folge }}</p>
						<div class="vr-datenschutz">
							<p>{{ t.datenschutzKurz }}</p>
							<details>
								<summary>{{ t.datenschutzMehr }}</summary>
								<ul>
									<li v-for="(punkt, i) in t.datenschutzPunkte" :key="i">{{ punkt }}</li>
								</ul>
								<a v-if="t.datenschutzUrl" :href="t.datenschutzUrl" target="_blank" rel="noopener noreferrer">{{ t.datenschutzLink }}</a>
							</details>
						</div>
						<div class="vr-dialog-knoepfe">
							<button type="button" class="vr-knopf-leise" @click="namenDialog = false">{{ t.abbrechen }}</button>
							<button type="submit" class="vr-knopf" :disabled="name.trim() === ''">{{ t.nameWeiter }}</button>
						</div>
					</form>
				</div>
			</Transition>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { generateUrl } from '@nextcloud/router'
import VideoPlayer from '../gemeinsam/VideoPlayer.vue'
import Zeichenebene from '../gemeinsam/Zeichenebene.vue'
import Symbol from '../gemeinsam/Symbol.vue'
import KommentarKarte from './KommentarKarte.vue'
import { ApiFehler, KundenApi } from './api'
import { texte, type KundenInfo } from './texte'
import { vonFrames } from '../lib/timecode'
import { herunterladen, standbild } from '../lib/zeichnung'
import { schriftenLaden } from '../gemeinsam/schriften'
import type { Kommentar, Marker, Version, Zeichnung } from '../gemeinsam/typen'

const props = defineProps<{ token: string, info: KundenInfo }>()

interface Daten {
	titel: string
	kommentareGesperrt: boolean
	versionen: Version[]
}

const FARBWERTE = { rot: '#ff4d4f', gelb: '#ffd60a', gruen: '#34c759', blau: '#2ea7f0', weiss: '#ffffff' } as const

schriftenLaden()

const api = new KundenApi(props.token)
const daten = ref<Daten | null>(null)
const t = computed(() => texte(props.info))
const farben = computed(() => (Object.keys(FARBWERTE) as (keyof typeof FARBWERTE)[]).map((k) => ({ name: t.value.farben[k], wert: FARBWERTE[k] })))
const fehler = ref('')
const versionId = ref<number | null>(null)
const version = computed(() => daten.value?.versionen.find((v) => v.id === versionId.value) ?? null)
const kommentare = ref<Kommentar[]>([])
const gesperrt = ref(false)
const player = ref<InstanceType<typeof VideoPlayer> | null>(null)
const liste = ref<HTMLElement | null>(null)
const feld = ref<HTMLTextAreaElement | null>(null)
const aktuellerFrame = ref(0)

const text = ref('')
const zeichnung = ref<Zeichnung | null>(null)
const zeichnenAktiv = ref(false)
const farbe = ref<string>(FARBWERTE.rot)
const fokus = ref(false)
const formFehler = ref('')
const sendet = ref(false)
const antwortAuf = ref<Kommentar | null>(null)
const ausgewaehlt = ref<number | null>(null)
const toast = ref('')

const namenDialog = ref(false)
const name = ref('')
const nameFeld = ref<HTMLInputElement | null>(null)

const kannSenden = computed(() => text.value.trim() !== '' || zeichnung.value !== null)
const eigeneEntwuerfe = computed(() => kommentare.value.filter((k) => k.entwurf && k.eigen).length)

const rate = computed(() => (version.value ? { num: version.value.fpsNum, den: version.value.fpsDen } : { num: 25, den: 1 }))
const tcVon = (f: number) => (version.value ? vonFrames(version.value.startFrames + f, rate.value, version.value.df) : '')
/** Antworten hängen am Bild ihrer Anmerkung, neue Anmerkungen am aktuellen. */
const eingabeTimecode = computed(() => tcVon(antwortAuf.value?.frameIn ?? aktuellerFrame.value))

const imVideo = computed(() => kommentare.value.filter((k) => k.versionId === versionId.value))
const faeden = computed(() => imVideo.value
	.filter((k) => k.parentId === null)
	.map((h) => ({ haupt: h, antworten: imVideo.value.filter((k) => k.parentId === h.id) })))
const hauptAnzahl = computed(() => faeden.value.length)
const marker = computed<Marker[]>(() => faeden.value.map(({ haupt: k }, i) => ({
	id: k.id,
	frame: k.frameIn,
	titel: `#${i + 1} ${k.timecode} ${k.autorTyp === 'besitzer' ? t.value.vonBesitzer : k.autor}`,
	entwurf: k.entwurf,
})))

const angezeigteZeichnung = computed<Zeichnung | null>(() => {
	if (zeichnenAktiv.value || zeichnung.value) return zeichnung.value
	const k = kommentare.value.find((x) => x.id === ausgewaehlt.value)
	// Fremde Zeichnung nur, solange das Bild ihrer Anmerkung steht.
	return k && k.frameIn === aktuellerFrame.value ? k.zeichnung : null
})

/** Neue, kurzlebige Video-Adresse für die angezeigte Version. */
async function adresseErneuern(): Promise<{ videoUrl: string, videoGueltigFuer: number } | null> {
	const antwort = await fetch(generateUrl('/apps/clipproof/r/{token}/daten', { token: props.token }), {
		headers: { Accept: 'application/json' },
		credentials: 'omit',
	})
	if (!antwort.ok) return null
	const neu = (await antwort.json() as Daten).versionen.find((v) => v.id === versionId.value)
	return neu ? { videoUrl: neu.videoUrl, videoGueltigFuer: neu.videoGueltigFuer } : null
}

onMounted(async () => {
	try {
		const antwort = await fetch(generateUrl('/apps/clipproof/r/{token}/daten', { token: props.token }), {
			headers: { Accept: 'application/json' },
			credentials: 'omit',
		})
		if (!antwort.ok) throw new Error(String(antwort.status))
		daten.value = await antwort.json() as Daten
		versionId.value = daten.value.versionen.at(-1)?.id ?? null
		gesperrt.value = daten.value.kommentareGesperrt
		await laden()
	} catch {
		fehler.value = t.value.ladeFehler
	}
})

watch(versionId, () => {
	ausgewaehlt.value = null
	antwortAuf.value = null
	zeichnungVerwerfen()
	zeichnenAktiv.value = false
})

async function laden(): Promise<void> {
	const r = await api.kommentare()
	kommentare.value = r.kommentare
	gesperrt.value = r.gesperrt
}

function hoeheAnpassen(): void {
	const f = feld.value
	if (!f) return
	f.style.height = 'auto'
	f.style.height = `${Math.min(f.scrollHeight, 160)}px`
}

function feldTaste(e: KeyboardEvent): void {
	if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
		e.preventDefault()
		void senden()
	} else if (e.key === 'Escape') {
		if (zeichnenAktiv.value) zeichnenAktiv.value = false
		else if (antwortAuf.value) antwortAuf.value = null
		else feld.value?.blur()
	}
}

/** Nach dem ersten Strich gleich ins Textfeld: schreiben, Enter, fertig. */
function nachStrich(): void {
	if (document.activeElement !== feld.value) feld.value?.focus({ preventScroll: true })
}

/** Taste M: Markieren an und aus, wenn kein Feld den Fokus hat. */
function globaleTaste(e: KeyboardEvent): void {
	const ziel = e.target as HTMLElement | null
	if (ziel?.closest?.('input, textarea, select, [contenteditable], [role=dialog]')) return
	if (e.metaKey || e.ctrlKey || e.altKey || gesperrt.value) return
	if (e.key === 'm' || e.key === 'M') {
		e.preventDefault()
		zeichnenUmschalten()
	}
}
window.addEventListener('keydown', globaleTaste)
onBeforeUnmount(() => window.removeEventListener('keydown', globaleTaste))

function zeichnenUmschalten(): void {
	zeichnenAktiv.value = !zeichnenAktiv.value
	if (zeichnenAktiv.value) {
		player.value?.anhalten()
		ausgewaehlt.value = null
	}
}

function farbeWaehlen(wert: string): void {
	farbe.value = wert
	// Eine Zeichnung hat eine Farbe; die Wahl gilt für alle Striche.
	if (zeichnung.value) zeichnung.value = { ...zeichnung.value, farbe: wert }
}

function strichZurueck(): void {
	if (!zeichnung.value) return
	const striche = zeichnung.value.striche.slice(0, -1)
	zeichnung.value = striche.length > 0 ? { ...zeichnung.value, striche } : null
}

function zeichnungVerwerfen(): void {
	zeichnung.value = null
}

async function antworten(k: Kommentar): Promise<void> {
	antwortAuf.value = k
	await zeigen(k)
	feld.value?.focus()
}

/**
 * Enter: anlegen und sofort senden. Jede Anmerkung ist eine eigene
 * Abgabe.
 */
async function senden(): Promise<void> {
	if (!version.value || !kannSenden.value || sendet.value) return
	if (!api.angemeldet) {
		namenDialog.value = true
		await nextTick()
		nameFeld.value?.focus()
		return
	}
	sendet.value = true
	formFehler.value = ''
	try {
		await api.anlegen({
			versionId: version.value.id,
			frameIn: antwortAuf.value?.frameIn ?? player.value?.aktuellerFrame() ?? aktuellerFrame.value,
			text: text.value.trim(),
			zeichnung: zeichnung.value,
			parentId: antwortAuf.value?.id ?? null,
		})
		text.value = ''
		zeichnung.value = null
		zeichnenAktiv.value = false
		antwortAuf.value = null
		await nextTick()
		hoeheAnpassen()
		const r = await api.abgeben()
		await laden()
		zeigeToast(t.value.bestaetigung(r.anzahl))
	} catch (e) {
		if (e instanceof ApiFehler && e.status === 401) {
			namenDialog.value = true
		} else if (e instanceof ApiFehler && e.status === 423) {
			gesperrt.value = true
		} else {
			formFehler.value = fehlerText(e)
		}
		await laden().catch(() => undefined)
	} finally {
		sendet.value = false
	}
}

/** Klartext für den Kunden, nie ein nackter Statuscode. */
function fehlerText(e: unknown): string {
	if (!(e instanceof ApiFehler)) return t.value.keinNetz
	if (e.status === 404 && e.code === 'link_invalid') return t.value.linkWeg
	if (e.status === 429) return t.value.zuSchnell
	if (e.status >= 500) return t.value.fehler
	return e.message || t.value.fehler
}

/** Liegengebliebene Entwürfe (Netzfehler, alte Fassung der Seite). */
async function entwuerfeSenden(): Promise<void> {
	sendet.value = true
	try {
		const r = await api.abgeben()
		await laden()
		zeigeToast(t.value.bestaetigung(r.anzahl))
	} catch (e) {
		formFehler.value = fehlerText(e)
	} finally {
		sendet.value = false
	}
}

async function anmelden(): Promise<void> {
	try {
		await api.anmelden(name.value)
		namenDialog.value = false
		await senden()
	} catch (e) {
		formFehler.value = fehlerText(e)
		namenDialog.value = false
	}
}

let toastUhr: ReturnType<typeof setTimeout> | undefined
function zeigeToast(meldung: string): void {
	toast.value = meldung
	clearTimeout(toastUhr)
	toastUhr = setTimeout(() => { toast.value = '' }, 3200)
}

async function zeigen(k: Kommentar): Promise<void> {
	ausgewaehlt.value = k.parentId ?? k.id
	if (!zeichnung.value) zeichnenAktiv.value = false
	await player.value?.springeZu(k.frameIn)
}

async function markerGewaehlt(id: number): Promise<void> {
	const k = kommentare.value.find((x) => x.id === id)
	if (!k) return
	await zeigen(k)
	liste.value?.querySelector(`[data-kommentar="${id}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}

async function bildSpeichern(k: Kommentar): Promise<void> {
	await zeigen(k)
	const video = player.value?.video
	if (!video) return
	const blob = await standbild(video, k.zeichnung)
	herunterladen(blob, `${daten.value?.titel ?? 'review'}_${k.timecode.replace(/[:;]/g, '-')}.png`)
}
</script>

<style>
/* Der Nextcloud-Hintergrund bleibt draußen. */
body:has(.vr-app) {
	background: #0c0e11 !important;
	background-image: none !important;
}
</style>

<style scoped>
.vr-app {
	position: fixed;
	inset: 0;
	z-index: 100;
	display: grid;
	grid-template-rows: 48px minmax(0, 1fr);
	background: var(--vr-grund);
	overflow: hidden;
}
.vr-mitte {
	grid-row: 1 / -1;
	align-self: center;
	justify-self: center;
	margin: 0;
	padding: 24px;
	text-align: center;
	color: var(--vr-text-2);
}
.vr-leise {
	color: var(--vr-text-3);
}
.vr-unsichtbar {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

/* Kopfzeile */
.vr-kopf {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 0 16px;
	border-bottom: 1px solid var(--vr-linie);
	background: var(--vr-grund);
	min-width: 0;
}
.vr-titel {
	margin: 0;
	font-family: var(--vr-titel);
	font-stretch: 125%;
	font-weight: 600;
	font-size: 16px;
	letter-spacing: .01em;
	color: var(--vr-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	min-width: 0;
}
.vr-version {
	position: relative;
	display: inline-flex;
	align-items: center;
	flex: none;
}
.vr-version select {
	appearance: none;
	height: 28px;
	padding: 0 28px 0 10px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche);
	color: var(--vr-text-2);
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
}
.vr-version select:hover {
	color: var(--vr-text);
	border-color: var(--vr-text-3);
}
.vr-version-pfeil {
	position: absolute;
	right: 8px;
	pointer-events: none;
	color: var(--vr-text-3);
	font-size: 14px;
}
.vr-version-fest {
	flex: none;
	font-size: 13px;
	font-weight: 500;
	color: var(--vr-text-3);
}
.vr-marke-name {
	margin-left: auto;
	flex: none;
	font-size: 12.5px;
	font-weight: 500;
	color: var(--vr-text-3);
}

/* Hauptbereich: Player links, Spalte rechts */
.vr-haupt {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 360px;
	min-height: 0;
}
.vr-spieler {
	min-width: 0;
	min-height: 0;
	position: relative;
}
.vr-markieren {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 12px;
	margin-right: 4px;
	border: 1px solid var(--vr-linie);
	border-radius: 999px;
	background: var(--vr-flaeche);
	color: var(--vr-text);
	font-size: 13px;
	font-weight: 500;
	transition: background var(--vr-schnell) var(--vr-ease), border-color var(--vr-schnell) var(--vr-ease);
}
/* Schmales Handy: nur das Symbol, der Name bleibt für Screenreader. */
@media (max-width: 420px) {
	.vr-markieren {
		width: 32px;
		padding: 0;
		justify-content: center;
	}
	.vr-markieren-text {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
}
.vr-markieren:hover {
	border-color: var(--vr-text-3);
}
.vr-markieren[aria-pressed="true"] {
	border-color: transparent;
	background: var(--vr-akzent);
	color: #fff;
}
.vr-zeichen-hinweis {
	position: absolute;
	top: 20px;
	left: 50%;
	transform: translateX(-50%);
	padding: 6px 12px;
	border-radius: 999px;
	background: rgba(12, 14, 17, .82);
	color: var(--vr-text);
	font-size: 13px;
	font-weight: 500;
	pointer-events: none;
	backdrop-filter: blur(6px);
}

.vr-spalte {
	display: flex;
	flex-direction: column;
	min-height: 0;
	background: var(--vr-flaeche);
	border-left: 1px solid var(--vr-linie);
}
.vr-spalte-kopf {
	display: flex;
	align-items: center;
	gap: 8px;
	height: 48px;
	padding: 0 16px;
	border-bottom: 1px solid var(--vr-linie);
	flex: none;
}
.vr-spalte-kopf h2 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--vr-text);
}
.vr-zahl {
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 999px;
	background: var(--vr-flaeche-3);
	color: var(--vr-text-2);
	font-size: 12px;
	font-weight: 600;
	line-height: 20px;
	text-align: center;
}
.vr-entwuerfe {
	margin: 12px 16px 0;
	padding: 8px 12px;
	border: 1px solid rgba(255, 201, 74, .4);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-tc-grund);
	color: var(--vr-tc);
	font-size: 13px;
	font-weight: 500;
	text-align: left;
}
.vr-liste {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	overscroll-behavior: contain;
	padding: 4px 0;
}
.vr-liste ol {
	list-style: none;
	margin: 0;
	padding: 0;
}
.vr-liste li + li {
	border-top: 1px solid var(--vr-linie);
}
.vr-leer {
	height: 100%;
	min-height: 180px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 24px 28px;
	text-align: center;
	color: var(--vr-text-2);
	font-size: 13.5px;
}
.vr-leer strong {
	color: var(--vr-text);
	font-weight: 600;
	font-size: 14px;
}
.vr-leer-symbol {
	font-size: 36px;
	color: var(--vr-text-3);
	margin-bottom: 6px;
}

/* Eingabe unten in der Spalte */
.vr-eingabe {
	flex: none;
	padding: 12px;
	border-top: 1px solid var(--vr-linie);
	background: var(--vr-flaeche);
}
.vr-antwort-auf {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 8px;
	color: var(--vr-text-2);
	font-size: 12.5px;
	font-weight: 500;
}
.vr-antwort-auf span {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.vr-ik--klein {
	width: 24px;
	height: 24px;
	font-size: 14px;
}
.vr-feld {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 8px 10px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius);
	background: var(--vr-flaeche-2);
	transition: border-color var(--vr-schnell) var(--vr-ease);
}
.vr-feld--fokus {
	border-color: var(--vr-akzent);
}
.vr-feld-tc {
	flex: none;
	margin-top: 1px;
	padding: 0 6px;
	border-radius: 4px;
	background: var(--vr-tc-grund);
	color: var(--vr-tc);
	font-size: 12.5px;
	font-weight: 600;
	line-height: 20px;
}
.vr-feld textarea {
	flex: 1;
	min-width: 0;
	min-height: 22px;
	max-height: 160px;
	padding: 0;
	border: 0;
	outline: none;
	resize: none;
	background: transparent;
	color: var(--vr-text);
	font-size: 14px;
	line-height: 22px;
}
.vr-feld textarea::placeholder {
	color: var(--vr-text-3);
}
.vr-werkzeuge {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-top: 8px;
	min-height: 32px;
}
.vr-enter {
	flex: 1;
	padding-left: 6px;
	font-size: 12px;
	color: var(--vr-text-3);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.vr-farben {
	display: inline-flex;
	gap: 6px;
	padding: 0 6px;
	margin-right: auto;
}
.vr-farbe {
	width: 18px;
	height: 18px;
	padding: 0;
	border: 2px solid var(--vr-flaeche);
	border-radius: 50%;
	background: var(--farbe);
	box-shadow: 0 0 0 1px var(--vr-linie);
	transition: transform var(--vr-schnell) var(--vr-ease), box-shadow var(--vr-schnell) var(--vr-ease);
}
.vr-farbe:hover {
	transform: scale(1.12);
}
.vr-farbe[aria-checked="true"] {
	box-shadow: 0 0 0 2px var(--vr-text);
}
.vr-senden {
	margin-left: auto;
	flex: none;
	width: 34px;
	height: 34px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border: 0;
	border-radius: var(--vr-radius-klein);
	background: var(--vr-akzent);
	color: #fff;
	font-size: 17px;
	transition: background var(--vr-schnell) var(--vr-ease), opacity var(--vr-schnell) var(--vr-ease);
}
/* Finger: Absenden ist der wichtigste Knopf, volle 44 px. */
@media (pointer: coarse) {
	.vr-senden {
		width: 44px;
		height: 44px;
	}
}
.vr-senden:hover:not(:disabled) {
	background: var(--vr-akzent-hover);
}
.vr-senden:disabled {
	opacity: .35;
	cursor: default;
}
.vr-fehler {
	margin: 8px 2px 0;
	color: var(--vr-fehler);
	font-size: 13px;
}
.vr-gesperrt {
	flex: none;
	margin: 0;
	padding: 14px 16px;
	border-top: 1px solid var(--vr-linie);
	color: var(--vr-text-2);
	font-size: 13.5px;
}

/* Bestätigung */
.vr-toast {
	position: fixed;
	left: 50%;
	top: 60px;
	transform: translateX(-50%);
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	border: 1px solid var(--vr-linie);
	border-radius: 999px;
	background: var(--vr-flaeche-2);
	color: var(--vr-text);
	font-size: 13.5px;
	font-weight: 500;
	box-shadow: 0 12px 32px rgba(0, 0, 0, .45);
	z-index: 120;
}
.vr-toast .vr-symbol {
	color: var(--vr-ok);
	font-size: 16px;
}
.vr-toast-enter-active {
	transition: opacity var(--vr-mittel) var(--vr-ease), transform var(--vr-mittel) var(--vr-ease);
}
.vr-toast-leave-active {
	transition: opacity 350ms var(--vr-ease), transform 350ms var(--vr-ease);
}
.vr-toast-enter-from,
.vr-toast-leave-to {
	opacity: 0;
	transform: translate(-50%, -8px);
}

/* Namensdialog */
.vr-dialog-grund {
	position: fixed;
	inset: 0;
	z-index: 130;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	background: rgba(0, 0, 0, .6);
}
.vr-dialog {
	width: min(420px, 100%);
	max-height: calc(100% - 32px);
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 24px;
	border: 1px solid var(--vr-linie);
	border-radius: 14px;
	background: var(--vr-flaeche);
	box-shadow: 0 24px 64px rgba(0, 0, 0, .55);
}
.vr-dialog h2 {
	margin: 0;
	font-family: var(--vr-titel);
	font-stretch: 125%;
	font-weight: 600;
	font-size: 20px;
	color: var(--vr-text);
}
.vr-dialog p {
	margin: 0;
	color: var(--vr-text-2);
	font-size: 14px;
}
.vr-dialog input {
	height: 42px;
	padding: 0 12px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche-2);
	color: var(--vr-text);
	font-size: 15px;
}
.vr-dialog input:focus {
	outline: none;
	border-color: var(--vr-akzent);
}
.vr-dialog .vr-dialog-folge {
	font-size: 13px;
	color: var(--vr-text-2);
}
.vr-datenschutz {
	padding-top: 12px;
	border-top: 1px solid var(--vr-linie);
}
.vr-datenschutz p,
.vr-datenschutz li {
	font-size: 12.5px;
	color: var(--vr-text-3);
	line-height: 1.5;
}
.vr-datenschutz details {
	margin-top: 6px;
	font-size: 12.5px;
	color: var(--vr-text-3);
}
.vr-datenschutz summary {
	cursor: pointer;
	color: var(--vr-text-2);
	font-weight: 500;
}
.vr-datenschutz ul {
	margin: 6px 0;
	padding-left: 18px;
}
.vr-datenschutz a {
	color: var(--vr-akzent);
}
.vr-dialog-knoepfe {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 4px;
}
.vr-knopf,
.vr-knopf-leise {
	height: 36px;
	padding: 0 16px;
	border-radius: var(--vr-radius-klein);
	font-size: 14px;
	font-weight: 500;
}
.vr-knopf {
	border: 0;
	background: var(--vr-akzent);
	color: #fff;
}
.vr-knopf:disabled {
	opacity: .4;
	cursor: default;
}
.vr-knopf-leise {
	border: 1px solid var(--vr-linie);
	background: transparent;
	color: var(--vr-text-2);
}
.vr-knopf-leise:hover {
	color: var(--vr-text);
	border-color: var(--vr-text-3);
}
.vr-dialog-enter-active {
	transition: opacity var(--vr-mittel) var(--vr-ease);
}
.vr-dialog-leave-active {
	transition: opacity var(--vr-schnell) var(--vr-ease);
}
.vr-dialog-enter-active .vr-dialog {
	transition: transform var(--vr-mittel) var(--vr-ease);
}
.vr-dialog-enter-from,
.vr-dialog-leave-to {
	opacity: 0;
}
.vr-dialog-enter-from .vr-dialog {
	transform: scale(.96);
}

/* Schmal: untereinander */
@media (max-width: 860px) {
	.vr-haupt {
		grid-template-columns: minmax(0, 1fr);
		grid-template-rows: minmax(220px, 52%) minmax(0, 1fr);
	}
	.vr-spalte {
		border-left: 0;
		border-top: 1px solid var(--vr-linie);
	}
	.vr-spalte-kopf {
		height: 40px;
	}
	.vr-marke-name {
		display: none;
	}
}
</style>
