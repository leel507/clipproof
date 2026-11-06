<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Exception\ReviewException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * „Zur Revision freigeben“ aus dem Drei-Punkte-Menü der Dateien-App:
 * schlägt die neue Version eines passenden Reviews vor, der Nutzer
 * bestätigt.
 */
class FreigabeService {
	public function __construct(
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private ReviewService $reviewService,
		private OrdnerService $ordner,
		private IRootFolder $rootFolder,
		private Einstellungen $einstellungen,
	) {
	}

	/** Wer die App benutzen darf (Admin-Einstellung Gruppen). */
	public function darf(string $uid): bool {
		return $this->einstellungen->darf($uid);
	}

	/**
	 * Was die Dateien-App im Fenster anbietet.
	 *
	 * @return array<string, mixed>
	 * @throws ReviewException
	 */
	public function vorschlag(string $uid, int $fileId): array {
		$datei = $this->datei($uid, $fileId);
		$gruppe = OrdnerService::gruppe($datei->getName());
		$elternId = (int)$datei->getParent()->getId();
		$schon = null;
		$passend = null;
		foreach ($this->reviews->findAllForOwner($uid) as $review) {
			$versionen = $this->versions->findForReview((int)$review->getId());
			if ($versionen === []) {
				continue;
			}
			foreach ($versionen as $v) {
				if ((int)$v->getFileId() === $fileId) {
					$schon = $this->kurz($review, $v);
				}
			}
			if ($review->isArchiviert() || $review->isCommentsLocked() || $passend !== null) {
				continue;
			}
			$letzte = end($versionen);
			$alt = $this->rootFolder->getUserFolder($uid)->getFirstNodeById((int)$letzte->getFileId());
			if ($alt instanceof File && OrdnerService::gruppe($alt->getName()) === $gruppe
				&& ((int)$alt->getParent()->getId() === $elternId || $this->imVorversionenVon($alt, $elternId))) {
				$passend = $this->kurz($review, $letzte) + ['naechsteVersion' => (int)$letzte->getNumber() + 1];
			}
		}
		return [
			'datei' => $datei->getName(),
			'gruppe' => $gruppe,
			'schon' => $schon,
			'vorschlag' => $passend,
		];
	}

	/**
	 * Freigeben: neues Review oder neue Version eines bestehenden.
	 *
	 * @return array{0: Review, 1: ReviewVersion}
	 * @throws ReviewException
	 */
	public function freigeben(string $uid, int $fileId, ?int $reviewId, string $titel): array {
		$datei = $this->datei($uid, $fileId);
		$vorige = null;
		if ($reviewId !== null) {
			try {
				$review = $this->reviews->findForOwner($reviewId, $uid);
			} catch (DoesNotExistException) {
				throw new ReviewException('Review not found.');
			}
			if ($review->isArchiviert() || $review->isCommentsLocked()) {
				throw new ReviewException('This review has been approved or archived. Please create a new one.');
			}
			$bisher = $this->versions->findForReview((int)$review->getId());
			$vorige = $bisher === [] ? null : end($bisher);
			$version = $this->reviewService->versionAnhaengen($review, $uid, $fileId);
		} else {
			$titel = trim($titel) !== '' ? mb_substr(trim($titel), 0, 200) : OrdnerService::gruppe($datei->getName());
			[$review] = $this->reviewService->anlegen($uid, $fileId, $titel);
			$version = $this->versions->findForReview((int)$review->getId())[0];
		}
		$this->ordner->nachFreigabe($review, $datei, $vorige);
		return [$review, $version];
	}

	/** @throws ReviewException */
	private function datei(string $uid, int $fileId): File {
		$datei = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		if (!$datei instanceof File || !ReviewService::istVideo($datei)) {
			throw new ReviewException('Not a video file (MP4 or MOV).');
		}
		// Eine alte Fassung darf nicht als neueste Version beim Kunden
		// landen.
		try {
			$ordnerName = $datei->getParent()->getName();
			$inVorversionen = Einstellungen::istVorversionen($ordnerName);
		} catch (\Throwable) {
			$inVorversionen = false;
		}
		if ($inVorversionen) {
			throw new ReviewException('This file is in “%s”, so it is an older version. Older versions are not released again. To use it again, copy it into the project folder and release it there.', [$ordnerName]);
		}
		return $datei;
	}

	private function imVorversionenVon(File $alt, int $elternId): bool {
		try {
			$ordner = $alt->getParent();
			return Einstellungen::istVorversionen($ordner->getName()) && (int)$ordner->getParent()->getId() === $elternId;
		} catch (\Throwable) {
			return false;
		}
	}

	/** @return array{reviewId: int, titel: string, version: int} */
	private function kurz(Review $review, ReviewVersion $v): array {
		return ['reviewId' => (int)$review->getId(), 'titel' => (string)$review->getTitle(), 'version' => (int)$v->getNumber()];
	}
}
