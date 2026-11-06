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
 * @template-extends QBMapper<ReviewVersion>
 */
class ReviewVersionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'clipproof_versions', ReviewVersion::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function find(int $id): ReviewVersion {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<ReviewVersion> nach Nummer aufsteigend
	 */
	public function findForReview(int $reviewId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
			->orderBy('number', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForReview(int $reviewId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('review_id', $qb->createNamedParameter($reviewId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
