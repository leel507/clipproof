<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IAppConfig;

/**
 * Content-Security-Policy für genau die Kundenseite: Sie darf sich von
 * den eingestellten Origins einbetten lassen (Vorgabe: keine). Nichts
 * global, nichts für andere Seiten.
 */
class EinbettungService {
	public const SCHLUESSEL_ORIGINS = 'frame_ancestors';
	public const VORGABE_ORIGINS = '';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/** @return list<string> */
	public function origins(): array {
		$wert = $this->config->getValueString(Application::APP_ID, self::SCHLUESSEL_ORIGINS, self::VORGABE_ORIGINS);
		$out = [];
		foreach (preg_split('/\s+/', trim($wert)) ?: [] as $origin) {
			// Nur Schema und Host (mit Port), ohne Pfad.
			if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $origin) === 1) {
				$out[] = $origin;
			}
		}
		return $out;
	}

	/**
	 * Speichert nur gültige Origins (Schema und Host, ohne Pfad).
	 *
	 * @return list<string> was gespeichert wurde
	 */
	public function setzen(string $eingabe): array {
		$gueltig = [];
		foreach (preg_split('/[\s,]+/', trim($eingabe)) ?: [] as $origin) {
			$origin = rtrim($origin, '/');
			if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $origin) === 1) {
				$gueltig[] = $origin;
			}
		}
		$gueltig = array_values(array_unique($gueltig));
		$this->config->setValueString(Application::APP_ID, self::SCHLUESSEL_ORIGINS, implode(' ', $gueltig));
		return $gueltig;
	}

	public function kundenseitenCsp(): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		foreach ($this->origins() as $origin) {
			$csp->addAllowedFrameAncestorDomain($origin);
		}
		$csp->addAllowedMediaDomain("'self'");
		return $csp;
	}
}
