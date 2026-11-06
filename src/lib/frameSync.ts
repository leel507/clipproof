/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Bildgenaue Steuerung eines <video>.
 *
 * Mit requestVideoFrameCallback liefert metadata.mediaTime einen Zeitpunkt
 * im tatsächlich angezeigten Bild: Chromium den Beginn des Bildes, Firefox
 * (rVFC ab Version 132, gemessen mit 158 am 15.10.2026) die angesteuerte
 * Zeit, beim Springen also die Bildmitte (n + 0,5) / fps. Beides liegt im
 * Bild n, deshalb abrunden (mit kleiner Toleranz gegen Gleitkommafehler)
 * statt runden: round() machte in Firefox aus der Bildmitte n + 1.
 * Ohne rVFC (ältere Browser) wird aus currentTime gerechnet.
 */

import type { Bildrate } from './timecode'

type VideoMitRvfc = HTMLVideoElement & {
	requestVideoFrameCallback?: (cb: (now: number, meta: { mediaTime: number }) => void) => number
	cancelVideoFrameCallback?: (id: number) => void
}

export function hatFrameCallback(video: HTMLVideoElement): boolean {
	return typeof (video as VideoMitRvfc).requestVideoFrameCallback === 'function'
}

/** Frame-Index aus einem Zeitpunkt im angezeigten Bild (rVFC). */
export function frameAusMediaTime(mediaTime: number, rate: Bildrate): number {
	// Toleranz ein Tausendstel Bild: Bildbeginn knapp darunter zählt noch.
	return Math.floor(mediaTime * rate.num / rate.den + 1e-3)
}

/** Frame-Index aus currentTime (Rückfall ohne rVFC). */
export function frameAusZeit(zeit: number, rate: Bildrate): number {
	// Kleine Toleranz gegen Rundungsfehler der Gleitkommazahl.
	return Math.floor(zeit * rate.num / rate.den + 1e-6)
}

/** Zielzeit für einen Frame: die Mitte des Bildes. */
export function zeitFuerFrame(frame: number, rate: Bildrate): number {
	return (frame + 0.5) * rate.den / rate.num
}

export class FrameUhr {
	private frame = 0
	/** Ziel eines laufenden Sprungs: Schnelles Tippen addiert sich darauf. */
	private ziel: number | null = null
	private rvfcId: number | null = null
	private rafId: number | null = null
	private readonly mitRvfc: boolean

	constructor(
		private readonly video: HTMLVideoElement,
		private readonly rate: Bildrate,
		private readonly bilder: number,
		private readonly beiAenderung: (frame: number) => void,
	) {
		this.mitRvfc = hatFrameCallback(video)
		if (this.mitRvfc) {
			this.naechstesBild()
		} else {
			for (const ereignis of ['seeked', 'timeupdate', 'loadeddata']) {
				video.addEventListener(ereignis, this.ausZeit)
			}
			video.addEventListener('play', this.schleife)
		}
	}

	get bildgenau(): boolean {
		return this.mitRvfc
	}

	get aktuell(): number {
		return this.frame
	}

	springeZu(frame: number): void {
		const ziel = Math.min(Math.max(0, Math.round(frame)), this.bilder - 1)
		this.ziel = ziel
		this.video.currentTime = zeitFuerFrame(ziel, this.rate)
	}

	schritt(delta: number): void {
		this.video.pause()
		const basis = this.video.seeking && this.ziel !== null ? this.ziel : this.frame
		this.springeZu(basis + delta)
	}

	/** Nach einem Wechsel der Quelle: Bildrückruf neu anmelden. */
	neuStarten(): void {
		const v = this.video as VideoMitRvfc
		if (!this.mitRvfc) {
			this.ausZeit()
			return
		}
		if (this.rvfcId !== null && v.cancelVideoFrameCallback) {
			v.cancelVideoFrameCallback(this.rvfcId)
		}
		this.naechstesBild()
	}

	beenden(): void {
		const v = this.video as VideoMitRvfc
		if (this.rvfcId !== null && v.cancelVideoFrameCallback) {
			v.cancelVideoFrameCallback(this.rvfcId)
		}
		if (this.rafId !== null) {
			cancelAnimationFrame(this.rafId)
		}
		for (const ereignis of ['seeked', 'timeupdate', 'loadeddata']) {
			this.video.removeEventListener(ereignis, this.ausZeit)
		}
		this.video.removeEventListener('play', this.schleife)
	}

	private setze(frame: number): void {
		const begrenzt = Math.min(Math.max(0, frame), this.bilder - 1)
		if (begrenzt !== this.frame) {
			this.frame = begrenzt
		}
		this.beiAenderung(this.frame)
	}

	private naechstesBild(): void {
		const v = this.video as VideoMitRvfc
		this.rvfcId = v.requestVideoFrameCallback!((_jetzt, meta) => {
			this.setze(frameAusMediaTime(meta.mediaTime, this.rate))
			this.naechstesBild()
		})
	}

	private readonly ausZeit = (): void => {
		this.setze(frameAusZeit(this.video.currentTime, this.rate))
	}

	private readonly schleife = (): void => {
		this.ausZeit()
		if (!this.video.paused) {
			this.rafId = requestAnimationFrame(this.schleife)
		}
	}
}
