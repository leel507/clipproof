/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Frame ↔ Timecode, gleich umgesetzt in lib/Service/Timecode.php und
 * gegen tests/fixtures/timecode.json geprüft.
 *
 * Gerechnet wird mit der absoluten Framezahl (Start-Timecode plus Index).
 * Der Timecode zählt mit der Nennbildrate (24 bei 23,976, 30 bei 29,97).
 * Drop-Frame überspringt Zählnummern, keine Bilder.
 */

export interface Bildrate {
	num: number
	den: number
}

export function nominal({ num, den }: Bildrate): number {
	return Math.round(num / den)
}

export function dropFrameMoeglich(rate: Bildrate): boolean {
	const n = nominal(rate)
	return rate.den === 1001 && (n === 30 || n === 60)
}

const zwei = (n: number): string => String(n).padStart(2, '0')

export function vonFrames(frames: number, rate: Bildrate, df: boolean): string {
	if (!Number.isInteger(frames) || frames < 0) {
		throw new RangeError('Invalid frame count')
	}
	const n = nominal(rate)
	let zaehl = frames
	if (df) {
		if (!dropFrameMoeglich(rate)) {
			throw new RangeError('Drop frame only at 29.97 and 59.94')
		}
		const drop = Math.trunc(n / 15)
		const jeMinute = n * 60 - drop
		const jeZehn = n * 600 - drop * 9
		const zehner = Math.trunc(frames / jeZehn)
		const rest = frames % jeZehn
		zaehl += drop * 9 * zehner
		if (rest > drop) {
			zaehl += drop * Math.trunc((rest - drop) / jeMinute)
		}
	}
	const ff = zaehl % n
	const s = Math.trunc(zaehl / n)
	return `${zwei(Math.trunc(s / 3600))}:${zwei(Math.trunc(s / 60) % 60)}:${zwei(s % 60)}${df ? ';' : ':'}${zwei(ff)}`
}

export function zuFrames(tc: string, rate: Bildrate, df: boolean): number {
	const m = /^(\d{2}):(\d{2}):(\d{2})[:;.](\d{2})$/.exec(tc)
	if (m === null) {
		throw new RangeError(`Timecode not readable: ${tc}`)
	}
	const [hh, mm, ss, ff] = m.slice(1).map(Number)
	const n = nominal(rate)
	let frames = (hh * 3600 + mm * 60 + ss) * n + ff
	if (df) {
		const drop = Math.trunc(n / 15)
		const minuten = hh * 60 + mm
		frames -= drop * (minuten - Math.trunc(minuten / 10))
	}
	return frames
}
