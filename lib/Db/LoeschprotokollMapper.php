<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Beleg für Löschungen (Art. 15 und 17): dass und wann, nicht was.
 * Zu einem Token lässt sich die Löschung nachweisen, weil der Hash
 * gespeichert ist; das Token selbst und die Inhalte nicht.
 */
class LoeschprotokollMapper {
	public const ANLASS_BESITZER = 'besitzer';
	public const ANLASS_FRIST = 'frist';
	/** Archivierung: nur Entwürfe und Gäste ohne Abgabe gelöscht. */
	public const ANLASS_ARCHIV_FRIST = 'archiv_frist';
	/** Ende der Aufbewahrung: der Rest des Reviews gelöscht. */
	public const ANLASS_AUFBEWAHRUNG = 'aufbewahrung_ende';
	/** Einzelne Abgaben oder Antworten nach ihrem eigenen Jahr gelöscht (Entscheidung 13). */
	public const ANLASS_AUFBEWAHRUNG_TEIL = 'aufbewahrung_teil';
	private const TABELLE = 'clipproof_loeschungen';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function eintragen(int $reviewId, string $tokenHash, string $anlass, int $kommentare, int $gaeste, int $abgaben, int $jetzt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABELLE)
			->values([
				'review_id' => $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT),
				'token_hash' => $qb->createNamedParameter($tokenHash),
				'anlass' => $qb->createNamedParameter($anlass),
				'kommentare' => $qb->createNamedParameter($kommentare, IQueryBuilder::PARAM_INT),
				'gaeste' => $qb->createNamedParameter($gaeste, IQueryBuilder::PARAM_INT),
				'abgaben' => $qb->createNamedParameter($abgaben, IQueryBuilder::PARAM_INT),
				'geloescht_am' => $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
	}

	/** Einträge vor diesem Zeitpunkt löschen (Aufbewahrung des Protokolls). */
	public function aufraeumen(int $vor): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABELLE)
			->where($qb->expr()->lt('geloescht_am', $qb->createNamedParameter($vor, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Nachweis zu einem Token.
	 *
	 * @return list<array{anlass: string, kommentare: int, gaeste: int, abgaben: int, geloescht_am: int}>
	 */
	public function zuToken(string $token): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('anlass', 'kommentare', 'gaeste', 'abgaben', 'geloescht_am')
			->from(self::TABELLE)
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter(hash('sha256', $token))));
		$out = [];
		$r = $qb->executeQuery();
		while ($z = $r->fetch()) {
			$out[] = ['anlass' => (string)$z['anlass'], 'kommentare' => (int)$z['kommentare'], 'gaeste' => (int)$z['gaeste'], 'abgaben' => (int)$z['abgaben'], 'geloescht_am' => (int)$z['geloescht_am']];
		}
		$r->closeCursor();
		return $out;
	}
}
