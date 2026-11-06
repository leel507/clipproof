/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Abgleich der Anmerkungen mit den Sequenzmarkern (E10). Reine Rechnung,
 * ohne Premiere und ohne Netz, damit sie sich testen lässt.
 *
 * Grundsätze:
 * - Das Panel fasst nur eigene Marker an. Sie tragen „[vr:<id>]“ in den
 *   Kommentaren; alle anderen Marker bleiben unberührt.
 * - Zeit in Ticks von Premiere (254016000000 je Sekunde). Für alle
 *   üblichen Bildraten ist das ganzzahlig, also bildgenau.
 * - Bild n des Proxys liegt in der Sequenz bei (n + versatz) Bildern ab
 *   Sequenzbeginn. versatz ist 0, wenn der Proxy die ganze Sequenz ist.
 */
(function (wurzel, fabrik) {
	var api = fabrik()
	// Immer fürs Panel ablegen: In Premiere läuft das Panel mit Node
	// (--enable-nodejs --mixed-context), dort gibt es auch im Browserteil
	// ein „module“. Nur module.exports zu setzen ließ VrAbgleich fehlen
	// („VrAbgleich is not defined“, Premiere 26.5.2, 18.10.2026).
	wurzel.VrAbgleich = api
	if (typeof module === 'object' && module && module.exports) {
		module.exports = api
	}
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict'

	// Texte in den Markern in der Sprache des Panels (sprache.js); ohne sie, etwa in den Tests, englisch.
	function text(schluessel) {
		var s = typeof self !== 'undefined' && self.VrSprache ? self.VrSprache : null
		if (s) return s.t(schluessel)
		return { you: 'You', drawingOnly: '(drawing only)', withDrawing: '[with drawing on the frame]' }[schluessel]
	}

	var TICKS_JE_SEKUNDE = 254016000000n
	var MARKE = /\[vr:(\d+)\]/

	/** Premiere-Markerfarben (Index für setColorByIndex). */
	var FARBE = { offen: 3, erledigt: 0, besitzer: 6 }

	/** Ticks als Zeichenkette für ein Bild. */
	function ticksFuerBild(bild, fpsNum, fpsDen) {
		var b = BigInt(Math.max(0, Math.round(bild)))
		return String((b * BigInt(fpsDen) * TICKS_JE_SEKUNDE) / BigInt(fpsNum))
	}

	/**
	 * Gleich bis auf eine Millisekunde (weit unter einem Bild): Premiere kann
	 * beim Setzen über Sekunden einen Tick abweichen; ohne Toleranz würde
	 * der Marker bei jedem Abgleich neu gesetzt.
	 */
	var TOLERANZ = TICKS_JE_SEKUNDE / 1000n
	function ticksGleich(a, b) {
		try {
			var d = BigInt(String(a)) - BigInt(String(b))
			return (d < 0n ? -d : d) <= TOLERANZ
		} catch (e) {
			return false
		}
	}

	/** Premiere kann Zeilenenden umschreiben (\n zu \r): vor dem Vergleich angleichen. */
	function zeilen(t) {
		return String(t || '').replace(/\r\n?/g, '\n')
	}

	function kennung(kommentare) {
		var m = MARKE.exec(kommentare || '')
		return m ? Number(m[1]) : null
	}

	/**
	 * Sollmarker aus den Review-Details für eine Version.
	 *
	 * @param details Antwort von /reviews/{id}
	 * @param versionId gewählte Version (null: neueste)
	 * @param versatz Bilder, um die der Proxy in der Sequenz verschoben ist
	 */
	function sollMarker(details, versionId, versatz) {
		var versionen = details.versionen || []
		var version = null
		for (var i = 0; i < versionen.length; i++) {
			if (versionId === null ? true : versionen[i].id === versionId) version = versionen[i]
		}
		if (!version) return { version: null, marker: [] }
		var alle = (details.kommentare || []).filter(function (k) { return k.versionId === version.id })
		var haupt = alle.filter(function (k) { return k.parentId === null })
		var marker = haupt.map(function (k, nr) {
			var antworten = alle.filter(function (a) { return a.parentId === k.id })
			var zeilen = [k.text || (k.zeichnung ? text('drawingOnly') : '')]
			antworten.forEach(function (a) {
				zeilen.push((a.autorTyp === 'besitzer' ? text('you') : a.autor) + ': ' + a.text)
			})
			if (k.zeichnung) zeilen.push(text('withDrawing'))
			zeilen.push('[vr:' + k.id + ']')
			return {
				id: k.id,
				name: '#' + (nr + 1) + ' ' + (k.autorTyp === 'besitzer' ? text('you') : k.autor),
				kommentare: zeilen.join('\n'),
				ticks: ticksFuerBild(k.frameIn + (versatz || 0), version.fpsNum, version.fpsDen),
				farbe: k.autorTyp === 'besitzer' ? FARBE.besitzer : (k.erledigt ? FARBE.erledigt : FARBE.offen),
				zeichnung: k.zeichnung || null,
				bild: k.frameIn,
			}
		})
		return { version: version, marker: marker }
	}

	/**
	 * Was zu tun ist. vorhanden: Marker der Sequenz als
	 * [{ guid, name, kommentare, ticks, farbe }].
	 *
	 * @return { neu: [], aendern: [{ guid, soll }], entfernen: [guid] }
	 */
	function plan(soll, vorhanden) {
		var eigene = {}
		vorhanden.forEach(function (m) {
			var id = kennung(m.kommentare)
			if (id === null) return
			if (eigene[id]) {
				// Doppelt (etwa nach Strg+Z): den zweiten entfernen.
				eigene['doppelt' + m.guid] = m
			} else {
				eigene[id] = m
			}
		})
		var neu = []
		var aendern = []
		var gesehen = {}
		soll.forEach(function (s) {
			gesehen[s.id] = true
			var ist = eigene[s.id]
			if (!ist) {
				neu.push(s)
			} else if (ist.name !== s.name || zeilen(ist.kommentare) !== zeilen(s.kommentare) || !ticksGleich(ist.ticks, s.ticks) || Number(ist.farbe) !== s.farbe) {
				aendern.push({ guid: ist.guid, soll: s })
			}
		})
		var entfernen = []
		Object.keys(eigene).forEach(function (schluessel) {
			var m = eigene[schluessel]
			if (schluessel.indexOf('doppelt') === 0 || !gesehen[kennung(m.kommentare)]) {
				entfernen.push(m.guid)
			}
		})
		return { neu: neu, aendern: aendern, entfernen: entfernen }
	}

	return {
		TICKS_JE_SEKUNDE: TICKS_JE_SEKUNDE,
		FARBE: FARBE,
		ticksFuerBild: ticksFuerBild,
		ticksGleich: ticksGleich,
		kennung: kennung,
		sollMarker: sollMarker,
		plan: plan,
	}
}))
