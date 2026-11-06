<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Exception\TokenInvalidException;
use OCA\Clipproof\Service\TokenService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase {
	private const JETZT = 1_791_741_600; // 11.10.2026, 18:00 UTC
	private const TOKEN = 'Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z';

	private ReviewMapper&MockObject $mapper;
	private ISecureRandom&MockObject $random;
	private ICrypto&MockObject $crypto;
	private TokenService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ReviewMapper::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::JETZT);
		$this->service = new TokenService($this->mapper, $this->random, $this->crypto, $time);
	}

	private function reviewMitToken(?int $ablauf = null, ?int $gesperrt = null): Review {
		$review = new Review();
		$review->setTokenHash(TokenService::hash(self::TOKEN));
		$review->setTokenExpiresAt($ablauf);
		$review->setTokenRevokedAt($gesperrt);
		return $review;
	}

	// Erzeugen

	public function testAssignErzeugtTokenUndSpeichertNurHashUndVerschluesselt(): void {
		$this->random->expects($this->once())
			->method('generate')
			->with(32, ISecureRandom::CHAR_ALPHANUMERIC)
			->willReturn(self::TOKEN);
		$this->crypto->method('encrypt')->willReturn('verschluesselt');

		$review = new Review();
		$review->setTokenRevokedAt(123);
		$token = $this->service->assign($review);

		$this->assertSame(self::TOKEN, $token);
		$this->assertSame(hash('sha256', self::TOKEN), $review->getTokenHash());
		$this->assertSame('verschluesselt', $review->getTokenEnc());
		$this->assertNotSame(self::TOKEN, $review->getTokenHash());
		$this->assertNull($review->getTokenRevokedAt(), 'Neues Token hebt eine Sperre auf');
	}

	public function testRenewSpeichert(): void {
		$this->random->method('generate')->willReturn(self::TOKEN);
		$this->crypto->method('encrypt')->willReturn('x');
		$this->mapper->expects($this->once())->method('update');
		$this->service->renew(new Review());
	}

	public function testRevealEntschluesselt(): void {
		$this->crypto->method('decrypt')->with('verschluesselt')->willReturn(self::TOKEN);
		$review = new Review();
		$review->setTokenEnc('verschluesselt');
		$this->assertSame(self::TOKEN, $this->service->reveal($review));
	}

	// Prüfen

	public function testResolveGueltigesToken(): void {
		$review = $this->reviewMitToken();
		$this->mapper->expects($this->once())
			->method('findByTokenHash')
			->with(hash('sha256', self::TOKEN))
			->willReturn($review);
		$this->assertSame($review, $this->service->resolve(self::TOKEN));
	}

	public function testResolveUnbekanntesToken(): void {
		$this->mapper->method('findByTokenHash')->willThrowException(new DoesNotExistException(''));
		$this->assertGrund(TokenInvalidException::UNBEKANNT, self::TOKEN);
	}

	/** Falsches Format erreicht die Datenbank gar nicht erst. */
	public function testResolveFalschesFormat(): void {
		$this->mapper->expects($this->never())->method('findByTokenHash');
		foreach (['', 'kurz', self::TOKEN . 'x', substr(self::TOKEN, 0, 31) . '/', "Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4\n"] as $falsch) {
			$this->assertGrund(TokenInvalidException::FORMAT, $falsch);
		}
	}

	// Ablauf

	public function testResolveAbgelaufen(): void {
		$this->mapper->method('findByTokenHash')->willReturn($this->reviewMitToken(self::JETZT - 1));
		$this->assertGrund(TokenInvalidException::ABGELAUFEN, self::TOKEN);
	}

	public function testResolveAbgelaufenGenauZumAblaufzeitpunkt(): void {
		$this->mapper->method('findByTokenHash')->willReturn($this->reviewMitToken(self::JETZT));
		$this->assertGrund(TokenInvalidException::ABGELAUFEN, self::TOKEN);
	}

	public function testResolveKurzVorAblaufNochGueltig(): void {
		$review = $this->reviewMitToken(self::JETZT + 1);
		$this->mapper->method('findByTokenHash')->willReturn($review);
		$this->assertSame($review, $this->service->resolve(self::TOKEN));
	}

	public function testFreigabeSetztAblaufAuf30TageUndSperrtKommentare(): void {
		$freigabe = self::JETZT - 1000;
		$review = new Review();
		$this->mapper->expects($this->once())->method('update');
		$this->service->applyFreigabe($review, $freigabe);

		$this->assertSame($freigabe + 30 * 86400, $review->getTokenExpiresAt());
		$this->assertTrue($review->isCommentsLocked());
		$this->assertSame($freigabe, $review->getFreigabeAm());
	}

	public function testRuecknahmeDerFreigabeHebtAblaufUndSperreAuf(): void {
		$review = new Review();
		$review->setTokenExpiresAt(self::JETZT + 5);
		$review->setCommentsLocked(true);
		$this->service->applyFreigabe($review, null);

		$this->assertNull($review->getTokenExpiresAt());
		$this->assertFalse($review->isCommentsLocked());
	}

	public function testDreissigTageNachFreigabeIstDerLinkInaktiv(): void {
		$review = $this->reviewMitToken();
		$this->service->applyFreigabe($review, self::JETZT - 30 * 86400);
		$this->mapper->method('findByTokenHash')->willReturn($review);
		$this->assertGrund(TokenInvalidException::ABGELAUFEN, self::TOKEN);
	}

	// Entwerten

	public function testRevokeSperrtSofort(): void {
		$review = $this->reviewMitToken();
		$this->mapper->expects($this->once())->method('update')->with($review);
		$this->service->revoke($review);
		$this->assertSame(self::JETZT, $review->getTokenRevokedAt());

		$this->mapper->method('findByTokenHash')->willReturn($review);
		$this->assertGrund(TokenInvalidException::GESPERRT, self::TOKEN);
	}

	public function testGesperrtGehtVorUnbefristet(): void {
		$this->mapper->method('findByTokenHash')->willReturn($this->reviewMitToken(null, self::JETZT - 10));
		$this->assertGrund(TokenInvalidException::GESPERRT, self::TOKEN);
	}

	private function assertGrund(string $grund, string $token): void {
		try {
			$this->service->resolve($token);
			$this->fail('TokenInvalidException erwartet: ' . $grund);
		} catch (TokenInvalidException $e) {
			$this->assertSame($grund, $e->getReason());
		}
	}
}
