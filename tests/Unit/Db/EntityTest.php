<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Db;

use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewVersion;
use PHPUnit\Framework\TestCase;

/**
 * Nextclouds Entity schreibt beim Einfügen nur Felder, die per Setter
 * geändert wurden. Ein Vorgabewert, der dem gesetzten Wert gleicht,
 * würde sonst als null in der Datenbank landen (gefunden in E3:
 * fps_den = 1 fehlte bei 25 fps).
 */
class EntityTest extends TestCase {
	public function testVersionSchreibtAuchNaheliegendeWerte(): void {
		$v = new ReviewVersion();
		$v->setFpsNum(25);
		$v->setFpsDen(1);
		$v->setFpsSource(ReviewVersion::FPS_CONTAINER);
		$v->setDropFrame(false);
		$v->setStartTcFrames(0);
		$v->setNumber(1);
		$geaendert = array_keys($v->getUpdatedFields());
		foreach (['fpsNum', 'fpsDen', 'fpsSource', 'dropFrame', 'startTcFrames', 'number'] as $feld) {
			$this->assertContains($feld, $geaendert, $feld . ' würde nicht geschrieben');
		}
	}

	public function testReviewSchreibtAnredeUndSperre(): void {
		$r = new Review();
		$r->setAnrede(Review::ANREDE_SIE);
		$r->setCommentsLocked(false);
		$geaendert = array_keys($r->getUpdatedFields());
		$this->assertContains('anrede', $geaendert);
		$this->assertContains('commentsLocked', $geaendert);
	}
}
