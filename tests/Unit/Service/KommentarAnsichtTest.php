<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\Guest;
use OCA\Clipproof\Db\GuestMapper;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Service\KommentarAnsicht;
use PHPUnit\Framework\TestCase;

/** „Erledigt“ sehen seit dem 15.10.2026 auch Kunden. */
class KommentarAnsichtTest extends TestCase {
	private function liste(bool $fuerBesitzer): array {
		$version = new ReviewVersion();
		$version->setId(2);
		$version->setFpsNum(25);
		$version->setFpsDen(1);
		$version->setStartTcFrames(0);
		$version->setDropFrame(false);
		$k = fn (int $id, bool $done) => (function () use ($id, $done) {
			$c = new Comment();
			$c->setId($id);
			$c->setVersionId(2);
			$c->setAuthorType(Comment::KUNDE);
			$c->setGuestId(5);
			$c->setFrameIn(25);
			$c->setText('Text');
			$c->setDone($done);
			$c->setCreatedAt(1_791_741_600);
			return $c;
		})();
		$gaeste = $this->createMock(GuestMapper::class);
		$gast = new Guest();
		$gast->setName('Frau Probe');
		$gaeste->method('findByIds')->willReturn([5 => $gast]);
		return (new KommentarAnsicht($gaeste, $this->createMock(\OCP\IUserManager::class)))->liste([$k(1, true), $k(2, false)], [2 => $version], [], null, $fuerBesitzer);
	}

	public function testKundeSiehtErledigt(): void {
		$l = $this->liste(false);
		$this->assertTrue($l[0]['erledigt']);
		$this->assertFalse($l[1]['erledigt']);
	}

	public function testBesitzerSiehtErledigt(): void {
		$this->assertTrue($this->liste(true)[0]['erledigt']);
	}
}
