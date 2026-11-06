<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Service\RangeStream;
use PHPUnit\Framework\TestCase;

class RangeStreamTest extends TestCase {
	public function testOhneKopfzeileGanzeDatei(): void {
		$this->assertNull(RangeStream::parse(null, 1000));
		$this->assertNull(RangeStream::parse('', 1000));
	}

	public function testOhneAnfrageKleinGanzGrossBegrenzt(): void {
		$this->assertNull(RangeStream::ohneAnfrage(1000));
		$this->assertNull(RangeStream::ohneAnfrage(RangeStream::MAX_SPANNE));
		$this->assertSame([0, RangeStream::MAX_SPANNE - 1], RangeStream::ohneAnfrage(1_500_000_000));
	}

	public function testVonBis(): void {
		$this->assertSame([0, 99], RangeStream::parse('bytes=0-99', 1000));
		$this->assertSame([500, 999], RangeStream::parse('bytes=500-', 1000));
		$this->assertSame([900, 999], RangeStream::parse('bytes=-100', 1000));
	}

	/** Safari fragt zuerst bytes=0-1 an. */
	public function testSafariSonde(): void {
		$this->assertSame([0, 1], RangeStream::parse('bytes=0-1', 1000));
	}

	/** E4: Offene Anfragen bekommen höchstens MAX_SPANNE Bytes. */
	public function testGrosseSpannenWerdenBegrenzt(): void {
		$groesse = 1_500_000_000;
		$max = RangeStream::MAX_SPANNE;
		$this->assertSame([0, $max - 1], RangeStream::parse('bytes=0-', $groesse));
		$this->assertSame([1000, 1000 + $max - 1], RangeStream::parse('bytes=1000-', $groesse));
		$this->assertSame([0, $max - 1], RangeStream::parse('bytes=0-999999999', $groesse));
		$this->assertSame([$groesse - $max, $groesse - 1], RangeStream::parse('bytes=-' . $max, $groesse));
		// Am Dateiende bleibt es bei der Dateigröße.
		$this->assertSame([$groesse - 10, $groesse - 1], RangeStream::parse('bytes=' . ($groesse - 10) . '-', $groesse));
	}

	public function testEndeWirdAufDateigroesseBegrenzt(): void {
		$this->assertSame([10, 999], RangeStream::parse('bytes=10-5000', 1000));
		$this->assertSame([0, 999], RangeStream::parse('bytes=-5000', 1000));
		$this->assertSame([0, 999], RangeStream::parse('bytes=0-', 1000));
	}

	public function testUnerfuellbar(): void {
		$this->assertSame(RangeStream::UNERFUELLBAR, RangeStream::parse('bytes=1000-', 1000));
		$this->assertSame(RangeStream::UNERFUELLBAR, RangeStream::parse('bytes=-0', 1000));
	}

	public function testUngueltigOderMehrereSpannenGanzeDatei(): void {
		$this->assertNull(RangeStream::parse('bytes=0-1,5-6', 1000));
		$this->assertNull(RangeStream::parse('items=0-1', 1000));
		$this->assertNull(RangeStream::parse('bytes=9-3', 1000));
		$this->assertNull(RangeStream::parse('bytes=-', 1000));
	}
}
