<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Settings;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Service\EinbettungService;
use OCA\Clipproof\Service\Einstellungen;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Admin-Einstellungen unter „Weitere Einstellungen“: Gruppen, Gültigkeit
 * des Links nach der Freigabe, Aufbewahrung, Einbettung. Ein schlichtes Formular ohne
 * eigenes JavaScript.
 */
class Admin implements ISettings {
	public function __construct(
		private Einstellungen $einstellungen,
		private EinbettungService $einbettung,
		private IURLGenerator $urls,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'gruppen' => implode(', ', $this->einstellungen->gruppen()),
			'tage' => $this->einstellungen->tageNachFreigabe(),
			'einbettung' => implode(' ', $this->einbettung->origins()),
			'jahre' => $this->einstellungen->aufbewahrungJahre(),
			'protokoll' => $this->einstellungen->protokollJahre(),
			'ordnersprache' => $this->einstellungen->ordnersprache(),
			'ziel' => $this->urls->linkToRoute('clipproof.admin.speichern'),
		], '');
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}
}
