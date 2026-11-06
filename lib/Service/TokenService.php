<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Exception\TokenInvalidException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * Review-Tokens: erzeugen, prüfen, Ablauf, Sperre.
 *
 * Gespeichert wird das Token nie im Klartext: SHA-256 zum Nachschlagen,
 * mit ICrypto verschlüsselt, damit der Besitzer den Link wieder anzeigen kann.
 * Kein Bezug zu Nextcloud-Shares (Entscheidung 1).
 */
class TokenService {
	public const LAENGE = 32;
	/** Der Link gilt bis 30 Tage nach der Freigabe. */
	public const GUELTIG_NACH_FREIGABE = 30 * 24 * 60 * 60;

	private const MUSTER = '/^[A-Za-z0-9]{32}$/';

	public function __construct(
		private ReviewMapper $mapper,
		private ISecureRandom $random,
		private ICrypto $crypto,
		private ITimeFactory $time,
	) {
	}

	public static function hash(string $token): string {
		return hash('sha256', $token);
	}

	/**
	 * Setzt ein neues Token am Review, ohne zu speichern. Ein vorheriges
	 * Token ist damit ungültig, eine Sperre aufgehoben.
	 *
	 * @return string das Token im Klartext, nur für den Link
	 */
	public function assign(Review $review): string {
		$token = $this->random->generate(self::LAENGE, ISecureRandom::CHAR_ALPHANUMERIC);
		$review->setTokenHash(self::hash($token));
		$review->setTokenEnc($this->crypto->encrypt($token));
		$review->setTokenRevokedAt(null);
		$review->setUpdatedAt($this->time->getTime());
		return $token;
	}

	/** Neues Token für ein bestehendes Review, gespeichert. */
	public function renew(Review $review): string {
		$token = $this->assign($review);
		$this->mapper->update($review);
		return $token;
	}

	/** Sperrt den Link sofort. */
	public function revoke(Review $review): void {
		$now = $this->time->getTime();
		$review->setTokenRevokedAt($now);
		$review->setUpdatedAt($now);
		$this->mapper->update($review);
	}

	/**
	 * Setzt oder entfernt die Freigabe: Ablauf nach der eingestellten Zahl
	 * von Tagen (Vorgabe 30) und Kommentieren gesperrt. Ohne Freigabe (auch nach
	 * einer Rücknahme) gilt der Link unbefristet und das Kommentieren ist
	 * wieder offen.
	 */
	public function applyFreigabe(Review $review, ?int $freigabeAm, int $gueltigNachFreigabe = self::GUELTIG_NACH_FREIGABE): void {
		$review->setFreigabeAm($freigabeAm);
		$review->setTokenExpiresAt($freigabeAm === null ? null : $freigabeAm + $gueltigNachFreigabe);
		$review->setCommentsLocked($freigabeAm !== null);
		$review->setUpdatedAt($this->time->getTime());
		$this->mapper->update($review);
	}

	/**
	 * Findet das Review zu einem Token und prüft, ob der Link gilt.
	 *
	 * @throws TokenInvalidException
	 */
	public function resolve(string $token): Review {
		if (preg_match(self::MUSTER, $token) !== 1) {
			throw new TokenInvalidException(TokenInvalidException::FORMAT);
		}
		try {
			$review = $this->mapper->findByTokenHash(self::hash($token));
		} catch (DoesNotExistException) {
			throw new TokenInvalidException(TokenInvalidException::UNBEKANNT);
		}
		$this->pruefeGueltig($review);
		return $review;
	}

	/**
	 * Darf über dieses Review noch zugegriffen werden? Gemeinsam für den
	 * Kundenlink und die signierten Video-Adressen.
	 *
	 * @throws TokenInvalidException
	 */
	public function pruefeGueltig(Review $review): void {
		if ($review->getTokenRevokedAt() !== null) {
			throw new TokenInvalidException(TokenInvalidException::GESPERRT);
		}
		if ($review->isArchiviert()) {
			throw new TokenInvalidException(TokenInvalidException::ABGELAUFEN);
		}
		$ablauf = $review->getTokenExpiresAt();
		if ($ablauf !== null && $ablauf <= $this->time->getTime()) {
			throw new TokenInvalidException(TokenInvalidException::ABGELAUFEN);
		}
	}

	/** Das Token im Klartext, damit der Besitzer den Link erneut kopieren kann. */
	public function reveal(Review $review): string {
		return $this->crypto->decrypt((string)$review->getTokenEnc());
	}
}
