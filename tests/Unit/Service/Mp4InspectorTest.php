<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Exception\Mp4Exception;
use OCA\Clipproof\Service\Mp4Info;
use OCA\Clipproof\Service\Mp4Inspector;
use PHPUnit\Framework\TestCase;

class Mp4InspectorTest extends TestCase {
	private const TESTDATEN = __DIR__ . '/../../../testdaten/';

	// Selbst gebaute Mini-Dateien: prüfen die Rechnung ohne Testclips.

	public function testKonstante25fps(): void {
		$info = $this->inspectBytes($this->mp4(25000, [[100, 1000]]));
		$this->assertSame([25, 1], [$info->fpsNum, $info->fpsDen]);
		$this->assertTrue($info->constantFrameRate);
		$this->assertSame(100, $info->frameCount);
		$this->assertSame([1920, 1080], [$info->width, $info->height]);
		$this->assertNull($info->startFrame);
	}

	public function testNtscBruchWirdGekuerzt(): void {
		// 29,97 bei Zeitbasis 30000 und 23,976 bei Zeitbasis 24000 * 10
		$info = $this->inspectBytes($this->mp4(30000, [[10, 1001]]));
		$this->assertSame([30000, 1001], [$info->fpsNum, $info->fpsDen]);
		$info = $this->inspectBytes($this->mp4(240000, [[10, 10010]]));
		$this->assertSame([24000, 1001], [$info->fpsNum, $info->fpsDen]);
	}

	public function testLetztesBildDarfAbweichen(): void {
		$info = $this->inspectBytes($this->mp4(12800, [[99, 512], [1, 300]]));
		$this->assertTrue($info->constantFrameRate);
		$this->assertSame([25, 1], [$info->fpsNum, $info->fpsDen]);
		$this->assertSame(100, $info->frameCount);
	}

	public function testVariableBildrateWirdErkannt(): void {
		$info = $this->inspectBytes($this->mp4(90000, [[10, 3000], [5, 3003], [10, 3000]]));
		$this->assertFalse($info->constantFrameRate);
		$this->assertSame(2, $info->distinctDurations);
	}

	public function testMoovVorneOderHinten(): void {
		$this->assertTrue($this->inspectBytes($this->mp4(25000, [[1, 1000]], moovVorne: true))->moovAtStart);
		$this->assertFalse($this->inspectBytes($this->mp4(25000, [[1, 1000]], moovVorne: false))->moovAtStart);
	}

	/** Upload oder Kopie abgebrochen, moov liegt vorne. */
	public function testAbgeschnitteneDateiWirdErkannt(): void {
		$voll = $this->mp4(25000, [[25, 1000]], moovVorne: true);
		$this->assertSame(25, $this->inspectBytes($voll)->frameCount);
		$this->expectException(Mp4Exception::class);
		$this->expectExceptionMessage('File incomplete');
		$this->inspectBytes(substr($voll, 0, -3));
	}

	public function testOhneVideospur(): void {
		$this->expectException(Mp4Exception::class);
		$this->inspectBytes($this->box('ftyp', 'isom') . $this->box('moov', ''));
	}

	public function testKeinMp4(): void {
		$this->expectException(Mp4Exception::class);
		$this->inspectBytes('Das ist kein Video, sondern Text.');
	}

	// Eigene Testclips (nicht im Repository; fehlen sie, wird übersprungen).
	// Erwartete Werte am 11.10.2026 mit ffprobe ermittelt.

	public function testClip25fps(): void {
		$info = $this->inspectFile('23.mp4');
		$this->assertSame([25, 1], [$info->fpsNum, $info->fpsDen]);
		$this->assertTrue($info->constantFrameRate);
		$this->assertSame(1655, $info->frameCount);
		$this->assertSame([1728, 3072], [$info->width, $info->height]);
		$this->assertSame('avc1', $info->codec);
		$this->assertNull($info->startFrame);
		$this->assertTrue($info->moovAtStart);
	}

	/** QuickTime-Stil: zweiter hdlr unter minf darf den Spurtyp nicht überschreiben. */
	public function testDatenHandlerUnterMinfWirdIgnoriert(): void {
		$bytes = $this->mp4(25000, [[10, 1000]], datenHandler: true);
		$info = $this->inspectBytes($bytes);
		$this->assertSame([25, 1], [$info->fpsNum, $info->fpsDen]);
	}

	public function testClip5994DropFrame(): void {
		$info = $this->inspectFile('nuttensohn.mp4');
		$this->assertSame([60000, 1001], [$info->fpsNum, $info->fpsDen]);
		$this->assertTrue($info->constantFrameRate);
		$this->assertSame(646, $info->frameCount);
		$this->assertSame([1920, 1080], [$info->width, $info->height]);
		$this->assertTrue($info->dropFrame);
		// 01:00:00;00 bei 59,94 Drop-Frame: 216000 Bildnummern je Stunde,
		// minus 4 ausgelassene Nummern in 54 von 60 Minuten.
		$this->assertSame(216000 - 4 * 54, $info->startFrame);
	}

	private function inspectFile(string $name): Mp4Info {
		$pfad = self::TESTDATEN . $name;
		if (!is_file($pfad)) {
			$this->markTestSkipped('Testclip fehlt: ' . $name);
		}
		$f = fopen($pfad, 'rb');
		$this->assertNotFalse($f);
		try {
			return (new Mp4Inspector())->inspect($f);
		} finally {
			fclose($f);
		}
	}

	private function inspectBytes(string $bytes): Mp4Info {
		$f = fopen('php://memory', 'w+b');
		$this->assertNotFalse($f);
		fwrite($f, $bytes);
		rewind($f);
		try {
			return (new Mp4Inspector())->inspect($f);
		} finally {
			fclose($f);
		}
	}

	private function box(string $type, string $payload): string {
		return pack('N', 8 + strlen($payload)) . $type . $payload;
	}

	/**
	 * Minimale MP4 mit einer Videospur.
	 *
	 * @param list<array{0: int, 1: int}> $stts Anzahl und Dauer je Eintrag
	 */
	private function mp4(int $timescale, array $stts, bool $moovVorne = true, bool $datenHandler = false): string {
		$frames = array_sum(array_column($stts, 0));
		$tkhd = $this->box('tkhd', str_repeat("\0", 76) . pack('NN', 1920 << 16, 1080 << 16));
		$mdhd = $this->box('mdhd', "\0\0\0\0" . pack('NNNN', 0, 0, $timescale, 0) . "\0\0\0\0");
		$hdlr = $this->box('hdlr', "\0\0\0\0" . "\0\0\0\0" . 'vide' . str_repeat("\0", 13));
		$sttsBody = "\0\0\0\0" . pack('N', count($stts));
		foreach ($stts as [$count, $delta]) {
			$sttsBody .= pack('NN', $count, $delta);
		}
		$stbl = $this->box('stbl', $this->box('stsd', "\0\0\0\0" . pack('N', 0))
			. $this->box('stts', $sttsBody)
			. $this->box('stsz', "\0\0\0\0" . pack('NN', 0, $frames)));
		$minf = $datenHandler
			? $this->box('hdlr', "\0\0\0\0" . 'dhlr' . 'alis' . str_repeat("\0", 13)) . $stbl
			: $stbl;
		$moov = $this->box('moov', $this->box('trak', $tkhd . $this->box('mdia', $mdhd . $hdlr . $this->box('minf', $minf))));
		$ftyp = $this->box('ftyp', 'isom');
		$mdat = $this->box('mdat', str_repeat("\0", 16));
		return $moovVorne ? $ftyp . $moov . $mdat : $ftyp . $mdat . $moov;
	}
}
