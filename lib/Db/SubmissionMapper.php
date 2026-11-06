<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Submission>
 */
class SubmissionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'clipproof_submissions', Submission::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Submission {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<Submission> neueste zuerst */
	public function findForReview(int $reviewId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Abgaben vor einem Zeitpunkt (Aufbewahrung je Abgabe, Entscheidung 13).
	 *
	 * @return list<Submission>
	 */
	public function findVor(int $grenze): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($grenze, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @param list<int> $ids */
	public function deleteIds(array $ids): int {
		if ($ids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	public function deleteForReview(int $reviewId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
