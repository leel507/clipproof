/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Sprache des Panels: Englisch (Vorgabe) oder Deutsch, einstellbar unter
 * „Access“. Gespeichert im localStorage des Panels („vr-sprache“).
 *
 * VrSprache.t('schluessel', { wert: 1 }) liefert den Text mit eingesetzten
 * Platzhaltern {wert}. Elemente mit data-t, data-t-title oder
 * data-t-placeholder setzt VrSprache.anwenden() auf die aktuelle Sprache.
 */
(function (wurzel) {
	'use strict'

	var TEXTE = {
		en: {
			sync: 'Sync',
			syncTitle: 'Sync now',
			access: 'Access',
			server: 'Server',
			panelKey: 'Panel key',
			panelKeyHint: 'from Clipproof, bottom left',
			language: 'Language',
			save: 'Save',
			keyNote: 'The key can only read your reviews and is stored in the macOS keychain. Create and revoke it in Clipproof, bottom left under “Premiere panel”.',
			sequence: 'Sequence',
			none: 'none',
			review: 'Review',
			version: 'Version',
			offset: 'Offset',
			offsetTitle: 'Frames by which the proxy is shifted in the sequence. 0 if the proxy is the whole sequence.',
			loading: 'Loading …',
			importImage: 'Import as image',
			close: 'Close',
			notInPremiere: 'Not running in Premiere',
			unreadable: 'Unreadable answer from Premiere: {text}',
			serverMissing: 'Server missing (Access)',
			keyMissing: 'Panel key missing (Access)',
			keyInvalid: 'Panel key invalid or revoked',
			error: 'Error {status}',
			timeout: 'Timed out',
			noSequence: 'No sequence open.',
			notLinked: 'This sequence is not linked to a review yet.',
			synced: '{n} markers, synced {zeit}',
			changes: ' ({neu} new, {geaendert} changed, {entfernt} removed)',
			noColor: '. Color not set on {n} markers (position is correct).',
			noReview: '(no review)',
			open: ' ({n} open)',
			latest: 'Latest version',
			versionN: 'Version {n}',
			noComments: 'No comments in this version yet.',
			drawingOnly: '(drawing only)',
			showDrawing: 'Show drawing',
			needsNode: 'Importing images needs Node in the panel.',
			bin: 'Clipproof drawings',
			imported: 'Drawing imported into the bin “{ablage}”.',
			noKeychain: 'Keychain not available: the key only lasts until the panel is closed and is not saved.',
			you: 'You',
			withDrawing: '[with drawing on the frame]',
		},
		de: {
			sync: 'Abgleichen',
			syncTitle: 'Jetzt abgleichen',
			access: 'Zugang',
			server: 'Server',
			panelKey: 'Panel-Schlüssel',
			panelKeyHint: 'aus Clipproof, links unten',
			language: 'Sprache',
			save: 'Speichern',
			keyNote: 'Der Schlüssel kann nur deine Reviews lesen und liegt im Schlüsselbund von macOS. Erzeugen und widerrufen in Clipproof, links unten unter „Premiere-Panel“.',
			sequence: 'Sequenz',
			none: 'keine',
			review: 'Review',
			version: 'Version',
			offset: 'Versatz',
			offsetTitle: 'Bilder, um die der Proxy in der Sequenz verschoben ist. 0, wenn der Proxy die ganze Sequenz ist.',
			loading: 'Wird geladen …',
			importImage: 'Als Bild ins Projekt',
			close: 'Schließen',
			notInPremiere: 'Nicht in Premiere',
			unreadable: 'Antwort aus Premiere unlesbar: {text}',
			serverMissing: 'Server fehlt (Zugang)',
			keyMissing: 'Panel-Schlüssel fehlt (Zugang)',
			keyInvalid: 'Panel-Schlüssel ungültig oder widerrufen',
			error: 'Fehler {status}',
			timeout: 'Zeitüberschreitung',
			noSequence: 'Keine Sequenz geöffnet.',
			notLinked: 'Diese Sequenz ist noch keinem Review zugeordnet.',
			synced: '{n} Marker, abgeglichen {zeit}',
			changes: ' ({neu} neu, {geaendert} geändert, {entfernt} entfernt)',
			noColor: '. Farbe bei {n} Markern nicht gesetzt (Position stimmt).',
			noReview: '(kein Review)',
			open: ' ({n} offen)',
			latest: 'Neueste Version',
			versionN: 'Version {n}',
			noComments: 'Noch keine Anmerkungen in dieser Version.',
			drawingOnly: '(nur Markierung)',
			showDrawing: 'Markierung zeigen',
			needsNode: 'Bildimport braucht Node im Panel.',
			bin: 'Clipproof Markierungen',
			imported: 'Markierung in der Ablage „{ablage}“.',
			noKeychain: 'Schlüsselbund nicht erreichbar: Der Schlüssel gilt nur, bis das Panel geschlossen wird, und wird nicht gespeichert.',
			you: 'Du',
			withDrawing: '[mit Markierung im Bild]',
		},
	}

	/* Meldungen aus dem ExtendScript-Teil (englische Quelltexte) */
	var VON_PREMIERE = { 'No sequence open': 'noSequence' }

	function sprache() {
		try {
			var s = wurzel.localStorage && wurzel.localStorage.getItem('vr-sprache')
			return s === 'de' ? 'de' : 'en'
		} catch (e) {
			return 'en'
		}
	}

	function setzen(s) {
		try {
			wurzel.localStorage.setItem('vr-sprache', s === 'de' ? 'de' : 'en')
		} catch (e) {
			// Ohne Speicher gilt die Wahl bis zum Schließen nicht; Englisch bleibt.
		}
	}

	function t(schluessel, werte) {
		var text = TEXTE[sprache()][schluessel]
		if (text === undefined) text = TEXTE.en[schluessel] !== undefined ? TEXTE.en[schluessel] : schluessel
		return String(text).replace(/\{(\w+)\}/g, function (ganz, name) {
			return werte && werte[name] !== undefined ? String(werte[name]) : ganz
		})
	}

	function vonPremiere(meldung) {
		var schluessel = VON_PREMIERE[String(meldung).replace(/\.$/, '')]
		return schluessel ? t(schluessel) : meldung
	}

	function anwenden(dokument) {
		var d = dokument || wurzel.document
		d.documentElement.lang = sprache()
		Array.prototype.forEach.call(d.querySelectorAll('[data-t]'), function (el) { el.textContent = t(el.getAttribute('data-t')) })
		Array.prototype.forEach.call(d.querySelectorAll('[data-t-title]'), function (el) { el.title = t(el.getAttribute('data-t-title')) })
		Array.prototype.forEach.call(d.querySelectorAll('[data-t-placeholder]'), function (el) { el.placeholder = t(el.getAttribute('data-t-placeholder')) })
	}

	var api = { t: t, sprache: sprache, setzen: setzen, anwenden: anwenden, vonPremiere: vonPremiere, TEXTE: TEXTE }
	wurzel.VrSprache = api
	if (typeof module === 'object' && module && module.exports) {
		module.exports = api
	}
}(typeof self !== 'undefined' ? self : this))
