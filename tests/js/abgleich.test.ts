/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// @vitest-environment node
import { describe, expect, it } from 'vitest'
import A from '../../premiere-panel/js/abgleich.js'
import quelltext from '../../premiere-panel/js/abgleich.js?raw'

const details = {
	versionen: [{ id: 1, fpsNum: 25, fpsDen: 1 }, { id: 2, fpsNum: 30000, fpsDen: 1001 }],
	kommentare: [
		{ id: 10, versionId: 2, parentId: null, autorTyp: 'kunde', autor: 'Frau Probe', text: 'Logo größer', erledigt: false, zeichnung: null, frameIn: 30 },
		{ id: 11, versionId: 2, parentId: 10, autorTyp: 'besitzer', autor: 'Studio Beispiel', text: 'Mache ich', erledigt: false, zeichnung: null, frameIn: 30 },
		{ id: 12, versionId: 1, parentId: null, autorTyp: 'kunde', autor: 'Alt', text: 'alt', erledigt: false, zeichnung: null, frameIn: 5 },
	],
}

describe('Abgleich Premiere-Marker', () => {
	it('Ticks bildgenau, auch bei 29,97', () => {
		expect(A.ticksFuerBild(30, 30000, 1001)).toBe(String(30n * 1001n * 254016000000n / 30000n))
		expect(A.ticksFuerBild(25, 25, 1)).toBe('254016000000')
	})
	it('Sollmarker nur der gewählten Version, Antworten im Text', () => {
		const s = A.sollMarker(details, null, 0)
		expect(s.version.id).toBe(2)
		expect(s.marker).toHaveLength(1)
		expect(s.marker[0].kommentare).toMatch(/(Du|You): Mache ich/)
		expect(s.marker[0].kommentare).toContain('[vr:10]')
		expect(s.marker[0].farbe).toBe(A.FARBE.offen)
	})
	it('fremde Marker bleiben, eigene werden ergänzt, geändert, entfernt', () => {
		const soll = A.sollMarker(details, null, 0).marker
		const p = A.plan(soll, [
			{ guid: 'f', name: 'Eigener', kommentare: 'Schnitt hier', ticks: '0', farbe: 1 },
			{ guid: 'a', name: 'alt', kommentare: 'x\n[vr:99]', ticks: '0', farbe: 3 },
		])
		expect(p.neu.map((m: { id: number }) => m.id)).toEqual([10])
		expect(p.entfernen).toEqual(['a'])
		const p2 = A.plan(soll, [{ guid: 'b', name: soll[0].name, kommentare: soll[0].kommentare, ticks: soll[0].ticks, farbe: A.FARBE.erledigt }])
		expect(p2.aendern).toHaveLength(1)
		expect(p2.neu).toHaveLength(0)
	})
})

describe('Laden im Panel', () => {
	it('legt VrAbgleich auch dann ab, wenn es ein module gibt (Premiere mit Node, mixed context)', () => {
		const fenster: Record<string, unknown> = {}
		const modul = { exports: {} as Record<string, unknown> }
		// Wie im CEP-Panel: self ist das Fenster, und module existiert trotzdem.
		new Function('self', 'module', quelltext)(fenster, modul)
		expect(typeof (fenster.VrAbgleich as { plan?: unknown })?.plan).toBe('function')
		expect(typeof (modul.exports as { plan?: unknown }).plan).toBe('function')
	})

	it('ohne module (reiner Browser) ebenso', () => {
		const fenster: Record<string, unknown> = {}
		new Function('self', 'module', quelltext)(fenster, undefined)
		expect(typeof (fenster.VrAbgleich as { plan?: unknown })?.plan).toBe('function')
	})
})

describe('Abgleich gegen Eigenheiten von Premiere', () => {
	it('umgeschriebene Zeilenenden und ein Tick Abweichung gelten nicht als Änderung', () => {
		const soll = [{ id: 7, name: '#1 Frau Probe', kommentare: 'Logo\ngrößer\n[vr:7]', ticks: '508032000000', farbe: 3 }]
		const ist = [{ guid: 'g1', name: '#1 Frau Probe', kommentare: 'Logo\rgrößer\r[vr:7]', ticks: '508032000001', farbe: 3 }]
		expect(A.plan(soll, ist)).toEqual({ neu: [], aendern: [], entfernen: [] })
	})

	it('ein ganzes Bild Abweichung wird korrigiert', () => {
		const soll = [{ id: 7, name: 'x', kommentare: '[vr:7]', ticks: '508032000000', farbe: 3 }]
		const ist = [{ guid: 'g1', name: 'x', kommentare: '[vr:7]', ticks: String(508032000000 + 10160640000), farbe: 3 }]
		expect(A.plan(soll, ist).aendern).toHaveLength(1)
	})
})
