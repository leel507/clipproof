/*!
 * SPDX-FileCopyrightText: 2026 Schauties Media
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Alle Texte, die ein Gast auf der Kundenseite sieht. Quelltext englisch, Übersetzungen in
 * l10n/. Die Anrede steckt in der Übersetzung: Der Server lädt für deutsche Besucher `de` (du)
 * oder `de_DE` (Sie), je nach Einstellung am Review. Keine Gedankenstriche.
 */
import { n, t } from '@nextcloud/l10n'

export type Anrede = 'du' | 'sie'

/** Was der Server über Besitzer, Datenschutz und Fristen mitgibt. */
export interface KundenInfo {
	/** Anzeigename des Besitzers, steht anstelle eines festen Firmennamens. */
	besitzer: string
	/** Datenschutzerklärung der Instanz (Theming), leer, wenn keine eingetragen ist. */
	datenschutzUrl: string
	/** Jahre im Archiv nach Ende des Eingangsjahres, 0 = bis der Besitzer löscht. */
	archivJahre: number
	/** Tage, die der Link nach der Freigabe gilt. */
	linkTage: number
}

const A = 'clipproof'

export function texte(info: KundenInfo) {
	const owner = info.besitzer
	const tt = (s: string, v: Record<string, string | number> = {}): string => t(A, s, { owner, ...v })
	const aufbewahrung = info.archivJahre > 0
		? n(A, 'Sent comments are kept for %n year after the end of the year they were sent and then deleted. During that time they are only stored and the link no longer works.', 'Sent comments are kept for %n years after the end of the year they were sent and then deleted. During that time they are only stored and the link no longer works.', info.archivJahre)
		: tt('Sent comments are kept until {owner} deletes the review.')
	return {
		laden: tt('Loading video …'),
		ladeFehler: tt('The video cannot be loaded right now. Please reload the page.'),
		version: tt('Version'),

		nameFrage: tt('What is your name?'),
		nameErklaerung: tt('Your name is shown with your comments so {owner} knows who they are from.'),
		nameFeld: tt('Name'),
		datenschutzKurz: tt('{owner} stores your name and your comments to finalize this video with you.'),
		datenschutzLink: tt('Full privacy policy'),
		datenschutzUrl: info.datenschutzUrl,
		datenschutzMehr: tt('More about privacy'),
		datenschutzPunkte: [
			tt('Purpose: finalizing this video. Sent comments count as change requests.'),
			tt('Stored are your name, your comments and when you wrote them. No IP address, no cookie.'),
			aufbewahrung,
			n(A, 'Unsent drafts are deleted no later than %n day after the video is approved.', 'Unsent drafts are deleted no later than %n days after the video is approved.', info.linkTage),
			tt('You can request access, correction and deletion and file a complaint with a data protection authority.'),
		],
		nameWeiter: tt('Continue'),

		anmerkungFeld: tt('Write a comment …'),
		anmerkungBei: (tc: string) => tt('Comment at {timecode}', { timecode: tc }),
		antwortFeld: tt('Write a reply …'),
		antwortAn: (name: string) => tt('Reply to {name}', { name }),
		senden: tt('Send'),
		enterHinweis: tt('Enter sends · Shift+Enter new line'),
		folge: tt('Enter sends your comment to {owner} right away. It counts as a change request and cannot be edited afterwards.'),
		zeichnen: tt('Draw on the frame'),
		zeichnenAus: tt('Stop drawing'),
		zeichnenHinweis: tt('Mark the spot in the frame, then write your comment and press Enter'),
		markieren: tt('Mark'),
		fertig: tt('Done'),
		farbe: (name: string) => tt('Color {color}', { color: name }),
		farben: { rot: tt('Red'), gelb: tt('Yellow'), gruen: tt('Green'), blau: tt('Blue'), weiss: tt('White') },
		rueckgaengig: tt('Undo last stroke'),
		zeichnungLoeschen: tt('Discard drawing'),
		mitZeichnung: tt('with drawing'),
		zeichnungAnzeigen: tt('Show drawing'),

		liste: tt('Comments'),
		leer: tt('No comments yet'),
		leerHinweis: tt('Pause the video at the right spot, write your comment below and press Enter.'),
		entwurf: tt('not sent'),
		entwuerfeSenden: (zahl: number) => n(A, '%n comment not sent yet. Send now', '%n comments not sent yet. Send now', zahl),
		vonBesitzer: owner,
		// Bewusst nicht „erledigt“: Es darf nicht nach einer Zustimmung des Gastes aussehen.
		bearbeitet: tt('Handled by {owner}'),
		bearbeitetHinweis: tt('{owner} has handled this comment. Whether it fits, you decide when you watch the next version.'),
		antworten: tt('Reply'),
		abbrechen: tt('Cancel'),
		alsBild: tt('Save as image'),
		springen: (tc: string) => tt('Jump to {timecode}', { timecode: tc }),
		sendetGerade: tt('Sending …'),
		bestaetigung: (zahl: number) => n(A, 'Your comment has reached {owner}.', 'Your %n comments have reached {owner}.', zahl, { owner }),
		gesperrt: tt('This video has been approved. Comments are no longer possible.'),
		fehler: tt('That did not work. Please try again.'),
		keinNetz: tt('No connection. Your comment is not lost: it is still in the field or listed as “not sent”. Please send it again in a moment.'),
		zuSchnell: tt('A lot of comments arrived at once. Please wait a minute and send again.'),
		linkWeg: tt('This link is no longer valid. Please contact {owner}.'),
		videoFehlt: tt('The video cannot be loaded right now. Reload the page; if that does not help, please let {owner} know.'),
		neuLaden: tt('Reload page'),

		firefox: tt('In this browser the player jumps to the middle of the frame. That is very accurate but not guaranteed to the single frame. For frame accurate comments use Chrome, Edge or Safari.'),
	}
}

export type Texte = ReturnType<typeof texte>
