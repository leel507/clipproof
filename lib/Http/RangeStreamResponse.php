<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Liefert eine Datei oder einen Ausschnitt in Blöcken aus. Der
 * Speicherverbrauch bleibt konstant, egal wie groß die Datei ist
 * (auch bei knappem memory_limit, etwa 512 MB).
 *
 * @template-extends Response<int, array<string, mixed>>
 */
class RangeStreamResponse extends Response implements ICallbackResponse {
	private const BLOCK = 256 * 1024;

	/**
	 * @param resource $stream bereits geöffnet, wird hier geschlossen
	 */
	public function __construct(
		private $stream,
		private int $start,
		private int $length,
		int $size,
		string $mime,
		bool $teilweise,
		string $etag,
		int $mtime,
		/** HEAD-Anfrage: nur Kopfzeilen, die Datei wird nicht gelesen. */
		private bool $nurKopf = false,
	) {
		parent::__construct();
		$this->setStatus($teilweise ? Http::STATUS_PARTIAL_CONTENT : Http::STATUS_OK);
		$this->addHeader('Content-Type', $mime);
		$this->addHeader('Content-Length', (string)$length);
		$this->addHeader('Accept-Ranges', 'bytes');
		$this->addHeader('Cache-Control', 'private, max-age=3600');
		$this->addHeader('X-Content-Type-Options', 'nosniff');
		$this->setETag($etag);
		$this->setLastModified((new \DateTime())->setTimestamp($mtime));
		if ($teilweise) {
			$this->addHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $start + $length - 1, $size));
		}
	}

	public function callback(IOutput $output): void {
		if ($this->nurKopf) {
			fclose($this->stream);
			return;
		}
		try {
			if ($this->start > 0 && fseek($this->stream, $this->start) !== 0) {
				// Nicht durchsuchbarer Strom: bis zum Anfang vorlesen.
				$rest = $this->start;
				while ($rest > 0 && !feof($this->stream)) {
					$gelesen = fread($this->stream, min(self::BLOCK, $rest));
					if ($gelesen === false) {
						return;
					}
					$rest -= strlen($gelesen);
				}
			}
			$offen = $this->length;
			while ($offen > 0 && !feof($this->stream)) {
				$block = fread($this->stream, min(self::BLOCK, $offen));
				if ($block === false || $block === '') {
					break;
				}
				$output->setOutput($block);
				$offen -= strlen($block);
				flush();
				// Der Browser springt oft und bricht dabei Anfragen ab.
				if (connection_aborted() === 1) {
					break;
				}
			}
		} finally {
			fclose($this->stream);
		}
	}
}
