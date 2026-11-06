/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import faelle from '../fixtures/timecode.json'
import { vonFrames, zuFrames } from '../../src/lib/timecode'
import { frameAusMediaTime, frameAusZeit, zeitFuerFrame } from '../../src/lib/frameSync'

describe('Timecode, gemeinsame Testfälle mit PHP', () => {
	for (const f of faelle.faelle) {
		const rate = { num: f.num, den: f.den }
		it(`${f.num}/${f.den} ${f.df ? 'DF' : 'NDF'} ${f.frames} ↔ ${f.tc}`, () => {
			expect(vonFrames(f.frames, rate, f.df)).toBe(f.tc)
			expect(zuFrames(f.tc, rate, f.df)).toBe(f.frames)
		})
	}
})

describe('Frame aus Zeit und zurück', () => {
	const raten = [
		{ num: 25, den: 1, bilder: 1500 },
		{ num: 30000, den: 1001, bilder: 1799 },
		{ num: 60000, den: 1001, bilder: 3597 },
		{ num: 24000, den: 1001, bilder: 1439 },
	]
	for (const { num, den, bilder } of raten) {
		const rate = { num, den }
		it(`${num}/${den}: Bildmitte führt zurück auf denselben Frame, bis zum letzten`, () => {
			for (let n = 0; n < bilder; n++) {
				expect(frameAusZeit(zeitFuerFrame(n, rate), rate)).toBe(n)
				// rVFC in Chromium: Beginn des Bildes
				expect(frameAusMediaTime(n * den / num, rate)).toBe(n)
				// rVFC in Firefox (ab 132, gemessen mit 158): die angesteuerte
				// Bildmitte. round() machte daraus n + 1 (Endspurt, Firefox-Test).
				expect(frameAusMediaTime(zeitFuerFrame(n, rate), rate)).toBe(n)
				// Chromium rundet mediaTime auf Mikrosekunden, auch nach unten.
				expect(frameAusMediaTime(Math.floor(n * den / num * 1e6) / 1e6, rate)).toBe(n)
			}
		})
	}
})
