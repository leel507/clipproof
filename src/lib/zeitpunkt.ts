/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Zeitpunkt einer Anmerkung für die Kundenseite, in der Sprache der Seite und
 * der Zeitzone des Betrachters: „vor 5 Minuten“, „heute 14:02“, sonst Datum
 * mit Uhrzeit. Ohne eigene Übersetzungen, alles über Intl.
 */
export function zeitpunkt(iso: string | null, locale: string, jetzt: Date = new Date(), zeitzone?: string): string {
	if (!iso) return ''
	const d = new Date(iso)
	const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
	const min = Math.round((jetzt.getTime() - d.getTime()) / 60000)
	if (min < 1) return rtf.format(0, 'second')
	if (min < 60) return rtf.format(-min, 'minute')
	const zeit = d.toLocaleTimeString(locale, { timeZone: zeitzone, hour: '2-digit', minute: '2-digit' })
	const tag = (x: Date) => x.toLocaleDateString('en-CA', { timeZone: zeitzone })
	if (tag(jetzt) === tag(d)) return `${rtf.format(0, 'day')} ${zeit}`
	return `${d.toLocaleDateString(locale, { timeZone: zeitzone, day: '2-digit', month: '2-digit' })} ${zeit}`
}
