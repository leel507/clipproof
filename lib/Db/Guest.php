<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein Kunde ohne Konto, der über das Review-Token kommentiert.
 *
 * Vorgabewerte sind null: Nextcloud schreibt nur geänderte Felder (E3).
 *
 * @method int|null getReviewId()
 * @method void setReviewId(?int $reviewId)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getSecretHash()
 * @method void setSecretHash(?string $secretHash)
 * @method int|null getCreatedAt()
 * @method void setCreatedAt(?int $createdAt)
 * @method int|null getLastSeenAt()
 * @method void setLastSeenAt(?int $lastSeenAt)
 */
class Guest extends Entity {
	protected ?int $reviewId = null;
	protected ?string $name = null;
	protected ?string $secretHash = null;
	protected ?int $createdAt = null;
	protected ?int $lastSeenAt = null;

	public function __construct() {
		$this->addType('reviewId', 'integer');
		$this->addType('name', 'string');
		$this->addType('secretHash', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('lastSeenAt', 'integer');
	}
}
