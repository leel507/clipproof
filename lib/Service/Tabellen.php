<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCP\IDBConnection;

/**
 * Welche Tabellen der App fehlen. Eigene Klasse, damit der Selbsttest
 * ohne Datenbank prüfbar bleibt.
 */
class Tabellen {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param list<string> $namen ohne Präfix
	 * @return list<string>
	 */
	public function fehlend(array $namen): array {
		return array_values(array_filter($namen, fn (string $t) => !$this->db->tableExists($t)));
	}
}
