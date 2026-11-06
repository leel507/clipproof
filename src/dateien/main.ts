/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * „Zur Revision freigeben“ im Drei-Punkte-Menü der Dateien-App. Wird nur für
 * Nutzer geladen, die Clipproof nutzen dürfen (DateienSkript.php). Schlägt die
 * neue Version eines passenden Reviews vor; der Nutzer bestätigt.
 *
 * Bewusst ohne Vue: ein kleines Fenster im Stil von Nextcloud, damit die
 * Dateien-App nicht spürbar langsamer lädt.
 */
import { registerFileAction } from '@nextcloud/files'
import { getRequestToken } from '@nextcloud/auth'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { t as tTr } from '@nextcloud/l10n'

const t = (s: string, v: Record<string, string | number> = {}): string => tTr('clipproof', s, v)

const VIDEO = ['video/mp4', 'video/quicktime']

const SYMBOL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M4 5h11a2 2 0 0 1 2 2v2.4l3.3-2.2A1 1 0 0 1 22 8v8a1 1 0 0 1-1.7.8L17 14.6V17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm4 4.5v5l4-2.5-4-2.5Z"/></svg>'

interface Kurz { reviewId: number, titel: string, version: number, naechsteVersion?: number }
interface Vorschlag { datei: string, gruppe: string, schon: Kurz | null, vorschlag: Kurz | null }
interface Ergebnis { reviewId: number, titel: string, version: number, link: string | null, ansicht: string }

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
	if (!antwort.ok) throw new Error((inhalt as { fehler?: string } | undefined)?.fehler ?? t('Error {status}', { status: antwort.status }))
	return inhalt as T
}

/** Element mit Text, nie mit HTML aus Daten. */
function el<K extends keyof HTMLElementTagNameMap>(tag: K, text?: string, klasse?: string): HTMLElementTagNameMap[K] {
	const e = document.createElement(tag)
	if (text !== undefined) e.textContent = text
	if (klasse) e.className = klasse
	return e
}

function fenster(titel: string): { koerper: HTMLElement, knoepfe: HTMLElement, schliessen: () => void } {
	const grund = el('div', undefined, 'vrd-grund')
	const box = el('div', undefined, 'vrd-box')
	box.setAttribute('role', 'dialog')
	box.setAttribute('aria-modal', 'true')
	box.setAttribute('aria-label', titel)
	box.appendChild(el('h2', titel))
	const koerper = el('div', undefined, 'vrd-koerper')
	const knoepfe = el('div', undefined, 'vrd-knoepfe')
	box.append(koerper, knoepfe)
	grund.appendChild(box)
	const schliessen = () => grund.remove()
	grund.addEventListener('click', (e) => { if (e.target === grund) schliessen() })
	grund.addEventListener('keydown', (e) => { if (e.key === 'Escape') schliessen() })
	document.body.appendChild(grund)
	return { koerper, knoepfe, schliessen }
}

function knopf(text: string, primaer: boolean, tun: () => void): HTMLButtonElement {
	const b = el('button', text, primaer ? 'vrd-knopf vrd-knopf--primaer' : 'vrd-knopf')
	b.type = 'button'
	b.addEventListener('click', tun)
	return b
}

function linkZeile(link: string): HTMLElement {
	const zeile = el('div', undefined, 'vrd-link')
	const feld = el('input')
	feld.readOnly = true
	feld.value = link
	feld.addEventListener('focus', () => feld.select())
	const kopieren = knopf(t('Copy'), false, async () => {
		await navigator.clipboard.writeText(link)
		kopieren.textContent = t('Copied')
	})
	zeile.append(feld, kopieren)
	return zeile
}

function ergebnisZeigen(f: ReturnType<typeof fenster>, e: Ergebnis): void {
	f.koerper.replaceChildren()
	f.knoepfe.replaceChildren()
	f.koerper.appendChild(el('p', e.version === 1
		? t('Released: new review “{title}”.', { title: e.titel })
		: t('Released: version {version} of “{title}”. The client link stays the same.', { version: e.version, title: e.titel }), 'vrd-erfolg'))
	f.koerper.appendChild(el('p', e.version === 1
		? t('Send this link to your client:')
		: t('Your client sees the new version under the existing link.'), 'vrd-leise'))
	if (e.link) f.koerper.appendChild(linkZeile(e.link))
	const oeffnen = el('a', t('Open in Clipproof'), 'vrd-knopf')
	oeffnen.href = e.ansicht
	f.knoepfe.append(oeffnen, knopf(t('Done'), true, f.schliessen))
}

async function freigebenDialog(fileId: number, name: string): Promise<void> {
	const f = fenster(t('Release for review'))
	f.koerper.appendChild(el('p', name, 'vrd-datei'))
	const warten = el('p', t('Checking …'), 'vrd-leise')
	f.koerper.appendChild(warten)
	let v: Vorschlag
	try {
		v = await ocs<Vorschlag>('GET', `/freigabe/${fileId}`)
	} catch (e) {
		warten.textContent = (e as Error).message
		f.knoepfe.appendChild(knopf(t('Close'), true, f.schliessen))
		return
	}
	warten.remove()

	if (v.schon) {
		f.koerper.appendChild(el('p', t('This file is already version {version} of “{title}”.', { version: v.schon.version, title: v.schon.titel })))
		const oeffnen = el('a', t('Open in Clipproof'), 'vrd-knopf')
		oeffnen.href = generateUrl('/apps/clipproof/') + `?review=${v.schon.reviewId}`
		f.knoepfe.append(oeffnen, knopf(t('Close'), true, f.schliessen))
		return
	}

	const wahl = el('div', undefined, 'vrd-wahl')
	const option = (wert: string, text: string, an: boolean): HTMLInputElement => {
		const label = el('label', undefined, 'vrd-option')
		const r = el('input')
		r.type = 'radio'
		r.name = 'vrd-ziel'
		r.value = wert
		r.checked = an
		label.append(r, el('span', text))
		wahl.appendChild(label)
		return r
	}
	const alsVersion = v.vorschlag
		? option('version', t('As a new version of “{title}” (becomes version {version})', { title: v.vorschlag.titel, version: v.vorschlag.naechsteVersion ?? '' }), true)
		: null
	const alsNeu = option('neu', t('New review'), !v.vorschlag)
	const titel = el('input', undefined, 'vrd-titel')
	titel.value = v.gruppe
	titel.setAttribute('aria-label', t('Title of the new review'))
	titel.disabled = !alsNeu.checked
	wahl.appendChild(titel)
	wahl.addEventListener('change', () => { titel.disabled = !alsNeu.checked })
	f.koerper.appendChild(wahl)
	const fehler = el('p', '', 'vrd-fehler')
	f.koerper.appendChild(fehler)

	const los = knopf(t('Release'), true, async () => {
		los.disabled = true
		fehler.textContent = ''
		try {
			const e = await ocs<Ergebnis>('POST', '/freigabe', {
				fileId,
				reviewId: alsVersion?.checked && v.vorschlag ? v.vorschlag.reviewId : null,
				titel: titel.value,
			})
			ergebnisZeigen(f, e)
		} catch (e) {
			fehler.textContent = (e as Error).message
			los.disabled = false
		}
	})
	f.knoepfe.append(knopf(t('Cancel'), false, f.schliessen), los)
	los.focus()
}

const stil = document.createElement('style')
stil.textContent = `
.vrd-grund { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
.vrd-box { width: min(460px, 100%); max-height: calc(100% - 32px); overflow: auto; padding: 20px; border-radius: var(--border-radius-large, 12px); background: var(--color-main-background); color: var(--color-main-text); box-shadow: 0 16px 48px rgba(0,0,0,.35); }
.vrd-box h2 { margin: 0 0 12px; font-size: 18px; font-weight: 600; }
.vrd-datei { margin: 0 0 12px; font-weight: 500; word-break: break-all; }
.vrd-leise { color: var(--color-text-maxcontrast); font-size: 13px; margin: 10px 0 0; }
.vrd-fehler { color: var(--color-error-text, var(--color-error)); font-size: 13px; margin: 8px 0 0; min-height: 1em; }
.vrd-erfolg { margin: 0; font-weight: 500; }
.vrd-wahl { display: flex; flex-direction: column; gap: 8px; }
.vrd-option { display: flex; gap: 8px; align-items: flex-start; cursor: pointer; }
.vrd-option input { margin-top: 3px; }
.vrd-titel { margin-left: 24px; width: calc(100% - 24px); }
.vrd-link { display: flex; gap: 6px; margin-top: 8px; }
.vrd-link input { flex: 1; min-width: 0; font-family: monospace; }
.vrd-knoepfe { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
.vrd-knopf { display: inline-flex; align-items: center; min-height: 34px; padding: 0 14px; border-radius: var(--border-radius-element, 8px); border: 1px solid var(--color-border-maxcontrast); background: var(--color-main-background); color: var(--color-main-text); text-decoration: none; cursor: pointer; }
.vrd-knopf--primaer { border-color: transparent; background: var(--color-primary-element); color: var(--color-primary-element-text); }
.vrd-knopf:disabled { opacity: .5; cursor: default; }
`
document.head.appendChild(stil)

registerFileAction({
	id: 'clipproof-freigeben',
	displayName: () => t('Release for review'),
	iconSvgInline: () => SYMBOL,
	order: 60,
	enabled: ({ nodes }) => nodes.length === 1 && nodes[0].fileid !== undefined && VIDEO.includes(nodes[0].mime),
	exec: async ({ nodes }) => {
		await freigebenDialog(nodes[0].fileid as number, nodes[0].basename)
		return null
	},
})
