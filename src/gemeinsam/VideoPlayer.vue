<!--
  - SPDX-FileCopyrightText: 2026 Schauties Media
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Player im Aufbau von Frame.io: Bild auf schwarzer Bühne, darunter über
	die volle Breite die Zeitleiste mit Markern, darunter die Bedienleiste
	mit Timecode in der Mitte. Füllt die Höhe seines Elternteils.

	Frame ist der nullbasierte Index ab dem ersten Bild der Datei; der
	Timecode enthält den Start-Timecode der Version.
-->
<template>
	<section ref="wurzel" class="vr-player" :aria-label="t('Video player')">
		<div ref="buehne" class="vr-buehne">
			<div class="vr-bild" :style="bildStil">
				<video
					ref="video"
					class="vr-video"
					:src="quelle"
					preload="auto"
					playsinline
					:loop="schleife"
					@click="umschalten"
					@play="spielt = true"
					@pause="spielt = false; adressePruefen()"
					@volumechange="stumm = video?.muted ?? false"
					@error="ladefehler" />
				<slot name="ueberlagerung" />
			</div>
			<div v-if="kaputt" class="vr-kaputt" role="alert">
				<p>{{ ladeFehler ?? t('The video cannot be loaded right now.') }}</p>
				<button type="button" class="vr-kaputt-knopf" @click="neuLaden">{{ neuLadenText ?? t('Reload page') }}</button>
			</div>
			<slot name="buehne" />
		</div>

		<div
			ref="leiste"
			class="vr-zeit"
			role="slider"
			tabindex="0"
			:aria-label="t('Position in the video')"
			:aria-valuemin="0"
			:aria-valuemax="version.bilder - 1"
			:aria-valuenow="frame"
			:aria-valuetext="timecode"
			@pointerdown="ziehenStart"
			@pointermove="ziehenWeiter"
			@pointerup="ziehenEnde"
			@pointercancel="ziehenEnde">
			<div class="vr-zeit-spur">
				<div class="vr-zeit-fuellung" :style="{ width: `${anteil * 100}%` }" />
			</div>
			<div class="vr-zeit-kopf" :style="{ left: `${anteil * 100}%` }" />
			<button
				v-for="m in marker"
				:key="m.id"
				type="button"
				class="vr-marke"
				:class="{ 'vr-marke--entwurf': m.entwurf, 'vr-marke--aktiv': m.id === aktiverMarker }"
				:style="{ left: `${(m.frame / Math.max(1, version.bilder - 1)) * 100}%` }"
				:title="m.titel"
				:aria-label="m.titel"
				@pointerdown.stop
				@click.stop="$emit('marker', m.id)" />
		</div>

		<div class="vr-bedienung">
			<div class="vr-bedienung-links">
				<button type="button" class="vr-ik vr-ik--gross" :aria-label="spielt ? t('Pause') : t('Play')" :title="spielt ? t('Pause (Space)') : t('Play (Space)')" @click="umschalten">
					<Symbol :name="spielt ? 'pause' : 'play'" />
				</button>
				<button type="button" class="vr-ik" :aria-label="t('One frame back')" :title="t('One frame back (Left arrow)')" @click="schritt(-1)">
					<Symbol name="bildZurueck" />
				</button>
				<button type="button" class="vr-ik" :aria-label="t('One frame forward')" :title="t('One frame forward (Right arrow)')" @click="schritt(1)">
					<Symbol name="bildVor" />
				</button>
				<button type="button" class="vr-ik" :aria-pressed="schleife" :aria-label="t('Loop')" :title="t('Loop')" @click="schleife = !schleife">
					<Symbol name="schleife" />
				</button>
				<button type="button" class="vr-text-knopf" :title="t('Speed')" :aria-label="t('Speed {speed}, change', { speed: tempoText })" @click="tempoWeiter">{{ tempoText }}</button>
				<button type="button" class="vr-ik" :aria-label="stumm ? t('Unmute') : t('Mute')" :title="stumm ? t('Unmute') : t('Mute')" @click="tonUmschalten">
					<Symbol :name="stumm ? 'stumm' : 'laut'" />
				</button>
			</div>

			<button type="button" class="vr-uhr vr-tc" :title="anzeige === 'tc' ? t('Switch to frame number') : t('Switch to timecode')" :aria-label="(anzeige === 'tc' ? t('Timecode {timecode}', { timecode }) : t('Frame {frame}', { frame })) + '. ' + (anzeige === 'tc' ? t('Switch to frame number') : t('Switch to timecode'))" aria-live="off" @click="anzeige = anzeige === 'tc' ? 'frame' : 'tc'">
				<span data-test="timecode">{{ anzeige === 'tc' ? timecode : t('Frame {frame}', { frame }) }}</span>
				<span class="vr-uhr-fps">{{ fpsText }}</span>
			</button>

			<div class="vr-bedienung-rechts">
				<span v-if="uhr && !uhr.bildgenau && hinweisFirefox" class="vr-ik vr-ik--info" :title="hinweisFirefox" tabindex="0" :aria-label="hinweisFirefox">
					<Symbol name="info" />
				</span>
				<slot name="werkzeuge" />
				<button v-if="vollbildMoeglich" type="button" class="vr-ik" :aria-label="t('Full screen')" :title="t('Full screen')" @click="vollbild">
					<Symbol name="vollbild" />
				</button>
			</div>
		</div>
	</section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { getCanonicalLocale, t as tTr } from '@nextcloud/l10n'
import { FrameUhr } from '../lib/frameSync'
import { vonFrames } from '../lib/timecode'
import Symbol from './Symbol.vue'
import type { AdresseErneuern, Marker, Version } from './typen'

const t = (s: string, v: Record<string, string | number> = {}): string => tTr('clipproof', s, v)

const props = defineProps<{
	version: Version
	marker?: Marker[]
	aktiverMarker?: number | null
	hinweisFirefox?: string
	/** Holt eine neue Video-Adresse, bevor die alte abläuft. */
	erneuern?: AdresseErneuern
	/** Text, wenn das Video nicht lädt (Datei weg, Server, Netz). */
	ladeFehler?: string
	neuLadenText?: string
}>()
const emit = defineEmits<{
	(e: 'frame', frame: number): void
	(e: 'marker', id: number): void
}>()

const TEMPI = [0.5, 1, 1.5, 2]

const wurzel = ref<HTMLElement | null>(null)
const buehne = ref<HTMLElement | null>(null)
const leiste = ref<HTMLElement | null>(null)
const video = ref<HTMLVideoElement | null>(null)
const uhr = shallowRef<FrameUhr | null>(null)
const frame = ref(0)
const spielt = ref(false)
const schleife = ref(false)
const stumm = ref(false)
/** Video lädt nicht: statt schwarzer Fläche eine Meldung. */
const kaputt = ref(false)

/*
 * Kurzlebige Video-Adresse: rechtzeitig und
 * unbemerkt erneuern. Angehalten sofort tauschen, beim Abspielen bis zur
 * nächsten Pause warten, außer es wird knapp. Scheitert eine Anfrage doch
 * (etwa nach dem Ruhezustand), einmal erneuern und an derselben Stelle
 * weitermachen.
 */
const quelle = ref(props.version.videoUrl)
let ablaufMs = Date.now() + props.version.videoGueltigFuer * 1000
let gueltigMs = props.version.videoGueltigFuer * 1000
let erneuert = false
let pruefTakt: number | null = null

function restMs(): number {
	return ablaufMs - Date.now()
}

/** Vorlauf: zehn Minuten, bei kurzer Gültigkeit (Proben) ein Viertel. */
function vorlaufMs(): number {
	return Math.min(10 * 60 * 1000, gueltigMs / 4)
}

async function adresseWechseln(): Promise<boolean> {
	const v = video.value
	if (!v || !props.erneuern) return false
	let neu: Awaited<ReturnType<AdresseErneuern>>
	try {
		neu = await props.erneuern()
	} catch {
		return false
	}
	if (!neu) return false
	const zeit = v.currentTime
	const spielte = !v.paused && !v.ended
	ablaufMs = Date.now() + neu.videoGueltigFuer * 1000
	gueltigMs = neu.videoGueltigFuer * 1000
	v.addEventListener('loadedmetadata', () => {
		v.currentTime = zeit
		uhr.value?.neuStarten()
		if (spielte) void v.play()
	}, { once: true })
	quelle.value = neu.videoUrl
	return true
}

function adressePruefen(): void {
	const v = video.value
	if (!v || kaputt.value || restMs() > vorlaufMs()) return
	// Läuft das Video, bis zur Pause warten; nur im letzten Viertel des
	// Vorlaufs mitten im Abspielen tauschen.
	if (v.paused || restMs() < vorlaufMs() / 4) void adresseWechseln()
}

async function ladefehler(): Promise<void> {
	if (!erneuert && props.erneuern) {
		erneuert = true
		if (await adresseWechseln()) {
			// Nach einem gelungenen Wechsel darf ein späterer Fehler erneut
			// eine neue Adresse holen.
			video.value?.addEventListener('canplay', () => { erneuert = false }, { once: true })
			return
		}
	}
	kaputt.value = true
}
function neuLaden(): void {
	window.location.reload()
}
const tempo = ref(1)
const anzeige = ref<'tc' | 'frame'>('tc')
const buehnenMass = ref({ b: 0, h: 0 })
const marker = computed(() => props.marker ?? [])
const vollbildMoeglich = typeof document !== 'undefined' && document.fullscreenEnabled === true

const rate = computed(() => ({ num: props.version.fpsNum, den: props.version.fpsDen }))
const timecode = computed(() => vonFrames(props.version.startFrames + frame.value, rate.value, props.version.df))
const anteil = computed(() => frame.value / Math.max(1, props.version.bilder - 1))
const tempoText = computed(() => `${tempo.value.toLocaleString(getCanonicalLocale())}×`)
const fpsText = computed(() => {
	const fps = props.version.fpsNum / props.version.fpsDen
	const text = Number.isInteger(fps) ? String(fps) : fps.toFixed(2).replace('.', ',')
	return `${text}${props.version.df ? ' DF' : ''}`
})

/** Bild so groß wie möglich, ganz sichtbar, im Seitenverhältnis der Datei. */
const bildStil = computed(() => {
	const verhaeltnis = (props.version.breite ?? 16) / (props.version.hoehe ?? 9)
	const { b, h } = buehnenMass.value
	if (b === 0 || h === 0) return { width: '100%', aspectRatio: String(verhaeltnis) }
	const breite = Math.min(b, h * verhaeltnis)
	return { width: `${breite}px`, height: `${breite / verhaeltnis}px` }
})

let beobachter: ResizeObserver | null = null

onMounted(() => {
	if (video.value) {
		uhr.value = new FrameUhr(video.value, rate.value, props.version.bilder, (f) => {
			frame.value = f
			emit('frame', f)
		})
	}
	if (buehne.value) {
		beobachter = new ResizeObserver(([e]) => {
			buehnenMass.value = { b: e.contentRect.width, h: e.contentRect.height }
		})
		beobachter.observe(buehne.value)
	}
	window.addEventListener('keydown', taste)
	// Takt nach dem Vorlauf: bei zwei Stunden alle 15 s, bei kurzen
	// Testlaufzeiten öfter, damit das Fenster zum Tauschen nicht verpasst wird.
	pruefTakt = window.setInterval(adressePruefen, Math.max(1000, Math.min(15 * 1000, vorlaufMs() / 8)))
})

onBeforeUnmount(() => {
	if (pruefTakt !== null) window.clearInterval(pruefTakt)
	uhr.value?.beenden()
	beobachter?.disconnect()
	window.removeEventListener('keydown', taste)
})

function umschalten(): void {
	const v = video.value
	if (!v) return
	if (v.paused) {
		void v.play()
	} else {
		v.pause()
	}
}

function schritt(n: number): void {
	video.value?.pause()
	uhr.value?.schritt(n)
}

function tempoWeiter(): void {
	tempo.value = TEMPI[(TEMPI.indexOf(tempo.value) + 1) % TEMPI.length]
	if (video.value) video.value.playbackRate = tempo.value
}

function tonUmschalten(): void {
	if (video.value) video.value.muted = !video.value.muted
}

function vollbild(): void {
	if (document.fullscreenElement) {
		void document.exitFullscreen()
	} else {
		void wurzel.value?.requestFullscreen()
	}
}

/* Zeitleiste: klicken oder ziehen, das Bild folgt sofort. */
let zieht = false
function frameAus(e: PointerEvent): number {
	const r = leiste.value!.getBoundingClientRect()
	const x = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width))
	return Math.round(x * (props.version.bilder - 1))
}
function ziehenStart(e: PointerEvent): void {
	zieht = true
	leiste.value?.setPointerCapture(e.pointerId)
	video.value?.pause()
	uhr.value?.springeZu(frameAus(e))
}
function ziehenWeiter(e: PointerEvent): void {
	if (zieht) uhr.value?.springeZu(frameAus(e))
}
function ziehenEnde(): void {
	zieht = false
}

/** Springt auf einen Frame und wartet, bis das Bild steht. */
function springeZu(n: number): Promise<void> {
	const v = video.value
	if (!v || !uhr.value) return Promise.resolve()
	v.pause()
	return new Promise((resolve) => {
		const fertig = () => setTimeout(resolve, 50)
		v.addEventListener('seeked', fertig, { once: true })
		uhr.value!.springeZu(n)
	})
}

function taste(e: KeyboardEvent): void {
	// Tippt jemand in ein Feld oder ist ein Dialog offen, gehören die Tasten dorthin.
	const ziel = e.target as HTMLElement | null
	if (ziel?.closest?.('input, textarea, select, [contenteditable], [role=dialog]')) return
	if (e.metaKey || e.ctrlKey || e.altKey) return
	switch (e.key) {
	case 'ArrowLeft':
		schritt(e.shiftKey ? -10 : -1)
		break
	case 'ArrowRight':
		schritt(e.shiftKey ? 10 : 1)
		break
	case ' ':
	case 'k':
		umschalten()
		break
	case 'Home':
		void springeZu(0)
		break
	case 'End':
		void springeZu(props.version.bilder - 1)
		break
	default:
		return
	}
	e.preventDefault()
}

defineExpose({
	springeZu,
	video,
	aktuellerFrame: () => frame.value,
	anhalten: () => video.value?.pause(),
})
</script>

<style scoped>
.vr-player {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	background: var(--vr-buehne);
	/* Anordnung nach der Breite des Players, nicht des Fensters: Im
	   kleinen Rahmen (Dashboard) ist er schmal, das Fenster nicht. */
	container-type: inline-size;
}
.vr-buehne {
	position: relative;
	flex: 1;
	min-height: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	padding: 12px 16px 8px;
}
.vr-kaputt {
	position: absolute;
	inset: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 14px;
	padding: 24px;
	text-align: center;
	background: var(--vr-buehne);
	color: var(--vr-text-2);
	font-size: 14px;
}
.vr-kaputt p {
	margin: 0;
	max-width: 36ch;
}
.vr-kaputt-knopf {
	min-height: 34px;
	padding: 0 14px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche-2);
	color: var(--vr-text);
	font: inherit;
	cursor: pointer;
}
.vr-kaputt-knopf:focus-visible {
	outline: 2px solid var(--vr-akzent);
	outline-offset: 2px;
}
.vr-bild {
	position: relative;
	max-width: 100%;
	max-height: 100%;
}
.vr-video {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	background: #000;
	display: block;
}

/* Zeitleiste über die volle Breite, Treffer höher als die Linie. */
.vr-zeit {
	position: relative;
	height: 22px;
	margin: 0 16px;
	cursor: pointer;
	touch-action: none;
	flex: none;
}
.vr-zeit-spur {
	position: absolute;
	left: 0;
	right: 0;
	top: 50%;
	height: 3px;
	margin-top: -1.5px;
	border-radius: 2px;
	background: var(--vr-flaeche-3);
	overflow: hidden;
	transition: height var(--vr-schnell) var(--vr-ease), margin var(--vr-schnell) var(--vr-ease);
}
.vr-zeit:hover .vr-zeit-spur {
	height: 5px;
	margin-top: -2.5px;
}
.vr-zeit-fuellung {
	height: 100%;
	background: var(--vr-akzent);
}
.vr-zeit-kopf {
	position: absolute;
	top: 50%;
	width: 12px;
	height: 12px;
	margin: -6px 0 0 -6px;
	border-radius: 50%;
	background: #fff;
	box-shadow: 0 0 0 3px rgba(0, 0, 0, .35);
	pointer-events: none;
}
.vr-marke {
	position: absolute;
	top: 0;
	width: 10px;
	height: 10px;
	margin-left: -5px;
	padding: 0;
	border: 2px solid var(--vr-buehne);
	border-radius: 50%;
	background: var(--vr-tc);
	cursor: pointer;
	transition: transform var(--vr-schnell) var(--vr-ease);
}
.vr-marke:hover,
.vr-marke--aktiv {
	transform: scale(1.35);
}
.vr-marke--entwurf {
	background: var(--vr-text-3);
}

.vr-bedienung {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	align-items: center;
	gap: 8px;
	padding: 4px 12px 10px;
	flex: none;
}
.vr-bedienung-links,
.vr-bedienung-rechts {
	display: flex;
	align-items: center;
	gap: 2px;
	min-width: 0;
}
.vr-bedienung-rechts {
	justify-content: flex-end;
}
/* Knöpfe nie zusammendrücken (Handy). */
.vr-bedienung-links > *,
.vr-bedienung-rechts > * {
	flex: none;
}
/* Finger: größere Trefferflächen, Aussehen bleibt gleich. */
@media (pointer: coarse) {
	.vr-zeit {
		height: 32px;
	}
	.vr-marke {
		top: 5px;
	}
	.vr-marke::before {
		content: '';
		position: absolute;
		inset: -11px;
	}
}
.vr-ik--gross {
	font-size: 20px;
	color: var(--vr-text);
}
.vr-ik--info {
	color: var(--vr-tc);
	cursor: help;
}
.vr-text-knopf {
	height: 32px;
	min-width: 44px;
	padding: 0 8px;
	border: 0;
	border-radius: var(--vr-radius-klein);
	background: transparent;
	color: var(--vr-text-2);
	font-size: 13px;
	font-weight: 500;
}
.vr-text-knopf:hover {
	background: var(--vr-flaeche-3);
	color: var(--vr-text);
}
.vr-uhr {
	display: inline-flex;
	align-items: baseline;
	gap: 8px;
	height: 32px;
	padding: 0 12px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche);
	color: var(--vr-text);
	font-size: 15px;
	font-weight: 500;
	line-height: 30px;
	white-space: nowrap;
}
.vr-uhr:hover {
	border-color: var(--vr-text-3);
}
.vr-uhr-fps {
	font-size: 12px;
	color: var(--vr-text-3);
}
/* Unter etwa 640 px passt die Leiste nicht in eine Zeile (Firefox-Test im
   360-px-Rahmen: Zeitanzeige über Tempo und Ton). */
@container (max-width: 640px) {
	.vr-bedienung {
		grid-template-columns: auto 1fr;
	}
	.vr-uhr {
		grid-row: 1;
		grid-column: 1 / -1;
		justify-self: center;
	}
	.vr-buehne {
		padding: 8px;
	}
	.vr-zeit {
		margin: 0 8px;
	}
}
</style>
