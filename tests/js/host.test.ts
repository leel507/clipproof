/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// @vitest-environment node
/**
 * host.jsx gegen eine nachgebaute Marker-Schnittstelle von Premiere: ein
 * ganzer Abgleich, ein zweiter ändert nichts, fremde Marker bleiben.
 * Premiere selbst lässt sich hier nicht starten; das wird von Hand geprüft.
 */
import { describe, expect, it } from 'vitest'
import A from '../../premiere-panel/js/abgleich.js'
import quelle from '../../premiere-panel/jsx/host.jsx?raw'

interface M { guid: string, name: string, comments: string, start: { ticks: string }, end: { ticks: string }, farbe: number, setColorByIndex(i: number): void, getColorByIndex(): number }

/**
 * streng: wie Premiere 26.5.2 vermutlich, gemessen 18.10.2026: „Illegal
 * Parameter type“): start/end nehmen nur Sekunden, kein Zeitobjekt, und
 * rechnen mit Gleitkomma in Ticks um. farbeVerboten: setColorByIndex wirft.
 */
function premiere(opt: { streng?: boolean, farbeVerboten?: boolean } = {}) {
	const liste: M[] = []
	let n = 0
	const marker = (sek: number): M => {
		const zeit = { start: { ticks: String(Math.round(sek * 254016000000)) }, end: { ticks: '0' } }
		const m = {
			guid: 'g' + (++n), name: '', comments: '', farbe: 0,
			setColorByIndex(i: number) {
				if (opt.farbeVerboten) throw new Error('Illegal Parameter type')
				this.farbe = i
			},
			getColorByIndex() { return this.farbe },
		} as unknown as M
		for (const feld of ['start', 'end'] as const) {
			Object.defineProperty(m, feld, {
				enumerable: true,
				get: () => zeit[feld],
				set: (w: unknown) => {
					if (typeof w === 'number') {
						zeit[feld] = { ticks: String(Math.round(w * 254016000000)) }
					} else if (opt.streng) {
						throw new Error('Illegal Parameter type')
					} else {
						zeit[feld] = w as { ticks: string }
					}
				},
			})
		}
		liste.push(m)
		return m
	}
	const markers = {
		getFirstMarker: () => liste[0] ?? null,
		getNextMarker: (m: M) => liste[liste.indexOf(m) + 1] ?? null,
		createMarker: marker,
		deleteMarker: (m: M) => { liste.splice(liste.indexOf(m), 1) },
	}
	const seq = { sequenceID: 'seq-1', name: 'Imagefilm', timebase: '10160640000', markers, setPlayerPosition: () => undefined }
	class Time { ticks = '0' }
	// Wie evalScript in Premiere: Der Aufruf ist Code im Umfeld von host.jsx.
	// eslint-disable-next-line no-new-func
	const umfeld = new Function('app', 'Time', 'ProjectItemType', quelle + '\nreturn function (code) { return eval(code) }')
	const auswerten = umfeld({ project: { activeSequence: seq } }, Time, { BIN: 2 }) as (code: string) => string
	const rufe = (code: string) => JSON.parse(auswerten(code))
	return { liste, rufe, marker }
}

const details = {
	versionen: [{ id: 2, fpsNum: 25, fpsDen: 1 }],
	kommentare: [
		{ id: 10, versionId: 2, parentId: null, autorTyp: 'kunde', autor: 'Frau „Probe“', text: 'Logo\ngrößer', erledigt: false, zeichnung: null, frameIn: 50 },
		{ id: 11, versionId: 2, parentId: null, autorTyp: 'kunde', autor: 'Herr B', text: 'Farbe', erledigt: true, zeichnung: { farbe: '#f00', striche: [] }, frameIn: 100 },
	],
}

function abgleich(p: ReturnType<typeof premiere>, d: typeof details = details) {
	const soll = A.sollMarker(d, null, 0).marker
	const plan = A.plan(soll, p.rufe('vrMarkerLesen()').marker)
	const nur = (m: { name: string, kommentare: string, ticks: string, farbe: number }) => ({ name: m.name, kommentare: m.kommentare, ticks: m.ticks, farbe: m.farbe })
	return p.rufe('vrMarkerAnwenden(' + JSON.stringify({
		neu: plan.neu.map(nur),
		aendern: plan.aendern.map((a: { guid: string, soll: never }) => ({ guid: a.guid, soll: nur(a.soll) })),
		entfernen: plan.entfernen,
	}) + ')')
}

describe('host.jsx mit nachgebautem Premiere', () => {
	it('legt Marker bildgenau an, zweiter Lauf ändert nichts, fremde bleiben', () => {
		const p = premiere()
		const fremd = p.marker(1)
		fremd.name = 'Eigener Marker'
		expect(abgleich(p)).toEqual({ neu: 2, geaendert: 0, entfernt: 0, ohneFarbe: 0 })
		expect(p.liste).toHaveLength(3)
		const eigen = p.liste.filter((m) => m.comments.includes('[vr:'))
		expect(eigen.map((m) => m.start.ticks)).toEqual([String(2 * 254016000000), String(4 * 254016000000)])
		expect(eigen[0].comments).toContain('Logo\ngrößer')
		expect(eigen[1].farbe).toBe(A.FARBE.erledigt)
		expect(abgleich(p)).toEqual({ neu: 0, geaendert: 0, entfernt: 0, ohneFarbe: 0 })
		expect(p.liste[0].name).toBe('Eigener Marker')
	})

	it('strenges Premiere: Zeitobjekt abgelehnt, Sekunden genommen, bildgenau, zweiter Lauf ruhig', () => {
		// 59,94 DF: krumme Sekunden, Gleitkomma-Rundung möglich.
		const df = {
			versionen: [{ id: 2, fpsNum: 60000, fpsDen: 1001 }],
			kommentare: [1799, 1800, 3596].map((f, i) => ({ id: 20 + i, versionId: 2, parentId: null, autorTyp: 'kunde', autor: 'Frau Probe', text: 'Bild ' + f, erledigt: false, zeichnung: null, frameIn: f })),
		} as unknown as typeof details
		const p = premiere({ streng: true })
		expect(abgleich(p, df)).toEqual({ neu: 3, geaendert: 0, entfernt: 0, ohneFarbe: 0 })
		const bild = (ticks: string) => Math.floor(Number(ticks) * 60000 / 1001 / 254016000000 + 1e-6)
		expect(p.liste.map((m) => bild(m.start.ticks))).toEqual([1799, 1800, 3596])
		expect(abgleich(p, df)).toEqual({ neu: 0, geaendert: 0, entfernt: 0, ohneFarbe: 0 })
	})

	it('Farbe abgelehnt: Marker trotzdem richtig, Hinweis statt Abbruch', () => {
		const p = premiere({ farbeVerboten: true })
		expect(abgleich(p)).toEqual({ neu: 2, geaendert: 0, entfernt: 0, ohneFarbe: 2 })
		expect(p.liste.map((m) => m.start.ticks)).toEqual([String(2 * 254016000000), String(4 * 254016000000)])
	})

	it('scheitert ein Pflichtschritt, nennt die Meldung den Schritt', () => {
		const p = premiere()
		const seqMarkers = (p as unknown as { liste: M[] }).liste
		void seqMarkers
		const kaputt = premiere()
		// createMarker wirft wie Premiere bei falschem Typ.
		;(kaputt as unknown as { rufe: (c: string) => unknown }).rufe('(app.project.activeSequence.markers.createMarker = function () { throw new Error("Illegal Parameter type") }, "{}")')
		expect(abgleich(kaputt)).toEqual({ fehler: 'Create marker: Error: Illegal Parameter type' })
	})

	it('Sequenzangaben als lesbares JSON, auch mit Anführungszeichen', () => {
		const p = premiere()
		expect(p.rufe('vrSequenz()')).toEqual({ sequenz: { id: 'seq-1', name: 'Imagefilm', bildTicks: '10160640000' } })
	})
})
