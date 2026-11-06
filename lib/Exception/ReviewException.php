<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Exception;

use Exception;
use OCP\IL10N;
use Throwable;

/**
 * Ein Review lässt sich so nicht anlegen. Die Meldung ist englischer Quelltext mit %s-Platzhaltern,
 * die Werte stehen getrennt; übersetzt wird erst im Controller mit meldung().
 */
class ReviewException extends Exception {
	private string $quelle;

	/** @param list<string> $werte */
	public function __construct(
		string $meldung,
		private array $werte = [],
		?Throwable $vorher = null,
	) {
		parent::__construct(vsprintf($meldung, $werte), 0, $vorher);
		$this->quelle = $meldung;
	}

	public function quelle(): string {
		return $this->quelle;
	}

	/** @return list<string> */
	public function werte(): array {
		return $this->werte;
	}

	/** Die Meldung in der Sprache des Nutzers. */
	public function meldung(IL10N $l): string {
		return $l->t($this->quelle, $this->werte);
	}
}
