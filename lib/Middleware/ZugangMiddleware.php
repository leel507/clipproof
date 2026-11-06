<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Middleware;

use OCA\Clipproof\Controller\ExportController;
use OCA\Clipproof\Controller\FreigabeApiController;
use OCA\Clipproof\Controller\OrdnerApiController;
use OCA\Clipproof\Controller\PageController;
use OCA\Clipproof\Controller\ReviewApiController;
use OCA\Clipproof\Service\Einstellungen;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCSController;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * Die Seiten und Schnittstellen für Nutzer gibt es nur für die Gruppen aus
 * den Admin-Einstellungen. Die Kundenseite und das Panel sind davon nicht
 * betroffen: Sie hängen am Review-Token beziehungsweise am Panel-Schlüssel.
 */
class ZugangMiddleware extends Middleware {
	private const GESCHUETZT = [
		PageController::class,
		ReviewApiController::class,
		OrdnerApiController::class,
		FreigabeApiController::class,
		ExportController::class,
	];

	public function __construct(
		private IUserSession $session,
		private Einstellungen $einstellungen,
		private IL10N $l,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		foreach (self::GESCHUETZT as $klasse) {
			if ($controller instanceof $klasse) {
				$uid = (string)$this->session->getUser()?->getUID();
				if (!$this->einstellungen->darf($uid)) {
					throw new KeinZugangException();
				}
				return;
			}
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if (!$exception instanceof KeinZugangException) {
			throw $exception;
		}
		if ($controller instanceof OCSController) {
			return new DataResponse(['fehler' => $this->l->t('Not allowed')], Http::STATUS_FORBIDDEN);
		}
		$antwort = new TemplateResponse('core', '403', [], 'guest');
		$antwort->setStatus(Http::STATUS_FORBIDDEN);
		return $antwort;
	}
}
