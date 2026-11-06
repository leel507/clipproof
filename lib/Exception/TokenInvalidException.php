<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Exception;

use Exception;

/**
 * Ein Review-Token gilt nicht. Der Grund ist nur für Tests und den Besitzer
 * Protokoll gedacht; nach außen antwortet die App immer gleich, damit
 * niemand ausprobieren kann, welche Tokens es gibt.
 */
class TokenInvalidException extends Exception {
	public const FORMAT = 'format';
	public const UNBEKANNT = 'unbekannt';
	public const GESPERRT = 'gesperrt';
	public const ABGELAUFEN = 'abgelaufen';

	public function __construct(
		private string $reason,
	) {
		parent::__construct('Review-Token ungültig: ' . $reason);
	}

	public function getReason(): string {
		return $this->reason;
	}
}
