<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Service\Einstellungen;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

/** Besitzerseite in Nextcloud (angemeldet). */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private Einstellungen $einstellungen,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'clipproof-besitzer');
		// Namen der Unterordner für den Hilfetext (Einstellung des Admins)
		$this->initialState->provideInitialState('ordnernamen', [
			'vorversionen' => $this->einstellungen->vorversionen(),
			'assets' => $this->einstellungen->assets(),
		]);
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
