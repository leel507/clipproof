<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Service\FreigabeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Lädt in der Dateien-App den Eintrag „Zur Revision freigeben“ im
 * Drei-Punkte-Menü, nur für den Besitzer.
 *
 * @template-implements IEventListener<Event>
 */
class DateienSkript implements IEventListener {
	public function __construct(
		private IUserSession $session,
		private FreigabeService $freigabe,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		$uid = $this->session->getUser()?->getUID();
		if ($uid !== null && $this->freigabe->darf($uid)) {
			Util::addScript(Application::APP_ID, 'clipproof-dateien');
		}
	}
}
