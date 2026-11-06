<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Clipproof\Listener\DateienSkript;
use OCA\Clipproof\Middleware\ZugangMiddleware;
use OCA\Clipproof\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'clipproof';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// „Zur Revision freigeben“ im Drei-Punkte-Menü der Dateien-App.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, DateienSkript::class);
		// Nur die Gruppen aus den Admin-Einstellungen sehen die Nutzerseiten.
		$context->registerMiddleware(ZugangMiddleware::class);
		// Abgaben von Kunden als Nextcloud-Benachrichtigung.
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
