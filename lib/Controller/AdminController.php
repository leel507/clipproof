<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Service\EinbettungService;
use OCA\Clipproof\Service\Einstellungen;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Speichert die Admin-Einstellungen aus dem Formular. Nur für
 * Administratoren (Vorgabe ohne NoAdminRequired), mit CSRF-Prüfung.
 */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private Einstellungen $einstellungen,
		private EinbettungService $einbettung,
		private IURLGenerator $urls,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'POST', url: '/admin/einstellungen')]
	public function speichern(string $gruppen = '', int $tage = Einstellungen::VORGABE_TAGE, int $jahre = 0, int $protokoll = Einstellungen::VORGABE_PROTOKOLL_JAHRE, string $einbettung = '', string $ordnersprache = 'en'): RedirectResponse {
		$this->einstellungen->setzeGruppen(preg_split('/\s*,\s*/', trim($gruppen)) ?: []);
		$this->einstellungen->setzeTageNachFreigabe($tage);
		$this->einstellungen->setzeAufbewahrungJahre($jahre);
		$this->einstellungen->setzeProtokollJahre($protokoll);
		$this->einstellungen->setzeOrdnersprache($ordnersprache);
		$this->einbettung->setzen($einbettung);
		return new RedirectResponse($this->urls->linkToRoute('settings.AdminSettings.index', ['section' => 'additional']) . '#clipproof');
	}
}
