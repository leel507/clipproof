<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\Guest;
use OCA\Clipproof\Db\GuestMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Exception\KommentarException;
use OCA\Clipproof\Service\AbgabeService;
use OCA\Clipproof\Service\CommentService;
use OCA\Clipproof\Service\GuestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class KommentarTest extends TestCase {
	private CommentMapper&MockObject $comments;
	private ReviewVersionMapper&MockObject $versions;
	private CommentService $service;
	private Review $review;
	private ReviewVersion $version;
	private Guest $gast;

	protected function setUp(): void {
		$this->comments = $this->createMock(CommentMapper::class);
		$this->comments->method('insert')->willReturnArgument(0);
		$this->comments->method('update')->willReturnArgument(0);
		$this->versions = $this->createMock(ReviewVersionMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_791_741_600);
		$this->service = new CommentService($this->comments, $this->versions, $time);

		$this->review = new Review();
		$this->review->setId(7);
		$this->review->setTitle('Imagefilm');
		$this->review->setCommentsLocked(false);
		$this->version = $this->version(id: 6, nummer: 2, num: 60000, den: 1001, df: true, start: 215784, bilder: 3597);
		$this->versions->method('find')->willReturn($this->version);
		$this->versions->method('findForReview')->willReturn([$this->version]);
		$this->gast = new Guest();
		$this->gast->setId(3);
		$this->gast->setName('Frau Beispiel');
	}

	private function version(int $id, int $nummer, int $num, int $den, bool $df, int $start, int $bilder): ReviewVersion {
		$v = new ReviewVersion();
		$v->setId($id);
		$v->setReviewId(7);
		$v->setNumber($nummer);
		$v->setFpsNum($num);
		$v->setFpsDen($den);
		$v->setDropFrame($df);
		$v->setStartTcFrames($start);
		$v->setDurationFrames($bilder);
		return $v;
	}

	private function kommentar(int $frame, string $text): Comment {
		$k = new Comment();
		$k->setFrameIn($frame);
		$k->setText($text);
		return $k;
	}

	// Protokolltext

	public function testProtokolltextMehrzahlUndDropFrame(): void {
		$text = AbgabeService::protokolltext($this->review, $this->version, $this->kommentar(1800, 'Logo kleiner'), 3, 1_791_741_600);
		$this->assertSame('Review "Imagefilm" version 2, sent 2026-10-11 18:00 UTC, 3 comments. First at 01:00:30;00 (frame 1800): "Logo kleiner"', $text);
	}

	public function testProtokolltextEinzahlUndEinWort(): void {
		$text = AbgabeService::protokolltext($this->review, $this->version, $this->kommentar(0, 'Logo'), 1, 1_791_741_600);
		$this->assertStringContainsString('1 comment.', $text);
		$this->assertGreaterThan(5, mb_strlen($text), 'Beleg ist immer länger als fünf Zeichen');
	}

	public function testProtokolltextNurZeichnungUndKuerzung(): void {
		$this->assertStringEndsWith('"(drawing)"', AbgabeService::protokolltext($this->review, $this->version, $this->kommentar(5, ''), 1, 1_791_741_600));
		$lang = AbgabeService::protokolltext($this->review, $this->version, $this->kommentar(5, str_repeat('a', 500)), 1, 1_791_741_600);
		$this->assertStringEndsWith('…"', $lang);
		$this->assertLessThan(320, mb_strlen($lang));
	}

	// Anlegen

	public function testEinWortGenuegt(): void {
		$k = $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 10, 'text' => 'Logo']);
		$this->assertSame(Comment::KUNDE, $k->getAuthorType());
		$this->assertNull($k->getSubmissionId(), 'Kunden schreiben Entwürfe');
		$this->assertSame(3, $k->getGuestId());
	}

	public function testLeerWirdAbgelehnt(): void {
		$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 10, 'text' => '   ']));
	}

	public function testZeichnungOhneTextGeht(): void {
		$k = $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 10, 'zeichnung' => '{"farbe":"#FFD60A","striche":[[[0.1,0.2],[0.3,0.4]]]}']);
		$this->assertSame('{"farbe":"#ffd60a","striche":[[[0.1,0.2],[0.3,0.4]]]}', $k->getDrawing());
	}

	public function testLeereZeichnungZaehltNicht(): void {
		$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 10, 'zeichnung' => '{"striche":[]}']));
	}

	/** Sicherheitsprüfung 15.10.2026: nur die erwartete Form, neu geschrieben. */
	public function testZeichnungWirdBereinigt(): void {
		$k = $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 10, 'text' => 'x',
			'zeichnung' => '{"farbe":"red;background:url(x)","striche":[[[5,-3],[0.123456,0.5]]],"boese":"<script>"}']);
		$this->assertSame('{"farbe":"#ff4d4f","striche":[[[1,0],[0.1235,0.5]]]}', $k->getDrawing());
	}

	public function testKaputteZeichnungenWerdenAbgelehnt(): void {
		foreach (['{"farbe":"#fff"}', '{"striche":"x"}', '{"striche":[["a"]]}', '{"striche":[[[1]]]}', '{"striche":[[[1,"x"]]]}', '[1,2]'] as $z) {
			$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 10, 'text' => 'x', 'zeichnung' => $z]));
		}
	}

	public function testUnlesbareZeichnungWirdAbgelehnt(): void {
		$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 10, 'zeichnung' => '{kaputt']));
	}

	public function testFrameAusserhalbWirdAbgelehnt(): void {
		$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 3597, 'text' => 'x']));
		$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => -1, 'text' => 'x']));
		$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 100, 'frameOut' => 99, 'text' => 'x']));
		// Letzter Frame ist erlaubt.
		$this->assertSame(3596, $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 3596, 'text' => 'x'])->getFrameIn());
	}

	public function testFremdeVersionWirdAbgelehnt(): void {
		$fremd = $this->version(id: 99, nummer: 1, num: 25, den: 1, df: false, start: 0, bilder: 100);
		$fremd->setReviewId(8);
		$versions = $this->createMock(ReviewVersionMapper::class);
		$versions->method('find')->willReturn($fremd);
		$time = $this->createMock(ITimeFactory::class);
		$service = new CommentService($this->comments, $versions, $time);
		$this->erwarteFehler(fn () => $service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 99, 'frameIn' => 1, 'text' => 'x']), 404);
	}

	public function testNachFreigabeGesperrt(): void {
		$this->review->setCommentsLocked(true);
		$this->erwarteFehler(fn () => $this->service->entwurfAnlegen($this->review, $this->gast, ['versionId' => 6, 'frameIn' => 1, 'text' => 'x']), 423);
	}

	// Ändern und Löschen

	public function testAbgesendetIstFest(): void {
		$k = $this->kommentar(10, 'x');
		$k->setId(50);
		$k->setVersionId(6);
		$k->setGuestId(3);
		$k->setSubmissionId(1);
		$this->comments->method('find')->willReturn($k);
		$this->erwarteFehler(fn () => $this->service->entwurfAendern($this->review, $this->gast, 50, ['text' => 'neu']), 409);
		$this->erwarteFehler(fn () => $this->service->entwurfLoeschen($this->review, $this->gast, 50), 409);
	}

	public function testFremderEntwurfIstUnsichtbar(): void {
		$k = $this->kommentar(10, 'x');
		$k->setId(51);
		$k->setVersionId(6);
		$k->setGuestId(4);
		$this->comments->method('find')->willReturn($k);
		$this->erwarteFehler(fn () => $this->service->entwurfAendern($this->review, $this->gast, 51, ['text' => 'neu']), 404);
	}

	public function testBesitzerSiehtKeineEntwuerfeBeimAbhaken(): void {
		$k = $this->kommentar(10, 'x');
		$k->setId(52);
		$k->setVersionId(6);
		$k->setAuthorType(Comment::KUNDE);
		$this->comments->method('find')->willReturn($k);
		$this->erwarteFehler(fn () => $this->service->erledigt($this->review, 52, true), 404);
	}

	// Gast

	public function testGastBrauchtNamenUndSpeichertNurHash(): void {
		$mapper = $this->createMock(GuestMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z');
		$service = new GuestService($mapper, $random, $this->createMock(ITimeFactory::class));

		[$gast, $schluessel] = $service->anmelden($this->review, "  Frau   Beispiel \n");
		$this->assertSame('Frau Beispiel', $gast->getName());
		$this->assertSame(hash('sha256', $schluessel), $gast->getSecretHash());
		$this->erwarteFehler(fn () => $service->anmelden($this->review, '   '));
		$this->assertNull($service->finden($this->review, 'kein-gueltiger-schluessel'));
	}

	private function erwarteFehler(callable $tun, int $status = 400): void {
		try {
			$tun();
			$this->fail('KommentarException erwartet');
		} catch (KommentarException $e) {
			$this->assertSame($status, $e->getStatus(), $e->getMessage());
		}
	}
}
