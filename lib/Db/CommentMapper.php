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
 * Sichtbarkeit ist hier fest verdrahtet, damit sie nicht in einem
 * Controller vergessen wird: Entwürfe von Kunden sieht nur ihr
 * Verfasser, der Besitzer nie.
 *
 * @template-extends QBMapper<Comment>
 */
class CommentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'clipproof_comments', Comment::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function find(int $id): Comment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Für den Besitzer: alles außer Entwürfen von Kunden.
	 *
	 * @param list<int> $versionIds
	 * @return list<Comment>
	 */
	public function findSichtbarFuerBesitzer(array $versionIds): array {
		if ($versionIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('version_id', $qb->createNamedParameter($versionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNotNull('submission_id'),
				$qb->expr()->eq('author_type', $qb->createNamedParameter(Comment::BESITZER)),
			))
			->orderBy('frame_in', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Für einen Gast: alles Abgesendete und Kommentare des Besitzers, dazu die
	 * eigenen Entwürfe.
	 *
	 * @param list<int> $versionIds
	 * @return list<Comment>
	 */
	public function findSichtbarFuerGast(array $versionIds, ?int $guestId): array {
		if ($versionIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$sichtbar = $qb->expr()->orX(
			$qb->expr()->isNotNull('submission_id'),
			$qb->expr()->eq('author_type', $qb->createNamedParameter(Comment::BESITZER)),
		);
		if ($guestId !== null) {
			$sichtbar->add($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		}
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('version_id', $qb->createNamedParameter($versionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($sichtbar)
			->orderBy('frame_in', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Entwürfe eines Gastes in den angegebenen Versionen.
	 *
	 * @param list<int> $versionIds
	 * @return list<Comment>
	 */
	public function findEntwuerfe(int $guestId, array $versionIds): array {
		if ($versionIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('version_id', $qb->createNamedParameter($versionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('submission_id'))
			->orderBy('frame_in', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Alle Entwürfe von Kunden. Inhalte werden dort
	 * nicht verwendet, nur Zahl und Zeitpunkt.
	 *
	 * @return list<Comment>
	 */
	public function findAlleEntwuerfe(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('submission_id'))
			->andWhere($qb->expr()->eq('author_type', $qb->createNamedParameter(Comment::KUNDE)));
		return $this->findEntities($qb);
	}

	/**
	 * Ordnet Entwürfe einer Abgabe zu, aber nur die, die noch keiner
	 * Abgabe gehören. Gibt die Zahl der zugeordneten Zeilen zurück. Zwei
	 * gleichzeitige Abgaben desselben Gastes: Die Datenbank sperrt die
	 * Zeilen, die zweite findet keine freien Entwürfe mehr.
	 *
	 * @param list<int> $ids
	 */
	public function abgabeZuordnen(array $ids, int $abgabeId, int $jetzt): int {
		if ($ids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->getTableName())
			->set('submission_id', $qb->createNamedParameter($abgabeId, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($jetzt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('submission_id'))
			->executeStatement();
	}

	/**
	 * Löscht nie abgesendete Entwürfe von Kunden (Archivierung). Gibt die
	 * Zahl der gelöschten Zeilen zurück.
	 *
	 * @param list<int> $versionIds
	 */
	public function deleteEntwuerfe(array $versionIds): int {
		if ($versionIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->in('version_id', $qb->createNamedParameter($versionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('submission_id'))
			->andWhere($qb->expr()->eq('author_type', $qb->createNamedParameter(Comment::KUNDE)))
			->executeStatement();
	}

	/**
	 * Antworten des Besitzers vor einem Zeitpunkt (Aufbewahrung je Schreiben).
	 *
	 * @return list<Comment>
	 */
	public function findBesitzerVor(int $grenze): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('author_type', $qb->createNamedParameter(Comment::BESITZER)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($grenze, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Antworten, deren Anmerkung mit einer Abgabe verfällt, ablösen
	 * (parent_id leeren). Sonst wären sie noch gespeichert, aber in der Besitzeransicht
	 * Ansicht und im Export unsichtbar.
	 *
	 * @param list<int> $abgabeIds
	 */
	public function elternLoesen(array $abgabeIds): int {
		if ($abgabeIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->in('submission_id', $qb->createNamedParameter($abgabeIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$r = $qb->executeQuery();
		$eltern = [];
		while (($id = $r->fetchOne()) !== false) {
			$eltern[] = (int)$id;
		}
		$r->closeCursor();
		if ($eltern === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->getTableName())
			->set('parent_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->in('parent_id', $qb->createNamedParameter($eltern, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('submission_id'),
				$qb->expr()->notIn('submission_id', $qb->createNamedParameter($abgabeIds, IQueryBuilder::PARAM_INT_ARRAY)),
			))
			->executeStatement();
	}

	/**
	 * Anmerkungen der angegebenen Abgaben und die angegebenen Einzelnen
	 * löschen. Gibt die Zahl der gelöschten Zeilen zurück.
	 *
	 * @param list<int> $abgabeIds
	 * @param list<int> $ids
	 */
	public function deleteAbgabenUndIds(array $abgabeIds, array $ids): int {
		$n = 0;
		if ($abgabeIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$n += $qb->delete($this->getTableName())
				->where($qb->expr()->in('submission_id', $qb->createNamedParameter($abgabeIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		if ($ids !== []) {
			$qb = $this->db->getQueryBuilder();
			$n += $qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		return $n;
	}

	/** @param list<int> $versionIds */
	public function deleteForVersions(array $versionIds): void {
		if ($versionIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('version_id', $qb->createNamedParameter($versionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
