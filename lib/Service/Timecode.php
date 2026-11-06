<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use InvalidArgumentException;

/**
 * Frame ↔ Timecode, gleich umgesetzt in src/lib/timecode.ts und gegen
 * tests/fixtures/timecode.json geprüft.
 *
 * Gerechnet wird mit der absoluten Framezahl (Start-Timecode plus
 * Index). Der Timecode zählt mit der Nennbildrate (24 bei 23,976, 30 bei
 * 29,97). Drop-Frame überspringt Zählnummern, keine Bilder: bei 29,97
 * zwei, bei 59,94 vier je Minute, außer in jeder zehnten Minute.
 */
final class Timecode {
	/** Nennbildrate, mit der der Timecode zählt. */
	public static function nominal(int $num, int $den): int {
		return (int)round($num / $den);
	}

	/** Drop-Frame gibt es nur bei 29,97 und 59,94. */
	public static function dropFrameMoeglich(int $num, int $den): bool {
		$nominal = self::nominal($num, $den);
		return $den === 1001 && ($nominal === 30 || $nominal === 60);
	}

	public static function vonFrames(int $frames, int $num, int $den, bool $df): string {
		if ($frames < 0) {
			throw new InvalidArgumentException('Negative frame count');
		}
		$nominal = self::nominal($num, $den);
		if ($df) {
			if (!self::dropFrameMoeglich($num, $den)) {
				throw new InvalidArgumentException('Drop frame only at 29.97 and 59.94');
			}
			$frames = self::dfZuZaehlnummer($frames, $nominal);
		}
		$ff = $frames % $nominal;
		$s = intdiv($frames, $nominal);
		return sprintf('%02d:%02d:%02d%s%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60, $df ? ';' : ':', $ff);
	}

	public static function zuFrames(string $tc, int $num, int $den, bool $df): int {
		if (preg_match('/^(\d{2}):(\d{2}):(\d{2})[:;.](\d{2})$/', $tc, $m) !== 1) {
			throw new InvalidArgumentException('Timecode not readable: ' . $tc);
		}
		[$hh, $mm, $ss, $ff] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
		$nominal = self::nominal($num, $den);
		$frames = (($hh * 3600) + ($mm * 60) + $ss) * $nominal + $ff;
		if ($df) {
			$drop = intdiv($nominal, 15); // 2 bei 30, 4 bei 60
			$minuten = $hh * 60 + $mm;
			$frames -= $drop * ($minuten - intdiv($minuten, 10));
		}
		return $frames;
	}

	/** Echte Bildzahl in die Zählnummer umrechnen, die der DF-Timecode zeigt. */
	private static function dfZuZaehlnummer(int $frames, int $nominal): int {
		$drop = intdiv($nominal, 15);
		$jeMinute = $nominal * 60 - $drop;
		$jeZehnMinuten = $nominal * 600 - $drop * 9;
		$zehner = intdiv($frames, $jeZehnMinuten);
		$rest = $frames % $jeZehnMinuten;
		$zusatz = $drop * 9 * $zehner;
		if ($rest > $drop) {
			$zusatz += $drop * intdiv($rest - $drop, $jeMinute);
		}
		return $frames + $zusatz;
	}
}
