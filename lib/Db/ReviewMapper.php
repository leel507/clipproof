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
 * @template-extends QBMapper<Review>
 */
class ReviewMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'clipproof_reviews', Review::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function find(int $id): Review {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByTokenHash(string $tokenHash): Review {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findForOwner(int $id, string $ownerUid): Review {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
		return $this->findEntity($qb);
	}

	/** @return list<Review> */
	public function findForOrdner(int $ordnerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ordner_id', $qb->createNamedParameter($ordnerId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Freigegeben vor dem Stichtag und noch nicht archiviert.
	 *
	 * @return list<Review>
	 */
	public function findZuArchivieren(int $stichtag): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('freigabe_am'))
			->andWhere($qb->expr()->lte('freigabe_am', $qb->createNamedParameter($stichtag, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('archiviert_am'));
		return $this->findEntities($qb);
	}

	/**
	 * Archivierte Reviews, deren Aufbewahrung abgelaufen ist.
	 *
	 * @return list<Review>
	 */
	public function findAufbewahrungAbgelaufen(int $jetzt): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('aufbewahren_bis'))
			->andWhere($qb->expr()->lte('aufbewahren_bis', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Sortierung nach Zeit, nicht nach Text (Kollation unter musl ist
	 * nicht verlässlich).
	 *
	 * @return list<Review>
	 */
	public function findAllForOwner(string $ownerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->orderBy('updated_at', 'DESC');
		return $this->findEntities($qb);
	}
}
