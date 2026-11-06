<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\Security\ISecureRandom;

/**
 * Schlüssel für das Premiere-Panel: kann nur
 * die Reviews des Besitzers und Anmerkungen lesen, sonst nichts. Kein Nextcloud-
 * Anwendungspasswort auf dem Mac, das das ganze Konto öffnet.
 *
 * Gespeichert wird nur der SHA-256 des Schlüssels; der Schlüssel selbst
 * erscheint einmal beim Erzeugen. Ein neuer Schlüssel ersetzt den alten.
 */
class PanelSchluessel {
	public const LAENGE = 40;
	private const HASH = 'panel_hash';
	private const AM = 'panel_am';
	private const MUSTER = '/^[A-Za-z0-9]{40}$/';

	public function __construct(
		private IUserConfig $config,
		private ISecureRandom $random,
		private ITimeFactory $time,
	) {
	}

	/** @return string der Schlüssel im Klartext, nur für diese Antwort */
	public function erzeugen(string $uid): string {
		$schluessel = $this->random->generate(self::LAENGE, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->config->setValueString($uid, Application::APP_ID, self::HASH, hash('sha256', $schluessel), flags: IUserConfig::FLAG_INDEXED);
		$this->config->setValueString($uid, Application::APP_ID, self::AM, (string)$this->time->getTime());
		return $schluessel;
	}

	public function widerrufen(string $uid): void {
		$this->config->deleteUserConfig($uid, Application::APP_ID, self::HASH);
		$this->config->deleteUserConfig($uid, Application::APP_ID, self::AM);
	}

	/** @return array{vorhanden: bool, erzeugtAm: ?string} */
	public function status(string $uid): array {
		$am = $this->config->getValueString($uid, Application::APP_ID, self::AM, '');
		return [
			'vorhanden' => $this->config->getValueString($uid, Application::APP_ID, self::HASH, '') !== '',
			'erzeugtAm' => $am !== '' ? gmdate('Y-m-d\TH:i:s\Z', (int)$am) : null,
		];
	}

	/** Benutzer zum Schlüssel, oder null. */
	public function benutzer(string $schluessel): ?string {
		if (preg_match(self::MUSTER, $schluessel) !== 1) {
			return null;
		}
		foreach ($this->config->searchUsersByValueString(Application::APP_ID, self::HASH, hash('sha256', $schluessel)) as $uid) {
			return (string)$uid;
		}
		return null;
	}
}
