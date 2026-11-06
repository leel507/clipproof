/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// @vitest-environment node
import { describe, expect, it } from 'vitest'
import S from '../../premiere-panel/js/sprache.js'

describe('Sprache des Panels', () => {
	it('ist ohne Einstellung englisch', () => {
		expect(S.sprache()).toBe('en')
		expect(S.t('sync')).toBe('Sync')
	})

	it('setzt Platzhalter ein und lässt unbekannte stehen', () => {
		expect(S.t('synced', { n: 3, zeit: '10:15' })).toBe('3 markers, synced 10:15')
		expect(S.t('error', {})).toBe('Error {status}')
	})

	it('übersetzt bekannte Meldungen aus Premiere', () => {
		expect(S.vonPremiere('No sequence open')).toBe('No sequence open.')
		expect(S.vonPremiere('Etwas anderes')).toBe('Etwas anderes')
	})

	it('hat in beiden Sprachen dieselben Texte, keiner leer', () => {
		const en = Object.keys(S.TEXTE.en).sort()
		const de = Object.keys(S.TEXTE.de).sort()
		expect(de).toEqual(en)
		for (const s of ['en', 'de']) {
			for (const [k, v] of Object.entries(S.TEXTE[s])) {
				expect(v, `${s}.${k}`).not.toBe('')
			}
		}
	})

	it('hat in beiden Sprachen dieselben Platzhalter', () => {
		const platz = (t: string) => (t.match(/\{\w+\}/g) || []).sort().join()
		for (const k of Object.keys(S.TEXTE.en)) {
			expect(platz(S.TEXTE.de[k]), k).toBe(platz(S.TEXTE.en[k]))
		}
	})
})
