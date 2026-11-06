/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Zugriff der Kundenseite auf die App. Anmeldung über das Token in der
 * Adresse und den Gastschlüssel in der Kopfzeile, keine Cookies.
 */
import { generateUrl } from '@nextcloud/router'
import type { Kommentar, Zeichnung } from '../gemeinsam/typen'

export class ApiFehler extends Error {
	constructor(public readonly status: number, meldung: string, public readonly code = '') {
		super(meldung)
	}
}

const SPEICHER = 'clipproof-gast-'

/** Gastschlüssel im Browser. localStorage kann fehlen (privates Fenster). */
export function gespeicherterSchluessel(token: string): string | null {
	try {
		return localStorage.getItem(SPEICHER + token)
	} catch {
		return null
	}
}

function schluesselMerken(token: string, schluessel: string): void {
	try {
		localStorage.setItem(SPEICHER + token, schluessel)
	} catch {
		// Ohne Speicher gilt der Schlüssel nur bis zum Neuladen.
	}
}

export class KundenApi {
	private schluessel: string | null

	constructor(private readonly token: string) {
		this.schluessel = gespeicherterSchluessel(token)
	}

	get angemeldet(): boolean {
		return this.schluessel !== null
	}

	private url(pfad: string): string {
		return generateUrl('/apps/clipproof/r/{token}' + pfad, { token: this.token })
	}

	private async anfrage<T>(methode: string, pfad: string, daten?: unknown): Promise<T> {
		const kopf: Record<string, string> = { Accept: 'application/json' }
		if (daten !== undefined) kopf['Content-Type'] = 'application/json'
		if (this.schluessel) kopf['X-Clipproof-Gast'] = this.schluessel
		const antwort = await fetch(this.url(pfad), {
			method: methode,
			headers: kopf,
			body: daten !== undefined ? JSON.stringify(daten) : undefined,
			credentials: 'omit',
		})
		if (antwort.status === 204) return undefined as T
		const json = await antwort.json().catch(() => ({}))
		if (!antwort.ok) {
			if (antwort.status === 401) this.schluessel = null
			throw new ApiFehler(antwort.status, (json as { fehler?: string }).fehler ?? '', (json as { code?: string }).code ?? '')
		}
		return json as T
	}

	async anmelden(name: string): Promise<string> {
		const r = await this.anfrage<{ name: string, schluessel: string }>('POST', '/gast', { name })
		this.schluessel = r.schluessel
		schluesselMerken(this.token, r.schluessel)
		return r.name
	}

	kommentare() {
		return this.anfrage<{ gast: { name: string } | null, kommentare: Kommentar[], entwuerfe: number, gesperrt: boolean }>('GET', '/kommentare')
	}

	anlegen(daten: { versionId: number, frameIn: number, text: string, zeichnung: Zeichnung | null, parentId?: number | null }) {
		return this.anfrage<Kommentar>('POST', '/kommentare', {
			...daten,
			zeichnung: daten.zeichnung ? JSON.stringify(daten.zeichnung) : null,
		})
	}

	aendern(id: number, text: string) {
		return this.anfrage<Kommentar>('PATCH', `/kommentare/${id}`, { text })
	}

	loeschen(id: number) {
		return this.anfrage<void>('DELETE', `/kommentare/${id}`)
	}

	abgeben() {
		return this.anfrage<{ anzahl: number, abgesendetAm: string }>('POST', '/abgaben')
	}
}
