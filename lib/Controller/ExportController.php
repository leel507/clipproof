<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\GuestMapper;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;

/** Markerdateien für den Besitzer (angemeldet, nur eigene Reviews). */
class ExportController extends Controller {
	private const FORMATE = [
		'edl' => ['edl', 'text/plain; charset=us-ascii'],
		'fcp7' => ['xml', 'application/xml; charset=utf-8'],
		'csv' => ['csv', 'text/csv; charset=utf-8'],
	];

	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private CommentMapper $comments,
		private GuestMapper $guests,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/export/{id}/{versionId}/{format}')]
	public function export(int $id, int $versionId, string $format): Response {
		if (!isset(self::FORMATE[$format])) {
			return new JSONResponse(['fehler' => $this->l->t('Unknown format')], Http::STATUS_BAD_REQUEST);
		}
		try {
			$review = $this->reviews->findForOwner($id, (string)$this->session->getUser()?->getUID());
			$version = $this->versions->find($versionId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['fehler' => $this->l->t('Not found.')], Http::STATUS_NOT_FOUND);
		}
		if ($version->getReviewId() !== $review->getId()) {
			return new JSONResponse(['fehler' => $this->l->t('Not found.')], Http::STATUS_NOT_FOUND);
		}
		$kommentare = $this->comments->findSichtbarFuerBesitzer([(int)$version->getId()]);
		$autoren = [];
		foreach ($this->guests->findByIds(array_values(array_unique(array_filter(array_map(fn ($k) => $k->getGuestId(), $kommentare))))) as $g) {
			$autoren[(int)$g->getId()] = (string)$g->getName();
		}
		$besitzer = (string)$this->session->getUser()?->getDisplayName();
		$marker = ExportService::marker($kommentare, $autoren, $besitzer !== '' ? $besitzer : 'Owner');
		$inhalt = match ($format) {
			'edl' => ExportService::edl($review, $version, $marker),
			'fcp7' => ExportService::fcp7($review, $version, $marker),
			'csv' => ExportService::csv($version, $marker),
		};
		[$endung, $typ] = self::FORMATE[$format];
		$name = ExportService::ascii(preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $review->getTitle()) ?? 'review') . '_v' . $version->getNumber() . '.' . $endung;
		return new DataDownloadResponse($inhalt, $name, $typ);
	}
}
