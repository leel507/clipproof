/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Zugriff des Besitzers über die OCS-API (angemeldet, mit Anfragetoken).
 */
import { t } from '@nextcloud/l10n'
import { getRequestToken } from '@nextcloud/auth'
import { generateOcsUrl } from '@nextcloud/router'
import type { Kommentar, Version } from '../gemeinsam/typen'

export interface ReviewKurz {
	id: number
	titel: string
	versionen: number
	offen: number
	gesperrt: boolean
	freigegeben: boolean
	archiviert: boolean
	geaendertAm: string
}

export interface Abgabe {
	id: number
	anzahl: number
	text: string
	am: string
}

export interface ProjektOrdner {
	id: number
	pfad: string
	titel: string
	aktiv: boolean
	fehler: string | null
	reviews: number
	geprueftAm: string | null
}

export interface ReviewDetails {
	id: number
	titel: string
	token: string | null
	link: string | null
	gesperrt: boolean
	ablaufAm: string | null
	freigegebenAm: string | null
	archiviert: boolean
	anrede: 'du' | 'sie'
	oeffenbar: boolean
	/** Letzter Tag der Aufbewahrung (JJJJ-MM-TT), nur im Archiv. */
	aufbewahrenBis: string | null
	versionen: Array<Omit<Version, 'videoUrl'> & { videoUrl: string | null }>
	kommentare: Kommentar[]
	abgaben: Abgabe[]
}

async function ocs<T>(methode: string, pfad: string, daten?: unknown): Promise<T> {
	const antwort = await fetch(generateOcsUrl('apps/clipproof/api/v1' + pfad) + '?format=json', {
		method: methode,
		headers: {
			'OCS-APIRequest': 'true',
			requesttoken: getRequestToken() ?? '',
			Accept: 'application/json',
			...(daten !== undefined ? { 'Content-Type': 'application/json' } : {}),
		},
		body: daten !== undefined ? JSON.stringify(daten) : undefined,
	})
	const json = await antwort.json().catch(() => null) as { ocs?: { data?: unknown } } | null
	const inhalt = json?.ocs?.data
	if (!antwort.ok) {
		throw new Error((inhalt as { fehler?: string } | undefined)?.fehler ?? t('clipproof', 'Error {status}', { status: antwort.status }))
	}
	return inhalt as T
}

export const besitzerApi = {
	liste: () => ocs<ReviewKurz[]>('GET', '/reviews'),
	details: (id: number) => ocs<ReviewDetails>('GET', `/reviews/${id}`),
	kommentieren: (id: number, d: { versionId: number, frameIn: number, text: string, parentId: number | null }) => ocs<ReviewDetails>('POST', `/reviews/${id}/kommentare`, d),
	erledigt: (id: number, kommentarId: number, erledigt: boolean) => ocs<ReviewDetails>('PUT', `/reviews/${id}/kommentare/${kommentarId}/erledigt`, { erledigt }),
	erneuern: (id: number) => ocs<ReviewDetails>('POST', `/reviews/${id}/token`),
	sperren: (id: number) => ocs<ReviewDetails>('DELETE', `/reviews/${id}/token`),
	freigabe: (id: number, freigegeben: boolean) => ocs<ReviewDetails>('PUT', `/reviews/${id}/freigabe`, { freigegeben }),
	anrede: (id: number, anrede: 'du' | 'sie') => ocs<ReviewDetails>('PUT', `/reviews/${id}/anrede`, { anrede }),
	loeschen: (id: number) => ocs<{ geloescht: boolean }>('DELETE', `/reviews/${id}`),
	anlegen: (titel: string, pfad: string) => ocs<ReviewDetails>('POST', '/reviews', { titel, pfad }),
	versionAnhaengen: (id: number, pfad: string) => ocs<ReviewDetails>('POST', `/reviews/${id}/versionen`, { pfad }),
	ordner: () => ocs<ProjektOrdner[]>('GET', '/ordner'),
	panelStatus: () => ocs<{ vorhanden: boolean, erzeugtAm: string | null }>('GET', '/panel-schluessel'),
	panelErzeugen: () => ocs<{ schluessel: string, vorhanden: boolean, erzeugtAm: string | null }>('POST', '/panel-schluessel'),
	panelWiderrufen: () => ocs<{ vorhanden: boolean, erzeugtAm: string | null }>('DELETE', '/panel-schluessel'),
	ordnerEinschalten: (pfad: string) => ocs<ProjektOrdner>('POST', '/ordner', { pfad }),
	ordnerSchalten: (id: number, aktiv: boolean) => ocs<ProjektOrdner>('PUT', `/ordner/${id}`, { aktiv }),
	ordnerPruefen: (id: number) => ocs<ProjektOrdner>('POST', `/ordner/${id}/pruefen`),
}
