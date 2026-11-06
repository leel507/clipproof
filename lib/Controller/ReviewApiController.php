<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\LoeschprotokollMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Db\Submission;
use OCA\Clipproof\Db\SubmissionMapper;
use OCA\Clipproof\Exception\KommentarException;
use OCA\Clipproof\Exception\ReviewException;
use OCA\Clipproof\Service\AufbewahrungService;
use OCA\Clipproof\Service\CommentService;
use OCA\Clipproof\Service\Einstellungen;
use OCA\Clipproof\Service\KommentarAnsicht;
use OCA\Clipproof\Service\BesitzerAnsicht;
use OCA\Clipproof\Service\PanelSchluessel;
use OCA\Clipproof\Service\ReviewService;
use OCA\Clipproof\Service\Timecode;
use OCA\Clipproof\Service\TokenService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * Ansicht des Besitzers. Nur eigene Reviews; Entwürfe von Kunden gibt es
 * hier nicht, auch nicht als Zahl.
 */
class ReviewApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private CommentMapper $comments,
		private SubmissionMapper $submissions,
		private CommentService $kommentare,
		private TokenService $tokens,
		private KommentarAnsicht $ansicht,
		private IURLGenerator $urls,
		private ReviewService $reviewService,
		private BesitzerAnsicht $besitzerAnsicht,
		private PanelSchluessel $panelSchluessel,
		private AufbewahrungService $aufbewahrung,
		private Einstellungen $einstellungen,
		private ITimeFactory $time,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/reviews')]
	public function liste(): DataResponse {
		return new DataResponse($this->besitzerAnsicht->liste($this->uid()));
	}

	/** Review aus einer Datei anlegen; Pfad in den Dateien des Besitzers. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews')]
	public function anlegen(string $titel, string $pfad): DataResponse {
		$uid = $this->uid();
		try {
			[$review] = $this->reviewService->anlegen($uid, $this->reviewService->dateiIdZuPfad($uid, $pfad), trim($titel) !== '' ? trim($titel) : pathinfo($pfad, PATHINFO_FILENAME));
		} catch (ReviewException $e) {
			return new DataResponse(['fehler' => $e->meldung($this->l)], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->details($review), Http::STATUS_CREATED);
	}

	/** Neuen Schnitt anhängen; der Kundenlink bleibt gleich. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews/{id}/versionen')]
	public function versionAnhaengen(int $id, string $pfad): DataResponse {
		return $this->mitOffenemReview($id, function (Review $r) use ($pfad) {
			try {
				$this->reviewService->versionAnhaengen($r, $this->uid(), $this->reviewService->dateiIdZuPfad($this->uid(), $pfad));
			} catch (ReviewException $e) {
				return new DataResponse(['fehler' => $e->meldung($this->l)], Http::STATUS_BAD_REQUEST);
			}
			return new DataResponse($this->details($r), Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/reviews/{id}')]
	public function einzeln(int $id): DataResponse {
		return $this->mitReview($id, fn (Review $r) => new DataResponse($this->details($r)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews/{id}/kommentare')]
	public function kommentieren(int $id, int $versionId, int $frameIn, string $text = '', ?int $parentId = null): DataResponse {
		return $this->mitOffenemReview($id, function (Review $r) use ($versionId, $frameIn, $text, $parentId) {
			$this->kommentare->besitzerKommentiert($r, $this->uid(), [
				'versionId' => $versionId, 'frameIn' => $frameIn, 'text' => $text, 'parentId' => $parentId,
			]);
			return new DataResponse($this->details($r), Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/reviews/{id}/kommentare/{kommentarId}/erledigt')]
	public function erledigt(int $id, int $kommentarId, bool $erledigt): DataResponse {
		return $this->mitOffenemReview($id, function (Review $r) use ($kommentarId, $erledigt) {
			$this->kommentare->erledigt($r, $kommentarId, $erledigt);
			return new DataResponse($this->details($r));
		});
	}

	/**
	 * Review mit allen Daten der App löschen (Pflegeansicht, Art. 17).
	 * Videodateien bleiben; ins Löschprotokoll kommt nur, dass und wann.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/reviews/{id}')]
	public function loeschen(int $id): DataResponse {
		return $this->mitReview($id, function (Review $r) {
			$this->reviewService->loeschen($r, LoeschprotokollMapper::ANLASS_BESITZER);
			return new DataResponse(['geloescht' => true]);
		});
	}

	/** Panel-Schlüssel: Stand (ohne Schlüssel). */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/panel-schluessel')]
	public function panelStatus(): DataResponse {
		return new DataResponse($this->panelSchluessel->status($this->uid()));
	}

	/** Neuer Panel-Schlüssel; der alte gilt sofort nicht mehr. Nur hier einmal sichtbar. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/panel-schluessel')]
	public function panelErzeugen(): DataResponse {
		$schluessel = $this->panelSchluessel->erzeugen($this->uid());
		return new DataResponse(['schluessel' => $schluessel] + $this->panelSchluessel->status($this->uid()));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/panel-schluessel')]
	public function panelWiderrufen(): DataResponse {
		$this->panelSchluessel->widerrufen($this->uid());
		return new DataResponse($this->panelSchluessel->status($this->uid()));
	}

	/**
	 * Freigeben sperrt das Kommentieren; der Link gilt danach noch die
	 * eingestellte Zahl von Tagen. Zurücknehmen öffnet wieder, auch ein
	 * schon archiviertes Review, solange sein Link noch existiert.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/reviews/{id}/freigabe')]
	public function freigabe(int $id, bool $freigegeben): DataResponse {
		return $this->mitReview($id, function (Review $r) use ($freigegeben) {
			if ($freigegeben) {
				if ($r->isArchiviert()) {
					return new DataResponse(['fehler' => $this->l->t('Archived: read and export only.')], Http::STATUS_CONFLICT);
				}
				if ($r->getFreigabeAm() === null) {
					$this->tokens->applyFreigabe($r, $this->time->getTime(), $this->einstellungen->gueltigNachFreigabe());
				}
			} else {
				if ($r->isArchiviert() && !$this->aufbewahrung->wiederoeffnen($r)) {
					return new DataResponse(['fehler' => $this->l->t('This review cannot be reopened.')], Http::STATUS_CONFLICT);
				}
				$this->tokens->applyFreigabe($r, null);
			}
			return new DataResponse($this->details($this->reviews->findForOwner((int)$r->getId(), $this->uid())));
		});
	}

	/** Anrede auf der deutschen Kundenseite: du oder Sie. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/reviews/{id}/anrede')]
	public function anrede(int $id, string $anrede): DataResponse {
		if ($anrede !== Review::ANREDE_DU && $anrede !== Review::ANREDE_SIE) {
			return new DataResponse(['fehler' => $this->l->t('Form of address must be “du” or “sie”.')], Http::STATUS_BAD_REQUEST);
		}
		return $this->mitOffenemReview($id, function (Review $r) use ($anrede) {
			$r->setAnrede($anrede);
			$r->setUpdatedAt($this->time->getTime());
			$this->reviews->update($r);
			return new DataResponse($this->details($r));
		});
	}

	/** Neues Token: Der alte Link gilt sofort nicht mehr. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews/{id}/token')]
	public function erneuern(int $id): DataResponse {
		return $this->mitOffenemReview($id, function (Review $r) {
			$this->tokens->renew($r);
			return new DataResponse($this->details($r));
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/reviews/{id}/token')]
	public function sperren(int $id): DataResponse {
		return $this->mitOffenemReview($id, function (Review $r) {
			$this->tokens->revoke($r);
			return new DataResponse($this->details($r));
		});
	}

	/** @return array<string, mixed> */
	private function details(Review $review): array {
		return $this->besitzerAnsicht->details($review);
	}

	/**
	 * @param callable(Review): DataResponse $tun
	 */
	private function mitReview(int $id, callable $tun): DataResponse {
		try {
			return $tun($this->reviews->findForOwner($id, $this->uid()));
		} catch (DoesNotExistException) {
			return new DataResponse(['fehler' => $this->l->t('Not found.')], Http::STATUS_NOT_FOUND);
		} catch (KommentarException $e) {
			return new DataResponse(['fehler' => $e->meldung($this->l)], $e->getStatus());
		}
	}

	/**
	 * Wie mitReview, aber nicht im Archiv: Archivierte Reviews sind nach
	 * Art. 18 gesperrt, nur lesen und exportieren.
	 *
	 * @param callable(Review): DataResponse $tun
	 */
	private function mitOffenemReview(int $id, callable $tun): DataResponse {
		return $this->mitReview($id, fn (Review $r) => $r->isArchiviert()
			? new DataResponse(['fehler' => $this->l->t('Archived: read and export only.')], Http::STATUS_CONFLICT)
			: $tun($r));
	}

	private function uid(): string {
		return (string)$this->session->getUser()?->getUID();
	}
}
