<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\Guest;
use OCA\Clipproof\Db\GuestMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\Submission;
use OCP\IUserManager;

/**
 * Wandelt Anmerkungen in das JSON für Kundenseite und Besitzeransicht.
 * Frame ist der nullbasierte Index ab Dateianfang, Timecode die Anzeige
 * mit Start-Timecode (festgelegt nach E2).
 */
class KommentarAnsicht {
	/** @var array<string, string> Anzeigenamen nach Nutzer-ID */
	private array $namen = [];

	public function __construct(
		private GuestMapper $guests,
		private IUserManager $users,
	) {
	}

	/** Anzeigename des Besitzers, unter dem seine Antworten beim Kunden erscheinen. */
	public function besitzerName(string $uid): string {
		return $this->namen[$uid] ??= ($this->users->getDisplayName($uid) ?? $uid);
	}

	/**
	 * @param list<Comment> $kommentare
	 * @param array<int, ReviewVersion> $versionen nach Id
	 * @param array<int, Submission> $abgaben nach Id
	 * @param bool $fuerBesitzer Für die Besitzeransicht. „Erledigt“ sehen auch
	 *                       Kunden, dort als „von <Besitzer> bearbeitet“.
	 * @return list<array<string, mixed>>
	 */
	public function liste(array $kommentare, array $versionen, array $abgaben, ?Guest $betrachter, bool $fuerBesitzer = false): array {
		$gastIds = array_values(array_unique(array_filter(array_map(fn (Comment $k) => $k->getGuestId(), $kommentare))));
		$gaeste = $this->guests->findByIds($gastIds);
		$out = [];
		foreach ($kommentare as $k) {
			$version = $versionen[(int)$k->getVersionId()] ?? null;
			if ($version === null) {
				continue;
			}
			$abgabe = $k->getSubmissionId() !== null ? ($abgaben[(int)$k->getSubmissionId()] ?? null) : null;
			$istBesitzer = $k->getAuthorType() === Comment::BESITZER;
			$out[] = [
				'id' => $k->getId(),
				'versionId' => $k->getVersionId(),
				'parentId' => $k->getParentId(),
				'autorTyp' => $k->getAuthorType(),
				'autor' => $istBesitzer ? $this->besitzerName((string)$k->getAuthorUid()) : ($gaeste[(int)$k->getGuestId()]?->getName() ?? ''),
				'eigen' => $betrachter !== null && $k->getGuestId() === $betrachter->getId(),
				'entwurf' => !$istBesitzer && $k->getSubmissionId() === null,
				'abgesendetAm' => $abgabe !== null ? gmdate('Y-m-d\TH:i:s\Z', (int)$abgabe->getCreatedAt()) : null,
				'frameIn' => $k->getFrameIn(),
				'frameOut' => $k->getFrameOut(),
				'timecode' => Timecode::vonFrames((int)$version->getStartTcFrames() + (int)$k->getFrameIn(), (int)$version->getFpsNum(), (int)$version->getFpsDen(), $version->isDropFrame()),
				'text' => $k->getText(),
				'zeichnung' => $k->getDrawing() !== null ? json_decode($k->getDrawing(), true) : null,
				'erledigt' => $k->getDone() === true,
				'erstelltAm' => gmdate('Y-m-d\TH:i:s\Z', (int)$k->getCreatedAt()),
			];
		}
		return $out;
	}
}
