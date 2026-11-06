<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein Review je Video.
 *
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method string|null getTokenEnc()
 * @method void setTokenEnc(?string $tokenEnc)
 * @method int|null getTokenExpiresAt()
 * @method void setTokenExpiresAt(?int $tokenExpiresAt)
 * @method int|null getTokenRevokedAt()
 * @method void setTokenRevokedAt(?int $tokenRevokedAt)
 * @method bool|null getCommentsLocked()
 * @method void setCommentsLocked(?bool $commentsLocked)
 * @method string getAnrede()
 * @method void setAnrede(string $anrede)
 * @method int|null getFreigabeAm()
 * @method void setFreigabeAm(?int $freigabeAm)
 * @method int|null getArchiviertAm()
 * @method void setArchiviertAm(?int $archiviertAm)
 * @method string|null getArchivAnlass()
 * @method void setArchivAnlass(?string $archivAnlass)
 * @method int|null getAufbewahrenBis()
 * @method void setAufbewahrenBis(?int $aufbewahrenBis)
 * @method int|null getOrdnerId()
 * @method void setOrdnerId(?int $ordnerId)
 * @method string|null getGruppe()
 * @method void setGruppe(?string $gruppe)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Review extends Entity {
	public const ANREDE_DU = 'du';
	public const ANREDE_SIE = 'sie';

	protected ?string $ownerUid = null;
	protected ?string $title = null;
	protected ?string $tokenHash = null;
	protected ?string $tokenEnc = null;
	protected ?int $tokenExpiresAt = null;
	protected ?int $tokenRevokedAt = null;
	protected ?bool $commentsLocked = null;
	protected ?string $anrede = null;
	protected ?int $freigabeAm = null;
	protected ?int $archiviertAm = null;
	protected ?string $archivAnlass = null;
	protected ?int $aufbewahrenBis = null;
	protected ?int $ordnerId = null;
	protected ?string $gruppe = null;
	protected ?int $createdAt = null;
	protected ?int $updatedAt = null;

	public function __construct() {
		$this->addType('ownerUid', 'string');
		$this->addType('title', 'string');
		$this->addType('tokenHash', 'string');
		$this->addType('tokenEnc', 'string');
		$this->addType('tokenExpiresAt', 'integer');
		$this->addType('tokenRevokedAt', 'integer');
		$this->addType('commentsLocked', 'boolean');
		$this->addType('anrede', 'string');
		$this->addType('freigabeAm', 'integer');
		$this->addType('archiviertAm', 'integer');
		$this->addType('archivAnlass', 'string');
		$this->addType('aufbewahrenBis', 'integer');
		$this->addType('ordnerId', 'integer');
		$this->addType('gruppe', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** Null aus der Datenbank gilt als „nicht gesperrt“. */
	public function isCommentsLocked(): bool {
		return $this->commentsLocked === true;
	}

	/**
	 * Archiviert: Link aus, nichts mehr änderbar, nur noch für den Besitzer lesbar
	 * und exportierbar, bis die Aufbewahrung endet.
	 */
	public function isArchiviert(): bool {
		return $this->archiviertAm !== null;
	}
}
