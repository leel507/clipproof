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
 * @template-extends QBMapper<Ordner>
 */
class OrdnerMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'clipproof_ordner', Ordner::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function find(int $id): Ordner {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findByFolder(int $folderId): ?Ordner {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		return $this->findEntities($qb)[0] ?? null;
	}

	/** @return list<Ordner> */
	public function findAktive(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->isNull('aktiv'),
				$qb->expr()->eq('aktiv', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
			))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<Ordner> */
	public function findForOwner(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
			->orderBy('updated_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Ein Review aus diesem Ordner wurde gelöscht: Ordner nicht mehr
	 * beobachten, sonst entstünde aus dem Video sofort ein neues Review.
	 * Der Nutzer kann ihn wieder einschalten.
	 */
	public function abmelden(int $ordnerId, int $jetzt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('aktiv', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ordnerId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
