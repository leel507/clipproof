#!/usr/bin/env node
/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Übersetzungen für Clipproof. Sammelt alle Texte aus src/, lib/ und templates/, gleicht sie mit
 * den Wörterbüchern in l10n/quelle/ ab und schreibt die Nextcloud-Dateien l10n/<sprache>.js|json.
 *   node tools/l10n.mjs           schreiben, fehlende und überzählige Einträge melden
 *   node tools/l10n.mjs --liste   nur die gesammelten Quelltexte ausgeben
 * Wörterbücher: de.json duzt, de_DE.json enthält nur, was beim Siezen anders lautet; der Rest kommt
 * aus de.json. Mehrzahl als Liste [einzahl, mehrzahl] unter dem Schlüssel "einzahl::mehrzahl".
 */
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const WURZEL = new URL('..', import.meta.url).pathname
const MEHRZAHL = 'nplurals=2; plural=(n != 1);'

function dateien(ordner, endungen) {
	const aus = []
	for (const name of readdirSync(join(WURZEL, ordner))) {
		const pfad = join(ordner, name)
		if (statSync(join(WURZEL, pfad)).isDirectory()) aus.push(...dateien(pfad, endungen))
		else if (endungen.some((e) => name.endsWith(e))) aus.push(pfad)
	}
	return aus
}

const S = String.raw`'((?:[^'\\]|\\.)*)'`
const ent = (s) => s.replace(/\\(.)/g, '$1')
const MUSTER_EIN = [
	new RegExp(String.raw`\b(?:t|tt|tTr)\((?:'clipproof',\s*|A,\s*)?` + S, 'g'),
	new RegExp(String.raw`->t\(` + S, 'g'),
	new RegExp(String.raw`new (?:ReviewException|KommentarException)\(` + S, 'g'),
	new RegExp(String.raw`\$this->fehler\(\$eintrag,\s*` + S, 'g'),
]
const MUSTER_MEHR = [
	new RegExp(String.raw`\b(?:n|nTr)\((?:'clipproof',\s*|A,\s*)?` + S + String.raw`,\s*` + S, 'g'),
	new RegExp(String.raw`->n\(\s*` + S + String.raw`,\s*` + S, 'g'),
]

const einzeln = new Set(), mehrzahl = new Map()
for (const datei of [...dateien('src', ['.ts', '.vue']), ...dateien('lib', ['.php']), ...dateien('templates', ['.php'])]) {
	const text = readFileSync(join(WURZEL, datei), 'utf8')
	for (const m of MUSTER_MEHR) for (const [, a, b] of text.matchAll(m)) mehrzahl.set(`${ent(a)}::${ent(b)}`, [ent(a), ent(b)])
	for (const m of MUSTER_EIN) for (const [, a] of text.matchAll(m)) einzeln.add(ent(a))
}
// Ein n()-Aufruf ist auch ein t(…)-Treffer für die Einzahl, wenn er so beginnt: nicht doppelt führen.
for (const [, [a]] of mehrzahl) einzeln.delete(a)
// tTr('clipproof', s, v) mit Variable: Die App-ID ist kein Text.
einzeln.delete('clipproof')

if (process.argv.includes('--liste')) {
	for (const s of [...einzeln].sort()) console.log(JSON.stringify(s))
	for (const k of [...mehrzahl.keys()].sort()) console.log('MEHRZAHL ' + JSON.stringify(k))
	process.exit(0)
}

const lesen = (sprache) => JSON.parse(readFileSync(join(WURZEL, 'l10n/quelle', sprache + '.json'), 'utf8'))
const de = lesen('de'), sie = lesen('de_DE')
let probleme = 0
for (const s of einzeln) if (!(s in de)) { console.error('fehlt in de.json: ' + JSON.stringify(s)); probleme++ }
for (const k of mehrzahl.keys()) if (!(k in de)) { console.error('fehlt in de.json (Mehrzahl): ' + JSON.stringify(k)); probleme++ }
const bekannt = new Set([...einzeln, ...mehrzahl.keys()])
for (const k of Object.keys(de)) if (!bekannt.has(k)) console.error('überzählig in de.json: ' + JSON.stringify(k))
for (const k of Object.keys(sie)) if (!bekannt.has(k)) console.error('überzählig in de_DE.json: ' + JSON.stringify(k))

/** Nextcloud erwartet Mehrzahl als "_einzahl_::_mehrzahl_" mit einer Liste. */
function nextcloud(woerter) {
	const aus = {}
	for (const s of [...einzeln].sort()) if (s in woerter) aus[s] = woerter[s]
	for (const [k, [a, b]] of [...mehrzahl.entries()].sort()) if (k in woerter) aus[`_${a}_::_${b}_`] = woerter[k]
	return aus
}
for (const [sprache, woerter] of [['de', de], ['de_DE', { ...de, ...sie }]]) {
	const uebersetzung = nextcloud(woerter)
	const json = JSON.stringify(uebersetzung, null, 4)
	writeFileSync(join(WURZEL, 'l10n', sprache + '.js'), `OC.L10N.register(\n    "clipproof",\n    ${json.replace(/\n/g, '\n    ')},\n"${MEHRZAHL}");\n`)
	writeFileSync(join(WURZEL, 'l10n', sprache + '.json'), `{ "translations": ${json},"pluralForm" :"${MEHRZAHL}"\n}\n`)
}
console.log(`${einzeln.size} Texte, ${mehrzahl.size} mit Mehrzahl, ${probleme} fehlende Übersetzungen`)
process.exit(probleme > 0 ? 1 : 0)
