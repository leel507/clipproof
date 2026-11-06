<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\BackgroundJob;

use OCA\Clipproof\Service\AufbewahrungService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Stündlich: Reviews nach der Freigabe archivieren und nach Ablauf der
 * Aufbewahrung löschen.
 */
class StuendlichJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AufbewahrungService $aufbewahrung,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60);
	}

	protected function run($argument): void {
		$this->aufbewahrung->anwenden();
	}
}
