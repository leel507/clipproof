<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\Guest;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Exception\KommentarException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Anmerkungen anlegen, ändern, löschen. Kunden schreiben Entwürfe, die
 * erst die Abgabe festschreibt (siehe AbgabeService). Kommentare des Besitzers
 * sind sofort sichtbar und lösen nie eine Beanstandung aus.
 */
class CommentService {
	public const TEXT_MAX = 5000;
	public const ZEICHNUNG_MAX = 200 * 1024;
	public const STRICHE_MAX = 200;
	public const PUNKTE_MAX = 20000;

	public function __construct(
		private CommentMapper $comments,
		private ReviewVersionMapper $versions,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array{versionId: int, frameIn: int, frameOut?: ?int, text?: string, zeichnung?: ?string, parentId?: ?int} $daten
	 * @throws KommentarException
	 */
	public function entwurfAnlegen(Review $review, Guest $gast, array $daten): Comment {
		$this->offen($review);
		$kommentar = $this->aufbauen($review, $daten);
		$kommentar->setAuthorType(Comment::KUNDE);
		$kommentar->setGuestId($gast->getId());
		return $this->comments->insert($kommentar);
	}

	/**
	 * @param array{versionId: int, frameIn: int, frameOut?: ?int, text?: string, zeichnung?: ?string, parentId?: ?int} $daten
	 * @throws KommentarException
	 */
	public function besitzerKommentiert(Review $review, string $uid, array $daten): Comment {
		$kommentar = $this->aufbauen($review, $daten);
		$kommentar->setAuthorType(Comment::BESITZER);
		$kommentar->setAuthorUid($uid);
		return $this->comments->insert($kommentar);
	}

	/**
	 * Eigenen Entwurf ändern. Abgesendetes ist fest.
	 *
	 * @param array{text?: string, zeichnung?: ?string, frameIn?: int, frameOut?: ?int} $daten
	 * @throws KommentarException
	 */
	public function entwurfAendern(Review $review, Guest $gast, int $id, array $daten): Comment {
		$this->offen($review);
		$kommentar = $this->eigenerEntwurf($review, $gast, $id);
		$version = $this->version($review, (int)$kommentar->getVersionId());
		if (array_key_exists('text', $daten)) {
			$kommentar->setText($this->text($daten['text']));
		}
		if (array_key_exists('zeichnung', $daten)) {
			$kommentar->setDrawing($this->zeichnung($daten['zeichnung']));
		}
		if (array_key_exists('frameIn', $daten)) {
			[$in, $out] = $this->frames($version, $daten['frameIn'], $daten['frameOut'] ?? null);
			$kommentar->setFrameIn($in);
			$kommentar->setFrameOut($out);
		}
		$this->inhaltPruefen($kommentar);
		$kommentar->setUpdatedAt($this->time->getTime());
		return $this->comments->update($kommentar);
	}

	/** @throws KommentarException */
	public function entwurfLoeschen(Review $review, Guest $gast, int $id): void {
		$this->offen($review);
		$this->comments->delete($this->eigenerEntwurf($review, $gast, $id));
	}

	/**
	 * Der Besitzer hakt ab oder nimmt den Haken zurück.
	 *
	 * @throws KommentarException
	 */
	public function erledigt(Review $review, int $id, bool $erledigt): Comment {
		$kommentar = $this->imReview($review, $id);
		if ($kommentar->getAuthorType() === Comment::KUNDE && $kommentar->getSubmissionId() === null) {
			// Entwürfe gibt es für den Besitzer nicht.
			throw new KommentarException('Not found.', 404);
		}
		$kommentar->setDone($erledigt);
		$kommentar->setUpdatedAt($this->time->getTime());
		return $this->comments->update($kommentar);
	}

	/** @return list<int> */
	public function versionIds(Review $review): array {
		return array_map(fn (ReviewVersion $v) => (int)$v->getId(), $this->versions->findForReview((int)$review->getId()));
	}

	/**
	 * @param array{versionId: int, frameIn: int, frameOut?: ?int, text?: string, zeichnung?: ?string, parentId?: ?int} $daten
	 * @throws KommentarException
	 */
	private function aufbauen(Review $review, array $daten): Comment {
		$version = $this->version($review, (int)$daten['versionId']);
		[$in, $out] = $this->frames($version, $daten['frameIn'], $daten['frameOut'] ?? null);
		$kommentar = new Comment();
		$kommentar->setVersionId($version->getId());
		$kommentar->setFrameIn($in);
		$kommentar->setFrameOut($out);
		$kommentar->setText($this->text($daten['text'] ?? ''));
		$kommentar->setDrawing($this->zeichnung($daten['zeichnung'] ?? null));
		$kommentar->setDone(false);
		$parentId = $daten['parentId'] ?? null;
		if ($parentId !== null) {
			$eltern = $this->imReview($review, $parentId);
			if ($eltern->getVersionId() !== $version->getId()
				|| ($eltern->getAuthorType() === Comment::KUNDE && $eltern->getSubmissionId() === null)) {
				throw new KommentarException('This comment cannot be replied to.');
			}
			$kommentar->setParentId($eltern->getId());
		}
		$this->inhaltPruefen($kommentar);
		$jetzt = $this->time->getTime();
		$kommentar->setCreatedAt($jetzt);
		$kommentar->setUpdatedAt($jetzt);
		return $kommentar;
	}

	/** @throws KommentarException */
	private function offen(Review $review): void {
		if ($review->isCommentsLocked()) {
			throw new KommentarException('This video has been approved. Comments are no longer possible.', 423);
		}
	}

	/** @throws KommentarException */
	private function version(Review $review, int $versionId): ReviewVersion {
		try {
			$version = $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			throw new KommentarException('Version not found.', 404);
		}
		if ($version->getReviewId() !== $review->getId()) {
			throw new KommentarException('Version not found.', 404);
		}
		return $version;
	}

	/**
	 * @return array{0: int, 1: ?int}
	 * @throws KommentarException
	 */
	private function frames(ReviewVersion $version, int $in, ?int $out): array {
		$letzter = (int)$version->getDurationFrames() - 1;
		if ($in < 0 || $in > $letzter) {
			throw new KommentarException('Frame outside the video.');
		}
		if ($out !== null && ($out < $in || $out > $letzter)) {
			throw new KommentarException('Range outside the video.');
		}
		return [$in, $out];
	}

	/** @throws KommentarException */
	private function text(mixed $text): string {
		$text = trim((string)$text);
		if (mb_strlen($text) > self::TEXT_MAX) {
			throw new KommentarException('The comment is too long.');
		}
		return $text;
	}

	/**
	 * Zeichnung als Vektoren (JSON), Koordinaten 0 bis 1. Wird nur auf
	 * Form geprüft; gezeichnet wird ausschließlich im Browser.
	 *
	 * @throws KommentarException
	 */
	private function zeichnung(mixed $zeichnung): ?string {
		if ($zeichnung === null || $zeichnung === '') {
			return null;
		}
		$zeichnung = (string)$zeichnung;
		if (strlen($zeichnung) > self::ZEICHNUNG_MAX) {
			throw new KommentarException('The drawing is too large.');
		}
		try {
			$daten = json_decode($zeichnung, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new KommentarException('The drawing cannot be read.');
		}
		if (!is_array($daten) || !is_array($daten['striche'] ?? null)) {
			throw new KommentarException('The drawing cannot be read.');
		}
		// Sicherheitsprüfung 15.10.2026: Nur die erwartete Form wird
		// gespeichert, neu geschrieben. Sonst könnte ein Gast mit kaputten
		// Daten die Ansicht anderer stören (gespeicherte Zeichnung).
		$farbe = is_string($daten['farbe'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $daten['farbe']) === 1
			? strtolower($daten['farbe'])
			: '#ff4d4f';
		$striche = [];
		$punkte = 0;
		foreach ($daten['striche'] as $strich) {
			if (!is_array($strich) || count($striche) >= self::STRICHE_MAX) {
				throw new KommentarException('The drawing cannot be read.');
			}
			$neu = [];
			foreach ($strich as $p) {
				if (!is_array($p) || count($p) !== 2 || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null) || ++$punkte > self::PUNKTE_MAX) {
					throw new KommentarException('The drawing cannot be read.');
				}
				$neu[] = [round(min(1, max(0, (float)$p[0])), 4), round(min(1, max(0, (float)$p[1])), 4)];
			}
			if ($neu !== []) {
				$striche[] = $neu;
			}
		}
		if ($striche === []) {
			return null;
		}
		return json_encode(['farbe' => $farbe, 'striche' => $striche], JSON_THROW_ON_ERROR);
	}

	/** Eine Anmerkung braucht Text oder Zeichnung. Ein Wort genügt. */
	private function inhaltPruefen(Comment $kommentar): void {
		if ((string)$kommentar->getText() === '' && $kommentar->getDrawing() === null) {
			throw new KommentarException('Please write or draw something.');
		}
	}

	/** @throws KommentarException */
	private function imReview(Review $review, int $id): Comment {
		try {
			$kommentar = $this->comments->find($id);
		} catch (DoesNotExistException) {
			throw new KommentarException('Not found.', 404);
		}
		if (!in_array((int)$kommentar->getVersionId(), $this->versionIds($review), true)) {
			throw new KommentarException('Not found.', 404);
		}
		return $kommentar;
	}

	/** @throws KommentarException */
	private function eigenerEntwurf(Review $review, Guest $gast, int $id): Comment {
		$kommentar = $this->imReview($review, $id);
		if ($kommentar->getGuestId() !== $gast->getId()) {
			throw new KommentarException('Not found.', 404);
		}
		if ($kommentar->getSubmissionId() !== null) {
			throw new KommentarException('Sent comments can no longer be changed.', 409);
		}
		return $kommentar;
	}
}
