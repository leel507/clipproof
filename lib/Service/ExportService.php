<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewVersion;

/**
 * Export der Anmerkungen als Marker (E7).
 *
 * - Resolve: EDL mit Markerzeilen, Import über „Timeline Markers from EDL“.
 *   Geht auch in der freien Fassung.
 * - Premiere: FCP7-XML. Der Import erzeugt eine neue Sequenz mit Markern;
 *   Marker in eine bestehende Sequenz zu übernehmen geht damit nicht
 *   zuverlässig (ehrlich benannt, docs/architektur.md, 12).
 * - CSV für Menschen.
 *
 * Positionen: Timecode = Start-Timecode der Version plus Frame-Index.
 * Drop-Frame wird durchgehalten (FCM-Zeile, Trenner, ntsc und
 * displayformat).
 *
 * EDL ist das schwächste Format: Ob ein Programm Umlaute aus der EDL
 * richtig liest, ist nicht zugesichert. Markernamen in der EDL sind daher
 * ASCII mit deutscher Umschrift (ä zu ae, ß zu ss); andere Zeichen werden
 * sichtbar zu „?“, nie stillschweigend weggelassen. Jeder Marker trägt
 * eine Nummer (#1, #2 …); unter derselben Nummer steht der volle Text in
 * der CSV als Begleitdatei. Stimmt der Start-Timecode nicht mit dem Timeline-Start
 * überein, sitzen alle Marker verschoben.
 */
class ExportService {
	public const RESOLVE_KUNDE = 'ResolveColorBlue';
	public const RESOLVE_ERLEDIGT = 'ResolveColorGreen';
	private const NAME_MAX = 120;

	/**
	 * Hauptanmerkungen eines Kunden mit ihren Antworten, abgesendet,
	 * nach Frame sortiert. Entwürfe kommen nie in einen Export.
	 *
	 * @param list<Comment> $kommentare Kommentare der Version (sichtbar für den Besitzer)
	 * @param array<int, string> $autoren Gast-Id zu Name
	 * @return list<array{frameIn: int, frameOut: ?int, autor: string, text: string, erledigt: bool, antworten: list<string>}>
	 */
	public static function marker(array $kommentare, array $autoren, string $besitzerName = 'Owner'): array {
		$antworten = [];
		foreach ($kommentare as $k) {
			if ($k->getParentId() !== null && self::abgesendet($k)) {
				$antworten[(int)$k->getParentId()][] = ($k->getAuthorType() === Comment::BESITZER ? $besitzerName : ($autoren[(int)$k->getGuestId()] ?? 'Client')) . ': ' . (string)$k->getText();
			}
		}
		$out = [];
		foreach ($kommentare as $k) {
			if ($k->getParentId() !== null || $k->getAuthorType() !== Comment::KUNDE || !self::abgesendet($k)) {
				continue;
			}
			$out[] = [
				'frameIn' => (int)$k->getFrameIn(),
				'frameOut' => $k->getFrameOut(),
				'autor' => $autoren[(int)$k->getGuestId()] ?? 'Client',
				'text' => trim((string)$k->getText()) !== '' ? (string)$k->getText() : '(Zeichnung)',
				'erledigt' => $k->getDone() === true,
				'antworten' => $antworten[(int)$k->getId()] ?? [],
			];
		}
		usort($out, fn ($a, $b) => $a['frameIn'] <=> $b['frameIn']);
		return $out;
	}

	/**
	 * Resolve-EDL mit einem Ereignis je Marker.
	 *
	 * @param list<array{frameIn: int, frameOut: ?int, autor: string, text: string, erledigt: bool, antworten: list<string>}> $marker
	 */
	public static function edl(Review $review, ReviewVersion $version, array $marker): string {
		[$num, $den, $df, $start] = self::rate($version);
		$zeilen = [
			'TITLE: ' . self::ascii(self::einzeilig($review->getTitle() . ' Version ' . $version->getNumber(), 70)),
			'FCM: ' . ($df ? 'DROP FRAME' : 'NON-DROP FRAME'),
			'',
		];
		foreach ($marker as $i => $m) {
			$dauer = $m['frameOut'] !== null ? $m['frameOut'] - $m['frameIn'] + 1 : 1;
			$in = Timecode::vonFrames($start + $m['frameIn'], $num, $den, $df);
			$aus = Timecode::vonFrames($start + $m['frameIn'] + 1, $num, $den, $df);
			$zeilen[] = sprintf('%03d  001      V     C        %s %s %s %s  ', $i + 1, $in, $aus, $in, $aus);
			$zeilen[] = sprintf(' |C:%s |M:%s |D:%d',
				$m['erledigt'] ? self::RESOLVE_ERLEDIGT : self::RESOLVE_KUNDE,
				self::ascii('#' . ($i + 1) . ' ' . self::markerName($m)),
				$dauer,
			);
			$zeilen[] = '';
		}
		// CMX3600 erwartet Zeilenenden im Windows-Stil.
		return implode("\r\n", $zeilen) . "\r\n";
	}

	/**
	 * FCP7-XML (xmeml 5) mit einer Sequenz und Markern, für Premiere.
	 *
	 * @param list<array{frameIn: int, frameOut: ?int, autor: string, text: string, erledigt: bool, antworten: list<string>}> $marker
	 */
	public static function fcp7(Review $review, ReviewVersion $version, array $marker): string {
		[$num, $den, $df, $start] = self::rate($version);
		$timebase = Timecode::nominal($num, $den);
		$ntsc = $den === 1001 ? 'TRUE' : 'FALSE';
		$rate = "<rate><timebase>$timebase</timebase><ntsc>$ntsc</ntsc></rate>";
		$x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$name = $x($review->getTitle() . ' Version ' . $version->getNumber() . ' Anmerkungen');
		$b = (int)$version->getWidth() ?: 1920;
		$h = (int)$version->getHeight() ?: 1080;

		$xml = [];
		$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml[] = '<!DOCTYPE xmeml>';
		$xml[] = '<xmeml version="5">';
		$xml[] = '	<sequence id="clipproof-' . (int)$review->getId() . '-' . (int)$version->getNumber() . '">';
		$xml[] = "		<name>$name</name>";
		$xml[] = '		<duration>' . (int)$version->getDurationFrames() . '</duration>';
		$xml[] = "		$rate";
		$xml[] = "		<timecode>$rate<string>" . Timecode::vonFrames($start, $num, $den, $df) . "</string><frame>$start</frame><displayformat>" . ($df ? 'DF' : 'NDF') . '</displayformat></timecode>';
		$xml[] = "		<media><video><format><samplecharacteristics>$rate<width>$b</width><height>$h</height></samplecharacteristics></format><track></track></video></media>";
		foreach ($marker as $m) {
			$xml[] = '		<marker>';
			$xml[] = '			<name>' . $x(self::markerName($m)) . '</name>';
			$xml[] = '			<comment>' . $x(self::langtext($m)) . '</comment>';
			$xml[] = '			<in>' . $m['frameIn'] . '</in>';
			$xml[] = '			<out>' . ($m['frameOut'] !== null ? $m['frameOut'] + 1 : -1) . '</out>';
			$xml[] = '		</marker>';
		}
		$xml[] = '	</sequence>';
		$xml[] = '</xmeml>';
		return implode("\n", $xml) . "\n";
	}

	/**
	 * CSV mit Semikolon und BOM, damit Excel Umlaute richtig zeigt.
	 *
	 * @param list<array{frameIn: int, frameOut: ?int, autor: string, text: string, erledigt: bool, antworten: list<string>}> $marker
	 */
	public static function csv(ReviewVersion $version, array $marker): string {
		[$num, $den, $df, $start] = self::rate($version);
		$f = fopen('php://memory', 'w+');
		fwrite($f, "\xEF\xBB\xBF");
		fputcsv($f, ['Nr', 'Timecode', 'Frame', 'Bis Frame', 'Autor', 'Erledigt', 'Anmerkung', 'Antworten'], ';', '"', '');
		foreach ($marker as $i => $m) {
			fputcsv($f, [
				'#' . ($i + 1),
				Timecode::vonFrames($start + $m['frameIn'], $num, $den, $df),
				$m['frameIn'],
				$m['frameOut'] ?? '',
				self::keineFormel($m['autor']),
				$m['erledigt'] ? 'ja' : 'nein',
				self::keineFormel($m['text']),
				self::keineFormel(implode(' | ', $m['antworten'])),
			], ';', '"', '');
		}
		rewind($f);
		$csv = (string)stream_get_contents($f);
		fclose($f);
		return $csv;
	}

	/**
	 * Text von Kunden darf in Excel oder Numbers nie als Formel laufen
	 * (CSV-Injektion, Sicherheitsprüfung 15.10.2026): beginnt er mit
	 * = + - @ Tab oder Wagenrücklauf, kommt ein Apostroph davor.
	 */
	public static function keineFormel(string $s): string {
		return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
	}

	/** @return array{0: int, 1: int, 2: bool, 3: int} */
	private static function rate(ReviewVersion $version): array {
		return [(int)$version->getFpsNum(), (int)$version->getFpsDen(), $version->isDropFrame(), (int)$version->getStartTcFrames()];
	}

	/** @param array{autor: string, text: string} $m */
	private static function markerName(array $m): string {
		return self::einzeilig($m['autor'] . ': ' . $m['text'], self::NAME_MAX);
	}

	/** @param array{autor: string, text: string, antworten: list<string>} $m */
	private static function langtext(array $m): string {
		return implode("\n", array_merge([$m['autor'] . ': ' . $m['text']], $m['antworten']));
	}

	/** Deutsche Umschrift nach ASCII; Unbekanntes wird sichtbar zu „?“. */
	public static function ascii(string $s): string {
		$s = strtr($s, [
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
			'„' => '"', '“' => '"', '”' => '"', '‚' => "'", '‘' => "'", '’' => "'",
			'…' => '...', '–' => '-', '—' => '-', '×' => 'x', 'é' => 'e', 'è' => 'e', 'à' => 'a',
		]);
		return (string)preg_replace('/[^\x20-\x7E]/u', '?', $s);
	}

	/** Eine Zeile ohne Steuerzeichen und ohne „|“, das die EDL-Felder trennt. */
	private static function einzeilig(string $s, int $max): string {
		$s = trim((string)preg_replace('/[\x00-\x1F\x7F|]+/u', ' ', $s));
		$s = (string)preg_replace('/\s+/u', ' ', $s);
		return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
	}

	private static function abgesendet(Comment $k): bool {
		return $k->getAuthorType() === Comment::BESITZER || $k->getSubmissionId() !== null;
	}
}
