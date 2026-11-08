/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { zeitpunkt } from '../../src/lib/zeitpunkt'

const jetzt = new Date('2026-11-08T15:30:00Z')

describe('Zeitpunkt einer Anmerkung', () => {
	it('folgt der Sprache der Seite', () => {
		expect(zeitpunkt('2026-11-08T15:25:00Z', 'en-US', jetzt, 'UTC')).toBe('5 minutes ago')
		expect(zeitpunkt('2026-11-08T15:25:00Z', 'de-DE', jetzt, 'UTC')).toBe('vor 5 Minuten')
	})

	it('zeigt gerade Gesendetes als jetzt', () => {
		expect(zeitpunkt('2026-11-08T15:29:50Z', 'en-US', jetzt, 'UTC')).toBe('now')
		expect(zeitpunkt('2026-11-08T15:29:50Z', 'de-DE', jetzt, 'UTC')).toBe('jetzt')
	})

	it('nennt am selben Tag die Uhrzeit, sonst das Datum', () => {
		expect(zeitpunkt('2026-11-08T09:05:00Z', 'de-DE', jetzt, 'UTC')).toBe('heute 09:05')
		expect(zeitpunkt('2026-11-02T09:05:00Z', 'de-DE', jetzt, 'UTC')).toBe('02.11. 09:05')
	})

	it('rechnet in der Zeitzone des Betrachters', () => {
		// 23:30 UTC ist in Berlin schon der nächste Tag
		expect(zeitpunkt('2026-11-08T07:00:00Z', 'de-DE', new Date('2026-11-08T23:30:00Z'), 'Europe/Berlin')).toBe('08.11. 08:00')
	})

	it('bleibt ohne Zeitpunkt leer', () => {
		expect(zeitpunkt(null, 'en-US', jetzt)).toBe('')
	})
})
