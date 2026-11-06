<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Notification;

use OCA\Clipproof\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Benachrichtigung an den Besitzer, wenn ein Kunde Anmerkungen abgibt.
 */
class Notifier implements INotifier {
	public const ABGABE = 'abgabe';

	public function __construct(
		private IFactory $l10n,
		private IURLGenerator $urls,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->get(Application::APP_ID)->t('Clipproof');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== self::ABGABE) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10n->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$anzahl = (int)($p['anzahl'] ?? 0);
		$notification->setParsedSubject($l->n(
			'%1$s left %2$d comment on “%3$s”, version %4$d',
			'%1$s left %2$d comments on “%3$s”, version %4$d',
			$anzahl,
			[(string)($p['gast'] ?? ''), $anzahl, (string)($p['titel'] ?? ''), (int)($p['version'] ?? 0)],
		));
		if (isset($p['erste']) && is_string($p['erste']) && $p['erste'] !== '') {
			$notification->setParsedMessage($p['erste']);
		}
		$notification->setLink($this->urls->linkToRouteAbsolute('clipproof.page.index') . '?review=' . $notification->getObjectId());
		try {
			$notification->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		} catch (\RuntimeException) {
			// Ohne Symbol geht es auch; eine Ausnahme hier legte die ganze Liste lahm.
		}
		return $notification;
	}
}
