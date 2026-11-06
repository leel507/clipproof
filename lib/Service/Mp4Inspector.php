<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Exception\Mp4Exception;

/**
 * Liest Bildrate, Bildanzahl, Größe und Start-Timecode aus dem Kopf einer
 * MP4- oder MOV-Datei. Kein ffmpeg: Gelesen werden nur die Box-Köpfe und
 * die Box „moov“ (wenige hundert KB), dazu bei einer Timecode-Spur
 * vier Bytes aus „mdat“.
 *
 * Bildrate: Zeitbasis der Videospur (mdhd) geteilt durch die Bilddauer
 * (stts). Haben alle Bilder dieselbe Dauer, ist die Bildrate konstant.
 * Das letzte Bild darf abweichen, das schreiben viele Encoder so.
 */
class Mp4Inspector {
	/** Obergrenze für moov, schützt den Speicher (etwa bei memory_limit 512 MB). */
	private const MAX_MOOV = 64 * 1024 * 1024;
	private const CONTAINER = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'dinf'];

	/**
	 * @param resource $stream lesbar und mit fseek durchsuchbar
	 * @throws Mp4Exception
	 */
	public function inspect($stream): Mp4Info {
		[$moov, $moovAtStart] = $this->readMoov($stream);

		$video = null;
		$timecode = null;
		foreach ($this->children($moov, 0, strlen($moov)) as [$type, $start, $end]) {
			if ($type !== 'trak') {
				continue;
			}
			$trak = $this->parseTrak($moov, $start, $end);
			if ($trak['handler'] === 'vide' && $video === null) {
				$video = $trak;
			} elseif ($trak['handler'] === 'tmcd' && $timecode === null) {
				$timecode = $trak;
			}
		}
		if ($video === null) {
			throw new Mp4Exception('No video track found');
		}
		if ($video['timescale'] <= 0 || $video['stts'] === []) {
			throw new Mp4Exception('Video track without time base or frame durations');
		}

		$frameCount = 0;
		$durations = [];
		foreach ($video['stts'] as [$count, $delta]) {
			$frameCount += $count;
			$durations[$delta] = ($durations[$delta] ?? 0) + $count;
		}
		$first = $video['stts'][0][1];
		$constant = count($durations) === 1;
		// Nur das letzte Bild weicht ab: gilt weiter als konstant.
		$entries = count($video['stts']);
		if (!$constant && $entries === 2 && $video['stts'][1][0] === 1) {
			$constant = true;
		}
		if ($first <= 0) {
			throw new Mp4Exception('Frame duration zero');
		}
		$gcd = $this->gcd($video['timescale'], $first);

		$startFrame = null;
		$dropFrame = null;
		if ($timecode !== null && $timecode['tmcd'] !== null && $timecode['chunkOffset'] !== null) {
			$dropFrame = $timecode['tmcd']['drop'];
			$startFrame = $this->readInt32At($stream, $timecode['chunkOffset']);
		}

		return new Mp4Info(
			fpsNum: intdiv($video['timescale'], $gcd),
			fpsDen: intdiv($first, $gcd),
			constantFrameRate: $constant,
			distinctDurations: count($durations),
			frameCount: $video['sampleCount'] ?? $frameCount,
			width: $video['width'],
			height: $video['height'],
			startFrame: $startFrame,
			dropFrame: $dropFrame,
			moovAtStart: $moovAtStart,
			codec: $video['codec'],
		);
	}

	/**
	 * Sucht moov auf der obersten Ebene, ohne mdat zu lesen.
	 *
	 * @param resource $stream
	 * @return array{0: string, 1: bool}
	 * @throws Mp4Exception
	 */
	private function readMoov($stream): array {
		$stat = fstat($stream);
		$size = is_array($stat) ? (int)$stat['size'] : 0;
		$pos = 0;
		$mdatSeen = false;
		$moov = null;
		while ($size === 0 || $pos + 8 <= $size) {
			if (fseek($stream, $pos) !== 0) {
				break;
			}
			$header = $this->readExact($stream, 8);
			if ($header === null) {
				break;
			}
			$boxSize = $this->u32($header, 0);
			$type = substr($header, 4, 4);
			$headerLen = 8;
			if ($boxSize === 1) {
				$large = $this->readExact($stream, 8);
				if ($large === null) {
					break;
				}
				$boxSize = $this->u64($large, 0);
				$headerLen = 16;
			} elseif ($boxSize === 0) {
				if ($size === 0) {
					throw new Mp4Exception('Box extends to end of file without known file size');
				}
				$boxSize = $size - $pos;
			}
			if ($boxSize < $headerLen) {
				throw new Mp4Exception('Invalid box size at byte ' . $pos);
			}
			// Abgeschnittene Datei (Upload oder Kopie unvollständig): Eine
			// Box reicht über das Dateiende. Ohne diese Prüfung ginge eine
			// halbe Datei mit moov am Anfang als Video durch.
			if ($size > 0 && $pos + $boxSize > $size) {
				throw new Mp4Exception('File incomplete: ends at byte ' . $size . ', expected ' . ($pos + $boxSize));
			}
			if ($moov !== null) {
				$pos += $boxSize;
				continue;
			}
			if ($type === 'moov') {
				$payload = $boxSize - $headerLen;
				if ($payload > self::MAX_MOOV) {
					throw new Mp4Exception('moov too large');
				}
				$data = $this->readExact($stream, $payload);
				if ($data === null) {
					throw new Mp4Exception('moov incomplete');
				}
				$moov = [$data, !$mdatSeen];
				$pos += $boxSize;
				continue;
			}
			if ($type === 'mdat') {
				$mdatSeen = true;
			}
			$pos += $boxSize;
		}
		if ($moov !== null) {
			return $moov;
		}
		throw new Mp4Exception('No moov box found');
	}

	/**
	 * @return array{handler: ?string, timescale: int, stts: list<array{0: int, 1: int}>, sampleCount: ?int, width: ?int, height: ?int, tmcd: ?array{drop: bool}, chunkOffset: ?int}
	 */
	private function parseTrak(string $d, int $start, int $end): array {
		$trak = [
			'handler' => null, 'timescale' => 0, 'stts' => [], 'sampleCount' => null,
			'width' => null, 'height' => null, 'tmcd' => null, 'chunkOffset' => null, 'codec' => null,
		];
		$this->walk($d, $start, $end, $trak, 'trak');
		return $trak;
	}

	/**
	 * Durchläuft die Boxen einer Spur rekursiv und sammelt, was gebraucht
	 * wird. Unbekannte Boxen werden übersprungen.
	 */
	private function walk(string $d, int $start, int $end, array &$trak, string $parent, int $tiefe = 0): void {
		// Echte Dateien schachteln wenige Ebenen; eine präparierte Datei mit
		// Tausenden würde den Prozess sprengen (Sicherheitsprüfung 15.10.2026).
		if ($tiefe > 12) {
			throw new Mp4Exception('Boxes nested too deeply');
		}
		foreach ($this->children($d, $start, $end) as [$type, $s, $e]) {
			if (in_array($type, self::CONTAINER, true)) {
				$this->walk($d, $s, $e, $trak, $type, $tiefe + 1);
				continue;
			}
			switch ($type) {
				case 'tkhd':
					// Breite und Höhe stehen als 16.16-Festkomma am Ende.
					if ($e - $s >= 8) {
						$trak['width'] = $this->u32($d, $e - 8) >> 16;
						$trak['height'] = $this->u32($d, $e - 4) >> 16;
					}
					break;
				case 'mdhd':
					$version = ord($d[$s]);
					$trak['timescale'] = $version === 1 ? $this->u32($d, $s + 20) : $this->u32($d, $s + 12);
					break;
				case 'hdlr':
					// Nur der Handler unter mdia nennt den Spurtyp. QuickTime
					// schreibt unter minf einen zweiten (Daten-Handler), der
					// den Typ sonst überschreiben würde.
					if ($parent === 'mdia') {
						$trak['handler'] = substr($d, $s + 8, 4);
					}
					break;
				case 'stts':
					$n = $this->u32($d, $s + 4);
					$list = [];
					for ($i = 0; $i < $n && $s + 16 + $i * 8 <= $e; $i++) {
						$list[] = [$this->u32($d, $s + 8 + $i * 8), $this->u32($d, $s + 12 + $i * 8)];
					}
					$trak['stts'] = $list;
					break;
				case 'stsz':
					$trak['sampleCount'] = $this->u32($d, $s + 8);
					break;
				case 'stsd':
					// Erster Eintrag; bei einer Timecode-Spur ist das „tmcd“.
					$trak['codec'] = substr($d, $s + 12, 4);
					if ($trak['codec'] === 'tmcd') {
						// Kopf 8, reserviert 6, Datenreferenz 2, reserviert 4, dann Flags.
						$flags = $this->u32($d, $s + 8 + 8 + 6 + 2 + 4);
						$trak['tmcd'] = ['drop' => ($flags & 0x0001) === 0x0001];
					}
					break;
				case 'stco':
					if ($this->u32($d, $s + 4) > 0) {
						$trak['chunkOffset'] = $this->u32($d, $s + 8);
					}
					break;
				case 'co64':
					if ($this->u32($d, $s + 4) > 0) {
						$trak['chunkOffset'] = $this->u64($d, $s + 8);
					}
					break;
			}
		}
	}

	/**
	 * Kind-Boxen zwischen $start und $end.
	 *
	 * @return list<array{0: string, 1: int, 2: int}> Typ, Beginn und Ende der Nutzdaten
	 */
	private function children(string $d, int $start, int $end): array {
		$out = [];
		$pos = $start;
		while ($pos + 8 <= $end) {
			$size = $this->u32($d, $pos);
			$type = substr($d, $pos + 4, 4);
			$headerLen = 8;
			if ($size === 1) {
				if ($pos + 16 > $end) {
					break;
				}
				$size = $this->u64($d, $pos + 8);
				$headerLen = 16;
			} elseif ($size === 0) {
				$size = $end - $pos;
			}
			if ($size < $headerLen || $pos + $size > $end) {
				break;
			}
			$out[] = [$type, $pos + $headerLen, $pos + $size];
			$pos += $size;
		}
		return $out;
	}

	/** @param resource $stream */
	private function readInt32At($stream, int $offset): ?int {
		if (fseek($stream, $offset) !== 0) {
			return null;
		}
		$b = $this->readExact($stream, 4);
		if ($b === null) {
			return null;
		}
		$v = $this->u32($b, 0);
		// Vorzeichenbehaftet; negative Startwerte gibt es praktisch nicht.
		return $v >= 0x80000000 ? $v - 0x100000000 : $v;
	}

	/** @param resource $stream */
	private function readExact($stream, int $length): ?string {
		if ($length === 0) {
			return '';
		}
		$buf = '';
		while (strlen($buf) < $length) {
			$chunk = fread($stream, $length - strlen($buf));
			if ($chunk === false || $chunk === '') {
				return null;
			}
			$buf .= $chunk;
		}
		return $buf;
	}

	private function u32(string $d, int $offset): int {
		/** @var array{1: int} $v */
		$v = unpack('N', $d, $offset);
		return $v[1];
	}

	private function u64(string $d, int $offset): int {
		/** @var array{1: int} $v */
		$v = unpack('J', $d, $offset);
		return $v[1];
	}

	private function gcd(int $a, int $b): int {
		while ($b !== 0) {
			[$a, $b] = [$b, $a % $b];
		}
		return abs($a);
	}
}
