/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Panel „Clipproof“ für Premiere Pro (E10).
 *
 * Holt alle 30 Sekunden die Anmerkungen des zugeordneten Reviews und
 * gleicht sie als Sequenzmarker ab. Fasst nur eigene Marker an
 * („[vr:<id>]“ in den Kommentaren). Keine Spuren, keine Clips.
 *
 * Läuft im Chromium von CEP; hier ist modernes JavaScript erlaubt. Das
 * ExtendScript in jsx/host.jsx dagegen ist ES3.
 */
/* global VrAbgleich, VrSprache */
(function () {
	'use strict'

	var TAKT = 30 * 1000
	var nodeRequire = typeof window.require === 'function' ? window.require : null
	var speicher = window.localStorage

	var zustand = {
		sequenz: null,
		reviews: [],
		details: null,
		letzterAbgleich: null,
		laeuft: false,
	}

	var $ = function (id) { return document.getElementById(id) }
	var T = function (schluessel, werte) { return VrSprache.t(schluessel, werte) }

	/* ---------- Einstellungen ---------- */

	function lesen(schluessel, vorgabe) {
		try {
			var w = speicher.getItem(schluessel)
			return w === null ? vorgabe : w
		} catch (e) {
			return vorgabe
		}
	}

	function schreiben(schluessel, wert) {
		try {
			speicher.setItem(schluessel, wert)
		} catch (e) {
			// Ohne Speicher gilt die Einstellung bis zum Schließen.
		}
	}

	/*
	 * Der Panel-Schlüssel liegt im Schlüsselbund von macOS (Dienst
	 * „clipproof-panel“), nicht in einer lesbaren Datei. Er kann nur
	 * die eigenen Reviews lesen; ein Nextcloud-Passwort braucht das Panel nicht.
	 */
	var SCHLUESSELBUND = 'clipproof-panel'
	var schluesselZwischen = null

	function schluesselLesen() {
		if (schluesselZwischen !== null) return schluesselZwischen
		if (nodeRequire) {
			try {
				schluesselZwischen = nodeRequire('child_process').execFileSync('security',
					['find-generic-password', '-s', SCHLUESSELBUND, '-a', 'panel', '-w'], { encoding: 'utf8' }).trim()
			} catch (e) {
				schluesselZwischen = ''
			}
		} else {
			// Ohne Schlüsselbund nie dauerhaft speichern: nur im Speicher.
			schluesselZwischen = ''
		}
		return schluesselZwischen
	}

	/**
	 * Im Schlüsselbund speichern. Geht das nicht, bleibt der Schlüssel nur
	 * bis zum Schließen im Panel, sichtbar gemeldet, nie still in einer Datei.
	 *
	 * @return true, wenn im Schlüsselbund gespeichert
	 */
	function schluesselSchreiben(wert) {
		schluesselZwischen = wert
		if (!nodeRequire) return false
		try {
			nodeRequire('child_process').execFileSync('security',
				['add-generic-password', '-U', '-s', SCHLUESSELBUND, '-a', 'panel', '-l', 'Clipproof Panel', '-w', wert])
			return true
		} catch (e) {
			return false
		}
	}

	function zugang() {
		return {
			server: lesen('vr-server', '').replace(/\/+$/, ''),
			schluessel: schluesselLesen(),
		}
	}

	/** Zuordnung je Sequenz: welches Review, welche Version, welcher Versatz. */
	function zuordnung(seqId) {
		try {
			return JSON.parse(lesen('vr-zuordnung-' + seqId, 'null'))
		} catch (e) {
			return null
		}
	}

	function zuordnungSpeichern(seqId, z) {
		schreiben('vr-zuordnung-' + seqId, JSON.stringify(z))
	}

	/* ---------- Premiere ---------- */

	function host(code) {
		return new Promise(function (fertig, fehler) {
			if (!window.__adobe_cep__) {
				fehler(new Error(T('notInPremiere')))
				return
			}
			window.__adobe_cep__.evalScript(code, function (antwort) {
				var d
				try {
					d = JSON.parse(antwort)
				} catch (e) {
					fehler(new Error(T('unreadable', { text: String(antwort).slice(0, 120) })))
					return
				}
				if (d && d.fehler) {
					fehler(new Error(VrSprache.vonPremiere(d.fehler)))
				} else {
					fertig(d)
				}
			})
		})
	}

	/** Werte als ES3-taugliches Literal ins ExtendScript reichen. */
	function literal(wert) {
		return JSON.stringify(wert).split(String.fromCharCode(0x2028)).join('\\u2028').split(String.fromCharCode(0x2029)).join('\\u2029')
	}

	/* ---------- Netz ---------- */

	/**
	 * Lesezugang der App für das Panel, angemeldet mit dem Panel-Schlüssel.
	 * Über Node, wenn vorhanden (kein CORS nötig), sonst fetch.
	 */
	function api(pfad) {
		var z = zugang()
		if (!/^https?:\/\//.test(z.server)) return Promise.reject(new Error(T('serverMissing')))
		if (!z.schluessel) return Promise.reject(new Error(T('keyMissing')))
		var url = z.server + '/index.php/apps/clipproof/panel' + pfad
		// Accept-Language: Fehlermeldungen der App kommen in der Sprache des Panels
		var kopf = { 'X-Clipproof-Panel': z.schluessel, Accept: 'application/json', 'Accept-Language': VrSprache.sprache() }
		var auswerten = function (status, text) {
			var d = null
			try { d = JSON.parse(text) } catch (e) { /* unten */ }
			if (status === 401) throw new Error(T('keyInvalid'))
			if (status < 200 || status >= 300 || d === null) {
				throw new Error((d && d.fehler) || T('error', { status: status }))
			}
			return d
		}
		if (nodeRequire) {
			return new Promise(function (fertig, fehler) {
				var modul = url.indexOf('https:') === 0 ? nodeRequire('https') : nodeRequire('http')
				var anfrage = modul.get(url, { headers: kopf, timeout: 20000 }, function (antwort) {
					var teile = []
					antwort.on('data', function (t) { teile.push(t) })
					antwort.on('end', function () {
						try {
							fertig(auswerten(antwort.statusCode, nodeRequire('buffer').Buffer.concat(teile).toString('utf8')))
						} catch (e) {
							fehler(e)
						}
					})
				})
				anfrage.on('timeout', function () { anfrage.destroy(new Error(T('timeout'))) })
				anfrage.on('error', fehler)
			})
		}
		return fetch(url, { headers: kopf }).then(function (r) {
			return r.text().then(function (t) { return auswerten(r.status, t) })
		})
	}

	/* ---------- Abgleich ---------- */

	async function abgleichen() {
		if (zustand.laeuft) return
		zustand.laeuft = true
		try {
			var s = await host('vrSequenz()')
			zustand.sequenz = s.sequenz
			if (!zustand.sequenz) {
				melden(T('noSequence'))
				zeichnen()
				return
			}
			var z = zuordnung(zustand.sequenz.id)
			if (!z) {
				melden(T('notLinked'))
				zeichnen()
				return
			}
			zustand.details = await api('/reviews/' + z.reviewId)
			var soll = VrAbgleich.sollMarker(zustand.details, z.versionId === null ? null : z.versionId, Number(z.versatz) || 0)
			var vorhanden = (await host('vrMarkerLesen()')).marker
			var plan = VrAbgleich.plan(soll.marker, vorhanden)
			var ergebnis = { neu: 0, geaendert: 0, entfernt: 0 }
			if (plan.neu.length + plan.aendern.length + plan.entfernen.length > 0) {
				ergebnis = await host('vrMarkerAnwenden(' + literal({
					neu: plan.neu.map(nurMarker),
					aendern: plan.aendern.map(function (a) { return { guid: a.guid, soll: nurMarker(a.soll) } }),
					entfernen: plan.entfernen,
				}) + ')')
			}
			zustand.letzterAbgleich = new Date()
			var aenderung = ergebnis.neu + ergebnis.geaendert + ergebnis.entfernt
			melden(T('synced', { n: soll.marker.length, zeit: uhrzeit(zustand.letzterAbgleich) }) +
				(aenderung > 0 ? T('changes', ergebnis) : '') +
				(ergebnis.ohneFarbe > 0 ? T('noColor', { n: ergebnis.ohneFarbe }) : ''))
			zeichnen()
		} catch (e) {
			melden(e.message, true)
		} finally {
			zustand.laeuft = false
		}
	}

	function nurMarker(m) {
		return { name: m.name, kommentare: m.kommentare, ticks: m.ticks, farbe: m.farbe }
	}

	/* ---------- Oberfläche ---------- */

	function melden(text, fehler) {
		var el = $('status')
		el.textContent = text
		el.className = fehler ? 'status status--fehler' : 'status'
	}

	function uhrzeit(d) {
		return d.toLocaleTimeString(VrSprache.sprache() === 'de' ? 'de-DE' : 'en-GB', { hour: '2-digit', minute: '2-digit' })
	}

	async function reviewsLaden() {
		try {
			zustand.reviews = await api('/reviews')
			zeichnenAuswahl()
		} catch (e) {
			melden(e.message, true)
		}
	}

	function zeichnenAuswahl() {
		var auswahl = $('review')
		var z = zustand.sequenz ? zuordnung(zustand.sequenz.id) : null
		auswahl.innerHTML = ''
		var leer = document.createElement('option')
		leer.value = ''
		leer.textContent = T('noReview')
		auswahl.appendChild(leer)
		zustand.reviews.filter(function (r) { return !r.archiviert }).forEach(function (r) {
			var o = document.createElement('option')
			o.value = String(r.id)
			o.textContent = r.titel + (r.offen > 0 ? T('open', { n: r.offen }) : '')
			if (z && z.reviewId === r.id) o.selected = true
			auswahl.appendChild(o)
		})
	}

	function zeichnen() {
		zeichnenAuswahl()
		$('sequenz').textContent = zustand.sequenz ? zustand.sequenz.name : T('none')
		var z = zustand.sequenz ? zuordnung(zustand.sequenz.id) : null
		$('zuordnung').hidden = !zustand.sequenz
		var versionAuswahl = $('version')
		versionAuswahl.innerHTML = ''
		var neueste = document.createElement('option')
		neueste.value = ''
		neueste.textContent = T('latest')
		versionAuswahl.appendChild(neueste)
		if (zustand.details && z) {
			zustand.details.versionen.forEach(function (v) {
				var o = document.createElement('option')
				o.value = String(v.id)
				o.textContent = T('versionN', { n: v.nummer })
				if (z.versionId === v.id) o.selected = true
				versionAuswahl.appendChild(o)
			})
			$('versatz').value = String(z.versatz || 0)
		}
		zeichnenListe(z)
	}

	function zeichnenListe(z) {
		var liste = $('liste')
		liste.innerHTML = ''
		if (!z || !zustand.details) return
		var soll = VrAbgleich.sollMarker(zustand.details, z.versionId === null ? null : z.versionId, Number(z.versatz) || 0)
		var nachId = {}
		zustand.details.kommentare.forEach(function (k) { nachId[k.id] = k })
		if (soll.marker.length === 0) {
			var leer = document.createElement('li')
			leer.className = 'leer'
			leer.textContent = T('noComments')
			liste.appendChild(leer)
			return
		}
		soll.marker.forEach(function (m) {
			var k = nachId[m.id]
			var li = document.createElement('li')
			li.className = 'anm' + (k.erledigt ? ' anm--erledigt' : '')
			var kopf = document.createElement('div')
			kopf.className = 'anm-kopf'
			var tc = document.createElement('span')
			tc.className = 'tc'
			tc.textContent = k.timecode
			var name = document.createElement('span')
			name.className = 'anm-name'
			name.textContent = m.name
			kopf.appendChild(tc)
			kopf.appendChild(name)
			var text = document.createElement('p')
			text.textContent = k.text || T('drawingOnly')
			li.appendChild(kopf)
			li.appendChild(text)
			if (k.zeichnung) {
				var knopf = document.createElement('button')
				knopf.type = 'button'
				knopf.className = 'link'
				knopf.textContent = T('showDrawing')
				knopf.addEventListener('click', function (e) {
					e.stopPropagation()
					vorschau(k, soll.version)
				})
				li.appendChild(knopf)
			}
			li.addEventListener('click', function () {
				host('vrSpringen(' + literal(m.ticks) + ')').catch(function (e) { melden(e.message, true) })
			})
			liste.appendChild(li)
		})
	}

	/* ---------- Markierung: Vorschau und Bild ---------- */

	function zeichne(ctx, zeichnung, b, h) {
		if (!zeichnung || !Array.isArray(zeichnung.striche)) return
		ctx.save()
		ctx.strokeStyle = zeichnung.farbe
		ctx.lineWidth = Math.max(2, b * 0.005)
		ctx.lineCap = 'round'
		ctx.lineJoin = 'round'
		zeichnung.striche.forEach(function (s) {
			if (!Array.isArray(s) || s.length === 0) return
			ctx.beginPath()
			ctx.moveTo(s[0][0] * b, s[0][1] * h)
			if (s.length === 1) ctx.lineTo(s[0][0] * b + 0.01, s[0][1] * h)
			s.slice(1).forEach(function (p) { ctx.lineTo(p[0] * b, p[1] * h) })
			ctx.stroke()
		})
		ctx.restore()
	}

	function vorschau(k, version) {
		var rahmen = $('vorschau')
		var video = $('vorschau-video')
		var leinwand = $('vorschau-zeichnung')
		rahmen.hidden = false
		$('vorschau-titel').textContent = k.timecode + '  ' + (k.text || '')
		var b = version.breite || 1920
		var h = version.hoehe || 1080
		$('vorschau-rahmen').style.aspectRatio = b + ' / ' + h
		if (version.videoUrl) {
			video.src = zugang().server + version.videoUrl
			video.currentTime = (k.frameIn + 0.5) * version.fpsDen / version.fpsNum
		} else {
			video.removeAttribute('src')
		}
		var r = leinwand.getBoundingClientRect()
		leinwand.width = Math.round(r.width * (window.devicePixelRatio || 1))
		leinwand.height = Math.round(r.height * (window.devicePixelRatio || 1))
		var ctx = leinwand.getContext('2d')
		ctx.clearRect(0, 0, leinwand.width, leinwand.height)
		zeichne(ctx, k.zeichnung, leinwand.width, leinwand.height)
		$('vorschau-import').onclick = function () { importieren(k, version) }
	}

	/** Markierung als PNG mit durchsichtigem Grund ins Projekt (nicht in die Zeitleiste). */
	function importieren(k, version) {
		if (!nodeRequire) {
			melden(T('needsNode'), true)
			return
		}
		var fs = nodeRequire('fs')
		var path = nodeRequire('path')
		var os = nodeRequire('os')
		var c = document.createElement('canvas')
		c.width = version.breite || 1920
		c.height = version.hoehe || 1080
		zeichne(c.getContext('2d'), k.zeichnung, c.width, c.height)
		var daten = c.toDataURL('image/png').split(',')[1]
		var ablage = T('bin')
		var ordner = path.join(os.homedir(), 'Documents', ablage)
		fs.mkdirSync(ordner, { recursive: true })
		var titel = String(zustand.details.titel).replace(/[^\p{L}\p{N} _-]/gu, '_')
		var datei = path.join(ordner, titel + '_' + k.timecode.replace(/[:;]/g, '-') + '_' + k.id + '.png')
		fs.writeFileSync(datei, nodeRequire('buffer').Buffer.from(daten, 'base64'))
		host('vrBildImportieren(' + literal(datei) + ', ' + literal(ablage) + ')')
			.then(function () { melden(T('imported', { ablage: ablage })) })
			.catch(function (e) { melden(e.message, true) })
	}

	/* ---------- Ereignisse ---------- */

	function verdrahten() {
		VrSprache.anwenden(document)
		$('sprache').value = VrSprache.sprache()
		$('sprache').addEventListener('change', function () {
			VrSprache.setzen($('sprache').value)
			VrSprache.anwenden(document)
			zeichnen()
			abgleichen() // Markertexte („You“/„Du“) folgen der Sprache
		})
		var z = zugang()
		$('server').value = z.server
		$('schluessel').value = z.schluessel ? '••••••••' : ''
		$('einstellungen').open = !z.schluessel

		$('speichern').addEventListener('click', function () {
			schreiben('vr-server', $('server').value.trim())
			var neu = $('schluessel').value.trim()
			var imBund = true
			if (neu && neu !== '••••••••') {
				imBund = schluesselSchreiben(neu)
			}
			$('schluessel').value = '••••••••'
			$('einstellungen').open = false
			reviewsLaden().then(function () {
				abgleichen().then(function () {
					if (!imBund) melden(T('noKeychain'), true)
				})
			})
		})

		$('review').addEventListener('change', function () {
			if (!zustand.sequenz) return
			var id = Number($('review').value)
			if (!id) {
				schreiben('vr-zuordnung-' + zustand.sequenz.id, 'null')
				zustand.details = null
				zeichnen()
				return
			}
			zuordnungSpeichern(zustand.sequenz.id, { reviewId: id, versionId: null, versatz: 0 })
			abgleichen()
		})
		$('version').addEventListener('change', function () {
			if (!zustand.sequenz) return
			var z = zuordnung(zustand.sequenz.id)
			if (!z) return
			z.versionId = $('version').value ? Number($('version').value) : null
			zuordnungSpeichern(zustand.sequenz.id, z)
			abgleichen()
		})
		$('versatz').addEventListener('change', function () {
			if (!zustand.sequenz) return
			var z = zuordnung(zustand.sequenz.id)
			if (!z) return
			z.versatz = Math.round(Number($('versatz').value) || 0)
			zuordnungSpeichern(zustand.sequenz.id, z)
			abgleichen()
		})
		$('jetzt').addEventListener('click', function () { reviewsLaden().then(function () { abgleichen() }) })
		$('vorschau-zu').addEventListener('click', function () {
			$('vorschau').hidden = true
			$('vorschau-video').removeAttribute('src')
		})
	}

	verdrahten()
	reviewsLaden().then(function () { abgleichen() })
	setInterval(function () { abgleichen() }, TAKT)
	// Sequenzwechsel schneller bemerken als im 30-Sekunden-Takt.
	setInterval(function () {
		host('vrSequenz()').then(function (s) {
			var neu = s.sequenz ? s.sequenz.id : null
			var alt = zustand.sequenz ? zustand.sequenz.id : null
			if (neu !== alt) {
				zustand.sequenz = s.sequenz
				zustand.details = null
				zeichnenAuswahl()
				abgleichen()
			}
		}).catch(function () { /* still */ })
	}, 3000)
}())
