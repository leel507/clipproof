<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;

/**
 * Admin-Einstellungen von Clipproof.
 *
 * - Wer die App benutzen darf: Gruppen; leer heißt alle angemeldeten
 *   Nutzer. Administratoren dürfen immer.
 * - Wie lange der Kundenlink nach der Freigabe noch gilt (Tage); danach
 *   wird das Review archiviert.
 * - Wie lange abgesendete Anmerkungen im Archiv bleiben (Jahre, 0 = bis der
 *   Besitzer löscht) und wie lange das Löschprotokoll (Jahre).
 * - Sprache der Unterordner in Projektordnern: englisch (Vorgabe) oder
 *   deutsch. Erkannt werden immer beide Schreibweisen.
 */
class Einstellungen {
	public const GRUPPEN = 'erlaubte_gruppen';
	public const TAGE_NACH_FREIGABE = 'link_tage_nach_freigabe';
	public const VORGABE_TAGE = 30;
	public const MAX_TAGE = 3650;
	public const AUFBEWAHRUNG_JAHRE = 'aufbewahrung_jahre';
	public const PROTOKOLL_JAHRE = 'protokoll_jahre';
	public const VORGABE_PROTOKOLL_JAHRE = 3;
	public const MAX_JAHRE = 30;
	public const ORDNERSPRACHE = 'ordnersprache';
	/** Unterordner für ältere Fassungen je Sprache; die erste ist die Vorgabe. */
	public const VORVERSIONEN = ['en' => 'Previous versions', 'de' => 'Vorversionen'];
	public const ASSETS = ['en' => 'Assets', 'de' => 'Assets'];

	public function __construct(
		private IAppConfig $config,
		private IGroupManager $gruppen,
		private IConfig $system,
	) {
	}

	/** @return list<string> */
	public function gruppen(): array {
		$roh = $this->config->getValueString(Application::APP_ID, self::GRUPPEN, '[]');
		$liste = json_decode($roh, true);
		if (!is_array($liste)) {
			return [];
		}
		return array_values(array_filter($liste, fn ($g) => is_string($g) && $g !== ''));
	}

	/** @param list<string> $gruppen */
	public function setzeGruppen(array $gruppen): void {
		$sauber = array_values(array_unique(array_filter(array_map('trim', $gruppen), fn ($g) => $g !== '' && $this->gruppen->groupExists($g))));
		$this->config->setValueString(Application::APP_ID, self::GRUPPEN, json_encode($sauber, JSON_THROW_ON_ERROR));
	}

	/** Darf dieser Nutzer Reviews anlegen und verwalten? */
	public function darf(string $uid): bool {
		if ($uid === '') {
			return false;
		}
		$erlaubt = $this->gruppen();
		if ($erlaubt === [] || $this->gruppen->isAdmin($uid)) {
			return true;
		}
		foreach ($erlaubt as $gruppe) {
			if ($this->gruppen->isInGroup($uid, $gruppe)) {
				return true;
			}
		}
		return false;
	}

	public function tageNachFreigabe(): int {
		$tage = $this->config->getValueInt(Application::APP_ID, self::TAGE_NACH_FREIGABE, self::VORGABE_TAGE);
		return max(1, min(self::MAX_TAGE, $tage));
	}

	public function setzeTageNachFreigabe(int $tage): void {
		$this->config->setValueInt(Application::APP_ID, self::TAGE_NACH_FREIGABE, max(1, min(self::MAX_TAGE, $tage)));
	}

	/** Gültigkeit des Links nach der Freigabe in Sekunden. */
	public function gueltigNachFreigabe(): int {
		return $this->tageNachFreigabe() * 24 * 60 * 60;
	}

	/** Jahre im Archiv; 0 heißt bis der Besitzer das Review löscht. */
	public function aufbewahrungJahre(): int {
		return max(0, min(self::MAX_JAHRE, $this->config->getValueInt(Application::APP_ID, self::AUFBEWAHRUNG_JAHRE, 0)));
	}

	public function setzeAufbewahrungJahre(int $jahre): void {
		$this->config->setValueInt(Application::APP_ID, self::AUFBEWAHRUNG_JAHRE, max(0, min(self::MAX_JAHRE, $jahre)));
	}

	public function protokollJahre(): int {
		return max(1, min(self::MAX_JAHRE, $this->config->getValueInt(Application::APP_ID, self::PROTOKOLL_JAHRE, self::VORGABE_PROTOKOLL_JAHRE)));
	}

	public function setzeProtokollJahre(int $jahre): void {
		$this->config->setValueInt(Application::APP_ID, self::PROTOKOLL_JAHRE, max(1, min(self::MAX_JAHRE, $jahre)));
	}

	/** 'en' oder 'de'. */
	public function ordnersprache(): string {
		$s = $this->config->getValueString(Application::APP_ID, self::ORDNERSPRACHE, 'en');
		return isset(self::VORVERSIONEN[$s]) ? $s : 'en';
	}

	public function setzeOrdnersprache(string $sprache): void {
		$this->config->setValueString(Application::APP_ID, self::ORDNERSPRACHE, isset(self::VORVERSIONEN[$sprache]) ? $sprache : 'en');
	}

	/** Name des Ordners für ältere Fassungen in der eingestellten Sprache. */
	public function vorversionen(): string {
		return self::VORVERSIONEN[$this->ordnersprache()];
	}

	public function assets(): string {
		return self::ASSETS[$this->ordnersprache()];
	}

	/** Ist das ein Ordner für ältere Fassungen, gleich in welcher Sprache? */
	public static function istVorversionen(string $name): bool {
		return in_array($name, self::VORVERSIONEN, true);
	}

	/** Zeitzone der Instanz für Jahresgrenzen (config.php default_timezone, sonst UTC). */
	public function zeitzone(): string {
		$zone = $this->system->getSystemValueString('default_timezone', 'UTC');
		return in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
	}
}
