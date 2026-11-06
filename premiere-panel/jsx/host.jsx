/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Clipproof, ExtendScript-Teil (E10).
 *
 * ACHTUNG: ES3. Nur var und function, keine Pfeilfunktionen, keine
 * Template-Strings, kein JSON-Objekt, keine Kommas am Ende von Listen.
 * Daten kommen als Literal im Aufruf herein; zurück geht eine von Hand
 * gebaute JSON-Zeichenkette.
 *
 * Fasst nur Marker an, die „[vr:<id>]“ in den Kommentaren tragen. Alle
 * anderen Marker, Spuren und Clips bleiben unberührt.
 */

var VR_BIN = 'Clipproof drawings'; // Vorgabe; das Panel gibt den Namen in seiner Sprache mit

function vrText(s) {
	var t = String(s === undefined || s === null ? '' : s);
	var out = '"';
	for (var i = 0; i < t.length; i++) {
		var c = t.charAt(i);
		var n = t.charCodeAt(i);
		if (c === '"') {
			out += '\\"';
		} else if (c === '\\') {
			out += '\\\\';
		} else if (c === '\n') {
			out += '\\n';
		} else if (c === '\r') {
			out += '\\r';
		} else if (c === '\t') {
			out += '\\t';
		} else if (n < 32) {
			out += '\\u' + ('000' + n.toString(16)).slice(-4);
		} else {
			out += c;
		}
	}
	return out + '"';
}

function vrFehler(meldung) {
	return '{"fehler":' + vrText(meldung) + '}';
}

/** Aktive Sequenz: Kennung, Name, Bilddauer in Ticks. */
function vrSequenz() {
	try {
		var seq = app.project.activeSequence;
		if (!seq) {
			return '{"sequenz":null}';
		}
		return '{"sequenz":{"id":' + vrText(seq.sequenceID) +
			',"name":' + vrText(seq.name) +
			',"bildTicks":' + vrText(seq.timebase) + '}}';
	} catch (e) {
		return vrFehler(e.toString());
	}
}

/** Alle Marker der aktiven Sequenz, auch fremde (das Panel filtert). */
function vrMarkerLesen() {
	try {
		var seq = app.project.activeSequence;
		if (!seq) {
			return vrFehler('No sequence open');
		}
		var teile = [];
		var m = vrSchritt('Read markers', function () { return seq.markers.getFirstMarker(); });
		while (m) {
			var farbe = '';
			try {
				farbe = m.getColorByIndex ? m.getColorByIndex() : '';
			} catch (e1) {
				farbe = '';
			}
			teile.push('{"guid":' + vrText(m.guid) +
				',"name":' + vrText(m.name) +
				',"kommentare":' + vrText(m.comments) +
				',"ticks":' + vrText(m.start.ticks) +
				',"farbe":' + vrText(farbe) + '}');
			m = seq.markers.getNextMarker(m);
		}
		return '{"marker":[' + teile.join(',') + ']}';
	} catch (e) {
		return vrFehler(e.message || e.toString());
	}
}

function vrMarkerNachGuid(seq, guid) {
	var m = seq.markers.getFirstMarker();
	while (m) {
		if (m.guid === guid) {
			return m;
		}
		m = seq.markers.getNextMarker(m);
	}
	return null;
}

var VR_TICKS = 254016000000;

/** Ein Schritt mit eigener Fehlermeldung: sagt, was in Premiere scheiterte. */
function vrSchritt(name, fn) {
	try {
		return fn();
	} catch (e) {
		throw new Error(name + ': ' + e.toString());
	}
}

/**
 * Zeitpunkt eines Markers setzen. Erst als Zeitobjekt in Ticks (exakt),
 * und wenn Premiere das ablehnt („Illegal Parameter type“, Premiere 26.5.2,
 * 18.10.2026), als Sekunden. Danach nachlesen: weicht der Marker mehr als
 * eine Millisekunde ab, ist das ein Fehler statt eines falschen Bildes.
 */
function vrZeitSetzen(m, feld, ticks) {
	var ok = false;
	try {
		var t = new Time();
		t.ticks = String(ticks);
		m[feld] = t;
		ok = true;
	} catch (e1) {
		ok = false;
	}
	if (!ok) {
		m[feld] = Number(ticks) / VR_TICKS;
	}
	var ist = Number(m[feld].ticks);
	if (Math.abs(ist - Number(ticks)) > VR_TICKS / 1000) {
		throw new Error('Marker liegt bei ' + ist + ' statt ' + ticks + ' Ticks');
	}
}

function vrSetzen(m, soll) {
	vrSchritt('Set marker time', function () { vrZeitSetzen(m, 'start', soll.ticks); });
	// Ende und Farbe sind Beiwerk: Scheitern sie, bleibt der Marker richtig.
	try {
		vrZeitSetzen(m, 'end', soll.ticks);
	} catch (e2) {}
	vrSchritt('Set marker name', function () { m.name = String(soll.name); });
	vrSchritt('Set marker comment', function () { m.comments = String(soll.kommentare); });
	var farbeOk = true;
	try {
		if (m.setColorByIndex) {
			m.setColorByIndex(Number(soll.farbe));
		}
	} catch (e3) {
		farbeOk = false;
	}
	return farbeOk;
}

/**
 * Plan anwenden: { neu: [soll], aendern: [{ guid, soll }], entfernen: [guid] }.
 * soll: { name, kommentare, ticks (Zeichenkette), farbe }.
 */
function vrMarkerAnwenden(plan) {
	try {
		var seq = app.project.activeSequence;
		if (!seq) {
			return vrFehler('No sequence open');
		}
		var i;
		var n = { neu: 0, geaendert: 0, entfernt: 0, ohneFarbe: 0 };
		for (i = 0; i < plan.entfernen.length; i++) {
			var weg = vrMarkerNachGuid(seq, plan.entfernen[i]);
			// Nur eigene Marker, auch wenn der Plan etwas anderes sagt.
			if (weg && String(weg.comments).indexOf('[vr:') >= 0) {
				vrSchritt('Remove marker', function () { seq.markers.deleteMarker(weg); });
				n.entfernt++;
			}
		}
		for (i = 0; i < plan.aendern.length; i++) {
			var alt = vrMarkerNachGuid(seq, plan.aendern[i].guid);
			if (alt && String(alt.comments).indexOf('[vr:') >= 0) {
				if (!vrSetzen(alt, plan.aendern[i].soll)) {
					n.ohneFarbe++;
				}
				n.geaendert++;
			}
		}
		for (i = 0; i < plan.neu.length; i++) {
			var s = plan.neu[i];
			var m = vrSchritt('Create marker', function () { return seq.markers.createMarker(Number(s.ticks) / VR_TICKS); });
			if (!vrSetzen(m, s)) {
				n.ohneFarbe++;
			}
			n.neu++;
		}
		return '{"neu":' + n.neu + ',"geaendert":' + n.geaendert + ',"entfernt":' + n.entfernt + ',"ohneFarbe":' + n.ohneFarbe + '}';
	} catch (e) {
		return vrFehler(e.message || e.toString());
	}
}

/** Abspielkopf auf eine Stelle (Ticks als Zeichenkette). */
function vrSpringen(ticks) {
	try {
		var seq = app.project.activeSequence;
		if (!seq) {
			return vrFehler('No sequence open');
		}
		vrSchritt('Set playhead', function () { seq.setPlayerPosition(String(ticks)); });
		return '{"ok":true}';
	} catch (e) {
		return vrFehler(e.message || e.toString());
	}
}

/**
 * Markierung (PNG mit durchsichtigem Hintergrund) ins Projekt holen, in
 * eine eigene Ablage. Nicht in die Zeitleiste: Das entscheidet der Nutzer.
 */
function vrBildImportieren(pfad, ablageName) {
	var name = ablageName ? String(ablageName) : VR_BIN;
	try {
		var wurzel = app.project.rootItem;
		var ablage = null;
		for (var i = 0; i < wurzel.children.numItems; i++) {
			var k = wurzel.children[i];
			if (k.name === name && k.type === ProjectItemType.BIN) {
				ablage = k;
			}
		}
		if (!ablage) {
			ablage = vrSchritt('Create bin', function () { return wurzel.createBin(name); });
		}
		vrSchritt('Import image', function () { app.project.importFiles([String(pfad)], true, ablage, false); });
		return '{"ok":true}';
	} catch (e) {
		return vrFehler(e.message || e.toString());
	}
}
