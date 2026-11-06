<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein beobachteter Projektordner. Vorgabewerte null (E3).
 *
 * @method string|null getKontoUid()
 * @method void setKontoUid(?string $kontoUid)
 * @method string|null getOwnerUid()
 * @method void setOwnerUid(?string $ownerUid)
 * @method int|null getFolderId()
 * @method void setFolderId(?int $folderId)
 * @method string|null getPfad()
 * @method void setPfad(?string $pfad)
 * @method string|null getTitel()
 * @method void setTitel(?string $titel)
 * @method bool|null getAktiv()
 * @method void setAktiv(?bool $aktiv)
 * @method string|null getFehler()
 * @method void setFehler(?string $fehler)
 * @method int|null getFehlerDatei()
 * @method void setFehlerDatei(?int $fehlerDatei)
 * @method int|null getGeprueftAm()
 * @method void setGeprueftAm(?int $geprueftAm)
 * @method int|null getCreatedAt()
 * @method void setCreatedAt(?int $createdAt)
 * @method int|null getUpdatedAt()
 * @method void setUpdatedAt(?int $updatedAt)
 */
class Ordner extends Entity {
	protected ?string $kontoUid = null;
	protected ?string $ownerUid = null;
	protected ?int $folderId = null;
	protected ?string $pfad = null;
	protected ?string $titel = null;
	protected ?bool $aktiv = null;
	protected ?string $fehler = null;
	protected ?int $fehlerDatei = null;
	protected ?int $geprueftAm = null;
	protected ?int $createdAt = null;
	protected ?int $updatedAt = null;

	public function __construct() {
		$this->addType('kontoUid', 'string');
		$this->addType('ownerUid', 'string');
		$this->addType('folderId', 'integer');
		$this->addType('pfad', 'string');
		$this->addType('titel', 'string');
		$this->addType('aktiv', 'boolean');
		$this->addType('fehler', 'string');
		$this->addType('fehlerDatei', 'integer');
		$this->addType('geprueftAm', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function isAktiv(): bool {
		return $this->aktiv !== false;
	}
}
