<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\Ordner;
use OCA\Clipproof\Db\OrdnerMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Exception\ReviewException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Beobachtete Projektordner.
 *
 * - Beobachtet wird nur, was der Nutzer eingeschaltet hat. Nie global;
 *   abgeschlossene Reviews bekommen keine Versionen mehr.
 * - Angesehen werden nur Dateien direkt im Projektordner, keine
 *   Unterordner (ältere Fassungen, Assets; Namen je nach Einstellung).
 * - Je Video ein Review. Welche Dateien dasselbe Video sind, sagt der
 *   Dateiname (gruppe()): „Teaser_v1“ und „Teaser_v2“ ja, „Teaser“ und
 *   „Hauptfilm“ nein. Die neueste Datei einer Gruppe wird zur neuen Version.
 *
 * Was die App dabei schreibt, und nur das: den Ordner Vorversionen
 * anlegen, wenn er fehlt, und die vorige Fassung eines Videos dorthin
 * verschieben. Sie löscht nie, überschreibt nie, fasst keine anderen
 * Dateien an.
 */
class OrdnerService {
	/** So lange muss eine Datei unverändert sein: fertig hochgeladen. */
	public const RUHE = 120;

	public function __construct(
		private OrdnerMapper $ordner,
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private ReviewService $reviewService,
		private IRootFolder $rootFolder,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private Einstellungen $einstellungen,
	) {
	}

	/**
	 * Gruppe einer Datei: Name ohne Endung und ohne Versionszeichen am Ende
	 * („v2“, „_v02“, „ REV3“, „-version 4“, „_r2“; davor ein Trenner).
	 * Reine Ziffern am Ende bleiben (könnten ein Datum sein).
	 */
	public static function gruppe(string $dateiname): string {
		$name = pathinfo($dateiname, PATHINFO_FILENAME);
		if (preg_match('/^(.+?)[\s._-]+(?:v|ver|version|rev|r)\.?\s*\d+$/iu', $name, $m) === 1) {
			$name = $m[1];
		}
		return trim($name) !== '' ? trim($name) : $name;
	}

	/**
	 * Ein Ordner aus den Dateien des Nutzers (auch ein geteilter Ordner).
	 * Gelesen wird später mit den Rechten des Eigentümers.
	 *
	 * @throws ReviewException
	 */
	public function eintragen(string $uid, string $pfad): Ordner {
		try {
			$knoten = $this->rootFolder->getUserFolder($uid)->get(trim($pfad, '/'));
		} catch (NotFoundException) {
			throw new ReviewException('Folder not found: %s', [$pfad]);
		}
		if (!$knoten instanceof Folder) {
			throw new ReviewException('This is not a folder: %s', [$pfad]);
		}
		// Die App schreibt später mit den Rechten des Eigentümers. Ohne
		// eigene Schreibrechte darf der Nutzer das nicht einschalten, sonst wäre
		// die App ein Umweg an einer Nur-Lesen-Freigabe vorbei.
		if (!$knoten->isCreatable() || !$knoten->isUpdateable()) {
			throw new ReviewException('You do not have write access to this folder.');
		}
		$konto = $knoten->getOwner()?->getUID() ?? $uid;
		$eigenerPfad = ltrim((string)$this->rootFolder->getUserFolder($konto)->getRelativePath(
			$this->rootFolder->getUserFolder($konto)->getFirstNodeById((int)$knoten->getId())?->getPath() ?? '',
		), '/');
		$eintrag = $this->ordner->findByFolder((int)$knoten->getId());
		return $this->speichern($eintrag, $konto, $uid, $knoten, $eigenerPfad !== '' ? $eigenerPfad : trim($pfad, '/'), $knoten->getName(), true);
	}

	public function ausschalten(Ordner $eintrag): void {
		$eintrag->setAktiv(false);
		$eintrag->setUpdatedAt($this->time->getTime());
		$this->ordner->update($eintrag);
	}

	/** Alle 5 Minuten: alle aktiven Ordner ansehen. Gibt die Zahl neuer Versionen zurück. */
	public function allePruefen(): int {
		$n = 0;
		foreach ($this->ordner->findAktive() as $eintrag) {
			try {
				$n += $this->pruefen($eintrag);
			} catch (\Throwable $e) {
				// Ein Ordner darf die übrigen nie aufhalten.
				$this->logger->warning('Projektordner {id}: {fehler}', ['id' => $eintrag->getId(), 'fehler' => $e->getMessage(), 'app' => Application::APP_ID]);
			}
		}
		return $n;
	}

	/** Einen Ordner ansehen. Gibt die Zahl angelegter Reviews und Versionen zurück. */
	public function pruefen(Ordner $eintrag): int {
		$jetzt = $this->time->getTime();
		$eintrag->setGeprueftAm($jetzt);
		$ordner = $this->rootFolder->getUserFolder((string)$eintrag->getKontoUid())->getFirstNodeById((int)$eintrag->getFolderId());
		if (!$ordner instanceof Folder) {
			$this->fehler($eintrag, 'Folder not found (moved or deleted?)', [], null);
			return 0;
		}

		/** @var array<string, Review> $jeGruppe */
		$jeGruppe = [];
		foreach ($this->reviews->findForOrdner((int)$eintrag->getId()) as $r) {
			$jeGruppe[(string)$r->getGruppe()] = $r;
		}

		$n = 0;
		foreach ($this->neuesteJeGruppe($ordner) as $gruppe => $datei) {
			if ($datei->getMTime() > $jetzt - self::RUHE) {
				continue;  // noch im Hochladen
			}
			if ($eintrag->getFehlerDatei() === (int)$datei->getId()) {
				continue;  // schon abgelehnt, nicht bei jedem Lauf
			}
			$review = $jeGruppe[$gruppe] ?? null;
			$bekannt = $review === null ? [] : $this->versions->findForReview((int)$review->getId());
			if ($this->schonBekannt($datei, $bekannt)) {
				continue;
			}
			if ($review !== null && ($review->isArchiviert() || $review->isCommentsLocked())) {
				// Freigegeben oder archiviert: keine neue Fassung, aber sichtbar,
				// sonst wirkt die Automatik kaputt.
				// Ohne fehler_datei: Nach einer Rücknahme wird sie angehängt.
				$this->fehler($eintrag, '%1$s is new in the folder, but “%2$s” has been approved. Create a new project or withdraw the approval.', [$datei->getName(), (string)$gruppe], null);
				continue;
			}
			$vorige = $bekannt === [] ? null : end($bekannt);
			try {
				if ($review === null) {
					$review = $this->neuesReview($eintrag, $datei, $gruppe);
					$version = $this->versions->findForReview((int)$review->getId())[0];
				} else {
					$version = $this->reviewService->versionMitDatei($review, (string)$eintrag->getOwnerUid(), $datei);
				}
			} catch (ReviewException $e) {
				$this->fehler($eintrag, $e->quelle(), $e->werte(), (int)$datei->getId(), $datei->getName());
				continue;
			}
			if ($vorige !== null && (int)$vorige->getFileId() !== (int)$datei->getId()) {
				$this->vorversionVerschieben($ordner, (int)$vorige->getFileId());
			}
			$this->logger->info('Projektordner {id}: „{gruppe}“ Version {n}', ['id' => $eintrag->getId(), 'gruppe' => $gruppe, 'n' => $version->getNumber(), 'app' => Application::APP_ID]);
			$eintrag->setFehler(null);
			$eintrag->setFehlerDatei(null);
			$n++;
		}
		$eintrag->setUpdatedAt($jetzt);
		$this->ordner->update($eintrag);
		return $n;
	}

	/**
	 * Beobachteter Ordner, in dem die Datei direkt liegt, oder null. Für
	 * „Zur Revision freigeben“ aus der Dateien-App.
	 */
	public function ordnerFuer(File $datei): ?Ordner {
		try {
			$eltern = $datei->getParent();
		} catch (\Throwable) {
			return null;
		}
		$eintrag = $this->ordner->findByFolder((int)$eltern->getId());
		return $eintrag !== null && $eintrag->isAktiv() ? $eintrag : null;
	}

	/**
	 * Nach einer Freigabe von Hand (Dateien-App): Liegt die Datei in einem
	 * beobachteten Ordner, gilt dasselbe wie bei der Automatik: Review dem
	 * Ordner zuordnen, vorige Fassung nach Vorversionen.
	 */
	public function nachFreigabe(Review $review, File $datei, ?ReviewVersion $vorige): void {
		$eintrag = $this->ordnerFuer($datei);
		if ($eintrag === null) {
			return;
		}
		if ($review->getOrdnerId() === null) {
			$review->setOrdnerId($eintrag->getId());
			$review->setGruppe(self::gruppe($datei->getName()));
			$this->reviews->update($review);
		}
		$ordner = $this->rootFolder->getUserFolder((string)$eintrag->getKontoUid())->getFirstNodeById((int)$eintrag->getFolderId());
		if ($ordner instanceof Folder && $vorige !== null && (int)$vorige->getFileId() !== (int)$datei->getId()) {
			$this->vorversionVerschieben($ordner, (int)$vorige->getFileId());
		}
	}

	/** @return array<string, File> neueste Videodatei je Gruppe, direkt im Ordner */
	private function neuesteJeGruppe(Folder $ordner): array {
		$out = [];
		foreach ($ordner->getDirectoryListing() as $knoten) {
			if (!$knoten instanceof File || !ReviewService::istVideo($knoten)) {
				continue;
			}
			$g = self::gruppe($knoten->getName());
			$bisher = $out[$g] ?? null;
			if ($bisher === null || $knoten->getMTime() > $bisher->getMTime()
				|| ($knoten->getMTime() === $bisher->getMTime() && $knoten->getId() > $bisher->getId())) {
				$out[$g] = $knoten;
			}
		}
		return $out;
	}

	/**
	 * Ist die Datei schon eine Version? Gleiche Datei-ID, aber nach der
	 * letzten Version überschrieben, zählt als neue Fassung.
	 *
	 * @param list<ReviewVersion> $bekannt
	 */
	private function schonBekannt(File $datei, array $bekannt): bool {
		$letzte = $bekannt === [] ? null : end($bekannt);
		foreach ($bekannt as $v) {
			if ((int)$v->getFileId() === (int)$datei->getId()) {
				return !($v === $letzte && $datei->getMTime() > (int)$v->getCreatedAt() + self::RUHE);
			}
		}
		return false;
	}

	/** @throws ReviewException */
	private function neuesReview(Ordner $eintrag, File $datei, string $gruppe): Review {
		[$review] = $this->reviewService->anlegenMitDatei((string)$eintrag->getOwnerUid(), $datei, $gruppe);
		$review->setOrdnerId($eintrag->getId());
		$review->setGruppe($gruppe);
		return $this->reviews->update($review);
	}

	/**
	 * Vorige Fassung nach Vorversionen verschieben, wenn sie noch direkt im
	 * Projektordner liegt. Nie überschreiben: bei gleichem Namen „(2)“.
	 * Die Datei-ID bleibt, ältere Versionen bleiben abspielbar.
	 *
	 * @return string|null neuer Pfad relativ zum Konto, oder null
	 */
	private function vorversionVerschieben(Folder $ordner, int $fileId): ?string {
		$alt = $ordner->getFirstNodeById($fileId);
		if (!$alt instanceof File || $alt->getParent()->getId() !== $ordner->getId()) {
			return null;
		}
		try {
			// Gibt es den Ordner schon in einer der beiden Sprachen, dorthin; sonst in der eingestellten anlegen.
			$vorhanden = array_values(array_filter(Einstellungen::VORVERSIONEN, fn (string $n) => $ordner->nodeExists($n)));
			$ziel = $this->unterordner($ordner, $vorhanden[0] ?? $this->einstellungen->vorversionen());
			$name = $alt->getName();
			$basis = pathinfo($name, PATHINFO_FILENAME);
			$endung = pathinfo($name, PATHINFO_EXTENSION);
			for ($i = 2; $ziel->nodeExists($name); $i++) {
				$name = $basis . ' (' . $i . ')' . ($endung !== '' ? '.' . $endung : '');
			}
			$neu = $alt->move($ziel->getPath() . '/' . $name);
			$wurzel = $this->rootFolder->getUserFolder((string)$alt->getOwner()?->getUID());
			return ltrim((string)$wurzel->getRelativePath($neu->getPath()), '/');
		} catch (\Throwable $e) {
			// Nicht verschoben ist kein Schaden: Die Datei bleibt, wo sie war.
			$this->logger->warning('Vorversion nicht verschoben: {fehler}', ['fehler' => $e->getMessage(), 'app' => Application::APP_ID]);
			return null;
		}
	}

	private function speichern(?Ordner $eintrag, string $kontoUid, string $ownerUid, Folder $knoten, string $pfad, string $titel, bool $aktiv): Ordner {
		$jetzt = $this->time->getTime();
		$neu = $eintrag === null;
		$eintrag ??= new Ordner();
		$eintrag->setKontoUid($kontoUid);
		$eintrag->setOwnerUid($ownerUid);
		$eintrag->setFolderId((int)$knoten->getId());
		$eintrag->setPfad(mb_substr(trim($pfad, '/'), 0, 1000));
		$eintrag->setTitel(mb_substr($titel !== '' ? $titel : $knoten->getName(), 0, 200));
		$eintrag->setAktiv($aktiv);
		$eintrag->setFehler(null);
		$eintrag->setFehlerDatei(null);
		$eintrag->setUpdatedAt($jetzt);
		if ($neu) {
			$eintrag->setCreatedAt($jetzt);
			return $this->ordner->insert($eintrag);
		}
		return $this->ordner->update($eintrag);
	}

	/** @throws ReviewException */
	private function unterordner(Folder $in, string $name): Folder {
		if ($in->nodeExists($name)) {
			$k = $in->get($name);
			if (!$k instanceof Folder) {
				throw new ReviewException('A file is in the way: %s', [$name]);
			}
			return $k;
		}
		return $in->newFolder($name);
	}

	/**
	 * Der Hintergrund-Job kennt keine Nutzersprache. Gespeichert werden deshalb Quelltext und Werte
	 * als JSON, übersetzt wird beim Anzeigen (OrdnerApiController::fehlerText).
	 *
	 * @param list<string> $werte
	 */
	private function fehler(Ordner $eintrag, string $quelle, array $werte, ?int $dateiId, string $datei = ''): void {
		$werte = array_map(fn (string $w) => mb_substr($w, 0, 60), $werte);
		$eintrag->setFehler(json_encode(['d' => mb_substr($datei, 0, 60), 'm' => $quelle, 'w' => $werte], JSON_UNESCAPED_UNICODE) ?: null);
		$eintrag->setFehlerDatei($dateiId);
		$eintrag->setUpdatedAt($this->time->getTime());
		$this->ordner->update($eintrag);
	}
}
