<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Exception;

use Exception;
use OCP\IL10N;

/**
 * Eine Anmerkung lässt sich so nicht anlegen, ändern oder absenden. Trägt den passenden
 * HTTP-Status. Die Meldung ist englischer Quelltext, übersetzt wird im Controller mit meldung().
 */
class KommentarException extends Exception {
	public function __construct(
		string $meldung,
		private int $status = 400,
	) {
		parent::__construct($meldung);
	}

	public function getStatus(): int {
		return $this->status;
	}

	/** Die Meldung in der Sprache des Gastes oder Nutzers. */
	public function meldung(IL10N $l): string {
		return $l->t($this->getMessage());
	}
}
