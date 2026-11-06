<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\GuestMapper;
use OCA\Clipproof\Db\LoeschprotokollMapper;
use OCA\Clipproof\Db\OrdnerMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Db\SubmissionMapper;
use OCA\Clipproof\Exception\Mp4Exception;
use OCA\Clipproof\Exception\ReviewException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;

class ReviewService {
	private const VIDEO_MIME = ['video/mp4', 'video/quicktime'];
	/** Codecs, die Browser abspielen. Alles andere ist ein Master, kein Proxy. */
	private const BROWSER_CODECS = ['avc1', 'avc3', 'hvc1', 'hev1', 'av01', 'vp09'];
	/**
	 * Größer ist kein Proxy (1080p mit 3 bis 4 Mbit/s: rund 30 MB je
	 * Minute). Schützt davor, dass ein Master beim Kunden landet.
	 */
	public const MAX_GROESSE = 3 * 1024 * 1024 * 1024;

	public function __construct(
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private TokenService $tokens,
		private Mp4Inspector $inspector,
		private IRootFolder $rootFolder,
		private IDBConnection $db,
		private ITimeFactory $time,
		private CommentMapper $comments,
		private GuestMapper $guests,
		private SubmissionMapper $submissions,
		private LoeschprotokollMapper $protokoll,
		private OrdnerMapper $ordner,
	) {
	}

	/**
	 * Legt ein Review mit erster Version an. Die Datei muss für den Besitzer
	 * sichtbar sein; gelesen wird sie später mit den Rechten ihres
	 * Eigentümers (Kundenkonto), ohne Share.
	 *
	 * @return array{0: Review, 1: string} Review und Token im Klartext
	 * @throws ReviewException
	 */
	public function anlegen(string $ownerUid, int $fileId, string $titel): array {
		return $this->anlegenMitDatei($ownerUid, $this->dateiFuerBenutzer($ownerUid, $fileId), $titel);
	}

	/**
	 * Wie anlegen, mit schon gefundener Datei. Für beobachtete Ordner: Die
	 * Datei liegt im Kundenkonto, das Review gehört dem Besitzer.
	 *
	 * @return array{0: Review, 1: string} Review und Token im Klartext
	 * @throws ReviewException
	 */
	public function anlegenMitDatei(string $ownerUid, File $datei, string $titel): array {
		$this->videoPruefen($datei);
		$fileId = (int)$datei->getId();
		$info = $this->pruefen($datei);
		$jetzt = $this->time->getTime();

		$this->db->beginTransaction();
		try {
			$review = new Review();
			$review->setOwnerUid($ownerUid);
			$review->setTitle($titel);
			$review->setAnrede(Review::ANREDE_SIE);
			$review->setCommentsLocked(false);
			$review->setCreatedAt($jetzt);
			$token = $this->tokens->assign($review);
			$review = $this->reviews->insert($review);

			$version = new ReviewVersion();
			$version->setReviewId($review->getId());
			$version->setNumber(1);
			$version->setFileId($fileId);
			$version->setFileOwnerUid($datei->getOwner()?->getUID() ?? $ownerUid);
			$version->setFpsNum($info->fpsNum);
			$version->setFpsDen($info->fpsDen);
			$version->setFpsSource(ReviewVersion::FPS_CONTAINER);
			$version->setDropFrame($info->dropFrame === true);
			$version->setStartTcFrames($info->startFrame ?? 0);
			$version->setDurationFrames($info->frameCount);
			$version->setWidth($info->width);
			$version->setHeight($info->height);
			$version->setCreatedAt($jetzt);
			$this->versions->insert($version);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return [$review, $token];
	}

	/**
	 * Hängt einen neuen Schnitt an. Der Kundenlink bleibt gleich; die
	 * Kundenseite zeigt die neueste Version. Bildrate wird wie beim
	 * Anlegen aus dem Container gelesen, variable Bildrate abgelehnt.
	 *
	 * @throws ReviewException
	 */
	public function versionAnhaengen(Review $review, string $uid, int $fileId): ReviewVersion {
		return $this->versionMitDatei($review, $uid, $this->dateiFuerBenutzer($uid, $fileId));
	}

	/** @throws ReviewException */
	public function versionMitDatei(Review $review, string $uid, File $datei): ReviewVersion {
		$this->videoPruefen($datei);
		$fileId = (int)$datei->getId();
		$info = $this->pruefen($datei);
		$jetzt = $this->time->getTime();
		$bisher = $this->versions->findForReview((int)$review->getId());
		$nummer = $bisher === [] ? 1 : max(array_map(fn (ReviewVersion $v) => (int)$v->getNumber(), $bisher)) + 1;

		$version = new ReviewVersion();
		$version->setReviewId($review->getId());
		$version->setNumber($nummer);
		$version->setFileId($fileId);
		$version->setFileOwnerUid($datei->getOwner()?->getUID() ?? $uid);
		$version->setFpsNum($info->fpsNum);
		$version->setFpsDen($info->fpsDen);
		$version->setFpsSource(ReviewVersion::FPS_CONTAINER);
		$version->setDropFrame($info->dropFrame === true);
		$version->setStartTcFrames($info->startFrame ?? 0);
		$version->setDurationFrames($info->frameCount);
		$version->setWidth($info->width);
		$version->setHeight($info->height);
		$version->setCreatedAt($jetzt);
		$version = $this->versions->insert($version);
		$review->setUpdatedAt($jetzt);
		$this->reviews->update($review);
		return $version;
	}

	/**
	 * Datei-Id zu einem Pfad in den Dateien eines Benutzers.
	 *
	 * @throws ReviewException
	 */
	public function dateiIdZuPfad(string $uid, string $pfad): int {
		try {
			$knoten = $this->rootFolder->getUserFolder($uid)->get(ltrim(trim($pfad), '/'));
		} catch (NotFoundException) {
			throw new ReviewException('File not found: %s', [$pfad]);
		}
		if (!$knoten instanceof File) {
			throw new ReviewException('This is a folder, not a file: %s', [$pfad]);
		}
		return (int)$knoten->getId();
	}

	/**
	 * Löscht ein Review mit allem, was die App dazu gespeichert hat:
	 * Anmerkungen (auch Entwürfe), Gäste, Abgaben, Rückrufe, Versionen.
	 * Die Videodateien in Nextcloud bleiben unberührt (Art. 17 für die
	 * Daten dieser App; die Dateien gehören dem Nutzer).
	 *
	 * Ins Löschprotokoll kommt nur, dass und wann, nicht was (E8).
	 */
	public function loeschen(Review $review, string $anlass): void {
		$id = (int)$review->getId();
		$versionIds = array_map(fn (ReviewVersion $v) => (int)$v->getId(), $this->versions->findForReview($id));
		$kommentare = count($this->comments->findSichtbarFuerGast($versionIds, null)) + count(array_filter(
			$this->comments->findAlleEntwuerfe(),
			fn ($k) => in_array((int)$k->getVersionId(), $versionIds, true),
		));
		$gaeste = $this->guests->countForReview($id);
		$abgaben = count($this->submissions->findForReview($id));
		$this->db->beginTransaction();
		try {
			$this->protokoll->eintragen($id, (string)$review->getTokenHash(), $anlass, $kommentare, $gaeste, $abgaben, $this->time->getTime());
			$this->comments->deleteForVersions($versionIds);
			$this->guests->deleteForReview($id);
			$this->submissions->deleteForReview($id);
			$this->versions->deleteForReview($id);
			if ($review->getOrdnerId() !== null) {
				$this->ordner->abmelden((int)$review->getOrdnerId(), $this->time->getTime());
			}
			$this->reviews->delete($review);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Öffnet die Videodatei einer Version mit den Rechten ihres Eigentümers.
	 *
	 * @throws NotFoundException
	 */
	public function dateiDerVersion(ReviewVersion $version): File {
		$knoten = $this->rootFolder->getUserFolder($version->getFileOwnerUid())->getFirstNodeById($version->getFileId());
		if (!$knoten instanceof File) {
			throw new NotFoundException('Video file not found');
		}
		return $knoten;
	}

	/** @throws ReviewException */
	private function dateiFuerBenutzer(string $uid, int $fileId): File {
		$knoten = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		if (!$knoten instanceof File) {
			throw new ReviewException('File not found or not a file');
		}
		return $knoten;
	}

	public static function istVideo(File $datei): bool {
		return in_array($datei->getMimeType(), self::VIDEO_MIME, true);
	}

	/** @throws ReviewException */
	private function videoPruefen(File $datei): void {
		if (!self::istVideo($datei)) {
			throw new ReviewException('Not an MP4 or MOV video: %s', [$datei->getMimeType()]);
		}
		if ($datei->getSize() > self::MAX_GROESSE) {
			throw new ReviewException('Larger than 3 GB, so this is not a proxy (a master file?). Please use a smaller proxy for the review.');
		}
	}

	/** @throws ReviewException */
	private function pruefen(File $datei): Mp4Info {
		$strom = $datei->fopen('rb');
		if ($strom === false) {
			throw new ReviewException('The file cannot be opened. Is it still being uploaded?');
		}
		try {
			$info = $this->inspector->inspect($strom);
		} catch (Mp4Exception $e) {
			throw new ReviewException('The video cannot be read. Is the file fully uploaded and exported as MP4 or MOV? (Technical: %s)', [$e->getMessage()], $e);
		} finally {
			fclose($strom);
		}
		if ($info->codec !== null && !in_array($info->codec, self::BROWSER_CODECS, true)) {
			throw new ReviewException('Browsers cannot play the codec “%s” (a master file?). Please use an H.264 proxy.', [$info->codec]);
		}
		if (!$info->constantFrameRate) {
			throw new ReviewException('Variable frame rate. Please export the proxy again with a constant frame rate.');
		}
		return $info;
	}
}
