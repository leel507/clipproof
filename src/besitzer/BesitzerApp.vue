<!--
  - SPDX-FileCopyrightText: 2026 Schauties Media
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Ansicht des Besitzers in Nextcloud, im selben Aufbau wie die Kundenseite:
	links die Reviews, in der Mitte der Player, rechts die Anmerkungen mit
	Erledigt und Antworten, darunter die Eingabe. Entwürfe von Gästen
	erscheinen hier nie. Texte über t() und n(), Übersetzungen in l10n/.
-->
<template>
	<div class="vrl vr-thema">
		<nav class="vrl-reviews" :aria-label="t('Reviews')">
			<div class="vrl-reviews-kopf">
				<h2>{{ t('Reviews') }}</h2>
				<button type="button" class="vr-ik" :aria-pressed="neuOffen" :aria-label="t('New review')" :title="t('New review')" @click="neuOffen = !neuOffen">
					<span class="vrl-plus" aria-hidden="true">+</span>
				</button>
			</div>
			<form v-if="neuOffen" class="vrl-neu" @submit.prevent="neuAnlegen">
				<input v-model="neuTitel" :placeholder="t('Title (optional)')" :aria-label="t('Title of the new review')">
				<input v-model="neuPfad" :placeholder="t('Path, for example Clients/Company/Project/edit1.mp4')" :aria-label="t('Path of the video file in your files')" required>
				<button type="submit" class="vrl-knopf" :disabled="neuPfad.trim() === ''">{{ t('Create review') }}</button>
			</form>
			<p v-if="liste.length === 0" class="vrl-leise vrl-pad">{{ t('No reviews yet.') }}</p>
			<ul class="vrl-reviewliste">
				<li v-for="r in sortiert" :key="r.id">
					<button type="button" class="vrl-eintrag" :class="{ 'vrl-eintrag--aktiv': r.id === auswahl }" @click="oeffnen(r.id)">
						<span class="vrl-eintrag-titel">{{ r.titel }}</span>
						<span class="vrl-eintrag-info">
							<span v-if="r.archiviert" class="vrl-pille">{{ t('Archive') }}</span>
							<span v-else-if="r.gesperrt" class="vrl-pille">{{ t('disabled') }}</span>
							<span v-else-if="r.freigegeben" class="vrl-pille vrl-pille--ok">{{ t('approved') }}</span>
							<span v-if="r.offen > 0" class="vrl-offen" :aria-label="n('%n open comment', '%n open comments', r.offen)">{{ r.offen }}</span>
						</span>
					</button>
				</li>
			</ul>

			<section class="vrl-ordner" :aria-label="t('Project folders')">
				<div class="vrl-reviews-kopf vrl-ordner-kopf">
					<h2>{{ t('Project folders') }}</h2>
					<button type="button" class="vr-ik" :aria-pressed="ordnerOffen" :aria-label="t('Watch a folder')" :title="t('Watch a folder')" @click="ordnerOffen = !ordnerOffen">
						<span class="vrl-plus" aria-hidden="true">+</span>
					</button>
				</div>
				<form v-if="ordnerOffen" class="vrl-neu" @submit.prevent="ordnerEinschalten">
					<input v-model="ordnerPfad" :placeholder="t('Path, for example Clients/Company/01 Image film')" :aria-label="t('Path of the project folder in your files')" required>
					<button type="submit" class="vrl-knopf" :disabled="ordnerPfad.trim() === ''">{{ t('Watch') }}</button>
					<p class="vrl-leise vrl-klein">{{ t('New videos directly in this folder become reviews automatically. Same film, same name, only the version number changes: Teaser_v1, Teaser_v2. Older versions move to “{previous}”. Master files and raw footage belong in “{assets}”.', { previous: ordnerNamen.vorversionen, assets: ordnerNamen.assets }) }}</p>
				</form>
				<ul>
					<li v-for="o in ordnerListe" :key="o.id" class="vrl-ordner-eintrag" :class="{ 'vrl-ordner-eintrag--aus': !o.aktiv }">
						<div class="vrl-ordner-zeile">
							<span class="vrl-eintrag-titel" :title="o.pfad">{{ o.titel }}</span>
							<button type="button" class="vrl-schalter" role="switch" :aria-checked="o.aktiv" :aria-label="o.aktiv ? t('Stop watching') : t('Start watching')" @click="ordnerSchalten(o)"><span /></button>
						</div>
						<span class="vrl-leise vrl-klein">{{ n('%n video', '%n videos', o.reviews) }}</span>
						<span v-if="o.fehler" class="vrl-ordner-fehler">{{ o.fehler }}</span>
					</li>
				</ul>
			</section>

			<section class="vrl-panel" :aria-label="t('Premiere panel')">
				<div class="vrl-reviews-kopf vrl-ordner-kopf">
					<h2>{{ t('Premiere panel') }}</h2>
				</div>
				<div class="vrl-panel-inhalt">
					<p class="vrl-leise vrl-klein">{{ panel.vorhanden ? t('Key active since {date}', { date: datum(panel.erzeugtAm ?? '') }) : t('No key.') }}</p>
					<div v-if="panelNeu" class="vrl-panel-neu">
						<code>{{ panelNeu }}</code>
						<button type="button" class="vrl-knopf" @click="panelKopieren">{{ panelKopiert ? t('Copied') : t('Copy') }}</button>
						<p class="vrl-leise vrl-klein">{{ t('Visible only now. Paste it in the panel under “Access”. It can only read your reviews.') }}</p>
					</div>
					<div class="vrl-panel-knoepfe">
						<button type="button" class="vrl-knopf" @click="panelErzeugen">{{ panel.vorhanden ? t('New key') : t('Create key') }}</button>
						<button v-if="panel.vorhanden" type="button" class="vrl-knopf" @click="panelWiderrufen">{{ t('Revoke') }}</button>
					</div>
				</div>
			</section>
		</nav>

		<div v-if="details" class="vrl-arbeit">
			<header class="vrl-kopf">
				<h1 class="vrl-titel">{{ details.titel }}</h1>
				<label v-if="details.versionen.length > 1" class="vrl-auswahl">
					<select v-model="versionId" :aria-label="t('Version')">
						<option v-for="v in details.versionen" :key="v.id" :value="v.id">{{ t('Version {number}', { number: v.nummer }) }}</option>
					</select>
					<Symbol name="pfeilUnten" class="vrl-auswahl-pfeil" />
				</label>
				<span v-else class="vrl-leise vrl-klein">{{ t('Version {number}', { number: version?.nummer ?? '' }) }}</span>

				<div class="vrl-kopf-rechts">
					<template v-if="!details.archiviert">
						<span v-if="details.ablaufAm" class="vrl-leise vrl-klein">{{ t('Link valid until {date}', { date: datum(details.ablaufAm) }) }}</span>
						<button v-if="details.link" type="button" class="vrl-knopf vrl-knopf--akzent" @click="kopieren">{{ kopiert ? t('Copied') : t('Copy client link') }}</button>
						<span v-else class="vrl-leise vrl-klein">{{ t('Link disabled') }}</span>
						<button v-if="!details.freigegebenAm" type="button" class="vrl-knopf" @click="freigabe(true)">{{ t('Approve') }}</button>
						<button v-else type="button" class="vrl-knopf" @click="freigabe(false)">{{ t('Withdraw approval') }}</button>
					</template>
					<template v-else>
						<span class="vrl-pille">{{ details.aufbewahrenBis ? t('Archive until {date}', { date: tag(details.aufbewahrenBis) }) : t('Archive') }}</span>
						<button v-if="details.oeffenbar" type="button" class="vrl-knopf" @click="freigabe(false)">{{ t('Reopen') }}</button>
					</template>
					<div class="vrl-menue-rahmen">
						<button type="button" class="vrl-knopf" :aria-expanded="menue === 'export'" @click="menue = menue === 'export' ? null : 'export'">{{ t('Export') }}</button>
						<div v-if="menue === 'export'" class="vrl-menue" role="menu" @click="menue = null">
							<a role="menuitem" :href="exportUrl('edl')" download>{{ t('EDL for Resolve') }}</a>
							<a role="menuitem" :href="exportUrl('fcp7')" download>{{ t('XML for Premiere') }}</a>
							<a role="menuitem" :href="exportUrl('csv')" download>CSV</a>
							<p class="vrl-menue-hinweis">{{ t('Resolve: timeline starting at {timecode}, then Timelines, Import, Timeline Markers from EDL.', { timecode: version?.startTimecode ?? '' }) }}</p>
						</div>
					</div>
					<div class="vrl-menue-rahmen">
						<button type="button" class="vr-ik" :aria-label="t('More actions')" :aria-expanded="menue === 'mehr'" @click="menue = menue === 'mehr' ? null : 'mehr'">
							<span aria-hidden="true">⋯</span>
						</button>
						<div v-if="menue === 'mehr'" class="vrl-menue" role="menu" @click="menue = null">
							<template v-if="!details.archiviert">
								<button type="button" role="menuitem" @click="versionFormOffen = true">{{ t('Add new version') }}</button>
								<button type="button" role="menuitem" @click="erneuern">{{ t('Create new client link') }}</button>
								<button v-if="!details.gesperrt" type="button" role="menuitem" @click="sperren">{{ t('Disable client link') }}</button>
								<button type="button" role="menuitem" @click="anrede(details.anrede === 'du' ? 'sie' : 'du')">{{ details.anrede === 'du' ? t('German: address client formally (Sie)') : t('German: address client informally (du)') }}</button>
							</template>
							<button type="button" role="menuitem" class="vrl-gefahr" @click="loeschen">{{ t('Delete review') }}</button>
						</div>
					</div>
				</div>
			</header>

			<form v-if="versionFormOffen" class="vrl-band" @submit.prevent="neueVersion">
				<input v-model="versionPfad" :placeholder="t('Path of the new edit')" :aria-label="t('Path of the new edit in your files')" required>
				<button type="submit" class="vrl-knopf vrl-knopf--akzent" :disabled="versionPfad.trim() === ''">{{ t('Add') }}</button>
				<button type="button" class="vrl-knopf" @click="versionFormOffen = false">{{ t('Cancel') }}</button>
				<span class="vrl-leise vrl-klein">{{ t('The client link stays the same; clients see the newest version.') }}</span>
			</form>
			<p v-if="hinweisNeuerLink" class="vrl-band vrl-band--warnung">{{ t('The old link no longer works. Please send the new link to your client.') }}</p>
			<p v-if="fehler" class="vrl-band vrl-band--fehler" role="alert">{{ fehler }}</p>

			<div class="vrl-haupt">
				<div class="vrl-spieler">
					<VideoPlayer
						v-if="version && version.videoUrl"
						ref="player"
						:key="version.id"
						:version="(version as Version)"
						:marker="marker"
						:aktiver-marker="ausgewaehlt"
						:erneuern="adresseErneuern"
						@frame="frame = $event"
						@marker="markerGewaehlt">
						<template #ueberlagerung>
							<Zeichenebene :zeichnung="angezeigteZeichnung" :aktiv="false" />
						</template>
					</VideoPlayer>
					<div v-else class="vrl-ohne-video">
						<p>{{ details.archiviert ? t('Archived videos are no longer played. Comments and export remain.') : t('Video not available.') }}</p>
					</div>
				</div>

				<aside class="vrl-spalte">
					<div class="vrl-reiter" role="tablist">
						<button type="button" role="tab" :aria-selected="reiter === 'anmerkungen'" @click="reiter = 'anmerkungen'">
							{{ t('Comments') }} <span class="vrl-zahl">{{ offenImVideo }}</span>
						</button>
						<button type="button" role="tab" :aria-selected="reiter === 'abgaben'" @click="reiter = 'abgaben'">
							{{ t('Submissions') }} <span class="vrl-zahl">{{ details.abgaben.length }}</span>
						</button>
					</div>

					<template v-if="reiter === 'anmerkungen'">
						<label class="vrl-filter">
							<input v-model="erledigteAusblenden" type="checkbox">
							{{ t('Hide done') }}
						</label>
						<div ref="listeEl" class="vrl-liste">
							<p v-if="faeden.length === 0" class="vrl-leer">{{ t('No sent comments yet.') }}</p>
							<ol>
								<li v-for="f in faeden" :key="f.haupt.id" :data-kommentar="f.haupt.id">
									<article
										v-for="k in [f.haupt, ...f.antworten]"
										:key="k.id"
										class="vrl-k"
										:class="{ 'vrl-k--antwort': k.parentId !== null, 'vrl-k--gewaehlt': ausgewaehlt === k.id, 'vrl-k--erledigt': k.erledigt }"
										@click="zeigen(k)">
										<div class="vrl-k-kopf">
											<button
												v-if="k.autorTyp === 'kunde' && k.parentId === null"
												type="button"
												class="vrl-haken"
												:class="{ 'vrl-haken--an': k.erledigt }"
												:aria-pressed="k.erledigt"
												:aria-label="k.erledigt ? t('Mark as open') : t('Mark as done')"
												:title="k.erledigt ? t('Done. The client sees “Handled by {owner}”.', { owner: ich }) : t('Mark as done. The client then sees “Handled by {owner}”.', { owner: ich })"
												:disabled="details.archiviert"
												@click.stop="erledigt(k, !k.erledigt)">
												<Symbol name="haken" />
											</button>
											<span v-else class="vrl-avatar" :class="{ 'vrl-avatar--besitzer': k.autorTyp === 'besitzer' }">{{ initial(k.autorTyp === 'besitzer' ? ich : k.autor) }}</span>
											<span class="vrl-k-autor">{{ k.autorTyp === 'besitzer' ? t('You') : k.autor }}</span>
											<span class="vrl-k-zeit">{{ k.abgesendetAm ? datum(k.abgesendetAm) : datum(k.erstelltAm) }}</span>
											<span v-if="k.parentId === null" class="vrl-k-nr">#{{ f.nummer }}</span>
										</div>
										<p class="vrl-k-text">
											<span v-if="k.parentId === null" class="vrl-tc vr-tc">{{ k.timecode }}</span>
											<span v-if="k.zeichnung" class="vrl-k-zeichen" :title="t('with drawing')"><Symbol name="stift" /></span>
											{{ k.text }}
										</p>
										<div v-if="k.parentId === null && !details.archiviert" class="vrl-k-fuss">
											<button type="button" class="vrl-link" @click.stop="antworten(k)">{{ t('Reply') }}</button>
										</div>
									</article>
								</li>
							</ol>
						</div>

						<form v-if="version && !details.archiviert" class="vrl-eingabe" @submit.prevent="kommentieren">
							<div v-if="antwortAuf" class="vrl-antwort-auf">
								<Symbol name="antworten" />
								<span>{{ t('Reply to {name}', { name: antwortAuf.autor }) }}</span>
								<button type="button" class="vr-ik vrl-ik-klein" :aria-label="t('Cancel')" @click="antwortAuf = null"><Symbol name="schliessen" /></button>
							</div>
							<div class="vrl-feld">
								<span class="vrl-tc vr-tc">{{ eingabeTimecode }}</span>
								<textarea
									ref="feld"
									v-model="text"
									rows="1"
									:placeholder="antwortAuf ? t('Write a reply …') : t('Write a comment …')"
									:aria-label="t('Comment')"
									@focus="player?.anhalten()"
									@input="hoeheAnpassen"
									@keydown="feldTaste" />
								<button type="submit" class="vrl-senden" :disabled="text.trim() === ''" :aria-label="t('Send')" :title="t('Send')"><Symbol name="senden" /></button>
							</div>
							<p class="vrl-leise vrl-klein vrl-hinweis">{{ t('Enter sends, clients see it right away · Shift+Enter new line') }}</p>
						</form>
					</template>

					<div v-else class="vrl-liste">
						<p v-if="details.abgaben.length === 0" class="vrl-leer">{{ t('No submissions yet.') }}</p>
						<ul class="vrl-abgaben">
							<li v-for="a in details.abgaben" :key="a.id">
								<span class="vrl-klein">{{ datum(a.am) }} · {{ n('%n comment', '%n comments', a.anzahl) }}</span>
							</li>
						</ul>
					</div>
				</aside>
			</div>
		</div>
		<div v-else class="vrl-arbeit vrl-mitte">
			<p class="vrl-leise">{{ t('Choose a review on the left.') }}</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { getCanonicalLocale, n as nTr, t as tTr } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import VideoPlayer from '../gemeinsam/VideoPlayer.vue'
import Zeichenebene from '../gemeinsam/Zeichenebene.vue'
import Symbol from '../gemeinsam/Symbol.vue'
import { vonFrames } from '../lib/timecode'
import { schriftenLaden } from '../gemeinsam/schriften'
import { besitzerApi, type ProjektOrdner, type ReviewDetails, type ReviewKurz } from './api'
import type { Kommentar, Marker, Version, Zeichnung } from '../gemeinsam/typen'

schriftenLaden()

const t = (s: string, v: Record<string, string | number> = {}): string => tTr('clipproof', s, v)
// Unterordner der Projektordner in der vom Admin eingestellten Sprache
const ordnerNamen = loadState<{ vorversionen: string, assets: string }>('clipproof', 'ordnernamen', { vorversionen: 'Previous versions', assets: 'Assets' })
const n = (eins: string, viele: string, zahl: number, v: Record<string, string | number> = {}): string => nTr('clipproof', eins, viele, zahl, v)
/** Anzeigename des angemeldeten Nutzers: so sehen Gäste den Besitzer. */
const ich = getCurrentUser()?.displayName || getCurrentUser()?.uid || ''

const liste = ref<ReviewKurz[]>([])
const auswahl = ref<number | null>(null)
const details = ref<ReviewDetails | null>(null)
const versionId = ref<number | null>(null)
const player = ref<InstanceType<typeof VideoPlayer> | null>(null)
const listeEl = ref<HTMLElement | null>(null)
const feld = ref<HTMLTextAreaElement | null>(null)
const frame = ref(0)
const text = ref('')
const antwortAuf = ref<Kommentar | null>(null)
const ausgewaehlt = ref<number | null>(null)
const fehler = ref('')
const kopiert = ref(false)
const hinweisNeuerLink = ref(false)
const neuOffen = ref(false)
const neuTitel = ref('')
const neuPfad = ref('')
const versionFormOffen = ref(false)
const versionPfad = ref('')
const menue = ref<'export' | 'mehr' | null>(null)
const reiter = ref<'anmerkungen' | 'abgaben'>('anmerkungen')
const erledigteAusblenden = ref(false)
const ordnerListe = ref<ProjektOrdner[]>([])
const ordnerOffen = ref(false)
const ordnerPfad = ref('')
const panel = ref<{ vorhanden: boolean, erzeugtAm: string | null }>({ vorhanden: false, erzeugtAm: null })
const panelNeu = ref('')
const panelKopiert = ref(false)

const sortiert = computed(() => [...liste.value].sort((a, b) => b.geaendertAm.localeCompare(a.geaendertAm) || a.titel.localeCompare(b.titel, 'de')))
const version = computed(() => details.value?.versionen.find((v) => v.id === versionId.value) ?? null)
const imVideo = computed(() => (details.value?.kommentare ?? []).filter((k) => k.versionId === versionId.value))
const alleFaeden = computed(() => imVideo.value
	.filter((k) => k.parentId === null)
	.map((h, i) => ({ haupt: h, nummer: i + 1, antworten: imVideo.value.filter((k) => k.parentId === h.id) })))
const faeden = computed(() => alleFaeden.value.filter((f) => !(erledigteAusblenden.value && f.haupt.erledigt)))
const offenImVideo = computed(() => alleFaeden.value.filter((f) => f.haupt.autorTyp === 'kunde' && !f.haupt.erledigt).length)
const marker = computed<Marker[]>(() => alleFaeden.value.map((f) => ({
	id: f.haupt.id,
	frame: f.haupt.frameIn,
	titel: `#${f.nummer} ${f.haupt.timecode} ${f.haupt.autorTyp === 'besitzer' ? t('You') : f.haupt.autor}`,
	entwurf: f.haupt.erledigt,
})))
const tcVon = (f: number) => {
	const v = version.value
	return v ? vonFrames(v.startFrames + f, { num: v.fpsNum, den: v.fpsDen }, v.df) : ''
}
const eingabeTimecode = computed(() => tcVon(antwortAuf.value?.frameIn ?? frame.value))
const angezeigteZeichnung = computed<Zeichnung | null>(() => {
	const k = imVideo.value.find((x) => x.id === ausgewaehlt.value)
	return k && k.frameIn === frame.value ? k.zeichnung : null
})

function menueSchliessen(e: MouseEvent): void {
	if (!(e.target as HTMLElement).closest('.vrl-menue-rahmen')) menue.value = null
}

onMounted(async () => {
	document.addEventListener('click', menueSchliessen)
	liste.value = await besitzerApi.liste()
	ordnerListe.value = await besitzerApi.ordner().catch(() => [])
	panel.value = await besitzerApi.panelStatus().catch(() => panel.value)
	// Aus der Dateien-App: „Im Clipproof öffnen“ (?review=<id>).
	const gewuenscht = Number(new URLSearchParams(location.search).get('review'))
	if (gewuenscht > 0 && liste.value.some((r) => r.id === gewuenscht)) await oeffnen(gewuenscht)
})

async function panelErzeugen(): Promise<void> {
	if (panel.value.vorhanden && !window.confirm(t('Create a new key? The current one stops working immediately.'))) return
	const r = await besitzerApi.panelErzeugen()
	panelNeu.value = r.schluessel
	panel.value = { vorhanden: r.vorhanden, erzeugtAm: r.erzeugtAm }
}

async function panelWiderrufen(): Promise<void> {
	if (!window.confirm(t('Revoke the panel key? The panel can then no longer read anything.'))) return
	panel.value = await besitzerApi.panelWiderrufen()
	panelNeu.value = ''
}

async function panelKopieren(): Promise<void> {
	await navigator.clipboard.writeText(panelNeu.value)
	panelKopiert.value = true
	setTimeout(() => { panelKopiert.value = false; panelNeu.value = '' }, 4000)
}
onBeforeUnmount(() => document.removeEventListener('click', menueSchliessen))

watch(versionId, () => {
	ausgewaehlt.value = null
	antwortAuf.value = null
})

async function ordnerEinschalten(): Promise<void> {
	fehler.value = ''
	try {
		await besitzerApi.ordnerEinschalten(ordnerPfad.value)
		ordnerPfad.value = ''
		ordnerOffen.value = false
		ordnerListe.value = await besitzerApi.ordner()
		liste.value = await besitzerApi.liste()
	} catch (e) {
		window.alert((e as Error).message)
	}
}

async function ordnerSchalten(o: ProjektOrdner): Promise<void> {
	try {
		await besitzerApi.ordnerSchalten(o.id, !o.aktiv)
		ordnerListe.value = await besitzerApi.ordner()
		liste.value = await besitzerApi.liste()
	} catch (e) {
		window.alert((e as Error).message)
	}
}

/** Neue, kurzlebige Video-Adresse, ohne die übrige Ansicht neu zu laden. */
async function adresseErneuern(): Promise<{ videoUrl: string, videoGueltigFuer: number } | null> {
	if (auswahl.value === null) return null
	const neu = (await besitzerApi.details(auswahl.value)).versionen.find((v) => v.id === versionId.value)
	return neu?.videoUrl ? { videoUrl: neu.videoUrl, videoGueltigFuer: neu.videoGueltigFuer } : null
}

async function oeffnen(id: number): Promise<void> {
	auswahl.value = id
	hinweisNeuerLink.value = false
	versionFormOffen.value = false
	ausgewaehlt.value = null
	antwortAuf.value = null
	await uebernehmen(besitzerApi.details(id))
	versionId.value = details.value?.versionen.at(-1)?.id ?? null
}

async function uebernehmen(p: Promise<ReviewDetails>): Promise<void> {
	fehler.value = ''
	try {
		details.value = await p
	} catch (e) {
		fehler.value = (e as Error).message
	}
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
		void kommentieren()
	} else if (e.key === 'Escape') {
		if (antwortAuf.value) antwortAuf.value = null
		else feld.value?.blur()
	}
}

async function kommentieren(): Promise<void> {
	if (!details.value || !version.value || text.value.trim() === '') return
	await uebernehmen(besitzerApi.kommentieren(details.value.id, {
		versionId: version.value.id,
		frameIn: antwortAuf.value?.frameIn ?? player.value?.aktuellerFrame() ?? frame.value,
		text: text.value.trim(),
		parentId: antwortAuf.value?.id ?? null,
	}))
	if (!fehler.value) {
		text.value = ''
		antwortAuf.value = null
		await nextTick()
		hoeheAnpassen()
	}
}

async function antworten(k: Kommentar): Promise<void> {
	antwortAuf.value = k
	await zeigen(k)
	feld.value?.focus()
}

async function zeigen(k: Kommentar): Promise<void> {
	ausgewaehlt.value = k.parentId ?? k.id
	await player.value?.springeZu(k.frameIn)
}

async function markerGewaehlt(id: number): Promise<void> {
	const k = imVideo.value.find((x) => x.id === id)
	if (!k) return
	await zeigen(k)
	listeEl.value?.querySelector(`[data-kommentar="${id}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}

async function neuAnlegen(): Promise<void> {
	fehler.value = ''
	try {
		const neu = await besitzerApi.anlegen(neuTitel.value, neuPfad.value)
		neuTitel.value = ''
		neuPfad.value = ''
		neuOffen.value = false
		liste.value = await besitzerApi.liste()
		await oeffnen(neu.id)
	} catch (e) {
		fehler.value = (e as Error).message
		if (!details.value) window.alert(fehler.value)
	}
}

async function neueVersion(): Promise<void> {
	if (!details.value) return
	await uebernehmen(besitzerApi.versionAnhaengen(details.value.id, versionPfad.value))
	if (!fehler.value) {
		versionPfad.value = ''
		versionFormOffen.value = false
		versionId.value = details.value?.versionen.at(-1)?.id ?? null
	}
}

async function erledigt(k: Kommentar, wert: boolean): Promise<void> {
	if (details.value) await uebernehmen(besitzerApi.erledigt(details.value.id, k.id, wert))
	liste.value = await besitzerApi.liste()
}

async function erneuern(): Promise<void> {
	if (!details.value) return
	if (!window.confirm(t('Create a new client link? The current link stops working immediately.'))) return
	await uebernehmen(besitzerApi.erneuern(details.value.id))
	hinweisNeuerLink.value = !fehler.value
}

/** Freigeben sperrt das Kommentieren; der Link läuft danach ab. */
async function freigabe(freigegeben: boolean): Promise<void> {
	if (!details.value) return
	if (freigegeben && !window.confirm(t('Approve? Your client can no longer write comments afterwards. The link expires after that.'))) return
	await uebernehmen(besitzerApi.freigabe(details.value.id, freigegeben))
	liste.value = await besitzerApi.liste()
}

async function anrede(wert: 'du' | 'sie'): Promise<void> {
	if (!details.value) return
	await uebernehmen(besitzerApi.anrede(details.value.id, wert))
}

async function sperren(): Promise<void> {
	if (!details.value) return
	if (!window.confirm(t('Disable the client link? Clients can then no longer open the review.'))) return
	await uebernehmen(besitzerApi.sperren(details.value.id))
	liste.value = await besitzerApi.liste()
}

/**
 * Löscht das Review mit Anmerkungen, Gästen und Abgaben. Die Videodatei
 * bleibt. Nicht rückgängig zu machen, deshalb die Rückfrage.
 */
async function loeschen(): Promise<void> {
	if (!details.value) return
	const titel = details.value.titel
	// Abgesendete Anmerkungen können Aufbewahrungspflichten unterliegen.
	const pflicht = details.value.abgaben.length > 0
		? ' ' + t('Note: sent comments may be subject to retention obligations. Export them first.')
		: ''
	if (!window.confirm(t('Delete “{title}” with all comments, guests and submissions? The video file stays. This cannot be undone.', { title: titel }) + pflicht)) return
	try {
		await besitzerApi.loeschen(details.value.id)
		details.value = null
		auswahl.value = null
		liste.value = await besitzerApi.liste()
	} catch (e) {
		fehler.value = (e as Error).message
	}
}

async function kopieren(): Promise<void> {
	if (!details.value?.link) return
	await navigator.clipboard.writeText(details.value.link)
	kopiert.value = true
	setTimeout(() => { kopiert.value = false }, 2000)
}

function exportUrl(format: 'edl' | 'fcp7' | 'csv'): string {
	return generateUrl('/apps/clipproof/export/{id}/{versionId}/{format}', { id: details.value?.id ?? 0, versionId: versionId.value ?? 0, format })
}

function initial(name: string): string {
	return (name.trim()[0] ?? '?').toUpperCase()
}

/** Kalendertag in der Sprache des Nutzers, ohne Zeitzonenverschiebung. */
function tag(jjjjmmtt: string | null): string {
	if (!jjjjmmtt) return ''
	const [j, m, d] = jjjjmmtt.split('-').map(Number)
	return new Date(Date.UTC(j, m - 1, d)).toLocaleDateString(getCanonicalLocale(), { timeZone: 'UTC', day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Zeitpunkt in Sprache und Zeitzone des Browsers. */
function datum(iso: string): string {
	return new Date(iso).toLocaleString(getCanonicalLocale(), { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}
</script>

<style>
/* Die Seite füllt den Inhaltsbereich von Nextcloud ganz aus. */
#content:has(.vrl-wurzel) {
	overflow: hidden;
}
.vrl-wurzel {
	width: 100%;
	height: 100%;
}
</style>

<style scoped>
.vrl {
	display: grid;
	grid-template-columns: 260px minmax(0, 1fr);
	width: 100%;
	height: 100%;
	min-height: 0;
	background: var(--vr-grund);
	border-radius: inherit;
	overflow: hidden;
}
.vrl-leise {
	color: var(--vr-text-3);
}
.vrl-klein {
	font-size: 12.5px;
}
.vrl-pad {
	padding: 8px 16px;
}
.vrl-mitte {
	display: flex;
	align-items: center;
	justify-content: center;
}

/* Linke Leiste */
.vrl-reviews {
	display: flex;
	flex-direction: column;
	min-height: 0;
	background: var(--vr-flaeche);
	border-right: 1px solid var(--vr-linie);
}
.vrl-reviews-kopf {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 48px;
	padding: 0 8px 0 16px;
	border-bottom: 1px solid var(--vr-linie);
	flex: none;
}
.vrl-reviews-kopf h2 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--vr-text);
}
.vrl-plus {
	font-size: 20px;
	line-height: 1;
}
.vrl-reviews ul {
	list-style: none;
	margin: 0;
	padding: 6px;
	overflow-y: auto;
}
.vrl-reviewliste {
	flex: 1;
	min-height: 80px;
}
.vrl-ordner {
	flex: none;
	max-height: 45%;
	display: flex;
	flex-direction: column;
	border-top: 1px solid var(--vr-linie);
}
.vrl-panel {
	flex: none;
	border-top: 1px solid var(--vr-linie);
}
.vrl-panel-inhalt {
	padding: 4px 16px 12px;
}
.vrl-panel-neu code {
	display: block;
	margin: 6px 0;
	padding: 6px 8px;
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche-2);
	color: var(--vr-tc);
	font-size: 12px;
	word-break: break-all;
	user-select: all;
}
.vrl-panel-knoepfe {
	display: flex;
	gap: 6px;
	margin-top: 6px;
}
.vrl-ordner-kopf {
	border-top: 0;
}
.vrl-ordner-eintrag {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 8px 10px;
	border-radius: var(--vr-radius-klein);
}
.vrl-ordner-eintrag--aus .vrl-eintrag-titel {
	color: var(--vr-text-3);
}
.vrl-ordner-zeile {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13.5px;
	font-weight: 500;
	color: var(--vr-text);
}
.vrl-ordner-fehler {
	font-size: 12px;
	color: var(--vr-tc);
}
.vrl-schalter {
	flex: none;
	position: relative;
	width: 32px;
	height: 18px;
	padding: 0;
	border: 0;
	border-radius: 999px;
	background: var(--vr-flaeche-3);
	transition: background var(--vr-schnell) var(--vr-ease);
}
.vrl-schalter span {
	position: absolute;
	top: 2px;
	left: 2px;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	background: var(--vr-text-2);
	transition: transform var(--vr-schnell) var(--vr-ease), background var(--vr-schnell) var(--vr-ease);
}
.vrl-schalter[aria-checked="true"] {
	background: var(--vr-akzent);
}
.vrl-schalter[aria-checked="true"] span {
	transform: translateX(14px);
	background: #fff;
}
.vrl-eintrag {
	width: 100%;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
	padding: 8px 10px;
	border: 0;
	border-radius: var(--vr-radius-klein);
	background: transparent;
	color: var(--vr-text-2);
	text-align: left;
}
.vrl-eintrag:hover {
	background: var(--vr-flaeche-2);
	color: var(--vr-text);
}
.vrl-eintrag--aktiv {
	background: var(--vr-flaeche-3);
	color: var(--vr-text);
}
.vrl-eintrag-titel {
	width: 100%;
	font-size: 13.5px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.vrl-eintrag-info {
	display: flex;
	gap: 6px;
	align-items: center;
}
.vrl-eintrag-info:empty {
	display: none;
}
.vrl-pille {
	display: inline-block;
	padding: 1px 7px;
	border-radius: 999px;
	background: var(--vr-flaeche-3);
	color: var(--vr-text-2);
	font-size: 11.5px;
	font-weight: 500;
	white-space: nowrap;
}
.vrl-pille--ok {
	background: rgba(60, 207, 142, .14);
	color: var(--vr-ok);
}
.vrl-offen {
	min-width: 18px;
	height: 18px;
	padding: 0 5px;
	border-radius: 999px;
	background: var(--vr-akzent);
	color: #fff;
	font-size: 11px;
	font-weight: 600;
	line-height: 18px;
	text-align: center;
}
.vrl-neu {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--vr-linie);
}
.vrl-neu input,
.vrl-band input {
	height: 32px;
	padding: 0 10px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche-2);
	color: var(--vr-text);
	font-size: 13px;
}
.vrl-neu input:focus,
.vrl-band input:focus {
	outline: none;
	border-color: var(--vr-akzent);
}

/* Arbeitsbereich */
.vrl-arbeit {
	display: flex;
	flex-direction: column;
	min-width: 0;
	min-height: 0;
}
.vrl-kopf {
	display: flex;
	align-items: center;
	gap: 12px;
	height: 48px;
	padding: 0 12px 0 16px;
	border-bottom: 1px solid var(--vr-linie);
	flex: none;
	min-width: 0;
}
.vrl-titel {
	margin: 0;
	font-family: var(--vr-titel);
	font-stretch: 125%;
	font-size: 16px;
	font-weight: 600;
	color: var(--vr-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	min-width: 0;
}
.vrl-auswahl {
	position: relative;
	display: inline-flex;
	align-items: center;
	flex: none;
}
.vrl-auswahl select {
	appearance: none;
	height: 28px;
	margin: 0;
	padding: 0 28px 0 10px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche);
	color: var(--vr-text-2);
	font-size: 13px;
	font-weight: 500;
}
.vrl-auswahl-pfeil {
	position: absolute;
	right: 8px;
	pointer-events: none;
	color: var(--vr-text-3);
}
.vrl-kopf-rechts {
	margin-left: auto;
	display: flex;
	align-items: center;
	gap: 8px;
	flex: none;
}
.vrl-knopf {
	height: 30px;
	padding: 0 12px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius-klein);
	background: var(--vr-flaeche);
	color: var(--vr-text);
	font-size: 13px;
	font-weight: 500;
	white-space: nowrap;
}
.vrl-knopf:hover {
	border-color: var(--vr-text-3);
}
.vrl-knopf:disabled {
	opacity: .4;
}
.vrl-knopf--akzent {
	border-color: transparent;
	background: var(--vr-akzent);
	color: #fff;
}
.vrl-knopf--akzent:hover {
	background: var(--vr-akzent-hover);
	border-color: transparent;
}
.vrl-menue-rahmen {
	position: relative;
}
.vrl-menue {
	position: absolute;
	right: 0;
	top: calc(100% + 6px);
	z-index: 20;
	min-width: 230px;
	padding: 6px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius);
	background: var(--vr-flaeche-2);
	box-shadow: 0 16px 40px rgba(0, 0, 0, .5);
	display: flex;
	flex-direction: column;
}
.vrl-menue a,
.vrl-menue button {
	display: block;
	width: 100%;
	padding: 8px 10px;
	border: 0;
	border-radius: var(--vr-radius-klein);
	background: transparent;
	color: var(--vr-text);
	font-size: 13.5px;
	text-align: left;
	text-decoration: none;
}
.vrl-menue a:hover,
.vrl-menue button:hover {
	background: var(--vr-flaeche-3);
}
.vrl-menue .vrl-gefahr {
	color: var(--vr-fehler);
}
.vrl-menue-hinweis {
	margin: 4px 10px 6px;
	font-size: 12px;
	color: var(--vr-text-3);
}
.vrl-band {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin: 0;
	padding: 8px 16px;
	border-bottom: 1px solid var(--vr-linie);
	background: var(--vr-flaeche);
	font-size: 13px;
}
.vrl-band input {
	flex: 1;
	min-width: 240px;
}
.vrl-band--warnung {
	color: var(--vr-tc);
}
.vrl-band--fehler {
	color: var(--vr-fehler);
}

.vrl-haupt {
	flex: 1;
	min-height: 0;
	display: grid;
	grid-template-columns: minmax(0, 1fr) 360px;
}
.vrl-spieler {
	min-width: 0;
	min-height: 0;
}
.vrl-ohne-video {
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 24px;
	background: var(--vr-buehne);
	color: var(--vr-text-2);
	text-align: center;
}

/* Rechte Spalte */
.vrl-spalte {
	display: flex;
	flex-direction: column;
	min-height: 0;
	background: var(--vr-flaeche);
	border-left: 1px solid var(--vr-linie);
}
.vrl-reiter {
	display: flex;
	gap: 4px;
	padding: 8px;
	border-bottom: 1px solid var(--vr-linie);
	flex: none;
}
.vrl-reiter button {
	flex: 1;
	height: 32px;
	border: 0;
	border-radius: var(--vr-radius-klein);
	background: transparent;
	color: var(--vr-text-2);
	font-size: 13px;
	font-weight: 500;
}
.vrl-reiter button[aria-selected="true"] {
	background: var(--vr-flaeche-3);
	color: var(--vr-text);
}
.vrl-zahl {
	margin-left: 4px;
	color: var(--vr-text-3);
	font-size: 12px;
}
.vrl-filter {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	border-bottom: 1px solid var(--vr-linie);
	font-size: 12.5px;
	color: var(--vr-text-2);
	cursor: pointer;
	flex: none;
}
.vrl-filter input {
	accent-color: var(--vr-akzent);
	margin: 0;
	min-height: 0;
}
.vrl-liste {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	overscroll-behavior: contain;
}
.vrl-liste ol {
	list-style: none;
	margin: 0;
	padding: 0;
}
.vrl-liste li + li {
	border-top: 1px solid var(--vr-linie);
}
.vrl-leer {
	padding: 24px 16px;
	text-align: center;
	color: var(--vr-text-3);
	font-size: 13px;
}
.vrl-k {
	padding: 12px 16px;
	border-left: 2px solid transparent;
	cursor: pointer;
}
.vrl-k:hover {
	background: var(--vr-flaeche-2);
}
.vrl-k--gewaehlt {
	background: var(--vr-flaeche-2);
	border-left-color: var(--vr-tc);
}
.vrl-k--antwort {
	padding-left: 50px;
	padding-top: 4px;
}
.vrl-k--erledigt .vrl-k-text,
.vrl-k--erledigt .vrl-k-autor {
	color: var(--vr-text-3);
}
.vrl-k-kopf {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}
.vrl-haken,
.vrl-avatar {
	flex: none;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: 600;
}
.vrl-haken {
	padding: 0;
	border: 1.5px solid var(--vr-text-3);
	background: transparent;
	color: transparent;
	font-size: 14px;
	transition: background var(--vr-schnell) var(--vr-ease), border-color var(--vr-schnell) var(--vr-ease), color var(--vr-schnell) var(--vr-ease);
}
.vrl-haken:hover {
	border-color: var(--vr-ok);
	color: var(--vr-ok);
}
.vrl-haken--an {
	border-color: var(--vr-ok);
	background: var(--vr-ok);
	color: #0c0e11;
}
.vrl-haken--an:hover {
	color: #0c0e11;
}
.vrl-avatar {
	background: var(--vr-flaeche-3);
	color: var(--vr-text);
}
.vrl-avatar--besitzer {
	background: var(--vr-akzent-tief);
	color: #fff;
}
.vrl-k--antwort .vrl-avatar {
	width: 20px;
	height: 20px;
	font-size: 10.5px;
}
.vrl-k-autor {
	font-weight: 600;
	font-size: 13.5px;
	color: var(--vr-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.vrl-k-zeit,
.vrl-k-nr {
	font-size: 12px;
	color: var(--vr-text-3);
	white-space: nowrap;
}
.vrl-k-nr {
	margin-left: auto;
}
.vrl-k-text {
	margin: 4px 0 0 32px;
	color: var(--vr-text);
	font-size: 14px;
	line-height: 1.5;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
.vrl-k--antwort .vrl-k-text {
	margin-left: 28px;
}
.vrl-tc {
	display: inline-block;
	margin-right: 6px;
	padding: 0 6px;
	border-radius: 4px;
	background: var(--vr-tc-grund);
	color: var(--vr-tc);
	font-size: 12.5px;
	font-weight: 600;
	line-height: 20px;
}
.vrl-k-zeichen {
	display: inline-flex;
	vertical-align: -2px;
	margin-right: 6px;
	color: var(--vr-text-2);
}
.vrl-k-fuss {
	margin: 6px 0 0 32px;
	opacity: 0;
	transition: opacity var(--vr-schnell) var(--vr-ease);
}
.vrl-k:hover .vrl-k-fuss,
.vrl-k--gewaehlt .vrl-k-fuss {
	opacity: 1;
}
.vrl-link {
	padding: 0;
	border: 0;
	background: none;
	color: var(--vr-text-2);
	font-size: 12.5px;
	font-weight: 500;
}
.vrl-link:hover {
	color: var(--vr-text);
	text-decoration: underline;
	text-underline-offset: 3px;
}
.vrl-abgaben {
	list-style: none;
	margin: 0;
	padding: 0;
}
.vrl-abgaben li {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--vr-linie);
	color: var(--vr-text-2);
}

/* Eingabe */
.vrl-eingabe {
	flex: none;
	padding: 12px;
	border-top: 1px solid var(--vr-linie);
}
.vrl-antwort-auf {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 8px;
	color: var(--vr-text-2);
	font-size: 12.5px;
}
.vrl-antwort-auf span {
	flex: 1;
}
.vrl-ik-klein {
	width: 24px;
	height: 24px;
	font-size: 14px;
}
.vrl-feld {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 8px 8px 8px 10px;
	border: 1px solid var(--vr-linie);
	border-radius: var(--vr-radius);
	background: var(--vr-flaeche-2);
}
.vrl-feld:focus-within {
	border-color: var(--vr-akzent);
}
.vrl-feld .vrl-tc {
	flex: none;
	margin: 1px 0 0;
}
.vrl-feld textarea {
	flex: 1;
	min-width: 0;
	min-height: 22px;
	max-height: 160px;
	margin: 0;
	padding: 0;
	border: 0;
	outline: none;
	resize: none;
	background: transparent;
	color: var(--vr-text);
	font-size: 14px;
	line-height: 22px;
	box-shadow: none;
}
.vrl-feld textarea::placeholder {
	color: var(--vr-text-3);
}
.vrl-senden {
	flex: none;
	width: 30px;
	height: 30px;
	margin: -4px 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border: 0;
	border-radius: var(--vr-radius-klein);
	background: var(--vr-akzent);
	color: #fff;
	font-size: 15px;
}
.vrl-senden:disabled {
	opacity: .35;
}
.vrl-hinweis {
	margin: 6px 2px 0;
}

@media (max-width: 1100px) {
	.vrl {
		grid-template-columns: 200px minmax(0, 1fr);
	}
	.vrl-haupt {
		grid-template-columns: minmax(0, 1fr) 300px;
	}
}
@media (max-width: 800px) {
	.vrl {
		grid-template-columns: minmax(0, 1fr);
		grid-template-rows: auto minmax(0, 1fr);
	}
	.vrl-reviews ul {
		max-height: 140px;
	}
	.vrl-haupt {
		grid-template-columns: minmax(0, 1fr);
		grid-template-rows: minmax(220px, 50%) minmax(0, 1fr);
	}
}
</style>
