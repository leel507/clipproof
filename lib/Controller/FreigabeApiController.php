<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Exception\ReviewException;
use OCA\Clipproof\Service\FreigabeService;
use OCA\Clipproof\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IL10N;

/** „Zur Revision freigeben“ aus der Dateien-App, nur für den Besitzer. */
class FreigabeApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private FreigabeService $freigabe,
		private TokenService $tokens,
		private IURLGenerator $urls,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/freigabe/{fileId}')]
	public function vorschlag(int $fileId): DataResponse {
		if (!$this->freigabe->darf($this->uid())) {
			return new DataResponse(['fehler' => $this->l->t('Not allowed')], Http::STATUS_FORBIDDEN);
		}
		try {
			return new DataResponse($this->freigabe->vorschlag($this->uid(), $fileId));
		} catch (ReviewException $e) {
			return new DataResponse(['fehler' => $e->meldung($this->l)], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/freigabe')]
	public function freigeben(int $fileId, ?int $reviewId = null, string $titel = ''): DataResponse {
		if (!$this->freigabe->darf($this->uid())) {
			return new DataResponse(['fehler' => $this->l->t('Not allowed')], Http::STATUS_FORBIDDEN);
		}
		try {
			[$review, $version] = $this->freigabe->freigeben($this->uid(), $fileId, $reviewId, $titel);
		} catch (ReviewException $e) {
			return new DataResponse(['fehler' => $e->meldung($this->l)], Http::STATUS_BAD_REQUEST);
		}
		$gesperrt = $review->getTokenRevokedAt() !== null;
		return new DataResponse([
			'reviewId' => $review->getId(),
			'titel' => $review->getTitle(),
			'version' => $version->getNumber(),
			'link' => $gesperrt ? null : $this->urls->linkToRouteAbsolute('clipproof.publicPage.show', ['token' => $this->tokens->reveal($review)]),
			'ansicht' => $this->urls->linkToRouteAbsolute('clipproof.page.index') . '?review=' . $review->getId(),
		], Http::STATUS_CREATED);
	}

	private function uid(): string {
		return (string)$this->session->getUser()?->getUID();
	}
}
