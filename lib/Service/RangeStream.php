<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

/**
 * Wertet die Range-Kopfzeile aus. Unterstützt wird eine Spanne je
 * Anfrage, das genügt Browsern beim Abspielen und Springen.
 */
final class RangeStream {
	/** Spanne nicht erfüllbar: Antwort 416. */
	public const UNERFUELLBAR = 'unerfuellbar';

	/**
	 * Höchstens so viele Bytes je Antwort (E4). Jeder Stream belegt einen
	 * PHP-Arbeiter, solange er läuft; sind alle belegt, steht die ganze
	 * Nextcloud. Bei offenen Anfragen (bytes=0-) hört der Browser nach dem
	 * Vorpuffern auf zu lesen, und der Arbeiter wartet blockiert. Mit
	 * begrenzten Antworten fordert der Browser das nächste Stück erst an,
	 * wenn er es braucht, und der Arbeiter ist nur belegt, solange Daten
	 * fließen. Browser erwarten das: 206 darf weniger liefern als erbeten.
	 */
	public const MAX_SPANNE = 4 * 1024 * 1024;

	/**
	 * @return array{0: int, 1: int}|null|string erste und letzte Byteposition
	 *                                           (einschließlich), null für die
	 *                                           ganze Datei, sonst UNERFUELLBAR
	 */
	public static function parse(?string $header, int $size): array|null|string {
		if ($header === null || trim($header) === '') {
			return null;
		}
		if (preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m) !== 1) {
			// Mehrere Spannen oder fremde Einheiten: ganze Datei (RFC 9110
			// erlaubt, die Kopfzeile zu ignorieren).
			return null;
		}
		[$von, $bis] = [$m[1], $m[2]];
		if ($von === '' && $bis === '') {
			return null;
		}
		if ($size <= 0) {
			return self::UNERFUELLBAR;
		}
		if ($von === '') {
			// Letzte n Bytes
			$n = (int)$bis;
			if ($n === 0) {
				return self::UNERFUELLBAR;
			}
			return self::begrenzt(max(0, $size - $n), $size - 1);
		}
		$start = (int)$von;
		if ($start >= $size) {
			return self::UNERFUELLBAR;
		}
		$ende = $bis === '' ? $size - 1 : min((int)$bis, $size - 1);
		if ($ende < $start) {
			return null;
		}
		return self::begrenzt($start, $ende);
	}

	/**
	 * Spanne für eine Anfrage ohne (brauchbare) Range-Kopfzeile. Kleine
	 * Dateien ganz (null, 200). Große nur die ersten MAX_SPANNE Bytes als
	 * 206: Sonst hielte ein Download oder ein Programm ohne Range einen
	 * Arbeiter minutenlang fest (Prüfung vor E9). Browser fragen für
	 * Video ohnehin mit Range und holen den Rest stückweise.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function ohneAnfrage(int $size): ?array {
		return $size > self::MAX_SPANNE ? self::begrenzt(0, $size - 1) : null;
	}

	/** @return array{0: int, 1: int} */
	private static function begrenzt(int $start, int $ende): array {
		return [$start, min($ende, $start + self::MAX_SPANNE - 1)];
	}
}
