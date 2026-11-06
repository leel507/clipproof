/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export interface Version {
	id: number
	nummer: number
	fpsNum: number
	fpsDen: number
	df: boolean
	/** Start-Timecode als absolute Framezahl */
	startFrames: number
	startTimecode: string
	bilder: number
	breite: number | null
	hoehe: number | null
	/** Kurzlebige, signierte Adresse ohne Review-Token (VideoAdresse.php). */
	videoUrl: string
	/** Restlaufzeit der Adresse in Sekunden zum Zeitpunkt der Antwort. */
	videoGueltigFuer: number
}

/** Neue Video-Adresse für eine laufende Seite. */
export type AdresseErneuern = () => Promise<{ videoUrl: string, videoGueltigFuer: number } | null>

/** Zeichnung als Vektoren, Koordinaten 0 bis 1 relativ zum Bild. */
export interface Zeichnung {
	farbe: string
	striche: Array<Array<[number, number]>>
}

export interface Kommentar {
	id: number
	versionId: number
	parentId: number | null
	autorTyp: 'kunde' | 'besitzer'
	autor: string
	eigen: boolean
	entwurf: boolean
	abgesendetAm: string | null
	/** Nullbasierter Index ab dem ersten Bild der Datei */
	frameIn: number
	frameOut: number | null
	/** Anzeige mit Start-Timecode */
	timecode: string
	text: string
	zeichnung: Zeichnung | null
	erledigt: boolean
	erstelltAm: string
}

/** Markierung einer Anmerkung auf der Zeitleiste */
export interface Marker {
	id: number
	frame: number
	titel: string
	entwurf: boolean
}
