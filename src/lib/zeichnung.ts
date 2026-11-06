/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Zeichnungen als Vektoren (Koordinaten 0 bis 1) und das Standbild mit
 * Zeichnung. Alles im Browser; der Server rechnet nicht (harte Grenze 3).
 * Video und Seite haben denselben Ursprung, das Canvas bleibt also lesbar.
 */
import type { Zeichnung } from '../gemeinsam/typen'

/** Strichstärke relativ zur Bildbreite. */
const STAERKE = 0.005

export function zeichne(ctx: CanvasRenderingContext2D, zeichnung: Zeichnung, breite: number, hoehe: number): void {
	if (!zeichnung || !Array.isArray(zeichnung.striche)) return
	ctx.save()
	ctx.strokeStyle = zeichnung.farbe
	ctx.lineWidth = Math.max(2, breite * STAERKE)
	ctx.lineCap = 'round'
	ctx.lineJoin = 'round'
	for (const strich of zeichnung.striche) {
		if (!Array.isArray(strich) || strich.length === 0) continue
		ctx.beginPath()
		ctx.moveTo(strich[0][0] * breite, strich[0][1] * hoehe)
		if (strich.length === 1) {
			ctx.lineTo(strich[0][0] * breite + 0.01, strich[0][1] * hoehe)
		}
		for (const [x, y] of strich.slice(1)) {
			ctx.lineTo(x * breite, y * hoehe)
		}
		ctx.stroke()
	}
	ctx.restore()
}

/** Punkt auf 0 bis 1 begrenzen und auf vier Stellen runden (kleine Nutzlast). */
export function normiert(x: number, y: number): [number, number] {
	const r = (v: number) => Math.round(Math.min(1, Math.max(0, v)) * 10000) / 10000
	return [r(x), r(y)]
}

/**
 * Standbild in voller Auflösung: das gerade angezeigte Bild des Videos
 * plus Zeichnung, als PNG. Der Aufrufer springt vorher auf den Frame.
 */
export async function standbild(video: HTMLVideoElement, zeichnung: Zeichnung | null): Promise<Blob> {
	const c = document.createElement('canvas')
	c.width = video.videoWidth
	c.height = video.videoHeight
	const ctx = c.getContext('2d')
	if (!ctx) throw new Error('Canvas not available')
	ctx.drawImage(video, 0, 0, c.width, c.height)
	if (zeichnung) zeichne(ctx, zeichnung, c.width, c.height)
	return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('PNG fehlgeschlagen'))), 'image/png'))
}

export function herunterladen(blob: Blob, name: string): void {
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = name
	document.body.appendChild(a)
	a.click()
	a.remove()
	setTimeout(() => URL.revokeObjectURL(url), 1000)
}
