<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Probe für Sprache und Anrede in einem lokalen Nextcloud-Container:
//   docker exec -u www-data <nextcloud-container> php /tmp/probe-sprache.php <review-id>
// Holt die Kundenseite mit deutschem Browser in du- und Sie-Fassung und prüft, welche Übersetzung
// geladen wird und was die Seite über den Besitzer mitbekommt. Gibt nie ein Token aus.

require_once '/var/www/html/lib/base.php';

use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Service\TokenService;

$id = (int)($argv[1] ?? 0);
$reviews = \OCP\Server::get(ReviewMapper::class);
$tokens = \OCP\Server::get(TokenService::class);
$review = $reviews->find($id);
$token = $tokens->reveal($review);
$basis = 'http://localhost/index.php/apps/clipproof';

function holen(string $url, string $sprache): array {
	$ctx = stream_context_create(['http' => ['header' => "Accept-Language: $sprache\r\n", 'ignore_errors' => true]]);
	$text = (string)file_get_contents($url, false, $ctx);
	$status = 0;
	foreach ($http_response_header ?? [] as $kopf) {
		if (preg_match('#^HTTP/\S+ (\d+)#', $kopf, $m)) {
			$status = (int)$m[1];
		}
	}
	return [$status, $text];
}

$ergebnis = [];
foreach (['du', 'sie'] as $anrede) {
	$review->setAnrede($anrede);
	$reviews->update($review);
	foreach (['de-DE,de;q=0.9', 'en-US,en;q=0.9'] as $sprache) {
		[$status, $html] = holen("$basis/r/$token", $sprache);
		preg_match('#id="initial-state-clipproof-kunde" value="([^"]+)"#', $html, $m);
		$start = isset($m[1]) ? json_decode(base64_decode($m[1]), true) : null;
		$ergebnis["$anrede, $sprache"] = [
			'status' => $status,
			'uebersetzung' => preg_match_all('#clipproof/l10n/(de(?:_DE)?)\.js#', $html, $l) ? $l[1] : [],
			'info' => $start['info'] ?? null,
		];
	}
}
[$status, $json] = holen("$basis/r/ungueltig-ungueltig-ungueltig/daten", 'de-DE,de;q=0.9');
$ergebnis['ungueltiger Link'] = ['status' => $status, 'antwort' => json_decode($json, true)];
echo json_encode($ergebnis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
