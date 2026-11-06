<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\BackgroundJob;

use OCA\Clipproof\Service\OrdnerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Alle 5 Minuten die eingeschalteten Projektordner ansehen (E11). Nur
 * diese Ordner, nie die ganze Nextcloud.
 */
class OrdnerJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OrdnerService $ordner,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	protected function run($argument): void {
		$this->ordner->allePruefen();
	}
}
