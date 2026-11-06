<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Service\ExportService;
use PHPUnit\Framework\TestCase;

class ExportServiceTest extends TestCase {
	private Review $review;

	protected function setUp(): void {
		$this->review = new Review();
		$this->review->setId(7);
		$this->review->setTitle('Imagefilm');
	}

	private function version(int $num, int $den, bool $df, int $start, int $bilder): ReviewVersion {
		$v = new ReviewVersion();
		$v->setNumber(2);
		$v->setFpsNum($num);
		$v->setFpsDen($den);
		$v->setDropFrame($df);
		$v->setStartTcFrames($start);
		$v->setDurationFrames($bilder);
		$v->setWidth(1280);
		$v->setHeight(720);
		return $v;
	}

	private function k(int $id, int $frame, string $text, ?int $abgabe = 1, string $typ = Comment::KUNDE, ?int $eltern = null, bool $erledigt = false): Comment {
		$k = new Comment();
		$k->setId($id);
		$k->setFrameIn($frame);
		$k->setText($text);
		$k->setAuthorType($typ);
		$k->setGuestId($typ === Comment::KUNDE ? 3 : null);
		$k->setSubmissionId($abgabe);
		$k->setParentId($eltern);
		$k->setDone($erledigt);
		return $k;
	}

	public function testMarkerOhneEntwuerfeMitAntwortenSortiert(): void {
		$marker = ExportService::marker([
			$this->k(1, 3596, 'Ende'),
			$this->k(2, 0, 'Anfang'),
			$this->k(3, 50, 'Entwurf', abgabe: null),
			$this->k(4, 0, 'Erledigt.', null, Comment::BESITZER, 2),
		], [3 => 'Frau Beispiel']);
		$this->assertCount(2, $marker, 'Entwurf und Antwort sind keine eigenen Marker');
		$this->assertSame([0, 3596], array_column($marker, 'frameIn'));
		$this->assertSame(['Owner: Erledigt.'], $marker[0]['antworten']);
	}

	/** Abnahme E7: erster und letzter Frame eines Drop-Frame-Clips. */
	public function testEdlDropFrameErsterUndLetzterFrame(): void {
		$v = $this->version(60000, 1001, true, 215784, 3597);
		$marker = ExportService::marker([$this->k(1, 0, 'Anfang'), $this->k(2, 3596, "Ende\nzweite Zeile | mit Strich", erledigt: true)], [3 => 'Frau Beispiel']);
		$edl = ExportService::edl($this->review, $v, $marker);
		$this->assertSame(
			"TITLE: Imagefilm Version 2\r\n"
			. "FCM: DROP FRAME\r\n"
			. "\r\n"
			. "001  001      V     C        01:00:00;00 01:00:00;01 01:00:00;00 01:00:00;01  \r\n"
			. " |C:ResolveColorBlue |M:#1 Frau Beispiel: Anfang |D:1\r\n"
			. "\r\n"
			. "002  001      V     C        01:00:59;56 01:00:59;57 01:00:59;56 01:00:59;57  \r\n"
			. " |C:ResolveColorGreen |M:#2 Frau Beispiel: Ende zweite Zeile mit Strich |D:1\r\n"
			. "\r\n",
			$edl,
		);
	}

	public function testEdlNonDropUndBereich(): void {
		$v = $this->version(25, 1, false, 90000, 1500);
		$k = $this->k(1, 1499, 'Bereich');
		$k->setFrameOut(1499);
		$k2 = $this->k(2, 100, 'Zehn Bilder');
		$k2->setFrameOut(109);
		$edl = ExportService::edl($this->review, $v, ExportService::marker([$k, $k2], []));
		$this->assertStringContainsString("FCM: NON-DROP FRAME\r\n", $edl);
		$this->assertStringContainsString('01:00:04:00 01:00:04:01', $edl);
		$this->assertStringContainsString('|D:10', $edl);
		$this->assertStringContainsString('01:00:59:24 01:00:59:24', str_replace('01:01:00:00', '01:00:59:24', $edl));
		$this->assertStringNotContainsString(';', $edl);
	}

	public function testFcp7DropFrame(): void {
		$v = $this->version(30000, 1001, true, 107892, 1799);
		$xml = ExportService::fcp7($this->review, $v, ExportService::marker([$this->k(1, 1798, 'Letztes <Bild> & „Ende“')], [3 => 'Frau B']));
		$dom = new \DOMDocument();
		$this->assertTrue($dom->loadXML($xml), 'gültiges XML');
		$this->assertStringContainsString('<timebase>30</timebase><ntsc>TRUE</ntsc>', $xml);
		$this->assertStringContainsString('<string>01:00:00;00</string><frame>107892</frame><displayformat>DF</displayformat>', $xml);
		$this->assertStringContainsString('<in>1798</in>', $xml);
		$this->assertStringContainsString('<out>-1</out>', $xml);
		$this->assertStringContainsString('Frau B: Letztes &lt;Bild&gt; &amp; „Ende“', $xml);
	}

	public function testCsv(): void {
		$v = $this->version(25, 1, false, 0, 1500);
		$csv = ExportService::csv($v, ExportService::marker([$this->k(1, 1654 - 154, 'Logo; kleiner')], [3 => 'Frau Ä']));
		$this->assertStringStartsWith("\xEF\xBB\xBF" . 'Nr;Timecode;Frame;', $csv);
		$this->assertStringContainsString('#1;00:01:00:00;1500;;"Frau Ä";nein;"Logo; kleiner";', $csv, 'voller Text mit Umlauten bleibt in der CSV');
	}

	public function testEdlNurAsciiMitSichtbarerUmschrift(): void {
		$v = $this->version(25, 1, false, 0, 100);
		$edl = ExportService::edl($this->review, $v, ExportService::marker([$this->k(1, 5, 'Grünstich raus, „Logo“ größer – bitte ✓')], [3 => 'Jörg']));
		$this->assertSame(1, preg_match('/^[\x00-\x7F]*$/', $edl), 'EDL ist reines ASCII');
		$this->assertStringContainsString('|M:#1 Joerg: Gruenstich raus, "Logo" groesser - bitte ? |D:1', $edl);
	}

	/** Zwei Marker auf demselben Frame, Frame 0 und letzter Frame: Reihenfolge bleibt. */
	public function testGleicherFrameUndRaender(): void {
		$v = $this->version(25, 1, false, 0, 100);
		$m = ExportService::marker([$this->k(3, 99, 'Letzter'), $this->k(1, 0, 'Erster A'), $this->k(2, 0, 'Erster B')], []);
		$this->assertSame(['Erster A', 'Erster B', 'Letzter'], array_column($m, 'text'));
		$edl = ExportService::edl($this->review, $v, $m);
		$this->assertSame(3, substr_count($edl, '|M:'));
		$this->assertStringContainsString('003  001      V     C        00:00:03:24 00:00:04:00', $edl);
	}

	/** Sicherheitsprüfung 15.10.2026: Kundentext läuft nie als Formel. */
	public function testCsvOhneFormeln(): void {
		foreach (['=HYPERLINK("x")', '+1', '-1+2', '@SUM(A1)', "\tx"] as $boese) {
			$this->assertSame("'" . $boese, ExportService::keineFormel($boese));
		}
		$this->assertSame('Logo größer', ExportService::keineFormel('Logo größer'));
	}
}
