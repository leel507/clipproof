<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\LoeschprotokollMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Db\Submission;
use OCA\Clipproof\Db\SubmissionMapper;
use OCP\IURLGenerator;

/**
 * Was der Besitzer über seine Reviews sieht: in seiner Ansicht (OCS) und im
 * Premiere-Panel (Panel-Schlüssel, nur lesen). Entwürfe von Kunden nie.
 */
class BesitzerAnsicht {
	public function __construct(
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private CommentMapper $comments,
		private SubmissionMapper $submissions,
		private TokenService $tokens,
		private KommentarAnsicht $ansicht,
		private IURLGenerator $urls,
		private VideoAdresse $videoAdresse,
		private Einstellungen $einstellungen,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function liste(string $uid): array {
		$out = [];
		foreach ($this->reviews->findAllForOwner($uid) as $review) {
			$versionen = $this->versionen($review);
			$sichtbar = $this->comments->findSichtbarFuerBesitzer(array_keys($versionen));
			$offen = count(array_filter($sichtbar, fn ($k) => $k->getAuthorType() === 'kunde' && $k->getDone() !== true));
			$out[] = [
				'id' => $review->getId(),
				'titel' => $review->getTitle(),
				'versionen' => count($versionen),
				'offen' => $offen,
				'gesperrt' => $review->getTokenRevokedAt() !== null,
				'freigegeben' => $review->getFreigabeAm() !== null,
				'archiviert' => $review->isArchiviert(),
				'geaendertAm' => gmdate('Y-m-d\TH:i:s\Z', (int)$review->getUpdatedAt()),
			];
		}
		return $out;
	}

	/** @return array<string, mixed> */
	public function details(Review $review): array {
		$versionen = $this->versionen($review);
		$abgaben = [];
		foreach ($this->submissions->findForReview((int)$review->getId()) as $s) {
			$abgaben[(int)$s->getId()] = $s;
		}
		$archiviert = $review->isArchiviert();
		$gesperrt = $review->getTokenRevokedAt() !== null || $archiviert;
		$token = $gesperrt ? null : $this->tokens->reveal($review);
		return [
			'id' => $review->getId(),
			'titel' => $review->getTitle(),
			'token' => $gesperrt ? null : $token,
			'link' => $gesperrt ? null : $this->urls->linkToRouteAbsolute('clipproof.publicPage.show', ['token' => $token]),
			'gesperrt' => $gesperrt,
			'ablaufAm' => $review->getTokenExpiresAt() !== null ? gmdate('Y-m-d\TH:i:s\Z', (int)$review->getTokenExpiresAt()) : null,
			'freigegebenAm' => $review->getFreigabeAm() !== null ? gmdate('Y-m-d\TH:i:s\Z', (int)$review->getFreigabeAm()) : null,
			'archiviert' => $archiviert,
			'anrede' => $review->getAnrede(),
			// Archiv nach Frist mit erhaltenem Link: Rücknahme öffnet wieder.
			'oeffenbar' => $archiviert && $review->getArchivAnlass() === LoeschprotokollMapper::ANLASS_FRIST && $review->getTokenEnc() !== null,
			'aufbewahrenBis' => $review->getAufbewahrenBis() !== null ? AufbewahrungService::letzterTag((int)$review->getAufbewahrenBis(), $this->einstellungen->zeitzone()) : null,
			'versionen' => array_values(array_map(fn (ReviewVersion $v) => [
				'id' => $v->getId(),
				'nummer' => $v->getNumber(),
				'fpsNum' => $v->getFpsNum(),
				'fpsDen' => $v->getFpsDen(),
				'df' => $v->isDropFrame(),
				'startFrames' => $v->getStartTcFrames(),
				'startTimecode' => Timecode::vonFrames((int)$v->getStartTcFrames(), (int)$v->getFpsNum(), (int)$v->getFpsDen(), $v->isDropFrame()),
				'bilder' => $v->getDurationFrames(),
				'breite' => $v->getWidth(),
				'hoehe' => $v->getHeight(),
			] + ($gesperrt ? ['videoUrl' => null, 'videoGueltigFuer' => 0] : $this->videoAdresse->erzeugen($review, $v)), $versionen)),
			'kommentare' => $this->ansicht->liste(
				$this->comments->findSichtbarFuerBesitzer(array_keys($versionen)),
				$versionen,
				$abgaben,
				null,
				true,
			),
			'abgaben' => array_values(array_map(fn (Submission $s) => [
				'id' => $s->getId(),
				'anzahl' => $s->getCommentCount(),
				'text' => $s->getProtocolText(),
				'am' => gmdate('Y-m-d\TH:i:s\Z', (int)$s->getCreatedAt()),
			], $abgaben)),
		];
	}

	/** @return array<int, ReviewVersion> */
	private function versionen(Review $review): array {
		$out = [];
		foreach ($this->versions->findForReview((int)$review->getId()) as $v) {
			$out[(int)$v->getId()] = $v;
		}
		return $out;
	}

}
