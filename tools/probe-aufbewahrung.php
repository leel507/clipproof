<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Probe für Archiv und Aufbewahrung in einem lokalen Nextcloud-Container:
//   docker exec -u www-data <nextcloud-container> php /tmp/probe-aufbewahrung.php <review-id>
// Setzt die Freigabe des Reviews 31 Tage zurück, lässt die Aufbewahrung
// einmal laufen und zeigt den Zustand. Nur über die Datenbankschicht.

require_once '/var/www/html/lib/base.php';

use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Service\AufbewahrungService;
use OCA\Clipproof\Service\TokenService;

$id = (int)($argv[1] ?? 0);
$reviews = \OCP\Server::get(ReviewMapper::class);
$review = $reviews->find($id);
$frueher = time() - 31 * 24 * 60 * 60;
\OCP\Server::get(TokenService::class)->applyFreigabe($review, $frueher);
[$frist, $ende] = \OCP\Server::get(AufbewahrungService::class)->anwenden();
$review = $reviews->find($id);
echo json_encode([
	'archiviert_oder_geloescht' => $frist,
	'archiviert' => $review->isArchiviert(),
	'anlass' => $review->getArchivAnlass(),
	'aufbewahrenBis' => $review->getAufbewahrenBis(),
	'kommentareGesperrt' => $review->isCommentsLocked(),
], JSON_UNESCAPED_UNICODE), "\n";
