<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Clipproof\Service\Timecode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimecodeTest extends TestCase {
	/** @return array<string, array{int, int, bool, int, string}> */
	public static function faelle(): array {
		$json = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/timecode.json'), true, flags: JSON_THROW_ON_ERROR);
		$out = [];
		foreach ($json['faelle'] as $f) {
			$out[sprintf('%d/%d %s %d', $f['num'], $f['den'], $f['df'] ? 'DF' : 'NDF', $f['frames'])] = [$f['num'], $f['den'], $f['df'], $f['frames'], $f['tc']];
		}
		return $out;
	}

	#[DataProvider('faelle')]
	public function testVonFrames(int $num, int $den, bool $df, int $frames, string $tc): void {
		$this->assertSame($tc, Timecode::vonFrames($frames, $num, $den, $df));
	}

	#[DataProvider('faelle')]
	public function testZuFrames(int $num, int $den, bool $df, int $frames, string $tc): void {
		$this->assertSame($frames, Timecode::zuFrames($tc, $num, $den, $df));
	}

	/** Hin und zurück über zwei Stunden, jedes Bild. */
	public function testRundreiseDf5994(): void {
		for ($n = 0; $n < 2 * 215784; $n += 7) {
			$this->assertSame($n, Timecode::zuFrames(Timecode::vonFrames($n, 60000, 1001, true), 60000, 1001, true));
		}
	}

	public function testDfNurBeiNtsc(): void {
		$this->assertTrue(Timecode::dropFrameMoeglich(30000, 1001));
		$this->assertTrue(Timecode::dropFrameMoeglich(60000, 1001));
		$this->assertFalse(Timecode::dropFrameMoeglich(24000, 1001));
		$this->assertFalse(Timecode::dropFrameMoeglich(25, 1));
		$this->expectException(InvalidArgumentException::class);
		Timecode::vonFrames(0, 25, 1, true);
	}
}
