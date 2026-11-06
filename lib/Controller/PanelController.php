<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Service\BesitzerAnsicht;
use OCA\Clipproof\Service\PanelSchluessel;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IL10N;

/**
 * Lesezugang für das Premiere-Panel (E10). Anmeldung nur mit dem
 * Panel-Schlüssel in der Kopfzeile X-Clipproof-Panel, keine Sitzung,
 * kein Nextcloud-Konto. Nur lesen: Liste und Details der eigenen Reviews.
 */
class PanelController extends Controller {
	public function __construct(
		IRequest $request,
		private PanelSchluessel $schluessel,
		private ReviewMapper $reviews,
		private BesitzerAnsicht $ansicht,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_panel')]
	#[FrontpageRoute(verb: 'GET', url: '/panel/reviews')]
	public function liste(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->abgewiesen();
		}
		return new JSONResponse($this->ansicht->liste($uid));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_panel')]
	#[FrontpageRoute(verb: 'GET', url: '/panel/reviews/{id}')]
	public function details(int $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->abgewiesen();
		}
		try {
			$review = $this->reviews->findForOwner($id, $uid);
		} catch (DoesNotExistException) {
			return new JSONResponse(['fehler' => $this->l->t('Not found.')], Http::STATUS_NOT_FOUND);
		}
		$d = $this->ansicht->details($review);
		// Der Panel-Schlüssel darf nur lesen. Kundenlink und Video-Adresse
		// enthalten das Review-Token, mit dem man als Gast schreiben könnte
		// (Sicherheitsprüfung 15.10.2026). Das Bild sieht der Besitzer in Premiere.
		unset($d['token'], $d['link']);
		foreach ($d['versionen'] as $i => $v) {
			$d['versionen'][$i]['videoUrl'] = null;
		}
		return new JSONResponse($d);
	}

	private function uid(): ?string {
		return $this->schluessel->benutzer((string)$this->request->getHeader('X-Clipproof-Panel'));
	}

	private function abgewiesen(): JSONResponse {
		$antwort = new JSONResponse(['fehler' => $this->l->t('Panel key not valid')], Http::STATUS_UNAUTHORIZED);
		$antwort->throttle();
		return $antwort;
	}
}
