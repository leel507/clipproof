<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\Guest;
use OCA\Clipproof\Db\GuestMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Exception\KommentarException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

/**
 * Kunden ohne Konto. Anmeldung je Anfrage über die Kopfzeile
 * X-Clipproof-Gast, keine Cookies (Safari im Rahmen). Gespeichert wird
 * nur der Hash des Gastschlüssels.
 */
class GuestService {
	public const KOPFZEILE = 'X-Clipproof-Gast';
	public const NAME_MAX = 100;
	private const MUSTER = '/^[A-Za-z0-9]{32}$/';

	public function __construct(
		private GuestMapper $guests,
		private ISecureRandom $random,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{0: Guest, 1: string} Gast und Schlüssel im Klartext
	 * @throws KommentarException
	 */
	public function anmelden(Review $review, string $name): array {
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
		if ($name === '') {
			throw new KommentarException('Please enter a name.');
		}
		if (mb_strlen($name) > self::NAME_MAX) {
			throw new KommentarException('The name is too long.');
		}
		$schluessel = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$jetzt = $this->time->getTime();
		$gast = new Guest();
		$gast->setReviewId($review->getId());
		$gast->setName($name);
		$gast->setSecretHash(hash('sha256', $schluessel));
		$gast->setCreatedAt($jetzt);
		$gast->setLastSeenAt($jetzt);
		return [$this->guests->insert($gast), $schluessel];
	}

	/** Gast zum Schlüssel, oder null ohne gültigen Schlüssel. */
	public function finden(Review $review, ?string $schluessel): ?Guest {
		if ($schluessel === null || preg_match(self::MUSTER, $schluessel) !== 1) {
			return null;
		}
		try {
			return $this->guests->findBySecretHash($review->getId(), hash('sha256', $schluessel));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @throws KommentarException
	 */
	public function pflicht(Review $review, ?string $schluessel): Guest {
		$gast = $this->finden($review, $schluessel);
		if ($gast === null) {
			throw new KommentarException('Please enter a name first.', 401);
		}
		return $gast;
	}
}
