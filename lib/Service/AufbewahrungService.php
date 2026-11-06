<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\GuestMapper;
use OCA\Clipproof\Db\LoeschprotokollMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Db\Submission;
use OCA\Clipproof\Db\SubmissionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Aufbewahrung und Löschfristen, einstellbar unter den Admin-Einstellungen.
 *
 * - Ist ein Review freigegeben und der Link abgelaufen (Tage nach der
 *   Freigabe), wird es archiviert: Link aus, nichts änderbar, nur der
 *   Besitzer liest und exportiert. Nie abgesendete Entwürfe und Gäste ohne
 *   Abgabe werden dabei gelöscht. Ohne jede Abgabe wird gleich gelöscht.
 * - Aufbewahrung im Archiv: Vorgabe 0 Jahre heißt, bis der Besitzer das
 *   Review löscht. Mit N Jahren verfällt jedes Schreiben (eine Abgabe mit
 *   ihren Anmerkungen, eine Antwort des Besitzers) N Jahre nach Ende seines
 *   eigenen Eingangsjahres (etwa für handelsrechtliche Fristen); ist nichts
 *   mehr übrig, wird das Review gelöscht.
 * - Das Löschprotokoll bleibt die eingestellten Jahre ab Ende des
 *   Kalenderjahres des Eintrags (Vorgabe 3).
 * - Jahresgrenzen gelten in der Zeitzone der Instanz.
 */
class AufbewahrungService {
	/** Nur noch Vorgaben für die statischen Rechnungen (und ihre Tests). */
	public const JAHRE = 6;
	public const PROTOKOLL_JAHRE = 3;
	private const ZONE = 'Europe/Berlin';

	public function __construct(
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private CommentMapper $comments,
		private GuestMapper $guests,
		private SubmissionMapper $submissions,
		private LoeschprotokollMapper $protokoll,
		private ReviewService $reviewService,
		private IDBConnection $db,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private Einstellungen $einstellungen,
	) {
	}

	/**
	 * Erster Zeitpunkt nach der Aufbewahrung: 1. Januar, sieben Jahre nach
	 * dem Jahr des Zeitpunkts, 0 Uhr deutscher Zeit. Aufbewahrt wird also
	 * bis einschließlich 31. Dezember des sechsten Folgejahres.
	 */
	public static function aufbewahrenBis(int $zeitpunkt, int $jahre = self::JAHRE, string $zone = self::ZONE): int {
		$tz = new \DateTimeZone($zone);
		$jahr = (int)(new \DateTimeImmutable('@' . $zeitpunkt))->setTimezone($tz)->format('Y');
		return (new \DateTimeImmutable(($jahr + $jahre + 1) . '-01-01 00:00:00', $tz))->getTimestamp();
	}

	/**
	 * Löschprotokoll: Einträge vor diesem Zeitpunkt sind abgelaufen, also
	 * 1. Januar vor drei Jahren, 0 Uhr deutscher Zeit. Ein Eintrag aus 2026
	 * bleibt bis einschließlich 31.12.2029.
	 */
	public static function protokollGrenze(int $jetzt, int $jahre = self::PROTOKOLL_JAHRE, string $zone = self::ZONE): int {
		$tz = new \DateTimeZone($zone);
		$jahr = (int)(new \DateTimeImmutable('@' . $jetzt))->setTimezone($tz)->format('Y');
		return (new \DateTimeImmutable(($jahr - $jahre) . '-01-01 00:00:00', $tz))->getTimestamp();
	}

	/**
	 * Schreiben vor diesem Zeitpunkt sind verfallen: 1. Januar vor sechs
	 * Jahren, 0 Uhr deutscher Zeit. Gleichbedeutend mit
	 * aufbewahrenBis(zeitpunkt) <= jetzt.
	 */
	public static function verfallGrenze(int $jetzt, int $jahre = self::JAHRE, string $zone = self::ZONE): int {
		$tz = new \DateTimeZone($zone);
		$jahr = (int)(new \DateTimeImmutable('@' . $jetzt))->setTimezone($tz)->format('Y');
		return (new \DateTimeImmutable(($jahr - $jahre) . '-01-01 00:00:00', $tz))->getTimestamp();
	}

	/** Der letzte Tag der Aufbewahrung als Datum (JJJJ-MM-TT). */
	public static function letzterTag(int $aufbewahrenBis, string $zone = self::ZONE): string {
		return (new \DateTimeImmutable('@' . ($aufbewahrenBis - 1)))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
	}

	/**
	 * Archiviert ein Review oder löscht es, wenn nichts abgesendet wurde.
	 *
	 * @param string $anlass LoeschprotokollMapper::ANLASS_FRIST
	 * @return 'archiviert'|'geloescht'|'schon'
	 */
	public function archivieren(Review $review, string $anlass): string {
		$jetzt = $this->time->getTime();
		if ($review->isArchiviert()) {
			return 'schon';
		}

		$id = (int)$review->getId();
		$abgaben = $this->submissions->findForReview($id);
		if ($abgaben === []) {
			$this->reviewService->loeschen($review, $anlass);
			return 'geloescht';
		}

		$versionIds = array_map(fn ($v) => (int)$v->getId(), $this->versions->findForReview($id));
		$letzte = max(array_map(fn (Submission $s) => (int)$s->getCreatedAt(), $abgaben));
		foreach ($this->comments->findSichtbarFuerBesitzer($versionIds) as $k) {
			if ($k->getAuthorType() === Comment::BESITZER) {
				$letzte = max($letzte, (int)$k->getCreatedAt());
			}
		}
		$behalten = array_values(array_unique(array_map(fn (Submission $s) => (int)$s->getGuestId(), $abgaben)));

		$this->db->beginTransaction();
		try {
			$entwuerfe = $this->comments->deleteEntwuerfe($versionIds);
			$gaeste = $this->guests->deleteAusser($id, $behalten);
			$this->protokoll->eintragen(
				$id,
				(string)$review->getTokenHash(),
				LoeschprotokollMapper::ANLASS_ARCHIV_FRIST,
				$entwuerfe,
				$gaeste,
				0,
				$jetzt,
			);
			$review->setArchiviertAm($jetzt);
			$review->setArchivAnlass($anlass);
			$jahre = $this->einstellungen->aufbewahrungJahre();
			$review->setAufbewahrenBis($jahre > 0 ? self::aufbewahrenBis($letzte, $jahre, $this->einstellungen->zeitzone()) : null);
			$review->setCommentsLocked(true);
			$review->setUpdatedAt($jetzt);
			$this->reviews->update($review);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return 'archiviert';
	}

	/**
	 * Der Besitzer nimmt eine Freigabe zurück, nachdem das Review schon
	 * archiviert war: wieder öffnen. Gelöschte Entwürfe kommen nicht zurück.
	 */
	public function wiederoeffnen(Review $review): bool {
		if (!$review->isArchiviert() || $review->getArchivAnlass() !== LoeschprotokollMapper::ANLASS_FRIST || $review->getTokenEnc() === null) {
			return false;
		}
		$review->setArchiviertAm(null);
		$review->setArchivAnlass(null);
		$review->setAufbewahrenBis(null);
		$review->setUpdatedAt($this->time->getTime());
		$this->reviews->update($review);
		return true;
	}

	/**
	 * Verfall je Schreiben: verfallene Abgaben (mit ihren Anmerkungen und
	 * Belegen) und verfallene Antworten des Besitzers löschen, Gäste ohne
	 * verbleibende Spur ebenso. Ist keine Abgabe mehr übrig, wird das
	 * ganze Review gelöscht. Jeder Schritt mit Eintrag im Löschprotokoll.
	 *
	 * @return int Zahl der betroffenen Reviews
	 */
	public function verfallJeSchreiben(int $jetzt): int {
		$jahre = $this->einstellungen->aufbewahrungJahre();
		if ($jahre === 0) {
			return 0;  // aufbewahren, bis der Besitzer löscht
		}
		$grenze = self::verfallGrenze($jetzt, $jahre, $this->einstellungen->zeitzone());
		/** @var array<int, array{abgaben: list<int>, besitzer: list<int>}> $jeReview */
		$jeReview = [];
		foreach ($this->submissions->findVor($grenze) as $s) {
			$jeReview[(int)$s->getReviewId()]['abgaben'][] = (int)$s->getId();
		}
		$versionZuReview = [];
		foreach ($this->comments->findBesitzerVor($grenze) as $k) {
			$v = (int)$k->getVersionId();
			if (!array_key_exists($v, $versionZuReview)) {
				try {
					$versionZuReview[$v] = (int)$this->versions->find($v)->getReviewId();
				} catch (\Throwable) {
					$versionZuReview[$v] = null;
				}
			}
			if ($versionZuReview[$v] !== null) {
				$jeReview[$versionZuReview[$v]]['besitzer'][] = (int)$k->getId();
			}
		}

		foreach ($jeReview as $reviewId => $was) {
			try {
				$review = $this->reviews->find($reviewId);
			} catch (\Throwable) {
				continue;
			}
			$abgabeIds = $was['abgaben'] ?? [];
			$besitzerIds = $was['besitzer'] ?? [];
			$this->db->beginTransaction();
			try {
				$this->comments->elternLoesen($abgabeIds);
				$kommentare = $this->comments->deleteAbgabenUndIds($abgabeIds, $besitzerIds);
				$abgaben = $this->submissions->deleteIds($abgabeIds);
				// Gäste behalten, die noch eine Abgabe oder Anmerkung haben.
				$rest = $this->submissions->findForReview($reviewId);
				$versionIds = array_map(fn ($v) => (int)$v->getId(), $this->versions->findForReview($reviewId));
				$behalten = array_map(fn (Submission $s) => (int)$s->getGuestId(), $rest);
				foreach ($this->comments->findSichtbarFuerGast($versionIds, null) as $k) {
					if ($k->getGuestId() !== null) {
						$behalten[] = (int)$k->getGuestId();
					}
				}
				foreach ($this->comments->findAlleEntwuerfe() as $k) {
					if (in_array((int)$k->getVersionId(), $versionIds, true) && $k->getGuestId() !== null) {
						$behalten[] = (int)$k->getGuestId();
					}
				}
				$gaeste = $this->guests->deleteAusser($reviewId, array_values(array_unique($behalten)));
				$this->protokoll->eintragen($reviewId, (string)$review->getTokenHash(), LoeschprotokollMapper::ANLASS_AUFBEWAHRUNG_TEIL, $kommentare, $gaeste, $abgaben, $jetzt);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			// Nichts mehr übrig (keine Abgabe, keine Antwort des Besitzers aus einem
			// späteren Jahr): Hülle des Reviews ebenfalls weg.
			if ($rest === [] && $this->comments->findSichtbarFuerBesitzer($versionIds) === []) {
				$this->reviewService->loeschen($review, LoeschprotokollMapper::ANLASS_AUFBEWAHRUNG);
			}
		}
		if ($jeReview !== []) {
			$this->logger->info('Aufbewahrung: verfallene Schreiben in {n} Reviews gelöscht', ['n' => count($jeReview), 'app' => Application::APP_ID]);
		}
		return count($jeReview);
	}

	/**
	 * Stündlich: archivieren, dessen Link nach der Freigabe abgelaufen ist,
	 * und löschen, was die Aufbewahrung hinter sich hat.
	 *
	 * @return array{0: int, 1: int} archiviert oder gelöscht nach Freigabe, gelöscht nach Aufbewahrung
	 */
	public function anwenden(): array {
		$jetzt = $this->time->getTime();
		$this->verfallJeSchreiben($jetzt);
		$frist = 0;
		foreach ($this->reviews->findZuArchivieren($jetzt - $this->einstellungen->gueltigNachFreigabe()) as $review) {
			$this->archivieren($review, LoeschprotokollMapper::ANLASS_FRIST);
			$frist++;
		}
		$ende = 0;
		foreach ($this->reviews->findAufbewahrungAbgelaufen($jetzt) as $review) {
			$this->reviewService->loeschen($review, LoeschprotokollMapper::ANLASS_AUFBEWAHRUNG);
			$ende++;
		}
		$protokoll = $this->protokoll->aufraeumen(self::protokollGrenze($jetzt, $this->einstellungen->protokollJahre(), $this->einstellungen->zeitzone()));
		if ($protokoll > 0) {
			$this->logger->info('Löschprotokoll: {n} abgelaufene Einträge gelöscht', ['n' => $protokoll, 'app' => Application::APP_ID]);
		}
		if ($frist + $ende > 0) {
			$this->logger->info('Aufbewahrung: {frist} Reviews nach Freigabe archiviert oder gelöscht, {ende} nach Ablauf der Aufbewahrung gelöscht', ['frist' => $frist, 'ende' => $ende, 'app' => Application::APP_ID]);
		}
		return [$frist, $ende];
	}
}
