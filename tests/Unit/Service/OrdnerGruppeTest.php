<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Service\OrdnerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Welche Dateien dasselbe Video sind.
 * Im Zweifel trennen, nie zusammenführen.
 */
class OrdnerGruppeTest extends TestCase {
	/** @return array<string, array{0: string, 1: string}> */
	public static function faelle(): array {
		return [
			'v1' => ['Teaser_v1.mp4', 'Teaser'],
			'v2 groß' => ['Teaser_V2.mp4', 'Teaser'],
			'v02' => ['Teaser_v02.mov', 'Teaser'],
			'Leerzeichen' => ['Teaser v3.mp4', 'Teaser'],
			'Bindestrich' => ['Teaser-v4.mp4', 'Teaser'],
			'REV' => ['261011_kx_02_yt_REV3.mov', '261011_kx_02_yt'],
			'version' => ['Hauptfilm version 2.mp4', 'Hauptfilm'],
			'r' => ['Hauptfilm_r2.mp4', 'Hauptfilm'],
			'ohne Marke' => ['Teaser.mp4', 'Teaser'],
			'Ziffern bleiben' => ['Teaser_2.mp4', 'Teaser_2'],
			'Datum bleibt' => ['Imagefilm_20261014.mp4', 'Imagefilm_20261014'],
			'kein Trenner' => ['Trailer2.mp4', 'Trailer2'],
			'r ohne Trenner' => ['Teaserv2.mp4', 'Teaserv2'],
			'final bleibt' => ['Teaser final.mp4', 'Teaser final'],
			'nur Marke' => ['v2.mp4', 'v2'],
		];
	}

	#[DataProvider('faelle')]
	public function testGruppe(string $datei, string $erwartet): void {
		$this->assertSame($erwartet, OrdnerService::gruppe($datei));
	}

	public function testVerschiedeneVideosBleibenGetrennt(): void {
		$this->assertNotSame(OrdnerService::gruppe('Teaser_v1.mp4'), OrdnerService::gruppe('Hauptfilm_v1.mp4'));
	}
}
