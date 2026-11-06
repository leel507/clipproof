<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\Ordner;
use OCA\Clipproof\Db\OrdnerMapper;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Exception\ReviewException;
use OCA\Clipproof\Service\OrdnerService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * Beobachtete Projektordner des Nutzers: einschalten, ausschalten, sofort
 * prüfen. Nur eigene Einträge.
 */
class OrdnerApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private OrdnerMapper $ordner,
		private ReviewMapper $reviews,
		private OrdnerService $service,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/ordner')]
	public function liste(): DataResponse {
		return new DataResponse(array_map(fn (Ordner $o) => $this->daten($o), $this->ordner->findForOwner($this->uid())));
	}

	/** Ordner aus den eigenen Dateien einschalten und gleich einmal ansehen. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/ordner')]
	public function einschalten(string $pfad): DataResponse {
		try {
			$eintrag = $this->service->eintragen($this->uid(), $pfad);
		} catch (ReviewException $e) {
			return new DataResponse(['fehler' => $e->meldung($this->l)], Http::STATUS_BAD_REQUEST);
		}
		$this->service->pruefen($eintrag);
		return new DataResponse($this->daten($this->ordner->find((int)$eintrag->getId())), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/ordner/{id}')]
	public function schalten(int $id, bool $aktiv): DataResponse {
		return $this->mitOrdner($id, function (Ordner $o) use ($aktiv) {
			if ($aktiv) {
				$o->setAktiv(true);
				$o->setFehler(null);
				$o->setFehlerDatei(null);
				$this->ordner->update($o);
				$this->service->pruefen($o);
			} else {
				$this->service->ausschalten($o);
			}
			return new DataResponse($this->daten($this->ordner->find($id)));
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/ordner/{id}/pruefen')]
	public function pruefen(int $id): DataResponse {
		return $this->mitOrdner($id, function (Ordner $o) use ($id) {
			if ($o->isAktiv()) {
				$this->service->pruefen($o);
			}
			return new DataResponse($this->daten($this->ordner->find($id)));
		});
	}

	/** Gespeicherten Fehler (JSON aus OrdnerService) in der Sprache des Nutzers; ältere Einträge sind Klartext. */
	public static function fehlerText(?string $gespeichert, IL10N $l): ?string {
		if ($gespeichert === null || $gespeichert === '') {
			return null;
		}
		$f = json_decode($gespeichert, true);
		if (!is_array($f) || !isset($f['m']) || !is_string($f['m'])) {
			return $gespeichert;
		}
		$text = $l->t($f['m'], array_map('strval', (array)($f['w'] ?? [])));
		return ($f['d'] ?? '') !== '' ? $f['d'] . ': ' . $text : $text;
	}

	/** @return array<string, mixed> */
	private function daten(Ordner $o): array {
		return [
			'id' => $o->getId(),
			'pfad' => $o->getPfad(),
			'titel' => $o->getTitel(),
			'aktiv' => $o->isAktiv(),
			'fehler' => self::fehlerText($o->getFehler(), $this->l),
			'reviews' => count($this->reviews->findForOrdner((int)$o->getId())),
			'geprueftAm' => $o->getGeprueftAm() !== null ? gmdate('Y-m-d\TH:i:s\Z', (int)$o->getGeprueftAm()) : null,
		];
	}

	/** @param callable(Ordner): DataResponse $tun */
	private function mitOrdner(int $id, callable $tun): DataResponse {
		try {
			$o = $this->ordner->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['fehler' => $this->l->t('Not found.')], Http::STATUS_NOT_FOUND);
		}
		if ($o->getOwnerUid() !== $this->uid()) {
			return new DataResponse(['fehler' => $this->l->t('Not found.')], Http::STATUS_NOT_FOUND);
		}
		return $tun($o);
	}

	private function uid(): string {
		return (string)$this->session->getUser()?->getUID();
	}
}
