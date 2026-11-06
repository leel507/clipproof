<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

/**
 * Was der Mp4Inspector aus dem Container gelesen hat.
 */
final class Mp4Info {
	public function __construct(
		/** Bildrate als gekürzter Bruch, etwa 25/1 oder 60000/1001 */
		public readonly int $fpsNum,
		public readonly int $fpsDen,
		/** Alle Bilder gleich lang (das letzte darf abweichen) */
		public readonly bool $constantFrameRate,
		/** Anzahl verschiedener Bilddauern, 1 bei konstanter Bildrate */
		public readonly int $distinctDurations,
		public readonly int $frameCount,
		public readonly ?int $width,
		public readonly ?int $height,
		/** Start-Timecode als Framezahl aus einer tmcd-Spur, sonst null */
		public readonly ?int $startFrame,
		/** Drop-Frame laut tmcd-Spur, sonst null */
		public readonly ?bool $dropFrame,
		/** moov steht vor mdat („Network Optimization“, schnellerer Start) */
		public readonly bool $moovAtStart,
		/** Kennung des Videocodecs aus stsd, etwa „avc1“ oder „apcn“ (ProRes) */
		public readonly ?string $codec = null,
	) {
	}
}
