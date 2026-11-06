<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Exception\TokenInvalidException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Kurzlebige, signierte Video-Adressen ohne Review-Token. Grund: Manche
 * Webserver schreiben abgebrochene Antworten mit Adresse ins Protokoll, und beim Springen im Video bricht der Browser
 * ständig ab. Eine solche Adresse ist nach kurzer Zeit wertlos und verrät
 * nichts über Anmerkungen oder Namen.
 *
 * Signiert werden Version, Ablauf und die Prüfsumme des aktuellen
 * Kundenlinks: Wird der Link erneuert oder gesperrt, gelten auch alle
 * ausgegebenen Video-Adressen nicht mehr.
 */
class VideoAdresse {
	public const GEHEIMNIS = 'video_geheimnis';
	/** Nur für Proben: Gültigkeit in Sekunden überschreiben. */
	public const GUELTIG_SCHLUESSEL = 'video_gueltig';
	public const GUELTIG = 2 * 60 * 60;
	private const MUSTER = '/^[A-Za-z0-9_-]{43}$/';

	public function __construct(
		private IAppConfig $config,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private IURLGenerator $urls,
		private ReviewMapper $reviews,
		private ReviewVersionMapper $versions,
		private TokenService $tokens,
	) {
	}

	public function gueltigkeit(): int {
		$wert = $this->config->getValueInt(Application::APP_ID, self::GUELTIG_SCHLUESSEL, self::GUELTIG);
		return max(30, min(self::GUELTIG, $wert));
	}

	/**
	 * @return array{videoUrl: string, videoGueltigFuer: int} Adresse und
	 *         verbleibende Sekunden (relativ, unabhängig von der Uhr des Browsers)
	 */
	public function erzeugen(Review $review, ReviewVersion $version): array {
		$dauer = $this->gueltigkeit();
		$ablauf = $this->time->getTime() + $dauer;
		$signatur = $this->signatur((int)$version->getId(), $ablauf, (string)$review->getTokenHash());
		return [
			'videoUrl' => $this->urls->linkToRoute('clipproof.publicApi.videoSigniert', [
				'versionId' => (int)$version->getId(),
				'ablauf' => $ablauf,
				'signatur' => $signatur,
			]),
			'videoGueltigFuer' => $dauer,
		];
	}

	/**
	 * @throws TokenInvalidException wenn abgelaufen, gefälscht oder das Review gesperrt ist
	 */
	public function pruefen(int $versionId, int $ablauf, string $signatur): ReviewVersion {
		$jetzt = $this->time->getTime();
		if (preg_match(self::MUSTER, $signatur) !== 1) {
			throw new TokenInvalidException(TokenInvalidException::FORMAT);
		}
		// Abgelaufen, oder weiter in der Zukunft, als je ausgegeben wird.
		if ($ablauf <= $jetzt || $ablauf > $jetzt + self::GUELTIG + 60) {
			throw new TokenInvalidException(TokenInvalidException::ABGELAUFEN);
		}
		try {
			$version = $this->versions->find($versionId);
			$review = $this->reviews->find((int)$version->getReviewId());
		} catch (DoesNotExistException) {
			throw new TokenInvalidException(TokenInvalidException::UNBEKANNT);
		}
		if (!hash_equals($this->signatur($versionId, $ablauf, (string)$review->getTokenHash()), $signatur)) {
			throw new TokenInvalidException(TokenInvalidException::UNBEKANNT);
		}
		$this->tokens->pruefeGueltig($review);
		return $version;
	}

	private function signatur(int $versionId, int $ablauf, string $tokenHash): string {
		$roh = hash_hmac('sha256', 'clipproof-video-v1|' . $versionId . '|' . $ablauf . '|' . $tokenHash, $this->geheimnis(), true);
		return rtrim(strtr(base64_encode($roh), '+/', '-_'), '=');
	}

	/** Eigenes Geheimnis der App, beim ersten Gebrauch erzeugt, nie ausgegeben. */
	private function geheimnis(): string {
		$wert = $this->config->getValueString(Application::APP_ID, self::GEHEIMNIS, '');
		if (strlen($wert) < 32) {
			$wert = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->config->setValueString(Application::APP_ID, self::GEHEIMNIS, $wert, sensitive: true);
		}
		return $wert;
	}
}
