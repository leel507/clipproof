<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Exception\TokenInvalidException;
use OCA\Clipproof\Service\TokenService;
use OCA\Clipproof\Service\VideoAdresse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/** Kurzlebige Video-Adressen ohne Review-Token. */
class VideoAdresseTest extends TestCase {
	private const JETZT = 1_791_741_600;

	private int $jetzt = self::JETZT;
	private Review $review;
	private ReviewVersion $version;
	/** @var array<string, string> */
	private array $config = [];

	protected function setUp(): void {
		$this->review = new Review();
		$this->review->setId(7);
		$this->review->setTokenHash(str_repeat('a', 64));
		$this->version = new ReviewVersion();
		$this->version->setId(11);
		$this->version->setReviewId(7);
	}

	private function dienst(): VideoAdresse {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn ($app, $k, $v = '') => $this->config[$k] ?? $v);
		$config->method('getValueInt')->willReturnCallback(fn ($app, $k, $v = 0) => isset($this->config[$k]) ? (int)$this->config[$k] : $v);
		$config->method('setValueString')->willReturnCallback(function ($app, $k, $v) {
			$this->config[$k] = $v;
			return true;
		});
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('g', 64));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->jetzt);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(fn ($route, $p) => '/v/' . $p['versionId'] . '/' . $p['ablauf'] . '/' . $p['signatur']);
		$reviews = $this->createMock(ReviewMapper::class);
		$reviews->method('find')->willReturnCallback(fn () => $this->review);
		$versions = $this->createMock(ReviewVersionMapper::class);
		$versions->method('find')->willReturnCallback(fn () => $this->version);
		$tokens = new TokenService($reviews, $random, $this->createMock(ICrypto::class), $time);
		return new VideoAdresse($config, $random, $time, $urls, $reviews, $versions, $tokens);
	}

	/** @return array{0: int, 1: int, 2: string} */
	private function teile(string $url): array {
		[, , $id, $ablauf, $sig] = explode('/', $url);
		return [(int)$id, (int)$ablauf, $sig];
	}

	public function testErzeugteAdresseGiltUndEnthaeltKeinToken(): void {
		$d = $this->dienst();
		$a = $d->erzeugen($this->review, $this->version);
		$this->assertSame(VideoAdresse::GUELTIG, $a['videoGueltigFuer']);
		$this->assertStringNotContainsString($this->review->getTokenHash(), $a['videoUrl']);
		$this->assertSame(11, $d->pruefen(...$this->teile($a['videoUrl']))->getId());
	}

	public function testAbgelaufen(): void {
		$d = $this->dienst();
		$a = $d->erzeugen($this->review, $this->version);
		$this->jetzt += VideoAdresse::GUELTIG;
		$this->expectExceptionObject(new TokenInvalidException(TokenInvalidException::ABGELAUFEN));
		$d->pruefen(...$this->teile($a['videoUrl']));
	}

	public function testAblaufVerlaengertFaellt(): void {
		$d = $this->dienst();
		[$id, $ablauf, $sig] = $this->teile($d->erzeugen($this->review, $this->version)['videoUrl']);
		$this->expectException(TokenInvalidException::class);
		$d->pruefen($id, $ablauf + 60, $sig);
	}

	public function testAndereVersionFaellt(): void {
		$d = $this->dienst();
		[, $ablauf, $sig] = $this->teile($d->erzeugen($this->review, $this->version)['videoUrl']);
		$this->expectException(TokenInvalidException::class);
		$d->pruefen(12, $ablauf, $sig);
	}

	public function testNeuerKundenlinkMachtAlteAdressenUngueltig(): void {
		$d = $this->dienst();
		$alt = $this->teile($d->erzeugen($this->review, $this->version)['videoUrl']);
		$this->review->setTokenHash(str_repeat('b', 64));
		$this->expectException(TokenInvalidException::class);
		$d->pruefen(...$alt);
	}

	public function testGesperrtesReviewFaellt(): void {
		$d = $this->dienst();
		$a = $this->teile($d->erzeugen($this->review, $this->version)['videoUrl']);
		$this->review->setTokenRevokedAt(self::JETZT);
		$this->expectExceptionObject(new TokenInvalidException(TokenInvalidException::GESPERRT));
		$d->pruefen(...$a);
	}

	public function testFormFalsch(): void {
		$this->expectExceptionObject(new TokenInvalidException(TokenInvalidException::FORMAT));
		$this->dienst()->pruefen(11, self::JETZT + 60, 'kurz');
	}

	public function testGeheimnisWirdEinmalErzeugtUndBleibt(): void {
		$d = $this->dienst();
		$eins = $d->erzeugen($this->review, $this->version)['videoUrl'];
		$this->assertSame(str_repeat('g', 64), $this->config[VideoAdresse::GEHEIMNIS]);
		$this->assertSame($eins, $this->dienst()->erzeugen($this->review, $this->version)['videoUrl']);
	}

	public function testGueltigkeitFuerProbenKuerzbar(): void {
		$this->config[VideoAdresse::GUELTIG_SCHLUESSEL] = '60';
		$this->assertSame(60, $this->dienst()->erzeugen($this->review, $this->version)['videoGueltigFuer']);
		$this->config[VideoAdresse::GUELTIG_SCHLUESSEL] = '999999';
		$this->assertSame(VideoAdresse::GUELTIG, $this->dienst()->gueltigkeit());
	}
}
