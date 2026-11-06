<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Guest>
 */
class GuestMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'clipproof_guests', Guest::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findBySecretHash(int $reviewId, string $secretHash): Guest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_hash', $qb->createNamedParameter($secretHash)))
			->andWhere($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, Guest> nach Id
	 */
	public function findByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($this->findEntities($qb) as $g) {
			$out[$g->getId()] = $g;
		}
		return $out;
	}

	public function countForReview(int $reviewId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from($this->getTableName())
			->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery();
		$n = (int)$r->fetchOne();
		$r->closeCursor();
		return $n;
	}

	/**
	 * Löscht die Gäste eines Reviews außer den angegebenen (Archivierung:
	 * Wer nie etwas abgesendet hat, wird nicht aufbewahrt). Gibt die Zahl
	 * der gelöschten Zeilen zurück.
	 *
	 * @param list<int> $behalten
	 */
	public function deleteAusser(int $reviewId, array $behalten): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)));
		if ($behalten !== []) {
			$qb->andWhere($qb->expr()->notIn('id', $qb->createNamedParameter($behalten, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $qb->executeStatement();
	}

	public function deleteForReview(int $reviewId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
